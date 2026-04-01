<?php
/**
 * Meta Box — schema preview and actions on the post edit screen.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Metabox {

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'wp_ajax_schema_ai_regenerate', array( $this, 'ajax_regenerate' ) );
		add_action( 'wp_ajax_schema_ai_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_schema_ai_remove', array( $this, 'ajax_remove' ) );
	}

	/**
	 * Register the meta box on all enabled post types.
	 */
	public function register(): void {
		$post_types = get_option( 'schema_ai_post_types', array( 'post', 'page' ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'schema-ai-metabox',
				__( 'Schema AI', 'schema-ai' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( 'schema_ai_metabox', 'schema_ai_metabox_nonce' );

		$status = get_post_meta( $post->ID, '_schema_ai_status', true );
		$type   = get_post_meta( $post->ID, '_schema_ai_type', true );
		$raw    = get_post_meta( $post->ID, '_schema_ai_data', true );

		$schema     = array();
		$validation = null;

		if ( ! empty( $raw ) ) {
			$schema = json_decode( $raw, true );
			if ( is_array( $schema ) ) {
				$validation = Schema_AI_Validator::validate( $schema );
			}
		}

		?>
		<div class="schema-ai-metabox-wrapper" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

			<!-- Status Badge -->
			<div class="schema-ai-metabox-status">
				<?php
				switch ( $status ) {
					case 'auto':
						echo '<span class="schema-ai-status-badge schema-ai-status-badge--auto">' . esc_html__( 'Auto-generated', 'schema-ai' ) . '</span>';
						break;
					case 'manual':
						echo '<span class="schema-ai-status-badge schema-ai-status-badge--manual">' . esc_html__( 'Manual', 'schema-ai' ) . '</span>';
						break;
					case 'edited':
						echo '<span class="schema-ai-status-badge schema-ai-status-badge--edited">' . esc_html__( 'Edited', 'schema-ai' ) . '</span>';
						break;
					case 'error':
						echo '<span class="schema-ai-status-badge schema-ai-status-badge--error">' . esc_html__( 'Error', 'schema-ai' ) . '</span>';
						break;
					default:
						echo '<span class="schema-ai-status-badge schema-ai-status-badge--none">' . esc_html__( 'No Schema', 'schema-ai' ) . '</span>';
						break;
				}
				?>
			</div>

			<!-- Type Label -->
			<?php if ( ! empty( $type ) ) : ?>
				<div class="schema-ai-metabox-type">
					<strong><?php esc_html_e( 'Type:', 'schema-ai' ); ?></strong>
					<span class="schema-ai-badge"><?php echo esc_html( $type ); ?></span>
				</div>
			<?php endif; ?>

			<!-- JSON Textarea -->
			<?php if ( ! empty( $schema ) ) : ?>
				<div class="schema-ai-metabox-json">
					<textarea
						id="schema-ai-json"
						class="large-text code"
						rows="10"
						readonly
					><?php echo esc_textarea( wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); ?></textarea>
				</div>
			<?php endif; ?>

			<!-- Validation Result -->
			<?php if ( null !== $validation ) : ?>
				<div class="schema-ai-metabox-validation">
					<?php if ( $validation['valid'] ) : ?>
						<p class="schema-ai-validation--valid">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Schema is valid.', 'schema-ai' ); ?>
						</p>
					<?php else : ?>
						<p class="schema-ai-validation--invalid">
							<span class="dashicons dashicons-warning"></span>
							<?php esc_html_e( 'Validation issues:', 'schema-ai' ); ?>
						</p>
						<ul>
							<?php foreach ( $validation['errors'] as $error ) : ?>
								<li class="schema-ai-validation-error"><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
							<?php foreach ( $validation['warnings'] as $warning ) : ?>
								<li class="schema-ai-validation-warning"><?php echo esc_html( $warning ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Action Buttons -->
			<div class="schema-ai-metabox-actions">
				<button type="button" class="button button-primary schema-ai-regenerate">
					<?php esc_html_e( 'Regenerate', 'schema-ai' ); ?>
				</button>
				<?php if ( ! empty( $schema ) ) : ?>
					<button type="button" class="button schema-ai-edit">
						<?php esc_html_e( 'Edit', 'schema-ai' ); ?>
					</button>
					<button type="button" class="button schema-ai-remove">
						<?php esc_html_e( 'Remove', 'schema-ai' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Spinner -->
			<div class="schema-ai-spinner" style="display: none;">
				<span class="spinner is-active"></span>
			</div>

		</div>
		<?php
	}

	/**
	 * AJAX handler: regenerate schema for a post.
	 */
	public function ajax_regenerate(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'schema-ai' ) ) );
		}

		$generator = new Schema_AI_Generator();
		$result    = $generator->generate_for_post( $post_id );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ?? __( 'Generation failed.', 'schema-ai' ) ) );
		}

		$data = get_post_meta( $post_id, '_schema_ai_data', true );

		wp_send_json_success( array(
			'type'   => $result['type'] ?? '',
			'data'   => $data,
			'status' => get_post_meta( $post_id, '_schema_ai_status', true ),
		) );
	}

	/**
	 * AJAX handler: save edited schema JSON.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'schema-ai' ) ) );
		}

		$json_string = isset( $_POST['schema'] ) ? wp_unslash( $_POST['schema'] ) : '';
		$decoded     = json_decode( $json_string, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON.', 'schema-ai' ) ) );
		}

		$clean = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$type  = $decoded['@type'] ?? ( $decoded['@graph'][0]['@type'] ?? 'Unknown' );

		update_post_meta( $post_id, '_schema_ai_data', $clean );
		update_post_meta( $post_id, '_schema_ai_type', $type );
		update_post_meta( $post_id, '_schema_ai_status', 'edited' );

		Schema_AI_Cache::delete( $post_id );

		$validation = Schema_AI_Validator::validate( $decoded );

		wp_send_json_success( array(
			'validation' => $validation,
			'type'       => $type,
		) );
	}

	/**
	 * AJAX handler: remove schema from a post.
	 */
	public function ajax_remove(): void {
		check_ajax_referer( 'schema_ai_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'schema-ai' ) ) );
		}

		delete_post_meta( $post_id, '_schema_ai_data' );
		delete_post_meta( $post_id, '_schema_ai_type' );
		update_post_meta( $post_id, '_schema_ai_status', 'none' );

		Schema_AI_Cache::delete( $post_id );

		wp_send_json_success();
	}
}
