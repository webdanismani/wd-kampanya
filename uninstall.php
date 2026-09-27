<?php
/**
 * Eklenti silinirken çalışır. Siparişlerdeki indirim satırları her durumda korunur.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wdkm_settings = get_option( 'wdkm_settings', array() );
if ( is_array( $wdkm_settings ) && isset( $wdkm_settings['delete_on_uninstall'] ) && 'yes' === $wdkm_settings['delete_on_uninstall'] ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdkm_campaigns" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdkm_usage" ); // phpcs:ignore
	delete_option( 'wdkm_settings' );
	delete_option( 'wdkm_db_version' );
}
