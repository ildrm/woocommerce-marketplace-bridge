<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

final class Jwt {
    /** Torob's published Ed25519 X.509 SubjectPublicKeyInfo (not a private key). */
    private const PUBLIC_KEY = 'MCowBQYDK2VwAyEAt6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=';
    private static function base64url(string $value): string|false {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4-strlen($value)%4)%4), true);
    }
    public static function verify(string $token, string $version, ?string $endpoint = null): bool {
        if ($version !== '1' || !function_exists('sodium_crypto_sign_verify_detached')) { return false; }
        if (strlen($token) > 8192) { return false; }
        $segments = explode('.', $token, 4);
        if (count($segments) !== 3) { return false; }
        [$header, $claims, $sig] = $segments;
        $h = self::base64url($header);
        $p = self::base64url($claims);
        $signature = self::base64url($sig);
        if ($h === false || $p === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) { return false; }
        $headerData = json_decode($h, true);
        $data = json_decode($p, true);
        if (!is_array($headerData) || !is_array($data) || ($headerData['alg'] ?? '') !== 'EdDSA'
            || (isset($headerData['v']) && $headerData['v'] !== 1)) { return false; }
        $spki = base64_decode(self::PUBLIC_KEY, true);
        if ($spki === false || strlen($spki) < 32) { return false; }
        $key = substr($spki, -32);
        if (!sodium_crypto_sign_verify_detached($signature, $header.'.'.$claims, $key)) { return false; }
        $now = time();
        if (!isset($data['exp'],$data['nbf']) || !is_int($data['exp']) || !is_int($data['nbf'])) { return false; }
        if ($now >= $data['exp'] || $now < $data['nbf'] || $data['exp'] <= $data['nbf']) { return false; }
        $endpoint ??= rest_url();
        $host = wp_parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') { return false; }
        $port = wp_parse_url($endpoint, PHP_URL_PORT);
        $expected = strtolower($host) . ($port ? ':' . (int)$port : '');
        $aud = $data['aud'] ?? null;
        if (is_string($aud)) { return hash_equals($expected, strtolower($aud)); }
        if (is_array($aud)) { return in_array($expected, array_map('strtolower', array_filter($aud, 'is_string')), true); }
        return false;
    }
}
