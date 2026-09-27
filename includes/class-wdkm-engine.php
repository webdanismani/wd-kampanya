<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kampanya motoru. WooCommerce'ten bağımsız, saf hesaplama yapar; canlı sepet ve geriye dönük
 * simülatör aynı motoru kullanır.
 *
 * Sanal sepet:
 *   lines    => [ key => [product_id, variation_id, qty, price (birim), cats[], on_sale, name] ]
 *   subtotal => ürün ara toplamı
 *   shipping => kargo tutarı (simülatör için)
 *   roles    => kullanıcı rolleri ('guest' misafir)
 *   time     => zaman damgası (tarih koşulları için)
 */
class WDKM_Engine {

	/**
	 * @param array $opts item_once (bool), mode (priority|best), check_dates (bool)
	 * @return array total, campaigns[id => …], hints[]
	 */
	public static function run( array $cart, array $campaigns, array $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'item_once'   => WDKM_Settings::yes( 'item_once' ),
				'mode'        => WDKM_Settings::get( 'conflict_mode' ),
				'check_dates' => false,
			)
		);

		usort(
			$campaigns,
			function ( $a, $b ) {
				return $a['priority'] === $b['priority'] ? $a['id'] - $b['id'] : $a['priority'] - $b['priority'];
			}
		);

		$ok = array();
		foreach ( $campaigns as $c ) {
			if ( self::conditions_ok( $c, $cart, $opts ) ) {
				$ok[] = $c;
			}
		}

		if ( 'best' === $opts['mode'] ) {
			$stack   = array_values(
				array_filter(
					$ok,
					function ( $c ) {
						return 'stack' === $c['stacking'];
					}
				)
			);
			$best    = self::sequence( $cart, $stack, $opts, false );
			foreach ( $ok as $c ) {
				if ( 'exclusive' === $c['stacking'] ) {
					$r = self::sequence( $cart, array( $c ), $opts, false );
					if ( $r['total'] > $best['total'] + 0.004 ) {
						$best = $r;
					}
				}
			}
			$result = $best;
		} else {
			$result = self::sequence( $cart, $ok, $opts, true );
		}

		$result['hints'] = self::hints( $cart, $campaigns, $opts, $result );
		return $result;
	}

	/* ====================================================================
	 * Koşullar ve hedefleme
	 * ================================================================== */

	public static function conditions_ok( array $c, array $cart, array $opts ) {
		$g = $c['config'];
		if ( ! empty( $g['roles'] ) ) {
			$roles = isset( $cart['roles'] ) ? (array) $cart['roles'] : array( 'guest' );
			if ( ! array_intersect( $g['roles'], $roles ) ) {
				return false;
			}
		}
		if ( (float) $g['min_subtotal'] > 0 && (float) $cart['subtotal'] < (float) $g['min_subtotal'] ) {
			return false;
		}
		if ( ! empty( $opts['check_dates'] ) ) {
			$t = gmdate( 'Y-m-d H:i:s', isset( $cart['time'] ) ? (int) $cart['time'] : time() );
			if ( ( $c['starts_at'] && $c['starts_at'] > $t ) || ( $c['ends_at'] && $c['ends_at'] < $t ) ) {
				return false;
			}
		}
		return true;
	}

	public static function matches( array $c, array $line, $ignore_include = false ) {
		$g   = $c['config'];
		$ids = array_filter( array( (int) $line['product_id'], (int) $line['variation_id'] ) );
		$cat = isset( $line['cats'] ) ? array_map( 'intval', (array) $line['cats'] ) : array();

		if ( $g['exclude_products'] && array_intersect( $ids, $g['exclude_products'] ) ) {
			return false;
		}
		if ( $g['exclude_cats'] && array_intersect( $cat, $g['exclude_cats'] ) ) {
			return false;
		}
		if ( ! empty( $g['exclude_sale'] ) && ! empty( $line['on_sale'] ) ) {
			return false;
		}
		if ( $ignore_include || ( ! $g['include_products'] && ! $g['include_cats'] ) ) {
			return true;
		}
		return (bool) ( array_intersect( $ids, $g['include_products'] ) || array_intersect( $cat, $g['include_cats'] ) );
	}

	/** Uygun ve henüz tüketilmemiş birimler (adet başına bir kayıt). */
	private static function units( array $c, array $cart, array $avail, $ignore_include = false ) {
		$u = array();
		foreach ( $cart['lines'] as $key => $line ) {
			$n = isset( $avail[ $key ] ) ? (int) $avail[ $key ] : 0;
			if ( $n <= 0 || (float) $line['price'] <= 0 || ! self::matches( $c, $line, $ignore_include ) ) {
				continue;
			}
			for ( $i = 0; $i < $n; $i++ ) {
				$u[] = array(
					'key'   => $key,
					'price' => (float) $line['price'],
					'pid'   => (int) $line['product_id'],
					'vid'   => (int) $line['variation_id'],
				);
			}
		}
		return $u;
	}

	private static function sort_units( array &$u, $desc = true ) {
		usort(
			$u,
			function ( $a, $b ) use ( $desc ) {
				if ( abs( $a['price'] - $b['price'] ) < 0.00001 ) {
					return strcmp( $a['key'], $b['key'] );
				}
				return $desc ? ( $b['price'] <=> $a['price'] ) : ( $a['price'] <=> $b['price'] );
			}
		);
	}

	/* ====================================================================
	 * Uygulama sırası
	 * ================================================================== */

	private static function sequence( array $cart, array $list, array $opts, $priority_mode ) {
		$avail = array();
		$left  = array();
		foreach ( $cart['lines'] as $k => $l ) {
			$avail[ $k ] = (int) $l['qty'];
			$left[ $k ]  = (float) $l['price'] * (int) $l['qty'];
		}
		$applied = array();
		$total   = 0.0;

		foreach ( $list as $c ) {
			if ( $priority_mode && 'exclusive' === $c['stacking'] && $applied ) {
				continue;
			}
			$full = array();
			foreach ( $cart['lines'] as $k => $l ) {
				$full[ $k ] = (int) $l['qty'];
			}
			$r = self::apply_type( $c, $cart, $opts['item_once'] ? $avail : $full );
			if ( empty( $r['lines'] ) && empty( $r['free_shipping'] ) ) {
				continue;
			}

			$lines = array();
			foreach ( $r['lines'] as $k => $amt ) {
				$amt = min( round( $amt, 4 ), $left[ $k ] );
				if ( $amt > 0 ) {
					$lines[ $k ] = $amt;
					$left[ $k ] -= $amt;
				}
			}
			if ( $opts['item_once'] ) {
				foreach ( $r['consumed'] as $k => $q ) {
					$avail[ $k ] = max( 0, $avail[ $k ] - $q );
				}
			}
			$amount = round( array_sum( $lines ), 2 );
			$ship   = ! empty( $r['free_shipping'] ) ? round( (float) ( $cart['shipping'] ?? 0 ), 2 ) : 0.0;
			if ( $amount <= 0 && empty( $r['free_shipping'] ) ) {
				continue;
			}
			$applied[ $c['id'] ] = array(
				'id'                => $c['id'],
				'name'              => $c['name'],
				'label'             => WDKM_Campaigns::label( $c ),
				'type'              => $c['type'],
				'amount'            => $amount,
				'lines'             => $lines,
				'free_shipping'     => ! empty( $r['free_shipping'] ),
				'shipping_discount' => $ship,
			);
			$total += $amount + $ship;

			if ( $priority_mode && 'exclusive' === $c['stacking'] ) {
				break;
			}
		}

		return array(
			'total'     => round( $total, 2 ),
			'campaigns' => $applied,
		);
	}

	/* ====================================================================
	 * Kampanya tipleri
	 * ================================================================== */

	private static function apply_type( array $c, array $cart, array $avail ) {
		$g   = $c['config'];
		$out = array(
			'lines'         => array(),
			'consumed'      => array(),
			'free_shipping' => false,
		);
		$add = function ( $unit, $amount ) use ( &$out ) {
			$out['lines'][ $unit['key'] ] = ( $out['lines'][ $unit['key'] ] ?? 0 ) + $amount;
		};
		$use = function ( $unit ) use ( &$out ) {
			$out['consumed'][ $unit['key'] ] = ( $out['consumed'][ $unit['key'] ] ?? 0 ) + 1;
		};
		$cap = (int) $g['max_times'];

		switch ( $c['type'] ) {

			case 'bxgy':
				$buy    = max( 2, (int) $g['buy'] );
				$free_n = $buy - max( 1, (int) $g['pay'] );
				$units  = self::units( $c, $cart, $avail );
				$groups = array();
				foreach ( $units as $u ) {
					$groups[ $g['same_product'] ? $u['pid'] : 0 ][] = $u;
				}
				$left_apps = $cap > 0 ? $cap : PHP_INT_MAX;
				foreach ( $groups as $grp ) {
					self::sort_units( $grp, true );
					$apps       = min( (int) floor( count( $grp ) / $buy ), $left_apps );
					$left_apps -= $apps;
					if ( $apps <= 0 ) {
						continue;
					}
					if ( 'chunk' === $g['free_pick'] ) {
						for ( $a = 0; $a < $apps; $a++ ) {
							$chunk = array_slice( $grp, $a * $buy, $buy );
							foreach ( $chunk as $i => $u ) {
								$use( $u );
								if ( $i >= $buy - $free_n ) {
									$add( $u, $u['price'] );
								}
							}
						}
					} else {
						// En ucuz ürünler bedava; kampanyaya en ucuz $apps*$buy birim katılır.
						$part = array_slice( $grp, -$apps * $buy );
						$free = array_slice( $grp, -$apps * $free_n );
						foreach ( $part as $u ) {
							$use( $u );
						}
						foreach ( $free as $u ) {
							$add( $u, $u['price'] );
						}
					}
					if ( $left_apps <= 0 ) {
						break;
					}
				}
				break;

			case 'nth':
				$n     = max( 2, (int) $g['n'] );
				$pct   = (float) $g['percent'];
				$units = self::units( $c, $cart, $avail );
				self::sort_units( $units, true );
				$apps = (int) floor( count( $units ) / $n );
				if ( empty( $g['repeat'] ) ) {
					$apps = min( $apps, 1 );
				}
				if ( $cap > 0 ) {
					$apps = min( $apps, $cap );
				}
				for ( $a = 0; $a < $apps; $a++ ) {
					$chunk = array_slice( $units, $a * $n, $n );
					foreach ( $chunk as $u ) {
						$use( $u );
					}
					$last = $chunk[ $n - 1 ];
					$add( $last, $last['price'] * $pct / 100 );
				}
				break;

			case 'percent':
				$units = self::units( $c, $cart, $avail );
				self::sort_units( $units, true );
				if ( $cap > 0 ) {
					$units = array_slice( $units, 0, $cap );
				}
				foreach ( $units as $u ) {
					$use( $u );
					$add( $u, 'percent' === $g['value_type'] ? $u['price'] * (float) $g['value'] / 100 : min( $u['price'], (float) $g['value'] ) );
				}
				break;

			case 'tiered':
				$units = self::units( $c, $cart, $avail );
				$basis = array_sum( wp_list_pluck( $units, 'price' ) );
				$tier  = self::tier_for( $g['tiers'], $basis );
				if ( ! $tier || $basis <= 0 ) {
					break;
				}
				$amount = 'percent' === $tier['type'] ? $basis * (float) $tier['value'] / 100 : min( (float) $tier['value'], $basis );
				foreach ( $units as $u ) {
					$use( $u );
					$add( $u, $amount * $u['price'] / $basis );
				}
				break;

			case 'bundle':
				$need = array_count_values( array_map( 'intval', (array) $g['components'] ) );
				if ( count( $need ) < 1 ) {
					break;
				}
				$units = self::units( $c, $cart, $avail, true );
				self::sort_units( $units, false );
				$pool = array();
				foreach ( $units as $u ) {
					foreach ( array_keys( $need ) as $comp ) {
						if ( $u['pid'] === $comp || $u['vid'] === $comp ) {
							$pool[ $comp ][] = $u;
							break;
						}
					}
				}
				$apps = PHP_INT_MAX;
				foreach ( $need as $comp => $mult ) {
					$apps = min( $apps, (int) floor( count( $pool[ $comp ] ?? array() ) / $mult ) );
				}
				if ( $cap > 0 ) {
					$apps = min( $apps, $cap );
				}
				for ( $a = 0; $a < $apps; $a++ ) {
					$set = array();
					foreach ( $need as $comp => $mult ) {
						for ( $i = 0; $i < $mult; $i++ ) {
							$set[] = array_shift( $pool[ $comp ] );
						}
					}
					$sum    = array_sum( wp_list_pluck( $set, 'price' ) );
					$amount = 'percent' === $g['bundle_type'] ? $sum * (float) $g['bundle_value'] / 100 : min( (float) $g['bundle_value'], $sum );
					foreach ( $set as $u ) {
						$use( $u );
						$add( $u, $sum > 0 ? $amount * $u['price'] / $sum : 0 );
					}
				}
				break;

			case 'free_shipping':
				$full = array();
				foreach ( $cart['lines'] as $k => $l ) {
					$full[ $k ] = (int) $l['qty'];
				}
				$basis = array_sum( wp_list_pluck( self::units( $c, $cart, $full ), 'price' ) );
				if ( $basis > 0 && $basis >= (float) $g['min_amount'] ) {
					$out['free_shipping'] = true;
				}
				break;
		}

		return $out;
	}

	public static function tier_for( array $tiers, $basis ) {
		$hit = null;
		foreach ( $tiers as $t ) {
			if ( $basis >= (float) $t['min'] ) {
				$hit = $t;
			}
		}
		return $hit;
	}

	/* ====================================================================
	 * Sepet ipuçları ("1 ürün daha ekleyin…")
	 * ================================================================== */

	private static function hints( array $cart, array $campaigns, array $opts, array $result ) {
		$hints = array();
		$full  = array();
		foreach ( $cart['lines'] as $k => $l ) {
			$full[ $k ] = (int) $l['qty'];
		}
		foreach ( $campaigns as $c ) {
			$g = $c['config'];
			$o = $opts;
			// Tutar koşulu dışında koşulları sağlıyor mu?
			$tmp                           = $c;
			$tmp['config']['min_subtotal'] = 0;
			if ( ! self::conditions_ok( $tmp, $cart, $o ) ) {
				continue;
			}
			$label = WDKM_Campaigns::label( $c );

			if ( (float) $g['min_subtotal'] > (float) $cart['subtotal'] && (float) $cart['subtotal'] > 0 ) {
				$hints[] = array(
					'id'    => $c['id'],
					'type'  => 'amount',
					'need'  => round( (float) $g['min_subtotal'] - (float) $cart['subtotal'], 2 ),
					'label' => $label,
				);
				continue;
			}

			$units = self::units( $c, $cart, $full );
			$count = count( $units );
			$basis = array_sum( wp_list_pluck( $units, 'price' ) );

			switch ( $c['type'] ) {
				case 'bxgy':
					$buy = max( 2, (int) $g['buy'] );
					$rem = $count % $buy;
					if ( $count > 0 && $rem > 0 && ( $buy - $rem ) <= 2 && ! self::capped( $c, $count, $buy ) ) {
						$hints[] = array(
							'id'    => $c['id'],
							'type'  => 'items',
							'need'  => $buy - $rem,
							'label' => $label,
						);
					}
					break;
				case 'nth':
					$n   = max( 2, (int) $g['n'] );
					$rem = $count % $n;
					if ( $count > 0 && $rem === $n - 1 && ( ! empty( $g['repeat'] ) || $count < $n ) && ! self::capped( $c, $count, $n ) ) {
						$hints[] = array(
							'id'    => $c['id'],
							'type'  => 'items',
							'need'  => 1,
							'label' => $label,
						);
					}
					break;
				case 'tiered':
					foreach ( $g['tiers'] as $t ) {
						if ( (float) $t['min'] > $basis ) {
							if ( $basis > 0 ) {
								$hints[] = array(
									'id'    => $c['id'],
									'type'  => 'amount',
									'need'  => round( (float) $t['min'] - $basis, 2 ),
									'label' => 'percent' === $t['type'] ? sprintf( '%%%s indirim', wc_format_localized_decimal( (float) $t['value'] + 0 ) ) : wp_strip_all_tags( wc_price( $t['value'] ) ) . ' indirim',
								);
							}
							break;
						}
					}
					break;
				case 'free_shipping':
					if ( $basis > 0 && $basis < (float) $g['min_amount'] ) {
						$hints[] = array(
							'id'    => $c['id'],
							'type'  => 'amount',
							'need'  => round( (float) $g['min_amount'] - $basis, 2 ),
							'label' => 'kargo bedava',
						);
					}
					break;
			}
		}
		return $hints;
	}

	private static function capped( array $c, $count, $size ) {
		$cap = (int) $c['config']['max_times'];
		return $cap > 0 && floor( $count / $size ) >= $cap;
	}
}
