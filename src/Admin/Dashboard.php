<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Admin;

use MarketplaceBridge\WooCommerce\Application\Sync;
use MarketplaceBridge\WooCommerce\Core\Registry;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Infrastructure\Redaction;
use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;
use MarketplaceBridge\WooCommerce\Providers\Digikala\Client as DigikalaClient;
use MarketplaceBridge\WooCommerce\Providers\Digikala\OpenApiCatalog;
use MarketplaceBridge\WooCommerce\Providers\Torob\Client as TorobClient;
use MarketplaceBridge\WooCommerce\Providers\Torob\Webhook;
use WP_Error;

final class Dashboard {
    public function __construct(private Registry $registry) {}

    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_mbwc_action', [$this, 'handle']);
    }

    public function menu(): void {
        add_menu_page('MarketplaceBridge', Screen::t('Marketplace Bridge'), 'manage_options', 'mbwc',
            [$this, 'render'], 'dashicons-update', 56);
    }

    public function enqueue(string $hook): void {
        if ($hook === 'toplevel_page_mbwc') {
            $version = filemtime(MBWC_PATH.'assets/admin.css');
            wp_enqueue_style('mbwc-admin', MBWC_URL.'assets/admin.css', [], $version ? (string)$version : MBWC_VERSION);
        }
    }

    public static function field(array $input, string $key, string $default = ''): string {
        $value = $input[$key] ?? $default;
        return is_string($value) ? trim(wp_unslash($value)) : $default;
    }

    public static function integer(string $value): int {
        $value = self::digits($value);
        $id = (int)$value;
        return $id > 0 && (string)$id === $value ? $id : 0;
    }

    private static function digits(string $value): string {
        return strtr($value, [
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
        ]);
    }

    private static function flash_key(string $id): string {
        return 'mbwc_result_'.get_current_user_id().'_'.$id;
    }

    public static function health_key(string $provider, string $account = ''): string {
        return 'mbwc_health_'.$provider.'_'.hash('sha256', $account);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) { wp_die(Screen::t('Access denied.'), '', ['response'=>403]); }
        $id = self::field($_GET, 'result');
        $flash = [];
        if (preg_match('/^[a-f0-9-]{36}$/D', $id)) {
            $key = self::flash_key($id);
            $value = get_transient($key);
            if (is_array($value)) {
                $flash = $value;
                delete_transient($key);
            }
        }
        (new Screen($this->registry, $_GET, $flash))->render();
    }

    private function redirect(string $tab, string|WP_Error $message, string $type = 'success', array $context = [], array $extra = []): never {
        if (is_wp_error($message)) {
            $data = $message->get_error_data();
            $http = is_array($data) ? (int)($data['status'] ?? 0) : 0;
            $message = Screen::t($message->get_error_message()).($http ? ' (HTTP '.$http.')' : '');
            $type = 'error';
        } else {
            $message = Screen::t($message);
        }
        $id = wp_generate_uuid4();
        set_transient(self::flash_key($id), ['text'=>$message, 'type'=>$type] + $extra, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(Screen::url($tab, ['result'=>$id] + $context), 303);
        exit;
    }

    public function handle(): void {
        if (!current_user_can('manage_options')) { wp_die(Screen::t('Access denied.'), '', ['response'=>403]); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die(Screen::t('Use POST for this operation.'), '', ['response'=>405]); }

        $action = self::field($_POST, 'mbwc_action');
        check_admin_referer('mbwc_'.$action);
        $account = array_key_exists('account', $_POST) ? self::field($_POST, 'account') : 'default';
        $operation = self::field($_POST, 'operation');
        switch ($action) {
            case 'save_torob':
                $this->save_torob();
            case 'save_account':
                $id = self::field($_POST, 'account_id');
                $label = sanitize_text_field(self::field($_POST, 'account_label'));
                $seller = self::field($_POST, 'account_seller_code');
                $token = self::field($_POST, 'account_token');
                $result = Accounts::save('digikala', $id, $label, $seller, $token, self::field($_POST, 'clear_token') === '1',
                    self::field($_POST, 'create_account') === '1');
                if ($result !== true) {
                    $context = Accounts::exists('digikala', $id) ? ['edit_account'=>$id] : ['edit_account'=>'~new'];
                    $this->redirect('providers', is_wp_error($result) ? $result : 'Could not save the account.', 'error', $context);
                }
                delete_transient(self::health_key('digikala', $id));
                $this->redirect('providers', 'Seller account saved.', 'success', ['edit_account'=>$id]);

            case 'remove_account':
                $id = self::field($_POST, 'account_id');
                if (self::field($_POST, 'confirm') !== $id || $id === 'default') {
                    $this->redirect('providers', 'Confirm account removal with its ID.', 'error');
                }
                if (!Accounts::remove('digikala', $id)) {
                    $this->redirect('providers', Sync::hasAccountMappings($id)
                        ? 'Remove this account’s product mappings first.' : 'Could not remove the account.', 'error', ['edit_account'=>$id]);
                }
                delete_transient(self::health_key('digikala', $id));
                $this->redirect('providers', 'Account removed.');

            case 'test_torob':
                $result = (new TorobClient())->run('shop');
                $this->remember('torob', '', $result);
                $this->show_result('torob', $result, ['panel'=>'shop']);
            case 'test_digikala':
                $result = (new DigikalaClient())->read('scopes', [], $account);
                if (Accounts::exists('digikala', $account)) { $this->remember('digikala', $account, $result); }
                $this->show_result('digikala', $result, ['account'=>$account]);
            case 'torob_operation':
                $this->torob_operation($operation);
            case 'digikala_read':
                $context = ['account'=>$account, 'op'=>$operation];
                $definition = DigikalaClient::READ_OPERATIONS[$operation] ?? null;
                if (!$definition) { $this->redirect('digikala', 'Choose an available operation.', 'error', $context); }
                [$endpoint, $names] = $definition;
                if (str_contains($endpoint, '{variant_id}')) { $names[] = 'variant_id'; }
                $parameters = $this->parameters($names, 'q_');
                $this->show_result('digikala', (new DigikalaClient())->read($operation, $parameters, $account), $context);

            case 'import_spec':
                $file = $_FILES['spec'] ?? null;
                if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK
                    || ($file['size'] ?? 0) > 6 * 1024 * 1024 || !is_string($file['tmp_name'] ?? null)
                    || !is_uploaded_file($file['tmp_name'])) {
                    $this->redirect('api', 'Select a local OpenAPI JSON file up to 6 MB.', 'error');
                }
                $json = file_get_contents($file['tmp_name'], false, null, 0, 6 * 1024 * 1024 + 1);
                $result = OpenApiCatalog::import((string)$json);
                if (is_wp_error($result)) { $this->redirect('api', $result); }
                $this->redirect('api', 'OpenAPI catalog imported.');

            case 'spec_operation':
                $context = ['op'=>$operation, 'account'=>$account];
                $selected = OpenApiCatalog::all()[$operation] ?? null;
                if (!$selected || $selected['method'] !== 'GET') {
                    $this->redirect('api', 'Choose a read-only operation.', 'error', $context);
                }
                $this->show_result('api', OpenApiCatalog::execute($operation, $this->parameters($selected['paths'], 'p_'),
                    $this->parameters($selected['query'], 'q_'), null, $account), $context);

            case 'add_mapping':
                $this->save_mapping($account);
            case 'delete_mapping':
                if (self::field($_POST, 'confirm') !== '1') {
                    $this->redirect('mappings', 'Confirm removal of the mapping.', 'error');
                }
                $id = self::integer(self::field($_POST, 'mapping_id'));
                $result = Sync::remove_mapping($id);
                $this->redirect('mappings', $result ? 'Mapping removed.' : 'Could not remove the mapping.', $result ? 'success' : 'error',
                    ['filter_account'=>self::field($_POST, 'filter_account')]);

            case 'retry_torob':
                $id = self::integer(self::field($_POST, 'product_id'));
                $revision = self::integer(self::field($_POST, 'revision'));
                $result = Webhook::retry($id, $revision);
                $this->redirect('torob', $result ? 'Product queued for retry.' : 'Could not retry this item. Check the connection and refresh the queue.',
                    $result ? 'success' : 'error', ['panel'=>'queue']);
            default:
                $this->redirect('overview', 'Unknown operation.', 'error');
        }
    }

    private function save_torob(): never {
        $unit = self::field($_POST, 'currency_unit');
        $flags = [];
        foreach (['enabled', 'webhooks', 'attribution', 'actions', 'order_details', 'share_phone'] as $name) {
            $flags[$name] = self::field($_POST, $name) === '1';
        }
        if ($flags['enabled'] && !in_array($unit, ['IRR', 'IRT'], true)) {
            $this->redirect('providers', 'Select the WooCommerce price unit before enabling Torob.', 'error');
        }
        if (($flags['enabled'] || $flags['attribution'] || $flags['actions']) && !function_exists('sodium_crypto_sign_verify_detached')) {
            $this->redirect('providers', 'Enable PHP Sodium before turning on a Torob endpoint.', 'error');
        }

        $changes = [];
        foreach (['shop_token', 'webhook_token'] as $field) {
            $token = self::field($_POST, $field);
            $clear = self::field($_POST, 'clear_'.$field) === '1';
            if (!Secrets::valid_token($token) || ($clear && $token !== '')) {
                $this->redirect('providers', 'Enter a valid token or select clear.', 'error');
            }
            if ($clear || $token !== '') { $changes[$field] = $token; }
        }
        $hasWebhook = array_key_exists('webhook_token', $changes)
            ? $changes['webhook_token'] !== '' : Secrets::exists('torob', 'webhook_token');
        if ($flags['webhooks'] && (!$flags['enabled'] || !$hasWebhook)) {
            $this->redirect('providers', 'Webhooks require the product feed and a webhook token.', 'error');
        }

        $options = [];
        if (in_array($unit, ['IRR', 'IRT'], true)) { $options['mbwc_currency_unit'] = $unit; }
        foreach ($flags as $name=>$enabled) { $options['mbwc_torob_'.$name] = $enabled ? '1' : '0'; }
        $outcome = Database::locked('torob_settings', static function () use ($changes, $options): bool|WP_Error {
            $hasWebhook = array_key_exists('webhook_token', $changes)
                ? $changes['webhook_token'] !== '' : Secrets::exists('torob', 'webhook_token');
            if ($options['mbwc_torob_webhooks'] === '1' && !$hasWebhook) {
                return new WP_Error('mbwc_webhook_config', 'Webhooks require the product feed and a webhook token.');
            }
            $previous = [];
            foreach ($changes as $field=>$token) {
                $key = Secrets::option_name('torob', $field);
                $previous[$key] = get_option($key, false);
                if (!Secrets::set('torob', $field, $token)) { self::restore_options($previous); return false; }
            }
            foreach ($options as $key=>$value) {
                $previous[$key] = get_option($key, false);
                if (!update_option($key, $value, false) && get_option($key) !== $value) {
                    self::restore_options($previous);
                    return false;
                }
            }
            return true;
        });
        if ($outcome !== true) {
            $this->redirect('providers', is_wp_error($outcome) ? $outcome : 'Could not save Torob settings.', 'error');
        }
        if (array_key_exists('shop_token', $changes)) { delete_transient(self::health_key('torob')); }
        (new Webhook())->ensure_schedule();
        $this->redirect('providers', 'Torob settings saved.');
    }

    private static function restore_options(array $previous): void {
        foreach ($previous as $key=>$value) {
            if ($value === false) { delete_option($key); }
            else { update_option($key, $value, false); }
        }
    }

    private function save_mapping(string $account): never {
        $id = self::integer(self::field($_POST, 'wc_id'));
        $variant = substr(self::digits(self::field($_POST, 'variant_id')), 0, 80);
        $dkp = substr(self::digits(self::field($_POST, 'product_id')), 0, 80);
        $edit = self::integer(self::field($_POST, 'mapping_id'));
        $context = ['account'=>$account, 'wc_id'=>$id, 'edit_mapping'=>$edit];
        $extra = ['mapping'=>['variant_id'=>$variant, 'product_id'=>$dkp]];
        if (self::field($_POST, 'verify_variant') === '1') {
            $found = (new DigikalaClient())->read('variant', ['variant_id'=>$variant], $account);
            if (is_wp_error($found)) { $this->redirect('mappings', $found, 'error', $context, $extra); }
        }
        $result = Sync::add_mapping($id, $variant, $dkp, $account, $edit, $edit ? self::field($_POST, 'mapping_revision') : null);
        if ($result !== true) {
            $this->redirect('mappings', is_wp_error($result) ? $result : 'Could not save the mapping.', 'error', $context, $extra);
        }
        $this->redirect('mappings', 'Mapping saved.', 'success', ['filter_account'=>$account]);
    }

    private function torob_operation(string $operation): never {
        $context = ['panel'=>'shop', 'op'=>$operation];
        if (!isset(TorobClient::OPERATIONS[$operation])) { $this->redirect('torob', 'Choose an available operation.', 'error', $context); }
        $write = in_array($operation, ['activate', 'deactivate', 'answer_report'], true);
        if ($write && self::field($_POST, 'confirm') !== '1') {
            $this->redirect('torob', 'Confirm the change before submitting.', 'error', $context);
        }
        $query = $this->parameters(Screen::torob_parameters($operation), 'q_');
        $body = null;
        if (in_array($operation, ['products', 'activate', 'deactivate'], true)) {
            $ids = $this->list_input('product_ids');
            $body = $ids ? ['product_ids'=>$ids] : [];
            if ($operation === 'products') { $body += $this->parameters(['status', 'updated_since'], 'b_'); }
        } elseif ($operation === 'answer_report') {
            $body = ['report_ids'=>array_map([self::class, 'integer'], $this->list_input('report_ids'))]
                + $this->parameters(['status', 'description'], 'b_');
        }
        $this->show_result('torob', (new TorobClient())->run($operation, $query, $body), $context);
    }

    private function list_input(string $field): array {
        $input = self::field($_POST, $field);
        if (strlen($input) > 100000) { return array_fill(0, 5001, ''); }
        return array_values(array_unique(preg_split('/[\s,،]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    private function parameters(array $names, string $prefix): array {
        $result = [];
        foreach ($names as $name) {
            $value = self::field($_POST, $prefix.bin2hex($name));
            if (in_array($name, ['variant_id', 'date', 'page', 'page_size', 'updated_since'], true)) { $value = self::digits($value); }
            if ($value !== '') { $result[$name] = $value; }
        }
        return $result;
    }

    private function remember(string $provider, string $account, array|WP_Error $result): void {
        set_transient(self::health_key($provider, $account), ['at'=>time(), 'ok'=>!is_wp_error($result)], DAY_IN_SECONDS);
    }

    private function show_result(string $tab, array|WP_Error $result, array $context = []): never {
        if (is_wp_error($result)) { $this->redirect($tab, $result, 'error', $context); }
        $json = wp_json_encode(Redaction::scrub($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $extra = is_string($json) && strlen($json) <= 48000 ? ['json'=>$json] : [];
        $this->redirect($tab, $extra ? 'Request completed.' : 'Request completed. The response is too large to display here.', 'success', $context, $extra);
    }
}
