<?php
/**
 * Plugin Name:       WM Zero-CDN Asset Localizer & Font Sovereignty Engine
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-zero-cdn-asset-localizer/
 * Description:       Checks the front page for Google Fonts, Font Awesome, cdnjs, jsDelivr and unpkg references and downloads a Google Fonts stylesheet with its WOFF2 files to the site's own uploads folder. Replacing the remote link in the theme stays a manual step.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-zero-cdn-asset-localizer
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WM_ZERO_CDN_VERSION', '1.2.0' );
define( 'WM_ZERO_CDN_FILE', __FILE__ );
define( 'WM_ZERO_CDN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WM_ZERO_CDN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix   = 'WMCyber\\ZeroCDN\\Localizer\\';
	$base_dir = WM_ZERO_CDN_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WMCyber\ZeroCDN\Localizer\Adapters\Zero_Cdn_Spoke_Adapter' ) && file_exists( WM_ZERO_CDN_PATH . 'includes/Adapters/Zero_Cdn_Spoke_Adapter.php' ) ) {
		require_once WM_ZERO_CDN_PATH . 'includes/Adapters/Zero_Cdn_Spoke_Adapter.php';
	}
	if ( class_exists( 'WMCyber\ZeroCDN\Localizer\Adapters\Zero_Cdn_Spoke_Adapter' ) ) {
		$modules[] = new Adapters\Zero_Cdn_Spoke_Adapter();
	}
	return $modules;
} );

// Bootstrap
add_action( 'plugins_loaded', function (): void {
	Core\Plugin::instance()->boot();
} );

