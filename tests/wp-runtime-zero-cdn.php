<?php
/**
 * Read-only runtime check for wm-zero-cdn-asset-localizer inside a real WordPress.
 *
 *   wp eval-file wp-content/plugins/wm-zero-cdn-asset-localizer/tests/wp-runtime-zero-cdn.php --user=1
 *
 * Safe on a live site: it writes nothing and downloads nothing. The plugin's options, its scan transient and the
 * files in uploads/wm-zero-cdn/ are compared before and after; the detector runs on in-memory fixtures; the front
 * page is fetched once, the same loopback the plugin performs, without storing the result. What it checks that the
 * standalone suite cannot: that the routes are registered in this install with their argument schema, what the
 * deployed files say, and what the site's own front page carries.
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

defined( 'ABSPATH' ) || exit;

use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;

global $wpdb;

$GLOBALS['wm_zc_pass'] = 0;
$GLOBALS['wm_zc_fail'] = 0;

/**
 * Report one check.
 *
 * @param string $label Description.
 * @param bool   $ok    Result.
 * @param string $note  Measured value.
 * @return void
 */
function wm_zc_check( $label, $ok, $note = '' ) {
	if ( $ok ) {
		++$GLOBALS['wm_zc_pass'];
		echo "  [PASS] {$label}\n";
		return;
	}
	++$GLOBALS['wm_zc_fail'];
	echo "  [FAIL] {$label}" . ( '' !== $note ? ": {$note}" : '' ) . "\n";
}

/**
 * Snapshot of everything the plugin owns.
 *
 * @return array
 */
function wm_zc_state() {
	global $wpdb;
	$uploads = wp_upload_dir();
	$folder  = $uploads['basedir'] . '/wm-zero-cdn';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$options = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',
			$wpdb->options,
			$wpdb->esc_like( 'wm_zero_cdn' ) . '%',
			$wpdb->esc_like( '_transient_wm_zero_cdn' ) . '%'
		),
		ARRAY_A
	);
	return [ 'options' => $options, 'files' => is_dir( $folder ) ? glob( $folder . '/*' ) : null ];
}

$before = wm_zc_state();

echo "\n=== WM ZERO-CDN ASSET LOCALIZER — RUNTIME CHECK ===\n\n";

// 1-2. Routes and their argument schema.
$routes        = rest_get_server()->get_routes();
$localize_args = (array) ( $routes['/wm-zero-cdn/v1/localize'][0]['args'] ?? [] );
wm_zc_check( 'both routes are registered', isset( $routes['/wm-zero-cdn/v1/scan'], $routes['/wm-zero-cdn/v1/localize'] ) );
wm_zc_check(
	'the localize route declares url as a required string in URI format',
	'string' === ( $localize_args['url']['type'] ?? '' ) && 'uri' === ( $localize_args['url']['format'] ?? '' ) && true === ( $localize_args['url']['required'] ?? false ),
	wp_json_encode( $localize_args )
);

// 3-5. The detector on the forms a page really uses.
$detector = new CdnDetector();
$relative = $detector->scan_html( "<link rel='stylesheet' href='//fonts.googleapis.com/css?family=Open+Sans'>" );
$gstatic  = $detector->scan_html( '<link rel="preconnect" href="https://fonts.gstatic.com">' ); // No path: a preconnect opens the connection anyway.
$entity   = $detector->scan_html( '<link href="https://fonts.googleapis.com/css2?family=Inter&#038;display=swap" rel="stylesheet">' );
wm_zc_check( 'a protocol-relative reference is found', 1 === count( $relative ) && str_starts_with( $relative[0]['url'], 'https://' ), wp_json_encode( $relative ) );
wm_zc_check( 'a fonts.gstatic.com reference is found', 1 === count( $gstatic ), wp_json_encode( $gstatic ) );
wm_zc_check( 'the reported URL carries no HTML entity', 1 === count( $entity ) && ! str_contains( $entity[0]['url'], '&#038;' ), wp_json_encode( $entity ) );

