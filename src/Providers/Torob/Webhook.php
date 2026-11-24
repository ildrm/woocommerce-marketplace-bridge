<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

use MarketplaceBridge\WooCommerce\Infrastructure\Database;
use MarketplaceBridge\WooCommerce\Infrastructure\Logger;
use MarketplaceBridge\WooCommerce\Infrastructure\Secrets;
use WC_Product;
use WP_Post;

final class Webhook {
    public const HOOK = 'mbwc_torob_flush_queue';
    public const MAX_ATTEMPTS = 5;
    private const BATCH_SIZE = 100;

    public function register(): void {
        foreach (['woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_new_product', 'woocommerce_new_product_variation', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status'] as $hook) {
            add_action($hook, [$this, 'changed'], 10, 1);
        }
        foreach (['woocommerce_product_set_stock', 'woocommerce_variation_set_stock'] as $hook) {
            add_action($hook, [$this, 'stock_changed'], 10, 1);
        }
        add_action('wp_trash_post', [$this, 'removing'], 10, 1);
        add_action('before_delete_post', [$this, 'removing'], 10, 1);
        add_action('set_object_terms', [$this, 'visibility_changed'], 10, 6);
        add_action('post_updated', [$this, 'post_updated'], 10, 3);
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['mbwc_torob_minute'] = ['interval'=>60, 'display'=>'MarketplaceBridge Torob queue'];
            return $schedules;
        });
        add_action('init', [$this, 'ensure_schedule']);
        add_action(self::HOOK, [$this, 'drain']);
        // Previously scheduled Action Scheduler and WP-Cron notifications enter the same queue.
        add_action('mbwc_torob_notify', [$this, 'changed'], 10, 1);
    }

    public static function enabled(): bool {
        return get_option('mbwc_torob_enabled', '0') === '1' && get_option('mbwc_torob_webhooks', '0') === '1'
            && get_option('mbwc_schema_version') === Database::VERSION && Secrets::exists('torob', 'webhook_token');
    }

    public function ensure_schedule(): void {
        $scheduled = wp_next_scheduled(self::HOOK);
        if (self::enabled()) {
            if (!$scheduled) { wp_schedule_event(time() + 60, 'mbwc_torob_minute', self::HOOK); }
        } elseif ($scheduled) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    public function stock_changed(WC_Product $product): void {
        $this->changed($product->get_id());
    }

    public function changed(int $id): void {
        if (!self::enabled()) { return; }
        $product = wc_get_product($id);
        if (!$product || $product->get_status('edit') !== 'publish') { return; }
        $ids = $product->is_type('variable') ? $product->get_children() : [$id];
        $this->enqueue(array_fill_keys($ids, ''));
    }

    public function visibility_changed(int $id, mixed $terms, array $termIds, string $taxonomy, bool $append, array $oldIds): void {
        if ($taxonomy !== 'product_visibility' || $append || !self::enabled()) { return; }
        $visibility = wc_get_product_visibility_term_ids();
        $hidden = [$visibility['exclude-from-catalog'], $visibility['exclude-from-search']];
        if (array_diff($hidden, $termIds) || !array_diff($hidden, $oldIds)) { return; }
        $post = get_post($id);
        if ($post && $post->post_type === 'product' && $post->post_status === 'publish' && $post->post_password === '') {
            $this->queue_removal($post);
        }
    }

    public function removing(int $id): void {
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, ['product', 'product_variation'], true)
            || $post->post_status !== 'publish' || $post->post_password !== '' || !self::enabled()) { return; }
        $product = wc_get_product($id);
        if ($product && $product->get_catalog_visibility('edit') !== 'hidden') { $this->queue_removal($post); }
    }

    public function post_updated(int $id, WP_Post $after, WP_Post $before): void {
        if (!in_array($before->post_type, ['product', 'product_variation'], true) || $before->post_status !== 'publish'
            || $before->post_password !== '' || ($after->post_status === 'publish' && $after->post_password === '')
            || $after->post_status === 'trash' || !self::enabled()) { return; }
        $product = wc_get_product($id);
        if ($product && $product->get_catalog_visibility('edit') !== 'hidden') { $this->queue_removal($before); }
    }

    private function queue_removal(WP_Post $post): void {
        $product = wc_get_product($post->ID);
        if (!$product) { return; }
        if ($product->is_type('variable')) {
            $urls = [];
            $parentUrl = get_permalink($post);
            if (!$parentUrl) { return; }
            foreach ($product->get_children() as $id) {
                $child = wc_get_product($id);
                if ($child && $child->get_status('edit') === 'publish' && get_post_field('post_password', $id, 'raw') === '') {
                    $urls[$id] = Feed::url($child, $parentUrl);
                }
            }
        } else {
            if ($product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if (!$parent || $parent->get_status('edit') !== 'publish' || $parent->get_catalog_visibility('edit') === 'hidden'
                    || get_post_field('post_password', $parent->get_id(), 'raw') !== '') { return; }
            }
            $urls = [$post->ID => $product->is_type('variation') ? Feed::url($product) : (get_permalink($post) ?: '')];
        }
        $this->enqueue($urls);
    }

    private function enqueue(array $urls): void {
        global $wpdb;
        $table = Database::table('torob_queue');
        $due = time() + 30;
        foreach (array_chunk($urls, self::BATCH_SIZE, true) as $chunk) {
            $values = [];
            foreach ($chunk as $id=>$url) {
                $values[] = $wpdb->prepare('(%d,%d,%s)', $id, $due, $url);
            }
            $result = $wpdb->query("INSERT INTO $table (product_id,due_at,previous_url) VALUES ".implode(',', $values)."
                ON DUPLICATE KEY UPDATE revision=revision+1,
                due_at=CASE WHEN attempts>=".self::MAX_ATTEMPTS." THEN VALUES(due_at)
                    WHEN attempts=0 THEN LEAST(due_at,VALUES(due_at)) ELSE due_at END,
                last_error=IF(attempts>=".self::MAX_ATTEMPTS.", '', last_error),
                attempts=IF(attempts>=".self::MAX_ATTEMPTS.", 0, attempts),
                previous_url=IF(VALUES(previous_url)='', previous_url, VALUES(previous_url))");
            if ($result === false) {
                Logger::record('torob', 'error', 'product_webhook', 'queue_error', 'Could not queue product changes');
            }
        }
    }

    public function drain(): void {
        if (!self::enabled()) { return; }
        global $wpdb;
        $table = Database::table('torob_queue');
        $lock = 'mbwc_torob_'.substr(hash('sha256', DB_NAME.'.'.$table), 0, 40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return; }
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT product_id,revision,attempts,previous_url FROM $table
                WHERE due_at<=%d AND attempts<%d ORDER BY due_at,product_id LIMIT %d", time(), self::MAX_ATTEMPTS, self::BATCH_SIZE), ARRAY_A);
            if (!$rows) { return; }
            _prime_post_caches(array_map('intval', array_column($rows, 'product_id')));
            $items = [];
            $sent = [];
            $discarded = [];
            foreach ($rows as $row) {
                $id = (int)$row['product_id'];
                $product = wc_get_product($id);
                $url = (string)$row['previous_url'];
                if ($product && Feed::eligible($product)) {
                    $url = Feed::url($product);
                }
                if (!Feed::is_store_url($url)) {
                    $discarded[] = $row;
                    continue;
                }
                $sent[] = $row;
                $items[] = ['page_url'=>$url, 'page_unique'=>Feed::unique($id)];
            }
            $this->remove($discarded);
            if (!$items) { return; }
            $response = (new Client())->update_webhook($items);
            if (!is_wp_error($response)) {
                $this->remove($sent);
                Logger::record('torob', 'info', 'product_webhook', 'acknowledged', count($items).' products queued by Torob');
                return;
            }
            $status = (int)($response->get_error_data()['status'] ?? 0);
            $retryable = in_array($status, [0, 408, 429], true) || $status >= 500;
            $reason = $status > 0 ? 'HTTP '.$status : (string)$response->get_error_code();
            foreach ($sent as $row) {
                $attempt = (int)$row['attempts'] + 1;
                $willRetry = $retryable && $attempt < self::MAX_ATTEMPTS;
                $updated = $wpdb->update($table, [
                    'attempts'=>$willRetry ? $attempt : self::MAX_ATTEMPTS,
                    'due_at'=>$willRetry ? time() + 60 * (2 ** $attempt) : time(),
                    'last_error'=>substr($reason, 0, 40),
                ], [
                    'product_id'=>(int)$row['product_id'], 'revision'=>(int)$row['revision'],
                ], ['%d', '%d', '%s'], ['%d', '%d']);
                if ($updated === 1 && !$willRetry) {
                    Logger::record('torob', 'error', 'product_webhook', 'terminal', 'Product '.$row['product_id'].' failed on attempt '.$attempt);
                }
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function queue_status(bool $withRows = false, int $page = 1): array|\WP_Error {
        if (get_option('mbwc_schema_version') !== Database::VERSION) {
            return new \WP_Error('mbwc_schema', 'Database setup is incomplete.');
        }
        global $wpdb;
        $table = Database::table('torob_queue');
        $max = self::MAX_ATTEMPTS;
        $counts = $wpdb->get_row("SELECT COUNT(CASE WHEN attempts<$max THEN 1 END) AS pending,
            COUNT(CASE WHEN attempts>=$max THEN 1 END) AS failed FROM $table", ARRAY_A);
        if ($wpdb->last_error) { return new \WP_Error('mbwc_queue', 'Could not read the Torob queue.'); }
        $pages = max(1, (int)ceil(((int)$counts['pending'] + (int)$counts['failed']) / 30));
        $page = min(max(1, $page), $pages);
        $rows = $withRows
            ? $wpdb->get_results($wpdb->prepare("SELECT product_id, revision, due_at, attempts, last_error FROM $table
                ORDER BY (attempts>=$max) DESC, due_at, product_id LIMIT 30 OFFSET %d", ($page - 1) * 30), ARRAY_A)
            : [];
        if ($wpdb->last_error) { return new \WP_Error('mbwc_queue', 'Could not read the Torob queue.'); }
        return ['pending'=>(int)$counts['pending'], 'failed'=>(int)$counts['failed'], 'rows'=>$rows ?: [], 'pages'=>$pages, 'page'=>$page];
    }

    public static function retry(int $id, int $revision): bool {
        if ($id < 1 || $revision < 1 || !self::enabled()) {
            return false;
        }
        global $wpdb;
        $table = Database::table('torob_queue');
        return $wpdb->query($wpdb->prepare("UPDATE $table SET attempts=0, due_at=%d, revision=revision+1
            WHERE product_id=%d AND revision=%d AND attempts>=%d", time(), $id, $revision, self::MAX_ATTEMPTS)) === 1;
    }

    private function remove(array $rows): void {
        if (!$rows) { return; }
        global $wpdb;
        // An edit made during delivery leaves a newer revision for the next run.
        $pairs = [];
        foreach ($rows as $row) {
            $pairs[] = $wpdb->prepare('(%d,%d)', $row['product_id'], $row['revision']);
        }
        $table = Database::table('torob_queue');
        $wpdb->query("DELETE FROM $table WHERE (product_id,revision) IN (".implode(',', $pairs).')');
    }
}
