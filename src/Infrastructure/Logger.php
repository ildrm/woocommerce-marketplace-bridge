<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

final class Logger {
    public static function record(string $provider, string $level, string $action, string $status, string $detail = ''): void {
        global $wpdb;
        $wpdb->insert(Database::table('logs'), [
            'provider'=>substr($provider,0,40), 'level'=>substr($level,0,20),
            'action'=>substr($action,0,100), 'status'=>substr($status,0,30),
            'detail'=>substr($detail,0,500), 'created_at'=>gmdate('Y-m-d H:i:s')
        ], ['%s','%s','%s','%s','%s','%s']);
    }
    public static function recent(int $limit=50): array {
        global $wpdb;
        $table = Database::table('logs');
        return $wpdb->get_results($wpdb->prepare("SELECT id,provider,level,action,status,detail,created_at FROM $table ORDER BY id DESC LIMIT %d", min(200,max(1,$limit))), ARRAY_A) ?: [];
    }
    public static function page(int $page, string $provider, string $level): array|\WP_Error {
        global $wpdb;
        $table = Database::table('logs');
        $where = 'WHERE 1=1';
        if ($provider !== '') { $where .= $wpdb->prepare(' AND provider=%s', $provider); }
        if ($level !== '') { $where .= $wpdb->prepare(' AND level=%s', $level); }
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        if ($wpdb->last_error) { return new \WP_Error('mbwc_logs', 'Could not read the activity log.'); }
        $pages = max(1, (int)ceil($count / 30));
        $page = min(max(1, $page), $pages);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT provider, level, action, status, detail, created_at
            FROM $table $where ORDER BY id DESC LIMIT 30 OFFSET %d", ($page - 1) * 30), ARRAY_A);
        if ($wpdb->last_error) { return new \WP_Error('mbwc_logs', 'Could not read the activity log.'); }
        return ['page'=>$page, 'pages'=>$pages, 'rows'=>$rows ?: []];
    }
}
