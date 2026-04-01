<?php
/**
 * Admin Bulk Generate page view.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

$enabled_types = get_option( 'schema_ai_post_types', array( 'post', 'page' ) );
$counts        = array();

foreach ( $enabled_types as $type ) {
	$counts[ $type ] = Schema_AI_Bulk::get_counts( $type );
}

$state = get_option( Schema_AI_Bulk::STATE_OPTION, array() );
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Bulk Generate Schema', 'schema-ai' ); ?></h1>

	<!-- Bulk Form -->
	<div class="schema-ai-card">
		<h2><?php esc_html_e( 'Configuration', 'schema-ai' ); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="schema-ai-bulk-post-type"><?php esc_html_e( 'Post Type', 'schema-ai' ); ?></label>
				</th>
				<td>
					<select id="schema-ai-bulk-post-type">
						<?php foreach ( $enabled_types as $type ) : ?>
							<?php
							$type_obj = get_post_type_object( $type );
							$label    = $type_obj ? $type_obj->labels->name : $type;
							$c        = $counts[ $type ];
							?>
							<option value="<?php echo esc_attr( $type ); ?>">
								<?php
								printf(
									/* translators: 1: post type name, 2: total count, 3: with schema, 4: without schema, 5: errors */
									'%1$s (%2$d total, %3$d with schema, %4$d without, %5$d errors)',
									esc_html( $label ),
									$c['total'],
									$c['with'],
									$c['without'],
									$c['errors']
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'schema-ai' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="schema_ai_bulk_mode" value="missing" checked>
							<?php esc_html_e( 'Missing schema only', 'schema-ai' ); ?>
						</label><br>
						<label>
							<input type="radio" name="schema_ai_bulk_mode" value="errors">
							<?php esc_html_e( 'Errors only (retry failed)', 'schema-ai' ); ?>
						</label><br>
						<label>
							<input type="radio" name="schema_ai_bulk_mode" value="all">
							<?php esc_html_e( 'All posts (regenerate everything)', 'schema-ai' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="schema-ai-bulk-batch-size"><?php esc_html_e( 'Batch Size', 'schema-ai' ); ?></label>
				</th>
				<td>
					<input type="number" id="schema-ai-bulk-batch-size" value="10" min="1" max="50" class="small-text">
					<p class="description"><?php esc_html_e( 'Number of posts to process (1-50).', 'schema-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="schema-ai-bulk-delay"><?php esc_html_e( 'Delay Between Posts', 'schema-ai' ); ?></label>
				</th>
				<td>
					<input type="number" id="schema-ai-bulk-delay" value="2" min="1" max="30" class="small-text">
					<span><?php esc_html_e( 'seconds', 'schema-ai' ); ?></span>
					<p class="description"><?php esc_html_e( 'Delay between API calls to avoid rate limiting (1-30 seconds).', 'schema-ai' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="button" id="schema-ai-bulk-start" class="button button-primary">
				<?php esc_html_e( 'Start Bulk Generate', 'schema-ai' ); ?>
			</button>
			<button type="button" id="schema-ai-bulk-cancel" class="button button-secondary" style="display:none;">
				<?php esc_html_e( 'Cancel', 'schema-ai' ); ?>
			</button>
		</p>
	</div>

	<!-- Progress -->
	<div class="schema-ai-card" id="schema-ai-bulk-progress" style="display:none;">
		<h2><?php esc_html_e( 'Progress', 'schema-ai' ); ?></h2>

		<div class="schema-ai-progress-bar">
			<div class="schema-ai-progress-bar-fill" id="schema-ai-progress-fill" style="width:0%;"></div>
		</div>

		<p id="schema-ai-bulk-info" class="schema-ai-bulk-info"></p>
	</div>

	<!-- Log -->
	<div class="schema-ai-card" id="schema-ai-bulk-log-card" style="display:none;">
		<h2><?php esc_html_e( 'Processing Log', 'schema-ai' ); ?></h2>
		<div id="schema-ai-bulk-log" class="schema-ai-bulk-log"></div>
	</div>
</div>
