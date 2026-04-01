<?php
/**
 * Cache Manager — transient-based schema caching with content-hash invalidation.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Cache {

	/**
	 * Transient key prefix.
	 */
	const PREFIX = 'schema_ai_';

	/**
	 * Default cache TTL in seconds.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'save_post', array( $this, 'invalidate_on_save' ), 5 );
	}

	/**
	 * Get cached schema for a post.
	 *
	 * Returns the schema array if the cache is valid (hash matches), null otherwise.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Schema array or null if cache miss / stale.
	 */
	public static function get( int $post_id ): ?array {
		$cached = get_transient( self::PREFIX . $post_id );

		if ( false === $cached ) {
			return null;
		}

		$data = json_decode( $cached, true );

		if ( ! is_array( $data ) || empty( $data['hash'] ) || empty( $data['schema'] ) ) {
			return null;
		}

		// Verify content hasn't changed since caching.
		if ( $data['hash'] !== self::content_hash( $post_id ) ) {
			delete_transient( self::PREFIX . $post_id );
			return null;
		}

		return $data['schema'];
	}

	/**
	 * Store schema in cache for a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $schema  Schema array to cache.
	 */
	public static function set( int $post_id, array $schema ): void {
		$data = wp_json_encode( array(
			'hash'   => self::content_hash( $post_id ),
			'schema' => $schema,
		) );

		set_transient( self::PREFIX . $post_id, $data, self::TTL );
	}

	/**
	 * Delete cached schema for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete( int $post_id ): void {
		delete_transient( self::PREFIX . $post_id );
	}

	/**
	 * Invalidate cache when a post is saved.
	 *
	 * @param int $post_id Post ID.
	 */
	public function invalidate_on_save( int $post_id ): void {
		self::delete( $post_id );
	}

	/**
	 * Generate a content hash for cache validation.
	 *
	 * @param int $post_id Post ID.
	 * @return string MD5 hash of post content + title + modified date.
	 */
	private static function content_hash( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		return md5( $post->post_content . $post->post_title . $post->post_modified );
	}
}
