<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

/** Independent seller connections; the legacy default token remains supported. */
final class Accounts {
    public static function validId(string $id): bool { return (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D',$id); }
    public static function all(string $provider): array {
        if (!in_array($provider,['digikala','torob'],true)) { return []; }
        $value=get_option('mbwc_accounts_'.$provider,[]);
        $accounts=is_array($value)?$value:[];
        if (!isset($accounts['default'])) {
            $accounts=['default'=>['label'=>'Default','seller_code'=>$provider==='digikala'?(string)get_option('mbwc_digikala_seller_code',''):'']]+$accounts;
        }
        return $accounts;
    }
    public static function exists(string $provider,string $id): bool { return isset(self::all($provider)[$id]); }
    public static function save(string $provider,string $id,string $label,string $sellerCode,string $token): bool|\WP_Error {
        if ($provider!=='digikala' || !self::validId($id) || strlen($label)>90 || $label==='' || !preg_match('/^[A-Za-z0-9_-]{0,60}$/D',$sellerCode)) {
            return new \WP_Error('mbwc_account','Invalid Digikala account configuration');
        }
        $all=self::all($provider);
        if (!isset($all[$id]) && count($all)>=21) { return new \WP_Error('mbwc_accounts','Maximum 20 additional accounts allowed'); }
        $all[$id]=['label'=>$label,'seller_code'=>$sellerCode];
        if ($token!=='') {
            if (!Secrets::set('digikala',$id==='default'?'access_token':'access_token_'.$id,$token)) { return new \WP_Error('mbwc_secret','Could not encrypt seller token'); }
        }
        return update_option('mbwc_accounts_'.$provider,$all,false);
    }
    public static function remove(string $provider,string $id): bool {
        if ($provider!=='digikala'||$id==='default'||!self::validId($id)) { return false; }
        $all=self::all($provider);
        if (!isset($all[$id])) { return false; }
        unset($all[$id]);
        Secrets::set('digikala','access_token_'.$id,'');
        return update_option('mbwc_accounts_'.$provider,$all,false);
    }
    public static function token(string $provider,string $id='default'): string {
        if (!self::exists($provider,$id)) { return ''; }
        if ($provider==='digikala' && $id!=='default') { return Secrets::get('digikala','access_token_'.$id); }
        return Secrets::get($provider,$provider==='digikala'?'access_token':'shop_token');
    }
    public static function sellerCode(string $id='default'): string {
        $all=self::all('digikala');
        return (string)($all[$id]['seller_code']??'');
    }
}
