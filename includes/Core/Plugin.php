<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\ZeroCDN\Localizer\Preloader\PreloadInjector;
use WMCyber\ZeroCDN\Localizer\Admin\AdminDashboard;
use WMCyber\ZeroCDN\Localizer\API\RestController;
use WMCyber\ZeroCDN\Localizer\CLI\ZeroCdnCommand;
use WMCyber\ZeroCDN\Localizer\Integrations\BlockIntegrations;

final class Plugin {
	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', function (): void {
			load_plugin_textdomain(
				'wm-zero-cdn-asset-localizer',
				false,
				dirname( plugin_basename( WM_ZERO_CDN_FILE ) ) . '/languages'
			);
		} );

		// Injected preload headers in frontend head
		add_action( 'wp_head', [ new PreloadInjector(), 'inject_preload_tags' ], 1 );

		// Admin Dashboard
		if ( is_admin() ) {
			AdminDashboard::instance()->boot();
		}

		// REST API
		add_action( 'rest_api_init', function (): void {
			( new RestController() )->register_routes();
		} );

		// Block Bindings (WP 6.5+) & GenerateBlocks 2.x Dynamic Tags
		( new BlockIntegrations() )->boot();

		// WP-CLI
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'cdn', ZeroCdnCommand::class );
		}

		/**
		 * Fires once WM Zero-CDN Asset Localizer has registered its runtime hooks and integrations.
		 *
		 * @param Plugin $plugin Active plugin bootstrapper instance.
		 */
		do_action( 'wm_zero_cdn_ready', $this );
	}
}
