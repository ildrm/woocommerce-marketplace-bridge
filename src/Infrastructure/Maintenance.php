<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Infrastructure;

/** Housekeeping; retention is bounded to avoid unlimited PII/log growth. */
final class Maintenance {
    public const HOOK='mbwc_daily_cleanup';
    public function register(): void {
        add_action('init',static function (): void {
            if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time()+HOUR_IN_SECONDS,'daily',self::HOOK); }
        },20);
        add_action(self::HOOK,[self::class,'run']);
    }
    public static function run(): void {
        global $wpdb;
        $retention=(int)get_option('mbwc_log_retention_days',30);
        $retention=min(365,max(7,$retention));
        $logBefore=gmdate('Y-m-d H:i:s',time()-$retention*DAY_IN_SECONDS);
        $attrBefore=gmdate('Y-m-d H:i:s',time()-90*DAY_IN_SECONDS);
        foreach ([['logs','created_at',$logBefore],['attribution','purchase_at',$attrBefore],['actions','happened_at',$attrBefore]] as [$table,$column,$before]) {
            $dbTable=Database::table($table);
            // Bound each invocation to protect shared-hosting request time and DB load.
            $wpdb->query($wpdb->prepare("DELETE FROM $dbTable WHERE $column < %s LIMIT 5000",$before));
        }
    }
}
