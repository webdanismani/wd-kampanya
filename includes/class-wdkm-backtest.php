<?php
defined( 'ABSPATH' ) || exit;

/**
 * Geriye dönük simülatör: geçmiş siparişleri kampanya motorundan geçirir ve
 * "bu kampanya(lar) o dönemde aktif olsaydı ne olurdu?" sorusunu yanıtlar.
 *
 * Varsayım: müşteriler aynı sepetleri oluştururdu (kampanyanın yaratacağı ek talep hesaba katılmaz).
 * Bu yüzden rapor, kampanyanın kendini amorti etmesi için gereken ek ciroyu da verir.
 */
class WDKM_Backtest {

	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** @return array [ id => [date, customer, shipping, lines] ] */
	public static function fetch_orders( $from_gmt, $to_gmt, array $statuses, $limit ) {
		global $wpdb;
		$st    = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
		$incl  = wc_prices_include_tax();
		$limit = max( 1, (int) $limit );

		if ( self::hpos() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.id, o.date_created_gmt AS d, o.customer_id AS cu,
						COALESCE(op.shipping_total_amount,0) AS s, COALESCE(op.shipping_tax_amount,0) AS st
					FROM {$wpdb->prefix}wc_orders o
					LEFT JOIN {$wpdb->prefix}wc_order_operational_data op ON op.order_id = o.id
					WHERE o.type = 'shop_order' AND o.status IN ({$st}) AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
					ORDER BY o.id DESC LIMIT {$limit}", // phpcs:ignore
					$from_gmt,
					$to_gmt
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS id, p.post_date_gmt AS d, cu.meta_value AS cu,
						COALESCE(s.meta_value,0) AS s, COALESCE(stx.meta_value,0) AS st
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
					LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_order_shipping'
					LEFT JOIN {$wpdb->postmeta} stx ON stx.post_id = p.ID AND stx.meta_key = '_order_shipping_tax'
					WHERE p.post_type = 'shop_order' AND p.post_status IN ({$st}) AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
					ORDER BY p.ID DESC LIMIT {$limit}", // phpcs:ignore
					$from_gmt,
					$to_gmt
				),
				ARRAY_A
			);
		}

		$orders = array();
		foreach ( (array) $rows as $r ) {
			$orders[ (int) $r['id'] ] = array(
				'time'     => strtotime( $r['d'] . ' UTC' ),
				'customer' => (int) $r['cu'],
				'shipping' => (float) $r['s'] + ( $incl ? (float) $r['st'] : 0 ),
				'lines'    => array(),
			);
		}

		foreach ( array_chunk( array_keys( $orders ), 500 ) as $chunk ) {
			$in    = implode( ',', array_map( 'intval', $chunk ) );
			$lines = $wpdb->get_results(
				"SELECT oi.order_id, oi.order_item_id,
					MAX(CASE WHEN m.meta_key = '_product_id' THEN m.meta_value END) AS pid,
					MAX(CASE WHEN m.meta_key = '_variation_id' THEN m.meta_value END) AS vid,
					MAX(CASE WHEN m.meta_key = '_qty' THEN m.meta_value END) AS qty,
					MAX(CASE WHEN m.meta_key = '_line_subtotal' THEN m.meta_value END) AS sub,
					MAX(CASE WHEN m.meta_key = '_line_subtotal_tax' THEN m.meta_value END) AS tax
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
					AND m.meta_key IN ('_product_id','_variation_id','_qty','_line_subtotal','_line_subtotal_tax')
				WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$in})
				GROUP BY oi.order_item_id",
				ARRAY_A
			); // phpcs:ignore
			foreach ( (array) $lines as $l ) {
				$qty = max( 0, (int) $l['qty'] );
				if ( ! $qty ) {
					continue;
				}
				$gross = (float) $l['sub'] + ( $incl ? (float) $l['tax'] : 0 );
				$orders[ (int) $l['order_id'] ]['lines'][ 'i' . $l['order_item_id'] ] = array(
					'product_id'   => (int) $l['pid'],
					'variation_id' => (int) $l['vid'],
					'qty'          => $qty,
					'price'        => round( $gross / $qty, 4 ),
					'cats'         => WDKM_Cart::cats( (int) $l['pid'] ),
					'on_sale'      => false,
				);
			}
		}
		return $orders;
	}

	/**
	 * @param array $args margin (%), statuses, respect_dates (bool), max_orders
	 */
	public static function run( array $campaigns, $from_ts, $to_ts, array $args = array() ) {
		$args   = wp_parse_args(
			$args,
			array(
				'margin'        => 30,
				'statuses'      => array( 'wc-completed', 'wc-processing' ),
				'respect_dates' => false,
				'max_orders'    => (int) WDKM_Settings::get( 'backtest_max_orders' ),
			)
		);
		$start  = microtime( true );
		$orders = self::fetch_orders( gmdate( 'Y-m-d H:i:s', $from_ts ), gmdate( 'Y-m-d H:i:s', $to_ts ), $args['statuses'], $args['max_orders'] );

		$role_cache = array();
		$s          = array(
			'orders'          => 0,
			'revenue'         => 0.0,
			'shipping'        => 0.0,
			'affected'        => 0,
			'affected_rev'    => 0.0,
			'discount'        => 0.0,
			'item_discount'   => 0.0,
			'ship_discount'   => 0.0,
			'per_campaign'    => array(),
			'days'            => array(),
			'products'        => array(),
			'near_orders'     => 0,
			'near_amount_gap' => array(),
			'near_items'      => 0,
			'buckets'         => array( 0, 0, 0, 0, 0 ),
			'truncated'       => count( $orders ) >= $args['max_orders'],
		);

		foreach ( $campaigns as $c ) {
			$s['per_campaign'][ $c['id'] ] = array(
				'name'     => $c['name'],
				'label'    => WDKM_Campaigns::label( $c ),
				'orders'   => 0,
				'discount' => 0.0,
				'free_sh'  => 0,
			);
		}

		foreach ( $orders as $oid => $o ) {
			if ( ! $o['lines'] ) {
				continue;
			}
			$sub = 0.0;
			foreach ( $o['lines'] as $l ) {
				$sub += $l['price'] * $l['qty'];
			}
			$cu = $o['customer'];
			if ( ! isset( $role_cache[ $cu ] ) ) {
				$role_cache[ $cu ] = WDKM_Cart::user_roles( $cu );
			}
			$cart = array(
				'lines'    => $o['lines'],
				'subtotal' => $sub,
				'shipping' => $o['shipping'],
				'roles'    => $role_cache[ $cu ],
				'time'     => $o['time'],
			);
			$res = WDKM_Engine::run( $cart, $campaigns, array( 'check_dates' => ! empty( $args['respect_dates'] ) ) );

			++$s['orders'];
			$s['revenue']  += $sub;
			$s['shipping'] += $o['shipping'];

			if ( $res['total'] > 0 ) {
				++$s['affected'];
				$s['affected_rev'] += $sub;
				$s['discount']     += $res['total'];
				$day                = wp_date( 'Y-m-d', $o['time'] );
				$s['days'][ $day ]  = ( $s['days'][ $day ] ?? 0 ) + $res['total'];
				$pct                = $sub > 0 ? $res['total'] / $sub * 100 : 0;
				$b                  = $pct < 5 ? 0 : ( $pct < 10 ? 1 : ( $pct < 20 ? 2 : ( $pct < 35 ? 3 : 4 ) ) );
				++$s['buckets'][ $b ];

				foreach ( $res['campaigns'] as $cid => $r ) {
					++$s['per_campaign'][ $cid ]['orders'];
					$s['per_campaign'][ $cid ]['discount'] += $r['amount'] + $r['shipping_discount'];
					$s['item_discount']                    += $r['amount'];
					$s['ship_discount']                    += $r['shipping_discount'];
					if ( $r['free_shipping'] ) {
						++$s['per_campaign'][ $cid ]['free_sh'];
					}
					foreach ( $r['lines'] as $key => $amt ) {
						$pid                     = $o['lines'][ $key ]['product_id'];
						$s['products'][ $pid ]   = ( $s['products'][ $pid ] ?? 0 ) + $amt;
					}
				}
			}

			if ( $res['hints'] ) {
				++$s['near_orders'];
				foreach ( $res['hints'] as $h ) {
					if ( 'amount' === $h['type'] ) {
						$s['near_amount_gap'][] = $h['need'];
					} else {
						++$s['near_items'];
					}
				}
			}
		}

		$m               = max( 0.1, (float) $args['margin'] ) / 100;
		$s['margin']     = (float) $args['margin'];
		$s['aov']        = $s['orders'] ? $s['revenue'] / $s['orders'] : 0;
		$s['profit_now'] = $s['revenue'] * $m;
		$s['profit_sim'] = $s['profit_now'] - $s['discount'];
		$s['breakeven']  = $s['discount'] / $m;
		$s['breakeven_orders'] = $s['aov'] > 0 ? $s['breakeven'] / $s['aov'] : 0;
		sort( $s['near_amount_gap'] );
		$n                  = count( $s['near_amount_gap'] );
		$s['near_gap_median'] = $n ? $s['near_amount_gap'][ (int) floor( $n / 2 ) ] : 0;
		arsort( $s['products'] );
		$s['products'] = array_slice( $s['products'], 0, 10, true );
		ksort( $s['days'] );
		$s['seconds'] = round( microtime( true ) - $start, 2 );
		return $s;
	}
}
