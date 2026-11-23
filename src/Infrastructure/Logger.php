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
}
