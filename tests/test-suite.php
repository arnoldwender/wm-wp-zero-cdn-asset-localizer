<?php
/**
 * Standalone test suite for wm-zero-cdn-asset-localizer (no WordPress needed).
 *
 * HTTP is stubbed per URL, so the real classes run end to end:
 * 1. CdnDetector::scan_html() on the five CDN hosts.
 * 2. CdnDetector::scan_front_page(): a readable page is scanned, an unreadable one is NOT reported clean.
 * 3. AssetDownloader::localize_css(): HTTP status, WOFF2 signature, URL rewriting, files on disk.
 * 4. The spoke adapter: health and telemetry follow the front-page measurement, settings URL exists.
 * 5. Admin screen and CLI: no fixed "100 %" / "0 IP-Leaks" claims, no emoji.
 *
 * Until 2026-09-14 the WOFF2 test re-implemented the rewrite regex inside the test instead of calling
 * the class, so it passed whatever AssetDownloader did.
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WM_ZERO_CDN_VERSION', '1.2.1' );
define( 'WM_ZERO_CDN_FILE', dirname( __DIR__ ) . '/wm-zero-cdn-asset-localizer.php' );
define( 'WM_ZERO_CDN_PATH', dirname( __DIR__ ) . '/' );
define( 'WM_ZERO_CDN_URL', 'https://site.test/wp-content/plugins/wm-zero-cdn-asset-localizer/' );

$GLOBALS['wm_test_uploads']           = sys_get_temp_dir() . '/wm_zero_cdn_test_uploads_' . getmypid();
$GLOBALS['mock_http']                 = [];
$GLOBALS['mock_transients']           = [];
$GLOBALS['mock_options']              = [];
$GLOBALS['http_calls']                = [];
$GLOBALS['captured_curl_opts']        = [];
$GLOBALS['fired_actions']             = [];
$GLOBALS['registered_actions']        = [];
$GLOBALS['registered_filters']        = [];
$GLOBALS['registered_settings']       = [];
$GLOBALS['registered_block_bindings'] = [];
$GLOBALS['mock_is_admin']             = false;

// ---- WordPress stand-ins -------------------------------------------------------------------------
final class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}
function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
function wp_upload_dir(): array {
	return [ 'basedir' => $GLOBALS['wm_test_uploads'], 'baseurl' => 'https://site.test/wp-content/uploads' ];
}
function wp_mkdir_p( string $target ): bool { return is_dir( $target ) || @mkdir( $target, 0777, true ); }
function home_url( string $path = '' ): string { return 'https://site.test' . $path; }
function admin_url( string $path = '' ): string { return 'https://site.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return str_replace( [ '"', "'" ], [ '&quot;', '&#039;' ], (string) $url ); }
function __( string $text, string $domain = 'default' ): string { return $text; }
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { return 1 === $number ? $single : $plural; }
function sanitize_key( string $key ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function sanitize_file_name( string $filename ): string { return (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', $filename ); }
function do_action( string $hook, ...$args ): void { $GLOBALS['fired_actions'][ $hook ][] = $args; }
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['registered_actions'][ $hook ][] = $callback; return true; }
function remove_action( string $hook, $callback, int $priority = 10 ): bool {
	if ( isset( $GLOBALS['registered_actions'][ $hook ] ) ) {
		$GLOBALS['registered_actions'][ $hook ] = array_values(
			array_filter(
				$GLOBALS['registered_actions'][ $hook ],
				static fn( $registered ): bool => $registered !== $callback
			)
		);
	}
	return true;
}
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['registered_filters'][ $hook ][] = $callback; return true; }
function is_admin(): bool { return (bool) ( $GLOBALS['mock_is_admin'] ?? false ); }
function register_setting( string $group, string $option_name, array $args = [] ): void { $GLOBALS['registered_settings'][ $option_name ] = [ 'group' => $group, 'args' => $args ]; }
function register_block_bindings_source( string $name, array $props ): bool { $GLOBALS['registered_block_bindings'][ $name ] = $props; return true; }
class GenerateBlocks_Register_Dynamic_Tag {
	/** @var array<string, array<string, mixed>> */
	public static array $tags = [];
	/** @param array<string, mixed> $args */
	public function __construct( array $args ) {
		self::$tags[ (string) ( $args['tag'] ?? '' ) ] = $args;
	}
}
function get_option( string $key, $default = false ) { return $GLOBALS['mock_options'][ $key ] ?? $default; }
function get_transient( string $key ) { return $GLOBALS['mock_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $ttl = 0 ): bool { $GLOBALS['mock_transients'][ $key ] = $value; return true; }
function wp_safe_remote_get( string $url, array $args = [] ) {
	$GLOBALS['http_calls'][] = $url;
	if ( ! empty( $GLOBALS['registered_actions']['http_api_curl'] ) ) {
		$handle = function_exists( 'curl_init' ) ? curl_init( $url ) : new stdClass();
		foreach ( $GLOBALS['registered_actions']['http_api_curl'] as $callback ) {
			$callback( $handle, $args, $url );
		}
	}
	return $GLOBALS['mock_http'][ $url ] ?? [ 'response' => [ 'code' => 404 ], 'body' => 'not found' ];
}
function wp_remote_retrieve_response_code( $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_retrieve_header( $response, string $header ): string { return (string) ( $response['headers'][ strtolower( $header ) ] ?? '' ); }
function ok( string $body, string $content_type = 'text/css' ): array { return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => $content_type ], 'body' => $body ]; }

/** PHP source without comments: the static checks look at code, not at comments that quote the old texts. */
function code_only( string $file ): string {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
			continue;
		}
		$out .= is_array( $token ) ? $token[1] : $token;
	}
	return $out;
}

