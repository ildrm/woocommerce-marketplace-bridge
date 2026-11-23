<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Admin;

use MarketplaceBridge\WooCommerce\Application\Sync;
use MarketplaceBridge\WooCommerce\Core\Registry;
use MarketplaceBridge\WooCommerce\Infrastructure\Accounts;
use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Infrastructure\Logger;
use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;
use MarketplaceBridge\WooCommerce\Providers\Digikala\Client as DigikalaClient;
use MarketplaceBridge\WooCommerce\Providers\Digikala\OpenApiCatalog;
use MarketplaceBridge\WooCommerce\Providers\Torob\Feed;
use MarketplaceBridge\WooCommerce\Providers\Torob\Webhook;
use WC_Data_Store;
use WC_Product;

final class Screen {
    private const TABS = [
        'overview'=>'Overview', 'providers'=>'Connections', 'torob'=>'Torob', 'digikala'=>'Digikala',
        'mappings'=>'Mappings', 'api'=>'API catalog', 'logs'=>'Activity',
    ];

    public function __construct(private Registry $registry, private array $query, private array $flash) {}

    public static function t(string $label): string { return __($label, 'marketplacebridge-for-woocommerce'); }
    private static function label(string $label): string { return esc_html(self::t($label)); }
    public static function url(string $tab, array $args = []): string {
        return admin_url('admin.php').'?'.http_build_query(['page'=>'mbwc', 'tab'=>$tab] + $args, '', '&', PHP_QUERY_RFC3986);
    }

    private function query(string $name, string $default = ''): string {
        return Dashboard::field($this->query, $name, $default);
    }

    public function render(): void {
        $tab = $this->query('tab', 'overview');
        if (!isset(self::TABS[$tab])) { $tab = 'overview'; }
        echo '<div class="wrap mbwc" dir="'.(is_rtl() ? 'rtl' : 'ltr').'">';
        echo '<header class="mbwc-head"><h1>'.self::label('Marketplace Bridge').'</h1>';
        echo '<p>'.self::label('Manage WooCommerce connections to Torob and Digikala.').'</p></header>';
        echo '<nav class="mbwc-nav" aria-label="'.esc_attr(self::t('Marketplace navigation')).'">';
        foreach (self::TABS as $key=>$label) { $this->link($key, $label, [], $tab === $key ? 'current' : ''); }
        echo '</nav>';
        if (isset($this->flash['text'])) {
            $this->notice($this->flash['text'], ($this->flash['type'] ?? '') === 'error' ? 'error' : 'success');
            if (isset($this->flash['json'])) { $this->json($this->flash['json']); }
        }
        if (get_option('mbwc_schema_version') !== Database::VERSION) {
            $this->notice('Database setup is incomplete. Refresh in one minute. If it persists, check database permissions.', 'error');
        }
        match ($tab) {
            'providers'=>$this->providers(), 'torob'=>$this->torob(), 'digikala'=>$this->digikala(),
            'mappings'=>$this->mappings(), 'api'=>$this->api(), 'logs'=>$this->logs(), default=>$this->overview(),
        };
        echo '</div>';
    }

    private function overview(): void {
        echo '<div class="mbwc-grid">';
        $this->panel('Torob product publishing');
        $this->status('Product feed', $this->flag('enabled') ? 'Enabled' : 'Disabled', $this->flag('enabled') ? 'ready' : '');
        $webhooks = Webhook::enabled();
        $this->status('Product change notifications', $webhooks ? 'Enabled' : 'Disabled', $webhooks ? 'ready' : '');
        $queue = Webhook::queue_status();
        if (!is_wp_error($queue)) {
            $this->status('Pending products', number_format_i18n($queue['pending']));
            $this->status('Failed products', number_format_i18n($queue['failed']), $queue['failed'] ? 'warning' : '');
        }
        echo '<div class="mbwc-actions">';
        if ($this->flag('enabled')) {
            $this->link('torob', 'Preview a product', [], 'button button-primary');
        } else {
            $this->link('providers', 'Configure Torob', [], 'button button-primary');
            $this->link('torob', 'Preview a product', [], 'button');
        }
        $this->link('torob', 'View delivery queue', ['panel'=>'queue'], 'button');
        echo '</div></section>';

        $this->panel('Digikala seller access');
        $accounts = Accounts::all('digikala');
        $configured = count(array_filter(array_keys($accounts), static fn($id) => Accounts::token('digikala', $id) !== ''));
        $this->status('Accounts with a token', number_format_i18n($configured));
        $this->status('Publish products, prices or stock', 'Unavailable', 'warning');
        $mappings = Sync::list_mappings(1, '', 1);
        if (!is_wp_error($mappings)) { $this->status('Product mappings', number_format_i18n($mappings['count'])); }
        echo '<div class="mbwc-actions">';
        $this->link('providers', 'Manage connections', [], 'button button-primary', '#mbwc-accounts');
        $this->link('mappings', 'Manage mappings', [], 'button');
        echo '</div></section></div>';

        if (count($this->registry->all()) > 2) {
            $this->panel('Additional integrations');
            echo '<ul>';
            foreach ($this->registry->all() as $key=>$provider) {
                if (!in_array($key, ['torob', 'digikala'], true)) { echo '<li>'.esc_html($provider->label()).'</li>'; }
            }
            echo '</ul></section>';
        }
    }

