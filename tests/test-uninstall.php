<?php
/**
 * Standalone falsifier for uninstall.php — runs without WordPress.
 *
 * Verifies both branches of D-UNINSTALL:
 *   1. By default (`wm_zero_cdn_delete_data_on_uninstall` unset / false), options, transients, and
 *      local WOFF2 files in `uploads/wm-zero-cdn/` are preserved untouched.
 *   2. When `wm_zero_cdn_delete_data_on_uninstall` is explicitly enabled, all plugin options
 *      (site-wide too), the scan transient, the font files, and the emptied directory (through
 *      `WP_Filesystem`) are removed.
 *
 * Run: php tests/test-uninstall.php   (exit 0 = green). Not a WP-CLI suite: never run it inside
 * a live site, it executes the real uninstaller against whatever wp_upload_dir() returns.
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$tmp   = sys_get_temp_dir() . '/wm_zero_cdn_uninstall_' . getmypid();
$fonts = $tmp . '/wm-zero-cdn';
mkdir( $fonts, 0777, true );
file_put_contents( $fonts . '/probe.woff2', 'x' );

define( 'WP_UNINSTALL_PLUGIN', true );
define( 'ABSPATH', $tmp . '/' ); // wp-admin/includes/file.php must not be needed: WP_Filesystem() is stubbed below.

$GLOBALS['wm_test_tmp']            = $tmp;
$GLOBALS['mock_uninstall_options'] = [];
$GLOBALS['deleted_options']        = [];
$GLOBALS['deleted_transients']     = [];

function get_option( string $key, $default = false ) {
	return $GLOBALS['mock_uninstall_options'][ $key ] ?? $default;
}
function get_site_option( string $key, $default = false ) {
	return $GLOBALS['mock_uninstall_options'][ 'site:' . $key ] ?? $default;
}
function delete_option( string $key ): bool {
	$GLOBALS['deleted_options'][] = $key;
	return true;
}
function delete_site_option( string $key ): bool {
	$GLOBALS['deleted_options'][] = 'site:' . $key;
	return true;
}
function wp_upload_dir(): array {
	return [ 'basedir' => $GLOBALS['wm_test_tmp'] ];
}
function wp_delete_file( string $file ): void {
	@unlink( $file );
}
function delete_transient( string $key ): bool {
	$GLOBALS['deleted_transients'][] = $key;
	return true;
}

/** Spy standing in for WP_Filesystem_Direct: records rmdir() calls and performs them. */
final class WM_Spy_Filesystem {
	/** @var array<int, string> */
	public array $rmdir_calls = [];
	public function rmdir( string $dir, bool $recursive = false ): bool {
		$this->rmdir_calls[] = $dir;
		return @rmdir( $dir );
	}
}
function WP_Filesystem(): bool {
	$GLOBALS['wp_filesystem'] = new WM_Spy_Filesystem();
	return true;
}

$passed = 0;
$failed = 0;
function assert_test( string $name, bool $condition, string $details = '' ): void {
	global $passed, $failed;
	if ( $condition ) {
		echo "[PASS] {$name}\n";
		$passed++;
	} else {
		echo "[FAIL] {$name}: {$details}\n";
		$failed++;
	}
}

// Phase 1: default behaviour (opt-in disabled) preserves options, transients, and local font files.
require dirname( __DIR__ ) . '/uninstall.php';

assert_test(
	'default uninstall (without opt-in) deletes no options',
	[] === $GLOBALS['deleted_options'],
	'deleted: ' . implode( ', ', $GLOBALS['deleted_options'] )
);
assert_test(
	'default uninstall (without opt-in) deletes no transients',
	[] === $GLOBALS['deleted_transients'],
	'deleted transients: ' . implode( ', ', $GLOBALS['deleted_transients'] )
);
assert_test(
	'default uninstall (without opt-in) preserves local WOFF2 files and directory',
	is_file( $fonts . '/probe.woff2' ) && is_dir( $fonts ),
	'font file or directory removed without opt-in'
);

// Phase 2: explicit opt-in removes options, transient, font files, and directory via WP_Filesystem.
$GLOBALS['mock_uninstall_options']['wm_zero_cdn_delete_data_on_uninstall'] = '1';
require dirname( __DIR__ ) . '/uninstall.php';

$deleted = $GLOBALS['deleted_options'];
assert_test(
	'opt-in uninstall deletes all three plugin options, site-wide too',
	in_array( 'wm_zero_cdn_preload_enabled', $deleted, true )
		&& in_array( 'wm_zero_cdn_preload_fonts', $deleted, true )
		&& in_array( 'wm_zero_cdn_delete_data_on_uninstall', $deleted, true )
		&& in_array( 'site:wm_zero_cdn_preload_enabled', $deleted, true )
		&& in_array( 'site:wm_zero_cdn_preload_fonts', $deleted, true )
		&& in_array( 'site:wm_zero_cdn_delete_data_on_uninstall', $deleted, true ),
	'deleted: ' . implode( ', ', $deleted )
);
assert_test( 'opt-in uninstall deletes the cached front-page scan', [ 'wm_zero_cdn_front_scan' ] === $GLOBALS['deleted_transients'], 'deleted transients: ' . implode( ', ', $GLOBALS['deleted_transients'] ) );
assert_test( 'opt-in uninstall deletes the planted font file', ! file_exists( $fonts . '/probe.woff2' ) );
assert_test( 'opt-in uninstall removes the emptied font directory', ! is_dir( $fonts ), 'still there: ' . $fonts );
$spy = $GLOBALS['wp_filesystem'] ?? null;
assert_test(
	'the directory is removed through WP_Filesystem (spy saw rmdir on the font directory)',
	$spy instanceof WM_Spy_Filesystem && [ $fonts ] === $spy->rmdir_calls,
	'rmdir calls: ' . ( $spy instanceof WM_Spy_Filesystem ? json_encode( $spy->rmdir_calls ) : 'WP_Filesystem() never called' )
);

@rmdir( $fonts );
@rmdir( $tmp );

echo "\n=== RESULT: {$passed} passed, {$failed} failed ===\n";
exit( 0 === $failed ? 0 : 1 );
