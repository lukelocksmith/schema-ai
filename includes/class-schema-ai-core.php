<?php
/**
 * Core plugin class (singleton).
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Core {

	/**
	 * Singleton instance.
	 */
	private static ?Schema_AI_Core $instance = null;

	/**
	 * Known conflicting schema/SEO plugins.
	 */
	private const CONFLICTING_PLUGINS = array(
		'wordpress-seo/wp-seo.php',
		'seo-by-rank-math/rank-math.php',
		'wp-seopress/seopress.php',
		'all-in-one-seo-pack/all_in_one_seo_pack.php',
		'schema-pro/schema-pro.php',
	);

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Get singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 */
	public function init(): void {
		// Load Action Scheduler if not already available.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$as_file = SCHEMA_AI_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
			if ( file_exists( $as_file ) ) {
				require_once $as_file;
			}
		}

		// Load text domain.
		load_plugin_textdomain( 'schema-ai', false, dirname( SCHEMA_AI_BASENAME ) . '/languages' );

		// Instantiate and init service classes.
		$logger = new Schema_AI_Logger();
		$logger->init();

		$cache = new Schema_AI_Cache();
		$cache->init();

		$generator = new Schema_AI_Generator();
		$generator->init();

		$frontend = new Schema_AI_Frontend();
		$frontend->init();

		if ( is_admin() ) {
			$admin = new Schema_AI_Admin();
			$admin->init();

			$metabox = new Schema_AI_Metabox();
			$metabox->init();
		}

		$bulk = new Schema_AI_Bulk();
		$bulk->init();

		$rest = new Schema_AI_Rest();
		$rest->init();

		// Register WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Schema_AI_CLI::register();
		}

		// Check for DB upgrades.
		$this->maybe_upgrade();
	}

	/**
	 * Plugin activation.
	 */
	public static function activate(): void {
		// Create custom log table.
		Schema_AI_Logger::create_table();

		// Store DB version.
		update_option( 'schema_ai_db_version', SCHEMA_AI_DB_VERSION );

		// Set default options.
		$defaults = array(
			'schema_ai_model'              => 'gemini-2.0-flash',
			'schema_ai_auto_generate'      => 1,
			'schema_ai_post_types'         => array( 'post', 'page' ),
			'schema_ai_publisher_name'     => get_bloginfo( 'name' ),
			'schema_ai_publisher_url'      => home_url(),
			'schema_ai_exclude_categories' => array(),
		);

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				update_option( $option, $value );
			}
		}

		// Detect conflicting plugins.
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$conflicts      = array_intersect( self::CONFLICTING_PLUGINS, $active_plugins );

		if ( ! empty( $conflicts ) ) {
			$conflict_names = implode( ', ', $conflicts );
			set_transient(
				'schema_ai_activation_notice',
				sprintf(
					/* translators: %s: list of conflicting plugin file names */
					__( 'Schema AI detected potentially conflicting plugins: %s. You may want to disable their schema output to avoid duplicates.', 'schema-ai' ),
					$conflict_names
				),
				30
			);
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate(): void {
		// Cancel all Action Scheduler jobs for this plugin.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'schema_ai_generate' );
			as_unschedule_all_actions( 'schema_ai_bulk_generate' );
			as_unschedule_all_actions( 'schema_ai_bulk_process' );
		}

		// Clear all plugin transients.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_schema_ai_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_schema_ai_' ) . '%'
			)
		);
	}

	/**
	 * Check if DB schema needs upgrading.
	 */
	private function maybe_upgrade(): void {
		$stored_version = get_option( 'schema_ai_db_version', '0' );

		if ( version_compare( $stored_version, SCHEMA_AI_DB_VERSION, '<' ) ) {
			Schema_AI_Logger::create_table();
			update_option( 'schema_ai_db_version', SCHEMA_AI_DB_VERSION );
		}
	}

	/**
	 * Encrypt a string using AES-256-CBC.
	 */
	public static function encrypt( string $value ): string {
		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt a string encrypted with encrypt().
	 */
	public static function decrypt( string $value ): string {
		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

		$decoded   = base64_decode( $value, true );
		$decrypted = openssl_decrypt( $decoded, 'aes-256-cbc', $key, 0, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Get the decrypted API key from options.
	 */
	public static function get_api_key(): string {
		$encrypted = get_option( 'schema_ai_api_key', '' );

		if ( empty( $encrypted ) ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}
}
