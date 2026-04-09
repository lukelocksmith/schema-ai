<?php
/**
 * Admin Dashboard page view.
 *
 * Receives: $stats, $log, $api_stats
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Schema AI Dashboard', 'schema-ai' ); ?></h1>

	<!-- Overview Card -->
	<div class="schema-ai-card">
		<h2><?php esc_html_e( 'Overview', 'schema-ai' ); ?></h2>
		<div class="schema-ai-stats-grid">
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value"><?php echo esc_html( number_format( $stats['total'] ) ); ?></span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Total Posts', 'schema-ai' ); ?></span>
			</div>
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value schema-ai-stat--success">
					<?php echo esc_html( number_format( $stats['with_schema'] ) ); ?>
					<?php if ( $stats['total'] > 0 ) : ?>
						<small>(<?php echo esc_html( round( $stats['with_schema'] / $stats['total'] * 100 ) ); ?>%)</small>
					<?php endif; ?>
				</span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'With Schema', 'schema-ai' ); ?></span>
			</div>
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value"><?php echo esc_html( number_format( $stats['without'] ) ); ?></span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Without Schema', 'schema-ai' ); ?></span>
			</div>
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value schema-ai-stat--error">
					<?php echo esc_html( number_format( $stats['errors'] ) ); ?>
				</span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Errors', 'schema-ai' ); ?></span>
			</div>
		</div>

		<?php if ( ! empty( $stats['types'] ) ) : ?>
			<div class="schema-ai-types">
				<h3><?php esc_html_e( 'Schema Types', 'schema-ai' ); ?></h3>
				<?php foreach ( $stats['types'] as $type => $count ) : ?>
					<span class="schema-ai-badge">
						<?php echo esc_html( $type ); ?>
						<strong><?php echo esc_html( number_format( $count ) ); ?></strong>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- API Usage Card -->
	<div class="schema-ai-card">
		<h2><?php esc_html_e( 'API Usage', 'schema-ai' ); ?></h2>
		<div class="schema-ai-stats-grid">
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value"><?php echo esc_html( number_format( $api_stats['today_calls'] ) ); ?></span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Today Calls', 'schema-ai' ); ?></span>
			</div>
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value"><?php echo esc_html( number_format( $api_stats['today_tokens'] ) ); ?></span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Today Tokens', 'schema-ai' ); ?></span>
			</div>
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value"><?php echo esc_html( number_format( $api_stats['month_calls'] ) ); ?></span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Month Calls', 'schema-ai' ); ?></span>
			</div>
			<div class="schema-ai-stat">
				<span class="schema-ai-stat-value"><?php echo esc_html( number_format( $api_stats['month_tokens'] ) ); ?></span>
				<span class="schema-ai-stat-label"><?php esc_html_e( 'Month Tokens', 'schema-ai' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Recent Activity -->
	<div class="schema-ai-card">
		<h2><?php esc_html_e( 'Recent Activity', 'schema-ai' ); ?></h2>

		<?php if ( ! empty( $log ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'schema-ai' ); ?></th>
						<th><?php esc_html_e( 'Post', 'schema-ai' ); ?></th>
						<th><?php esc_html_e( 'Type', 'schema-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'schema-ai' ); ?></th>
						<th><?php esc_html_e( 'Details', 'schema-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'schema-ai' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'schema-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td>
								<?php echo esc_html( human_time_diff( strtotime( $entry['created_at'] ), current_time( 'timestamp' ) ) ); ?>
								<?php esc_html_e( 'ago', 'schema-ai' ); ?>
							</td>
							<td>
								<?php if ( ! empty( $entry['post_id'] ) && ! empty( $entry['post_title'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $entry['post_id'] ) ); ?>">
										<?php echo esc_html( $entry['post_title'] ); ?>
									</a>
								<?php elseif ( ! empty( $entry['post_id'] ) ) : ?>
									#<?php echo esc_html( $entry['post_id'] ); ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $entry['schema_type'] ) ) : ?>
									<span class="schema-ai-badge"><?php echo esc_html( $entry['schema_type'] ); ?></span>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td>
								<?php
								switch ( $entry['status'] ) {
									case 'success':
										echo '<span class="dashicons dashicons-yes-alt schema-ai-status--success" title="' . esc_attr__( 'Success', 'schema-ai' ) . '"></span>';
										break;
									case 'warning':
										echo '<span class="dashicons dashicons-warning schema-ai-status--warning" title="' . esc_attr__( 'Warning', 'schema-ai' ) . '"></span>';
										break;
									case 'error':
										echo '<span class="dashicons dashicons-dismiss schema-ai-status--error" title="' . esc_attr__( 'Error', 'schema-ai' ) . '"></span>';
										break;
									default:
										echo '<span class="dashicons dashicons-minus"></span>';
										break;
								}
								?>
							</td>
							<td>
								<?php if ( 'error' === $entry['status'] && ! empty( $entry['error_message'] ) ) : ?>
									<span class="schema-ai-error-detail" title="<?php echo esc_attr( $entry['error_message'] ); ?>">
										<?php echo esc_html( mb_strimwidth( $entry['error_message'], 0, 80, '…' ) ); ?>
									</span>
								<?php elseif ( 'warning' === $entry['status'] && ! empty( $entry['error_message'] ) ) : ?>
									<span class="schema-ai-warning-detail" title="<?php echo esc_attr( $entry['error_message'] ); ?>">
										<?php echo esc_html( mb_strimwidth( $entry['error_message'], 0, 80, '…' ) ); ?>
									</span>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format( (int) $entry['tokens_used'] ) ); ?></td>
							<td><?php echo esc_html( number_format( (int) $entry['duration_ms'] ) ); ?>ms</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="schema-ai-empty-state">
				<?php esc_html_e( 'No activity yet. Schema will appear here once posts are processed.', 'schema-ai' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
