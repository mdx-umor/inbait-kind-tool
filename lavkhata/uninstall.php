<?php
/**
 * Uninstall. Your accounting data (ledger) is kept unless you opt in,
 * because deleting a plugin should not delete your books.
 * To remove everything, add this to wp-config.php before uninstalling:
 *   define( 'LAVKHATA_REMOVE_ALL_DATA', true );
 *
 * @package Lavkhata
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'lvk_nightly_sync' );
wp_clear_scheduled_hook( 'lvk_backfill' );
wp_clear_scheduled_hook( 'lvk_sync_batch' );

if ( defined( 'LAVKHATA_REMOVE_ALL_DATA' ) && LAVKHATA_REMOVE_ALL_DATA ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lvk_orders" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lvk_ledger" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lvk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lvk\\_%'" );
	// phpcs:enable
	foreach ( array( 'lavkhata_settings', 'lvk_db_version', 'lvk_backfill_cursor', 'lvk_backfill_done', 'lvk_last_sync', 'woocommerce_lvk_advance_settings' ) as $opt ) {
		delete_option( $opt );
	}
}
