<?php
/**
 * Namespace spy for curl_setopt() in WMCyber\ZeroCDN\Localizer\Downloader so the standalone
 * test suite can verify CURLOPT_RESOLVE DNS pinning without hitting the network.
 */

declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Downloader;

/**
 * Record curl_setopt() calls from AssetDownloader::remote_get_pinned() and forward to PHP's real curl_setopt().
 *
 * @param mixed $handle
 * @param int   $option
 * @param mixed $value
 */
function curl_setopt( $handle, int $option, $value ): bool {
	$GLOBALS['captured_curl_opts'][] = [
		'handle' => $handle,
		'option' => $option,
		'value'  => $value,
	];
	if ( function_exists( '\curl_setopt' ) && ( ( is_object( $handle ) && $handle instanceof \CurlHandle ) || is_resource( $handle ) ) ) {
		return \curl_setopt( $handle, $option, $value );
	}
	return true;
}
