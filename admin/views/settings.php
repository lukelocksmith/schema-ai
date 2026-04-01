<?php
/**
 * Admin Settings page view.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

$api_key             = get_option( 'schema_ai_api_key', '' );
$model               = get_option( 'schema_ai_model', 'gemini-2.0-flash' );
$auto_generate       = get_option( 'schema_ai_auto_generate', 1 );
$post_types          = get_option( 'schema_ai_post_types', array( 'post', 'page' ) );
$publisher_name      = get_option( 'schema_ai_publisher_name', '' );
$publisher_url       = get_option( 'schema_ai_publisher_url', '' );
$publisher_logo      = get_option( 'schema_ai_publisher_logo', '' );
$exclude_categories  = get_option( 'schema_ai_exclude_categories', array() );

$available_types      = get_post_types( array( 'public' => true ), 'objects' );
$available_categories = get_categories( array( 'hide_empty' => false ) );
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Schema AI Settings', 'schema-ai' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'schema_ai_settings' ); ?>

		<table class="form-table" role="presentation">

			<!-- API Key -->
			<tr>
				<th scope="row">
					<label for="schema_ai_api_key"><?php esc_html_e( 'API Key', 'schema-ai' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="schema_ai_api_key"
						name="schema_ai_api_key"
						value="<?php echo esc_attr( ! empty( $api_key ) ? '••••••••' : '' ); ?>"
						class="regular-text"
						autocomplete="off"
					/>
					<button type="button" class="button" id="schema-ai-test-key"><?php esc_html_e( 'Test API Key', 'schema-ai' ); ?></button>
					<span id="schema-ai-test-result"></span>
					<p class="description">
						<?php esc_html_e( 'Your Google Gemini API key.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

			<!-- Model -->
			<tr>
				<th scope="row">
					<label for="schema_ai_model"><?php esc_html_e( 'Model', 'schema-ai' ); ?></label>
				</th>
				<td>
					<select id="schema_ai_model" name="schema_ai_model">
						<option value="gemini-2.0-flash" <?php selected( $model, 'gemini-2.0-flash' ); ?>>
							Gemini 2.0 Flash
						</option>
						<option value="gemini-2.5-flash" <?php selected( $model, 'gemini-2.5-flash' ); ?>>
							Gemini 2.5 Flash
						</option>
						<option value="gemini-2.5-pro" <?php selected( $model, 'gemini-2.5-pro' ); ?>>
							Gemini 2.5 Pro
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select the Gemini model for schema generation.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

			<!-- Auto-generate -->
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Auto-generate', 'schema-ai' ); ?>
				</th>
				<td>
					<label for="schema_ai_auto_generate">
						<input
							type="checkbox"
							id="schema_ai_auto_generate"
							name="schema_ai_auto_generate"
							value="1"
							<?php checked( $auto_generate, 1 ); ?>
						/>
						<?php esc_html_e( 'Automatically generate schema when a post is published.', 'schema-ai' ); ?>
					</label>
				</td>
			</tr>

			<!-- Post Types -->
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Post Types', 'schema-ai' ); ?>
				</th>
				<td>
					<fieldset>
						<?php foreach ( $available_types as $type ) : ?>
							<label>
								<input
									type="checkbox"
									name="schema_ai_post_types[]"
									value="<?php echo esc_attr( $type->name ); ?>"
									<?php checked( in_array( $type->name, $post_types, true ) ); ?>
								/>
								<?php echo esc_html( $type->labels->singular_name ); ?>
							</label><br/>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Select which post types should have schema generated.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

			<!-- Publisher Name -->
			<tr>
				<th scope="row">
					<label for="schema_ai_publisher_name"><?php esc_html_e( 'Publisher Name', 'schema-ai' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="schema_ai_publisher_name"
						name="schema_ai_publisher_name"
						value="<?php echo esc_attr( $publisher_name ); ?>"
						class="regular-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Organization name used in schema publisher field.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

			<!-- Publisher URL -->
			<tr>
				<th scope="row">
					<label for="schema_ai_publisher_url"><?php esc_html_e( 'Publisher URL', 'schema-ai' ); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="schema_ai_publisher_url"
						name="schema_ai_publisher_url"
						value="<?php echo esc_attr( $publisher_url ); ?>"
						class="regular-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Organization URL used in schema publisher field.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

			<!-- Publisher Logo -->
			<tr>
				<th scope="row">
					<label for="schema_ai_publisher_logo"><?php esc_html_e( 'Publisher Logo', 'schema-ai' ); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="schema_ai_publisher_logo"
						name="schema_ai_publisher_logo"
						value="<?php echo esc_attr( $publisher_logo ); ?>"
						class="regular-text"
					/>
					<p class="description">
						<?php esc_html_e( 'URL to the organization logo image.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

			<!-- Exclude Categories -->
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Exclude Categories', 'schema-ai' ); ?>
				</th>
				<td>
					<fieldset>
						<?php if ( ! empty( $available_categories ) ) : ?>
							<?php foreach ( $available_categories as $category ) : ?>
								<label>
									<input
										type="checkbox"
										name="schema_ai_exclude_categories[]"
										value="<?php echo esc_attr( $category->term_id ); ?>"
										<?php checked( in_array( $category->term_id, $exclude_categories, true ) ); ?>
									/>
									<?php echo esc_html( $category->name ); ?>
								</label><br/>
							<?php endforeach; ?>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'No categories found.', 'schema-ai' ); ?>
							</p>
						<?php endif; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Posts in selected categories will be excluded from auto-generation.', 'schema-ai' ); ?>
					</p>
				</td>
			</tr>

		</table>

		<?php submit_button(); ?>
	</form>
</div>
