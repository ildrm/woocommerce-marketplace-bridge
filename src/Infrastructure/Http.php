<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

final class Http {
    /** Only fixed, provider-owned HTTPS origins are permitted. */
    private const ORIGINS = ['torob'=>'https://api.torob.com', 'digikala'=>'https://seller.digikala.com'];
    /** @return array<string,mixed>|\WP_Error */
    public static function request(string $provider, string $method, string $path, string $token, array $query=[], array|object|null $payload=null, ?string $sellerCode=null): array|\WP_Error {
        if (!isset(self::ORIGINS[$provider])) { return new \WP_Error('mbwc_provider','Unknown provider'); }
        if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true)) { return new \WP_Error('mbwc_method','Unsupported method'); }
        if (!str_starts_with($path, '/') || str_contains($path, '//') || str_contains($path, '..') || preg_match('/[?#\\\\]/', $path)) {
            return new \WP_Error('mbwc_path','Invalid endpoint path');
        }
        $url = self::ORIGINS[$provider] . $path;
        $query = array_filter($query, static fn($v): bool => is_scalar($v) && $v !== '');
        if ($query) { $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
        if (strlen($url)>4096) { return new \WP_Error('mbwc_url','Query exceeds URL size limit'); }
        $headers = ['Accept'=>'application/json', 'Content-Type'=>'application/json'];
        if ($token !== '') { $headers['Authorization']='Bearer '.$token; }
        if ($provider === 'digikala') {
            $seller = trim($sellerCode ?? (string)get_option('mbwc_digikala_seller_code',''));
            if ($seller !== '' && preg_match('/^[A-Za-z0-9_-]{1,60}$/', $seller)) {
                $headers['User-Agent'] = 'DigikalaTrustedBot/SelfToken/' . $seller;
            }
        }
        $args = ['method'=>$method, 'headers'=>$headers, 'timeout'=>20, 'redirection'=>0, 'sslverify'=>true,
                 'limit_response_size'=>1024*1024, 'reject_unsafe_urls'=>true];
        if ($payload !== null) {
            $body = wp_json_encode($payload);
            if ($body === false) { return new \WP_Error('mbwc_json', 'Could not encode request body'); }
            $args['body'] = $body;
        }
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            Logger::record($provider, 'error', $method.' '.$path, 'network_error', $response->get_error_code());
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
        Logger::record($provider, $status>=400?'error':'info', $method.' '.$path, (string)$status, $status>=400?'Remote HTTP error':'Request completed');
        if ($status<200 || $status>=300) {
            return new \WP_Error('mbwc_remote_http', 'Provider request failed (HTTP '.$status.').', ['status'=>$status, 'response'=>$data]);
        }
        if ($raw !== '' && !is_array($decoded)) { return new \WP_Error('mbwc_invalid_json','Unexpected non-JSON provider response'); }
        return $data;
    }
}
