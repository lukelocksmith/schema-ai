<?php
/**
 * Schema Generator — main orchestrator for schema generation.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Generator {

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 20, 3 );
	}

	/**
	 * Handle post publish transition.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}

		if ( ! get_option( 'schema_ai_auto_generate' ) ) {
			return;
		}

		$allowed_types = get_option( 'schema_ai_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}

		if ( get_transient( 'schema_ai_bulk_lock' ) ) {
			return;
		}

		$exclude_categories = get_option( 'schema_ai_exclude_categories', array() );
		if ( ! empty( $exclude_categories ) ) {
			$post_categories = wp_get_post_categories( $post->ID );
			if ( ! empty( array_intersect( $post_categories, $exclude_categories ) ) ) {
				return;
			}
		}

		$status = get_post_meta( $post->ID, '_schema_ai_status', true );
		if ( 'edited' === $status ) {
			return;
		}

		$this->generate_for_post( $post->ID );
	}

	/**
	 * Generate schema for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{success: bool, type?: string, error?: string}
	 */
	public function generate_for_post( int $post_id ): array {
		$start = microtime( true );

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => __( 'Post not found.', 'schema-ai' ),
			);
		}

		// Pre-analysis.
		$analysis = Schema_AI_Analyzer::analyze( $post_id );

		// Build prompt.
		$prompt = $this->build_prompt( $post, $analysis );

		// Call Gemini API.
		$result = Schema_AI_Gemini::generate( $prompt );

		$duration_ms = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( ! $result['success'] ) {
			update_post_meta( $post_id, '_schema_ai_status', 'error' );

			Schema_AI_Logger::log( array(
				'post_id'       => $post_id,
				'action'        => 'generate',
				'schema_type'   => $analysis['type'],
				'status'        => 'error',
				'tokens_used'   => $result['tokens_used'] ?? 0,
				'model'         => $result['model'] ?? '',
				'duration_ms'   => $duration_ms,
				'error_message' => $result['error'] ?? __( 'Unknown error.', 'schema-ai' ),
			) );

			return array(
				'success' => false,
				'error'   => $result['error'] ?? __( 'Unknown error.', 'schema-ai' ),
			);
		}

		$schema = $result['data'];

		// Fix: if Gemini returned a plain array instead of @graph object, wrap it.
		if ( isset( $schema[0] ) && ! isset( $schema['@type'] ) && ! isset( $schema['@graph'] ) ) {
			$context = $schema['@context'] ?? 'https://schema.org';
			unset( $schema['@context'] );
			$schema = array(
				'@context' => $context,
				'@graph'   => array_values( array_filter( $schema, 'is_array' ) ),
			);
		}

		// Ensure @context is present.
		if ( empty( $schema['@context'] ) ) {
			$schema['@context'] = 'https://schema.org';
		}

		// Validate.
		$validation = Schema_AI_Validator::validate( $schema );

		// Detect type.
		$type = $schema['@type'] ?? ( $schema['@graph'][0]['@type'] ?? 'Unknown' );

		// Save schema data.
		update_post_meta(
			$post_id,
			'_schema_ai_data',
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		update_post_meta( $post_id, '_schema_ai_type', $type );
		update_post_meta( $post_id, '_schema_ai_status', 'auto' );

		// Invalidate cache.
		Schema_AI_Cache::delete( $post_id );

		// Log result.
		$log_status = $validation['valid'] ? 'success' : 'warning';

		Schema_AI_Logger::log( array(
			'post_id'       => $post_id,
			'action'        => 'generate',
			'schema_type'   => $type,
			'status'        => $log_status,
			'tokens_used'   => $result['tokens_used'] ?? 0,
			'model'         => $result['model'] ?? '',
			'duration_ms'   => $duration_ms,
			'error_message' => $validation['valid']
				? null
				: implode( '; ', array_merge( $validation['errors'], $validation['warnings'] ) ),
		) );

		return array(
			'success' => true,
			'type'    => $type,
		);
	}

	/**
	 * Build the prompt for Gemini API.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $analysis Pre-analysis result.
	 * @return string Full prompt.
	 */
	private function build_prompt( WP_Post $post, array $analysis ): string {
		$truncated = Schema_AI_Gemini::truncate_content( $post->post_content );
		$content   = $truncated['content'];

		$author = get_the_author_meta( 'display_name', $post->post_author );
		$image  = get_the_post_thumbnail_url( $post->ID, 'full' ) ?: '';

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$categories = is_array( $categories ) ? implode( ', ', $categories ) : '';

		$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		$tags = is_array( $tags ) ? implode( ', ', $tags ) : '';

		$publisher_name = get_option( 'schema_ai_publisher_name', get_bloginfo( 'name' ) );
		$publisher_url  = get_option( 'schema_ai_publisher_url', home_url() );
		$publisher_logo = get_option( 'schema_ai_publisher_logo', '' );
		$locale         = get_locale();

		$prompt = <<<PROMPT
You are a schema.org structured data expert. Analyze this WordPress post and generate valid JSON-LD markup.

POST METADATA:
- Title: {$post->post_title}
- URL: {$this->get_permalink( $post->ID )}
- Published: {$post->post_date}
- Modified: {$post->post_modified}
- Author: {$author}
- Featured image: {$image}
- Categories: {$categories}
- Tags: {$tags}
- Language: {$locale}

FULL CONTENT (HTML):
{$content}

PHP PRE-ANALYSIS SUGGESTS: {$analysis['type']} (confidence: {$analysis['confidence']})

INSTRUCTIONS:
1. Determine the BEST schema.org type for this content. Choose from: Article, BlogPosting, HowTo, FAQPage, NewsArticle, Review, TechArticle, Product, Service, Event, Organization, LocalBusiness, Recipe, VideoObject, Course, SoftwareApplication
2. Generate complete JSON-LD with ALL required and recommended properties
3. For HowTo: extract actual steps — MAX 12 steps, keep step names short (under 60 chars), omit step descriptions
4. For FAQPage: extract actual Q&A pairs — MAX 10 questions, keep answers under 150 chars each
5. For Article/BlogPosting: include full author, publisher, image objects
6. Use @graph if multiple types apply (e.g. Article + FAQPage)
7. Publisher is always: {"@type": "Organization", "name": "{$publisher_name}", "url": "{$publisher_url}", "logo": {"@type": "ImageObject", "url": "{$publisher_logo}"}}
8. Generate all property values in the SAME LANGUAGE as the content
9. Always include @context: "https://schema.org"
10. For Article/TechArticle/BlogPosting: include wordCount (count words in the content)
11. IMPORTANT: Keep total JSON output compact — no verbose descriptions, no duplicate data, use short property values

Return ONLY valid JSON-LD. No explanations, no markdown, no code fences.
PROMPT;

		if ( $truncated['truncated'] ) {
			$prompt .= "\n\nNOTE: Content was truncated due to length. Generate schema based on available content.";
		}

		return $prompt;
	}

	/**
	 * Get permalink for a post (wrapper for testability).
	 *
	 * @param int $post_id Post ID.
	 * @return string Permalink URL.
	 */
	protected function get_permalink( int $post_id ): string {
		return get_permalink( $post_id );
	}
}
