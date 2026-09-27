<?php
/**
 * Plugin Name:       WD Kampanya – Kampanya Motoru + Geriye Dönük Simülatör
 * Plugin URI:        https://oblifex.com
 * Description:       "3 Al 2 Öde", "2. ürüne %50", kademeli sepet indirimi, kombin, sepette %X ve kargo bedava kampanyalarını tek motorda yönetin. Çakışma kuralları, sepet ipuçları ve "bu kampanya geçen ay aktif olsaydı?" sorusunu yanıtlayan geriye dönük simülatör içerir.
 * Version:           1.0.0
 * Author:            Web Danışmanı
 * Author URI:        https://webdanismani.com
 * Text Domain:       wd-kampanya
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 * License:           GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'WDKM_VERSION', '1.0.0' );
define( 'WDKM_FILE', __FILE__ );
define( 'WDKM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDKM_URL', plugin_dir_url( __FILE__ ) );
define( 'WDKM_SUPPORT_URL', 'https://oblifex.com' );
define( 'WDKM_REPO_URL', 'https://github.com/webdanismani/wd-kampanya' );

// Eksik yükleme koruması: dosyalar eksikse (ör. GitHub web yüklemesinde klasörler atlanmışsa)
// site çökmez; eklenti çalışmaz ve yöneticiye yeniden kurulum uyarısı gösterilir.
$wdkm_required = array(
	'includes/class-wdkm-settings.php',
	'includes/class-wdkm-install.php',
	'includes/class-wdkm-campaigns.php',
	'includes/class-wdkm-engine.php',
	'includes/class-wdkm-cart.php',
	'includes/class-wdkm-backtest.php',
	'includes/class-wdkm-admin.php',
	'assets/css/admin.css',
	'assets/css/frontend.css',
	'assets/js/admin.js',
);
$wdkm_missing  = array();
foreach ( $wdkm_required as $wdkm_file ) {
	if ( ! is_readable( WDKM_DIR . $wdkm_file ) ) {
		$wdkm_missing[] = $wdkm_file;
	}
}
if ( $wdkm_missing ) {
	add_action(
		'admin_notices',
		function () use ( $wdkm_missing ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$list = implode( ', ', array_slice( $wdkm_missing, 0, 5 ) ) . ( count( $wdkm_missing ) > 5 ? ' …' : '' );
			printf(
				'<div class="notice notice-error"><p><strong>WD Kampanya eksik yüklenmiş, bu yüzden çalıştırılmadı.</strong> Bulunamayan dosyalar: <code>%s</code></p><p>Eklentiyi silip <a href="%s" target="_blank" rel="noopener">GitHub sayfasından</a> (Code → Download ZIP) ya da <a href="%s" target="_blank" rel="noopener">oblifex.com</a> üzerindeki paketle yeniden kurun.</p></div>',
				esc_html( $list ),
				esc_url( WDKM_REPO_URL ),
				esc_url( WDKM_SUPPORT_URL )
			);
		}
	);
	return;
}
foreach ( $wdkm_required as $wdkm_file ) {
	if ( '.php' === substr( $wdkm_file, -4 ) ) {
		require_once WDKM_DIR . $wdkm_file;
	}
}
unset( $wdkm_required, $wdkm_missing, $wdkm_file );

register_activation_hook( __FILE__, array( 'WDKM_Install', 'activate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>WD Kampanya</strong> çalışmak için WooCommerce eklentisine ihtiyaç duyar.</p></div>';
				}
			);
			return;
		}
		WDKM_Install::maybe_upgrade();
		new WDKM_Cart();
		if ( is_admin() ) {
			new WDKM_Admin();
		}
	},
	20
);
