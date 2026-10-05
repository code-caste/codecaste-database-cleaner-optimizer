<?php
/**
 * Cleanup view.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccdco_active_tab = 'cleanup';

$ccdco_actionable_items = array();
if ( ! empty( $ccdco_last_results ) ) {
	foreach ( $ccdco_scanners as $ccdco_scanner ) {
		$ccdco_id     = $ccdco_scanner->get_id();
		$ccdco_result = isset( $ccdco_last_results[ $ccdco_id ] ) ? $ccdco_last_results[ $ccdco_id ] : null;

		if ( ! $ccdco_result || empty( $ccdco_result['can_cleanup'] ) || empty( $ccdco_result['count'] ) ) {
			continue;
		}

		$ccdco_actionable_items[] = array(
			'id'      => $ccdco_id,
			'scanner' => $ccdco_scanner,
			'result'  => $ccdco_result,
		);
	}
}
?>
<div class="wrap ccdco-wrap">
	<?php include __DIR__ . '/nav.php'; ?>

	<div class="ccdco-card ccdco-cleanup-intro">
		<div class="ccdco-card-header">
			<div>
				<h2><?php esc_html_e( 'Cleanup', 'codecaste-database-cleaner-optimizer' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Optimize supported database records that your latest scan identified as eligible. Nothing is changed during scanning.', 'codecaste-database-cleaner-optimizer' ); ?>
				</p>
			</div>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=codecaste-database-cleaner-optimizer-scan-results' ) ); ?>">
				<?php esc_html_e( 'Review Scan Results', 'codecaste-database-cleaner-optimizer' ); ?>
			</a>
		</div>
		<div class="ccdco-cleanup-safety-note">
			<span class="dashicons dashicons-shield" aria-hidden="true"></span>
			<div>
				<strong><?php esc_html_e( 'Optimization is permanent.', 'codecaste-database-cleaner-optimizer' ); ?></strong>
				<span><?php esc_html_e( 'A live eligibility check runs immediately before each optimization. A current database backup is strongly recommended.', 'codecaste-database-cleaner-optimizer' ); ?></span>
			</div>
		</div>
	</div>

	<div id="ccdco-cleanup-content" class="ccdco-card">
		<div class="ccdco-section-heading">
			<div>
				<h2><?php esc_html_e( 'Optimization opportunities', 'codecaste-database-cleaner-optimizer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Only current, actionable findings are shown here.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
		</div>

		<?php if ( empty( $ccdco_last_results ) ) : ?>
			<div class="ccdco-empty-state">
				<strong><?php esc_html_e( 'Run a database scan first.', 'codecaste-database-cleaner-optimizer' ); ?></strong>
				<p><?php esc_html_e( 'After the scan completes, supported optimization opportunities will appear here.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
		<?php elseif ( empty( $ccdco_actionable_items ) ) : ?>
			<div class="ccdco-empty-state ccdco-empty-state-success">
				<strong><?php esc_html_e( 'Nothing needs optimization right now.', 'codecaste-database-cleaner-optimizer' ); ?></strong>
				<p><?php esc_html_e( 'The latest scan did not find any supported cleanup records that are currently eligible.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
		<?php else : ?>
			<div class="ccdco-cleanup-summary" aria-live="polite">
				<strong><?php echo esc_html( number_format_i18n( count( $ccdco_actionable_items ) ) ); ?></strong>
				<span><?php esc_html_e( 'optimization opportunities require review', 'codecaste-database-cleaner-optimizer' ); ?></span>
			</div>

			<div class="ccdco-cleanup-list">
				<?php foreach ( $ccdco_actionable_items as $ccdco_item ) : ?>
					<?php
					$ccdco_scanner = $ccdco_item['scanner'];
					$ccdco_result  = $ccdco_item['result'];
					$ccdco_id      = $ccdco_item['id'];
					$ccdco_is_orphan = ( false !== strpos( $ccdco_id, 'orphaned' ) );
					?>
					<article class="ccdco-cleanup-item" data-scanner="<?php echo esc_attr( $ccdco_id ); ?>">
						<div class="ccdco-cleanup-item-header">
							<div class="ccdco-cleanup-item-main">
								<div class="ccdco-cleanup-title-row">
									<h3><?php echo esc_html( $ccdco_result['title'] ); ?></h3>
									<span class="ccdco-badge ccdco-severity-<?php echo esc_attr( $ccdco_result['severity'] ); ?>">
										<?php echo esc_html( ucfirst( $ccdco_result['severity'] ) ); ?>
									</span>
								</div>
								<p class="description"><?php echo esc_html( $ccdco_scanner->get_description() ); ?></p>
							</div>
							<div class="ccdco-cleanup-stat-grid">
								<div><span><?php esc_html_e( 'Eligible records', 'codecaste-database-cleaner-optimizer' ); ?></span><strong class="ccdco-cleanup-count"><?php echo esc_html( number_format_i18n( $ccdco_result['count'] ) ); ?></strong></div>
								<div><span><?php esc_html_e( 'Estimated payload', 'codecaste-database-cleaner-optimizer' ); ?></span><strong class="ccdco-cleanup-size"><?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_result['size'] ) ); ?></strong></div>
							</div>
						</div>

						<?php if ( $ccdco_is_orphan ) : ?>
							<div class="ccdco-orphan-note">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Potentially orphaned data. Review before optimization because dynamic or custom references may not be detectable.', 'codecaste-database-cleaner-optimizer' ); ?></span>
							</div>
						<?php endif; ?>

						<div class="ccdco-cleanup-actions">
							<button type="button" class="button button-primary ccdco-start-cleanup">
								<?php esc_html_e( 'Optimize eligible records', 'codecaste-database-cleaner-optimizer' ); ?>
							</button>
							<span class="ccdco-cleanup-status" role="status"></span>
						</div>

						<div class="ccdco-cleanup-progress" hidden aria-live="polite">
							<div class="ccdco-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="ccdco-progress-bar-fill"></div></div>
							<p class="ccdco-progress-text"></p>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<div id="ccdco-confirm-modal" class="ccdco-modal" hidden aria-hidden="true">
	<div class="ccdco-modal-backdrop" tabindex="-1"></div>
	<div class="ccdco-modal-content" role="dialog" aria-modal="true" aria-labelledby="ccdco-confirm-title" aria-describedby="ccdco-confirm-description">
		<button type="button" class="ccdco-modal-close" id="ccdco-modal-close" aria-label="<?php esc_attr_e( 'Close dialog', 'codecaste-database-cleaner-optimizer' ); ?>">&times;</button>
		<h2 id="ccdco-confirm-title"><?php esc_html_e( 'Confirm optimization', 'codecaste-database-cleaner-optimizer' ); ?></h2>
		<p id="ccdco-confirm-description"><?php esc_html_e( 'This permanently changes database records. Review the current eligibility and continue only when you are sure.', 'codecaste-database-cleaner-optimizer' ); ?></p>
		<div class="ccdco-confirm-summary">
			<div><span><?php esc_html_e( 'Currently eligible', 'codecaste-database-cleaner-optimizer' ); ?></span><strong id="ccdco-modal-count">—</strong></div>
			<div><span><?php esc_html_e( 'Estimated payload', 'codecaste-database-cleaner-optimizer' ); ?></span><strong id="ccdco-modal-size">—</strong></div>
		</div>
		<p class="ccdco-confirm-live-note"><?php esc_html_e( 'The server performs another eligibility check when optimization begins and processes the full current set in safe batches.', 'codecaste-database-cleaner-optimizer' ); ?></p>
		<div class="ccdco-modal-actions">
			<button type="button" class="button" id="ccdco-modal-cancel"><?php esc_html_e( 'Cancel', 'codecaste-database-cleaner-optimizer' ); ?></button>
			<button type="button" class="button button-primary" id="ccdco-modal-confirm"><?php esc_html_e( 'Optimize now', 'codecaste-database-cleaner-optimizer' ); ?></button>
		</div>
	</div>
</div>

<div id="ccdco-result-modal" class="ccdco-modal" hidden aria-hidden="true">
	<div class="ccdco-modal-backdrop" tabindex="-1"></div>
	<div class="ccdco-modal-content" role="dialog" aria-modal="true" aria-labelledby="ccdco-result-title">
		<button type="button" class="ccdco-modal-close" data-close-result aria-label="<?php esc_attr_e( 'Close dialog', 'codecaste-database-cleaner-optimizer' ); ?>">&times;</button>
		<h2 id="ccdco-result-title"><?php esc_html_e( 'Optimization completed', 'codecaste-database-cleaner-optimizer' ); ?></h2>
		<div class="ccdco-result-grid">
			<div><span><?php esc_html_e( 'Records optimized', 'codecaste-database-cleaner-optimizer' ); ?></span><strong id="ccdco-result-processed">0</strong></div>
			<div><span><?php esc_html_e( 'Records remaining', 'codecaste-database-cleaner-optimizer' ); ?></span><strong id="ccdco-result-remaining">0</strong></div>
			<div><span><?php esc_html_e( 'Estimated payload processed', 'codecaste-database-cleaner-optimizer' ); ?></span><strong id="ccdco-result-payload">0 B</strong></div>
		</div>
		<p class="ccdco-result-note" id="ccdco-result-note"></p>
		<div class="ccdco-modal-actions">
			<button type="button" class="button button-primary" data-close-result><?php esc_html_e( 'Done', 'codecaste-database-cleaner-optimizer' ); ?></button>
		</div>
	</div>
</div>
