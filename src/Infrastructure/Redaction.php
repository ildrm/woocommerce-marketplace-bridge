<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

/** Screenshots/logs should not contain tokens or personal order data. */
final class Redaction {
    public static function scrub(mixed $data, int $depth = 0): mixed {
        if ($depth > 10) { return '[depth limit]'; }
        if (is_array($data)) {
            $output=[];
            $counter=0;
            foreach ($data as $key=>$value) {
                if (++$counter > 300) { $output['__truncated__']='Additional entries omitted'; break; }
                if (is_string($key) && preg_match('/(?:token|secret|password|authorization|api[_-]?key|private[_-]?key|phone|mobile|email|address|customer|buyer|recipient|iban|card[_-]?number|national[_-]?id|national[_-]?code|first[_-]?name|last[_-]?name|full[_-]?name|postal[_-]?code|cookie|ip_address)/i',$key)) {
                    $output[$key]='[REDACTED]';
                } else { $output[$key]=self::scrub($value,$depth+1); }
            }
            return $output;
        }
        if (is_string($data)) {
            $data = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+/i','Bearer [REDACTED]',$data);
            $data = preg_replace('/(?<!\d)(?:09|\+989|00989)[0-9]{9}(?!\d)/','[REDACTED MOBILE]',$data);
            $data = preg_replace('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED EMAIL]', $data);
            return strlen($data)>1800 ? substr($data,0,1800).' [truncated]' : $data;
        }
        return $data;
    }
}
