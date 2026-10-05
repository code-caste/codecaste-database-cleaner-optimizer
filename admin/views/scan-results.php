<?php
/**
 * Detailed scan results view.
 *
 * Available variables: $ccdco_last_results, $ccdco_last_time.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccdco_active_tab = 'scan-results';
?>
<div class="wrap ccdco-wrap">
	<?php include __DIR__ . '/nav.php'; ?>

	<div class="ccdco-card">
		<div class="ccdco-card-header">
			<h2><?php esc_html_e( 'Scan Results', 'codecaste-database-cleaner-optimizer' ); ?></h2>
			<div>
				<button type="button" class="button button-primary" id="ccdco-run-scan">
					<?php esc_html_e( 'Run New Scan', 'codecaste-database-cleaner-optimizer' ); ?>
				</button>
				<span class="spinner ccdco-inline-spinner" id="ccdco-scan-spinner"></span>
			</div>
		</div>

		<p class="description">
			<?php
			echo $ccdco_last_time
				? esc_html(
					sprintf(
						/* translators: %s: human time diff */
						__( 'Results from the last scan, run %s ago. Scanning never modifies your data.', 'codecaste-database-cleaner-optimizer' ),
						human_time_diff( (int) $ccdco_last_time, time() )
					)
				)
				: esc_html__( 'No scan has been run yet. Scanning never modifies your data.', 'codecaste-database-cleaner-optimizer' );
			?>
		</p>

		<div id="ccdco-results-table-wrap">
			<?php if ( empty( $ccdco_last_results ) ) : ?>
				<p><?php esc_html_e( 'Run a scan to see results here.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped ccdco-paginated-table" data-page-size="10">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Scanner', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Severity', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Records', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Est. Size', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Cleanup Available', 'codecaste-database-cleaner-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody id="ccdco-results-table-body">
						<?php foreach ( $ccdco_last_results as $ccdco_result ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $ccdco_result['title'] ); ?></strong></td>
								<td>
									<span class="ccdco-badge ccdco-severity-<?php echo esc_attr( $ccdco_result['severity'] ); ?>">
										<?php echo esc_html( ucfirst( $ccdco_result['severity'] ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( number_format_i18n( $ccdco_result['count'] ) ); ?></td>
								<td><?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_result['size'] ) ); ?></td>
								<td><?php echo $ccdco_result['can_cleanup'] ? esc_html__( 'Yes', 'codecaste-database-cleaner-optimizer' ) : esc_html__( 'No', 'codecaste-database-cleaner-optimizer' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="ccdco-pagination" aria-label="Scan results pagination"></div>
			<?php endif; ?>
		</div>
	</div>

	<div id="ccdco-scan-progress" class="ccdco-progress-wrap" style="display:none;">
		<div class="ccdco-progress-bar"><div class="ccdco-progress-bar-fill"></div></div>
		<p class="ccdco-progress-text"></p>
	</div>
</div>
