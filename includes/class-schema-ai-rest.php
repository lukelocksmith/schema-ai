<?php
/**
 * REST API — schema-ai/v1 namespace routes.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Rest {

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'schema-ai/v1';

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// Single post schema CRUD.
		register_rest_route( self::NAMESPACE, '/schema/(?P<post_id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => array( $this, 'check_post_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_schema' ),
				'permission_callback' => array( $this, 'check_post_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_schema' ),
				'permission_callback' => array( $this, 'check_post_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_schema' ),
				'permission_callback' => array( $this, 'check_post_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
				),
			),
		) );

		// Stats overview.
		register_rest_route( self::NAMESPACE, '/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_stats' ),
			'permission_callback' => array( $this, 'check_manage_options' ),
		) );

		// Paginated log.
		register_rest_route( self::NAMESPACE, '/log', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_log' ),
			'permission_callback' => array( $this, 'check_manage_options' ),
			'args'                => array(
				'limit'  => array(
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// Bulk operations.
		register_rest_route( self::NAMESPACE, '/bulk/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'bulk_start' ),
			'permission_callback' => array( $this, 'check_manage_options' ),
		) );

		register_rest_route( self::NAMESPACE, '/bulk/cancel', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'bulk_cancel' ),
			'permission_callback' => array( $this, 'check_manage_options' ),
		) );

		register_rest_route( self::NAMESPACE, '/bulk/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'bulk_status' ),
			'permission_callback' => array( $this, 'check_manage_options' ),
		) );
	}

	/**
	 * GET /schema/{post_id} — return schema data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_schema( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );

		$schema_json = get_post_meta( $post_id, '_schema_ai_data', true );
		$status      = get_post_meta( $post_id, '_schema_ai_status', true ) ?: 'none';
		$type        = get_post_meta( $post_id, '_schema_ai_type', true ) ?: '';

		$schema     = $schema_json ? json_decode( $schema_json, true ) : null;
		$validation = $schema ? Schema_AI_Validator::validate( $schema ) : null;

		return new WP_REST_Response( array(
			'post_id'    => $post_id,
			'schema'     => $schema,
			'status'     => $status,
			'type'       => $type,
			'validation' => $validation,
		), 200 );
	}

	/**
	 * POST /schema/{post_id} — generate schema via AI.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function generate_schema( WP_REST_Request $request ): WP_REST_Response {
		$post_id   = (int) $request->get_param( 'post_id' );
		$generator = new Schema_AI_Generator();
		$result    = $generator->generate_for_post( $post_id );

		if ( ! $result['success'] ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result['error'] ?? __( 'Generation failed.', 'schema-ai' ),
			), 500 );
		}

		// Return the freshly generated schema.
		$schema_json = get_post_meta( $post_id, '_schema_ai_data', true );
		$schema      = json_decode( $schema_json, true );
		$validation  = Schema_AI_Validator::validate( $schema );

		return new WP_REST_Response( array(
			'success'    => true,
			'post_id'    => $post_id,
			'schema'     => $schema,
			'type'       => $result['type'] ?? '',
			'validation' => $validation,
		), 200 );
	}

	/**
	 * PUT /schema/{post_id} — update schema from JSON body.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_schema( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$body    = $request->get_json_params();

		if ( empty( $body['schema'] ) || ! is_array( $body['schema'] ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Invalid schema data. Provide a "schema" object in the request body.', 'schema-ai' ),
			), 400 );
		}

		$schema = $body['schema'];

		// Validate.
		$validation = Schema_AI_Validator::validate( $schema );

		// Detect type.
		$type = $schema['@type'] ?? ( $schema['@graph'][0]['@type'] ?? 'Unknown' );

		// Save.
		update_post_meta(
			$post_id,
			'_schema_ai_data',
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		update_post_meta( $post_id, '_schema_ai_type', $type );
		update_post_meta( $post_id, '_schema_ai_status', 'edited' );

		Schema_AI_Cache::delete( $post_id );

		return new WP_REST_Response( array(
			'success'    => true,
			'post_id'    => $post_id,
			'schema'     => $schema,
			'type'       => $type,
			'status'     => 'edited',
			'validation' => $validation,
		), 200 );
	}

	/**
	 * DELETE /schema/{post_id} — remove schema.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function delete_schema( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );

		delete_post_meta( $post_id, '_schema_ai_data' );
		delete_post_meta( $post_id, '_schema_ai_type' );
		update_post_meta( $post_id, '_schema_ai_status', 'none' );

		Schema_AI_Cache::delete( $post_id );

		return new WP_REST_Response( array(
			'success' => true,
			'post_id' => $post_id,
			'status'  => 'none',
		), 200 );
	}

	/**
	 * GET /stats — overview statistics.
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats(): WP_REST_Response {
		$admin = new Schema_AI_Admin();
		$stats = $admin->get_overview_stats();
		$api   = Schema_AI_Logger::get_stats();

		return new WP_REST_Response( array(
			'overview' => $stats,
			'api'      => $api,
		), 200 );
	}

	/**
	 * GET /log — paginated log entries.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_log( WP_REST_Request $request ): WP_REST_Response {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$entries = Schema_AI_Logger::get_recent( $limit, $offset );

		return new WP_REST_Response( array(
			'entries' => $entries,
			'limit'   => $limit,
			'offset'  => $offset,
		), 200 );
	}

	/**
	 * POST /bulk/start — start bulk processing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function bulk_start( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		// Simulate AJAX start by setting up $_POST and calling the handler.
		$_POST['post_type']  = $body['post_type'] ?? 'post';
		$_POST['mode']       = $body['mode'] ?? 'missing';
		$_POST['batch_size'] = $body['batch_size'] ?? 10;
		$_POST['delay']      = $body['delay'] ?? 2;
		$_POST['nonce']      = wp_create_nonce( 'schema_ai_nonce' );

		$bulk = new Schema_AI_Bulk();

		if ( get_transient( Schema_AI_Bulk::LOCK_TRANSIENT ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Bulk process already running.', 'schema-ai' ),
			), 409 );
		}

		$post_type  = sanitize_text_field( $body['post_type'] ?? 'post' );
		$mode       = sanitize_text_field( $body['mode'] ?? 'missing' );
		$batch_size = max( 1, min( 50, absint( $body['batch_size'] ?? 10 ) ) );
		$delay      = max( 1, min( 30, absint( $body['delay'] ?? 2 ) ) );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		switch ( $mode ) {
			case 'missing':
				$args['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => '_schema_ai_status',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_schema_ai_status',
						'value' => 'none',
					),
				);
				break;

			case 'errors':
				$args['meta_query'] = array(
					array(
						'key'   => '_schema_ai_status',
						'value' => 'error',
					),
				);
				break;
		}

		$query    = new WP_Query( $args );
		$post_ids = $query->posts;

		if ( empty( $post_ids ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'No posts found matching the criteria.', 'schema-ai' ),
			), 404 );
		}

		$state = array(
			'post_ids'   => $post_ids,
			'total'      => count( $post_ids ),
			'processed'  => 0,
			'success'    => 0,
			'errors'     => 0,
			'status'     => 'running',
			'log'        => array(),
			'started_at' => current_time( 'mysql' ),
		);

		update_option( Schema_AI_Bulk::STATE_OPTION, $state, false );
		set_transient( Schema_AI_Bulk::LOCK_TRANSIENT, 1, HOUR_IN_SECONDS );

		foreach ( $post_ids as $index => $post_id ) {
			$offset = $index * $delay;
			as_schedule_single_action(
				time() + $offset,
				Schema_AI_Bulk::AS_HOOK,
				array( (int) $post_id ),
				'schema-ai'
			);
		}

		return new WP_REST_Response( array(
			'success' => true,
			'total'   => count( $post_ids ),
			'message' => sprintf(
				/* translators: %d: number of posts */
				__( 'Bulk processing started for %d posts.', 'schema-ai' ),
				count( $post_ids )
			),
		), 200 );
	}

	/**
	 * POST /bulk/cancel — cancel bulk processing.
	 *
	 * @return WP_REST_Response
	 */
	public function bulk_cancel(): WP_REST_Response {
		$state = get_option( Schema_AI_Bulk::STATE_OPTION, array() );

		if ( ! empty( $state ) ) {
			$state['status'] = 'cancelled';
			update_option( Schema_AI_Bulk::STATE_OPTION, $state, false );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Schema_AI_Bulk::AS_HOOK );
		}

		delete_transient( Schema_AI_Bulk::LOCK_TRANSIENT );

		return new WP_REST_Response( array(
			'success' => true,
			'message' => __( 'Bulk processing cancelled.', 'schema-ai' ),
		), 200 );
	}

	/**
	 * GET /bulk/status — return current bulk state.
	 *
	 * @return WP_REST_Response
	 */
	public function bulk_status(): WP_REST_Response {
		$state = get_option( Schema_AI_Bulk::STATE_OPTION, array() );

		return new WP_REST_Response( $state, 200 );
	}

	/**
	 * Permission callback: check edit_post capability for the given post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_post_permission( WP_REST_Request $request ): bool|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit this post.', 'schema-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback: check manage_options capability.
	 *
	 * @return bool|WP_Error
	 */
	public function check_manage_options(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'schema-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate post_id parameter.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public function validate_post_id( mixed $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}
}