    private function providers(): void {
        $this->panel('Torob settings');
        $this->post('save_torob');
        $unit = (string)get_option('mbwc_currency_unit', get_woocommerce_currency());
        echo '<div class="mbwc-grid">';
        echo '<div><h3>'.self::label('Product publishing').'</h3>';
        $this->select('currency_unit', 'WooCommerce price unit', [''=>'Choose a unit', 'IRR'=>'IRR — Rial', 'IRT'=>'IRT — Toman'], $unit);
        $this->help('The feed reports whole Toman. Preview a real product to check the conversion.');
        $this->check('enabled', 'Enable the authenticated product feed', $this->flag('enabled'));
        $this->check('webhooks', 'Notify Torob about product changes', $this->flag('webhooks'));
        $this->help('Change notifications require the feed, a webhook token and a working WordPress cron.');
        $this->credential('webhook_token', 'Webhook token', Secrets::exists('torob', 'webhook_token'));
        echo '</div><div><h3>'.self::label('Shop API and attribution').'</h3>';
        $this->credential('shop_token', 'Shop API token', Secrets::exists('torob', 'shop_token'));
        echo '<details class="mbwc-details"><summary>'.self::label('Order and action attribution').'</summary>';
        $this->check('attribution', 'Share status for Torob-referred orders', $this->flag('attribution'));
        $this->check('order_details', 'Include item prices and shipping for attributed orders', $this->flag('order_details'));
        $this->check('share_phone', 'Include the customer phone for attributed orders', $this->flag('share_phone'));
        $this->check('actions', 'Expose actions recorded by extensions', $this->flag('actions'));
        $this->help('Order details and phone sharing apply only when order attribution is enabled.');
        echo '</details></div></div>';
        $this->end('Save Torob settings');
        echo '</section>';

        $this->panel('Digikala seller accounts', 'mbwc-accounts');
        $this->help('Use a separate access token for each seller. Product mappings belong to one account.');
        $accounts = Accounts::all('digikala');
        $this->table(['Account', 'Seller code', 'Token', 'Last connection test', 'Actions']);
        foreach ($accounts as $id=>$account) {
            $token = Accounts::token('digikala', $id) !== '';
            echo '<tr><td><strong>'.self::label($account['label']).'</strong> <bdi class="mbwc-muted">'.esc_html($id).'</bdi></td>';
            echo '<td><bdi>'.esc_html($account['seller_code'] ?: '—').'</bdi></td><td>';
            $this->badge($token ? 'Configured' : 'Missing', $token ? 'ready' : 'warning');
            echo '</td><td>'; $this->health('digikala', $id); echo '</td><td>';
            $this->link('providers', 'Edit', ['edit_account'=>$id], 'button', '#mbwc-account-editor');
            echo '</td></tr>';
        }
        $this->end_table();
        echo '<div class="mbwc-actions">';
        $this->link('providers', 'Add seller account', ['edit_account'=>'~new'], 'button button-primary', '#mbwc-account-editor');
        $this->link('digikala', 'Test a seller connection', [], 'button');
        echo '</div></section>';

        $id = $this->query('edit_account');
        $new = $id === '~new';
        if ($new || isset($accounts[$id])) {
            $account = $accounts[$id] ?? ['label'=>'', 'seller_code'=>''];
            $this->panel($new ? 'Add seller account' : 'Edit seller account', 'mbwc-account-editor');
            $this->post('save_account');
            echo '<div class="mbwc-fields">';
            if ($new) {
                $this->hidden('create_account', '1');
                $this->input('account_id', 'Account ID', '', ['required'=>true, 'maxlength'=>'40', 'pattern'=>'[A-Za-z][A-Za-z0-9_\-]*', 'dir'=>'ltr']);
            } else {
                $this->hidden('account_id', $id);
            }
            $this->input('account_label', 'Display name', $account['label'], ['required'=>true, 'maxlength'=>'90']);
            $this->input('account_seller_code', 'Seller code', $account['seller_code'], ['maxlength'=>'60', 'dir'=>'ltr']);
            echo '</div>';
            $this->credential('account_token', 'Seller access token', !$new && Accounts::token('digikala', $id) !== '', 'clear_token');
            $this->end($new ? 'Create account' : 'Save account');
            if (!$new && $id !== 'default') {
                echo '<details class="mbwc-details"><summary>'.self::label('Remove account').'</summary>';
                $this->help('Remove the account’s mappings before removing the connection.');
                $this->post('remove_account');
                $this->hidden('account_id', $id);
                $this->input('confirm', 'Type the account ID', '', ['required'=>true, 'placeholder'=>$id, 'dir'=>'ltr']);
                $this->end('Remove account', 'button mbwc-danger');
                echo '</details>';
            }
            echo '</section>';
        }
    }

