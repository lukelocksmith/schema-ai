<?php
/**
 * Content Analyzer — detects the most appropriate Schema.org type for a post.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Analyzer {

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		// Reserved for future hooks.
	}

	/**
	 * Analyze a post and determine the best Schema.org type.
	 *
	 * @param int $post_id Post ID.
	 * @return array{type: string, confidence: string}
	 */
	public static function analyze( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'type'       => 'Article',
				'confidence' => 'low',
			);
		}

		$title   = strtolower( $post->post_title );
		$content = $post->post_content;

		// WooCommerce product: immediate match.
		if ( 'product' === $post->post_type ) {
			return array(
				'type'       => 'Product',
				'confidence' => 'high',
			);
		}

		$signals = array();

		// --- FAQ signal: question marks in h2-h4 headings ---
		if ( preg_match_all( '/<h[2-4][^>]*>([^<]*\?[^<]*)<\/h[2-4]>/i', $content, $faq_matches ) ) {
			$question_count = count( $faq_matches[0] );
			if ( $question_count >= 3 ) {
				$signals['FAQPage'] = $question_count * 3;
			} else {
				$signals['FAQPage'] = $question_count;
			}
		}

		// --- HowTo signal: title keywords ---
		$howto_keywords = array(
			'jak ', 'how to', 'krok po kroku', 'step by step',
			'tutorial', 'poradnik', 'instrukcja', 'guide',
		);
		$howto_score = 0;

		foreach ( $howto_keywords as $keyword ) {
			if ( str_contains( $title, $keyword ) ) {
				$howto_score += 5;
				break;
			}
		}

		// Ordered lists and "krok/step/etap N" in headings.
		if ( preg_match_all( '/<ol\b/i', $content ) ) {
			$howto_score += substr_count( strtolower( $content ), '<ol' );
		}
		if ( preg_match_all( '/<h[2-4][^>]*>[^<]*(krok|step|etap)\s+\d+/i', $content, $step_matches ) ) {
			$howto_score += count( $step_matches[0] ) * 2;
		}

		if ( $howto_score > 0 ) {
			$signals['HowTo'] = $howto_score;
		}

		// --- Review signal: title keywords ---
		$review_keywords = array(
			'recenzja', 'review', 'opinia', 'porównanie',
			'comparison', 'versus', ' vs ',
		);

		foreach ( $review_keywords as $keyword ) {
			if ( str_contains( $title, $keyword ) ) {
				$signals['Review'] = ( $signals['Review'] ?? 0 ) + 5;
			}
		}

		// --- Event signal: keywords + date pattern ---
		$event_keywords = array(
			'event', 'konferencja', 'conference', 'webinar',
			'meetup', 'warsztat', 'workshop', 'summit',
		);
		$has_event_keyword = false;

		foreach ( $event_keywords as $keyword ) {
			if ( str_contains( $title, $keyword ) || str_contains( strtolower( $content ), $keyword ) ) {
				$has_event_keyword = true;
				break;
			}
		}

		if ( $has_event_keyword && preg_match( '/\d{1,4}[-\/\.]\d{1,2}[-\/\.]\d{1,4}/', $content ) ) {
			$signals['Event'] = 8;
		}

		// --- Recipe signal ---
		$recipe_keywords = array(
			'przepis', 'recipe', 'składniki', 'ingredients',
			'czas przygotowania', 'prep time', 'cooking time',
		);

		foreach ( $recipe_keywords as $keyword ) {
			if ( str_contains( $title, $keyword ) || str_contains( strtolower( $content ), $keyword ) ) {
				$signals['Recipe'] = ( $signals['Recipe'] ?? 0 ) + 4;
			}
		}

		// --- VideoObject signal ---
		$video_patterns = array(
			'youtube.com', 'youtu.be', 'vimeo.com',
			'<video', 'wp-block-embed',
		);

		foreach ( $video_patterns as $pattern ) {
			if ( str_contains( strtolower( $content ), $pattern ) ) {
				$signals['VideoObject'] = ( $signals['VideoObject'] ?? 0 ) + 3;
			}
		}

		// --- TechArticle signal: code blocks ---
		if ( preg_match_all( '/<(code|pre)\b/i', $content, $code_matches ) ) {
			$code_count = count( $code_matches[0] );
			if ( $code_count >= 2 ) {
				$signals['TechArticle'] = $code_count * 2;
			}
		}

		// --- Determine winner ---
		if ( empty( $signals ) ) {
			return match ( $post->post_type ) {
				'post'  => array( 'type' => 'BlogPosting', 'confidence' => 'medium' ),
				default => array( 'type' => 'Article', 'confidence' => 'low' ),
			};
		}

		arsort( $signals );
		$best_type  = array_key_first( $signals );
		$best_score = $signals[ $best_type ];

		$confidence = match ( true ) {
			$best_score >= 10 => 'high',
			$best_score >= 5  => 'medium',
			default           => 'low',
		};

		return array(
			'type'       => $best_type,
			'confidence' => $confidence,
		);
	}
}