// Host name => the addresses it resolves to. The downloader asks through this filter, so the suite
// answers and needs no network; a host nobody set resolves to a public address.
$GLOBALS['mock_host_addresses'] = [];
function apply_filters( string $hook, $value, ...$args ) {
	if ( 'wm_zero_cdn_host_addresses' === $hook ) {
		$host  = (string) ( $args[0] ?? '' );
		$value = $GLOBALS['mock_host_addresses'][ $host ] ?? [ '93.184.216.34' ];
	}
	foreach ( $GLOBALS['registered_filters'][ $hook ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function register_rest_route( string $namespace, string $route, array $args = [] ): bool {
	$GLOBALS['rest_routes'][ $namespace . $route ] = $args;
	return true;
}
class WP_REST_Server {
	public const READABLE = 'GET';
	public const CREATABLE = 'POST';
}
class WP_REST_Request {
	private array $params = [];
	public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
}
class WP_REST_Response {
	public function __construct( private $data = null, private int $status = 200 ) {}
	public function get_status(): int { return $this->status; }
	public function get_data() { return $this->data; }
}

require_once __DIR__ . '/fixtures/hub-interface.php';
require_once __DIR__ . '/fixtures/curl-spy.php';
require_once ABSPATH . 'includes/Scanner/CdnDetector.php';
require_once ABSPATH . 'includes/Downloader/AssetDownloader.php';
require_once ABSPATH . 'includes/Preloader/PreloadInjector.php';
require_once ABSPATH . 'includes/API/RestController.php';
require_once ABSPATH . 'includes/Admin/AdminDashboard.php';
require_once ABSPATH . 'includes/Integrations/BlockIntegrations.php';
require_once ABSPATH . 'includes/Core/Plugin.php';
require_once ABSPATH . 'includes/Adapters/Zero_Cdn_Spoke_Adapter.php';

use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;
use WMCyber\ZeroCDN\Localizer\Downloader\AssetDownloader;
use WMCyber\ZeroCDN\Localizer\Preloader\PreloadInjector;
use WMCyber\ZeroCDN\Localizer\API\RestController;
use WMCyber\ZeroCDN\Localizer\Admin\AdminDashboard;
use WMCyber\ZeroCDN\Localizer\Integrations\BlockIntegrations;
use WMCyber\ZeroCDN\Localizer\Core\Plugin;
use WMCyber\ZeroCDN\Localizer\Adapters\Zero_Cdn_Spoke_Adapter;

$passed_count = 0;
$failed_count = 0;
function assert_test( string $name, bool $condition, string $failure_details = '' ): void {
	global $passed_count, $failed_count;
	if ( $condition ) {
		echo "[PASS] {$name}\n";
		$passed_count++;
	} else {
		echo "[FAIL] {$name}: {$failure_details}\n";
		$failed_count++;
	}
}

echo "============================================================\n";
echo " WM ZERO CDN ASSET LOCALIZER — TEST SUITE\n";
echo "============================================================\n\n";

$detector = new CdnDetector();

// ---- 1. scan_html -------------------------------------------------------------------------------
echo "[GROUP 1] scan_html\n";
$findings1 = $detector->scan_html( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">' );
assert_test(
	'Google Fonts link is found with its host',
	1 === count( $findings1 ) && 'Google Fonts' === $findings1[0]['provider'] && 'fonts.googleapis.com' === $findings1[0]['host'],
	json_encode( $findings1 )
);
assert_test(
	'A finding carries no legal classification derived from a regex',
	! array_key_exists( 'liability', $findings1[0] ?? [] ) && ! array_key_exists( 'risk', $findings1[0] ?? [] ),
	json_encode( $findings1 )
);
$findings2 = $detector->scan_html(
	'<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>'
	. '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">'
	. '<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>'
);
$providers = array_column( $findings2, 'provider' );
assert_test( 'CDNJS, jsDelivr and unpkg are found', in_array( 'CDNJS', $providers, true ) && in_array( 'jsDelivr', $providers, true ) && in_array( 'Unpkg', $providers, true ), json_encode( $providers ) );
assert_test( 'Self-hosted assets give zero findings', [] === $detector->scan_html( '<link rel="stylesheet" href="/wp-content/themes/generatepress/style.css">' ) );

// ---- 2. scan_front_page -------------------------------------------------------------------------
echo "\n[GROUP 2] scan_front_page\n";
$GLOBALS['mock_http']['https://site.test/'] = ok( '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto"></head></html>' );
$front = $detector->scan_front_page( false );
assert_test( 'A readable front page is scanned: ok and one Google Fonts reference', true === $front['ok'] && 1 === count( $front['found'] ), json_encode( $front ) );

$GLOBALS['mock_http']['https://site.test/'] = [ 'response' => [ 'code' => 500 ], 'body' => '' ];
$front500 = $detector->scan_front_page( false );
assert_test( 'An HTTP 500 front page is not ok and reports no findings as a result (not a clean site)', false === $front500['ok'] && 'HTTP 500' === $front500['error'], json_encode( $front500 ) );

$GLOBALS['mock_http']['https://site.test/'] = new WP_Error( 'http_request_failed', 'cURL error 7' );
$front_err = $detector->scan_front_page( false );
assert_test( 'A transport error is not ok and keeps the error text', false === $front_err['ok'] && 'cURL error 7' === $front_err['error'], json_encode( $front_err ) );

$GLOBALS['http_calls'] = [];
$cached = $detector->scan_front_page( true );
assert_test( 'use_cache returns the stored result without a new request', [] === $GLOBALS['http_calls'] && false === $cached['ok'], json_encode( $GLOBALS['http_calls'] ) );

// ---- 3. AssetDownloader -------------------------------------------------------------------------
echo "\n[GROUP 3] AssetDownloader::localize_css\n";
$css_url  = 'https://fonts.googleapis.com/css2?family=Inter';
$font_ok  = 'https://fonts.gstatic.com/s/inter/v13/ok.woff2';
$font_bad = 'https://fonts.gstatic.com/s/inter/v13/html-page.woff2';
$GLOBALS['mock_http'][ $css_url ]  = ok( "@font-face { src: url({$font_ok}) format('woff2'); }\n@font-face { src: url({$font_bad}) format('woff2'); }" );
$GLOBALS['mock_http'][ $font_ok ]  = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$GLOBALS['mock_http'][ $font_bad ] = ok( '<!doctype html><title>Error</title>' );

$res = ( new AssetDownloader() )->localize_css( $css_url );
$local_css = $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . basename( (string) ( $res['local_css_url'] ?? '' ) );
$css_on_disk = is_file( $local_css ) ? (string) file_get_contents( $local_css ) : '';
assert_test( 'A 200 stylesheet is saved and one real WOFF2 is stored', true === $res['success'] && 1 === count( $res['downloaded_fonts'] ) && is_file( $local_css ), json_encode( $res ) );
assert_test( 'A font body without the wOF2 signature is not stored and listed as failed', [ $font_bad ] === $res['failed_fonts'] && 1 === count( glob( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/font_*.woff2' ) ?: [] ), json_encode( $res ) );
assert_test(
	'The stored stylesheet points the good font to the local copy and keeps the failed one remote',
	str_contains( $css_on_disk, 'https://site.test/wp-content/uploads/wm-zero-cdn/font_' ) && ! str_contains( $css_on_disk, $font_ok ) && str_contains( $css_on_disk, $font_bad ),
	$css_on_disk
);

$GLOBALS['mock_http']['https://fonts.googleapis.com/css2?family=Missing'] = [ 'response' => [ 'code' => 404 ], 'body' => '<html>404</html>' ];
$res404 = ( new AssetDownloader() )->localize_css( 'https://fonts.googleapis.com/css2?family=Missing' );
assert_test( 'A 404 stylesheet is an error, not a saved file', false === $res404['success'] && str_contains( (string) $res404['error'], '404' ), json_encode( $res404 ) );

$downloader_src = code_only( ABSPATH . 'includes/Downloader/AssetDownloader.php' );
assert_test( 'Downloads use wp_safe_remote_get(), never wp_remote_get()', ! preg_match( '/\bwp_remote_get\s*\(/', $downloader_src ) && str_contains( $downloader_src, 'wp_safe_remote_get(' ) );

// ---- 4. Spoke adapter ---------------------------------------------------------------------------
echo "\n[GROUP 4] Spoke adapter follows the front-page measurement\n";
$adapter = new Zero_Cdn_Spoke_Adapter();
$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_http']['https://site.test/'] = new WP_Error( 'http_request_failed', 'cURL error 7' );
assert_test( 'Unreadable front page: health DEGRADED, never HEALTHY', 'DEGRADED' === $adapter->get_health_status() );
$tele = $adapter->get_telemetry_metrics();
assert_test( 'Unreadable front page: telemetry says UNREADABLE and the count is null, not 0', 'UNREADABLE' === $tele['front_page_scan'] && null === $tele['front_page_cdn_refs'], json_encode( $tele ) );

$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_http']['https://site.test/'] = ok( '<html><link rel="stylesheet" href="/wp-content/themes/x/style.css"></html>' );
assert_test( 'Readable front page without CDN references: HEALTHY', 'HEALTHY' === $adapter->get_health_status() );

$GLOBALS['mock_transients'] = [];
$GLOBALS['mock_http']['https://site.test/'] = ok( '<link href="https://use.fontawesome.com/releases/v5/css/all.css" rel="stylesheet">' );
assert_test( 'Front page with a CDN reference: DEGRADED', 'DEGRADED' === $adapter->get_health_status() );
assert_test( 'Telemetry carries no "AIR-GAPPED" verdict', ! str_contains( json_encode( $adapter->get_telemetry_metrics() ), 'AIR-GAPPED' ) );
assert_test( 'Settings URL points at the registered page slug wm-zero-cdn', 'https://site.test/wp-admin/admin.php?page=wm-zero-cdn' === $adapter->get_settings_url(), (string) $adapter->get_settings_url() );
$self = $adapter->execute_action( 'self_test' );
assert_test( 'self_test still detects the Google Fonts fixture', true === $self['success'] );

// ---- 5. Admin screen and CLI wording ------------------------------------------------------------
echo "\n[GROUP 5] Admin screen and CLI\n";
$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2714}\x{2716}]/u';
$admin_src = code_only( ABSPATH . 'includes/Admin/AdminDashboard.php' );
$cli_src   = code_only( ABSPATH . 'includes/CLI/ZeroCdnCommand.php' );
assert_test( 'Admin screen has no fixed legal or performance verdicts', ! preg_match( '/100% Seguro|100% PROTEGIDO|0 IP-Leaks|Cumple sentencia|\'Activa\'/', $admin_src ) );
assert_test( 'Admin screen uses dashicons, not emoji', 0 === preg_match( $emoji, $admin_src ) );
assert_test( 'The localize result is built with textContent, not innerHTML', ! str_contains( $admin_src, 'innerHTML' ) && str_contains( $admin_src, 'textContent' ) );
assert_test( 'WP-CLI no longer prints "GDPR Compliant"', ! str_contains( $cli_src, 'GDPR Compliant' ) && str_contains( $cli_src, 'scan_front_page' ) );

// ---- 6. Scanner coverage (2026-09-16) -----------------------------------------------------------
echo "\n[GROUP 6] Scanner coverage\n";
$relative_link = $detector->scan_html( "<link rel='stylesheet' href='//fonts.googleapis.com/css?family=Open+Sans'>" );
assert_test(
	'A protocol-relative Google Fonts link is found and reported with a scheme (it produced no finding at all)',
	1 === count( $relative_link ) && 'https://fonts.googleapis.com/css?family=Open+Sans' === $relative_link[0]['url'],
	json_encode( $relative_link )
);
$gstatic = $detector->scan_html( '<link rel="preconnect" href="https://fonts.gstatic.com"><style>@font-face{src:url(https://fonts.gstatic.com/s/inter/v13/a.woff2)}</style>' );
assert_test(
	'fonts.gstatic.com — the host that serves the font files — is found, as a preconnect and as a font URL',
	2 === count( $gstatic ) && 'Google Fonts' === $gstatic[0]['provider'] && 'https://fonts.gstatic.com' === $gstatic[0]['url'],
	json_encode( $gstatic )
);
assert_test(
	'A bare preconnect with no path is a finding (requiring a slash after the host hid it)',
	1 === count( $detector->scan_html( '<link rel="preconnect" href="//fonts.googleapis.com">' ) ),
	json_encode( $detector->scan_html( '<link rel="preconnect" href="//fonts.googleapis.com">' ) )
);
$json_escaped = $detector->scan_html( '<script>var s = "https:\/\/fonts.googleapis.com\/css2?family=Inter";</script>' );
assert_test(
	'A JSON-escaped URL in an inline script is found and unescaped',
	1 === count( $json_escaped ) && 'https://fonts.googleapis.com/css2?family=Inter' === $json_escaped[0]['url'],
	json_encode( $json_escaped )
);
$entity_url = $detector->scan_html( '<link href="https://fonts.googleapis.com/css2?family=Inter&#038;display=swap" rel="stylesheet">' );
assert_test(
	'HTML entities are decoded, so the operator copies the URL the browser requests',
	1 === count( $entity_url ) && str_contains( $entity_url[0]['url'], '&display=swap' ) && ! str_contains( $entity_url[0]['url'], '&#038;' ),
	json_encode( $entity_url )
);
$inline_import = $detector->scan_html( '<style>@import url(https://fonts.googleapis.com/css2?family=Roboto);body{margin:0}</style>' );
assert_test(
	'An unquoted url() stops at the parenthesis instead of swallowing the markup after it',
	1 === count( $inline_import ) && 'https://fonts.googleapis.com/css2?family=Roboto' === $inline_import[0]['url'],
	json_encode( $inline_import )
);
$kit_hosts = $detector->scan_html( '<script src="https://ka-f.fontawesome.com/releases/v6/js/all.js"></script><script src="https://cdn.jsdelivr.net/gh/user/repo/x.js"></script>' );
assert_test( 'The Font Awesome kit host and a jsDelivr /gh/ path are found', 2 === count( $kit_hosts ), json_encode( $kit_hosts ) );
assert_test(
	'A host named in prose, with no URL, is not a finding',
	[] === $detector->scan_html( '<p>We removed fonts.googleapis.com from the theme.</p>' )
);

// ---- 7. Downloader: refusals, url() forms and counting (2026-09-16) ------------------------------
echo "\n[GROUP 7] Downloader refusals and counting\n";
$GLOBALS['mock_http']['https://example.org/page'] = ok( '<!doctype html><title>Hi</title>', 'text/html; charset=utf-8' );
$html_answer = ( new AssetDownloader() )->localize_css( 'https://example.org/page' );
assert_test(
	'A 200 answer that is not CSS is refused instead of being stored as the site stylesheet',
	false === $html_answer['success'] && str_contains( (string) $html_answer['error'], 'text/html' ),
	json_encode( $html_answer )
);
$private_target = ( new AssetDownloader() )->localize_css( 'http://169.254.169.254/latest/meta-data/' );
$other_scheme   = ( new AssetDownloader() )->localize_css( 'file:///etc/passwd' );
assert_test(
	'A link-local address and a non-http scheme are refused before any request leaves the site',
	false === $private_target['success'] && false === $other_scheme['success'] && ! in_array( 'http://169.254.169.254/latest/meta-data/', $GLOBALS['http_calls'], true ),
	json_encode( [ $private_target, $other_scheme ] )
);

// The literal check refused 127.0.0.1 and allowed every other spelling of the same machine. Measured
// on a local WordPress install against the shipped method before the fix: all six came back allowed. The font URLs
// come from the remote stylesheet, so this is not a URL the administrator typed.
$GLOBALS['mock_host_addresses'] = [
	'2130706433'          => [ '127.0.0.1' ],   // gethostbyname() resolves the decimal spelling
	'0177.0.0.1'          => [ '127.0.0.1' ],   // and the octal one
	'127.1'               => [ '127.0.0.1' ],
	'localhost'           => [ '127.0.0.1' ],
	'metadata.internal'   => [ '169.254.169.254' ],
	'nothing.invalid'     => [],                // a name that does not resolve
];
$GLOBALS['http_calls'] = [];
$disguised = [];
foreach ( [ 'http://2130706433/x.css', 'http://0177.0.0.1/x.css', 'http://127.1/x.css', 'http://[::1]/x.css', 'http://[::ffff:127.0.0.1]/x.css', 'http://localhost/x.css', 'http://metadata.internal/x.css' ] as $spelling ) {
	$answer = ( new AssetDownloader() )->localize_css( $spelling );
	if ( false !== ( $answer['success'] ?? null ) ) {
		$disguised[] = $spelling;
	}
}
assert_test(
	'Every spelling of a private address is refused: decimal, octal, shorthand, IPv6, IPv4-mapped, a name that resolves there',
	[] === $disguised && [] === $GLOBALS['http_calls'],
	json_encode( [ 'allowed' => $disguised, 'requests' => $GLOBALS['http_calls'] ] )
);

// The assertion above only fails on PHP 8.2: from 8.3 the private/reserved flags of
// FILTER_VALIDATE_IP reach through the IPv4 mapping by themselves, so on a newer runtime the missing
// normalisation is invisible and the mutation that removes it would survive. This checks the
// normalisation directly, so the defence does not depend on which PHP the suite happens to run under.
$unmap = new ReflectionMethod( AssetDownloader::class, 'unmap_ipv4' );
$mapped = [
	'::ffff:127.0.0.1' => '127.0.0.1',   // loopback written as IPv6
	'::ffff:7f00:1'    => '127.0.0.1',   // the same 16 bytes, hexadecimal spelling
	'::ffff:10.0.0.1'  => '10.0.0.1',    // a private range behind the mapping
	'::1'              => '::1',         // not mapped: unchanged
	'8.8.8.8'          => '8.8.8.8',     // plain IPv4: unchanged
	'not-an-address'   => 'not-an-address',
];
$unmapped_bad = [];
foreach ( $mapped as $input => $expected ) {
	$got = $unmap->invoke( null, (string) $input );
	if ( $got !== $expected ) {
		$unmapped_bad[ $input ] = $got;
	}
}
assert_test(
	'An IPv4-mapped IPv6 address is reduced to its IPv4 form before the private/reserved check',
	[] === $unmapped_bad,
	json_encode( $unmapped_bad )
);

$unresolvable = ( new AssetDownloader() )->localize_css( 'https://nothing.invalid/x.css' );
assert_test(
	'A host name that resolves to nothing is refused, not fetched anyway',
	false === $unresolvable['success'] && str_contains( (string) $unresolvable['error'], 'could not be resolved' ),
	json_encode( $unresolvable )
);

$GLOBALS['mock_http']['https://silent.example/x.css'] = [
	'response' => [ 'code' => 200 ],
	'headers'  => [],
	'body'     => '<script>alert(document.cookie)</script>',
];
$no_type = ( new AssetDownloader() )->localize_css( 'https://silent.example/x.css' );
assert_test(
	'A 200 answer with no content type at all is refused (the check used to skip itself and store the body as CSS)',
	false === $no_type['success'] && str_contains( (string) $no_type['error'], 'without a content type' ),
	json_encode( $no_type )
);
$GLOBALS['mock_host_addresses'] = [];

$dup_css  = 'https://fonts.example/dup.css';
$dup_font = 'https://fonts.example/f.woff2';
$GLOBALS['mock_http'][ $dup_css ]  = ok( "a{src:url({$dup_font})}b{src:url('{$dup_font}')}c{src:url( \"{$dup_font}\" )}" );
$GLOBALS['mock_http'][ $dup_font ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$GLOBALS['http_calls']             = [];
$dup_result  = ( new AssetDownloader() )->localize_css( $dup_css );
$dup_on_disk = (string) file_get_contents( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . basename( (string) $dup_result['local_css_url'] ) );
assert_test(
	'The same font written three ways is fetched once, stored once and counted once (the counter counted every occurrence)',
	true === $dup_result['success'] && 1 === count( $dup_result['downloaded_fonts'] )
		&& 1 === count( array_filter( $GLOBALS['http_calls'], static fn( $u ) => $u === $dup_font ) ),
	json_encode( [ $dup_result, $GLOBALS['http_calls'] ] )
);
assert_test(
	'All three spellings are rewritten to the local copy (quoted and padded ones stayed remote)',
	3 === substr_count( $dup_on_disk, '/wm-zero-cdn/font_' ) && ! str_contains( $dup_on_disk, $dup_font ),
	$dup_on_disk
);

$rel_css  = 'https://fonts.example/css/theme.css';
$rel_font = 'https://fonts.example/css/../fonts/rel.woff2';
$GLOBALS['mock_http'][ $rel_css ]  = ok( '@font-face{src:url(../fonts/rel.woff2)}' );
$GLOBALS['mock_http'][ $rel_font ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$rel_result = ( new AssetDownloader() )->localize_css( $rel_css );
assert_test(
	'A relative url() is resolved against the stylesheet and localized (it was left pointing at uploads, where nothing was)',
	true === $rel_result['success'] && 1 === count( $rel_result['downloaded_fonts'] ),
	json_encode( $rel_result )
);

// A directory carrying the file name is what a failed write looks like from the outside: nothing
// stored, and the answer used to claim the font was local.
$blocked_css   = 'https://fonts.example/blocked.css';
$blocked_font  = 'https://fonts.example/blocked.woff2';
$blocked_name  = 'font_' . substr( hash( 'sha256', $blocked_font ), 0, 16 ) . '.woff2';
$GLOBALS['mock_http'][ $blocked_css ]  = ok( "a{src:url({$blocked_font})}" );
$GLOBALS['mock_http'][ $blocked_font ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
@mkdir( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . $blocked_name, 0777, true );
// The failed write emits the warning PHP emits for it; the point of the case is what the answer says.
$blocked_result = @( new AssetDownloader() )->localize_css( $blocked_css );
@rmdir( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . $blocked_name );
assert_test(
	'A font that could not be written is listed as failed, not reported as downloaded',
	true === $blocked_result['success'] && [] === $blocked_result['downloaded_fonts'] && [ $blocked_font ] === $blocked_result['failed_fonts'],
	json_encode( $blocked_result )
);
assert_test(
	'The download folder carries an index.php, so a server with directory indexing lists nothing',
	is_file( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/index.php' )
);

// ---- 8. Preload (2026-09-16) --------------------------------------------------------------------
echo "\n[GROUP 8] Preload\n";
foreach ( [ 'b.woff2', 'c.woff2', 'd.woff2' ] as $planted ) {
	file_put_contents( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . $planted, 'wOF2' );
}
$GLOBALS['mock_options']['wm_zero_cdn_preload_enabled'] = true;
// The entries that must be filtered out stand before the three real files, so the cap cannot hide them.
$GLOBALS['mock_options']['wm_zero_cdn_preload_fonts']   = [ 'a.ttf', 'missing.woff2', 'b.woff2', 'c.woff2', 'd.woff2' ];
ob_start();
( new PreloadInjector() )->inject_preload_tags();
$preload_tags = (string) ob_get_clean();
assert_test(
	'Three real WOFF2 files are preloaded although a .ttf stands first (slicing before filtering dropped one)',
	3 === substr_count( $preload_tags, 'rel="preload"' ) && str_contains( $preload_tags, 'd.woff2' ),
	$preload_tags
);
assert_test( 'A listed file that is not in uploads is not preloaded', ! str_contains( $preload_tags, 'missing.woff2' ), $preload_tags );

// ---- 9. REST arguments and status (2026-09-16) --------------------------------------------------
echo "\n[GROUP 9] REST\n";
$GLOBALS['rest_routes'] = [];
( new RestController() )->register_routes();
$localize_args = $GLOBALS['rest_routes']['wm-zero-cdn/v1/localize']['args'] ?? [];
assert_test(
	'The localize route declares url as a required string in URI format (an array url reached the downloader as "Array")',
	'string' === ( $localize_args['url']['type'] ?? '' ) && 'uri' === ( $localize_args['url']['format'] ?? '' ) && true === ( $localize_args['url']['required'] ?? false ),
	json_encode( $localize_args )
);
$failing_request = new WP_REST_Request();
$failing_request->set_param( 'url', 'https://fonts.googleapis.com/css2?family=Missing' );
$failing_response = ( new RestController() )->handle_localize( $failing_request );
assert_test(
	'A download that failed answers 502 instead of 200 (nothing watching status codes could see it)',
	502 === $failing_response->get_status(),
	'status=' . $failing_response->get_status()
);

// ---- 10. Admin script and uninstaller (2026-09-16) ----------------------------------------------
echo "\n[GROUP 10] Admin script and uninstaller\n";
assert_test(
	'The admin download script has a failure path for the request and for a body that is not JSON',
	2 === substr_count( code_only( ABSPATH . 'includes/Admin/AdminDashboard.php' ), '.catch(' ),
	'catch handlers: ' . substr_count( code_only( ABSPATH . 'includes/Admin/AdminDashboard.php' ), '.catch(' )
);
$uninstall_src = (string) file_get_contents( ABSPATH . 'uninstall.php' );
assert_test(
	'The uninstaller prefixes the variables it defines in the global scope',
	! preg_match( '/^\$(options|option|upload_dir|local_font_dir|files|file)\b/m', $uninstall_src ) && str_contains( $uninstall_src, '$wm_zero_cdn_options' ),
	'unprefixed globals in uninstall.php'
);

// ---- 11. Settings, lifecycle hooks, Block Bindings & GenerateBlocks -----------------------------
echo "\n[GROUP 11] Settings, lifecycle hooks & block integrations\n";
$GLOBALS['registered_settings'] = [];
AdminDashboard::instance()->register_settings();
assert_test(
	'register_settings registers preload options and opt-in wm_zero_cdn_delete_data_on_uninstall with default 0',
	isset( $GLOBALS['registered_settings']['wm_zero_cdn_preload_enabled'] )
		&& isset( $GLOBALS['registered_settings']['wm_zero_cdn_preload_fonts'] )
		&& isset( $GLOBALS['registered_settings']['wm_zero_cdn_delete_data_on_uninstall'] )
		&& 0 === ( $GLOBALS['registered_settings']['wm_zero_cdn_delete_data_on_uninstall']['args']['default'] ?? null ),
	json_encode( array_keys( $GLOBALS['registered_settings'] ) )
);
$sanitized_fonts = AdminDashboard::sanitize_preload_fonts( "../inter.woff2\nignore.ttf\ninter.woff2\nroboto-bold.woff2\n" );
assert_test(
	'sanitize_preload_fonts keeps distinct .woff2 basenames and drops non-WOFF2 entries',
	[ 'inter.woff2', 'roboto-bold.woff2' ] === $sanitized_fonts,
	json_encode( $sanitized_fonts )
);

$GLOBALS['fired_actions'] = [];
Plugin::instance()->boot();
assert_test(
	'Plugin::boot() fires the wm_zero_cdn_ready action with the Plugin instance',
	1 === count( $GLOBALS['fired_actions']['wm_zero_cdn_ready'] ?? [] )
		&& ( $GLOBALS['fired_actions']['wm_zero_cdn_ready'][0][0] ?? null ) instanceof Plugin,
	json_encode( array_keys( $GLOBALS['fired_actions'] ) )
);

$GLOBALS['registered_block_bindings']    = [];
GenerateBlocks_Register_Dynamic_Tag::$tags = [];
$GLOBALS['registered_filters']['generateblocks_dynamic_tag_replacement'] = [];
$GLOBALS['registered_filters']['generateblocks_dynamic_tag_output']      = [];
$integrations = new BlockIntegrations();
$integrations->boot();
$integrations->register_block_bindings();
$integrations->register_generateblocks_tags();
assert_test(
	'BlockIntegrations registers the wm-zero-cdn/status Block Bindings source',
	isset( $GLOBALS['registered_block_bindings']['wm-zero-cdn/status'] ),
	json_encode( array_keys( $GLOBALS['registered_block_bindings'] ) )
);
assert_test(
	'BlockIntegrations registers the wm_zero_cdn_status GenerateBlocks 2.x Dynamic Tag',
	isset( GenerateBlocks_Register_Dynamic_Tag::$tags['wm_zero_cdn_status'] )
		&& [] === ( $GLOBALS['registered_filters']['generateblocks_dynamic_tag_output'] ?? [] ),
	json_encode( array_keys( GenerateBlocks_Register_Dynamic_Tag::$tags ) )
);

$GLOBALS['mock_transients'] = [];
$GLOBALS['http_calls']      = [];
assert_test(
	'With no cached scan, status binding reports unverified (never clean) and makes zero HTTP calls',
	'unverified' === $integrations->get_binding_value( [ 'key' => 'state' ] )
		&& '—' === $integrations->get_binding_value( [ 'key' => 'cdn_refs' ] )
		&& 'unverified' === apply_filters( 'generateblocks_dynamic_tag_replacement', '', [ 'tag' => 'wm_zero_cdn_status', 'options' => [ 'field' => 'state' ] ] )
		&& 'Post Title' === apply_filters( 'generateblocks_dynamic_tag_output', 'Post Title', [ 'source' => 'post' ], 'Post Title' )
		&& 'Post Title' === $integrations->filter_generateblocks_tag_output( 'Post Title', [ 'source' => 'post' ], 'Post Title' )
		&& [] === $GLOBALS['http_calls'],
	json_encode( [ $integrations->status_snapshot(), $GLOBALS['http_calls'] ] )
);

$GLOBALS['mock_transients'][ CdnDetector::FRONT_SCAN_TRANSIENT ] = [
	'ok'         => true,
	'url'        => 'https://site.test/',
	'error'      => null,
	'found'      => [],
	'scanned_at' => time(),
];
assert_test(
	'With a clean cached scan, status binding and GenerateBlocks tag report clean and 0 references',
	'clean' === $integrations->get_binding_value( [ 'key' => 'state' ] )
		&& '0' === $integrations->render_generateblocks_tag( [ 'field' => 'cdn_refs' ] ),
	json_encode( $integrations->status_snapshot() )
);

$GLOBALS['mock_transients'][ CdnDetector::FRONT_SCAN_TRANSIENT ] = [
	'ok'         => true,
	'url'        => 'https://site.test/',
	'error'      => null,
	'found'      => [ [ 'provider' => 'Google Fonts', 'url' => 'https://example.test/a', 'host' => 'example.test' ] ],
	'scanned_at' => time(),
];
assert_test(
	'With a cached scan holding references, status binding reports references_found and count 1',
	'references_found' === $integrations->get_binding_value( [ 'key' => 'state' ] )
		&& '1' === $integrations->get_binding_value( [ 'key' => 'cdn_refs' ] ),
	json_encode( $integrations->status_snapshot() )
);

// ---- 12. Font host allowlist & DNS pinning (R2-B7) ----------------------------------------------
echo "\n[GROUP 12] Font host allowlist & DNS pinning\n";
$GLOBALS['registered_filters']  = [];
$GLOBALS['mock_host_addresses'] = [];

$all_default_allowed = true;
foreach ( AssetDownloader::DEFAULT_ALLOWED_FONT_HOSTS as $allowed_host ) {
	if ( ! AssetDownloader::is_allowed_font_host( 'https://' . $allowed_host . '/font.woff2', 'https://fonts.googleapis.com/css2?family=Inter' ) ) {
		$all_default_allowed = false;
	}
}
assert_test(
	'is_allowed_font_host permits same-host, all DEFAULT_ALLOWED_FONT_HOSTS, and refuses untrusted or lookalike hosts',
	$all_default_allowed
		&& AssetDownloader::is_allowed_font_host( 'https://fonts.example/a.woff2', 'https://fonts.example/style.css' )
		&& ! AssetDownloader::is_allowed_font_host( 'https://evil.example/payload.woff2', 'https://fonts.googleapis.com/css2?family=Inter' )
		&& ! AssetDownloader::is_allowed_font_host( 'https://fonts.gstatic.com.evil.example/payload.woff2', 'https://fonts.googleapis.com/css2?family=Inter' )
		&& ! AssetDownloader::is_allowed_font_host( '/relative.woff2', 'https://fonts.googleapis.com/css2?family=Inter' ),
	'allowlist helper failed'
);

$allow_css   = 'https://fonts.googleapis.com/css2?family=AllowlistMix';
$allow_good  = 'https://fonts.gstatic.com/s/allowlist/v1/ok.woff2';
$allow_evil  = 'https://evil.example/payload.woff2';
$GLOBALS['mock_http'][ $allow_css ]  = ok( "@font-face{src:url({$allow_good})}@font-face{src:url({$allow_evil})}" );
$GLOBALS['mock_http'][ $allow_good ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$GLOBALS['mock_http'][ $allow_evil ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$GLOBALS['http_calls']               = [];
$allow_result  = ( new AssetDownloader() )->localize_css( $allow_css );
$allow_on_disk = is_file( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . basename( (string) ( $allow_result['local_css_url'] ?? '' ) ) )
	? (string) file_get_contents( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/' . basename( (string) $allow_result['local_css_url'] ) )
	: '';
assert_test(
	'Untrusted external font hosts in CSS are refused into failed_fonts without making HTTP requests',
	true === $allow_result['success']
		&& 1 === count( $allow_result['downloaded_fonts'] )
		&& [ $allow_evil ] === $allow_result['failed_fonts']
		&& ! in_array( $allow_evil, $GLOBALS['http_calls'], true )
		&& str_contains( $allow_on_disk, $allow_evil )
		&& ! str_contains( $allow_on_disk, $allow_good ),
	json_encode( [ $allow_result, $GLOBALS['http_calls'] ] )
);

$GLOBALS['registered_filters']['wm_zero_cdn_allowed_font_hosts'] = [
	static function ( array $hosts, string $stylesheet_url ): array {
		$hosts[] = 'Custom-CDN.example';
		return $hosts;
	},
];
$filtered_css  = 'https://fonts.googleapis.com/css2?family=FilteredHost';
$filtered_font = 'https://custom-cdn.example/custom.woff2';
$GLOBALS['mock_http'][ $filtered_css ]  = ok( "@font-face{src:url({$filtered_font})}" );
$GLOBALS['mock_http'][ $filtered_font ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$GLOBALS['http_calls']                  = [];
$filtered_result                        = ( new AssetDownloader() )->localize_css( $filtered_css );
$GLOBALS['registered_filters']          = [];
assert_test(
	'wm_zero_cdn_allowed_font_hosts filter allows an operator-approved CDN host (case-insensitive)',
	true === $filtered_result['success']
		&& 1 === count( $filtered_result['downloaded_fonts'] )
		&& [] === $filtered_result['failed_fonts']
		&& in_array( $filtered_font, $GLOBALS['http_calls'], true ),
	json_encode( [ $filtered_result, $GLOBALS['http_calls'] ] )
);

$GLOBALS['mock_host_addresses'] = [ 'rebind.example' => [ '198.51.100.10' ] ];
$GLOBALS['captured_curl_opts']  = [];
$GLOBALS['registered_actions']  = [];
$pinned_css                     = 'https://rebind.example/pinned.css';
$pinned_font                    = 'https://rebind.example/pinned.woff2';
$GLOBALS['mock_http'][ $pinned_css ]  = ok( "@font-face{src:url({$pinned_font})}" );
$GLOBALS['mock_http'][ $pinned_font ] = ok( 'wOF2' . str_repeat( "\0", 60 ) );
$pinned_result                        = ( new AssetDownloader() )->localize_css( $pinned_css );
$pinned_resolve_entries               = [];
foreach ( $GLOBALS['captured_curl_opts'] as $call ) {
	if ( defined( 'CURLOPT_RESOLVE' ) && CURLOPT_RESOLVE === $call['option'] ) {
		$pinned_resolve_entries[] = $call['value'];
	}
}
assert_test(
	'remote_get_pinned pins CURLOPT_RESOLVE via http_api_curl and removes its hook in finally',
	true === $pinned_result['success']
		&& [ [ 'rebind.example:443:198.51.100.10' ], [ 'rebind.example:443:198.51.100.10' ] ] === $pinned_resolve_entries
		&& [] === ( $GLOBALS['registered_actions']['http_api_curl'] ?? [] ),
	json_encode( [ 'resolve' => $pinned_resolve_entries, 'hooks_left' => count( $GLOBALS['registered_actions']['http_api_curl'] ?? [] ) ] )
);

$remote_get_pinned = new ReflectionMethod( AssetDownloader::class, 'remote_get_pinned' );
$GLOBALS['captured_curl_opts'] = [];
$remote_get_pinned->invoke( null, 'http://rebind.example/plain.css', [] );
$remote_get_pinned->invoke( null, 'https://rebind.example:8443/custom-port.css', [] );
$port_entries = [];
foreach ( $GLOBALS['captured_curl_opts'] as $call ) {
	if ( defined( 'CURLOPT_RESOLVE' ) && CURLOPT_RESOLVE === $call['option'] ) {
		$port_entries[] = $call['value'];
	}
}
assert_test(
	'remote_get_pinned pins the exact port (80 for http, explicit port when supplied)',
	[ [ 'rebind.example:80:198.51.100.10' ], [ 'rebind.example:8443:198.51.100.10' ] ] === $port_entries,
	json_encode( $port_entries )
);

$GLOBALS['http_calls'] = [];
$direct_refusal        = $remote_get_pinned->invoke( null, 'http://127.0.0.1/direct.css', [] );
assert_test(
	'remote_get_pinned refuses unsafe resolved targets before calling wp_safe_remote_get',
	is_wp_error( $direct_refusal ) && [] === $GLOBALS['http_calls'],
	json_encode( [ 'error' => is_wp_error( $direct_refusal ) ? $direct_refusal->get_error_message() : $direct_refusal, 'calls' => $GLOBALS['http_calls'] ] )
);

$flip_lookups = 0;
$GLOBALS['registered_filters']['wm_zero_cdn_host_addresses'] = [
	static function ( $addresses, string $host ) use ( &$flip_lookups ) {
		if ( 'flip.example' === $host ) {
			++$flip_lookups;
			return 1 === $flip_lookups ? [ '198.51.100.20' ] : [ '127.0.0.1' ];
		}
		return $addresses;
	},
];
$GLOBALS['http_calls'] = [];
$rebound               = ( new AssetDownloader() )->localize_css( 'https://flip.example/rebound.css' );
$GLOBALS['registered_filters']  = [];
$GLOBALS['mock_host_addresses'] = [];
assert_test(
	'DNS rebinding between refuse_url() and remote_get_pinned() is refused without making an HTTP call',
	false === $rebound['success']
		&& str_contains( (string) ( $rebound['error'] ?? '' ), 'private, loopback or reserved' )
		&& [] === $GLOBALS['http_calls'],
	json_encode( [ $rebound, $GLOBALS['http_calls'] ] )
);

// ---- cleanup ------------------------------------------------------------------------------------
foreach ( glob( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn/*' ) ?: [] as $f ) {
	@unlink( $f );
}
@rmdir( $GLOBALS['wm_test_uploads'] . '/wm-zero-cdn' );
@rmdir( $GLOBALS['wm_test_uploads'] );

echo "\n============================================================\n";
echo " TEST RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
echo "============================================================\n\n";

exit( 0 === $failed_count ? 0 : 1 );