    private function torob(): void {
        $panel = $this->query('panel', 'feed');
        if (!in_array($panel, ['feed', 'queue', 'shop'], true)) { $panel = 'feed'; }
        echo '<nav class="mbwc-subnav" aria-label="'.esc_attr(self::t('Torob tools')).'">';
        foreach (['feed'=>'Product feed', 'queue'=>'Delivery queue', 'shop'=>'Shop API'] as $key=>$title) {
            $this->link('torob', $title, ['panel'=>$key], $panel === $key ? 'current' : '');
        }
        echo '</nav>';
        match ($panel) { 'queue'=>$this->queue(), 'shop'=>$this->shop(), default=>$this->feed() };
    }

    private function feed(): void {
        $this->panel('Torob endpoints');
        $pretty = (string)get_option('permalink_structure') !== '';
        $this->endpoint('Product feed', '/torob_api/v3/products', 'products', $this->flag('enabled'), $pretty);
        if ($this->flag('attribution')) { $this->endpoint('Order attribution', '/torob/v1/orders', 'orders', true, $pretty); }
        if ($this->flag('actions')) { $this->endpoint('Action attribution', '/torob/v1/actions', 'actions', true, $pretty); }
        if (!$pretty) { $this->help('Plain permalinks require the WordPress REST URL.'); }
        $this->link('providers', 'Change Torob settings', [], 'button');
        echo '</section>';
        $this->panel('Preview a product');
        $this->help('This preview uses your WooCommerce data and sends no request to Torob.');
        $this->get('torob');
        $this->input('wc_id', 'WooCommerce product or variation ID', $this->query('wc_id'), ['required'=>true, 'inputmode'=>'numeric', 'dir'=>'ltr']);
        $this->end('Preview');
        $id = Dashboard::integer($this->query('wc_id'));
        if (!$id && $this->query('wc_id') !== '') {
            $this->notice('Enter a valid WooCommerce ID.', 'error');
        } elseif ($id) {
            $product = (new Feed())->preview($id);
            if (is_wp_error($product)) { $this->notice($product->get_error_message(), 'error'); }
            else {
                $this->status('Exported price in Toman', number_format_i18n($product['current_price']));
                $this->status('Availability', $product['availability'] ? 'Available' : 'Unavailable', $product['availability'] ? 'ready' : 'warning');
                $this->json((string)wp_json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        echo '</section>';
    }

    private function endpoint(string $label, string $route, string $rest, bool $enabled, bool $pretty): void {
        echo '<div class="mbwc-endpoint"><strong>'.self::label($label).'</strong> ';
        $this->badge($enabled ? 'Enabled' : 'Disabled', $enabled ? 'ready' : '');
        if ($pretty) { echo '<code dir="ltr">'.esc_html(home_url($route)).'</code>'; }
        echo '<span class="mbwc-muted">'.self::label('WordPress REST URL').'</span><code dir="ltr">'.esc_html(rest_url('marketplacebridge/v1/torob/'.$rest)).'</code></div>';
    }

    private function queue(): void {
        $this->panel('Product delivery queue');
        $queue = Webhook::queue_status(true, $this->page());
        if (is_wp_error($queue)) { $this->notice($queue->get_error_message(), 'error'); echo '</section>'; return; }
        $scheduled = wp_next_scheduled(Webhook::HOOK);
        $webhooks = Webhook::enabled();
        $this->status('Pending products', number_format_i18n($queue['pending']));
        $this->status('Failed products', number_format_i18n($queue['failed']), $queue['failed'] ? 'warning' : '');
        $this->status('Next scheduled run', $scheduled ? $this->date($scheduled) : 'Unscheduled');
        if (!$webhooks) { $this->notice('Delivery is paused. Enable the product feed and notifications and configure the webhook token.'); }
        elseif (!$scheduled || $scheduled < time() - 300) { $this->notice('WordPress cron is overdue or unscheduled. Check the site’s cron runner.', 'error'); }
        if (!$queue['rows']) { $this->notice('No products are waiting for delivery.'); }
        else {
            $this->table(['WooCommerce product', 'State', 'Time', 'Last error', 'Actions']);
            foreach ($queue['rows'] as $row) {
                $failed = (int)$row['attempts'] >= Webhook::MAX_ATTEMPTS;
                echo '<tr><td>'; $this->product_link((int)$row['product_id']); echo '</td><td>';
                $this->badge($failed ? 'Failed' : ((int)$row['attempts'] ? 'Retry scheduled' : 'Pending'), $failed ? 'warning' : '');
                echo '</td><td>'.esc_html($this->date((int)$row['due_at'])).'</td><td><bdi>'.esc_html($row['last_error'] ?: '—').'</bdi></td><td>';
                if ($failed && $webhooks) {
                    $this->post('retry_torob');
                    $this->hidden('product_id', (string)$row['product_id']);
                    $this->hidden('revision', (string)$row['revision']);
                    $this->end('Retry', 'button');
                }
                echo '</td></tr>';
            }
            $this->end_table();
            $this->pagination('torob', $queue['page'], $queue['pages'], ['panel'=>'queue']);
        }
        echo '</section>';
    }

    public static function torob_parameters(string $op): array {
        return match ($op) {
            'products'=>['page_size', 'cursor', 'domain'],
            'clicks'=>['date', 'click_type', 'is_guaranteed', 'page', 'page_size', 'domain'],
            'price_reports'=>['active_reports', 'page', 'page_size', 'domain'],
            'transfer_preview'=>['destination'],
            default=>['domain'],
        };
    }

    private function shop(): void {
        $this->panel('Torob Shop API');
        $this->status('Shop API token', Secrets::exists('torob', 'shop_token') ? 'Configured' : 'Missing');
        echo '<div class="mbwc-actions">';
        $this->post('test_torob'); $this->end('Test shop connection', 'button');
        $this->health('torob');
        echo '</div></section>';
        $operations = [
            'shop'=>self::t('Shop details'), 'products'=>self::t('Find products on Torob'),
            'clicks'=>self::t('Click report'), 'price_reports'=>self::t('Price reports'),
            'activate'=>self::t('Activate products on Torob'), 'deactivate'=>self::t('Deactivate products on Torob'),
            'answer_report'=>self::t('Answer price reports'), 'transfer_preview'=>self::t('Check transfer eligibility'),
        ];
        $op = $this->query('op', 'shop');
        if (!isset($operations[$op])) { $op = 'shop'; }
        $this->panel('Shop operations');
        $this->get('torob', ['panel'=>'shop']);
        $this->select('op', 'Operation', $operations, $op, true);
        $this->end('Open operation', 'button');
        echo '<hr><h3>'.esc_html($operations[$op]).'</h3>';
        $this->post('torob_operation');
        $this->hidden('operation', $op);
        echo '<div class="mbwc-fields">';
        foreach (self::torob_parameters($op) as $name) {
            $this->parameter($name, 'q_', in_array($name, ['date', 'destination'], true));
        }
        if ($op === 'products') {
            $this->parameter('status', 'b_');
            $this->parameter('updated_since', 'b_');
        }
        echo '</div>';
        if (in_array($op, ['products', 'activate', 'deactivate'], true)) {
            $this->textarea('product_ids', 'Torob product IDs', $op !== 'products');
            $this->help('Separate IDs with commas, spaces or new lines.');
        }
        if ($op === 'answer_report') {
            $this->textarea('report_ids', 'Report IDs', true);
            $this->select('b_'.bin2hex('status'), 'Resolution', [
                ''=>'Choose a resolution', 'claimed_to_be_corrected'=>'Corrected', 'claimed_to_be_invalid'=>'Report is invalid',
            ], '', false, true);
            $this->textarea('b_'.bin2hex('description'), 'Explanation', false);
        }
        if (in_array($op, ['activate', 'deactivate', 'answer_report'], true)) {
            $this->check('confirm', 'Apply this change to the specified IDs on Torob.', false, true);
        }
        $this->end('Submit operation');
        echo '</section>';
    }

    private function digikala(): void {
        $account = $this->account();
        $this->panel('Test a seller connection');
        $this->post('test_digikala');
        $this->account_field($account);
        $this->end('Check access scopes');
        $this->health('digikala', $account);
        echo '</section>';

        $this->panel('Read seller data');
        $this->help('This connection can read seller data. Publishing products or updating prices and stock is unavailable.');
        $ops = [
            'health'=>'API health', 'scopes'=>'Access scopes', 'categories'=>'Category tree',
            'seller_products'=>'Seller products', 'variants'=>'Variants', 'variant'=>'Variant details',
            'variant_seller_stock'=>'Variant seller stock', 'orders'=>'Orders', 'order_statistics'=>'Order statistics',
            'order_history'=>'Order history', 'inventories'=>'Inventory', 'packages'=>'Packages', 'packages_warehouses'=>'Warehouses',
        ];
        $op = $this->query('op', 'variants');
        if (!isset(DigikalaClient::READ_OPERATIONS[$op])) { $op = 'variants'; }
        $this->get('digikala', ['account'=>$account]);
        $this->select('op', 'Operation', $ops, $op);
        $this->end('Open operation', 'button');
        echo '<hr><h3>'.self::label($ops[$op]).'</h3>';
        $this->post('digikala_read');
        $this->hidden('operation', $op);
        $this->account_field($account);
        [$path, $fields] = DigikalaClient::READ_OPERATIONS[$op];
        if (str_contains($path, '{variant_id}')) { $fields[] = 'variant_id'; }
        echo '<div class="mbwc-fields">';
        foreach ($fields as $name) { $this->parameter($name, 'q_', $name === 'variant_id'); }
        echo '</div>';
        $this->end('Read from Digikala');
        echo '</section>';
    }

    private function api(): void {
        $catalog = OpenApiCatalog::all();
        $this->panel('Digikala OpenAPI catalog');
        $this->help('Import the official seller OpenAPI JSON. Importing replaces the current catalog.');
        $this->post('import_spec', true);
        $this->input('spec', 'OpenAPI JSON file', '', ['type'=>'file', 'accept'=>'.json,application/json', 'required'=>true]);
        $this->end('Import catalog');
        if (!$catalog) { $this->notice('No catalog has been imported.'); echo '</section>'; return; }
        $read = count(array_filter($catalog, static fn(array $op): bool => $op['method'] === 'GET'));
        $this->status('Runnable GET operations', number_format_i18n($read));
        $this->status('Catalog-only operations', number_format_i18n(count($catalog) - $read));
        echo '</section>';

        $account = $this->account();
        $selected = $catalog[$this->query('op')] ?? null;
        if ($selected && $selected['method'] === 'GET') {
            $this->panel('Read from an imported endpoint');
            echo '<code dir="ltr">'.esc_html('GET '.$selected['path']).'</code>';
            $this->post('spec_operation');
            $this->hidden('operation', $selected['id']);
            $this->account_field($account);
            echo '<div class="mbwc-fields">';
            foreach ($selected['paths'] as $name) { $this->parameter($name, 'p_', true); }
            foreach ($selected['query'] as $name) { $this->parameter($name, 'q_', in_array($name, $selected['required_query'] ?? [], true)); }
            echo '</div>';
            $this->end('Read from Digikala');
            echo '</section>';
        }

        $this->panel('Find API operations');
        $search = substr(sanitize_text_field($this->query('q')), 0, 200);
        $this->get('api', ['account'=>$account]);
        $this->input('q', 'Search by path, name or group', $search, ['type'=>'search', 'maxlength'=>'160']);
        $this->end('Search', 'button');
        $matches = array_values(array_filter($catalog, static fn(array $op): bool => $search === ''
            || stripos(implode(' ', [$op['method'], $op['path'], $op['summary'], $op['tag']]), $search) !== false));
        $pages = max(1, (int)ceil(count($matches) / 25));
        $page = min($this->page(), $pages);
        if (!$matches) { $this->notice('No operations match your search.'); }
        else {
            $this->table(['Endpoint', 'Summary', 'Group', 'Actions']);
            foreach (array_slice($matches, ($page - 1) * 25, 25) as $op) {
                echo '<tr><td><code dir="ltr">'.esc_html($op['method'].' '.$op['path']).'</code></td>';
                echo '<td>'.esc_html($op['summary']).'</td><td>'.esc_html($op['tag']).'</td><td>';
                if ($op['method'] === 'GET') {
                    $this->link('api', 'Open', ['op'=>$op['id'], 'account'=>$account, 'q'=>$search, 'p'=>$page], 'button');
                } else { $this->badge('Catalog only'); }
                echo '</td></tr>';
            }
            $this->end_table();
            $this->pagination('api', $page, $pages, ['q'=>$search, 'account'=>$account]);
        }
        echo '</section>';
    }

    private function mappings(): void {
        $account = $this->account();
        $edit = Sync::mapping(Dashboard::integer($this->query('edit_mapping')));
        $this->panel($edit ? 'Edit product mapping' : 'Add product mapping');
        $this->help('A mapping links an existing Digikala variant to a simple WooCommerce product or a variation.');
        if (!$edit) { $this->product_search($account); }
        $id = (int)($edit['wc_product_id'] ?? Dashboard::integer($this->query('wc_id')));
        if ($id) {
            echo '<div class="mbwc-selected">'; $this->product_link($id); echo '</div>';
        }
        $this->post('add_mapping');
        if ($edit) {
            $this->hidden('account', $edit['account']);
            $this->hidden('mapping_id', (string)$edit['id']);
            $this->hidden('mapping_revision', Sync::revision($edit));
            $this->hidden('wc_id', (string)$id);
            $this->status('Account', $edit['account']);
        } else {
            $this->account_field($account);
            $this->input('wc_id', 'WooCommerce product or variation ID', $id ? (string)$id : '', ['required'=>true, 'inputmode'=>'numeric', 'dir'=>'ltr']);
        }
        echo '<div class="mbwc-fields">';
        $variant = $this->flash['mapping']['variant_id'] ?? ltrim((string)($edit['external_variant_id'] ?? ''), '0');
        $dkp = $this->flash['mapping']['product_id'] ?? ($edit['external_product_id'] ?? '');
        $this->input('variant_id', 'Digikala variant ID (DKPC)', $variant, ['required'=>true, 'maxlength'=>'32', 'inputmode'=>'numeric', 'dir'=>'ltr']);
        $this->input('product_id', 'Digikala product ID (DKP), optional', $dkp, ['maxlength'=>'36', 'dir'=>'ltr']);
        echo '</div>';
        $this->check('verify_variant', 'Check variant access through Digikala before saving');
        $this->end('Save mapping');
        if ($edit) { $this->link('mappings', 'Cancel edit', ['filter_account'=>$edit['account']], 'button'); }
        echo '</section>';

        $this->panel('Saved mappings', 'mbwc-mapping-list');
        $filter = $this->query('filter_account');
        if (!Accounts::exists('digikala', $filter)) { $filter = ''; }
        $this->get('mappings');
        $this->account_field($filter, 'filter_account', true);
        $this->end('Filter', 'button');
        $result = Sync::list_mappings($this->page(), $filter);
        if (is_wp_error($result)) { $this->notice($result->get_error_message(), 'error'); }
        elseif (!$result['rows']) { $this->notice('No mappings were found.'); }
        else {
            $this->table(['Account', 'WooCommerce product', 'Variant ID', 'DKP', 'Updated', 'Actions']);
            foreach ($result['rows'] as $row) {
                echo '<tr><td><bdi>'.esc_html($row['account']).'</bdi></td><td>';
                $this->product_link((int)$row['wc_product_id']);
                echo '</td><td><bdi>'.esc_html($row['external_variant_id']).'</bdi></td><td><bdi>'.esc_html($row['external_product_id'] ?: '—').'</bdi></td>';
                echo '<td>'.esc_html($this->date((int)strtotime($row['updated_at'].' UTC'))).'</td><td>';
                $this->link('mappings', 'Edit', ['edit_mapping'=>$row['id'], 'filter_account'=>$filter], 'button');
                echo '<details class="mbwc-details"><summary>'.self::label('Remove').'</summary>';
                $this->post('delete_mapping');
                $this->hidden('mapping_id', (string)$row['id']);
                $this->hidden('filter_account', $filter);
                $this->check('confirm', 'Remove this mapping?', false, true);
                $this->end('Remove mapping', 'button mbwc-danger');
                echo '</details></td></tr>';
            }
            $this->end_table();
            $this->pagination('mappings', $result['page'], $result['pages'], ['filter_account'=>$filter]);
        }
        echo '</section>';
    }

    private function product_search(string $account): void {
        $search = substr(sanitize_text_field($this->query('product_search')), 0, 240);
        $this->get('mappings', ['account'=>$account]);
        $this->input('product_search', 'Find a WooCommerce product by name, SKU or ID', $search, ['type'=>'search', 'maxlength'=>'100', 'required'=>true]);
        $this->end('Search products', 'button');
        if ($search === '') { return; }
        $ids = WC_Data_Store::load('product')->search_products($search, '', true, true, 25);
        $id = Dashboard::integer($search);
        if ($id) { array_unshift($ids, $id); }
        $products = [];
        foreach (array_unique($ids) as $item) {
            $product = wc_get_product((int)$item);
            if ($product && Sync::can_map($product)) { $products[] = $product; }
        }
        if (!$products) { $this->notice('No simple products or variations match that search.'); return; }
        echo '<ul class="mbwc-results">';
        foreach ($products as $product) {
            $url = self::url('mappings', ['wc_id'=>$product->get_id(), 'account'=>$account]);
            echo '<li><a href="'.esc_url($url).'">'.esc_html($this->product_title($product)).'</a></li>';
        }
        echo '</ul>';
    }

    private function logs(): void {
        $providers = [''=>'All providers', 'torob'=>'Torob', 'digikala'=>'Digikala'];
        $levels = [''=>'All levels', 'info'=>'Info', 'warning'=>'Warning', 'error'=>'Error'];
        $provider = $this->query('provider');
        $level = $this->query('level');
        if (!isset($providers[$provider])) { $provider = ''; }
        if (!isset($levels[$level])) { $level = ''; }
        $this->panel('Integration activity');
        $this->get('logs');
        echo '<div class="mbwc-fields">';
        $this->select('provider', 'Provider', $providers, $provider);
        $this->select('level', 'Level', $levels, $level);
        echo '</div>';
        $this->end('Filter', 'button');
        $result = Logger::page($this->page(), $provider, $level);
        if (is_wp_error($result)) { $this->notice($result->get_error_message(), 'error'); }
        elseif (!$result['rows']) { $this->notice('No activity matches these filters.'); }
        else {
            $this->table(['Time', 'Provider', 'Level', 'Operation', 'Status', 'Details']);
            foreach ($result['rows'] as $row) {
                echo '<tr><td>'.esc_html($this->date((int)strtotime($row['created_at'].' UTC'))).'</td>';
                echo '<td>'.self::label($providers[$row['provider']] ?? $row['provider']).'</td><td>';
                $this->badge($levels[$row['level']] ?? $row['level'], $row['level'] === 'error' ? 'warning' : '');
                echo '</td>';
                foreach (['action', 'status', 'detail'] as $key) { echo '<td><bdi>'.esc_html($row[$key]).'</bdi></td>'; }
                echo '</tr>';
            }
            $this->end_table();
            $this->pagination('logs', $result['page'], $result['pages'], ['provider'=>$provider, 'level'=>$level]);
        }
        echo '</section>';
    }

    private function account(): string {
        $id = $this->query('account', 'default');
        return Accounts::exists('digikala', $id) ? $id : 'default';
    }

    private function account_field(string $selected, string $name = 'account', bool $all = false): void {
        $options = $all ? [''=>'All accounts'] : [];
        foreach (Accounts::all('digikala') as $id=>$account) {
            $options[$id] = self::t($account['label']).' ('.$id.')';
        }
        $this->select($name, 'Seller account', $options, $selected);
    }

    private function product_title(WC_Product $product): string {
        $sku = $product->get_sku();
        return $product->get_name().' (#'.$product->get_id().($sku !== '' ? ' · '.$sku : '').')';
    }

    private function product_link(int $id): void {
        $product = wc_get_product($id);
        if (!$product) { echo self::label('Product missing').' <bdi>#'.(int)$id.'</bdi>'; return; }
        $title = '<bdi>'.esc_html($this->product_title($product)).'</bdi>';
        $url = get_edit_post_link($product->is_type('variation') ? $product->get_parent_id() : $id);
        echo $url ? '<a href="'.esc_url($url).'">'.$title.'</a>' : $title;
    }

    private function flag(string $name): bool { return get_option('mbwc_torob_'.$name, '0') === '1'; }
    private function page(): int { return max(1, Dashboard::integer($this->query('p'))); }
    private function date(int $timestamp): string { return wp_date('Y/m/d H:i', $timestamp); }

    private function health(string $provider, string $account = ''): void {
        $result = get_transient(Dashboard::health_key($provider, $account));
        if (!is_array($result)) { echo '<span class="mbwc-muted">'.self::label('Not tested recently').'</span>'; return; }
        $this->badge($result['ok'] ? 'Test passed' : 'Test failed', $result['ok'] ? 'ready' : 'warning');
        echo ' <span class="mbwc-muted">'.esc_html($this->date((int)$result['at'])).'</span>';
    }

    private function panel(string $title, string $id = ''): void {
        echo '<section class="mbwc-panel"'.($id === '' ? '' : ' id="'.esc_attr($id).'"').'><h2>'.self::label($title).'</h2>';
    }

    private function help(string $text): void {
        echo '<p class="mbwc-help">'.self::label($text).'</p>';
    }

    private function status(string $label, string $value, string $tone = ''): void {
        echo '<p class="mbwc-status"><span>'.self::label($label).'</span>';
        $this->badge($value, $tone);
        echo '</p>';
    }

    private function badge(string $label, string $tone = ''): void {
        echo '<span class="mbwc-badge'.($tone ? ' is-'.esc_attr($tone) : '').'">'.self::label($label).'</span>';
    }

    private function notice(string $text, string $type = ''): void {
        echo '<p class="mbwc-notice'.($type ? ' is-'.esc_attr($type) : '').'" role="'.($type === 'error' ? 'alert' : 'status').'">'.self::label($text).'</p>';
    }

    private function json(string $value): void {
        echo '<details class="mbwc-details mbwc-response"><summary>'.self::label('Show JSON response').'</summary>';
        echo '<pre dir="ltr" tabindex="0" aria-label="'.esc_attr(self::t('JSON response')).'">'.esc_html($value).'</pre></details>';
    }

    private function table(array $columns): void {
        echo '<p class="mbwc-table-hint mbwc-muted">'.self::label('Scroll the table to see all columns.').'</p>';
        echo '<div class="mbwc-table" role="region" tabindex="0" aria-label="'.esc_attr(self::t('Data table')).'">';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($columns as $column) { echo '<th scope="col">'.self::label($column).'</th>'; }
        echo '</tr></thead><tbody>';
    }

    private function end_table(): void { echo '</tbody></table></div>'; }

    private function pagination(string $tab, int $page, int $pages, array $args = []): void {
        if ($pages < 2) { return; }
        echo '<nav class="mbwc-actions mbwc-pagination" aria-label="'.esc_attr(self::t('Pagination')).'">';
        if ($page > 1) { $this->link($tab, 'Previous', ['p'=>$page - 1] + $args, 'button'); }
        echo '<span>'.esc_html(sprintf(self::t('Page %1$s of %2$s'), number_format_i18n($page), number_format_i18n($pages))).'</span>';
        if ($page < $pages) { $this->link($tab, 'Next', ['p'=>$page + 1] + $args, 'button'); }
        echo '</nav>';
    }

    private function link(string $tab, string $label, array $args = [], string $classes = '', string $fragment = ''): void {
        echo '<a class="'.esc_attr($classes).'"'.($classes === 'current' ? ' aria-current="page"' : '').' href="'
            .esc_url(self::url($tab, $args).$fragment).'">'.self::label($label).'</a>';
    }

    private function post(string $action, bool $upload = false): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.($upload ? ' enctype="multipart/form-data"' : '').'>';
        $this->hidden('action', 'mbwc_action');
        $this->hidden('mbwc_action', $action);
        $this->hidden('_wpnonce', wp_create_nonce('mbwc_'.$action));
    }

    private function get(string $tab, array $args = []): void {
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
        foreach (['page'=>'mbwc', 'tab'=>$tab] + $args as $name=>$value) { $this->hidden($name, (string)$value); }
    }

    private function hidden(string $name, string $value): void {
        echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
    }

    private function end(string $label, string $classes = 'button button-primary'): void {
        echo '<div class="mbwc-actions"><button class="'.esc_attr($classes).'" type="submit">'.self::label($label).'</button></div></form>';
    }

    private function field_start(string $name, string $label): string {
        $id = wp_unique_id('mbwc-');
        echo '<div class="mbwc-field"><label for="'.esc_attr($id).'">'.self::label($label).'</label>';
        return ' id="'.esc_attr($id).'" name="'.esc_attr($name).'"';
    }

    private function input(string $name, string $label, string $value, array $attributes = []): void {
        $field = $this->field_start($name, $label);
        echo '<input'.$field;
        if (($attributes['type'] ?? '') !== 'file') { echo ' value="'.esc_attr($value).'"'; }
        foreach ($attributes as $key=>$attr) { echo ' '.esc_attr($key).($attr === true ? '' : '="'.esc_attr($attr).'"'); }
        echo '></div>';
    }

    private function textarea(string $name, string $label, bool $required): void {
        $field = $this->field_start($name, $label);
        echo '<textarea'.$field.' rows="3"'.($required ? ' required' : '').'></textarea></div>';
    }

    private function select(string $name, string $label, array $options, string $value, bool $localized = false, bool $required = false): void {
        $field = $this->field_start($name, $label);
        echo '<select'.$field.($required ? ' required' : '').'>';
        foreach ($options as $key=>$text) {
            echo '<option value="'.esc_attr($key).'"'.selected((string)$key, $value, false).'>'.($localized ? esc_html($text) : self::label($text)).'</option>';
        }
        echo '</select></div>';
    }

    private function check(string $name, string $label, bool $checked = false, bool $required = false): void {
        echo '<label class="mbwc-check"><input type="checkbox" name="'.esc_attr($name).'" value="1"'
            .checked($checked, true, false).($required ? ' required' : '').'><span>'.self::label($label).'</span></label>';
    }

    private function credential(string $name, string $label, bool $configured, string $clear = ''): void {
        $this->input($name, $label, '', [
            'type'=>'password', 'dir'=>'ltr', 'autocomplete'=>'new-password',
            'placeholder'=>self::t($configured ? 'Leave blank to keep current token' : 'No token configured'),
        ]);
        if ($configured) { $this->check($clear ?: 'clear_'.$name, 'Remove stored token'); }
    }

    private function parameter(string $name, string $prefix, bool $required = false): void {
        $labels = [
            'date'=>'Date in Tehran (YYYY-MM-DD)', 'domain'=>'Shop domain', 'destination'=>'Destination domain',
            'cursor'=>'Cursor', 'page'=>'Page', 'page_size'=>'Page size', 'variant_id'=>'Variant ID',
            'search[search_term]'=>'Search term', 'updated_since'=>'Updated since (ISO 8601)', 'status'=>'Product status',
            'active_reports'=>'Active reports', 'click_type'=>'Click type', 'is_guaranteed'=>'Guaranteed clicks',
        ];
        $field = $prefix.bin2hex($name);
        if (in_array($name, ['active_reports', 'is_guaranteed'], true) && $this->query('tab') === 'torob') {
            $this->select($field, $labels[$name], [''=>'Any', 'true'=>'Yes', 'false'=>'No'], '');
            return;
        }
        $attrs = ['maxlength'=>'300', 'dir'=>'ltr'];
        if ($required) { $attrs['required'] = true; }
        if (in_array($name, ['page', 'page_size', 'variant_id'], true)) { $attrs['inputmode'] = 'numeric'; }
        $this->input($field, $labels[$name] ?? $name, '', $attrs);
    }
}
