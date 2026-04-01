<?php
/**
 * Bulk Processor — batch schema generation via Action Scheduler.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Bulk {

	/**
	 * Option key for bulk processing state.
	 */
	const STATE_OPTION = 'schema_ai_bulk_state';

	/**
	 * Transient key for bulk lock.
	 */
	const LOCK_TRANSIENT = 'schema_ai_bulk_lock';

	/**
	 * Action Scheduler hook name.
	 */
	const AS_HOOK = 'schema_ai_bulk_process';

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( self::AS_HOOK, array( $this, 'process_single' ) );

		add_action( 'wp_ajax_schema_ai_bulk_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_schema_ai_bulk_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_schema_ai_bulk_status', array( $this, 'ajax_status' ) );
	}

	/**
	 * AJAX handler: start bulk processing.
	 */
	public function ajax_start(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'schema-ai' ) ) );
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			wp_send_json_error( array( 'message' => __( 'Bulk process already running.', 'schema-ai' ) ) );
		}

		$post_type  = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? 'post' ) );
		$mode       = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'missing' ) );
		$batch_size = absint( $_POST['batch_size'] ?? 10 );
		$delay      = absint( $_POST['delay'] ?? 2 );

		$batch_size = max( 1, min( 50, $batch_size ) );
		$delay      = max( 1, min( 30, $delay ) );

		// Build query args.
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

			case 'all':
				// No meta_query — process all published posts.
				break;
		}

		$query    = new WP_Query( $args );
		$post_ids = $query->posts;

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No posts found matching the criteria.', 'schema-ai' ) ) );
		}

		// Save state.
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

		update_option( self::STATE_OPTION, $state, false );
		set_transient( self::LOCK_TRANSIENT, 1, HOUR_IN_SECONDS );

		// Schedule individual actions with delay offsets.
		foreach ( $post_ids as $index => $post_id ) {
			$offset = $index * $delay;
			as_schedule_single_action(
				time() + $offset,
				self::AS_HOOK,
				array( (int) $post_id ),
				'schema-ai'
			);
		}

		wp_send_json_success( array(
			'total'   => count( $post_ids ),
			'message' => sprintf(
				/* translators: %d: number of posts */
				__( 'Bulk processing started for %d posts.', 'schema-ai' ),
				count( $post_ids )
			),
		) );
	}

	/**
	 * Process a single post during bulk operation.
	 *
	 * @param int $post_id Post ID.
	 */
	public function process_single( int $post_id ): void {
		$state = get_option( self::STATE_OPTION, array() );

		if ( empty( $state ) || 'cancelled' === ( $state['status'] ?? '' ) ) {
			return;
		}

		$generator = new Schema_AI_Generator();
		$result    = $generator->generate_for_post( $post_id );

		// Update state.
		$state['processed']++;

		if ( $result['success'] ) {
			$state['success']++;
		} else {
			$state['errors']++;
		}

		// Add log entry.
		$post  = get_post( $post_id );
		$title = $post ? $post->post_title : '#' . $post_id;

		$state['log'][] = array(
			'post_id' => $post_id,
			'title'   => $title,
			'success' => $result['success'],
			'type'    => $result['type'] ?? '',
			'error'   => $result['error'] ?? '',
		);

		// Keep last 50 log entries.
		if ( count( $state['log'] ) > 50 ) {
			$state['log'] = array_slice( $state['log'], -50 );
		}

		// Check completion.
		if ( $state['processed'] >= $state['total'] ) {
			$state['status'] = 'completed';
			delete_transient( self::LOCK_TRANSIENT );
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * AJAX handler: cancel bulk processing.
	 */
	public function ajax_cancel(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'schema-ai' ) ) );
		}

		$state = get_option( self::STATE_OPTION, array() );

		if ( ! empty( $state ) ) {
			$state['status'] = 'cancelled';
			update_option( self::STATE_OPTION, $state, false );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::AS_HOOK );
		}

		delete_transient( self::LOCK_TRANSIENT );

		wp_send_json_success( array( 'message' => __( 'Bulk processing cancelled.', 'schema-ai' ) ) );
	}

	/**
	 * AJAX handler: get bulk processing status.
	 */
	public function ajax_status(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'schema-ai' ) ) );
		}

		$state = get_option( self::STATE_OPTION, array() );

		wp_send_json_success( $state );
	}

	/**
	 * Get post counts for a specific post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array{total: int, with: int, without: int, errors: int}
	 */
	public static function get_counts( string $post_type ): array {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			)
		);

		$with = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_schema_ai_status'
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND pm.meta_value IN ('auto', 'manual', 'edited')",
				$post_type
			)
		);

		$errors = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_schema_ai_status'
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND pm.meta_value = 'error'",
				$post_type
			)
		);

		$without = $total - $with - $errors;

		return array(
			'total'   => $total,
			'with'    => $with,
			'without' => max( 0, $without ),
			'errors'  => $errors,
		);
	}
}
