<?php
/**
 * Logger — custom database table for API call logging.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Logger {

	/**
	 * Initialize logger hooks.
	 */
	public function init(): void {
		// Reserved for future hooks (e.g. admin notices, log cleanup cron).
	}

	/**
	 * Create or update the log table via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'schema_ai_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(50) NOT NULL DEFAULT '',
			schema_type varchar(50) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			tokens_used int(11) NOT NULL DEFAULT 0,
			model varchar(50) NOT NULL DEFAULT '',
			duration_ms int(11) NOT NULL DEFAULT 0,
			error_message text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log entry.
	 *
	 * @param array $data {
	 *     @type int    $post_id       Post ID.
	 *     @type string $action        Action performed (e.g. 'generate', 'bulk_generate').
	 *     @type string $schema_type   Schema type (e.g. 'Article', 'Product').
	 *     @type string $status        Status ('success', 'error', 'cached').
	 *     @type int    $tokens_used   Tokens consumed.
	 *     @type string $model         Model used.
	 *     @type int    $duration_ms   Duration in milliseconds.
	 *     @type string $error_message Error message if failed.
	 * }
	 */
	public static function log( array $data ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'schema_ai_log',
			array(
				'post_id'       => $data['post_id'] ?? 0,
				'action'        => $data['action'] ?? '',
				'schema_type'   => $data['schema_type'] ?? '',
				'status'        => $data['status'] ?? '',
				'tokens_used'   => $data['tokens_used'] ?? 0,
				'model'         => $data['model'] ?? '',
				'duration_ms'   => $data['duration_ms'] ?? 0,
				'error_message' => $data['error_message'] ?? null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get recent log entries with post title.
	 *
	 * @param int $limit  Number of entries to return.
	 * @param int $offset Offset for pagination.
	 * @return array
	 */
	public static function get_recent( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'schema_ai_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.post_title
				FROM {$table} AS l
				LEFT JOIN {$wpdb->posts} AS p ON l.post_id = p.ID
				ORDER BY l.created_at DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Get the most recent error message for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Error message or null if none found.
	 */
	public static function get_last_error_for_post( int $post_id ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'schema_ai_log';

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT error_message FROM {$table}
				WHERE post_id = %d AND status = 'error'
				ORDER BY created_at DESC
				LIMIT 1",
				$post_id
			)
		);
	}

	/**
	 * Get aggregated usage statistics.
	 *
	 * @return array {
	 *     @type int $today_calls  API calls today.
	 *     @type int $today_tokens Tokens used today.
	 *     @type int $month_calls  API calls this month.
	 *     @type int $month_tokens Tokens used this month.
	 * }
	 */
	public static function get_stats(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'schema_ai_log';
		$today = current_time( 'Y-m-d' );
		$month = current_time( 'Y-m' );

		$today_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS calls, COALESCE(SUM(tokens_used), 0) AS tokens
				FROM {$table}
				WHERE DATE(created_at) = %s",
				$today
			),
			ARRAY_A
		);

		$month_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS calls, COALESCE(SUM(tokens_used), 0) AS tokens
				FROM {$table}
				WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
				$month
			),
			ARRAY_A
		);

		return array(
			'today_calls'  => (int) ( $today_stats['calls'] ?? 0 ),
			'today_tokens' => (int) ( $today_stats['tokens'] ?? 0 ),
			'month_calls'  => (int) ( $month_stats['calls'] ?? 0 ),
			'month_tokens' => (int) ( $month_stats['tokens'] ?? 0 ),
		);
	}
}
