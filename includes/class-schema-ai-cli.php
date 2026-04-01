<?php
/**
 * WP-CLI Commands — schema-ai command group.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_CLI {

	/**
	 * Register WP-CLI commands.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'schema-ai', self::class );
	}

	/**
	 * Generate schema for a single post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to generate schema for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-ai generate 42
	 *
	 * @subcommand generate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
		}

		WP_CLI::log( sprintf( 'Generating schema for "%s" (ID: %d)...', $post->post_title, $post_id ) );

		$generator = new Schema_AI_Generator();
		$result    = $generator->generate_for_post( $post_id );

		if ( $result['success'] ) {
			WP_CLI::success( sprintf( 'Schema generated: %s', $result['type'] ?? 'Unknown' ) );

			$schema_json = get_post_meta( $post_id, '_schema_ai_data', true );
			if ( $schema_json ) {
				$schema = json_decode( $schema_json, true );
				WP_CLI::log( wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
		} else {
			WP_CLI::error( sprintf( 'Generation failed: %s', $result['error'] ?? 'Unknown error' ) );
		}
	}

	/**
	 * Bulk generate schema for multiple posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Post type to process. Default: post.
	 *
	 * [--mode=<mode>]
	 * : Processing mode: missing, errors, all. Default: missing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-ai bulk
	 *     wp schema-ai bulk --post-type=page --mode=all
	 *
	 * @subcommand bulk
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function bulk( array $args, array $assoc_args ): void {
		$post_type = $assoc_args['post-type'] ?? 'post';
		$mode      = $assoc_args['mode'] ?? 'missing';

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		switch ( $mode ) {
			case 'missing':
				$query_args['meta_query'] = array(
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
				$query_args['meta_query'] = array(
					array(
						'key'   => '_schema_ai_status',
						'value' => 'error',
					),
				);
				break;

			case 'all':
				// No meta_query.
				break;

			default:
				WP_CLI::error( 'Invalid mode. Use: missing, errors, or all.' );
		}

		$query    = new WP_Query( $query_args );
		$post_ids = $query->posts;

		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No posts found matching the criteria.' );
			return;
		}

		WP_CLI::log( sprintf( 'Processing %d %s posts (mode: %s)...', count( $post_ids ), $post_type, $mode ) );

		$progress  = \WP_CLI\Utils\make_progress_bar( 'Generating schemas', count( $post_ids ) );
		$success   = 0;
		$errors    = 0;
		$generator = new Schema_AI_Generator();

		foreach ( $post_ids as $post_id ) {
			$result = $generator->generate_for_post( (int) $post_id );

			if ( $result['success'] ) {
				$success++;
			} else {
				$errors++;
				$post = get_post( $post_id );
				$name = $post ? $post->post_title : '#' . $post_id;
				WP_CLI::warning( sprintf( 'Failed: %s — %s', $name, $result['error'] ?? 'Unknown error' ) );
			}

			$progress->tick();
			sleep( 1 );
		}

		$progress->finish();

		WP_CLI::success( sprintf( 'Done. %d succeeded, %d failed.', $success, $errors ) );
	}

	/**
	 * Display overview statistics and API usage.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-ai status
	 *
	 * @subcommand status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$admin = new Schema_AI_Admin();
		$stats = $admin->get_overview_stats();
		$api   = Schema_AI_Logger::get_stats();

		WP_CLI::log( '--- Schema Overview ---' );
		WP_CLI::log( sprintf( 'Total posts:    %d', $stats['total'] ) );
		WP_CLI::log( sprintf( 'With schema:    %d', $stats['with_schema'] ) );
		WP_CLI::log( sprintf( 'Without schema: %d', $stats['without'] ) );
		WP_CLI::log( sprintf( 'Errors:         %d', $stats['errors'] ) );

		if ( ! empty( $stats['types'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( '--- Schema Types ---' );
			foreach ( $stats['types'] as $type => $count ) {
				WP_CLI::log( sprintf( '  %s: %d', $type, $count ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( '--- API Usage ---' );
		WP_CLI::log( sprintf( 'Today:  %d calls, %d tokens', $api['today_calls'], $api['today_tokens'] ) );
		WP_CLI::log( sprintf( 'Month:  %d calls, %d tokens', $api['month_calls'], $api['month_tokens'] ) );
	}

	/**
	 * Validate existing schema for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to validate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-ai validate 42
	 *
	 * @subcommand validate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function validate( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
		}

		$schema_json = get_post_meta( $post_id, '_schema_ai_data', true );

		if ( empty( $schema_json ) ) {
			WP_CLI::error( sprintf( 'No schema found for post %d.', $post_id ) );
		}

		$schema = json_decode( $schema_json, true );

		if ( ! is_array( $schema ) ) {
			WP_CLI::error( 'Stored schema is not valid JSON.' );
		}

		$validation = Schema_AI_Validator::validate( $schema );

		$type = $schema['@type'] ?? ( $schema['@graph'][0]['@type'] ?? 'Unknown' );
		WP_CLI::log( sprintf( 'Schema type: %s', $type ) );

		if ( $validation['valid'] ) {
			WP_CLI::success( 'Schema is valid. No errors found.' );
		} else {
			WP_CLI::warning( 'Schema has validation issues:' );
		}

		if ( ! empty( $validation['errors'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Errors:' );
			foreach ( $validation['errors'] as $error ) {
				WP_CLI::log( '  - ' . $error );
			}
		}

		if ( ! empty( $validation['warnings'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Warnings:' );
			foreach ( $validation['warnings'] as $warning ) {
				WP_CLI::log( '  - ' . $warning );
			}
		}
	}

	/**
	 * Remove schema from a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to remove schema from.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-ai remove 42
	 *
	 * @subcommand remove
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
		}

		delete_post_meta( $post_id, '_schema_ai_data' );
		delete_post_meta( $post_id, '_schema_ai_type' );
		update_post_meta( $post_id, '_schema_ai_status', 'none' );

		Schema_AI_Cache::delete( $post_id );

		WP_CLI::success( sprintf( 'Schema removed from "%s" (ID: %d).', $post->post_title, $post_id ) );
	}

	/**
	 * Show recent log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Number of entries to show. Default: 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp schema-ai log
	 *     wp schema-ai log --limit=50
	 *
	 * @subcommand log
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$limit   = (int) ( $assoc_args['limit'] ?? 20 );
		$limit   = max( 1, min( 100, $limit ) );
		$entries = Schema_AI_Logger::get_recent( $limit );

		if ( empty( $entries ) ) {
			WP_CLI::warning( 'No log entries found.' );
			return;
		}

		$items = array();
		foreach ( $entries as $entry ) {
			$items[] = array(
				'ID'         => $entry['id'],
				'Post'       => $entry['post_title'] ?? ( '#' . $entry['post_id'] ),
				'Action'     => $entry['action'],
				'Type'       => $entry['schema_type'],
				'Status'     => $entry['status'],
				'Tokens'     => $entry['tokens_used'],
				'Duration'   => $entry['duration_ms'] . 'ms',
				'Created At' => $entry['created_at'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'ID', 'Post', 'Action', 'Type', 'Status', 'Tokens', 'Duration', 'Created At' ) );
	}
}
