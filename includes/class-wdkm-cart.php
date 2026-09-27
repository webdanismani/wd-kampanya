<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce entegrasyonu: sepet indirimleri (negatif ücret satırı), kargo bedava, sipariş kaydı,
 * kullanım takibi, ürün rozetleri ve sepet ipuçları.
 */
class WDKM_Cart {

	private static $cache = array();
	private $badge_done   = false;

	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'shipping' ), 50, 2 );

		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_fee_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_usage' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_usage' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'release_usage' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'release_usage' ) );

		add_action( 'woocommerce_before_cart_table', array( $this, 'hints_box' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'hints_box' ), 5 );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'hints_box' ) );
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'savings_row' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'savings_row' ) );

		add_action( 'woocommerce_single_product_summary', array( $this, 'single_badges' ), 6 );
		add_filter( 'render_block', array( $this, 'block_badges' ), 10, 3 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'loop_badges' ), 11 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style( 'wdkm-front', WDKM_URL . 'assets/css/frontend.css', array(), WDKM_VERSION );
	}

	/* ====================================================================
	 * Sanal sepet
	 * ================================================================== */

	public static function cats( $product_id ) {
		static $c = array();
		if ( ! isset( $c[ $product_id ] ) ) {
			$ids = wc_get_product_term_ids( $product_id, 'product_cat' );
			$all = $ids;
			foreach ( $ids as $t ) {
				$all = array_merge( $all, get_ancestors( $t, 'product_cat', 'taxonomy' ) );
			}
			$c[ $product_id ] = array_values( array_unique( array_map( 'intval', $all ) ) );
		}
		return $c[ $product_id ];
	}

	public static function user_roles( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $user_id ) {
			return array( 'guest' );
		}
		$u = get_userdata( $user_id );
		return $u && $u->roles ? array_values( $u->roles ) : array( 'guest' );
	}

	/** WooCommerce sepetinden sanal sepet üretir. */
	public static function virtual_cart( $wc_cart = null ) {
		$wc_cart = $wc_cart ? $wc_cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$lines   = array();
		$sub     = 0.0;
		if ( $wc_cart ) {
			foreach ( $wc_cart->get_cart() as $key => $item ) {
				$p = isset( $item['data'] ) ? $item['data'] : null;
				if ( ! $p instanceof WC_Product ) {
					continue;
				}
				$price         = (float) $p->get_price();
				$qty           = (int) $item['quantity'];
				$lines[ $key ] = array(
					'product_id'   => (int) $item['product_id'],
					'variation_id' => (int) $item['variation_id'],
					'qty'          => $qty,
					'price'        => $price,
					'cats'         => self::cats( (int) $item['product_id'] ),
					'on_sale'      => $p->is_on_sale(),
					'name'         => $p->get_name(),
				);
				$sub          += $price * $qty;
			}
		}
		return array(
			'lines'    => $lines,
			'subtotal' => round( $sub, 4 ),
			'shipping' => 0,
			'roles'    => self::user_roles(),
			'time'     => time(),
		);
	}

	/** Sepet için motor sonucu (istek içinde önbellekli). */
	public static function result( $wc_cart = null ) {
		$wc_cart = $wc_cart ? $wc_cart : WC()->cart;
		if ( ! $wc_cart || $wc_cart->is_empty() ) {
			return array( 'total' => 0, 'campaigns' => array(), 'hints' => array() );
		}
		$cart = self::virtual_cart( $wc_cart );
		$key  = md5( wp_json_encode( array( $cart['lines'], $cart['roles'], $wc_cart->get_applied_coupons() ) ) );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$live = WDKM_Campaigns::live();
		if ( 'block' === WDKM_Settings::get( 'coupon_policy' ) && $wc_cart->get_applied_coupons() ) {
			$live = array();
		}
		self::$cache[ $key ] = WDKM_Engine::run( $cart, $live );
		return self::$cache[ $key ];
	}

	/* ====================================================================
	 * İndirim satırları ve kargo
	 * ================================================================== */

	public function add_fees( $wc_cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$res = self::result( $wc_cart );
		if ( empty( $res['campaigns'] ) ) {
			return;
		}
		$items   = $wc_cart->get_cart();
		$tax_on  = wc_tax_enabled();
		$incl    = wc_prices_include_tax();
		$prefix  = trim( (string) WDKM_Settings::get( 'fee_prefix' ) );
		$prefix  = '' === $prefix ? '' : $prefix . ' ';

		foreach ( $res['campaigns'] as $c ) {
			if ( $c['amount'] <= 0 ) {
				continue;
			}
			$amount = 0.0;
			foreach ( $c['lines'] as $key => $disc ) {
				$ratio = 1.0;
				if ( $tax_on && $incl && isset( $items[ $key ] ) ) {
					$st  = (float) ( $items[ $key ]['line_subtotal'] ?? 0 );
					$stt = (float) ( $items[ $key ]['line_subtotal_tax'] ?? 0 );
					if ( $st + $stt > 0 ) {
						$ratio = $st / ( $st + $stt );
					}
				}
				$amount += $disc * $ratio;
			}
			$wc_cart->fees_api()->add_fee(
				array(
					'id'        => 'wdkm-' . $c['id'],
					'name'      => $prefix . $c['label'],
					'amount'    => -1 * round( $amount, wc_get_price_decimals() + 2 ),
					'taxable'   => $tax_on,
					'tax_class' => '',
				)
			);
		}
	}

	public function shipping( $rates, $package ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $rates;
		}
		$res  = self::result();
		$free = null;
		foreach ( $res['campaigns'] as $c ) {
			if ( ! empty( $c['free_shipping'] ) ) {
				$free = $c;
				break;
			}
		}
		if ( ! $free ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			$cost = (float) $rate->get_cost();
			if ( $cost <= 0 ) {
				continue;
			}
			$rate->add_meta_data( 'wdkm_campaign_id', $free['id'] );
			$rate->add_meta_data( 'wdkm_original_cost', $cost + array_sum( (array) $rate->get_taxes() ) );
			$rate->set_cost( 0 );
			$rate->set_taxes( array() );
			$rate->set_label( $rate->get_label() . ' – ' . $free['label'] );
		}
		return $rates;
	}

	/* ====================================================================
	 * Sipariş ve kullanım
	 * ================================================================== */

	public function tag_fee_item( $item, $fee_key, $fee ) {
		if ( isset( $fee->id ) && 0 === strpos( (string) $fee->id, 'wdkm-' ) ) {
			$item->add_meta_data( '_wdkm_campaign_id', (int) substr( $fee->id, 5 ), true );
		}
	}

	public function record_usage( $order ) {
		global $wpdb;
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$by = array();
		foreach ( $order->get_fees() as $fee ) {
			$cid = (int) $fee->get_meta( '_wdkm_campaign_id' );
			if ( $cid ) {
				$by[ $cid ] = ( $by[ $cid ] ?? 0 ) + abs( (float) $fee->get_total() + (float) $fee->get_total_tax() );
			}
		}
		foreach ( $order->get_shipping_methods() as $ship ) {
			$cid = (int) $ship->get_meta( 'wdkm_campaign_id' );
			if ( $cid ) {
				$by[ $cid ] = ( $by[ $cid ] ?? 0 ) + (float) $ship->get_meta( 'wdkm_original_cost' );
			}
		}
		if ( ! $by ) {
			return;
		}
		$u = $wpdb->prefix . 'wdkm_usage';
		foreach ( $by as $cid => $amt ) {
			$amt = round( $amt, wc_get_price_decimals() );
			$by[ $cid ] = $amt;
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$u} (campaign_id, order_id, discount, created_at) VALUES (%d, %d, %f, %s)", $cid, $order->get_id(), $amt, current_time( 'mysql', true ) ) ); // phpcs:ignore
		}
		$order->update_meta_data( '_wdkm_applied', $by );
		$order->save_meta_data();
	}

	public function release_usage( $order_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wdkm_usage', array( 'order_id' => (int) $order_id ) );
	}

	/* ====================================================================
	 * Sepet ipuçları ve kazanç satırı
	 * ================================================================== */

	public static function hint_texts( array $res ) {
		$out = array();
		foreach ( $res['hints'] as $h ) {
			if ( 'items' === $h['type'] ) {
				$out[] = sprintf( '<strong>%d ürün daha</strong> ekleyin, <strong>%s</strong> kampanyasından yararlanın.', (int) $h['need'], esc_html( $h['label'] ) );
			} else {
				$out[] = sprintf( 'Sepetinize <strong>%s</strong> daha ürün ekleyin, <strong>%s</strong> kazanın.', wc_price( $h['need'] ), esc_html( $h['label'] ) );
			}
		}
		return array_unique( $out );
	}

	public function hints_box() {
		if ( ! WDKM_Settings::yes( 'show_hints' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$t = self::hint_texts( self::result() );
		if ( ! $t ) {
			return;
		}
		echo '<div class="wdkm-hints">';
		foreach ( array_slice( $t, 0, 3 ) as $line ) {
			echo '<p class="wdkm-hint"><span class="wdkm-hint__icon" aria-hidden="true">🎁</span><span>' . wp_kses_post( $line ) . '</span></p>';
		}
		echo '</div>';
	}

	public function savings_row() {
		if ( ! WDKM_Settings::yes( 'show_savings' ) ) {
			return;
		}
		$res = self::result();
		if ( $res['total'] <= 0 ) {
			return;
		}
		printf( '<tr class="wdkm-savings"><th>Kampanya kazancınız</th><td data-title="Kampanya kazancınız"><strong>%s</strong></td></tr>', wp_kses_post( wc_price( $res['total'] ) ) );
	}

	/* ====================================================================
	 * Ürün rozetleri
	 * ================================================================== */

	/** Ürün için geçerli kampanya etiketleri. */
	public static function product_labels( WC_Product $product, $loop = false ) {
		$pid  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$line = array(
			'product_id'   => $pid,
			'variation_id' => 0,
			'qty'          => 1,
			'price'        => (float) $product->get_price(),
			'cats'         => self::cats( $pid ),
			'on_sale'      => $product->is_on_sale(),
		);
		$roles = self::user_roles();
		$out   = array();
		foreach ( WDKM_Campaigns::live() as $c ) {
			if ( $loop && ! in_array( $c['type'], array( 'bxgy', 'nth', 'percent', 'bundle' ), true ) ) {
				continue;
			}
			$g = $c['config'];
			if ( ! empty( $g['roles'] ) && ! array_intersect( $g['roles'], $roles ) ) {
				continue;
			}
			if ( 'bundle' === $c['type'] ) {
				$ids = array( $pid );
				if ( $product->is_type( 'variable' ) ) {
					$ids = array_merge( $ids, $product->get_children() );
				}
				if ( ! array_intersect( $ids, array_map( 'intval', $g['components'] ) ) ) {
					continue;
				}
			} elseif ( ! WDKM_Engine::matches( $c, $line ) ) {
				continue;
			}
			$out[ $c['id'] ] = array(
				'label' => WDKM_Campaigns::label( $c ),
				'type'  => $c['type'],
				'c'     => $c,
			);
		}
		return $out;
	}

	private static function badges_html( WC_Product $product, $loop ) {
		$labels = self::product_labels( $product, $loop );
		if ( ! $labels ) {
			return '';
		}
		$html = '<div class="wdkm-badges' . ( $loop ? ' wdkm-badges--loop' : '' ) . '">';
		foreach ( $labels as $l ) {
			$html .= '<span class="wdkm-badge wdkm-badge--' . esc_attr( $l['type'] ) . '">' . esc_html( $l['label'] ) . '</span>';
		}
		$html .= '</div>';

		if ( ! $loop ) {
			foreach ( $labels as $l ) {
				if ( 'bundle' === $l['type'] ) {
					$names = array();
					foreach ( $l['c']['config']['components'] as $cid ) {
						$cp = wc_get_product( $cid );
						if ( $cp && (int) $cid !== $product->get_id() ) {
							$names[] = '<a href="' . esc_url( $cp->get_permalink() ) . '">' . esc_html( $cp->get_name() ) . '</a>';
						}
					}
					if ( $names ) {
						$html .= '<p class="wdkm-bundle-note">Birlikte alın: ' . implode( ' + ', $names ) . '</p>';
					}
				}
			}
		}
		return $html;
	}

	public function single_badges() {
		global $product;
		if ( $this->badge_done || ! WDKM_Settings::yes( 'show_badges' ) || ! $product instanceof WC_Product ) {
			return;
		}
		$h = self::badges_html( $product, false );
		if ( $h ) {
			$this->badge_done = true;
			echo $h; // phpcs:ignore
		}
	}

	public function block_badges( $content, $block, $instance = null ) {
		if ( $this->badge_done || empty( $block['blockName'] ) || 'woocommerce/product-price' !== $block['blockName'] || ! is_product() || ! WDKM_Settings::yes( 'show_badges' ) ) {
			return $content;
		}
		$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
		if ( $pid !== (int) get_queried_object_id() ) {
			return $content;
		}
		$p = wc_get_product( $pid );
		$h = $p ? self::badges_html( $p, false ) : '';
		if ( $h ) {
			$this->badge_done = true;
			return $h . $content;
		}
		return $content;
	}

	public function loop_badges() {
		global $product;
		if ( ! WDKM_Settings::yes( 'show_loop_badges' ) || ! $product instanceof WC_Product ) {
			return;
		}
		echo self::badges_html( $product, true ); // phpcs:ignore
	}
}
