<?php
/**
 * Admin Settings and Menu.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Admin {

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'conflict_notice' ) );
		add_action( 'wp_ajax_schema_ai_test_key', array( $this, 'ajax_test_key' ) );
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'Schema AI', 'schema-ai' ),
			__( 'Schema AI', 'schema-ai' ),
			'manage_options',
			'schema-ai',
			array( $this, 'render_dashboard' ),
			'dashicons-code-standards',
			80
		);

		add_submenu_page(
			'schema-ai',
			__( 'Dashboard', 'schema-ai' ),
			__( 'Dashboard', 'schema-ai' ),
			'manage_options',
			'schema-ai',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'schema-ai',
			__( 'Bulk Generate', 'schema-ai' ),
			__( 'Bulk Generate', 'schema-ai' ),
			'manage_options',
			'schema-ai-bulk',
			array( $this, 'render_bulk' )
		);

		add_submenu_page(
			'schema-ai',
			__( 'Settings', 'schema-ai' ),
			__( 'Settings', 'schema-ai' ),
			'manage_options',
			'schema-ai-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		register_setting( 'schema_ai_settings', 'schema_ai_api_key', array(
			'sanitize_callback' => array( $this, 'sanitize_api_key' ),
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_model', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_auto_generate', array(
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_post_types', array(
			'sanitize_callback' => array( $this, 'sanitize_post_types' ),
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_publisher_name', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_publisher_url', array(
			'sanitize_callback' => 'esc_url_raw',
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_publisher_logo', array(
			'sanitize_callback' => 'esc_url_raw',
		) );

		register_setting( 'schema_ai_settings', 'schema_ai_exclude_categories', array(
			'sanitize_callback' => array( $this, 'sanitize_exclude_categories' ),
		) );
	}

	/**
	 * Sanitize API key — preserve existing if masked value submitted.
	 *
	 * @param string $value Submitted value.
	 * @return string Encrypted API key.
	 */
	public function sanitize_api_key( string $value ): string {
		if ( '••••••••' === $value ) {
			return get_option( 'schema_ai_api_key', '' );
		}

		return Schema_AI_Core::encrypt( $value );
	}

	/**
	 * Sanitize checkbox to '1' or '0'.
	 *
	 * @param mixed $value Submitted value.
	 * @return string '1' or '0'.
	 */
	public function sanitize_checkbox( mixed $value ): string {
		return $value ? '1' : '0';
	}

	/**
	 * Sanitize post types array.
	 *
	 * @param mixed $value Submitted value.
	 * @return array Sanitized post types.
	 */
	public function sanitize_post_types( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Sanitize exclude categories array.
	 *
	 * @param mixed $value Submitted value.
	 * @return array Sanitized category IDs.
	 */
	public function sanitize_exclude_categories( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_map( 'absint', $value );
	}

	/**
	 * Enqueue admin assets on relevant pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$is_plugin_page = str_contains( $hook, 'schema-ai' );
		$is_edit_post   = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_plugin_page && ! $is_edit_post ) {
			return;
		}

		wp_enqueue_style(
			'schema-ai-admin',
			SCHEMA_AI_URL . 'admin/css/admin.css',
			array(),
			SCHEMA_AI_VERSION
		);

		wp_enqueue_script(
			'schema-ai-admin',
			SCHEMA_AI_URL . 'admin/js/admin.js',
			array(),
			SCHEMA_AI_VERSION,
			true
		);

		wp_localize_script( 'schema-ai-admin', 'schemaAI', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'restUrl'   => rest_url( 'schema-ai/v1/' ),
			'nonce'     => wp_create_nonce( 'schema_ai_nonce' ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Show admin notice if conflicting plugins detected.
	 */
	public function conflict_notice(): void {
		$conflicts = get_option( 'schema_ai_conflicts', array() );

		if ( empty( $conflicts ) ) {
			return;
		}

		$conflict_names = implode( ', ', $conflicts );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Schema AI:', 'schema-ai' ); ?></strong>
				<?php
				printf(
					/* translators: %s: list of conflicting plugin names */
					esc_html__( 'Potentially conflicting plugins detected: %s. You may want to disable their schema output to avoid duplicates.', 'schema-ai' ),
					esc_html( $conflict_names )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard(): void {
		$stats     = $this->get_overview_stats();
		$log       = Schema_AI_Logger::get_recent( 20 );
		$api_stats = Schema_AI_Logger::get_stats();

		include SCHEMA_AI_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings(): void {
		include SCHEMA_AI_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the bulk generate page.
	 */
	public function render_bulk(): void {
		include SCHEMA_AI_DIR . 'admin/views/bulk.php';
	}

	/**
	 * Get overview statistics for the dashboard.
	 *
	 * @return array{total: int, with_schema: int, without: int, errors: int, types: array}
	 */
	public function get_overview_stats(): array {
		global $wpdb;

		$enabled_types = get_option( 'schema_ai_post_types', array( 'post', 'page' ) );

		if ( empty( $enabled_types ) ) {
			return array(
				'total'       => 0,
				'with_schema' => 0,
				'without'     => 0,
				'errors'      => 0,
				'types'       => array(),
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $enabled_types ), '%s' ) );

		// Count total published posts of enabled types.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish'",
				...$enabled_types
			)
		);

		// Count posts with schema (status = auto, manual, or edited).
		$with_schema = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_schema_ai_status'
				WHERE p.post_type IN ({$placeholders}) AND p.post_status = 'publish'
				AND pm.meta_value IN ('auto', 'manual', 'edited')",
				...$enabled_types
			)
		);

		// Count posts with errors.
		$errors = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_schema_ai_status'
				WHERE p.post_type IN ({$placeholders}) AND p.post_status = 'publish'
				AND pm.meta_value = 'error'",
				...$enabled_types
			)
		);

		$without = $total - $with_schema - $errors;

		// Get type breakdown.
		$type_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS schema_type, COUNT(*) AS count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_schema_ai_type'
				WHERE p.post_type IN ({$placeholders}) AND p.post_status = 'publish'
				AND pm.meta_value != ''
				GROUP BY pm.meta_value
				ORDER BY count DESC",
				...$enabled_types
			),
			ARRAY_A
		);

		$types = array();
		if ( is_array( $type_results ) ) {
			foreach ( $type_results as $row ) {
				$types[ $row['schema_type'] ] = (int) $row['count'];
			}
		}

		return array(
			'total'       => $total,
			'with_schema' => $with_schema,
			'without'     => $without,
			'errors'      => $errors,
			'types'       => $types,
		);
	}

	/**
	 * AJAX: Test API key by sending a simple request to Gemini.
	 */
	public function ajax_test_key(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$api_key = Schema_AI_Core::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'No API key configured. Save settings first.', 'schema-ai' ) ) );
		}

		$model = get_option( 'schema_ai_model', 'gemini-2.5-flash' );
		$url   = Schema_AI_Gemini::API_BASE . $model . ':generateContent';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'contents'         => array( array( 'parts' => array( array( 'text' => 'Return: {"test": true}' ) ) ) ),
						'generationConfig' => array( 'maxOutputTokens' => 20, 'responseMimeType' => 'application/json' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			wp_send_json_success( array( 'message' => sprintf( __( 'API key works! Model: %s', 'schema-ai' ), $model ) ) );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = $body['error']['message'] ?? "HTTP {$code}";
			wp_send_json_error( array( 'message' => $msg ) );
		}
	}
}
