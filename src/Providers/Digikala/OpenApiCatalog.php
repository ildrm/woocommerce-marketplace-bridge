<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Digikala;

use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use MarketplaceBridge\WooCommerce\Infrastructure\Http;

/** Admin-supplied specification for browsing and safe GETs; NEVER a generic write executor. */
final class OpenApiCatalog {
    private static function shorten(string $text, int $max): string {
        return function_exists('mb_substr') ? mb_substr($text,0,$max) : substr($text,0,$max);
    }
    public static function import(string $json): int|\WP_Error {
        if (strlen($json)<30 || strlen($json)>6*1024*1024) { return new \WP_Error('mbwc_spec_size','Specification must be 30 bytes–6 MB'); }
        $doc=json_decode($json,true,128);
        if (!is_array($doc) || (!isset($doc['openapi']) && !isset($doc['swagger'])) || !isset($doc['paths']) || !is_array($doc['paths'])) {
            return new \WP_Error('mbwc_spec','Invalid OpenAPI/Swagger JSON');
        }
        if (count($doc['paths'])>2000) { return new \WP_Error('mbwc_spec_size','Too many API paths'); }
        $prefix='/open-api/v1';
        if (isset($doc['servers']) && is_array($doc['servers'])) {
            foreach ($doc['servers'] as $server) {
                $url=is_array($server)?(string)($server['url']??''):'';
                if ($url==='') { continue; }
                $parts=wp_parse_url($url);
                if ($parts===false || !is_array($parts)) { return new \WP_Error('mbwc_spec_origin','Invalid API server'); }
                $host=$parts['host']??'';
                if ($host!=='' && ($host!=='seller.digikala.com' || ($parts['scheme']??'https')!=='https')) {
                    return new \WP_Error('mbwc_spec_origin','Specification server is not Digikala Seller API');
                }
                $base=rtrim((string)($parts['path']??''),'/');
                if ($base!=='' && $base!==$prefix && !str_starts_with($base,$prefix.'/')) { return new \WP_Error('mbwc_spec_origin','Unsupported API base path'); }
            }
        }
        if (isset($doc['host']) && $doc['host']!=='seller.digikala.com') { return new \WP_Error('mbwc_spec_origin','Unsupported Swagger host'); }
        if (isset($doc['basePath']) && (string)$doc['basePath']!==$prefix) { return new \WP_Error('mbwc_spec_origin','Unsupported Swagger base path'); }
        $ops=[];
        foreach ($doc['paths'] as $sourcePath=>$config) {
            if (!is_string($sourcePath) || !is_array($config)) { continue; }
            $path=$sourcePath;
            if (!str_starts_with($path,$prefix.'/') && $path!==$prefix) { $path=$prefix.'/'.ltrim($path,'/'); }
            if (strlen($path)>350 || !preg_match('~^/open-api/v1(?:/[A-Za-z0-9_./{}-]*)?$~D',$path) || str_contains($path,'//') || str_contains($path,'..')) { continue; }
            foreach (['get','post','put','patch','delete'] as $method) {
                if (!isset($config[$method]) || !is_array($config[$method])) { continue; }
                if (count($ops)>=1500) { return new \WP_Error('mbwc_spec_size','Too many OpenAPI operations'); }
                $definition=$config[$method];
                $id='op_'.substr(hash('sha256',strtoupper($method).':'.$path),0,16);
                $params=array_merge((array)($config['parameters']??[]),(array)($definition['parameters']??[]));
                $query=[]; $names=[]; $required=[];
                foreach ($params as $param) {
                    if (!is_array($param) || !isset($param['name'])) { continue; }
                    $name=(string)$param['name'];
                    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\[\].-]{0,100}$/D',$name)) { continue; }
                    if (($param['in']??'')==='query') { $query[]=$name; if (!empty($param['required'])) { $required[]=$name; } }
                    if (($param['in']??'')==='path') { $names[]=$name; }
                }
                preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',$path,$matches);
                $names=array_values(array_unique(array_merge($names,$matches[1])));
                $ops[$id]=[
                    'id'=>$id,'method'=>strtoupper($method),'path'=>$path,
                    'summary'=>self::shorten((string)($definition['summary']??$definition['operationId']??$path),160),
                    'tag'=>self::shorten((string)($definition['tags'][0]??'Other'),60),
                    'query'=>array_values(array_unique($query)),'required_query'=>array_values(array_unique($required)),
                    'paths'=>$names,'risk'=>$method==='get'?'read':'disabled_write',
                ];
            }
        }
        if (!$ops) { return new \WP_Error('mbwc_spec_empty','No Digikala seller API operations found'); }
        update_option('mbwc_digikala_spec',[
            'imported'=>gmdate('c'),'source'=>self::shorten((string)($doc['info']['version']??'unknown'),50),
            'operations'=>$ops
        ],false);
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
        if (!Accounts::exists('digikala',$account)) { return new \WP_Error('mbwc_account','Unknown Digikala account'); }
        $token=Accounts::token('digikala',$account);
        if ($token==='') { return new \WP_Error('mbwc_auth','Configure this seller account token first'); }
        $path=$op['path'];
        foreach ($op['paths'] as $name) {
            if (!isset($pathVars[$name]) || !is_scalar($pathVars[$name])) { return new \WP_Error('mbwc_path_variable','Missing path parameter: '.$name); }
            $value=(string)$pathVars[$name];
            if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value)) { return new \WP_Error('mbwc_path_variable','Invalid path parameter: '.$name); }
            $path=str_replace('{'.$name.'}',rawurlencode($value),$path);
        }
        if (str_contains($path,'{') || str_contains($path,'}')) { return new \WP_Error('mbwc_path_variable','Unresolved path parameter'); }
        $query=array_intersect_key($query,array_flip($op['query']));
        foreach ($op['required_query']??[] as $name) { if (!isset($query[$name]) || $query[$name]==='') { return new \WP_Error('mbwc_query','Missing required query parameter: '.$name); } }
        return Http::request('digikala','GET',$path,$token,$query,null,Accounts::sellerCode($account));
    }
}
