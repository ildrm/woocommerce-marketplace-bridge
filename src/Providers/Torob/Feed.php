<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Providers\Torob;

use WC_Product;
use MarketplaceBridge\WooCommerce\Domain\Money;
use MarketplaceBridge\WooCommerce\Infrastructure\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class Feed {
    public static function add_rewrite_rule(): void {
        add_rewrite_rule('^torob_api/v3/products/?$', 'index.php?mbwc_torob_products=1', 'top');
    }
    public function register(): void {
        add_filter('query_vars', static function (array $vars): array { $vars[] = 'mbwc_torob_products'; return $vars; });
        add_action('rest_api_init', function (): void {
            register_rest_route('marketplacebridge/v1', '/torob/products', [
                'methods'=>'POST', 'callback'=>[$this, 'handle'],
                'permission_callback'=>[$this, 'authorized']
            ]);
        });
        add_action('template_redirect', function (): void {
            if (!get_query_var('mbwc_torob_products')) { return; }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json(['error'=>'Method not allowed'], 405); }
            $request = new WP_REST_Request('POST', '/marketplacebridge/v1/torob/products');
            $request->set_body((string) file_get_contents('php://input', false, null, 0, self::MAX_REQUEST_BYTES + 1));
            $request->set_header('content-type', 'application/json');
            $request->set_header('x-torob-token', (string)($_SERVER['HTTP_X_TOROB_TOKEN'] ?? ''));
            $request->set_header('x-torob-token-version', (string)($_SERVER['HTTP_X_TOROB_TOKEN_VERSION'] ?? ''));
            if (!$this->authorized($request, true)) { wp_send_json(['error'=>'Unauthorized'], 401); }
            $result = $this->handle($request);
            if (is_wp_error($result)) { wp_send_json(['error'=>$result->get_error_message()], (int)($result->get_error_data()['status'] ?? 400)); }
            wp_send_json($result->get_data(), $result->get_status());
        }, 0);
    }
    private const MAX_REQUEST_BYTES = 256 * 1024;
    private bool $loggedInvalidMoney = false;

    public function authorized(WP_REST_Request $request, bool $rewrite = false): bool {
        if (get_option('mbwc_torob_enabled', '0') !== '1') { return false; }
        return Jwt::verify((string)$request->get_header('x-torob-token'), (string)$request->get_header('x-torob-token-version'),
            $rewrite ? home_url() : null);
    }
    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $raw = $request->get_body();
        if (strlen($raw) > self::MAX_REQUEST_BYTES) { return $this->invalid('Request body is too large'); }
        $body = json_decode($raw, false, 16);
        if (!$body instanceof \stdClass || !get_object_vars($body)) { return $this->invalid('Invalid or empty JSON body'); }
        $body = get_object_vars($body);
        $hasUrls = array_key_exists('page_urls', $body);
        $hasIds = array_key_exists('page_uniques', $body);
        if (array_key_exists('limit', $body) || array_key_exists('size', $body)) { return $this->invalid('Page size is fixed at 100'); }
        if ($hasUrls && $hasIds) { return $this->invalid('Choose one lookup method'); }
        if ($hasUrls || $hasIds) {
            $key = $hasUrls ? 'page_urls':'page_uniques';
            $maxLength = $hasUrls ? 1500 : 200;
            if (!is_array($body[$key]) || !$body[$key] || count($body[$key]) > 100
                || array_filter($body[$key], static fn($id) => is_string($id) && $id !== '' && strlen($id) <= $maxLength) !== $body[$key]
                || array_intersect(['sort', 'page', 'cursor'], array_keys($body))) {
                return $this->invalid('Expected a list of up to 100 string identifiers');
            }
            $results = [];
            foreach ($body[$key] as $identifier) {
                $id = $hasUrls ? $this->id_from_url($identifier) : $this->id_from_unique($identifier);
                if (!$id) { continue; }
                $product = wc_get_product($id);
                if ($product instanceof WC_Product && self::eligible($product)) { $results[$id] = $this->serialize($product); }
            }
            $count = count($results);
            return new WP_REST_Response(['api_version'=>'torob_api_v3','current_page'=>1,'total'=>$count,'max_pages'=>1,'next_cursor'=>null,'products'=>array_values($results)], 200);
        }
        $sort = $body['sort'] ?? null;
        if (!is_string($sort) || !in_array($sort, ['date_added_desc','date_updated_desc','product_id_desc'], true)) { return $this->invalid('sort parameter is missing or invalid'); }
        $cursor = array_key_exists('cursor', $body);
        if ($cursor && ($sort !== 'product_id_desc' || array_key_exists('page', $body)
            || !is_string($body['cursor']) || self::positive_id($body['cursor']) === 0)) {
            return $this->invalid('Invalid cursor pagination parameters');
        }
        $isCursor = $cursor || !array_key_exists('page', $body);
        if ($isCursor && $sort !== 'product_id_desc') { return $this->invalid('Cursor pagination requires product_id_desc'); }
        $page = $isCursor ? 1 : $body['page'];
        if (!is_int($page) || $page < 1 || $page > 100000) { return $this->invalid('Invalid page number'); }
        $last = $cursor ? (int)$body['cursor'] : null;
        $pageData = $this->find_page($sort, (int)$page, $last, $isCursor);
        if (is_wp_error($pageData)) { return $pageData; }
        if ($pageData['ids']) { _prime_post_caches($pageData['ids']); }
        $products = [];
        foreach ($pageData['ids'] as $id) {
            $product = wc_get_product($id);
            if ($product instanceof WC_Product && self::eligible($product)) { $products[] = $this->serialize($product); }
        }
        $response = ['api_version'=>'torob_api_v3','total'=>$pageData['total'], 'max_pages'=>$pageData['max_pages'], 'next_cursor'=>null, 'products'=>$products];
        if ($isCursor) {
            $response['total'] = null;
            $response['max_pages'] = null;
            $response['next_cursor'] = !empty($pageData['has_more']) && $pageData['ids'] ? (string)end($pageData['ids']) : null;
        } else { $response['current_page'] = (int)$page; }
        return new WP_REST_Response($response, 200);
    }
    private function invalid(string $message): WP_Error { return new WP_Error('mbwc_bad_request', $message, ['status'=>400]); }
    private static function positive_id(string $value): int {
        $id = (int)$value;
        return $id > 0 && (string)$id === $value ? $id : 0;
    }
    private function id_from_unique(string $unique): int {
        return str_starts_with($unique, 'wc_') ? self::positive_id(substr($unique, 3)) : 0;
    }
    private function id_from_url(string $url): int {
        if (!self::is_store_url($url)) { return 0; }
        $q = [];
        parse_str((string)wp_parse_url($url, PHP_URL_QUERY), $q);
        $id = url_to_postid($url);
        if (array_key_exists('variation_id', $q)) {
            $variation = is_string($q['variation_id']) ? self::positive_id($q['variation_id']) : 0;
            if (!$id || !$variation) { return 0; }
            $product = wc_get_product($variation);
            if (!$product || !$product->is_type('variation') || $product->get_parent_id() !== $id) { return 0; }
            $requestedPath = rtrim((string)wp_parse_url($url, PHP_URL_PATH), '/');
            $parentPath = rtrim((string)wp_parse_url(get_permalink($id) ?: '', PHP_URL_PATH), '/');
            return $requestedPath === $parentPath ? $variation : 0;
        }
        return $id;
    }
    public static function is_store_url(string $url): bool {
        if (strlen($url) > 1500) { return false; }
        $parts = wp_parse_url($url);
        $store = wp_parse_url(home_url());
        return isset($parts['host'], $parts['scheme'], $store['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && strcasecmp($parts['host'], $store['host']) === 0
            && ($parts['port'] ?? null) === ($store['port'] ?? null);
    }
    public static function eligible(WC_Product $product): bool {
        if ($product->get_status() !== 'publish' || get_post_field('post_password', $product->get_id(), 'raw') !== '') { return false; }
        if ($product->get_catalog_visibility() === 'hidden') { return false; }
        if ($product->is_type('variable')) { return false; } // children are exported individually.
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent || $parent->get_status() !== 'publish' || $parent->get_catalog_visibility()==='hidden'
                || get_post_field('post_password', $parent->get_id(), 'raw') !== '') { return false; }
        }
        return true;
    }
    public function preview(int $id): array|WP_Error {
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product || !self::eligible($product)) {
            return new WP_Error('mbwc_preview_product', 'This product is outside the Torob feed. Select a published, visible product or variation.');
        }
        return $this->serialize($product, false);
    }
    private function where(): string {
        global $wpdb;
        $p = $wpdb->posts;
        $visibility = wc_get_product_visibility_term_ids();
        $excludeCatalog = (int)$visibility['exclude-from-catalog'];
        $excludeSearch = (int)$visibility['exclude-from-search'];
        $relationships = $wpdb->term_relationships;
        $hidden = static fn(string $id): string =>
            "EXISTS (SELECT 1 FROM $relationships c WHERE c.object_id=$id AND c.term_taxonomy_id=$excludeCatalog)
             AND EXISTS (SELECT 1 FROM $relationships s WHERE s.object_id=$id AND s.term_taxonomy_id=$excludeSearch)";
        return "p.post_status='publish' AND p.post_password='' AND NOT ({$hidden('p.ID')})
            AND ((p.post_type='product' AND NOT EXISTS (
                SELECT 1 FROM $relationships tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug='variable'
            )) OR (p.post_type='product_variation' AND EXISTS (
                SELECT 1 FROM $p parent WHERE parent.ID=p.post_parent AND parent.post_type='product'
                AND parent.post_status='publish' AND parent.post_password='' AND NOT ({$hidden('parent.ID')})
            )))";
    }
    private function find_page(string $sort, int $page, ?int $cursor, bool $isCursor): array|WP_Error {
        global $wpdb;
        $table = $wpdb->posts;
        $where = $this->where();
        $join = $sort === 'date_updated_desc'
            ? "LEFT JOIN $table changed_parent ON p.post_type='product_variation' AND changed_parent.ID=p.post_parent"
            : '';
        $order = match($sort) {
            'date_added_desc' => 'p.post_date_gmt DESC,p.ID DESC',
            'date_updated_desc' => 'GREATEST(p.post_modified_gmt,COALESCE(changed_parent.post_modified_gmt,p.post_modified_gmt)) DESC,p.ID DESC',
            default => 'p.ID DESC',
        };
        if ($isCursor && $cursor !== null) { $where .= $wpdb->prepare(' AND p.ID < %d', $cursor); }
        $offset = $isCursor ? 0 : (($page-1)*100);
        $raw = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM $table p $join WHERE $where ORDER BY $order LIMIT %d OFFSET %d", $isCursor ? 101 : 100, $offset)) ?: []);
        if ($wpdb->last_error) { return new WP_Error('mbwc_feed_query', 'Unable to query product feed', ['status'=>503]); }
        $hasMore = $isCursor && count($raw) > 100;
        $ids = array_slice($raw, 0, 100);
        $total = $isCursor ? null : (int)$wpdb->get_var("SELECT COUNT(*) FROM $table p WHERE $where");
        if ($wpdb->last_error) { return new WP_Error('mbwc_feed_query', 'Unable to count products', ['status'=>503]); }
        return ['ids'=>$ids,'has_more'=>$hasMore,'total'=>$total,'max_pages'=>$total === null?null:(int)ceil($total/100)];
    }
    private static function truncate(string $s, int $max): string { return function_exists('mb_substr') ? mb_substr($s,0,$max) : wp_html_excerpt($s,$max); }
    public static function unique(int $id): string { return 'wc_'.$id; }
    public static function url(WC_Product $product, ?string $parentUrl = null): string {
        if ($product->is_type('variation')) {
            $parent = $parentUrl ?? get_permalink($product->get_parent_id());
            $args = array_map('urlencode', array_filter($product->get_variation_attributes(), static fn($value) => $value !== ''));
            $args['variation_id'] = $product->get_id();
            return $parent ? add_query_arg($args, $parent) : '';
        }
        return get_permalink($product->get_id()) ?: '';
    }
    private function serialize(WC_Product $product, bool $log = true): array {
        $price = (string)$product->get_price();
        $regular = (string)$product->get_regular_price();
        $currency = (string)get_option('mbwc_currency_unit', get_woocommerce_currency());
        $current = 0;
        $available = $product->is_purchasable() && $product->is_in_stock() && $price !== '';
        if ($available) {
            try {
                $current = Money::toToman($price, $currency);
                $available = $current > 0 || (int)$price === 0;
            } catch (\InvalidArgumentException|\OverflowException) {
                $available = false;
            }
            if (!$available && $log && !$this->loggedInvalidMoney) {
                Logger::record('torob','error','money_conversion','invalid','Cannot export price for product '.$product->get_id());
                $this->loggedInvalidMoney = true;
            }
        }
        $mainId = $product->get_image_id();
        if (!$mainId && $product->is_type('variation')) { $mainId = (int)get_post_thumbnail_id($product->get_parent_id()); }
        $images = [];
        if ($mainId) { $image = wp_get_attachment_image_url($mainId, 'full'); if ($image && strlen($image) <= 1000) { $images[]=$image; } }
        foreach ($product->get_gallery_image_ids() as $id) {
            $image = wp_get_attachment_image_url($id, 'full'); if ($image && strlen($image) <= 1000 && !in_array($image, $images, true)) { $images[]=$image; }
        }
        $parentId = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $terms = get_the_terms($parentId, 'product_cat');
        $category = is_array($terms) && $terms ? $terms[0]->name : '';
        $spec = [];
        if ($product->is_type('variation')) {
            foreach ($product->get_variation_attributes() as $key=>$value) { $spec[wc_attribute_label(str_replace('attribute_', '', $key))] = (string)$value; }
        } else {
            foreach ($product->get_attributes() as $attribute) {
                if (!$attribute instanceof \WC_Product_Attribute) { continue; }
                $value = $attribute->is_taxonomy() ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields'=>'names']) : $attribute->get_options();
                $spec[wc_attribute_label($attribute->get_name())] = implode(', ', array_map('strval', is_array($value)?$value:[]));
            }
        }
        $created = $product->get_date_created(); $modified = $product->get_date_modified();
        $updatedAt = $modified ? $modified->getTimestamp() : 0;
        if ($product->is_type('variation')) {
            $updatedAt = max($updatedAt, (int)get_post_modified_time('U', true, $parentId));
        }
        $result = [
            'page_unique'=>self::unique($product->get_id()), 'page_url'=>self::url($product),
            'product_group_id'=>(string)$parentId, 'title'=>self::truncate(wp_strip_all_tags($product->get_name()),500),
            'current_price'=>$current,
            'availability'=>(bool)$available,
            'category_name'=>self::truncate((string)$category,200),
            'image_links'=>$images, 'spec'=>(object)$spec,
            'short_desc'=>self::truncate(wp_strip_all_tags($product->get_short_description()),500),
            'date_added'=>$created ? $created->date(DATE_ATOM) : gmdate(DATE_ATOM),
            'date_updated'=>gmdate(DATE_ATOM, $updatedAt ?: ($created ? $created->getTimestamp() : time()))
        ];
        if ($available && $regular !== '') {
            try {
                $old = Money::toToman($regular,$currency);
                if ($old > $result['current_price']) { $result['old_price']=$old; }
            } catch (\InvalidArgumentException|\OverflowException) { /* Omit invalid optional discount data. */ }
        }
        return $result;
    }
}
