<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Digikala;

use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use MarketplaceBridge\WooCommerce\Infrastructure\Http;

/** Admin-supplied specification for browsing and safe GETs; NEVER a generic write executor. */
final class OpenApiCatalog {
    private const PREFIX = '/open-api/v1';

    private static function shorten(mixed $text, int $max, string $fallback = ''): string {
        $text = is_string($text) ? $text : $fallback;
        return function_exists('mb_substr') ? mb_substr($text,0,$max) : substr($text,0,$max);
    }

    private static function server_base(mixed $servers, string $fallback): string|\WP_Error {
        if ($servers === null || $servers === []) { return $fallback; }
        if (!is_array($servers)) { return new \WP_Error('mbwc_spec_origin', 'Invalid API server'); }
        $first = null;
        foreach ($servers as $server) {
            $url = is_array($server) ? ($server['url'] ?? '') : '';
            $parts = is_string($url) && $url !== '' ? wp_parse_url($url) : false;
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
                || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
                || (isset($parts['host']) && strtolower($parts['host']) !== 'seller.digikala.com')
                || (isset($parts['scheme']) && strtolower($parts['scheme']) !== 'https')) {
                return new \WP_Error('mbwc_spec_origin', 'Specification server is not Digikala Seller API');
            }
            $base = rtrim($parts['path'] ?? '', '/') ?: self::PREFIX;
            if (!self::supported_base($base)) { return new \WP_Error('mbwc_spec_origin', 'Unsupported API base path'); }
            $first ??= $base;
        }
        return $first ?? $fallback;
    }

    private static function supported_base(string $base): bool {
        return preg_match('~^/open-api/v1(?:/[A-Za-z0-9_.-]+)*$~D', $base) && !str_contains($base, '..')
            && !in_array('.', explode('/', $base), true);
    }

    private static function parameter(mixed $param, array $doc): ?array {
        for ($depth = 0; $depth < 8 && is_array($param); ++$depth) {
            $ref = $param['$ref'] ?? null;
            if ($ref === null) { return $param; }
            if (!is_string($ref) || !preg_match('~^#/(?:components/parameters|parameters)/[^/]+$~D', $ref)) { break; }
            $param = $doc;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $part);
                $param = is_array($param) ? ($param[$key] ?? null) : null;
            }
        }
        return null;
    }
    public static function import(string $json): int|\WP_Error {
        if (strlen($json)<30 || strlen($json)>6*1024*1024) { return new \WP_Error('mbwc_spec_size','Specification must be 30 bytes–6 MB'); }
        $doc=json_decode($json,true,128);
        if (!is_array($doc) || (!isset($doc['openapi']) && !isset($doc['swagger'])) || !isset($doc['paths']) || !is_array($doc['paths'])) {
            return new \WP_Error('mbwc_spec','Invalid OpenAPI/Swagger JSON');
        }
        if (count($doc['paths'])>2000) { return new \WP_Error('mbwc_spec_size','Too many API paths'); }
        $prefix = self::PREFIX;
        if (isset($doc['host']) && (!is_string($doc['host']) || strtolower($doc['host']) !== 'seller.digikala.com')) {
            return new \WP_Error('mbwc_spec_origin', 'Unsupported Swagger host');
        }
        $swaggerBase = $doc['basePath'] ?? $prefix;
        if (is_string($swaggerBase)) { $swaggerBase = rtrim($swaggerBase, '/') ?: $prefix; }
        if (!is_string($swaggerBase) || !self::supported_base($swaggerBase)) {
            return new \WP_Error('mbwc_spec_origin', 'Unsupported Swagger base path');
        }
        $documentBase = self::server_base($doc['servers'] ?? null, $swaggerBase);
        if (is_wp_error($documentBase)) { return $documentBase; }
        $ops=[];
        foreach ($doc['paths'] as $sourcePath=>$config) {
            if (!is_string($sourcePath) || !is_array($config)) { continue; }
            if (!str_starts_with($sourcePath, '/') || str_starts_with($sourcePath, '//')) { continue; }
            $pathBase = self::server_base($config['servers'] ?? null, $documentBase);
            if (is_wp_error($pathBase)) { return $pathBase; }
            foreach (['get','post','put','patch','delete'] as $method) {
                if (!isset($config[$method]) || !is_array($config[$method])) { continue; }
                if (count($ops)>=1500) { return new \WP_Error('mbwc_spec_size','Too many OpenAPI operations'); }
                $definition=$config[$method];
                $base = self::server_base($definition['servers'] ?? null, $pathBase);
                if (is_wp_error($base)) { return $base; }
                $path = $sourcePath === $prefix || str_starts_with($sourcePath, $prefix.'/') ? $sourcePath : $base.$sourcePath;
                if (strlen($path) > 350 || !preg_match('~^/open-api/v1(?:/[A-Za-z0-9_./{}-]*)?$~D', $path)
                    || str_contains($path, '//') || str_contains($path, '..') || in_array('.', explode('/', $path), true)) { continue; }
                $id='op_'.substr(hash('sha256',strtoupper($method).':'.$path),0,16);
                $params=array_merge((array)($config['parameters']??[]),(array)($definition['parameters']??[]));
                $query = [];
                foreach ($params as $param) {
                    $param = self::parameter($param, $doc);
                    $name = $param['name'] ?? null;
                    if (is_string($name) && ($param['in'] ?? '') === 'query' && preg_match('/^[a-zA-Z][a-zA-Z0-9_\[\].-]{0,100}$/D', $name)) {
                        $query[$name] = ($param['required'] ?? false) === true;
                    }
                }
                preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_.-]*)\}/', $path, $matches);
                $names = array_values(array_unique($matches[1]));
                $ops[$id]=[
                    'id'=>$id,'method'=>strtoupper($method),'path'=>$path,
                    'summary'=>self::shorten($definition['summary']??$definition['operationId']??$path,160,$path),
                    'tag'=>self::shorten($definition['tags'][0]??'Other',60,'Other'),
                    'query'=>array_keys($query),'required_query'=>array_keys(array_filter($query)),
                    'paths'=>$names,'risk'=>$method==='get'?'read':'disabled_write',
                ];
            }
        }
        if (!$ops) { return new \WP_Error('mbwc_spec_empty','No Digikala seller API operations found'); }
        $spec = [
            'imported'=>gmdate('c'),'source'=>self::shorten($doc['info']['version']??'unknown',50,'unknown'),
            'operations'=>$ops
        ];
        if (!update_option('mbwc_digikala_spec', $spec, false) && get_option('mbwc_digikala_spec') !== $spec) {
            return new \WP_Error('mbwc_spec_save', 'Could not save the imported specification');
        }
        return count($ops);
    }
    public static function all(): array {
        $spec=get_option('mbwc_digikala_spec',[]);
        return is_array($spec) && isset($spec['operations']) && is_array($spec['operations']) ? $spec['operations'] : [];
    }
    public static function execute(string $id, array $pathVars, array $query, ?array $body, string $account='default'): array|\WP_Error {
        $ops=self::all();
        if (!isset($ops[$id])) { return new \WP_Error('mbwc_operation','Unknown API operation'); }
        $op=$ops[$id];
        // An untrusted imported document is NOT authorization for arbitrary marketplace writes.
        if ($op['method']!=='GET') { return new \WP_Error('mbwc_restricted','Generic writes are disabled. A reviewed typed workflow is required.'); }
        if ($body!==null) { return new \WP_Error('mbwc_body','GET operations must not contain a request body'); }
        $path=$op['path'];
        foreach ($op['paths'] as $name) {
            if (!isset($pathVars[$name]) || !is_scalar($pathVars[$name])) { return new \WP_Error('mbwc_path_variable','Missing path parameter: '.$name); }
            $value=(string)$pathVars[$name];
            if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value)) { return new \WP_Error('mbwc_path_variable','Invalid path parameter: '.$name); }
            $path=str_replace('{'.$name.'}',rawurlencode($value),$path);
        }
        if (str_contains($path,'{') || str_contains($path,'}')) { return new \WP_Error('mbwc_path_variable','Unresolved path parameter'); }
        $query=array_intersect_key($query,array_flip($op['query']));
        foreach ($query as $name=>$value) {
            if (!is_scalar($value)) { return new \WP_Error('mbwc_query', 'Expected a scalar query parameter: '.$name); }
        }
        foreach ($op['required_query']??[] as $name) { if (!isset($query[$name]) || $query[$name]==='') { return new \WP_Error('mbwc_query','Missing required query parameter: '.$name); } }
        $credentials = Accounts::credentials($account);
        if (is_wp_error($credentials)) { return $credentials; }
        return Http::request('digikala', 'GET', $path, $credentials['token'], $query, null, $credentials['seller_code']);
    }
}
