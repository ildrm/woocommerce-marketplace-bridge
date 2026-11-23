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
            $request->set_body((string) file_get_contents('php://input'));
            $request->set_header('content-type', 'application/json');
            $request->set_header('x-torob-token', (string)($_SERVER['HTTP_X_TOROB_TOKEN'] ?? ''));
            $request->set_header('x-torob-token-version', (string)($_SERVER['HTTP_X_TOROB_TOKEN_VERSION'] ?? ''));
            if (!$this->authorized($request)) { wp_send_json(['error'=>'Unauthorized'], 401); }
            $result = $this->handle($request);
            if (is_wp_error($result)) { wp_send_json(['error'=>$result->get_error_message()], (int)($result->get_error_data()['status'] ?? 400)); }
            wp_send_json($result->get_data(), $result->get_status());
        });
    }
    public function authorized(WP_REST_Request $request): bool {
        if (get_option('mbwc_torob_enabled', '0') !== '1') { return false; }
        return Jwt::verify((string)$request->get_header('x-torob-token'), (string)$request->get_header('x-torob-token-version'));
    }
    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body) || !$body) { return $this->invalid('Invalid or empty JSON body'); }
        $hasUrls = isset($body['page_urls']);
        $hasIds = isset($body['page_uniques']);
        if ($hasUrls && $hasIds) { return $this->invalid('Choose one lookup method'); }
        if ($hasUrls || $hasIds) {
            $key = $hasUrls ? 'page_urls':'page_uniques';
            if (!is_array($body[$key]) || !$body[$key] || count($body[$key]) > 100 || array_filter($body[$key], 'is_string') !== array_values($body[$key])) {
                return $this->invalid('Expected a list of up to 100 string identifiers');
            }
            $results = [];
            foreach ($body[$key] as $identifier) {
                $id = $hasUrls ? $this->id_from_url($identifier) : $this->id_from_unique($identifier);
                if (!$id) { continue; }
                $product = wc_get_product($id);
                if ($product instanceof WC_Product && $this->eligible($product)) { $results[$id] = $this->serialize($product); }
            }
            $count = count($results);
            return new WP_REST_Response(['api_version'=>'torob_api_v3','current_page'=>1,'total'=>$count,'max_pages'=>1,'next_cursor'=>null,'products'=>array_values($results)], 200);
        }
        $sort = $body['sort'] ?? null;
        if (!is_string($sort) || !in_array($sort, ['date_added_desc','date_updated_desc','product_id_desc'], true)) { return $this->invalid('sort parameter is missing or invalid'); }
        $cursor = array_key_exists('cursor', $body);
        if ($cursor && ($sort !== 'product_id_desc' || isset($body['page']) || !is_string($body['cursor']) || !ctype_digit($body['cursor']))) {
            return $this->invalid('Invalid cursor pagination parameters');
        }
        $isCursor = $cursor || !array_key_exists('page', $body);
        if ($isCursor && $sort !== 'product_id_desc') { return $this->invalid('Cursor pagination requires product_id_desc'); }
        $page = $isCursor ? 1 : filter_var($body['page'], FILTER_VALIDATE_INT);
        if (!$isCursor && ($page === false || $page < 1 || $page > 100000)) { return $this->invalid('Invalid page number'); }
        $last = $cursor ? (int)$body['cursor'] : null;
        $pageData = $this->find_page($sort, (int)$page, $last, $isCursor);
        $products = [];
        foreach ($pageData['ids'] as $id) {
            $product = wc_get_product($id);
            if ($product instanceof WC_Product && $this->eligible($product)) { $products[] = $this->serialize($product); }
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
    private function id_from_unique(string $unique): int {
        return preg_match('/^wc_([1-9][0-9]{0,15})$/', $unique, $m) ? (int)$m[1] : 0;
    }
    private function id_from_url(string $url): int {
        if (!filter_var($url, FILTER_VALIDATE_URL)) { return 0; }
        $host = wp_parse_url($url, PHP_URL_HOST);
        $storeHost = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($host) || !is_string($storeHost) || strtolower($host) !== strtolower($storeHost)) { return 0; }
        $urlParts = wp_parse_url($url);
        $q = [];
        parse_str((string)($urlParts['query'] ?? ''), $q);
        $variation = isset($q['variation_id']) ? absint($q['variation_id']) : 0;
        if ($variation) {
            $product = wc_get_product($variation);
            if (!$product || !$product->is_type('variation')) { return 0; }
            $canonical = self::url($product);
            $requestedPath = wp_parse_url($url, PHP_URL_PATH);
            $canonicalPath = wp_parse_url($canonical, PHP_URL_PATH);
            return $requestedPath === $canonicalPath ? $variation : 0;
        }
        return url_to_postid($url);
    }
    private function eligible(WC_Product $product): bool {
        if ($product->get_status() !== 'publish') { return false; }
        if ($product->get_catalog_visibility() === 'hidden') { return false; }
        if ($product->is_type('variable')) { return false; } // children are exported individually.
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent || $parent->get_status() !== 'publish' || $parent->get_catalog_visibility()==='hidden') { return false; }
        }
        return true;
    }
    private function where(): string {
        global $wpdb;
        $p = $wpdb->posts;
        $m = $wpdb->postmeta;
        return "p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} vx JOIN {$wpdb->term_taxonomy} vtx ON vtx.term_taxonomy_id=vx.term_taxonomy_id JOIN {$wpdb->terms} vt ON vt.term_id=vtx.term_id WHERE vx.object_id=IF(p.post_type='product_variation',p.post_parent,p.ID) AND vtx.taxonomy='product_visibility' AND vt.slug='exclude-from-catalog') AND ((p.post_type='product' AND NOT EXISTS (SELECT 1 FROM $m pm WHERE pm.post_id=p.ID AND pm.meta_key='_product_type' AND pm.meta_value='variable') AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug='variable')) OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM $p parent WHERE parent.ID=p.post_parent AND parent.post_status='publish')))";
    }
    private function find_page(string $sort, int $page, ?int $cursor, bool $isCursor): array {
        global $wpdb;
        $table = $wpdb->posts;
        $where = $this->where();
        $order = match($sort) { 'date_added_desc' => 'p.post_date_gmt DESC,p.ID DESC', 'date_updated_desc' => 'p.post_modified_gmt DESC,p.ID DESC', default=>'p.ID DESC' };
        if ($isCursor && $cursor !== null) { $where .= $wpdb->prepare(' AND p.ID < %d', $cursor); }
        $offset = $isCursor ? 0 : (($page-1)*100);
        $raw = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM $table p WHERE $where ORDER BY $order LIMIT %d OFFSET %d", $isCursor ? 101 : 100, $offset)) ?: []);
        $hasMore = $isCursor && count($raw) > 100;
        $ids = array_slice($raw, 0, 100);
        $total = $isCursor ? null : (int)$wpdb->get_var("SELECT COUNT(*) FROM $table p WHERE $where");
        return ['ids'=>$ids,'has_more'=>$hasMore,'total'=>$total,'max_pages'=>$total === null?null:(int)ceil($total/100)];
    }
    private function toman(string $price): int {
        try { return Money::toToman($price, (string)get_option('mbwc_currency_unit','IRR')); }
        catch (\Throwable $e) {
            Logger::record('torob','error','money_conversion','invalid','Invalid or unsupported product money amount');
            return 0;
        }
    }
    private static function truncate(string $s, int $max): string { return function_exists('mb_substr') ? mb_substr($s,0,$max) : wp_html_excerpt($s,$max); }
    public static function unique(int $id): string { return 'wc_'.$id; }
    public static function url(WC_Product $product): string {
        if ($product->is_type('variation')) {
            $parent = get_permalink($product->get_parent_id());
            $args = $product->get_variation_attributes();
            $args['variation_id'] = $product->get_id();
            return add_query_arg($args, $parent);
        }
        return get_permalink($product->get_id());
    }
    private function serialize(WC_Product $product): array {
        $price = (string)$product->get_price();
        $regular = (string)$product->get_regular_price();
        $available = $product->is_purchasable() && $product->is_in_stock() && $price !== '';
        if ($available) {
            try { Money::toToman($price, (string)get_option('mbwc_currency_unit','IRR')); }
            catch (\Throwable $e) { $available=false; }
        }
        $mainId = $product->get_image_id();
        if (!$mainId && $product->is_type('variation')) { $mainId = (int)get_post_thumbnail_id($product->get_parent_id()); }
        $images = [];
        if ($mainId) { $image = wp_get_attachment_image_url($mainId, 'full'); if ($image) { $images[]=$image; } }
        foreach ($product->get_gallery_image_ids() as $id) {
            $image = wp_get_attachment_image_url($id, 'full'); if ($image && !in_array($image, $images, true)) { $images[]=$image; }
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
        $result = [
            'page_unique'=>self::unique($product->get_id()), 'page_url'=>self::url($product),
            'product_group_id'=>(string)$parentId, 'title'=>self::truncate(wp_strip_all_tags($product->get_name()),500),
            'current_price'=>$available?$this->toman($price):0,
            'availability'=>(bool)$available,
            'category_name'=>self::truncate((string)$category,200),
            'image_links'=>$images, 'spec'=>$spec ?: (object)[],
            'short_desc'=>self::truncate(wp_strip_all_tags($product->get_short_description()),500),
            'date_added'=>$created ? $created->date(DATE_ATOM) : gmdate(DATE_ATOM),
            'date_updated'=>$modified ? $modified->date(DATE_ATOM) : gmdate(DATE_ATOM)
        ];
        if ($available && $regular !== '') {
            try {
                $old = Money::toToman($regular,(string)get_option('mbwc_currency_unit','IRR'));
                if ($old > $result['current_price']) { $result['old_price']=$old; }
            } catch (\Throwable $e) { /* Invalid regular price must not affect current price. */ }
        }
        return $result;
    }
}
