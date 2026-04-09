<?php
/**
 * Plugin Name: Schema AI
 * Plugin URI: https://github.com/lukaszek/schema-ai
 * Description: AI-powered Schema.org structured data generator for WordPress.
 * Version: 1.1.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Lukasz Slusarski
 * Author URI: https://important.is
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: schema-ai
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'SCHEMA_AI_VERSION', '1.1.1' );
define( 'SCHEMA_AI_DB_VERSION', '1.0' );
define( 'SCHEMA_AI_FILE', __FILE__ );
define( 'SCHEMA_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCHEMA_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'SCHEMA_AI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for Schema_AI_ prefixed classes.
 *
 * Looks in includes/ and admin/ directories for files named
 * class-schema-ai-{lowercase}.php
 */
spl_autoload_register( function ( string $class_name ): void {
	// Only handle Schema_AI_ prefixed classes.
	if ( 0 !== strpos( $class_name, 'Schema_AI_' ) ) {
		return;
	}

	// Convert class name to file name: Schema_AI_Core -> class-schema-ai-core.php
	$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

	$directories = array(
		SCHEMA_AI_DIR . 'includes/',
		SCHEMA_AI_DIR . 'admin/',
	);

	foreach ( $directories as $directory ) {
		$file_path = $directory . $file_name;
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}
} );

// Activation hook.
register_activation_hook( __FILE__, array( 'Schema_AI_Core', 'activate' ) );

// Deactivation hook.
register_deactivation_hook( __FILE__, array( 'Schema_AI_Core', 'deactivate' ) );

// Initialize plugin on plugins_loaded.
add_action( 'plugins_loaded', function (): void {
	Schema_AI_Core::instance()->init();
} );
