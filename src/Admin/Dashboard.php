<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Admin;

use MarketplaceBridge\WooCommerce\Core\Registry;
use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;
use MarketplaceBridge\WooCommerce\Infrastructure\Logger;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use MarketplaceBridge\WooCommerce\Infrastructure\Redaction;
use MarketplaceBridge\WooCommerce\Providers\Torob\Client as TorobClient;
use MarketplaceBridge\WooCommerce\Providers\Digikala\Client as DigikalaClient;
use MarketplaceBridge\WooCommerce\Providers\Digikala\OpenApiCatalog;
use MarketplaceBridge\WooCommerce\Application\Sync;

final class Dashboard {
    public function __construct(private Registry $registry) {}
    public function register(): void {
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_enqueue_scripts',[$this,'enqueue']);
        add_action('admin_post_mbwc_action',[$this,'handle']);
    }
    public function menu(): void {
        add_menu_page('MarketplaceBridge','MarketplaceBridge','manage_options','mbwc',[$this,'render'],'dashicons-update',56);
    }
    public function enqueue(string $hook): void {
        if ($hook !== 'toplevel_page_mbwc') { return; }
        wp_enqueue_style('mbwc-admin', MBWC_URL.'assets/admin.css',[],MBWC_VERSION);
    }
    private function redirect(string $tab, string $message, string $type='success'): never {
        set_transient('mbwc_flash_'.get_current_user_id(), ['message'=>substr($message,0,12000),'type'=>$type],60);
        wp_safe_redirect(add_query_arg(['page'=>'mbwc','tab'=>$tab],admin_url('admin.php')));
        exit;
    }
    public function handle(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden',403); }
        check_admin_referer('mbwc_action');
        $action = sanitize_key((string)($_POST['mbwc_action']??''));
        switch($action) {
            case 'save_settings':
                foreach (['torob_shop_token'=>['torob','shop_token'], 'torob_webhook_token'=>['torob','webhook_token'], 'digikala_access_token'=>['digikala','access_token']] as $field=>$target) {
                    if (!isset($_POST[$field])) { continue; }
                    $secret = trim((string)wp_unslash($_POST[$field]));
                    if ($secret !== '') { Secrets::set($target[0],$target[1],$secret); }
                    if (!empty($_POST['clear_'.$field])) { Secrets::set($target[0],$target[1],''); }
                }
                update_option('mbwc_torob_enabled',!empty($_POST['torob_enabled'])?'1':'0',false);
                update_option('mbwc_torob_webhooks',!empty($_POST['torob_webhooks'])?'1':'0',false);
                update_option('mbwc_torob_attribution',!empty($_POST['torob_attribution'])?'1':'0',false);
                update_option('mbwc_torob_actions',!empty($_POST['torob_actions'])?'1':'0',false);
                update_option('mbwc_torob_order_details',!empty($_POST['torob_order_details'])?'1':'0',false);
                update_option('mbwc_torob_share_phone',!empty($_POST['torob_share_phone'])?'1':'0',false);
                update_option('mbwc_currency_unit',in_array($_POST['currency_unit']??'', ['IRR','IRT'],true)?$_POST['currency_unit']:'IRR',false);
                $seller = sanitize_text_field(wp_unslash((string)($_POST['digikala_seller_code']??'')));
                if ($seller === '' || preg_match('/^[A-Za-z0-9_-]{1,60}$/', $seller)) { update_option('mbwc_digikala_seller_code',$seller,false); }
                $this->redirect('providers','Configuration saved.');
            case 'save_account':
                $id=sanitize_key((string)($_POST['account_id']??''));
                $label=sanitize_text_field(wp_unslash((string)($_POST['account_label']??'')));
                $seller=sanitize_text_field(wp_unslash((string)($_POST['account_seller_code']??'')));
                $token=trim((string)wp_unslash($_POST['account_token']??''));
                $result=Accounts::save('digikala',$id,$label,$seller,$token);
                $this->redirect('providers',is_wp_error($result)?$result->get_error_message():'Seller account saved.',is_wp_error($result)||!$result?'error':'success');
            case 'remove_account':
                $id=sanitize_key((string)($_POST['account_id']??''));
                // Prevent the deletion of a connection with active product mappings.
                if (Sync::hasAccountMappings($id)) { $this->redirect('providers','Remove the account mappings before deleting the connection.','error'); }
                if ((string)($_POST['confirm']??'')!==$id) { $this->redirect('providers','Type the account ID to confirm removal.','error'); }
                $ok=Accounts::remove('digikala',$id);
                $this->redirect('providers',$ok?'Account removed.':'Could not remove account.',$ok?'success':'error');
            case 'test_torob':
                $result = (new TorobClient())->run('shop');
                $this->show_result('torob',$result);
            case 'test_digikala':
                $result=(new DigikalaClient())->read('scopes',[],$this->selected_account());
                $this->show_result('digikala',$result);
            case 'torob_operation':
                $operation = sanitize_key((string)($_POST['operation']??''));
                $query = $this->decode_json((string)wp_unslash($_POST['params']??'{}'));
                $body = $this->decode_json((string)wp_unslash($_POST['body']??'{}'));
                if (is_wp_error($query)||is_wp_error($body)) { $this->redirect('torob','Invalid JSON entered','error'); }
                if (in_array($operation,['activate','deactivate','answer_report'],true) && ($_POST['confirm']??'')!=='YES') {
                    $this->redirect('torob','Confirm the requested mutation by typing YES.','error');
                }
                $this->show_result('torob',(new TorobClient())->run($operation,$query,$body));
            case 'digikala_read':
                $op = sanitize_key((string)($_POST['operation']??''));
                $params = $this->decode_json((string)wp_unslash($_POST['params']??'{}'));
                if (is_wp_error($params)) { $this->redirect('digikala','Invalid JSON parameters','error'); }
                $this->show_result('digikala',(new DigikalaClient())->read($op,$params,$this->selected_account()));
            case 'import_spec':
                $f = $_FILES['spec']??null;
                if (!is_array($f)||($f['error']??1)!==UPLOAD_ERR_OK || ($f['size']??0)>6*1024*1024 || !is_uploaded_file((string)($f['tmp_name']??''))) {
                    $this->redirect('api','Select a valid local OpenAPI JSON file (up to 6 MB).','error');
                }
                $json = file_get_contents($f['tmp_name']);
                $n = OpenApiCatalog::import((string)$json);
                $this->redirect('api', is_wp_error($n)?$n->get_error_message():$n.' approved-path operations imported.',is_wp_error($n)?'error':'success');
            case 'spec_operation':
                $operation=sanitize_text_field(wp_unslash((string)($_POST['operation']??'')));
                $operations=OpenApiCatalog::all();
                if (!isset($operations[$operation])) { $this->redirect('api','Operation not found','error'); }
                $path=$this->decode_json((string)wp_unslash($_POST['path_vars']??'{}'));
                $query=$this->decode_json((string)wp_unslash($_POST['params']??'{}'));
                $bodyString=trim((string)wp_unslash($_POST['body']??''));
                $body=$bodyString===''?null:$this->decode_json($bodyString);
                if (is_wp_error($path)||is_wp_error($query)||is_wp_error($body)) { $this->redirect('api','Invalid JSON values','error'); }
                if ($operations[$operation]['method']!=='GET') {
                    $this->redirect('api','Unreviewed API mutations are disabled in this build. Use a reviewed workflow.','error');
                }
                $this->show_result('api',OpenApiCatalog::execute($operation,$path,$query,$body,$this->selected_account()));
            case 'add_mapping':
                $result=Sync::add_mapping(absint($_POST['wc_id']??0),trim((string)($_POST['variant_id']??'')),trim((string)($_POST['product_id']??'')),$this->selected_account());
                $this->redirect('mappings',is_wp_error($result)?$result->get_error_message():($result?'Product mapping saved':'Could not save mapping'),is_wp_error($result)||!$result?'error':'success');
            case 'delete_mapping':
                if (($_POST['confirm']??'')!=='REMOVE') { $this->redirect('mappings','Type REMOVE to confirm mapping deletion.','error'); }
                $done=Sync::remove_mapping(absint($_POST['mapping_id']??0));
                $this->redirect('mappings',$done?'Mapping removed':'Mapping not found',$done?'success':'error');
            default: $this->redirect('overview','Unknown action','error');
        }
    }
    private function selected_account(): string {
        $id=sanitize_key((string)($_POST['account']??'default'));
        return Accounts::exists('digikala',$id) ? $id : 'INVALID';
    }
    private function account_field(): void {
        echo '<div class="mbwc-field"><label for="mbwc-account">Digikala account</label><select id="mbwc-account" name="account">';
        foreach (Accounts::all('digikala') as $id=>$info) {
            echo '<option value="'.esc_attr($id).'">'.esc_html($info['label'].' ('.$id.')').'</option>';
        }
        echo '</select></div>';
    }
    private function decode_json(string $json): array|\WP_Error {
        if (strlen($json)>100000) { return new \WP_Error('mbwc_size','JSON exceeds limit'); }
        $v = json_decode($json,true,32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($v)) { return new \WP_Error('mbwc_json','Expected JSON object'); }
        return $v;
    }
    private function show_result(string $tab, array|\WP_Error $result): never {
        if (is_wp_error($result)) {
            $this->redirect($tab,$result->get_error_message().' '.$this->safe_error_data($result),'error');
        }
        $scrubbed=Redaction::scrub($result);
        $this->redirect($tab,(string)wp_json_encode($scrubbed,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'data');
    }
    private function safe_error_data(\WP_Error $error): string {
        $data=$error->get_error_data();
        return is_array($data) && isset($data['status']) ? '(HTTP '.(int)$data['status'].')' : '';
    }
    private function form_start(string $action, bool $multipart=false): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="mbwc-form"'.($multipart?' enctype="multipart/form-data"':'').'>';
        echo '<input type="hidden" name="action" value="mbwc_action">';
        echo '<input type="hidden" name="mbwc_action" value="'.esc_attr($action).'">';
        wp_nonce_field('mbwc_action');
    }
    private function form_end(string $label): void { echo '<p><button type="submit" class="button button-primary">'.esc_html($label).'</button></p></form>'; }
    public function render(): void {
        $tab = sanitize_key((string)($_GET['tab']??'overview'));
        $tabs=['overview'=>'Overview / نمای کلی','providers'=>'Connections / اتصال','torob'=>'Torob API','digikala'=>'Digikala API','mappings'=>'Mappings / نگاشت','api'=>'OpenAPI console','logs'=>'Logs / گزارش‌ها'];
        if (!isset($tabs[$tab])) { $tab='overview'; }
        echo '<div class="wrap mbwc" dir="'.(is_rtl()?'rtl':'ltr').'"><header class="mbwc-head"><div><p class="mbwc-kicker">WOOCOMMERCE MULTI-PROVIDER CONNECTOR</p><h1>MarketplaceBridge <small>0.2.0-alpha</small></h1><p>Torob + Digikala integration control center</p></div><span class="mbwc-tag">ALPHA · TEST BEFORE USE</span></header>';
        echo '<nav class="mbwc-nav" aria-label="MarketplaceBridge sections">';
        foreach ($tabs as $key=>$name) { echo '<a class="'.($tab===$key?'active':'').'" href="'.esc_url(add_query_arg(['page'=>'mbwc','tab'=>$key],admin_url('admin.php'))).'">'.esc_html($name).'</a>'; }
        echo '</nav>';
        $flash=get_transient('mbwc_flash_'.get_current_user_id());
        if (is_array($flash)) {
            delete_transient('mbwc_flash_'.get_current_user_id());
            echo '<div class="mbwc-alert '.esc_attr($flash['type']??'').'" role="status"><pre>'.esc_html($flash['message']??'').'</pre></div>';
        }
        switch($tab) {
            case 'providers':$this->providers();break;
            case 'torob':$this->torob();break;
            case 'digikala':$this->digikala();break;
            case 'api':$this->api();break;
            case 'mappings':$this->mappings();break;
            case 'logs':$this->logs();break;
            default:$this->overview();
        }
        echo '<footer class="mbwc-footer">Sensitive operations remain disabled until contracts and live permissions are verified. <a href="https://github.com/torob/Torob-Sync" target="_blank" rel="noopener noreferrer">Torob docs</a> · <a href="https://seller.digikala.com/open-api/v1/doc/" target="_blank" rel="noopener noreferrer">Digikala docs</a></footer></div>';
    }
    private function overview(): void {
        echo '<div class="mbwc-grid">';
        $cards=[
            ['Torob Product API',get_option('mbwc_torob_enabled','0')==='1'?'Enabled':'Disabled','Public feed requires verified EdDSA JWT'],
            ['Torob Shop API',Secrets::exists('torob','shop_token')?'Token configured':'Not configured','Account operations'],
            ['Digikala',Secrets::exists('digikala','access_token')?'Token configured':'Not configured','Seller Open API'],
            ['Mapped variants',(string)count(Sync::mappings(500)),'Most recent 500 mappings'],
        ];
        foreach($cards as $card) { echo '<section class="mbwc-card"><h2>'.esc_html($card[0]).'</h2><strong>'.esc_html($card[1]).'</strong><p>'.esc_html($card[2]).'</p></section>'; }
        echo '</div><section class="mbwc-panel"><h2>Available providers</h2><table class="widefat striped"><thead><tr><th>Provider</th><th>Capability</th><th>Status</th></tr></thead><tbody>';
        foreach ($this->registry->all() as $provider) { foreach ($provider->capabilities() as $name=>$state) {
            echo '<tr><td>'.esc_html($provider->label()).'</td><td>'.esc_html($name).'</td><td><code>'.esc_html($state).'</code></td></tr>';
        } }
        echo '</tbody></table></section>';
        echo '<section class="mbwc-panel"><h2>Torob order tracking endpoint</h2><p>Optional. Disabled by default. JWT-authenticated and restricted to attributed orders.</p><code>'.esc_html(home_url('/torob/v1/orders')).'</code></section>';
        echo '<section class="mbwc-panel"><h2>Torob product endpoint</h2><p>Provide this URL to Torob only after enabling the product API and verifying WooCommerce prices.</p><code>'.esc_html(home_url('/torob_api/v3/products')).'</code><p>REST fallback: <code>'.esc_html(rest_url('marketplacebridge/v1/torob/products')).'</code></p></section>';
    }
    private function secret_field(string $name,string $label,string $provider,string $field): void {
        echo '<div class="mbwc-field"><label for="'.esc_attr($name).'">'.esc_html($label).' <span class="mbwc-muted">'.(Secrets::exists($provider,$field)?'● Configured':'○ Not configured').'</span></label>';
        echo '<input autocomplete="off" type="password" id="'.esc_attr($name).'" name="'.esc_attr($name).'" value="" placeholder="Leave blank to keep existing secret" />';
        echo '<label class="mbwc-inline"><input type="checkbox" name="clear_'.esc_attr($name).'" value="1" /> Clear stored secret</label></div>';
    }
    private function providers(): void {
        $this->form_start('save_settings');
        echo '<div class="mbwc-grid"><section class="mbwc-panel"><h2>Torob / ترب</h2><p>Manage Product API v3 access, Shop API, and outbound product webhooks.</p>';
        echo '<label class="mbwc-inline"><input type="checkbox" name="torob_enabled" value="1" '.checked(get_option('mbwc_torob_enabled','0'),'1',false).'/> Enable authenticated product endpoint</label>';
        echo '<label class="mbwc-inline"><input type="checkbox" name="torob_webhooks" value="1" '.checked(get_option('mbwc_torob_webhooks','0'),'1',false).'/> Enable outgoing product change webhooks</label>';
        echo '<label class="mbwc-inline"><input type="checkbox" name="torob_attribution" value="1" '.checked(get_option('mbwc_torob_attribution','0'),'1',false).'/> Enable Torob order attribution (shares order status and click ID after checkout; requires suitable privacy disclosure)</label>';
        echo '<label class="mbwc-inline"><input type="checkbox" name="torob_actions" value="1" '.checked(get_option('mbwc_torob_actions','0'),'1',false).'/> Enable Torob custom action tracking (developer event hook only)</label>';
        $this->secret_field('torob_shop_token','Torob Shop API Bearer token','torob','shop_token');
        $this->secret_field('torob_webhook_token','Torob product webhook Bearer token','torob','webhook_token');
        echo '<label class="mbwc-inline"><input type="checkbox" name="torob_order_details" value="1" '.checked(get_option('mbwc_torob_order_details','0'),'1',false).' /> Share item totals and line-item URLs in order attribution (optional)</label>';
        echo '<label class="mbwc-inline"><input type="checkbox" name="torob_share_phone" value="1" '.checked(get_option('mbwc_torob_share_phone','0'),'1',false).' /> Share customer phone in attribution (separate privacy consent required)</label>';
        echo '</section><section class="mbwc-panel"><h2>Digikala / دیجی‌کالا</h2><p>Enter an authorized self-token from Seller Service Hub. No token or secret is shown in the browser after saving.</p>';
        $this->secret_field('digikala_access_token','Default Digikala Access Token','digikala','access_token');
        echo '<div class="mbwc-field"><label>Seller Code</label><input name="digikala_seller_code" value="'.esc_attr((string)get_option('mbwc_digikala_seller_code','')).'" /></div></section></div>';
        echo '<section class="mbwc-panel"><h2>Money & localization</h2><p>Explicit WooCommerce price input unit. Torob outputs prices in Toman (IRT).</p><select name="currency_unit"><option value="IRR" '.selected(get_option('mbwc_currency_unit','IRR'),'IRR',false).'>IRR (Rial) → divide by 10</option><option value="IRT" '.selected(get_option('mbwc_currency_unit','IRR'),'IRT',false).'>IRT (Toman) → no conversion</option></select></section>';
        $this->form_end('Save connections and settings');
        echo '<section class="mbwc-panel"><h2>Digikala seller accounts</h2><p>Isolate tokens and variant mapping per seller connection. A dedicated seller account token is required.</p><table class="widefat striped"><thead><tr><th>ID</th><th>Label</th><th>Seller</th><th>Token</th><th></th></tr></thead><tbody>';
        foreach(Accounts::all('digikala') as $id=>$account) {
            echo '<tr><td>'.esc_html($id).'</td><td>'.esc_html($account['label']).'</td><td>'.esc_html($account['seller_code']??'').'</td><td>'.(Accounts::token('digikala',$id)?'Configured':'Missing').'</td><td>';
            if ($id!=='default') { $this->form_start('remove_account'); echo '<input type="hidden" name="account_id" value="'.esc_attr($id).'" /><input name="confirm" aria-label="Type account ID to confirm removal" placeholder="Type '.esc_attr($id).'" required />';$this->form_end('Remove'); }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $this->form_start('save_account');
        echo '<div class="mbwc-inline-fields"><div class="mbwc-field"><label>Account ID (stable)</label><input required maxlength="40" name="account_id" pattern="[a-zA-Z][a-zA-Z0-9_-]*" placeholder="seller_two" /></div>';
        echo '<div class="mbwc-field"><label>Label</label><input required maxlength="90" name="account_label" /></div>';
        echo '<div class="mbwc-field"><label>Seller code</label><input maxlength="60" name="account_seller_code" /></div>';
        echo '<div class="mbwc-field"><label>Seller access token</label><input type="password" name="account_token" autocomplete="off" /></div></div>';
        $this->form_end('Add / update seller account'); echo '</section>';
    }
    private function torob(): void {
        echo '<div class="mbwc-grid"><section class="mbwc-panel"><h2>Shop API connection</h2><p>Uses the token from Connections. Returns the shop and product statistics.</p>';
        $this->form_start('test_torob');$this->form_end('Test Torob Shop API');echo '</section>';
        echo '<section class="mbwc-panel"><h2>Torob API operation</h2><p>All changes are manual. Charge transfers are intentionally unavailable.</p>';
        $this->form_start('torob_operation');echo '<div class="mbwc-field"><label>Operation</label><select name="operation">';
        foreach (TorobClient::OPERATIONS as $name=>$definition) { echo '<option value="'.esc_attr($name).'">'.esc_html($name.' ('.$definition[0].')').'</option>'; }
        echo '</select></div><div class="mbwc-field"><label>Query parameters (JSON object)</label><textarea name="params" rows="3">{}</textarea></div>';
        echo '<div class="mbwc-field"><label>Body (JSON object)</label><textarea name="body" rows="4">{}</textarea></div>';
        echo '<div class="mbwc-field"><label>For activate, deactivate or answer_report, type YES</label><input name="confirm" autocomplete="off" placeholder="YES" /></div>';
        $this->form_end('Execute Torob operation'); echo '</section></div>';
    }
    private function digikala(): void {
        echo '<div class="mbwc-grid"><section class="mbwc-panel"><h2>Digikala connection</h2><p>Validate seller token scopes.</p>';
        $this->form_start('test_digikala');$this->account_field();$this->form_end('Check scopes');echo '</section>';
        echo '<section class="mbwc-panel"><h2>Read-only operations</h2><p>Select a documented, read-only operation.</p>';
        $this->form_start('digikala_read');$this->account_field();echo '<div class="mbwc-field"><label>Operation</label><select name="operation">';
        foreach(DigikalaClient::READ_OPERATIONS as $id=>$def) { echo '<option value="'.esc_attr($id).'">'.esc_html($id).'</option>'; }
        echo '</select></div><div class="mbwc-field"><label>Optional parameters (JSON object)</label><textarea name="params" rows="5">{}</textarea></div>';
        $this->form_end('Read Digikala API');echo '</section></div>';
    }
    private function api(): void {
        $catalog=OpenApiCatalog::all();
        echo '<section class="mbwc-panel"><h2>Authorized OpenAPI operation catalog</h2><p>Upload the official seller Swagger JSON if your account can access it. Operations are restricted to seller.digikala.com/open-api/v1 and executed only after manual submission. Current imported operations: <strong>'.count($catalog).'</strong>.</p>';
        $this->form_start('import_spec',true); echo '<input type="file" name="spec" accept=".json,application/json" required />';
        $this->form_end('Import OpenAPI JSON');echo '</section>';
        if (!$catalog) { echo '<div class="mbwc-alert">No schema imported. Get an authorized OpenAPI JSON export from the Digikala seller panel.</div>';return; }
        $stats=[]; $read=0; $write=0;
        foreach($catalog as $op) { $stats[$op['tag']]=($stats[$op['tag']]??0)+1; if ($op['method']==='GET') {++$read;} else {++$write;} }
        arsort($stats);
        echo '<section class="mbwc-panel"><h2>Imported API inventory</h2><p><strong>'.(int)$read.' GET</strong> available for manual read-only requests · <strong>'.(int)$write.' writes</strong> catalogued but disabled · <strong>'.count($stats).' resource groups</strong>.</p><div class="mbwc-taglist">';
        foreach($stats as $tag=>$n){echo '<span>'.esc_html((string)$tag).' <strong>'.(int)$n.'</strong></span>';}
        echo '</div></section>';
        echo '<section class="mbwc-panel"><h2>Execute an imported operation</h2><p><strong>Safe mode:</strong> Imported operations are read-only. Writes require reviewed provider-specific workflows and are blocked.</p>';
        $this->form_start('spec_operation'); $this->account_field(); echo '<div class="mbwc-field"><label>Operation</label><select name="operation">';
        foreach($catalog as $id=>$op) { echo '<option value="'.esc_attr($id).'">'.esc_html('['.$op['method'].'] '.$op['tag'].' — '.$op['path']).'</option>'; }
        echo '</select></div><div class="mbwc-field"><label>Path variables (JSON object)</label><textarea rows="3" name="path_vars">{}</textarea></div>';
        echo '<div class="mbwc-field"><label>Query parameters (JSON object)</label><textarea rows="3" name="params">{}</textarea></div>';
        echo '<div class="mbwc-field"><label>Request body JSON, leave empty for GET</label><textarea rows="6" name="body"></textarea></div>';
        echo '<p>All imported write operations are shown for auditing but are disabled until their request schemas, scopes, and effects have been reviewed.</p>'; 
        $this->form_end('Execute selected operation'); echo '</section>';
    }
    private function mappings(): void {
        echo '<section class="mbwc-panel"><h2>Digikala product mapping</h2><p>Manually associate a WooCommerce product or variation with a verified Digikala variant ID. No automated stock/price writes are performed in this release.</p>';
        $this->form_start('add_mapping');$this->account_field();
        echo '<div class="mbwc-inline-fields"><div class="mbwc-field"><label>WooCommerce product/variation ID</label><input required type="number" min="1" name="wc_id" /></div>';
        echo '<div class="mbwc-field"><label>Digikala variant ID</label><input required inputmode="numeric" name="variant_id" pattern="[0-9]+" /></div>';
        echo '<div class="mbwc-field"><label>DKP (optional)</label><input name="product_id" /></div></div>';
        $this->form_end('Save mapping');echo '</section>';
        echo '<section class="mbwc-panel"><h2>Existing mappings</h2><table class="widefat striped"><thead><tr><th>Account</th><th>WooCommerce</th><th>Digikala variant</th><th>DKP</th><th>Modified (UTC)</th><th></th></tr></thead><tbody>';
        foreach(Sync::mappings() as $r) {
            echo '<tr><td>'.esc_html($r['account']).'</td><td><a href="'.esc_url(get_edit_post_link((int)$r['wc_product_id'])).'">#'.(int)$r['wc_product_id'].'</a></td><td>'.esc_html($r['external_variant_id']).'</td><td>'.esc_html($r['external_product_id']).'</td><td>'.esc_html($r['updated_at']).'</td><td>';
            $this->form_start('delete_mapping');echo '<input type="hidden" name="mapping_id" value="'.(int)$r['id'].'" /><input name="confirm" aria-label="Type REMOVE to confirm mapping deletion" placeholder="REMOVE" required />';
            $this->form_end('Remove');echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
    private function logs(): void {
        echo '<section class="mbwc-panel"><h2>Last 100 operations (UTC)</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Provider</th><th>Level</th><th>Action</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
        foreach(Logger::recent(100) as $row) { echo '<tr>'; foreach(['created_at','provider','level','action','status','detail'] as $field) { echo '<td>'.esc_html($row[$field]).'</td>'; } echo '</tr>'; }
        echo '</tbody></table></section>';
    }
}
