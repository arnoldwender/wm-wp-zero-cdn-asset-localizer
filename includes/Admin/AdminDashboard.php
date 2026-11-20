<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;

/**
 * Admin screen and dashboard widget.
 *
 * Every figure on these screens is measured. Until 2026-09-14 the page (in Spanish) showed
 * "Pre-carga: Activa" and "Protección Legal RGPD: 100% Seguro" as fixed strings, the widget "0 IP-Leaks"
 * and "WOFF2 Kompression", and the scanner reported "100 % alojados en tu servidor" because it only
 * looked at the styles registered in the admin request — never the theme's frontend.
 */
final class AdminDashboard {
	public const OPTION_DELETE_ON_UNINSTALL = 'wm_zero_cdn_delete_data_on_uninstall';

	private static ?AdminDashboard $instance = null;

	public static function instance(): AdminDashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	public function register_settings(): void {
		register_setting(
			'wm_zero_cdn_settings',
			'wm_zero_cdn_preload_enabled',
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ self::class, 'sanitize_checkbox' ],
				'default'           => 0,
			]
		);

		register_setting(
			'wm_zero_cdn_settings',
			'wm_zero_cdn_preload_fonts',
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_preload_fonts' ],
				'default'           => [],
			]
		);

		register_setting(
			'wm_zero_cdn_settings',
			'wm_zero_cdn_delete_data_on_uninstall',
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ self::class, 'sanitize_checkbox' ],
				'default'           => 0,
			]
		);
	}

	/**
	 * Sanitize a boolean checkbox option to 0 or 1.
	 *
	 * @param mixed $value Raw submitted value.
	 */
	public static function sanitize_checkbox( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Sanitize preload font list (newline-separated textarea or array) into distinct `.woff2` basenames.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return list<string>
	 */
	public static function sanitize_preload_fonts( $value ): array {
		if ( is_string( $value ) ) {
			$items = preg_split( '/[\r\n,]+/', $value ) ?: [];
		} elseif ( is_array( $value ) ) {
			$items = $value;
		} else {
			return [];
		}

		$clean = [];
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) && ! is_numeric( $item ) ) {
				continue;
			}
			$raw  = trim( (string) $item );
			$base = basename( $raw );
			$safe = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $base ) : (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $base );
			if ( '' !== $safe && str_ends_with( strtolower( $safe ), '.woff2' ) ) {
				$clean[ $safe ] = true;
			}
		}

		return array_keys( $clean );
	}

	/** Number of WOFF2 files in uploads/wm-zero-cdn/. */
	public static function local_font_count(): int {
		$upload_dir = wp_upload_dir();
		$files      = glob( $upload_dir['basedir'] . '/wm-zero-cdn/*.woff2' );
		return is_array( $files ) ? count( $files ) : 0;
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_zero_cdn_widget',
			__( 'WM Zero-CDN — externe Schriften und Bibliotheken', 'wm-zero-cdn-asset-localizer' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		$scan  = ( new CdnDetector() )->scan_front_page( true );
		$fonts = self::local_font_count();
		$refs  = count( $scan['found'] );
		?>
		<div class="wm-dash-widget-box" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? wm_get_suite_theme() : 'cyber-events' ); ?>">
			<div class="wm-dash-banner <?php echo ( $scan['ok'] && 0 === $refs ) ? 'is-valid' : ''; ?>">
				<span class="wm-dash-banner-icon"><span class="dashicons dashicons-cloud" aria-hidden="true"></span></span>
				<div>
					<div class="wm-dash-banner-title">
						<?php
						if ( ! $scan['ok'] ) {
							esc_html_e( 'Startseite konnte nicht geprüft werden', 'wm-zero-cdn-asset-localizer' );
						} elseif ( 0 === $refs ) {
							esc_html_e( 'Keine CDN-Verweise auf der Startseite gefunden', 'wm-zero-cdn-asset-localizer' );
						} else {
							/* translators: %d: number of CDN references found on the front page */
							echo esc_html( sprintf( _n( '%d CDN-Verweis auf der Startseite', '%d CDN-Verweise auf der Startseite', $refs, 'wm-zero-cdn-asset-localizer' ), $refs ) );
						}
						?>
					</div>
					<div class="wm-dash-banner-sub"><?php esc_html_e( 'Geprüft: Google Fonts, Font Awesome, cdnjs, jsDelivr, unpkg im HTML der Startseite', 'wm-zero-cdn-asset-localizer' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<span class="wm-dash-kpi-val"><?php echo $scan['ok'] ? esc_html( (string) $refs ) : '—'; ?></span>
					<span class="wm-dash-kpi-lbl"><?php esc_html_e( 'CDN-Verweise', 'wm-zero-cdn-asset-localizer' ); ?></span>
				</div>
				<div class="wm-dash-kpi-card">
					<span class="wm-dash-kpi-val"><?php echo esc_html( (string) $fonts ); ?></span>
					<span class="wm-dash-kpi-lbl"><?php esc_html_e( 'Lokale WOFF2-Dateien', 'wm-zero-cdn-asset-localizer' ); ?></span>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-zero-cdn&tab=scanner' ) ); ?>" class="wm-dash-btn">
					<span><?php esc_html_e( 'Startseite jetzt prüfen', 'wm-zero-cdn-asset-localizer' ); ?></span> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Shared mark across the family
	 * (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WM Zero-CDN Localizer', 'wm-zero-cdn-asset-localizer' ),
			__( 'Zero-CDN', 'wm-zero-cdn-asset-localizer' ),
			'manage_options',
			'wm-zero-cdn',
			[ $this, 'render_dashboard' ],
			self::family_menu_icon(),
			85
		);
	}

	/**
	 * Family sheets on the plugin's own screen (standalone and under the hub, both hooks contain the
	 * page slug) and on the dashboard, where the widget renders (it had no sheet until 2026-09-14).
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'wm-zero-cdn' ) && 'index.php' !== $hook ) {
			return;
		}

		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		wp_enqueue_style(
			'wm-admin-tokens',
			WM_ZERO_CDN_URL . 'assets/css/wm-admin-tokens.css',
			[],
			(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_ZERO_CDN_VERSION )
		);
		wp_enqueue_style(
			'wm-admin-ui',
			WM_ZERO_CDN_URL . 'assets/css/wm-admin-ui.css',
			[ 'wm-admin-tokens' ],
			(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_ZERO_CDN_VERSION )
		);
	}

	/**
	 * @return array<string, array{label: string, icon: string}>
	 */
	private function tabs(): array {
		return [
			'inventory' => [ 'label' => __( 'Übersicht', 'wm-zero-cdn-asset-localizer' ), 'icon' => 'dashicons-chart-bar' ],
			'localize'  => [ 'label' => __( 'Schrift herunterladen', 'wm-zero-cdn-asset-localizer' ), 'icon' => 'dashicons-download' ],
			'scanner'   => [ 'label' => __( 'Startseite prüfen', 'wm-zero-cdn-asset-localizer' ), 'icon' => 'dashicons-search' ],
			'settings'  => [ 'label' => __( 'Einstellungen', 'wm-zero-cdn-asset-localizer' ), 'icon' => 'dashicons-admin-generic' ],
			'glossary'  => [ 'label' => __( 'Begriffe', 'wm-zero-cdn-asset-localizer' ), 'icon' => 'dashicons-book-alt' ],
		];
	}

	public function render_dashboard(): void {
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing, checked against the tab list.
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'inventory' ) );
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'inventory';
		}

		$detector   = new CdnDetector();
		// The scanner tab always measures afresh; the overview reuses a result up to ten minutes old.
		$front      = $detector->scan_front_page( 'scanner' !== $active_tab );
		$registered = $detector->scan_enqueued_styles();
		$fonts      = self::local_font_count();

		$preload_on    = (bool) apply_filters( 'wm_zero_cdn_preload_enabled', get_option( 'wm_zero_cdn_preload_enabled', false ) );
		$preload_fonts = (array) apply_filters( 'wm_zero_cdn_preload_fonts', get_option( 'wm_zero_cdn_preload_fonts', [] ) );
		?>
		<div class="wrap wm-admin">
			<!-- Header -->
			<header class="wm-admin-header">
				<div class="wm-header-brand">
					<div class="wm-header-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					</div>
					<div>
						<h1><?php esc_html_e( 'WM Zero-CDN — Schriften lokal bereitstellen', 'wm-zero-cdn-asset-localizer' ); ?></h1>
						<p class="wm-subtitle"><?php esc_html_e( 'Startseite auf CDN-Verweise prüfen &bull; Google-Fonts-CSS und WOFF2-Dateien auf den eigenen Server laden', 'wm-zero-cdn-asset-localizer' ); ?></p>
					</div>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-zero-cdn-asset-localizer' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-zero-cdn-asset-localizer' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-zero-cdn-asset-localizer' ) : esc_html__( 'Standalone', 'wm-zero-cdn-asset-localizer' ); ?></span>
					</span>
					<?php if ( ! $front['ok'] ) : ?>
					<span class="wm-badge wm-badge-amber"><?php esc_html_e( 'STARTSEITE NICHT GEPRÜFT', 'wm-zero-cdn-asset-localizer' ); ?></span>
					<?php elseif ( count( $front['found'] ) > 0 ) : ?>
					<span class="wm-badge wm-badge-red"><?php echo esc_html( sprintf( /* translators: %d: CDN references */ __( '%d CDN-VERWEISE', 'wm-zero-cdn-asset-localizer' ), count( $front['found'] ) ) ); ?></span>
					<?php else : ?>
					<span class="wm-badge wm-badge-green"><?php esc_html_e( 'KEINE CDN-VERWEISE GEFUNDEN', 'wm-zero-cdn-asset-localizer' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<!-- Quick Plain Language Guide -->
			<div class="wm-quick-guide">
				<div class="wm-quick-guide-title">
					<span><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <?php esc_html_e( 'Was dieses Plugin tut – und was nicht', 'wm-zero-cdn-asset-localizer' ); ?></span>
				</div>
				<p class="wm-quick-guide-desc">
					<?php esc_html_e( 'Wenn eine Seite Schriften oder Bibliotheken von einem fremden Server lädt, schickt der Browser jedes Besuchers seine IP-Adresse an diesen Server. Das Plugin prüft das HTML der Startseite auf Google Fonts, Font Awesome, cdnjs, jsDelivr und unpkg und lädt auf Wunsch ein Google-Fonts-Stylesheet samt WOFF2-Dateien nach wp-content/uploads/wm-zero-cdn/. Es tauscht nichts automatisch aus: die lokale CSS-Datei muss im Theme statt des externen Links eingebunden werden.', 'wm-zero-cdn-asset-localizer' ); ?>
				</p>
				<div class="wm-quick-guide-actions">
					<strong><?php esc_html_e( 'Schnellzugriff:', 'wm-zero-cdn-asset-localizer' ); ?></strong>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wm-zero-cdn', 'tab' => 'scanner' ], admin_url( 'admin.php' ) ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Startseite prüfen', 'wm-zero-cdn-asset-localizer' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wm-zero-cdn', 'tab' => 'localize' ], admin_url( 'admin.php' ) ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Schrift herunterladen', 'wm-zero-cdn-asset-localizer' ); ?>
					</a>
				</div>
			</div>

			<!-- Tabs -->
			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Module Tabs', 'wm-zero-cdn-asset-localizer' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wm-zero-cdn', 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>" class="wm-tab-link <?php echo $active_tab === $slug ? 'active' : ''; ?>"<?php echo $active_tab === $slug ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $tab['label'] ); ?>
				</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'inventory' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<div class="wm-grid-4">
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'CDN-Verweise auf der Startseite', 'wm-zero-cdn-asset-localizer' ); ?></span>
						<span class="wm-stat-value text-cyan"><?php echo $front['ok'] ? esc_html( (string) count( $front['found'] ) ) : '—'; ?></span>
						<span class="wm-stat-delta"><?php echo $front['ok'] ? esc_html( sprintf( /* translators: %s: time of the scan */ __( 'geprüft %s', 'wm-zero-cdn-asset-localizer' ), wp_date( 'H:i', $front['scanned_at'] ) ) ) : esc_html( sprintf( /* translators: %s: error */ __( 'nicht lesbar: %s', 'wm-zero-cdn-asset-localizer' ), (string) $front['error'] ) ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Lokale WOFF2-Dateien', 'wm-zero-cdn-asset-localizer' ); ?></span>
						<span class="wm-stat-value text-emerald"><?php echo esc_html( (string) $fonts ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'in uploads/wm-zero-cdn/', 'wm-zero-cdn-asset-localizer' ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'Preload', 'wm-zero-cdn-asset-localizer' ); ?></span>
						<span class="wm-stat-value text-amber"><?php echo $preload_on && [] !== $preload_fonts ? esc_html__( 'An', 'wm-zero-cdn-asset-localizer' ) : esc_html__( 'Aus', 'wm-zero-cdn-asset-localizer' ); ?></span>
						<span class="wm-stat-delta"><?php echo esc_html( sprintf( /* translators: %d: number of fonts listed for preloading */ __( '%d Dateien eingetragen (max. 3 werden ausgegeben)', 'wm-zero-cdn-asset-localizer' ), count( $preload_fonts ) ) ); ?></span>
					</div>
					<div class="wm-stat-card">
						<span class="wm-stat-label"><?php esc_html_e( 'CDN-Stylesheets in dieser Admin-Anfrage', 'wm-zero-cdn-asset-localizer' ); ?></span>
						<span class="wm-stat-value text-purple"><?php echo esc_html( (string) count( $registered ) ); ?></span>
						<span class="wm-stat-delta"><?php esc_html_e( 'Theme-Styles des Frontends sind hier nicht registriert', 'wm-zero-cdn-asset-localizer' ); ?></span>
					</div>
				</div>

				<div class="wm-card" style="margin-top: 24px;">
					<h2><?php esc_html_e( 'So wird eine Schrift lokal', 'wm-zero-cdn-asset-localizer' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Unter „Startseite prüfen“ die Google-Fonts-URL des Themes ablesen.', 'wm-zero-cdn-asset-localizer' ); ?></li>
						<li><?php esc_html_e( 'Unter „Schrift herunterladen“ diese URL eintragen: Stylesheet und WOFF2-Dateien landen in uploads/wm-zero-cdn/, die Schrift-URLs im Stylesheet zeigen auf die lokalen Dateien.', 'wm-zero-cdn-asset-localizer' ); ?></li>
						<li><?php esc_html_e( 'Im Theme oder per Snippet den externen Link entfernen und die angezeigte lokale CSS-URL einbinden. Das Plugin macht diesen Schritt nicht.', 'wm-zero-cdn-asset-localizer' ); ?></li>
						<li><?php esc_html_e( 'Startseite erneut prüfen.', 'wm-zero-cdn-asset-localizer' ); ?></li>
					</ol>
				</div>
			</div>

			<?php elseif ( 'localize' === $active_tab ) : ?>
			<?php
			$google_css_base   = 'https://' . 'fonts.' . 'googleapis.com/css2?family=';
			$localize_examples = [
				[
					'label' => __( 'Inter', 'wm-zero-cdn-asset-localizer' ),
					'url'   => $google_css_base . 'Inter:wght@400;600;700&display=swap',
				],
				[
					'label' => __( 'Roboto', 'wm-zero-cdn-asset-localizer' ),
					'url'   => $google_css_base . 'Roboto:wght@400;500;700&display=swap',
				],
				[
					'label' => __( 'Montserrat', 'wm-zero-cdn-asset-localizer' ),
					'url'   => $google_css_base . 'Montserrat:wght@400;600;700&display=swap',
				],
				[
					'label' => __( 'Poppins', 'wm-zero-cdn-asset-localizer' ),
					'url'   => $google_css_base . 'Poppins:wght@400;600;700&display=swap',
				],
			];
			$default_localize_url = $localize_examples[0]['url'];
			?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Google-Fonts-Stylesheet herunterladen', 'wm-zero-cdn-asset-localizer' ); ?></h2>
					<p style="color: var(--wm-text-secondary);">
						<?php esc_html_e( 'Stylesheet-URL eintragen. Das Plugin lädt das CSS und jede darin genannte WOFF2-Datei, prüft, dass es wirklich WOFF2 ist, und schreibt die Schrift-URLs auf die lokalen Kopien um. Die lokale Datei wird nicht automatisch eingebunden.', 'wm-zero-cdn-asset-localizer' ); ?>
					</p>

					<div style="margin: 14px 0;">
						<span style="font-size: 12px; font-weight: 700; color: var(--wm-text-secondary); display: block; margin-bottom: 6px;"><?php esc_html_e( 'Beispiele:', 'wm-zero-cdn-asset-localizer' ); ?></span>
						<div style="display: flex; flex-wrap: wrap; gap: 6px;">
							<?php foreach ( $localize_examples as $example_item ) : ?>
							<button type="button" class="button button-secondary button-small" data-wm-cdn-url="<?php echo esc_attr( $example_item['url'] ); ?>"><?php echo esc_html( $example_item['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>

					<div style="margin-top: 16px;">
						<label for="wm-cdn-target-url" style="color: var(--wm-text-primary); font-weight: 600; display: block; margin-bottom: 6px; font-size: 13.5px;"><?php esc_html_e( 'Stylesheet-URL:', 'wm-zero-cdn-asset-localizer' ); ?></label>
						<input type="url" id="wm-cdn-target-url" class="wm-input" value="<?php echo esc_attr( $default_localize_url ); ?>" style="width: 100%; max-width: 600px;" />
						<div style="margin-top: 12px;">
							<button type="button" class="button button-primary button-large" id="wm-cdn-localize-run">
								<?php esc_html_e( 'Herunterladen und lokal speichern', 'wm-zero-cdn-asset-localizer' ); ?>
							</button>
						</div>
					</div>

					<div id="wm-cdn-localize-status" style="margin-top: 16px; font-size: 13.5px;" aria-live="polite"></div>
				</div>

				<script>
				( function () {
					const input = document.getElementById( 'wm-cdn-target-url' );
					const box   = document.getElementById( 'wm-cdn-localize-status' );
					document.querySelectorAll( '[data-wm-cdn-url]' ).forEach( function ( btn ) {
						btn.addEventListener( 'click', function () { input.value = btn.getAttribute( 'data-wm-cdn-url' ); } );
					} );
					// Messages are built with textContent: the server's error text can echo the typed URL.
					function line( text, strong ) {
						const el = document.createElement( strong ? 'strong' : 'div' );
						el.textContent = text;
						return el;
					}
					document.getElementById( 'wm-cdn-localize-run' ).addEventListener( 'click', function () {
						box.replaceChildren( line( <?php echo wp_json_encode( __( 'Lade Stylesheet und Schriftdateien …', 'wm-zero-cdn-asset-localizer' ) ); ?> ) );
						fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'wm-zero-cdn/v1/localize' ) ) ); ?>, {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
							body: JSON.stringify( { url: input.value } )
						} )
						.then( function ( r ) { return r.json().catch( function () { return { success: false, error: 'HTTP ' + r.status }; } ); } )
						.then( function ( d ) {
							box.replaceChildren();
							if ( d.success && d.data ) {
								box.className = 'notice notice-success inline';
								box.append( line( <?php echo wp_json_encode( __( 'Gespeichert. Lokale CSS-Datei (im Theme einbinden):', 'wm-zero-cdn-asset-localizer' ) ); ?>, true ) );
								box.append( line( d.data.local_css_url ) );
								box.append( line( <?php echo wp_json_encode( __( 'WOFF2-Dateien lokal:', 'wm-zero-cdn-asset-localizer' ) ); ?> + ' ' + d.data.downloaded_fonts.length + ' · ' + <?php echo wp_json_encode( __( 'nicht ladbar (bleiben extern):', 'wm-zero-cdn-asset-localizer' ) ); ?> + ' ' + ( d.data.failed_fonts || [] ).length ) );
							} else {
								box.className = 'notice notice-error inline';
								box.append( line( <?php echo wp_json_encode( __( 'Fehler:', 'wm-zero-cdn-asset-localizer' ) ); ?> + ' ' + ( ( d.data && d.data.error ) || d.error || d.message || '—' ) ) );
							}
						} )
						// Without this the box kept showing "Lade Stylesheet …" for ever whenever the answer
						// was not JSON — a gateway page, a dropped connection, a PHP warning ahead of the body.
						.catch( function ( err ) {
							box.className = 'notice notice-error inline';
							box.replaceChildren( line( <?php echo wp_json_encode( __( 'Fehler:', 'wm-zero-cdn-asset-localizer' ) ); ?> + ' ' + ( err && err.message ? err.message : '—' ) ) );
						} );
					} );
				} )();
				</script>
			</div>

			<?php elseif ( 'scanner' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'CDN-Verweise im HTML der Startseite', 'wm-zero-cdn-asset-localizer' ); ?></h2>
					<p style="color: var(--wm-text-secondary);">
						<?php echo esc_html( sprintf( /* translators: %s: front page URL */ __( 'Gerade abgerufen: %s. Geprüft werden Links und Skripte zu Google Fonts, Font Awesome, cdnjs, jsDelivr und unpkg; andere externe Hosts und Unterseiten nicht.', 'wm-zero-cdn-asset-localizer' ), $front['url'] ) ); ?>
					</p>

					<?php if ( ! $front['ok'] ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %s: error */ __( 'Die Startseite konnte nicht abgerufen werden (%s). Ohne diese Messung sagt das Plugin nichts über externe Verweise.', 'wm-zero-cdn-asset-localizer' ), (string) $front['error'] ) ); ?></p></div>
					<?php elseif ( [] === $front['found'] ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'Keine Verweise auf die fünf geprüften CDN-Hosts im HTML der Startseite.', 'wm-zero-cdn-asset-localizer' ); ?></p></div>
					<?php else : ?>
					<table class="wm-table widefat striped" style="width: 100%;">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Anbieter', 'wm-zero-cdn-asset-localizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'URL', 'wm-zero-cdn-asset-localizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $front['found'] as $item ) : ?>
							<tr>
								<td><span class="wm-badge wm-badge-cyan"><?php echo esc_html( $item['provider'] ); ?></span></td>
								<td><code style="font-size: 12px; word-break: break-all;"><?php echo esc_html( $item['url'] ); ?></code></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>

				<div class="wm-card" style="margin-top: 24px;">
					<h2><?php esc_html_e( 'CDN-Stylesheets, die in dieser Admin-Anfrage registriert sind', 'wm-zero-cdn-asset-localizer' ); ?></h2>
					<?php if ( [] === $registered ) : ?>
					<p style="color: var(--wm-text-secondary);"><?php esc_html_e( 'Keine. Die Styles des Frontends werden im Admin nicht registriert; maßgeblich ist die Prüfung der Startseite oben.', 'wm-zero-cdn-asset-localizer' ); ?></p>
					<?php else : ?>
					<table class="wm-table widefat striped" style="width: 100%;">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Handle', 'wm-zero-cdn-asset-localizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Anbieter', 'wm-zero-cdn-asset-localizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Quelle', 'wm-zero-cdn-asset-localizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $registered as $item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $item['handle'] ); ?></strong></td>
								<td><span class="wm-badge wm-badge-cyan"><?php echo esc_html( $item['provider'] ); ?></span></td>
								<td><code style="font-size: 12px; word-break: break-all;"><?php echo esc_html( $item['src'] ); ?></code></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>

			<?php elseif ( 'settings' === $active_tab ) : ?>
			<?php
			$stored_preload_enabled = (bool) get_option( 'wm_zero_cdn_preload_enabled', false );
			$stored_preload_fonts   = self::sanitize_preload_fonts( get_option( 'wm_zero_cdn_preload_fonts', [] ) );
			$delete_on_uninstall    = (bool) get_option( 'wm_zero_cdn_delete_data_on_uninstall', false );
			?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'Einstellungen', 'wm-zero-cdn-asset-localizer' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( 'wm_zero_cdn_settings' ); ?>

						<div style="margin-top: 16px;">
							<label for="wm-zero-cdn-preload-enabled" style="display: flex; align-items: flex-start; gap: 10px; font-weight: 600; color: var(--wm-text-primary);">
								<input type="checkbox" id="wm-zero-cdn-preload-enabled" name="wm_zero_cdn_preload_enabled" value="1" <?php checked( $stored_preload_enabled ); ?> />
								<span><?php esc_html_e( 'Preload-Links im Kopfbereich aktivieren', 'wm-zero-cdn-asset-localizer' ); ?></span>
							</label>
							<p style="color: var(--wm-text-secondary); margin: 6px 0 0 26px;">
								<?php esc_html_e( 'Gibt für bis zu drei vorhandene WOFF2-Dateien in wp-content/uploads/wm-zero-cdn/ ein rel="preload"-Element im Kopfbereich aus.', 'wm-zero-cdn-asset-localizer' ); ?>
							</p>
						</div>

						<div style="margin-top: 20px;">
							<label for="wm-zero-cdn-preload-fonts" style="color: var(--wm-text-primary); font-weight: 600; display: block; margin-bottom: 6px; font-size: 13.5px;">
								<?php esc_html_e( 'Lokale WOFF2-Dateinamen für Preload (eine Datei pro Zeile):', 'wm-zero-cdn-asset-localizer' ); ?>
							</label>
							<textarea id="wm-zero-cdn-preload-fonts" name="wm_zero_cdn_preload_fonts" rows="4" class="wm-input" style="width: 100%; max-width: 600px;"><?php echo esc_textarea( implode( "\n", $stored_preload_fonts ) ); ?></textarea>
							<p style="color: var(--wm-text-secondary); margin-top: 6px;">
								<?php esc_html_e( 'Nur Dateinamen auf .woff2, die tatsächlich im Ordner uploads/wm-zero-cdn/ liegen, werden berücksichtigt (höchstens drei).', 'wm-zero-cdn-asset-localizer' ); ?>
							</p>
						</div>

						<div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--wm-border-subtle);">
							<label for="wm-zero-cdn-delete-on-uninstall" style="display: flex; align-items: flex-start; gap: 10px; font-weight: 600; color: var(--wm-text-primary);">
								<input type="checkbox" id="wm-zero-cdn-delete-on-uninstall" name="wm_zero_cdn_delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
								<span><?php esc_html_e( 'Daten beim Deinstallieren löschen', 'wm-zero-cdn-asset-localizer' ); ?></span>
							</label>
							<p style="color: var(--wm-text-secondary); margin: 6px 0 0 26px;">
								<?php esc_html_e( 'Standardmäßig bleiben Optionen und lokal gespeicherte Schriftdateien erhalten, wenn das Plugin gelöscht wird. Nur wenn diese Option aktiviert ist, entfernt die Deinstallation alle Plugin-Optionen, den Prüf-Zwischenspeicher und den Ordner wp-content/uploads/wm-zero-cdn/ dieser Website.', 'wm-zero-cdn-asset-localizer' ); ?>
							</p>
						</div>

						<div style="margin-top: 20px;">
							<?php submit_button( __( 'Einstellungen speichern', 'wm-zero-cdn-asset-localizer' ), 'primary', 'submit', false ); ?>
						</div>
					</form>
				</div>
			</div>

			<?php elseif ( 'glossary' === $active_tab ) : ?>
			<div class="wm-tab-content">
				<div class="wm-card">
					<h2><span class="dashicons dashicons-book-alt" aria-hidden="true"></span> <?php esc_html_e( 'Begriffe', 'wm-zero-cdn-asset-localizer' ); ?></h2>
					<div class="wm-glossary-grid">
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'CDN (Content Delivery Network)', 'wm-zero-cdn-asset-localizer' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Fremde Server, die Dateien ausliefern. Lädt der Browser eine Datei von dort, erhält der Server die IP-Adresse des Besuchers.', 'wm-zero-cdn-asset-localizer' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'WOFF2', 'wm-zero-cdn-asset-localizer' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Komprimiertes Schriftformat für das Web. Das Plugin speichert nur Dateien, die mit der WOFF2-Signatur beginnen; es konvertiert keine anderen Formate.', 'wm-zero-cdn-asset-localizer' ); ?></p>
						</div>
						<div class="wm-glossary-card">
							<div class="wm-glossary-term"><?php esc_html_e( 'Preload', 'wm-zero-cdn-asset-localizer' ); ?></div>
							<p class="wm-glossary-def"><?php esc_html_e( 'Ein <link rel="preload">-Hinweis, der den Browser eine Schrift früher laden lässt. Das Plugin gibt bis zu drei solcher Links für vorhandene Dateien in uploads/wm-zero-cdn/ aus, wenn Preload unter „Einstellungen“ oder per Filter aktiviert ist.', 'wm-zero-cdn-asset-localizer' ); ?></p>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
