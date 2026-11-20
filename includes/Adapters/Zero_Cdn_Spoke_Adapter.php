<?php
/**
 * WM Suite Zero-CDN Asset Localizer — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface)
 * for centralized telemetry, font sovereignty reporting, and automated diagnostics.
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;

final class Zero_Cdn_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-zero-cdn-asset-localizer';
	}

	public function get_name(): string {
		return 'WM Zero-CDN Asset Localizer';
	}

	public function get_version(): string {
		return defined( 'WM_ZERO_CDN_VERSION' ) ? WM_ZERO_CDN_VERSION : '1.2.0';
	}

	public function get_description(): string {
		return 'Prüft die Startseite auf CDN-Verweise und lädt Google-Fonts-Stylesheets samt WOFF2 auf den eigenen Server.';
	}

	/**
	 * HEALTHY only when the front page was read and holds no reference to the five CDN hosts.
	 * An unreadable front page is DEGRADED, never HEALTHY. Until 2026-09-14 this looked at the styles
	 * registered in the hub's own admin request, found none, and reported HEALTHY for every site; its
	 * 'WARNING' value is not a status the hub accepts either.
	 */
	public function get_health_status(): string {
		$scan = ( new CdnDetector() )->scan_front_page( true );
		return ( $scan['ok'] && [] === $scan['found'] ) ? 'HEALTHY' : 'DEGRADED';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-zero-cdn-asset-localizer@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_ZERO_CDN_FILE ),
				],
			],
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			'properties'  => [
				[
					'name'  => 'wm:features:scan',
					'value' => 'Front-page HTML scan for Google Fonts, Font Awesome, cdnjs, jsDelivr and unpkg references',
				],
				[
					'name'  => 'wm:features:localize',
					'value' => 'Downloads a stylesheet and its WOFF2 files to uploads/wm-zero-cdn/; does not enqueue them',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	/** The page slug is `wm-zero-cdn`; until 2026-09-14 this linked to `wm-zero-cdn-dashboard`, which does not exist. */
	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-zero-cdn' );
	}

	public function get_telemetry_metrics(): array {
		$scan            = ( new CdnDetector() )->scan_front_page( true );
		$preloaded       = (array) get_option( 'wm_zero_cdn_preload_fonts', [] );
		$preload_enabled = (bool) get_option( 'wm_zero_cdn_preload_enabled', false );

		$upload_dir        = wp_upload_dir();
		$local_font_dir    = $upload_dir['basedir'] . '/wm-zero-cdn';
		$cached_font_files = 0;
		if ( is_dir( $local_font_dir ) ) {
			$files             = glob( $local_font_dir . '/*.woff2' );
			$cached_font_files = is_array( $files ) ? count( $files ) : 0;
		}

		return [
			'front_page_scan'     => $scan['ok'] ? 'READ' : 'UNREADABLE',
			// null, not 0, when the page could not be read: a missing measurement is not a clean site.
			'front_page_cdn_refs' => $scan['ok'] ? count( $scan['found'] ) : null,
			'preload_enabled'     => $preload_enabled ? 'YES' : 'NO',
			'preloaded_fonts'     => count( $preloaded ),
			'local_cached_woff2'  => $cached_font_files,
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
				// Detector fixture: this markup is fed to CdnDetector::scan_html() and never printed,
				// so the enqueue rule for stylesheets does not apply to it.
				$fixture_url = 'https://' . 'fonts.' . 'googleapis.com/css2?family=Inter:wght@400;700';
				$test_html   = '<link rel="stylesheet" href="' . $fixture_url . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- in-memory detector fixture passed to scan_html(), never printed.
				$detector    = new CdnDetector();
				$found = $detector->scan_html( $test_html );
				return [
					'success' => count( $found ) > 0,
					'message' => sprintf( 'CDN-Erkennungstest erfolgreich: %d externe CDN-Muster erkannt (Google Fonts).', count( $found ) ),
				];

			case 'scan':
				$scan = ( new CdnDetector() )->scan_front_page( false );
				if ( ! $scan['ok'] ) {
					return [
						'success' => false,
						'message' => sprintf( 'Startseite nicht lesbar (%s): keine Aussage über CDN-Verweise.', esc_html( (string) $scan['error'] ) ),
					];
				}
				return [
					'success' => true,
					'message' => sprintf( 'Startseite geprüft: %d Verweise auf Google Fonts, Font Awesome, cdnjs, jsDelivr oder unpkg.', count( $scan['found'] ) ),
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}
