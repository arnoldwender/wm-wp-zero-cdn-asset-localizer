<?php
/**
 * Stand-in for the WM Suite Hub module contract, so the spoke adapter can be loaded without the hub.
 * Mirrors wm-suite-hub/src/Contracts/WM_Plugin_Module_Interface.php.
 */

declare(strict_types=1);

namespace ArnoldWender\SuiteHub\Contracts;

if ( ! interface_exists( __NAMESPACE__ . '\WM_Plugin_Module_Interface' ) ) {
	interface WM_Plugin_Module_Interface {
		public function get_module_slug(): string;
		public function get_name(): string;
		public function get_version(): string;
		public function get_description(): string;
		public function get_health_status(): string;
		public function get_sbom_data(): array;
		public function get_dependencies(): array;
		public function get_settings_url(): ?string;
		public function get_telemetry_metrics(): array;
		public function execute_action( string $action, array $params = [] ): array;
	}
}
