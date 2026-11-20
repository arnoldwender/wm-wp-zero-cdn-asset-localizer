<?php
declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;
use WMCyber\ZeroCDN\Localizer\Downloader\AssetDownloader;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController {
	private const NAMESPACE = 'wm-zero-cdn/v1';

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/scan', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_scan' ],
			'permission_callback' => [ $this, 'check_permissions' ],
		] );

		register_rest_route( self::NAMESPACE, '/localize', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_localize' ],
			'permission_callback' => [ $this, 'check_permissions' ],
			// Without this schema an array `url` produced "Array to string conversion" and the callback
			// carried on with the literal string "Array"; `"0"` counted as missing because the guard was
			// empty(). WordPress validates the type and the URI format before the callback runs.
			'args'                => [
				'url' => [
					'type'     => 'string',
					'format'   => 'uri',
					'required' => true,
				],
			],
		] );
	}

	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * `front_page` is the measurement; `registered_styles` only lists the CDN stylesheets registered
	 * in this REST request (not the theme's). `success` is false when the front page was unreadable.
	 */
	public function handle_scan(): WP_REST_Response {
		$detector = new CdnDetector();
		$front    = $detector->scan_front_page( false );

		return new WP_REST_Response( [
			'success' => $front['ok'],
			'data'    => [
				'front_page'        => $front,
				'registered_styles' => $detector->scan_enqueued_styles(),
			],
		], 200 );
	}

	public function handle_localize( WP_REST_Request $request ): WP_REST_Response {
		$url = (string) $request->get_param( 'url' );
		if ( '' === trim( $url ) ) {
			return new WP_REST_Response( [ 'error' => 'url parameter required' ], 400 );
		}

		$downloader = new AssetDownloader();
		$result     = $downloader->localize_css( $url );
		$success    = (bool) ( $result['success'] ?? false );

		return new WP_REST_Response(
			[
				'success' => $success,
				'data'    => $result,
			],
			// A download that failed answered 200, so nothing watching status codes could see it.
			$success ? 200 : 502
		);
	}
}
