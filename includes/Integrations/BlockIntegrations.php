<?php
/**
 * WordPress 6.5+ Block Bindings and GenerateBlocks 2.x Dynamic Tag integrations.
 *
 * Exposes measured Zero-CDN status values (`wm-zero-cdn/status` block binding source and
 * `{{wm_zero_cdn_status}}` GenerateBlocks dynamic tag) from cached scan state and local WOFF2
 * files on disk, without triggering loopback HTTP requests during frontend page rendering.
 *
 * @package WMCyber\ZeroCDN\Localizer
 */

declare(strict_types=1);

namespace WMCyber\ZeroCDN\Localizer\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\ZeroCDN\Localizer\Admin\AdminDashboard;
use WMCyber\ZeroCDN\Localizer\Scanner\CdnDetector;

final class BlockIntegrations {
	private bool $gb_registered = false;

	public function boot(): void {
		add_action( 'init', [ $this, 'register_block_bindings' ], 20 );
		add_action( 'init', [ $this, 'register_generateblocks_tags' ], 20 );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_generateblocks_tag_output' ], 10, 2 );
	}

	/**
	 * Register the `wm-zero-cdn/status` Block Bindings source on WordPress 6.5+.
	 */
	public function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-zero-cdn/status',
			[
				'label'              => __( 'WM Zero-CDN Status', 'wm-zero-cdn-asset-localizer' ),
				'get_value_callback' => [ $this, 'get_binding_value' ],
				'uses_context'       => [],
			]
		);
	}

	/**
	 * Register the `{{wm_zero_cdn_status}}` Dynamic Tag when GenerateBlocks 2.x is active.
	 */
	public function register_generateblocks_tags(): void {
		if ( $this->gb_registered || ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		$this->gb_registered = true;

		new \GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'       => __( 'WM Zero-CDN Status', 'wm-zero-cdn-asset-localizer' ),
				'tag'         => 'wm_zero_cdn_status',
				'type'        => 'wm-zero-cdn',
				'supports'    => [],
				'description' => __( 'Gibt den gemessenen CDN-Prüfstatus oder die Anzahl lokaler WOFF2-Dateien aus.', 'wm-zero-cdn-asset-localizer' ),
				'options'     => [
					'field' => [
						'type'    => 'select',
						'label'   => __( 'Feld', 'wm-zero-cdn-asset-localizer' ),
						'default' => 'summary',
						'options' => [
							[
								'value' => 'summary',
								'label' => __( 'Zusammenfassung', 'wm-zero-cdn-asset-localizer' ),
							],
							[
								'value' => 'cdn_refs',
								'label' => __( 'CDN-Verweise', 'wm-zero-cdn-asset-localizer' ),
							],
							[
								'value' => 'local_fonts',
								'label' => __( 'Lokale WOFF2-Dateien', 'wm-zero-cdn-asset-localizer' ),
							],
							[
								'value' => 'state',
								'label' => __( 'Prüfstatus-Kennung', 'wm-zero-cdn-asset-localizer' ),
							],
						],
					],
				],
				'return'      => [ $this, 'render_generateblocks_tag' ],
			]
		);
	}

	/**
	 * Fallback replacement filter for `{{wm_zero_cdn_status}}` in GenerateBlocks dynamic tag pipelines.
	 *
	 * @param mixed $output         Current dynamic tag replacement/output.
	 * @param mixed $tag_data       Parsed GenerateBlocks tag descriptor or legacy tag identifier.
	 * @param mixed $options_or_raw Optional legacy options array or raw scalar output.
	 * @return mixed
	 */
	public function filter_generateblocks_tag_output( mixed $output, mixed $tag_data = '', mixed $options_or_raw = null ): mixed {
		$tag     = '';
		$options = [];
		if ( is_string( $tag_data ) ) {
			$tag     = $tag_data;
			$options = is_array( $options_or_raw ) ? $options_or_raw : [];
		} elseif ( is_array( $tag_data ) ) {
			$tag     = (string) ( $tag_data['tag'] ?? $tag_data['name'] ?? '' );
			$options = isset( $tag_data['options'] ) && is_array( $tag_data['options'] ) ? $tag_data['options'] : [];
		}

		if ( 'wm_zero_cdn_status' === $tag && ( null === $output || '' === $output ) ) {
			return $this->render_generateblocks_tag( $options );
		}
		return $output;
	}

	/**
	 * Block Bindings value callback for `wm-zero-cdn/status`.
	 *
	 * @param array<string, mixed> $source_args    Binding source arguments (`key`: summary|cdn_refs|local_fonts|state).
	 * @param mixed                $block_instance Block instance.
	 * @param string               $attribute_name Bound attribute name.
	 */
	public function get_binding_value( array $source_args, $block_instance = null, string $attribute_name = '' ): string {
		$raw_key = isset( $source_args['key'] ) && is_string( $source_args['key'] ) ? $source_args['key'] : 'summary';
		$field   = self::clean_key( $raw_key );
		return $this->resolve_status_field( '' !== $field ? $field : 'summary' );
	}

	/**
	 * GenerateBlocks 2.x Dynamic Tag return callback for `{{wm_zero_cdn_status}}`.
	 *
	 * @param array<string, mixed> $options Tag options (`field`: summary|cdn_refs|local_fonts|state).
	 * @param mixed                $block   Block data.
	 * @param mixed                $instance Dynamic tag instance.
	 */
	public function render_generateblocks_tag( array $options = [], $block = [], $instance = null ): string {
		$raw_key = isset( $options['field'] ) && is_string( $options['field'] ) ? $options['field'] : 'summary';
		$field   = self::clean_key( $raw_key );
		return esc_html( $this->resolve_status_field( '' !== $field ? $field : 'summary' ) );
	}

	/**
	 * Measured status snapshot from cached scan transient and local WOFF2 directory.
	 *
	 * Never reports an unverified site as clean, and never triggers a loopback HTTP request
	 * during frontend rendering.
	 *
	 * @return array{ok: bool, cdn_refs: ?int, local_fonts: int, state: string, summary: string}
	 */
	public function status_snapshot(): array {
		$cached      = get_transient( CdnDetector::FRONT_SCAN_TRANSIENT );
		$local_fonts = AdminDashboard::local_font_count();

		if ( ! is_array( $cached ) || empty( $cached['ok'] ) || ! isset( $cached['found'] ) || ! is_array( $cached['found'] ) ) {
			return [
				'ok'          => false,
				'cdn_refs'    => null,
				'local_fonts' => $local_fonts,
				'state'       => 'unverified',
				'summary'     => __( 'Startseite noch nicht geprüft', 'wm-zero-cdn-asset-localizer' ),
			];
		}

		$refs = count( $cached['found'] );
		if ( 0 === $refs ) {
			return [
				'ok'          => true,
				'cdn_refs'    => 0,
				'local_fonts' => $local_fonts,
				'state'       => 'clean',
				'summary'     => __( 'Keine CDN-Verweise auf der Startseite gefunden', 'wm-zero-cdn-asset-localizer' ),
			];
		}

		return [
			'ok'          => true,
			'cdn_refs'    => $refs,
			'local_fonts' => $local_fonts,
			'state'       => 'references_found',
			/* translators: %d: number of CDN references found on the front page */
			'summary'     => sprintf( _n( '%d CDN-Verweis auf der Startseite', '%d CDN-Verweise auf der Startseite', $refs, 'wm-zero-cdn-asset-localizer' ), $refs ),
		];
	}

	/**
	 * Resolve a single status field and pass it through `wm_zero_cdn_status_value`.
	 */
	public function resolve_status_field( string $field ): string {
		$snapshot = $this->status_snapshot();

		switch ( $field ) {
			case 'cdn_refs':
				$value = null !== $snapshot['cdn_refs'] ? (string) $snapshot['cdn_refs'] : '—';
				break;
			case 'local_fonts':
				$value = (string) $snapshot['local_fonts'];
				break;
			case 'state':
				$value = $snapshot['state'];
				break;
			case 'summary':
			default:
				$value = $snapshot['summary'];
				break;
		}

		return (string) apply_filters( 'wm_zero_cdn_status_value', $value, $field, $snapshot );
	}

	private static function clean_key( string $key ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $key );
		}
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
