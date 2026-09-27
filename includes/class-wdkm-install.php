<?php
defined( 'ABSPATH' ) || exit;

class WDKM_Install {

	const DB_VERSION = '1.0.0';

	public static function activate() {
		self::create_tables();
		add_option( WDKM_Settings::OPTION, WDKM_Settings::defaults() );
		update_option( 'wdkm_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wdkm_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'wdkm_db_version', self::DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = $wpdb->prefix . 'wdkm_campaigns';
		$u = $wpdb->prefix . 'wdkm_usage';
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL DEFAULT '',
  type varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  priority int(11) NOT NULL DEFAULT 10,
  stacking varchar(20) NOT NULL DEFAULT 'stack',
  starts_at datetime NULL,
  ends_at datetime NULL,
  usage_limit int(11) NOT NULL DEFAULT 0,
  badge varchar(100) NOT NULL DEFAULT '',
  config longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$c};
CREATE TABLE {$u} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  discount decimal(14,4) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_order (campaign_id, order_id),
  KEY order_id (order_id)
) {$c};"
		);
	}
}
