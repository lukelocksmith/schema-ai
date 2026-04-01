<?php
/**
 * Frontend Output — renders JSON-LD schema in wp_head.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Frontend {

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'wp_head', array( $this, 'output_schema' ), 99 );
	}

	/**
	 * Output JSON-LD schema markup in the document head.
	 */
	public function output_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return;
		}

		$status = get_post_meta( $post_id, '_schema_ai_status', true );

		if ( empty( $status ) || 'none' === $status || 'error' === $status ) {
			return;
		}

		$raw = get_post_meta( $post_id, '_schema_ai_data', true );

		if ( empty( $raw ) ) {
			return;
		}

		$schema = json_decode( $raw, true );

		if ( ! is_array( $schema ) ) {
			return;
		}

		$json = wp_json_encode(
			$schema,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);

		if ( ! $json ) {
			return;
		}

		echo "\n" . '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
	}
}
