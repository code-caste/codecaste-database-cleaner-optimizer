<?php
/**
 * Scan history view.
 *
 * Available variables: $ccdco_history.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccdco_active_tab = 'history';
?>
<div class="wrap ccdco-wrap">
	<?php include __DIR__ . '/nav.php'; ?>

	<div class="ccdco-card">
		<div class="ccdco-card-header">
			<h2><?php esc_html_e( 'Scan History', 'codecaste-database-cleaner-optimizer' ); ?></h2>
			<?php if ( ! empty( $ccdco_history ) ) : ?>
				<button type="button" class="button" id="ccdco-clear-history">
					<?php esc_html_e( 'Clear History', 'codecaste-database-cleaner-optimizer' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( ! CCDCO_Scan_History::is_enabled() ) : ?>
			<p class="description">
				<?php
				printf(
					wp_kses(
						/* translators: %s: settings page URL */
						__( 'Scan history is currently disabled. Enable it on the <a href="%s">Settings</a> page to start recording scans.', 'codecaste-database-cleaner-optimizer' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'admin.php?page=codecaste-database-cleaner-optimizer-settings' ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( empty( $ccdco_history ) ) : ?>
			<p><?php esc_html_e( 'No scans recorded yet.', 'codecaste-database-cleaner-optimizer' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" id="ccdco-history-table" data-page-size="10">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'codecaste-database-cleaner-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Database Size', 'codecaste-database-cleaner-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Health Score', 'codecaste-database-cleaner-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Issues Found', 'codecaste-database-cleaner-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Potentially Cleanable', 'codecaste-database-cleaner-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'codecaste-database-cleaner-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ccdco_history as $ccdco_row ) : ?>
						<?php
						$ccdco_scan_date      = isset( $ccdco_row['scan_date'] ) ? $ccdco_row['scan_date'] : '';
						$ccdco_database_size  = isset( $ccdco_row['database_size'] ) ? (int) $ccdco_row['database_size'] : 0;
						$ccdco_health_score   = isset( $ccdco_row['health_score'] ) ? (int) $ccdco_row['health_score'] : 0;
						$ccdco_issue_count    = isset( $ccdco_row['issue_count'] ) ? (int) $ccdco_row['issue_count'] : 0;
						$ccdco_cleanable_size = isset( $ccdco_row['cleanable_size'] ) ? (int) $ccdco_row['cleanable_size'] : 0;
						$ccdco_scan_duration  = isset( $ccdco_row['scan_duration'] ) ? (float) $ccdco_row['scan_duration'] : 0.0;
						?>
						<tr>
							<td>
								<?php
								echo esc_html( CCDCO_Scan_History::format_scan_date( $ccdco_scan_date ) );
								?>
							</td>
							<td><?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_database_size ) ); ?></td>
							<td><?php echo esc_html( $ccdco_health_score ); ?>/100</td>
							<td><?php echo esc_html( number_format_i18n( $ccdco_issue_count ) ); ?></td>
							<td><?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_cleanable_size ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $ccdco_scan_duration, 2 ) ); ?>s</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="ccdco-pagination" aria-label="Scan history pagination"></div>
		<?php endif; ?>
	</div>
</div>