// 6. The site's own front page, measured here and not stored.
$response = wp_safe_remote_get( home_url( '/' ), [ 'timeout' => 10, 'redirection' => 3 ] );
if ( is_wp_error( $response ) ) {
	echo '  [INFO] front page: ' . $response->get_error_message() . " (loopback blocked or the site is behind authentication)\n";
} else {
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = 200 === $code ? (string) wp_remote_retrieve_body( $response ) : '';
	echo '  [INFO] front page: HTTP ' . $code . ( '' !== $body ? ', CDN references found: ' . count( $detector->scan_html( $body ) ) : '' ) . "\n";
}

// 7-8. What the operator is told, and what is on disk.
$uploads = wp_upload_dir();
$folder  = $uploads['basedir'] . '/wm-zero-cdn';
wm_zc_check(
	'the download folder, when it exists, carries its index.php guard',
	! is_dir( $folder ) || is_file( $folder . '/index.php' ),
	'folder: ' . $folder
);
$cached = get_option( '_transient_' . CdnDetector::FRONT_SCAN_TRANSIENT, null );
echo '  [INFO] cached scan: ' . ( is_array( $cached ) ? ( ( $cached['ok'] ?? false ) ? 'readable, ' . count( (array) $cached['found'] ) . ' references' : 'unreadable: ' . (string) ( $cached['error'] ?? '?' ) ) : 'none' ) . "\n";

// 9-10. The deployed files, not the ones in the repository.
$dir     = WM_ZERO_CDN_PATH;
$shipped = array_merge( glob( $dir . 'includes/*/*.php' ) ?: [], [ $dir . 'wm-zero-cdn-asset-localizer.php', $dir . 'uninstall.php' ] );
$missing = array_values(
	array_filter(
		$shipped,
		static function ( $file ) {
			$src = (string) file_get_contents( $file );
			return ! preg_match( "/defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)/", $src ) || ! str_contains( $src, 'declare(strict_types=1)' );
		}
	)
);
wm_zc_check( 'every deployed PHP file carries the guard and strict types', [] === $missing, implode( ', ', array_map( 'basename', $missing ) ) );
wm_zc_check(
	'the deployed downloader refuses private addresses and checks its writes',
	str_contains( (string) file_get_contents( $dir . 'includes/Downloader/AssetDownloader.php' ), 'refuse_url' )
		&& str_contains( (string) file_get_contents( $dir . 'includes/Downloader/AssetDownloader.php' ), 'false === file_put_contents' )
);

// 11. The hub adapter, only when a cached scan exists: asking it without one would store a scan.
if ( is_array( $cached ) && interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	require_once $dir . 'includes/Adapters/Zero_Cdn_Spoke_Adapter.php';
	$adapter   = new \WMCyber\ZeroCDN\Localizer\Adapters\Zero_Cdn_Spoke_Adapter();
	$telemetry = $adapter->get_telemetry_metrics();
	wm_zc_check(
		'the hub health follows the cached front-page measurement',
		( ( $cached['ok'] ?? false ) && [] === (array) $cached['found'] ) === ( 'HEALTHY' === $adapter->get_health_status() ),
		'health ' . $adapter->get_health_status() . ', telemetry ' . wp_json_encode( $telemetry )
	);
} else {
	echo "  [INFO] hub adapter not asked: no cached scan (asking it would store one)\n";
}

// 12. The German catalog belongs to the strings this version ships.
require_once ABSPATH . WPINC . '/pomo/mo.php';
$mo       = new MO();
$imported = $mo->import_from_file( $dir . 'languages/wm-zero-cdn-asset-localizer-de_DE.mo' );
// The source strings are German, so msgfmt writes almost nothing into the German .mo: an empty msgstr
// is not compiled, and WordPress falls back to the source. What has to hold is that the catalog loads
// and carries no double-encoded entry from the dictionary era.
wm_zc_check(
	'the German catalog loads and carries no double-encoded entry',
	$imported && [] === array_filter( array_keys( $mo->entries ), static fn( $key ) => str_contains( (string) $key, 'Ã' ) ),
	'imported: ' . var_export( $imported, true ) . ', entries: ' . ( $imported ? count( $mo->entries ) : 0 )
);

// 13. Nothing moved.
wm_zc_check( 'the check wrote nothing', wm_zc_state() === $before );

echo "\n  RUNTIME CHECKS: {$GLOBALS['wm_zc_pass']} PASSED | {$GLOBALS['wm_zc_fail']} FAILED\n\n";
