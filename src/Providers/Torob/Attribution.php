<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Domain\Money;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/** Explicitly opt-in Torob order attribution. No IP, phone or customer name is exposed. */
final class Attribution {
    public function register(): void {
        add_action('init', [$this,'capture'], 15);
        add_action('mbwc_torob_record_action', [$this,'record_action'], 10, 1);
        add_action('mbwc_torob_update_action', [$this,'update_action'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this,'attach'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this,'attach'], 10, 1);
        add_action('woocommerce_checkout_order_processed', [$this,'record'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this,'record_object'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this,'touch'], 10, 1);
        add_action('rest_api_init', function (): void {
            register_rest_route('marketplacebridge/v1', '/torob/orders', ['methods'=>'GET','callback'=>[$this,'orders'],
                'permission_callback'=>[$this,'authorize']]);
        });
        add_filter('query_vars', static function (array $vars): array { $vars[]='mbwc_torob_orders'; $vars[]='mbwc_torob_actions'; return $vars; });
        add_action('init', static function (): void {
            add_rewrite_rule('^torob/v1/orders/?$', 'index.php?mbwc_torob_orders=1', 'top');
            add_rewrite_rule('^torob/v1/actions/?$', 'index.php?mbwc_torob_actions=1', 'top');
        });
        add_action('template_redirect', function (): void {
            $mode = get_query_var('mbwc_torob_orders') ? 'orders' : (get_query_var('mbwc_torob_actions') ? 'actions' : '');
            if (!$mode) { return; }
            if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { wp_send_json(['error'=>'Method not allowed'],405); }
            $req=new WP_REST_Request('GET','/marketplacebridge/v1/torob/orders');
            $safe=[];
            foreach ((array)wp_unslash($_GET) as $key=>$value) { if (is_string($key) && is_scalar($value)) { $safe[sanitize_key($key)]=sanitize_text_field((string)$value); } }
            $req->set_query_params($safe);
            $req->set_header('x-torob-token',(string)($_SERVER['HTTP_X_TOROB_TOKEN']??''));
            $req->set_header('x-torob-token-version',(string)($_SERVER['HTTP_X_TOROB_TOKEN_VERSION']??''));
            $auth=$this->authorize_for($req, $mode);
            if (is_wp_error($auth)) { wp_send_json(['error'=>$auth->get_error_message()],(int)($auth->get_error_data()['status']??403)); }
            if ($auth!==true) { wp_send_json(['error'=>'Unauthorized'],401); }
            $res=$mode === 'orders' ? $this->orders($req) : $this->actions($req);
            if (is_wp_error($res)) { wp_send_json(['error'=>$res->get_error_message()],(int)($res->get_error_data()['status']??400)); }
            wp_send_json($res->get_data(),200);
        });
        add_action('rest_api_init', function (): void {
            register_rest_route('marketplacebridge/v1', '/torob/actions', ['methods'=>'GET', 'callback'=>[$this,'actions'], 'permission_callback'=>fn($r)=>$this->authorize_for($r,'actions')]);
        });
    }
    private function enabled(): bool { return get_option('mbwc_torob_attribution','0')==='1'; }
    public function capture(): void {
        if (!$this->tracking_enabled() || is_admin() || headers_sent() || empty($_GET['torob_clid']) || !is_string($_GET['torob_clid'])) { return; }
        $clid=(string)wp_unslash($_GET['torob_clid']);
        if (!preg_match('/^[a-zA-Z0-9_-]{8,128}$/D', $clid)) { return; }
        $stamp=time();
        $value = $clid.'|'.$stamp.'|'.hash_hmac('sha256',$clid.'|'.$stamp,wp_salt('nonce'));
        setcookie('mbwc_torob_clid', $value, [
            'expires'=>time()+7*DAY_IN_SECONDS, 'path'=>COOKIEPATH ?: '/', 'secure'=>is_ssl(),
            'httponly'=>true, 'samesite'=>'Lax'
        ]);
        $_COOKIE['mbwc_torob_clid']=$value;
    }
    private function cookie(): string {
        if (!$this->tracking_enabled() || empty($_COOKIE['mbwc_torob_clid']) || !is_string($_COOKIE['mbwc_torob_clid'])) { return ''; }
        $parts=explode('|',(string)wp_unslash($_COOKIE['mbwc_torob_clid']));
        if (count($parts)!==3 || !preg_match('/^[a-zA-Z0-9_-]{8,128}$/D',$parts[0]) || !ctype_digit($parts[1])) { return ''; }
        if (!hash_equals(hash_hmac('sha256',$parts[0].'|'.$parts[1],wp_salt('nonce')),$parts[2])) { return ''; }
        if ((int)$parts[1]>time() || (int)$parts[1]<time()-7*DAY_IN_SECONDS) { return ''; }
        return $parts[0];
    }
    public function attach(\WC_Order $order): void {
        $clid=$this->cookie();
        if ($clid) { $order->update_meta_data('_mbwc_torob_clid',$clid); }
    }
    public function record_object(\WC_Order $order): void { $this->record($order->get_id()); }
    public function record(int $orderId): void {
        if (!$this->enabled()) { return; }
        $order=wc_get_order($orderId);
        if (!$order) { return; }
        $clid=(string)$order->get_meta('_mbwc_torob_clid',true);
        if ($clid==='' || !preg_match('/^[a-zA-Z0-9_-]{8,128}$/D',$clid)) { return; }
        global $wpdb;
        $table=Database::table('attribution');
        $created=$order->get_date_created();
        $date=$created?gmdate('Y-m-d H:i:s',$created->getTimestamp()):gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare("INSERT INTO $table (order_id,click_id,purchase_at,updated_at) VALUES (%d,%s,%s,%s) ON DUPLICATE KEY UPDATE click_id=VALUES(click_id),updated_at=VALUES(updated_at)",
            $orderId,$clid,$date,gmdate('Y-m-d H:i:s')));
    }
    public function touch(int $orderId): void {
        if (!$this->enabled()) { return; }
        $this->record($orderId);
    }
    private function tracking_enabled(): bool { return $this->enabled() || get_option('mbwc_torob_actions','0')==='1'; }
    public function authorize(WP_REST_Request $request): true|WP_Error { return $this->authorize_for($request, 'orders'); }
    public function authorize_for(WP_REST_Request $request, string $mode): true|WP_Error {
        if (($mode==='orders' && !$this->enabled()) || ($mode==='actions' && get_option('mbwc_torob_actions','0')!=='1')) { return new WP_Error('mbwc_disabled','Order tracking not enabled by merchant',['status'=>403]); }
        if (!Jwt::verify((string)$request->get_header('x-torob-token'),(string)$request->get_header('x-torob-token-version'))) {
            return new WP_Error('mbwc_auth','Unauthorized',['status'=>401]);
        }
        return true;
    }
    /** External extensions may fire do_action('mbwc_torob_record_action', 'signup'); */
    public function record_action(string $actionType): void {
        if (get_option('mbwc_torob_actions','0')!=='1' || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $actionType)) { return; }
        $clid=$this->cookie();
        if ($clid==='') { return; }
        global $wpdb;
        $table=Database::table('actions');
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($table,['click_id'=>$clid,'action_type'=>$actionType,'status'=>'completed','happened_at'=>$now,'updated_at'=>$now],['%s','%s','%s','%s','%s']);
    }
    public function update_action(int $id, string $status): void {
        if ($id<1 || !in_array($status,['completed','cancelled'],true) || get_option('mbwc_torob_actions','0')!=='1') { return; }
        global $wpdb;
        $wpdb->update(Database::table('actions'),['status'=>$status,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id],['%s','%s'],['%d']);
    }
    public function actions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $since=(string)$request->get_param('timestamp_gt');
        $limit=filter_var($request->get_param('limit'),FILTER_VALIDATE_INT);
        if (!$limit || $limit<1 || $limit>1000 || !$since || strlen($since)>40 || !preg_match('/Z$/i',$since)) {
            return new WP_Error('mbwc_params','Expected timestamp_gt (UTC ISO 8601) and limit 1..1000',['status'=>400]);
        }
        try { $date=new \DateTimeImmutable($since); } catch (\Exception) {
            return new WP_Error('mbwc_date','Invalid timestamp',['status'=>400]);
        }
        global $wpdb;
        $table=Database::table('actions');
        $dateSQL=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT click_id,action_type,status,happened_at,updated_at FROM $table WHERE happened_at > %s ORDER BY happened_at ASC,id ASC LIMIT %d",$dateSQL,$limit),ARRAY_A)?:[];
        $data=[];
        foreach($rows as $r) {
            $data[]=['timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',strtotime($r['happened_at'].' UTC')),
              'last_updated_timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',strtotime($r['updated_at'].' UTC')),
              'torob_clid'=>$r['click_id'],'status'=>$r['status'],'action_type'=>$r['action_type']];
        }
        return new WP_REST_Response(['success'=>true,'data'=>$data],200);
    }
    public function orders(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $since=(string)$request->get_param('purchase_timestamp_gt');
        $limit=filter_var($request->get_param('limit'),FILTER_VALIDATE_INT);
        if (!$limit || $limit<1 || $limit>1000 || !$since || strlen($since)>40) {
            return new WP_Error('mbwc_params','Expected purchase_timestamp_gt (UTC ISO 8601) and limit 1..1000',['status'=>400]);
        }
        try { $date=new \DateTimeImmutable($since); } catch (\Exception) {
            return new WP_Error('mbwc_date','Invalid timestamp',['status'=>400]);
        }
        if (!preg_match('/Z$/i',$since)) { return new WP_Error('mbwc_date','Timestamp must be UTC with Z suffix',['status'=>400]); }
        global $wpdb;
        $table=Database::table('attribution');
        $dateSQL=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $records=$wpdb->get_results($wpdb->prepare("SELECT order_id,click_id,purchase_at,updated_at FROM $table WHERE purchase_at > %s ORDER BY purchase_at ASC,id ASC LIMIT %d",$dateSQL,$limit),ARRAY_A)?:[];
        $data=[];
        foreach($records as $entry) {
            $order=wc_get_order((int)$entry['order_id']);
            if (!$order) { continue; }
            $status=$order->get_status();
            if (in_array($status,['cancelled','refunded'],true)) { $mapped='cancelled'; }
            elseif (in_array($status,['completed','processing'],true) || $order->is_paid()) { $mapped='completed'; }
            else { continue; }
            $created=$order->get_date_created();$updated=$order->get_date_modified();
            $row=[
                'purchase_timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',$created?$created->getTimestamp():strtotime($entry['purchase_at'].' UTC')),
                'last_updated_timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',$updated?$updated->getTimestamp():strtotime($entry['updated_at'].' UTC')),
                'torob_clid'=>$entry['click_id'], 'status'=>$mapped
            ];
            $gateway=(string)$order->get_payment_method();
            if (in_array($gateway,['torobpay','torob_pay'],true)) { $row['psp']='torobpay'; }
            if (get_option('mbwc_torob_order_details','0')==='1') {
                $currency=(string)get_option('mbwc_currency_unit','IRR');
                try {
                    $total=0;
                    $items=[];
                    foreach ($order->get_items('line_item') as $item) {
                        if (!is_a($item, 'WC_Order_Item_Product')) { continue; }
                        $lineToman=Money::toToman((string)$item->get_total(),$currency);
                        $total+=$lineToman;
                        $quantity=(int)$item->get_quantity();
                        $product=wc_get_product($item->get_variation_id()?:$item->get_product_id());
                        if (!$product || $quantity<1) { continue; }
                        $items[]=['product_url'=>Feed::url($product),'product_price'=>intdiv($lineToman,$quantity), 'quantity'=>$quantity];
                    }
                    $row['order_value']=$total;
                    $row['shipping_amount']=Money::toToman((string)$order->get_shipping_total(),$currency);
                    $row['products']=$items;
                } catch (\Throwable $e) { /* Do not send inaccurate monetary data. */ }
            }
            if (get_option('mbwc_torob_share_phone','0')==='1') {
                $phone=(string)$order->get_billing_phone();
                if (preg_match('/^\+?[0-9]{9,15}$/D',$phone)) { $row['phone_number']=$phone; }
            }
            $data[]=$row;
        }
        return new WP_REST_Response(['success'=>true,'data'=>$data],200);
    }
}
