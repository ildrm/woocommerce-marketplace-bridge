<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Domain\Money;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/** Explicitly opt-in Torob order and action attribution. */
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
        add_action('woocommerce_before_delete_order', [$this,'forget_order'], 10, 1);
        add_action('woocommerce_trash_order', [$this,'forget_order'], 10, 1);
        add_action('woocommerce_untrash_order', [$this,'record'], 10, 1);
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
            $auth=$this->authorize_for($req, $mode, true);
            if (is_wp_error($auth)) { wp_send_json(['error'=>$auth->get_error_message()],(int)($auth->get_error_data()['status']??403)); }
            if ($auth!==true) { wp_send_json(['error'=>'Unauthorized'],401); }
            $res=$mode === 'orders' ? $this->orders($req) : $this->actions($req);
            if (is_wp_error($res)) { wp_send_json(['error'=>$res->get_error_message()],(int)($res->get_error_data()['status']??400)); }
            wp_send_json($res->get_data(),200);
        }, 0);
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
        if (!$this->enabled()) { return; }
        $clid=$this->cookie();
        if ($clid) { $order->update_meta_data('_mbwc_torob_clid',$clid); }
    }
    public function record_object(\WC_Order $order): void { $this->record($order->get_id()); }
    public function record(int $orderId): void {
        if (!$this->enabled()) { return; }
        $order=wc_get_order($orderId);
        if (!$order instanceof \WC_Order) { return; }
        $clid=(string)$order->get_meta('_mbwc_torob_clid',true);
        if ($clid==='' || !preg_match('/^[a-zA-Z0-9_-]{8,128}$/D',$clid)) { return; }
        if (self::order_status($order) === null) {
            $this->forget_order($orderId);
            return;
        }
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
    public function forget_order(int $orderId): void {
        if (get_option('mbwc_schema_version') !== Database::VERSION) { return; }
        global $wpdb;
        $wpdb->delete(Database::table('attribution'), ['order_id'=>$orderId], ['%d']);
    }
    private static function order_status(\WC_Order $order): ?string {
        $status = $order->get_status();
        if (in_array($status, ['cancelled', 'refunded'], true)) { return 'cancelled'; }
        return in_array($status, ['completed', 'processing'], true) || $order->is_paid() ? 'completed' : null;
    }
    private function tracking_enabled(): bool { return $this->enabled() || get_option('mbwc_torob_actions','0')==='1'; }
    public function authorize(WP_REST_Request $request): true|WP_Error { return $this->authorize_for($request, 'orders'); }
    public function authorize_for(WP_REST_Request $request, string $mode, bool $rewrite = false): true|WP_Error {
        if (($mode==='orders' && !$this->enabled()) || ($mode==='actions' && get_option('mbwc_torob_actions','0')!=='1')) { return new WP_Error('mbwc_disabled','Order tracking not enabled by merchant',['status'=>403]); }
        if (!Jwt::verify((string)$request->get_header('x-torob-token'),(string)$request->get_header('x-torob-token-version'),
            $rewrite ? home_url() : null)) {
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
    private function poll_params(WP_REST_Request $request, string $key): array|WP_Error {
        $since = $request->get_param($key);
        $limit = filter_var($request->get_param('limit'), FILTER_VALIDATE_INT);
        if (!$limit || $limit < 1 || $limit > 1000 || !is_string($since)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $since)) {
            return new WP_Error('mbwc_params', 'Expected '.$key.' (UTC ISO 8601) and limit 1..1000', ['status'=>400]);
        }
        $format = str_contains($since, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z';
        $date = \DateTimeImmutable::createFromFormat($format, $since, new \DateTimeZone('UTC'));
        if (!$date || \DateTimeImmutable::getLastErrors()) {
            return new WP_Error('mbwc_date', 'Invalid timestamp', ['status'=>400]);
        }
        return [$date->format('Y-m-d H:i:s'), $limit];
    }
    public function actions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->poll_params($request, 'timestamp_gt');
        if (is_wp_error($params)) { return $params; }
        [$dateSQL, $limit] = $params;
        global $wpdb;
        $table=Database::table('actions');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT click_id,action_type,status,happened_at,updated_at FROM $table WHERE happened_at > %s ORDER BY happened_at ASC,id ASC LIMIT %d",$dateSQL,$limit + 1),ARRAY_A)?:[];
        if ($wpdb->last_error) { return new WP_Error('mbwc_tracking_query', 'Unable to query actions', ['status'=>503]); }
        $data=[];
        $page = self::fit_page($rows, $limit, 'happened_at');
        if (is_wp_error($page)) { return $page; }
        foreach($page as $r) {
            $data[]=['timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',strtotime($r['happened_at'].' UTC')),
              'last_updated_timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',strtotime($r['updated_at'].' UTC')),
              'torob_clid'=>$r['click_id'],'status'=>$r['status'],'action_type'=>$r['action_type']];
        }
        return new WP_REST_Response(['success'=>true,'data'=>$data],200);
    }
    public function orders(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->poll_params($request, 'purchase_timestamp_gt');
        if (is_wp_error($params)) { return $params; }
        [$dateSQL, $limit] = $params;
        global $wpdb;
        $table=Database::table('attribution');
        $records = $this->tracked_orders($table, $dateSQL, $limit + 1);
        if (is_wp_error($records)) { return $records; }
        $data=[];
        $page = self::fit_page($records, $limit, 'purchase_at');
        if (is_wp_error($page)) { return $page; }
        foreach($page as $entry) {
            $order = $entry['order'];
            $mapped = $entry['status'];
            $updated=$order->get_date_modified();
            $row=[
                'purchase_timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',strtotime($entry['purchase_at'].' UTC')),
                'last_updated_timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z',$updated?$updated->getTimestamp():strtotime($entry['updated_at'].' UTC')),
                'torob_clid'=>$entry['click_id'], 'status'=>$mapped
            ];
            $gateway=(string)$order->get_payment_method();
            if (in_array($gateway,['torobpay','torob_pay'],true)) { $row['psp']='torobpay'; }
            if (get_option('mbwc_torob_order_details','0')==='1') {
                $currency = $order->get_currency();
                if ($currency === get_woocommerce_currency()) {
                    $currency = (string)get_option('mbwc_currency_unit', $currency);
                }
                try {
                    $total=0;
                    $items=[];
                    foreach ($order->get_items('line_item') as $item) {
                        if (!is_a($item, 'WC_Order_Item_Product')) { continue; }
                        $lineToman=Money::toToman((string)$item->get_total(),$currency);
                        if ($lineToman > PHP_INT_MAX - $total) { throw new \OverflowException('Order value is out of range'); }
                        $total+=$lineToman;
                        $quantity=(int)$item->get_quantity();
                        $product=wc_get_product($item->get_variation_id()?:$item->get_product_id());
                        $url = $product ? Feed::url($product) : '';
                        if ($quantity < 1 || !Feed::is_store_url($url)) { continue; }
                        $items[]=['product_url'=>$url,'product_price'=>intdiv($lineToman,$quantity), 'quantity'=>$quantity];
                    }
                    $shipping = Money::toToman((string)$order->get_shipping_total(), $currency);
                    $row += ['order_value'=>$total, 'shipping_amount'=>$shipping, 'products'=>$items];
                } catch (\InvalidArgumentException|\OverflowException) { /* Omit invalid optional monetary data. */ }
            }
            if (get_option('mbwc_torob_share_phone','0')==='1') {
                $phone=(string)$order->get_billing_phone();
                if (preg_match('/^\+?[0-9]{9,15}$/D',$phone)) { $row['phone_number']=$phone; }
            }
            $data[]=$row;
        }
        return new WP_REST_Response(['success'=>true,'data'=>$data],200);
    }

    private static function fit_page(array $rows, int $limit, string $timestamp): array|WP_Error {
        if (count($rows) <= $limit) { return $rows; }
        $end = $limit;
        // Keep a same-second group together when it fits on its own page.
        while ($end > 0 && $rows[$end - 1][$timestamp] === $rows[$limit][$timestamp]) { --$end; }
        if (!$end) {
            // There is no secondary cursor in the provider contract. Never acknowledge a partial timestamp group.
            $message = $limit < 1000 ? 'Retry the same timestamp with a larger limit (maximum 1000).'
                : 'More than 1000 records share one timestamp. This group cannot be paged with a timestamp-only cursor.';
            return new WP_Error('mbwc_tracking_timestamp_group', $message, ['status'=>409]);
        }
        return array_slice($rows, 0, $end);
    }

    private function tracked_orders(string $table, string $date, int $limit): array|WP_Error {
        global $wpdb;
        $records = [];
        $id = PHP_INT_MAX;
        // Older installs may contain pending and deleted orders. Scan past them in bounded batches.
        $batch = min(max($limit, 25), 250);
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id,order_id,click_id,purchase_at,updated_at FROM $table
                WHERE purchase_at > %s OR (purchase_at=%s AND id>%d) ORDER BY purchase_at,id LIMIT %d",
                $date, $date, $id, $batch), ARRAY_A);
            if ($wpdb->last_error) { return new WP_Error('mbwc_tracking_query', 'Unable to query orders', ['status'=>503]); }
            foreach ($rows ?: [] as $row) {
                $date = $row['purchase_at'];
                $id = (int)$row['id'];
                $order = wc_get_order((int)$row['order_id']);
                if (!$order instanceof \WC_Order || (string)$order->get_meta('_mbwc_torob_clid', true) !== $row['click_id']) {
                    continue;
                }
                $status = self::order_status($order);
                if ($status !== null) {
                    $records[] = $row + ['order'=>$order, 'status'=>$status];
                    if (count($records) >= $limit) { return $records; }
                }
            }
            if (count($rows ?: []) < $batch) { break; }
        }
        return $records;
    }
}
