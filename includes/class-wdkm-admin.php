<?php
defined( 'ABSPATH' ) || exit;

/**
 * Yönetim paneli: kampanya listesi ve düzenleme, geriye dönük simülatör, performans raporu, ayarlar.
 */
class WDKM_Admin {

	const CAP = 'manage_woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 30 );
		foreach ( array( 'save', 'status', 'duplicate', 'delete', 'settings' ) as $a ) {
			add_action( 'admin_post_wdkm_' . $a, array( $this, 'handle_' . $a ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( WDKM_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
	}

	/* ====================================================================
	 * Altyapı
	 * ================================================================== */

	public function menu() {
		add_menu_page( 'WD Kampanya', 'Kampanyalar', self::CAP, 'wdkm', array( $this, 'page_list' ), 'dashicons-megaphone', 56 );
		add_submenu_page( 'wdkm', 'Kampanyalar', 'Tüm Kampanyalar', self::CAP, 'wdkm', array( $this, 'page_list' ) );
		add_submenu_page( 'wdkm', 'Yeni Kampanya', 'Yeni Ekle', self::CAP, 'wdkm-new', array( $this, 'page_new' ) );
		add_submenu_page( 'wdkm', 'Geriye Dönük Simülatör', 'Simülatör', self::CAP, 'wdkm-sim', array( $this, 'page_sim' ) );
		add_submenu_page( 'wdkm', 'Kampanya Performansı', 'Performans', self::CAP, 'wdkm-report', array( $this, 'page_report' ) );
		add_submenu_page( 'wdkm', 'Ayarlar', 'Ayarlar', self::CAP, 'wdkm-settings', array( $this, 'page_settings' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'wdkm' ) ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'wdkm-admin', WDKM_URL . 'assets/css/admin.css', array(), WDKM_VERSION );
		wp_enqueue_script( 'wdkm-admin', WDKM_URL . 'assets/js/admin.js', array( 'jquery', 'wc-enhanced-select' ), WDKM_VERSION, true );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'wdkm-settings' ) ) . '">Ayarlar</a>' );
		return $links;
	}

