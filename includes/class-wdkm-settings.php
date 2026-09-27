<?php
defined( 'ABSPATH' ) || exit;

class WDKM_Settings {

	const OPTION = 'wdkm_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'conflict_mode'       => 'priority',
			'item_once'           => 'yes',
			'coupon_policy'       => 'allow',
			'fee_prefix'          => 'Kampanya: ',
			'show_badges'         => 'yes',
			'show_loop_badges'    => 'yes',
			'show_hints'          => 'yes',
			'show_savings'        => 'yes',
			'backtest_max_orders' => 20000,
			'delete_on_uninstall' => 'no',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$s           = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $k ) {
		$a = self::all();
		return isset( $a[ $k ] ) ? $a[ $k ] : null;
	}

	public static function yes( $k ) {
		return 'yes' === self::get( $k );
	}

	public static function save( array $v ) {
		update_option( self::OPTION, $v );
		self::$cache = null;
	}
}
