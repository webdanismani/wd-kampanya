<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kampanya deposu: tipler, varsayılanlar, doğrulama, okuma/yazma.
 */
class WDKM_Campaigns {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wdkm_campaigns';
	}

	public static function types() {
		return array(
			'bxgy'          => array( 'X Al Y Öde', '3 al 2 öde: her grupta en ucuz ürün(ler) bedava.' ),
			'nth'           => array( 'N. Ürüne İndirim', '2. ürüne %50: sıradaki ürün indirimli.' ),
			'percent'       => array( 'Sepette İndirim', 'Seçili ürün/kategorilerde sepette %X veya ₺X indirim.' ),
			'tiered'        => array( 'Kademeli Sepet İndirimi', '500 ₺ üzeri %5, 1.000 ₺ üzeri %10…' ),
			'bundle'        => array( 'Kombin / Birlikte Al', 'Belirli ürünler birlikte alınınca indirim.' ),
			'free_shipping' => array( 'Kargo Bedava', 'Sepet tutarı eşiği aşınca kargo ücretsiz.' ),
		);
	}

	public static function statuses() {
		return array(
			'active' => 'Aktif',
			'draft'  => 'Taslak',
			'paused' => 'Duraklatıldı',
		);
	}

	public static function default_config() {
		return array(
			// X al Y öde.
			'buy'              => 3,
			'pay'              => 2,
			'same_product'     => false,
			'free_pick'        => 'cheapest',
			// N. ürün.
			'n'                => 2,
			'percent'          => 50,
			'repeat'           => true,
			// Ortak.
			'max_times'        => 0,
			// Sepette indirim.
			'value_type'       => 'percent',
			'value'            => 10,
			// Kademeli.
			'tiers'            => array(
				array(
					'min'   => 500,
					'type'  => 'percent',
					'value' => 5,
				),
				array(
					'min'   => 1000,
					'type'  => 'percent',
					'value' => 10,
				),
			),
			// Kombin.
			'components'       => array(),
			'bundle_type'      => 'percent',
			'bundle_value'     => 15,
			// Kargo bedava.
			'min_amount'       => 500,
			// Hedef ve koşullar.
			'include_products' => array(),
			'include_cats'     => array(),
			'exclude_products' => array(),
			'exclude_cats'     => array(),
			'exclude_sale'     => false,
			'min_subtotal'     => 0,
			'roles'            => array(),
		);
	}

	/** DB satırını çalışma biçimine çevirir. */
	public static function normalize( $row ) {
		if ( ! $row ) {
			return null;
		}
		$cfg = json_decode( (string) $row['config'], true );
		return array(
			'id'          => (int) $row['id'],
			'name'        => $row['name'],
			'type'        => $row['type'],
			'status'      => $row['status'],
			'priority'    => (int) $row['priority'],
			'stacking'    => 'exclusive' === $row['stacking'] ? 'exclusive' : 'stack',
			'starts_at'   => $row['starts_at'],
			'ends_at'     => $row['ends_at'],
			'usage_limit' => (int) $row['usage_limit'],
			'badge'       => $row['badge'],
			'config'      => wp_parse_args( is_array( $cfg ) ? $cfg : array(), self::default_config() ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return self::normalize( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore
	}

	public static function all( $status = '' ) {
		global $wpdb;
		$t    = self::table();
		$rows = $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY priority ASC, id ASC", $status ), ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( "SELECT * FROM {$t} ORDER BY priority ASC, id ASC", ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'normalize' ), (array) $rows );
	}

	public static function by_ids( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$c = self::get( (int) $id );
			if ( $c ) {
				$out[] = $c;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['priority'] === $b['priority'] ? $a['id'] - $b['id'] : $a['priority'] - $b['priority'];
			}
		);
		return $out;
	}

	/** Şu an canlı sepette geçerli kampanyalar (durum, tarih, kullanım limiti). */
	public static function live() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$now   = current_time( 'mysql', true );
		$cache = array();
		foreach ( self::all( 'active' ) as $c ) {
			if ( $c['starts_at'] && $c['starts_at'] > $now ) {
				continue;
			}
			if ( $c['ends_at'] && $c['ends_at'] < $now ) {
				continue;
			}
			if ( $c['usage_limit'] > 0 && self::usage_count( $c['id'] ) >= $c['usage_limit'] ) {
				continue;
			}
			$cache[] = $c;
		}
		return $cache;
	}

	public static function usage_count( $id ) {
		global $wpdb;
		$u = $wpdb->prefix . 'wdkm_usage';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$u} WHERE campaign_id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Sayıya Türkçe ek: iyelik (62'si, 40'ı) veya yönelme (10'a, 20'ye).
	 * Ondalıklı sayılarda son okunan rakama göre çekimlenir.
	 */
	public static function tr_num( $formatted, $case = 'poss' ) {
		$digits = preg_replace( '/[^0-9,\.]/', '', (string) $formatted );
		$parts  = preg_split( '/[,\.]/', $digits );
		$last   = end( $parts );
		if ( count( $parts ) > 1 && '' !== trim( $last, '0' ) ) {
			$n = (int) rtrim( $last, '0' );
		} else {
			$n = (int) str_replace( array( ',', '.' ), '', $parts[0] );
		}
		$ones = $n % 10;
		$tens = (int) floor( ( $n % 100 ) / 10 );
		if ( 'dat' === $case ) {
			$o = array( 1 => 'e', 2 => 'ye', 3 => 'e', 4 => 'e', 5 => 'e', 6 => 'ya', 7 => 'ye', 8 => 'e', 9 => 'a' );
			$t = array( 1 => 'a', 2 => 'ye', 3 => 'a', 4 => 'a', 5 => 'ye', 6 => 'a', 7 => 'e', 8 => 'e', 9 => 'a' );
			$z = ( 0 === $n ) ? 'a' : ( 0 === $n % 1000 ? 'e' : 'e' );
		} else {
			$o = array( 1 => 'i', 2 => 'si', 3 => 'ü', 4 => 'ü', 5 => 'i', 6 => 'sı', 7 => 'si', 8 => 'i', 9 => 'u' );
			$t = array( 1 => 'u', 2 => 'si', 3 => 'u', 4 => 'ı', 5 => 'si', 6 => 'ı', 7 => 'i', 8 => 'i', 9 => 'ı' );
			$z = ( 0 === $n ) ? 'ı' : ( 0 === $n % 1000 ? 'i' : 'ü' );
		}
		$suf = $ones ? $o[ $ones ] : ( $tens ? $t[ $tens ] : $z );
		return $formatted . "'" . $suf;
	}

	/** Otomatik rozet metni. */
	public static function label( array $c ) {
		if ( '' !== trim( (string) $c['badge'] ) ) {
			return $c['badge'];
		}
		$g   = $c['config'];
		$num = function ( $v ) {
			return wc_format_localized_decimal( (float) $v + 0 );
		};
		$money = function ( $v ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $v, array( 'decimals' => floor( (float) $v ) == (float) $v ? 0 : wc_get_price_decimals() ) ) ), ENT_QUOTES, 'UTF-8' );
		};
		switch ( $c['type'] ) {
			case 'bxgy':
				return sprintf( '%d Al %d Öde', (int) $g['buy'], (int) $g['pay'] );
			case 'nth':
				return 100 <= (float) $g['percent'] ? sprintf( '%d. Ürün Bedava', (int) $g['n'] ) : sprintf( '%d. Ürüne %%%s İndirim', (int) $g['n'], $num( $g['percent'] ) );
			case 'percent':
				return 'percent' === $g['value_type'] ? sprintf( 'Sepette %%%s İndirim', $num( $g['value'] ) ) : sprintf( 'Sepette %s İndirim', $money( $g['value'] ) );
			case 'tiered':
				$max = 0;
				$pct = true;
				foreach ( (array) $g['tiers'] as $t ) {
					$max = max( $max, (float) $t['value'] );
					$pct = $pct && 'percent' === $t['type'];
				}
				return $pct ? sprintf( 'Sepette %%%s Varan İndirim', self::tr_num( $num( $max ), 'dat' ) ) : 'Kademeli Sepet İndirimi';
			case 'bundle':
				return 'percent' === $g['bundle_type'] ? sprintf( 'Birlikte Al %%%s İndirim', $num( $g['bundle_value'] ) ) : sprintf( 'Birlikte Al %s İndirim', $money( $g['bundle_value'] ) );
			case 'free_shipping':
				return sprintf( '%s Üzeri Kargo Bedava', $money( $g['min_amount'] ) );
		}
		return $c['name'];
	}

	/* --------------------------------------------------------------------
	 * Yazma
	 * ------------------------------------------------------------------ */

	/** sanitize_text_field "%50" gibi ifadeleri URL kodu sanıp sildiği için kampanya metinlerinde kullanılmaz. */
	public static function clean_text( $v ) {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );
	}

	private static function ids( $v ) {
		if ( is_string( $v ) ) {
			$v = explode( ',', $v );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
	}

	private static function dec( $v, $min = 0, $max = 1000000000 ) {
		$v = (float) wc_format_decimal( is_scalar( $v ) ? $v : 0 );
		return max( $min, min( $max, $v ) );
	}

	/** Form verisini doğrular. @return array|WP_Error */
	public static function sanitize( array $in ) {
		$types = self::types();
		$type  = isset( $in['type'] ) && isset( $types[ $in['type'] ] ) ? $in['type'] : '';
		if ( ! $type ) {
			return new WP_Error( 'wdkm', 'Kampanya tipi seçin.' );
		}
		$name = isset( $in['name'] ) ? self::clean_text( $in['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'wdkm', 'Kampanya adı zorunlu.' );
		}
		$g = isset( $in['config'] ) && is_array( $in['config'] ) ? $in['config'] : array();
		$d = self::default_config();

		$cfg = array(
			'buy'              => max( 2, absint( $g['buy'] ?? $d['buy'] ) ),
			'pay'              => max( 1, absint( $g['pay'] ?? $d['pay'] ) ),
			'same_product'     => ! empty( $g['same_product'] ),
			'free_pick'        => ( $g['free_pick'] ?? '' ) === 'chunk' ? 'chunk' : 'cheapest',
			'n'                => max( 2, absint( $g['n'] ?? $d['n'] ) ),
			'percent'          => self::dec( $g['percent'] ?? $d['percent'], 0, 100 ),
			'repeat'           => ! empty( $g['repeat'] ),
			'max_times'        => absint( $g['max_times'] ?? 0 ),
			'value_type'       => ( $g['value_type'] ?? '' ) === 'fixed' ? 'fixed' : 'percent',
			'value'            => self::dec( $g['value'] ?? $d['value'] ),
			'tiers'            => array(),
			'components'       => self::ids( $g['components'] ?? array() ),
			'bundle_type'      => ( $g['bundle_type'] ?? '' ) === 'fixed' ? 'fixed' : 'percent',
			'bundle_value'     => self::dec( $g['bundle_value'] ?? $d['bundle_value'] ),
			'min_amount'       => self::dec( $g['min_amount'] ?? $d['min_amount'] ),
			'include_products' => self::ids( $g['include_products'] ?? array() ),
			'include_cats'     => self::ids( $g['include_cats'] ?? array() ),
			'exclude_products' => self::ids( $g['exclude_products'] ?? array() ),
			'exclude_cats'     => self::ids( $g['exclude_cats'] ?? array() ),
			'exclude_sale'     => ! empty( $g['exclude_sale'] ),
			'min_subtotal'     => self::dec( $g['min_subtotal'] ?? 0 ),
			'roles'            => array_values( array_filter( array_map( 'sanitize_key', (array) ( $g['roles'] ?? array() ) ) ) ),
		);
		if ( 'percent' === $cfg['value_type'] ) {
			$cfg['value'] = min( 100, $cfg['value'] );
		}
		if ( 'percent' === $cfg['bundle_type'] ) {
			$cfg['bundle_value'] = min( 100, $cfg['bundle_value'] );
		}
		foreach ( (array) ( $g['tiers'] ?? array() ) as $t ) {
			if ( ! isset( $t['min'] ) || '' === trim( (string) $t['min'] ) || ! isset( $t['value'] ) || '' === trim( (string) $t['value'] ) ) {
				continue;
			}
			$tt             = ( $t['type'] ?? '' ) === 'fixed' ? 'fixed' : 'percent';
			$cfg['tiers'][] = array(
				'min'   => self::dec( $t['min'] ),
				'type'  => $tt,
				'value' => 'percent' === $tt ? self::dec( $t['value'], 0, 100 ) : self::dec( $t['value'] ),
			);
		}
		usort(
			$cfg['tiers'],
			function ( $a, $b ) {
				return $a['min'] <=> $b['min'];
			}
		);

		// Tipe özel doğrulama.
		if ( 'bxgy' === $type && $cfg['pay'] >= $cfg['buy'] ) {
			return new WP_Error( 'wdkm', '"Öde" adedi "Al" adedinden küçük olmalı (ör. 3 al 2 öde).' );
		}
		if ( 'tiered' === $type && ! $cfg['tiers'] ) {
			return new WP_Error( 'wdkm', 'En az bir kademe girin.' );
		}
		if ( 'bundle' === $type && count( $cfg['components'] ) < 2 ) {
			return new WP_Error( 'wdkm', 'Kombin için en az iki ürün seçin.' );
		}

		$date = function ( $v ) {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				return null;
			}
			$ts = strtotime( get_gmt_from_date( str_replace( 'T', ' ', $v ) ) . ' UTC' );
			return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		};

		return array(
			'name'        => $name,
			'type'        => $type,
			'status'      => isset( $in['status'] ) && isset( self::statuses()[ $in['status'] ] ) ? $in['status'] : 'draft',
			'priority'    => isset( $in['priority'] ) ? (int) $in['priority'] : 10,
			'stacking'    => isset( $in['stacking'] ) && 'exclusive' === $in['stacking'] ? 'exclusive' : 'stack',
			'starts_at'   => $date( $in['starts_at'] ?? '' ),
			'ends_at'     => $date( $in['ends_at'] ?? '' ),
			'usage_limit' => absint( $in['usage_limit'] ?? 0 ),
			'badge'       => self::clean_text( $in['badge'] ?? '' ),
			'config'      => wp_json_encode( $cfg ),
		);
	}

	/** @return int|WP_Error */
	public static function save( array $in, $id = 0 ) {
		global $wpdb;
		$data = self::sanitize( $in );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		if ( $id ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
			return (int) $id;
		}
		$data['created_at'] = $data['updated_at'];
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	public static function duplicate( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return 0;
		}
		unset( $row['id'] );
		$row['name']       = $row['name'] . ' (kopya)';
		$row['status']     = 'draft';
		$row['created_at'] = current_time( 'mysql', true );
		$row['updated_at'] = $row['created_at'];
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}
}