	public function row_meta( $links, $file ) {
		if ( plugin_basename( WDKM_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( WDKM_SUPPORT_URL ) . '" target="_blank" rel="noopener">Destek (oblifex.com)</a>';
			$links[] = '<a href="' . esc_url( WDKM_REPO_URL ) . '" target="_blank" rel="noopener">GitHub</a>';
		}
		return $links;
	}

	public function footer_text( $text ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'wdkm' ) ) {
			return $text;
		}
		return sprintf( 'WD Kampanya ücretsizdir. Soru, öneri ve destek için <a href="%s" target="_blank" rel="noopener">oblifex.com</a> webmaster forumuna katılın. · <a href="https://webdanismani.com" target="_blank" rel="noopener">Web Danışmanı</a>', esc_url( WDKM_SUPPORT_URL ) );
	}

	private static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function check() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Bu işlem için yetkiniz yok.', 403 );
		}
	}

	private static function redirect( $page, array $args = array() ) {
		wp_safe_redirect( self::url( $page, array_map( 'rawurlencode', $args ) ) );
		exit;
	}

	private static function action_url( $action, $id, array $extra = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'wdkm_' . $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ), 'wdkm_' . $action . '_' . $id );
	}

	private function header( $title, $actions = '' ) {
		$n = isset( $_GET['wdkm_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['wdkm_notice'] ) ) : ''; // phpcs:ignore
		$e = isset( $_GET['wdkm_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wdkm_error'] ) ) : ''; // phpcs:ignore
		echo '<div class="wrap wdkm-wrap"><div class="wdkm-top"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<span class="wdkm-brand">WD Kampanya</span></div><hr class="wp-header-end">'; // phpcs:ignore
		if ( $n ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $n ) . '</p></div>';
		}
		if ( $e ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $e ) . '</p></div>';
		}
	}

	private function footer() {
		echo '</div>';
	}

	private static function pct( $v, $dec = 1 ) {
		return ( $v < 0 ? '-' : '' ) . '%' . number_format_i18n( abs( $v ), $dec );
	}

	private static function local_dt( $gmt ) {
		return $gmt ? get_date_from_gmt( $gmt, 'Y-m-d\TH:i' ) : '';
	}

	private static function period_text( array $c ) {
		if ( ! $c['starts_at'] && ! $c['ends_at'] ) {
			return 'Süresiz';
		}
		$f = $c['starts_at'] ? get_date_from_gmt( $c['starts_at'], 'd.m.Y H:i' ) : '…';
		$t = $c['ends_at'] ? get_date_from_gmt( $c['ends_at'], 'd.m.Y H:i' ) : '…';
		return $f . ' – ' . $t;
	}

	private static function live_state( array $c ) {
		if ( 'active' !== $c['status'] ) {
			return array( $c['status'], WDKM_Campaigns::statuses()[ $c['status'] ] ?? $c['status'] );
		}
		$now = current_time( 'mysql', true );
		if ( $c['starts_at'] && $c['starts_at'] > $now ) {
			return array( 'scheduled', 'Başlamayı bekliyor' );
		}
		if ( $c['ends_at'] && $c['ends_at'] < $now ) {
			return array( 'ended', 'Süresi doldu' );
		}
		if ( $c['usage_limit'] > 0 && WDKM_Campaigns::usage_count( $c['id'] ) >= $c['usage_limit'] ) {
			return array( 'ended', 'Limit doldu' );
		}
		return array( 'live', 'Yayında' );
	}

	/* ====================================================================
	 * Kampanya listesi
	 * ================================================================== */

	public function page_list() {
		self::check();
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore
		if ( 'edit' === $action ) {
			$this->render_form( WDKM_Campaigns::get( absint( $_GET['id'] ?? 0 ) ) ); // phpcs:ignore
			return;
		}

		global $wpdb;
		$usage = array();
		foreach ( (array) $wpdb->get_results( "SELECT campaign_id, COUNT(*) AS n, SUM(discount) AS d FROM {$wpdb->prefix}wdkm_usage GROUP BY campaign_id", ARRAY_A ) as $r ) { // phpcs:ignore
			$usage[ (int) $r['campaign_id'] ] = $r;
		}
		$list  = WDKM_Campaigns::all();
		$types = WDKM_Campaigns::types();

		$this->header( 'Kampanyalar', '<a href="' . esc_url( self::url( 'wdkm-new' ) ) . '" class="page-title-action">Yeni kampanya</a>' );
		$mode = 'best' === WDKM_Settings::get( 'conflict_mode' ) ? 'Müşteriye en avantajlı seçenek uygulanır' : 'Öncelik sırasına göre uygulanır';
		printf( '<p class="description wdkm-lead">Çakışma kuralı: <strong>%s</strong>%s. <a href="%s">Değiştir</a></p>', esc_html( $mode ), WDKM_Settings::yes( 'item_once' ) ? ', bir ürün adedi yalnızca bir kampanyadan indirim alır' : '', esc_url( self::url( 'wdkm-settings' ) ) );

		if ( ! $list ) {
			echo '<div class="wdkm-empty"><h2>Henüz kampanya yok</h2><p>İlk kampanyanızı oluşturun ve yayına almadan önce geçmiş siparişlerinizle simüle edin.</p><p><a class="button button-primary button-hero" href="' . esc_url( self::url( 'wdkm-new' ) ) . '">Yeni kampanya</a></p></div>';
			$this->footer();
			return;
		}
		?>
		<table class="widefat striped wdkm-table">
			<thead><tr>
				<th class="num">Öncelik</th><th>Kampanya</th><th>Tip</th><th>Durum</th><th>Tarih</th><th>Birleşme</th><th class="num">Kullanım</th><th class="num">Verilen indirim</th><th></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $list as $c ) :
				list( $state, $state_label ) = self::live_state( $c );
				$u    = $usage[ $c['id'] ] ?? array( 'n' => 0, 'd' => 0 );
				$edit = self::url( 'wdkm', array( 'action' => 'edit', 'id' => $c['id'] ) );
				?>
				<tr>
					<td class="num"><?php echo (int) $c['priority']; ?></td>
					<td><a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $c['name'] ); ?></strong></a><br><span class="wdkm-chip"><?php echo esc_html( WDKM_Campaigns::label( $c ) ); ?></span></td>
					<td><?php echo esc_html( $types[ $c['type'] ][0] ?? $c['type'] ); ?></td>
					<td><span class="wdkm-state wdkm-state--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state_label ); ?></span></td>
					<td><span class="wdkm-dim"><?php echo esc_html( self::period_text( $c ) ); ?></span></td>
					<td><?php echo 'exclusive' === $c['stacking'] ? 'Tek başına' : 'Birleşebilir'; ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( (int) $u['n'] ) ); ?><?php echo $c['usage_limit'] ? ' / ' . esc_html( number_format_i18n( $c['usage_limit'] ) ) : ''; ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( (float) $u['d'] ) ); ?></td>
					<td class="wdkm-row-actions">
						<a href="<?php echo esc_url( self::url( 'wdkm-sim', array( 'c' => array( $c['id'] ), 'run' => 1 ) ) ); ?>" class="button button-small">Simüle et</a>
						<?php if ( 'active' === $c['status'] ) : ?>
							<a href="<?php echo esc_url( self::action_url( 'status', $c['id'], array( 'to' => 'paused' ) ) ); ?>" class="button button-small">Duraklat</a>
						<?php else : ?>
							<a href="<?php echo esc_url( self::action_url( 'status', $c['id'], array( 'to' => 'active' ) ) ); ?>" class="button button-small button-primary">Yayına al</a>
						<?php endif; ?>
						<a href="<?php echo esc_url( $edit ); ?>">Düzenle</a> ·
						<a href="<?php echo esc_url( self::action_url( 'duplicate', $c['id'] ) ); ?>">Kopyala</a> ·
						<a href="<?php echo esc_url( self::action_url( 'delete', $c['id'] ) ); ?>" class="wdkm-del" data-confirm="Kampanya silinecek. Emin misiniz?">Sil</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->footer();
	}

	public function page_new() {
		self::check();
		$this->render_form( null );
	}

	/* ====================================================================
	 * Kampanya formu
	 * ================================================================== */

	private static function product_options( array $ids ) {
		$html = '';
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$html .= '<option value="' . (int) $id . '" selected>' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		return $html;
	}

	private static function product_select( $name, array $ids, $placeholder = 'Ürün ara…' ) {
		return sprintf(
			'<select class="wc-product-search" multiple="multiple" style="width:100%%" name="%s[]" data-placeholder="%s" data-action="woocommerce_json_search_products_and_variations">%s</select>',
			esc_attr( $name ),
			esc_attr( $placeholder ),
			self::product_options( $ids )
		);
	}

	private static function cat_select( $name, array $ids ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$html  = '<select class="wc-enhanced-select" multiple="multiple" style="width:100%" name="' . esc_attr( $name ) . '[]" data-placeholder="Kategori seçin…">';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$depth = count( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) );
				$html .= '<option value="' . (int) $t->term_id . '" ' . selected( in_array( (int) $t->term_id, $ids, true ), true, false ) . '>' . esc_html( str_repeat( '— ', $depth ) . $t->name ) . '</option>';
			}
		}
		return $html . '</select>';
	}

	private function render_form( $c ) {
		$is_new = ! $c;
		if ( $is_new ) {
			$c = array(
				'id'          => 0,
				'name'        => '',
				'type'        => isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'bxgy', // phpcs:ignore
				'status'      => 'draft',
				'priority'    => 10,
				'stacking'    => 'stack',
				'starts_at'   => null,
				'ends_at'     => null,
				'usage_limit' => 0,
				'badge'       => '',
				'config'      => WDKM_Campaigns::default_config(),
			);
		}
		$g     = $c['config'];
		$types = WDKM_Campaigns::types();
		$num   = function ( $v ) {
			return esc_attr( wc_format_localized_decimal( (float) $v + 0 ) );
		};
		$roles = wp_roles()->get_names();

		$this->header( $is_new ? 'Yeni kampanya' : 'Kampanyayı düzenle', '<a href="' . esc_url( self::url( 'wdkm' ) ) . '" class="page-title-action">← Kampanyalar</a>' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdkm-form" data-type="<?php echo esc_attr( $c['type'] ); ?>">
			<input type="hidden" name="action" value="wdkm_save">
			<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
			<?php wp_nonce_field( 'wdkm_save' ); ?>

			<div class="wdkm-layout">
				<div class="wdkm-main">
					<div class="wdkm-panel">
						<label class="wdkm-name"><span>Kampanya adı</span>
							<input type="text" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="ör. Sonbahar 3 Al 2 Öde" required></label>
					</div>

					<div class="wdkm-panel">
						<h2>Kampanya tipi</h2>
						<div class="wdkm-types">
							<?php foreach ( $types as $k => $t ) : ?>
								<label class="wdkm-type">
									<input type="radio" name="type" value="<?php echo esc_attr( $k ); ?>" <?php checked( $c['type'], $k ); ?>>
									<span><strong><?php echo esc_html( $t[0] ); ?></strong><small><?php echo esc_html( $t[1] ); ?></small></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="wdkm-panel">
						<h2>Kural</h2>

						<div class="wdkm-if" data-types="bxgy">
							<p class="wdkm-sentence">
								<input type="number" min="2" name="config[buy]" value="<?php echo (int) $g['buy']; ?>" class="small-text"> al,
								<input type="number" min="1" name="config[pay]" value="<?php echo (int) $g['pay']; ?>" class="small-text"> öde.
							</p>
							<p><label><input type="radio" name="config[free_pick]" value="cheapest" <?php checked( $g['free_pick'], 'cheapest' ); ?>> Sepetteki <strong>en ucuz</strong> ürün(ler) bedava</label><br>
								<label><input type="radio" name="config[free_pick]" value="chunk" <?php checked( $g['free_pick'], 'chunk' ); ?>> Pahalıdan ucuza gruplanır, her grubun en ucuzu bedava <span class="wdkm-dim">(müşteri lehine)</span></label></p>
							<p><label><input type="checkbox" name="config[same_product]" value="1" <?php checked( $g['same_product'] ); ?>> Yalnızca aynı üründen alınınca geçerli</label></p>
						</div>

						<div class="wdkm-if" data-types="nth">
							<p class="wdkm-sentence">
								<input type="number" min="2" name="config[n]" value="<?php echo (int) $g['n']; ?>" class="small-text">. ürüne
								%<input type="text" inputmode="decimal" name="config[percent]" value="<?php echo $num( $g['percent'] ); // phpcs:ignore ?>" class="small-text"> indirim
								<span class="wdkm-dim">(100 = bedava)</span>
							</p>
							<p><label><input type="checkbox" name="config[repeat]" value="1" <?php checked( $g['repeat'] ); ?>> Tekrarlansın (4. ürün, 6. ürün…)</label></p>
							<p class="wdkm-dim">İndirim her gruptaki en ucuz ürüne uygulanır.</p>
						</div>

						<div class="wdkm-if" data-types="percent">
							<p class="wdkm-sentence">Seçili ürünlerde sepette
								<input type="text" inputmode="decimal" name="config[value]" value="<?php echo $num( $g['value'] ); // phpcs:ignore ?>" class="small-text">
								<select name="config[value_type]">
									<option value="percent" <?php selected( $g['value_type'], 'percent' ); ?>>% indirim</option>
									<option value="fixed" <?php selected( $g['value_type'], 'fixed' ); ?>><?php echo esc_html( get_woocommerce_currency_symbol() ); ?> indirim (adet başı)</option>
								</select>
							</p>
						</div>

						<div class="wdkm-if" data-types="tiered">
							<table class="wdkm-tiers widefat">
								<thead><tr><th>Sepet tutarı (en az)</th><th>İndirim</th><th></th></tr></thead>
								<tbody>
								<?php
								$tiers   = $g['tiers'];
								$tiers[] = array( 'min' => '', 'type' => 'percent', 'value' => '', '_tpl' => 1 );
								foreach ( $tiers as $i => $t ) :
									$tpl  = ! empty( $t['_tpl'] );
									$base = $tpl ? 'config[tiers][__i__]' : 'config[tiers][' . $i . ']';
									?>
									<tr class="<?php echo $tpl ? 'wdkm-tier-tpl' : 'wdkm-tier'; ?>"<?php echo $tpl ? ' hidden' : ''; ?>>
										<td><input type="text" inputmode="decimal" name="<?php echo esc_attr( $base ); ?>[min]" value="<?php echo '' === $t['min'] ? '' : $num( $t['min'] ); // phpcs:ignore ?>" class="regular-text" placeholder="500"<?php echo $tpl ? ' disabled' : ''; ?>> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?></td>
										<td><input type="text" inputmode="decimal" name="<?php echo esc_attr( $base ); ?>[value]" value="<?php echo '' === $t['value'] ? '' : $num( $t['value'] ); // phpcs:ignore ?>" class="small-text" placeholder="5"<?php echo $tpl ? ' disabled' : ''; ?>>
											<select name="<?php echo esc_attr( $base ); ?>[type]"<?php echo $tpl ? ' disabled' : ''; ?>>
												<option value="percent" <?php selected( $t['type'], 'percent' ); ?>>%</option>
												<option value="fixed" <?php selected( $t['type'], 'fixed' ); ?>><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></option>
											</select></td>
										<td><button type="button" class="button-link wdkm-tier-del">Sil</button></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<p><button type="button" class="button wdkm-tier-add">+ Kademe ekle</button> <span class="wdkm-dim">Ulaşılan en yüksek kademe uygulanır. Tutar, hedeflenen ürünlerin sepet toplamıdır.</span></p>
						</div>

						<div class="wdkm-if" data-types="bundle">
							<p><strong>Kombin ürünleri</strong> (hepsi sepette olunca indirim)</p>
							<?php echo self::product_select( 'config[components]', $g['components'], 'Kombine girecek ürünleri seçin…' ); // phpcs:ignore ?>
							<p class="wdkm-sentence">Kombine
								<input type="text" inputmode="decimal" name="config[bundle_value]" value="<?php echo $num( $g['bundle_value'] ); // phpcs:ignore ?>" class="small-text">
								<select name="config[bundle_type]">
									<option value="percent" <?php selected( $g['bundle_type'], 'percent' ); ?>>% indirim</option>
									<option value="fixed" <?php selected( $g['bundle_type'], 'fixed' ); ?>><?php echo esc_html( get_woocommerce_currency_symbol() ); ?> indirim (kombin başı)</option>
								</select>
							</p>
						</div>

						<div class="wdkm-if" data-types="free_shipping">
							<p class="wdkm-sentence">Hedeflenen ürünlerin sepet toplamı
								<input type="text" inputmode="decimal" name="config[min_amount]" value="<?php echo $num( $g['min_amount'] ); // phpcs:ignore ?>" class="small-text"> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
								ve üzerindeyse kargo bedava.</p>
						</div>

						<div class="wdkm-if" data-types="bxgy nth percent bundle">
							<p><label>En fazla uygulama sayısı <input type="number" min="0" name="config[max_times]" value="<?php echo (int) $g['max_times']; ?>" class="small-text"></label> <span class="wdkm-dim">0 = sınırsız (sepet başına)</span></p>
						</div>
					</div>

					<div class="wdkm-panel">
						<h2>Hedef ürünler</h2>
						<p class="wdkm-dim wdkm-if" data-types="bundle">Kombin tipinde hedef listesi yok sayılır; hariç tutmalar geçerlidir.</p>
						<div class="wdkm-grid2">
							<div><label>Ürünler</label><?php echo self::product_select( 'config[include_products]', $g['include_products'], 'Boş = tüm ürünler' ); // phpcs:ignore ?></div>
							<div><label>Kategoriler</label><?php echo self::cat_select( 'config[include_cats]', $g['include_cats'] ); // phpcs:ignore ?></div>
							<div><label>Hariç ürünler</label><?php echo self::product_select( 'config[exclude_products]', $g['exclude_products'], 'Hariç tutulacak ürünler' ); // phpcs:ignore ?></div>
							<div><label>Hariç kategoriler</label><?php echo self::cat_select( 'config[exclude_cats]', $g['exclude_cats'] ); // phpcs:ignore ?></div>
						</div>
						<p><label><input type="checkbox" name="config[exclude_sale]" value="1" <?php checked( $g['exclude_sale'] ); ?>> Zaten indirimde olan ürünleri hariç tut</label></p>
					</div>

					<div class="wdkm-panel">
						<h2>Koşullar</h2>
						<table class="form-table">
							<tr><th>En az sepet tutarı</th><td><input type="text" inputmode="decimal" name="config[min_subtotal]" value="<?php echo $num( $g['min_subtotal'] ); // phpcs:ignore ?>" class="small-text"> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?> <span class="wdkm-dim">0 = koşul yok</span></td></tr>
							<tr><th>Müşteri grubu</th><td class="wdkm-checks">
								<label><input type="checkbox" name="config[roles][]" value="guest" <?php checked( in_array( 'guest', $g['roles'], true ) ); ?>> Misafir (üye olmayan)</label>
								<?php foreach ( $roles as $k => $l ) : ?>
									<label><input type="checkbox" name="config[roles][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $g['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $l ) ); ?></label>
								<?php endforeach; ?>
								<p class="wdkm-dim">Hiçbiri seçilmezse herkes için geçerlidir.</p>
							</td></tr>
						</table>
					</div>
				</div>

				<aside class="wdkm-side">
					<div class="wdkm-panel">
						<h2>Yayın</h2>
						<p><label>Durum<select name="status" class="widefat">
							<?php foreach ( WDKM_Campaigns::statuses() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select></label></p>
						<p><label>Başlangıç<input type="datetime-local" name="starts_at" value="<?php echo esc_attr( self::local_dt( $c['starts_at'] ) ); ?>" class="widefat"></label></p>
						<p><label>Bitiş<input type="datetime-local" name="ends_at" value="<?php echo esc_attr( self::local_dt( $c['ends_at'] ) ); ?>" class="widefat"></label></p>
						<p><label>Kullanım limiti (sipariş)<input type="number" min="0" name="usage_limit" value="<?php echo (int) $c['usage_limit']; ?>" class="widefat"></label><span class="wdkm-dim">0 = sınırsız</span></p>
						<p class="wdkm-submit">
							<button class="button button-primary button-large" name="after" value="save">Kaydet</button>
							<button class="button button-large" name="after" value="simulate">Kaydet ve simüle et</button>
						</p>
					</div>
					<div class="wdkm-panel">
						<h2>Çakışma</h2>
						<p><label>Öncelik <input type="number" name="priority" value="<?php echo (int) $c['priority']; ?>" class="small-text"></label> <span class="wdkm-dim">Küçük sayı önce uygulanır.</span></p>
						<p><label><input type="radio" name="stacking" value="stack" <?php checked( $c['stacking'], 'stack' ); ?>> Diğer kampanyalarla birleşebilir</label><br>
							<label><input type="radio" name="stacking" value="exclusive" <?php checked( $c['stacking'], 'exclusive' ); ?>> Tek başına (uygulanırsa diğerleri uygulanmaz)</label></p>
					</div>
					<div class="wdkm-panel">
						<h2>Rozet</h2>
						<p><input type="text" name="badge" value="<?php echo esc_attr( $c['badge'] ); ?>" class="widefat" placeholder="<?php echo esc_attr( $is_new ? 'Otomatik (ör. 3 Al 2 Öde)' : WDKM_Campaigns::label( array_merge( $c, array( 'badge' => '' ) ) ) ); ?>"></p>
						<p class="wdkm-dim">Ürün sayfasında, listede ve sepette görünen kısa ad. Boş = otomatik.</p>
					</div>
				</aside>
			</div>
		</form>
		<?php
		$this->footer();
	}

	/* ====================================================================
	 * İşleyiciler
	 * ================================================================== */

	public function handle_save() {
		self::check();
		check_admin_referer( 'wdkm_save' );
		$id  = absint( $_POST['id'] ?? 0 );
		$in  = wp_unslash( $_POST ); // phpcs:ignore -- WDKM_Campaigns::sanitize() içinde temizlenir.
		$res = WDKM_Campaigns::save( $in, $id );
		if ( is_wp_error( $res ) ) {
			$page = $id ? 'wdkm' : 'wdkm-new';
			$args = $id ? array( 'action' => 'edit', 'id' => $id ) : array();
			self::redirect( $page, array_merge( $args, array( 'wdkm_error' => $res->get_error_message() ) ) );
		}
		if ( isset( $_POST['after'] ) && 'simulate' === $_POST['after'] ) {
			wp_safe_redirect( self::url( 'wdkm-sim', array( 'c' => array( $res ), 'run' => 1 ) ) );
			exit;
		}
		self::redirect( 'wdkm', array( 'action' => 'edit', 'id' => $res, 'wdkm_notice' => 'Kampanya kaydedildi.' ) );
	}

	public function handle_status() {
		self::check();
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'wdkm_status_' . $id );
		$to = sanitize_key( $_GET['to'] ?? '' );
		WDKM_Campaigns::set_status( $id, $to );
		self::redirect( 'wdkm', array( 'wdkm_notice' => 'active' === $to ? 'Kampanya yayına alındı.' : 'Kampanya duraklatıldı.' ) );
	}

	public function handle_duplicate() {
		self::check();
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'wdkm_duplicate_' . $id );
		$new = WDKM_Campaigns::duplicate( $id );
		self::redirect( 'wdkm', array( 'action' => 'edit', 'id' => $new, 'wdkm_notice' => 'Kampanya kopyalandı (taslak).' ) );
	}

	public function handle_delete() {
		self::check();
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'wdkm_delete_' . $id );
		WDKM_Campaigns::delete( $id );
		self::redirect( 'wdkm', array( 'wdkm_notice' => 'Kampanya silindi.' ) );
	}

	/* ====================================================================
	 * Geriye dönük simülatör
	 * ================================================================== */

	public function page_sim() {
		self::check();
		$all    = WDKM_Campaigns::all();
		$sel    = isset( $_GET['c'] ) ? array_map( 'absint', (array) $_GET['c'] ) : array(); // phpcs:ignore
		$today  = wp_date( 'Y-m-d' );
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-d', strtotime( '-30 days' ) ); // phpcs:ignore
		$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $today; // phpcs:ignore
		$margin = isset( $_GET['margin'] ) ? (float) wc_format_decimal( wp_unslash( $_GET['margin'] ) ) : 30; // phpcs:ignore
		$stats  = isset( $_GET['st'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['st'] ) ) : array( 'wc-completed', 'wc-processing' ); // phpcs:ignore
		$resp   = ! empty( $_GET['respect'] ); // phpcs:ignore
		$run    = ! empty( $_GET['run'] ) && $sel; // phpcs:ignore

		$this->header( 'Geriye Dönük Simülatör' );
		echo '<p class="description wdkm-lead">Seçtiğiniz kampanyalar geçmiş bir dönemde aktif olsaydı ne olurdu? O dönemin gerçek siparişleri, canlı sepetle <strong>aynı kampanya motorundan</strong> geçirilir.</p>';
		?>
		<form method="get" class="wdkm-panel wdkm-sim-form">
			<input type="hidden" name="page" value="wdkm-sim">
			<input type="hidden" name="run" value="1">
			<div class="wdkm-sim-grid">
				<div>
					<h3>Kampanyalar</h3>
					<?php if ( ! $all ) : ?>
						<p>Önce bir kampanya oluşturun. <a href="<?php echo esc_url( self::url( 'wdkm-new' ) ); ?>">Yeni kampanya</a></p>
					<?php endif; ?>
					<?php foreach ( $all as $c ) : ?>
						<label class="wdkm-sim-c"><input type="checkbox" name="c[]" value="<?php echo (int) $c['id']; ?>" <?php checked( in_array( $c['id'], $sel, true ) ); ?>>
							<?php echo esc_html( $c['name'] ); ?> <span class="wdkm-chip"><?php echo esc_html( WDKM_Campaigns::label( $c ) ); ?></span>
							<span class="wdkm-dim"><?php echo esc_html( WDKM_Campaigns::statuses()[ $c['status'] ] ); ?></span></label>
					<?php endforeach; ?>
					<p class="wdkm-dim">Birden fazla seçerseniz çakışma kurallarıyla birlikte simüle edilir.</p>
				</div>
				<div>
					<h3>Dönem</h3>
					<p class="wdkm-presets">
						<?php foreach ( array( 7 => 'Son 7 gün', 30 => 'Son 30 gün', 90 => 'Son 90 gün', 365 => 'Son 1 yıl' ) as $d => $l ) : ?>
							<button type="button" class="button button-small" data-days="<?php echo (int) $d; ?>"><?php echo esc_html( $l ); ?></button>
						<?php endforeach; ?>
					</p>
					<p><input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"> – <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></p>
					<h3>Brüt kâr marjı</h3>
					<p>%<input type="text" inputmode="decimal" name="margin" value="<?php echo esc_attr( wc_format_localized_decimal( $margin ) ); ?>" class="small-text"> <span class="wdkm-dim">Kâr etkisi ve amorti hesabı için ortalama marjınız.</span></p>
				</div>
				<div>
					<h3>Siparişler</h3>
					<?php foreach ( wc_get_order_statuses() as $k => $l ) : ?>
						<?php
						if ( in_array( $k, array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ), true ) ) {
							continue;
						}
						?>
						<label class="wdkm-sim-c"><input type="checkbox" name="st[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $stats, true ) ); ?>> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?>
					<p><label><input type="checkbox" name="respect" value="1" <?php checked( $resp ); ?>> Kampanyanın kendi tarih aralığına uy</label></p>
				</div>
			</div>
			<p><button class="button button-primary button-large">Simüle et</button></p>
		</form>
		<?php
		if ( $run ) {
			$from_ts = strtotime( get_gmt_from_date( $from . ' 00:00:00' ) . ' UTC' );
			$to_ts   = strtotime( get_gmt_from_date( $to . ' 23:59:59' ) . ' UTC' );
			if ( ! $from_ts || ! $to_ts || $from_ts > $to_ts ) {
				echo '<div class="notice notice-error"><p>Geçerli bir tarih aralığı seçin.</p></div>';
			} else {
				$camps = WDKM_Campaigns::by_ids( $sel );
				$r     = WDKM_Backtest::run(
					$camps,
					$from_ts,
					$to_ts,
					array(
						'margin'        => $margin,
						'statuses'      => $stats,
						'respect_dates' => $resp,
					)
				);
				$this->render_results( $r, $camps, $from, $to );
			}
		}
		$this->footer();
	}

	private function render_results( array $r, array $camps, $from, $to ) {
		if ( ! $r['orders'] ) {
			echo '<div class="wdkm-empty"><p>Bu dönemde seçilen durumda sipariş bulunamadı.</p></div>';
			return;
		}
		$rev      = $r['revenue'];
		$disc_pct = $rev > 0 ? $r['discount'] / $rev * 100 : 0;
		$aff_pct  = $r['affected'] / $r['orders'] * 100;
		$pchange  = $r['profit_now'] > 0 ? ( $r['profit_sim'] - $r['profit_now'] ) / $r['profit_now'] * 100 : 0;
		$names    = implode( ' + ', wp_list_pluck( $camps, 'name' ) );
		?>
		<div class="wdkm-result">
			<h2 class="wdkm-result__title"><?php echo esc_html( $names ); ?> <span class="wdkm-dim"><?php echo esc_html( wp_date( 'd.m.Y', strtotime( $from ) ) . ' – ' . wp_date( 'd.m.Y', strtotime( $to ) ) ); ?> · <?php echo esc_html( number_format_i18n( $r['orders'] ) ); ?> sipariş · <?php echo esc_html( number_format_i18n( $r['seconds'], 2 ) ); ?> sn</span></h2>
			<?php if ( $r['truncated'] ) : ?>
				<div class="notice notice-warning inline"><p>Sipariş sayısı üst sınıra ulaştı; en yeni <?php echo esc_html( number_format_i18n( $r['orders'] ) ); ?> sipariş kullanıldı. Ayarlar'dan sınırı değiştirebilirsiniz.</p></div>
			<?php endif; ?>

			<div class="wdkm-verdict <?php echo $r['discount'] <= 0 ? 'is-none' : ''; ?>">
				<?php if ( $r['discount'] <= 0 ) : ?>
					<strong>Bu kampanya o dönemde hiçbir siparişe uygulanmazdı.</strong> Hedef ürünleri, eşikleri veya koşulları gözden geçirin.
				<?php else : ?>
					Bu kampanya aktif olsaydı <strong><?php echo esc_html( number_format_i18n( $r['affected'] ) ); ?> siparişe (<?php echo esc_html( self::pct( $aff_pct ) ); ?>)</strong> toplam
					<strong><?php echo wp_kses_post( wc_price( $r['discount'] ) ); ?></strong> indirim verilirdi. Bu, dönemin ürün cirosunun <strong><?php echo esc_html( WDKM_Campaigns::tr_num( self::pct( $disc_pct ) ) ); ?></strong>.
					Kendini amorti etmesi için yaklaşık <strong><?php echo wp_kses_post( wc_price( $r['breakeven'] ) ); ?> ek ciro</strong> (≈ <?php echo esc_html( number_format_i18n( ceil( $r['breakeven_orders'] ) ) ); ?> ek sipariş) getirmesi gerekir.
				<?php endif; ?>
			</div>

			<div class="wdkm-cards">
				<div class="wdkm-card"><span>Etkilenen sipariş</span><strong><?php echo esc_html( number_format_i18n( $r['affected'] ) ); ?></strong><em><?php echo esc_html( self::pct( $aff_pct ) ); ?> · <?php echo esc_html( number_format_i18n( $r['orders'] ) ); ?> siparişten</em></div>
				<div class="wdkm-card wdkm-card--accent"><span>Toplam indirim</span><strong><?php echo wp_kses_post( wc_price( $r['discount'] ) ); ?></strong><em>Cironun <?php echo esc_html( WDKM_Campaigns::tr_num( self::pct( $disc_pct ) ) ); ?><?php echo $r['ship_discount'] > 0 ? ' · kargo ' . wp_strip_all_tags( wc_price( $r['ship_discount'] ) ) : ''; ?></em></div>
				<div class="wdkm-card"><span>Brüt kâr (%<?php echo esc_html( wc_format_localized_decimal( $r['margin'] ) ); ?> marj)</span><strong><?php echo wp_kses_post( wc_price( $r['profit_sim'] ) ); ?></strong><em><?php echo wp_kses_post( wc_price( $r['profit_now'] ) ); ?> yerine · <span class="wdkm-neg"><?php echo esc_html( self::pct( $pchange ) ); ?></span></em></div>
				<div class="wdkm-card"><span>Amorti için ek ciro</span><strong><?php echo wp_kses_post( wc_price( $r['breakeven'] ) ); ?></strong><em>≈ <?php echo esc_html( number_format_i18n( ceil( $r['breakeven_orders'] ) ) ); ?> sipariş (ort. sepet <?php echo wp_kses_post( wc_price( $r['aov'] ) ); ?>)</em></div>
			</div>

			<?php if ( $r['near_orders'] ) : ?>
				<div class="wdkm-panel wdkm-near">
					<h3>Kıl payı kaçıranlar</h3>
					<p><strong><?php echo esc_html( number_format_i18n( $r['near_orders'] ) ); ?> sipariş</strong> kampanyaya çok yaklaştı ama yararlanamadı.
					<?php if ( $r['near_amount_gap'] ) : ?>
						Tutar eşiğine ortanca <strong><?php echo wp_kses_post( wc_price( $r['near_gap_median'] ) ); ?></strong> kalmıştı.
					<?php endif; ?>
					<?php if ( $r['near_items'] ) : ?>
						<?php echo esc_html( number_format_i18n( $r['near_items'] ) ); ?> siparişe 1–2 ürün eksikti.
					<?php endif; ?>
					Sepet ipuçları ("₺X daha ekleyin…") açıkken bu müşterilerin bir kısmı sepetini büyütür; simülasyon bu ek satışı hesaba <em>katmaz</em>.</p>
				</div>
			<?php endif; ?>

			<div class="wdkm-grid2">
				<div class="wdkm-panel">
					<h3>Kampanya bazında</h3>
					<table class="widefat striped wdkm-table">
						<thead><tr><th>Kampanya</th><th class="num">Sipariş</th><th class="num">İndirim</th><th class="num">Sipariş başı</th></tr></thead>
						<tbody>
						<?php foreach ( $r['per_campaign'] as $pc ) : ?>
							<tr>
								<td><?php echo esc_html( $pc['name'] ); ?><br><span class="wdkm-chip"><?php echo esc_html( $pc['label'] ); ?></span></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $pc['orders'] ) ); ?><?php echo $pc['free_sh'] ? '<br><span class="wdkm-dim">' . (int) $pc['free_sh'] . ' kargo bedava</span>' : ''; ?></td>
								<td class="num"><?php echo wp_kses_post( wc_price( $pc['discount'] ) ); ?></td>
								<td class="num"><?php echo $pc['orders'] ? wp_kses_post( wc_price( $pc['discount'] / $pc['orders'] ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $camps ) > 1 ) : ?>
						<p class="wdkm-dim">Çakışma kuralı ve "bir ürün yalnızca bir kampanyadan indirim alır" ayarı uygulanmıştır.</p>
					<?php endif; ?>
				</div>
				<div class="wdkm-panel">
					<h3>İndirimin sepete oranı</h3>
					<?php
					$labels = array( '%5\'ten az', '%5–10', '%10–20', '%20–35', '%35 ve üzeri' );
					$max    = max( 1, max( $r['buckets'] ) );
					echo '<div class="wdkm-bars">';
					foreach ( $r['buckets'] as $i => $n ) {
						printf( '<div class="wdkm-bar"><span class="wdkm-bar__l">%s</span><span class="wdkm-bar__t"><i style="width:%s%%"></i></span><span class="wdkm-bar__v">%s sipariş</span></div>', esc_html( $labels[ $i ] ), esc_attr( round( 100 * $n / $max, 1 ) ), esc_html( number_format_i18n( $n ) ) );
					}
					echo '</div>';
					?>
					<p class="wdkm-dim">Sağdaki gruplar kalabalıksa kampanya bazı sepetlerde çok cömert olabilir; "en fazla uygulama sayısı" ile sınırlayın.</p>
				</div>
			</div>

			<?php if ( $r['days'] ) : ?>
				<div class="wdkm-panel">
					<h3>Günlük indirim</h3>
					<div class="wdkm-days">
						<?php
						$dmax = max( $r['days'] );
						foreach ( $r['days'] as $d => $v ) {
							printf( '<span class="wdkm-day" title="%s: %s"><i style="height:%s%%"></i></span>', esc_attr( wp_date( 'd.m.Y', strtotime( $d ) ) ), esc_attr( wp_strip_all_tags( wc_price( $v ) ) ), esc_attr( max( 2, round( 100 * $v / $dmax, 1 ) ) ) );
						}
						?>
					</div>
					<div class="wdkm-days__axis"><span><?php echo esc_html( wp_date( 'd.m', strtotime( array_key_first( $r['days'] ) ) ) ); ?></span><span><?php echo esc_html( wp_date( 'd.m', strtotime( array_key_last( $r['days'] ) ) ) ); ?></span></div>
				</div>
			<?php endif; ?>

			<?php if ( $r['products'] ) : ?>
				<div class="wdkm-panel">
					<h3>En çok indirim alacak ürünler</h3>
					<table class="widefat striped wdkm-table">
						<thead><tr><th>Ürün</th><th class="num">Toplam indirim</th><th class="num">Payı</th></tr></thead>
						<tbody>
						<?php
						foreach ( $r['products'] as $pid => $v ) :
							$p = wc_get_product( $pid );
							?>
							<tr><td><?php echo $p ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( $p->get_name() ) . '</a>' : '#' . (int) $pid; ?></td>
								<td class="num"><?php echo wp_kses_post( wc_price( $v ) ); ?></td>
								<td class="num"><?php echo esc_html( self::pct( $r['item_discount'] > 0 ? $v / $r['item_discount'] * 100 : 0 ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p class="wdkm-dim wdkm-assume">Varsayımlar: müşteriler aynı sepetleri oluştururdu; geçmiş siparişlerdeki kupon indirimleri ve o tarihteki "indirimde" durumu dikkate alınmaz; fiyatlar sipariş anındaki satır tutarlarıdır.</p>
		</div>
		<?php
	}

	/* ====================================================================
	 * Performans raporu (gerçekleşen)
	 * ================================================================== */

	public function page_report() {
		self::check();
		global $wpdb;
		$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore
		$days  = in_array( $days, array( 7, 30, 90, 365, 0 ), true ) ? $days : 30;
		$since = $days ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';
		$hpos  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$bad   = "'wc-failed','wc-cancelled','wc-pending','wc-checkout-draft','trash'";
		$u     = $wpdb->prefix . 'wdkm_usage';

		if ( $hpos ) {
			$ot     = "{$wpdb->prefix}wc_orders";
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT u.campaign_id, COUNT(*) n, SUM(u.discount) d, SUM(o.total_amount) rev FROM {$u} u INNER JOIN {$ot} o ON o.id = u.order_id WHERE o.status NOT IN ({$bad}) AND u.created_at >= %s GROUP BY u.campaign_id", $since ), ARRAY_A ); // phpcs:ignore
			$all    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, SUM(total_amount) rev FROM {$ot} WHERE type = 'shop_order' AND status NOT IN ({$bad}) AND date_created_gmt >= %s", $since ), ARRAY_A ); // phpcs:ignore
			$withc  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT o.id) n, SUM(o.total_amount) rev FROM {$ot} o WHERE o.status NOT IN ({$bad}) AND o.date_created_gmt >= %s AND o.id IN (SELECT order_id FROM {$u})", $since ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT u.campaign_id, COUNT(*) n, SUM(u.discount) d, SUM(t.meta_value) rev FROM {$u} u INNER JOIN {$wpdb->posts} p ON p.ID = u.order_id LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_order_total' WHERE p.post_status NOT IN ({$bad}) AND u.created_at >= %s GROUP BY u.campaign_id", $since ), ARRAY_A ); // phpcs:ignore
			$all    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, SUM(t.meta_value) rev FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_order_total' WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ({$bad}) AND p.post_date_gmt >= %s", $since ), ARRAY_A ); // phpcs:ignore
			$withc  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) n, SUM(t.meta_value) rev FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_order_total' WHERE p.post_status NOT IN ({$bad}) AND p.post_date_gmt >= %s AND p.ID IN (SELECT order_id FROM {$u})", $since ), ARRAY_A ); // phpcs:ignore
		}

		$this->header( 'Kampanya Performansı' );
		echo '<div class="wdkm-periods">';
		foreach ( array( 7 => '7 gün', 30 => '30 gün', 90 => '90 gün', 365 => '1 yıl', 0 => 'Tümü' ) as $d => $l ) {
			printf( '<a class="%s" href="%s">%s</a>', $d === $days ? 'is-active' : '', esc_url( self::url( 'wdkm-report', array( 'days' => $d ) ) ), esc_html( $l ) );
		}
		echo '</div>';

		$n_all   = (int) ( $all['n'] ?? 0 );
		$aov_all = $n_all ? (float) $all['rev'] / $n_all : 0;
		$n_c     = (int) ( $withc['n'] ?? 0 );
		$aov_c   = $n_c ? (float) $withc['rev'] / $n_c : 0;
		$n_o     = $n_all - $n_c;
		$aov_o   = $n_o > 0 ? ( (float) $all['rev'] - (float) $withc['rev'] ) / $n_o : 0;
		$tot_d   = array_sum( array_map( 'floatval', wp_list_pluck( (array) $rows, 'd' ) ) );
		?>
		<div class="wdkm-cards">
			<div class="wdkm-card"><span>Kampanyalı sipariş</span><strong><?php echo esc_html( number_format_i18n( $n_c ) ); ?></strong><em><?php echo esc_html( number_format_i18n( $n_all ) ); ?> siparişten · <?php echo esc_html( self::pct( $n_all ? $n_c / $n_all * 100 : 0 ) ); ?></em></div>
			<div class="wdkm-card wdkm-card--accent"><span>Verilen indirim</span><strong><?php echo wp_kses_post( wc_price( $tot_d ) ); ?></strong><em>Kampanyalı ciro <?php echo wp_kses_post( wc_price( (float) ( $withc['rev'] ?? 0 ) ) ); ?></em></div>
			<div class="wdkm-card"><span>Ort. sepet – kampanyalı</span><strong><?php echo wp_kses_post( wc_price( $aov_c ) ); ?></strong><em>Kampanyasız: <?php echo wp_kses_post( wc_price( $aov_o ) ); ?></em></div>
			<div class="wdkm-card"><span>Sepet farkı</span><strong><?php echo esc_html( $aov_o > 0 ? ( $aov_c >= $aov_o ? '+' : '' ) . self::pct( ( $aov_c - $aov_o ) / $aov_o * 100 ) : '—' ); ?></strong><em>Kampanyalı siparişler daha mı büyük?</em></div>
		</div>
		<table class="widefat striped wdkm-table">
			<thead><tr><th>Kampanya</th><th class="num">Sipariş</th><th class="num">İndirim</th><th class="num">Sipariş cirosu</th><th class="num">İndirim / ciro</th><th class="num">Ort. sepet</th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6">Bu dönemde kampanyalı sipariş yok.</td></tr>
			<?php endif; ?>
			<?php
			foreach ( (array) $rows as $row ) :
				$c = WDKM_Campaigns::get( $row['campaign_id'] );
				?>
				<tr>
					<td><?php echo $c ? esc_html( $c['name'] ) . '<br><span class="wdkm-chip">' . esc_html( WDKM_Campaigns::label( $c ) ) . '</span>' : '#' . (int) $row['campaign_id'] . ' (silinmiş)'; ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['n'] ) ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $row['d'] ) ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $row['rev'] ) ); ?></td>
					<td class="num"><?php echo esc_html( self::pct( (float) $row['rev'] > 0 ? (float) $row['d'] / (float) $row['rev'] * 100 : 0 ) ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( (int) $row['n'] ? (float) $row['rev'] / (int) $row['n'] : 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="wdkm-dim">İptal edilen ve başarısız siparişler dahil edilmez. Simülatördeki tahminle gerçekleşeni karşılaştırarak kampanyanın ek satış getirip getirmediğini görebilirsiniz.</p>
		<?php
		$this->footer();
	}

	/* ====================================================================
	 * Ayarlar
	 * ================================================================== */

	public function page_settings() {
		self::check();
		$s = WDKM_Settings::all();
		$this->header( 'Ayarlar' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wdkm_settings">
			<?php wp_nonce_field( 'wdkm_settings' ); ?>
			<div class="wdkm-panel">
				<h2>Çakışma kuralları</h2>
				<table class="form-table">
					<tr><th>Birden fazla kampanya uygunsa</th><td>
						<label><input type="radio" name="s[conflict_mode]" value="priority" <?php checked( $s['conflict_mode'], 'priority' ); ?>> <strong>Öncelik sırasına göre</strong>: birleşebilen kampanyalar sırayla uygulanır, "tek başına" kampanya uygulanırsa diğerleri durur</label><br>
						<label><input type="radio" name="s[conflict_mode]" value="best" <?php checked( $s['conflict_mode'], 'best' ); ?>> <strong>Müşteriye en avantajlısı</strong>: birleşebilenlerin toplamı ile her "tek başına" kampanya karşılaştırılır, en yüksek indirim uygulanır</label>
					</td></tr>
					<tr><th>Ürün başına tek indirim</th><td><label><input type="checkbox" name="s[item_once]" value="yes" <?php checked( $s['item_once'], 'yes' ); ?>> Bir ürün adedi yalnızca bir kampanyadan indirim alsın</label>
						<p class="description">Örn. 3 al 2 öde'de bedava çıkan ürüne ayrıca %10 sepet indirimi uygulanmaz.</p></td></tr>
					<tr><th>Kuponlarla birlikte</th><td>
						<label><input type="radio" name="s[coupon_policy]" value="allow" <?php checked( $s['coupon_policy'], 'allow' ); ?>> Kampanyalar kuponlarla birlikte geçerli</label><br>
						<label><input type="radio" name="s[coupon_policy]" value="block" <?php checked( $s['coupon_policy'], 'block' ); ?>> Kupon kullanılırsa kampanyalar uygulanmaz</label>
					</td></tr>
				</table>
			</div>
			<div class="wdkm-panel">
				<h2>Görünüm</h2>
				<table class="form-table">
					<tr><th>İndirim satırı öneki</th><td><input type="text" name="s[fee_prefix]" value="<?php echo esc_attr( $s['fee_prefix'] ); ?>" class="regular-text"><p class="description">Sepette "Kampanya: 3 Al 2 Öde −₺199" gibi görünür.</p></td></tr>
					<tr><th>Rozetler</th><td>
						<label><input type="checkbox" name="s[show_badges]" value="yes" <?php checked( $s['show_badges'], 'yes' ); ?>> Ürün sayfasında kampanya rozetleri</label><br>
						<label><input type="checkbox" name="s[show_loop_badges]" value="yes" <?php checked( $s['show_loop_badges'], 'yes' ); ?>> Mağaza/kategori listesinde rozetler</label></td></tr>
					<tr><th>Sepet</th><td>
						<label><input type="checkbox" name="s[show_hints]" value="yes" <?php checked( $s['show_hints'], 'yes' ); ?>> İpuçları: "1 ürün daha ekleyin, 3 Al 2 Öde'den yararlanın", "₺120 daha ekleyin, kargo bedava"</label><br>
						<label><input type="checkbox" name="s[show_savings]" value="yes" <?php checked( $s['show_savings'], 'yes' ); ?>> "Kampanya kazancınız" satırı</label></td></tr>
				</table>
			</div>
			<div class="wdkm-panel">
				<h2>Simülatör ve kaldırma</h2>
				<table class="form-table">
					<tr><th>En fazla sipariş</th><td><input type="number" min="100" max="200000" name="s[backtest_max_orders]" value="<?php echo (int) $s['backtest_max_orders']; ?>" class="small-text"><p class="description">Simülasyonda işlenecek en yeni sipariş sayısı. Paylaşımlı sunucularda düşük tutun.</p></td></tr>
					<tr><th>Kaldırma</th><td><label><input type="checkbox" name="s[delete_on_uninstall]" value="yes" <?php checked( $s['delete_on_uninstall'], 'yes' ); ?>> Eklenti silinirken kampanyaları ve kullanım kayıtlarını sil</label></td></tr>
				</table>
			</div>
			<?php submit_button( 'Ayarları kaydet' ); ?>
		</form>
		<?php
		$this->footer();
	}

	public function handle_settings() {
		self::check();
		check_admin_referer( 'wdkm_settings' );
		$in  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore
		$d   = WDKM_Settings::defaults();
		$out = array(
			'conflict_mode'       => ( $in['conflict_mode'] ?? '' ) === 'best' ? 'best' : 'priority',
			'coupon_policy'       => ( $in['coupon_policy'] ?? '' ) === 'block' ? 'block' : 'allow',
			'fee_prefix'          => isset( $in['fee_prefix'] ) ? WDKM_Campaigns::clean_text( $in['fee_prefix'] ) : $d['fee_prefix'],
			'backtest_max_orders' => max( 100, min( 200000, absint( $in['backtest_max_orders'] ?? $d['backtest_max_orders'] ) ) ),
		);
		foreach ( array( 'item_once', 'show_badges', 'show_loop_badges', 'show_hints', 'show_savings', 'delete_on_uninstall' ) as $k ) {
			$out[ $k ] = ( $in[ $k ] ?? '' ) === 'yes' ? 'yes' : 'no';
		}
		WDKM_Settings::save( $out );
		self::redirect( 'wdkm-settings', array( 'wdkm_notice' => 'Ayarlar kaydedildi.' ) );
	}
}
