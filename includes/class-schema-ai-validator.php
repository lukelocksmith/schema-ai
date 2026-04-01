<?php
/**
 * Validator — checks generated schema for completeness and correctness.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Validator {

	/**
	 * Required fields per Schema.org type.
	 */
	const REQUIRED_FIELDS = array(
		'Article'             => array( 'headline', 'author', 'datePublished', 'image', 'publisher' ),
		'BlogPosting'         => array( 'headline', 'author', 'datePublished', 'image', 'publisher' ),
		'TechArticle'         => array( 'headline', 'author', 'datePublished' ),
		'HowTo'              => array( 'name', 'step' ),
		'FAQPage'            => array( 'mainEntity' ),
		'NewsArticle'        => array( 'headline', 'author', 'datePublished', 'image', 'publisher' ),
		'Review'             => array( 'itemReviewed', 'author', 'reviewRating' ),
		'Product'            => array( 'name', 'image', 'offers' ),
		'Service'            => array( 'name', 'provider', 'description' ),
		'Organization'       => array( 'name', 'url' ),
		'LocalBusiness'      => array( 'name', 'address', 'telephone' ),
		'Event'              => array( 'name', 'startDate', 'location' ),
		'Recipe'             => array( 'name', 'image', 'recipeIngredient', 'recipeInstructions' ),
		'VideoObject'        => array( 'name', 'description', 'thumbnailUrl', 'uploadDate' ),
		'Course'             => array( 'name', 'description', 'provider' ),
		'SoftwareApplication' => array( 'name', 'operatingSystem', 'applicationCategory' ),
	);

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		// Reserved for future hooks.
	}

	/**
	 * Validate a schema array.
	 *
	 * @param array $schema Schema array to validate.
	 * @return array{valid: bool, errors: array, warnings: array}
	 */
	public static function validate( array $schema ): array {
		$errors   = array();
		$warnings = array();

		// Handle @graph: validate each item.
		if ( isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
			foreach ( $schema['@graph'] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					$errors[] = sprintf(
						/* translators: %d: index of graph item */
						__( '@graph item %d is not a valid object.', 'schema-ai' ),
						$index
					);
					continue;
				}

				$item_result = self::validate_single( $item, $index );
				$errors      = array_merge( $errors, $item_result['errors'] );
				$warnings    = array_merge( $warnings, $item_result['warnings'] );
			}

			return array(
				'valid'    => empty( $errors ),
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		$result   = self::validate_single( $schema );
		$errors   = $result['errors'];
		$warnings = $result['warnings'];

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate a single schema item.
	 *
	 * @param array    $schema Schema item.
	 * @param int|null $index  Index within @graph (null if top-level).
	 * @return array{errors: array, warnings: array}
	 */
	private static function validate_single( array $schema, ?int $index = null ): array {
		$errors   = array();
		$warnings = array();
		$prefix   = null !== $index
			? sprintf( '@graph[%d]: ', $index )
			: '';

		// Check @context.
		if ( empty( $schema['@context'] ) ) {
			$warnings[] = $prefix . __( 'Missing @context property.', 'schema-ai' );
		}

		// Check @type.
		if ( empty( $schema['@type'] ) ) {
			$errors[] = $prefix . __( 'Missing @type property.', 'schema-ai' );
			return array( 'errors' => $errors, 'warnings' => $warnings );
		}

		$type            = $schema['@type'];
		$required_fields = self::get_required_fields( $type );

		// Check required fields.
		foreach ( $required_fields as $field ) {
			if ( ! isset( $schema[ $field ] ) || self::is_empty_value( $schema[ $field ] ) ) {
				$errors[] = $prefix . sprintf(
					/* translators: 1: field name, 2: schema type */
					__( 'Missing required field "%1$s" for type %2$s.', 'schema-ai' ),
					$field,
					$type
				);
			}
		}

		// Type-specific validation: HowTo steps.
		if ( 'HowTo' === $type && isset( $schema['step'] ) && is_array( $schema['step'] ) ) {
			foreach ( $schema['step'] as $step_index => $step ) {
				if ( ! is_array( $step ) ) {
					$errors[] = $prefix . sprintf(
						/* translators: %d: step index */
						__( 'HowTo step %d is not a valid object.', 'schema-ai' ),
						$step_index
					);
					continue;
				}

				if ( empty( $step['text'] ) && empty( $step['name'] ) ) {
					$errors[] = $prefix . sprintf(
						/* translators: %d: step index */
						__( 'HowTo step %d is missing both "name" and "text".', 'schema-ai' ),
						$step_index
					);
				}
			}
		}

		// Type-specific validation: FAQPage mainEntity.
		if ( 'FAQPage' === $type && isset( $schema['mainEntity'] ) && is_array( $schema['mainEntity'] ) ) {
			foreach ( $schema['mainEntity'] as $qa_index => $qa ) {
				if ( ! is_array( $qa ) ) {
					$errors[] = $prefix . sprintf(
						/* translators: %d: QA index */
						__( 'FAQ mainEntity item %d is not a valid object.', 'schema-ai' ),
						$qa_index
					);
					continue;
				}

				if ( empty( $qa['name'] ) && empty( $qa['@type'] ) ) {
					$errors[] = $prefix . sprintf(
						/* translators: %d: QA index */
						__( 'FAQ mainEntity item %d is missing @type.', 'schema-ai' ),
						$qa_index
					);
				}

				if ( empty( $qa['acceptedAnswer'] ) ) {
					$errors[] = $prefix . sprintf(
						/* translators: %d: QA index */
						__( 'FAQ mainEntity item %d is missing acceptedAnswer.', 'schema-ai' ),
						$qa_index
					);
				}
			}
		}

		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	/**
	 * Get required fields for a schema type.
	 *
	 * @param string $type Schema.org type.
	 * @return array List of required field names.
	 */
	public static function get_required_fields( string $type ): array {
		return self::REQUIRED_FIELDS[ $type ] ?? array();
	}

	/**
	 * Check if a value is considered empty.
	 *
	 * @param mixed $value Value to check.
	 * @return bool True if empty.
	 */
	private static function is_empty_value( mixed $value ): bool {
		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			return true;
		}

		if ( is_array( $value ) && 0 === count( $value ) ) {
			return true;
		}

		return false;
	}
}
