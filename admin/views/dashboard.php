<?php
/**
 * Database Optimizer – single-page tabbed dashboard.
 *
 * Variables provided by CCDCO_Admin::render_dashboard():
 *   $ccdco_last_results  array  Stored scan results keyed by scanner ID.
 *   $ccdco_last_time     int    Unix timestamp of the last scan.
 *   $ccdco_scanners      array  CCDCO_Scanner_Interface[] of enabled scanners.
 *   $ccdco_all_scanners  array  CCDCO_Scanner_Interface[] of all scanners (for settings).
 *   $ccdco_settings      array  Plugin settings array.
 *   $ccdco_history       array  Scan history rows.
 *   $ccdco_tables        array  Raw DB table data (for the Database Tables tab).
 *   $ccdco_health        array  Stored health score data from the last scan (score, label).
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- Defaults ---------- */
$ccdco_last_results = isset( $ccdco_last_results ) && is_array( $ccdco_last_results ) ? $ccdco_last_results : array();
$ccdco_last_time    = isset( $ccdco_last_time ) ? (int) $ccdco_last_time : 0;
$ccdco_scanners     = isset( $ccdco_scanners )  && is_array( $ccdco_scanners )  ? $ccdco_scanners  : array();
$ccdco_all_scanners = isset( $ccdco_all_scanners ) && is_array( $ccdco_all_scanners ) ? $ccdco_all_scanners : array();
$ccdco_settings     = isset( $ccdco_settings )  && is_array( $ccdco_settings )  ? $ccdco_settings  : array();
$ccdco_history      = isset( $ccdco_history )   && is_array( $ccdco_history )   ? $ccdco_history   : array();
$ccdco_tables       = isset( $ccdco_tables )    && is_array( $ccdco_tables )    ? $ccdco_tables    : array();
$ccdco_health       = isset( $ccdco_health )    && is_array( $ccdco_health )    ? $ccdco_health    : array();

/* ---------- Aggregate stats ---------- */
$ccdco_cleanable_count = 0;
$ccdco_cleanable_size  = 0;
$ccdco_db_size         = 0;

$ccdco_scanner_map = array();
foreach ( $ccdco_scanners as $ccdco_sc ) {
	$ccdco_scanner_map[ $ccdco_sc->get_id() ] = $ccdco_sc;
}

foreach ( $ccdco_last_results as $id => $ccdco_result ) {
	if ( ! is_array( $ccdco_result ) ) {
		continue;
	}
	if ( 'table_size' === $id && isset( $ccdco_result['size'] ) ) {
		$ccdco_db_size = (int) $ccdco_result['size'];
	}
	if ( ! empty( $ccdco_result['can_cleanup'] ) && ! empty( $ccdco_result['count'] ) ) {
		++$ccdco_cleanable_count;
		$ccdco_cleanable_size += (int) ( $ccdco_result['size'] ?? 0 );
	}
}

// Shown in the site's own timezone (Settings > General), so it is correct in any country.
$ccdco_last_scan_label = $ccdco_last_time
	? CCDCO_Scan_History::format_timestamp( $ccdco_last_time )
	: __( 'Never', 'codecaste-database-cleaner-optimizer' );

/* ---------- Health score (always clamped to 0-100) ---------- */
$ccdco_health_score = null;
if ( isset( $ccdco_health['score'] ) ) {
	$ccdco_health_score = (int) $ccdco_health['score'];
} elseif ( ! empty( $ccdco_last_results ) ) {
	// Older installs: a scan exists but no score was stored, so derive it from the stored results.
	$ccdco_calc         = CCDCO_Health_Score::calculate( $ccdco_last_results );
	$ccdco_health_score = (int) $ccdco_calc['score'];
}
$ccdco_has_health   = null !== $ccdco_health_score;
$ccdco_health_score = $ccdco_has_health ? max( 0, min( 100, $ccdco_health_score ) ) : 0;
$ccdco_health_grade = $ccdco_health_score >= 90 ? 'excellent' : ( $ccdco_health_score >= 75 ? 'good' : ( $ccdco_health_score >= 50 ? 'notice' : 'warning' ) );
$ccdco_health_label = $ccdco_has_health ? CCDCO_Health_Score::get_label( $ccdco_health_score ) : __( 'Run a scan', 'codecaste-database-cleaner-optimizer' );

/* ---------- Dashicon map per scanner ---------- */
$ccdco_icon_map = array(
	'post_revisions'         => 'dashicons-backup',
	'auto_drafts'            => 'dashicons-edit',
	'transients'             => 'dashicons-clock',
	'orphaned_postmeta'      => 'dashicons-admin-generic',
	'orphaned_termmeta'      => 'dashicons-tag',
	'orphaned_commentmeta'   => 'dashicons-admin-comments',
	'autoloaded_options'     => 'dashicons-performance',
	'action_scheduler'       => 'dashicons-calendar-alt',
	'table_size'             => 'dashicons-database',
);

function ccdco_scanner_icon( $id, $map ) {
	foreach ( $map as $key => $ccdco_icon ) {
		if ( false !== strpos( $id, $key ) ) {
			return $ccdco_icon;
		}
	}
	return 'dashicons-database-view';
}

/* ---------- Settings helpers ---------- */
$ccdco_enabled_scanners = isset( $ccdco_settings['enabled_scanners'] ) ? (array) $ccdco_settings['enabled_scanners'] : array();
?>
<div class="wrap ccdco-wrap">

	<?php
	/*
	 * WordPress JS moves every admin notice to just after ".wp-header-end" (or, if missing,
	 * after the first h1/h2 in .wrap — which is our hero title). This marker sits ABOVE the
	 * hero so notices appear above "Database Optimizer" instead of inside the header.
	 */
	?>
	<hr class="wp-header-end" />

	<!-- ====================================================
	     HERO HEADER
	===================================================== -->
	<div class="ccdco-hero">
		<div class="ccdco-hero-left">
			<span class="dashicons dashicons-database ccdco-hero-icon" aria-hidden="true"></span>
			<div class="ccdco-hero-text">
				<h1 class="ccdco-hero-title"><?php esc_html_e( 'Database Optimizer', 'codecaste-database-cleaner-optimizer' ); ?></h1>
				<p class="ccdco-hero-sub"><?php esc_html_e( 'Scan, review, and safely clean your WordPress database.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
		</div>
		<div class="ccdco-hero-actions">
			<button type="button" class="ccdco-btn ccdco-btn-secondary" id="ccdco-run-scan">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<?php esc_html_e( 'Scan Database', 'codecaste-database-cleaner-optimizer' ); ?>
			</button>
		</div>
	</div>

	<!-- Scan progress (visible only while a scan is running) -->
	<div id="ccdco-scan-progress" class="ccdco-scan-progress" hidden role="status" aria-live="polite">
		<div class="ccdco-scan-progress-head">
			<span class="ccdco-spinner" aria-hidden="true"></span>
			<strong id="ccdco-scan-progress-title"><?php esc_html_e( 'Scanning your database…', 'codecaste-database-cleaner-optimizer' ); ?></strong>
			<span class="ccdco-scan-progress-pct" id="ccdco-scan-progress-pct">0%</span>
		</div>
		<div class="ccdco-scan-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="ccdco-scan-track">
			<div class="ccdco-scan-fill" id="ccdco-scan-fill"></div>
		</div>
		<p class="ccdco-scan-progress-step" id="ccdco-scan-progress-step"><?php esc_html_e( 'Preparing scan…', 'codecaste-database-cleaner-optimizer' ); ?></p>
	</div>

	<!-- Scan message banner -->
	<div id="ccdco-scan-message" class="ccdco-scan-banner" hidden role="status"></div>

	<!-- ====================================================
	     STAT CARDS
	===================================================== -->
	<div class="ccdco-stats-row">

		<div class="ccdco-stat-card ccdco-health-card" id="ccdco-health-card" data-grade="<?php echo esc_attr( $ccdco_has_health ? $ccdco_health_grade : 'none' ); ?>">
			<div class="ccdco-stat-icon-wrap">
				<span class="dashicons dashicons-heart" aria-hidden="true"></span>
			</div>
			<div class="ccdco-stat-body">
				<span class="ccdco-stat-label"><?php esc_html_e( 'Health Score', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong class="ccdco-stat-value">
					<span id="ccdco-stat-health"><?php echo $ccdco_has_health ? esc_html( $ccdco_health_score ) : '—'; ?></span><span class="ccdco-stat-suffix" id="ccdco-stat-health-suffix"<?php echo $ccdco_has_health ? '' : ' hidden'; ?>>/100</span>
				</strong>
				<span class="ccdco-health-label" id="ccdco-stat-health-label"><?php echo esc_html( $ccdco_health_label ); ?></span>
				<div class="ccdco-health-bar" aria-hidden="true"><span id="ccdco-health-bar-fill" style="width:<?php echo esc_attr( $ccdco_health_score ); ?>%"></span></div>
			</div>
		</div>

		<div class="ccdco-stat-card">
			<div class="ccdco-stat-icon-wrap ccdco-color-blue">
				<span class="dashicons dashicons-database" aria-hidden="true"></span>
			</div>
			<div class="ccdco-stat-body">
				<span class="ccdco-stat-label"><?php esc_html_e( 'Database Size', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong class="ccdco-stat-value" id="ccdco-stat-db-size">
					<?php echo esc_html( $ccdco_db_size ? CCDCO_DB_Helper::format_bytes( $ccdco_db_size ) : '—' ); ?>
				</strong>
			</div>
		</div>

		<div class="ccdco-stat-card">
			<div class="ccdco-stat-icon-wrap ccdco-color-orange">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			</div>
			<div class="ccdco-stat-body">
				<span class="ccdco-stat-label"><?php esc_html_e( 'Items to Clean', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong class="ccdco-stat-value ccdco-color-orange-text" id="ccdco-stat-items">
					<?php echo esc_html( number_format_i18n( $ccdco_cleanable_count ) ); ?>
				</strong>
			</div>
		</div>

		<div class="ccdco-stat-card">
			<div class="ccdco-stat-icon-wrap ccdco-color-green">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			</div>
			<div class="ccdco-stat-body">
				<span class="ccdco-stat-label"><?php esc_html_e( 'Reclaimable Space', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong class="ccdco-stat-value ccdco-color-green-text" id="ccdco-stat-cleanable">
					<?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_cleanable_size ) ); ?>
				</strong>
			</div>
		</div>

		<div class="ccdco-stat-card">
			<div class="ccdco-stat-icon-wrap ccdco-color-muted">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
			</div>
			<div class="ccdco-stat-body">
				<span class="ccdco-stat-label"><?php esc_html_e( 'Last Scan', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong class="ccdco-stat-value ccdco-stat-scan-time" id="ccdco-stat-last-scan">
					<?php echo esc_html( $ccdco_last_scan_label ); ?>
				</strong>
			</div>
		</div>

	</div>

	<!-- ====================================================
	     TAB NAVIGATION
	===================================================== -->
	<div class="ccdco-tab-bar" role="tablist" aria-label="<?php esc_attr_e( 'Plugin sections', 'codecaste-database-cleaner-optimizer' ); ?>">
		<button class="ccdco-tab ccdco-tab-active" data-tab="cleanup" role="tab" aria-selected="true" aria-controls="ccdco-panel-cleanup">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			<?php esc_html_e( 'Cleanup Items', 'codecaste-database-cleaner-optimizer' ); ?>
			<?php if ( $ccdco_cleanable_count > 0 ) : ?>
				<span class="ccdco-tab-badge" id="ccdco-tab-badge-cleanup"><?php echo esc_html( number_format_i18n( $ccdco_cleanable_count ) ); ?></span>
			<?php endif; ?>
		</button>
		<button class="ccdco-tab" data-tab="tables" role="tab" aria-selected="false" aria-controls="ccdco-panel-tables">
			<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
			<?php esc_html_e( 'Database Tables', 'codecaste-database-cleaner-optimizer' ); ?>
		</button>
		<button class="ccdco-tab" data-tab="history" role="tab" aria-selected="false" aria-controls="ccdco-panel-history">
			<span class="dashicons dashicons-chart-line" aria-hidden="true"></span>
			<?php esc_html_e( 'History', 'codecaste-database-cleaner-optimizer' ); ?>
		</button>
		<button class="ccdco-tab" data-tab="settings" role="tab" aria-selected="false" aria-controls="ccdco-panel-settings">
			<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
			<?php esc_html_e( 'Settings', 'codecaste-database-cleaner-optimizer' ); ?>
		</button>
	</div>

	<!-- ====================================================
	     PANEL: CLEANUP ITEMS
	===================================================== -->
	<div id="ccdco-panel-cleanup" class="ccdco-panel ccdco-panel-active" role="tabpanel" aria-labelledby="ccdco-tab-cleanup">

		<?php $ccdco_has_results = ! empty( $ccdco_last_results ); ?>

		<!-- Empty state: always in the DOM so a first scan can hide it without a page reload. -->
		<div id="ccdco-cleanup-empty" class="ccdco-empty-hero"<?php echo $ccdco_has_results ? ' hidden' : ''; ?>>
			<span class="dashicons dashicons-search ccdco-empty-icon" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'No scan results yet', 'codecaste-database-cleaner-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Click "Scan Database" above to analyze your database. Scanning is read-only and never modifies your data.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			<button type="button" class="ccdco-btn ccdco-btn-primary ccdco-btn-lg" id="ccdco-run-scan-empty">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<?php esc_html_e( 'Scan Now', 'codecaste-database-cleaner-optimizer' ); ?>
			</button>
		</div>

		<!-- Results area: always in the DOM. -->
		<div id="ccdco-cleanup-results"<?php echo $ccdco_has_results ? '' : ' hidden'; ?>>

			<!-- Toolbar: Optimize All lives here, with the cleanup items it acts on -->
			<div class="ccdco-cleanup-toolbar">
				<div>
					<h2><?php esc_html_e( 'Cleanup Items', 'codecaste-database-cleaner-optimizer' ); ?></h2>
					<p class="ccdco-panel-desc"><?php esc_html_e( 'Clean items one at a time, or optimize everything eligible in one go.', 'codecaste-database-cleaner-optimizer' ); ?></p>
				</div>
				<button type="button" class="ccdco-btn ccdco-btn-primary" id="ccdco-optimize-all" <?php echo $ccdco_cleanable_count > 0 ? '' : 'disabled'; ?>>
					<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
					<?php esc_html_e( 'Optimize All', 'codecaste-database-cleaner-optimizer' ); ?>
				</button>
			</div>

			<!-- Safety note -->
			<div class="ccdco-notice-bar ccdco-notice-bar-warning">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Cleanup is permanent. A live eligibility check runs before each operation. We strongly recommend a full database backup first.', 'codecaste-database-cleaner-optimizer' ); ?></span>
			</div>

			<!-- Cleanup item list -->
			<div id="ccdco-cleanup-list" class="ccdco-cleanup-list">
				<?php foreach ( $ccdco_last_results as $id => $ccdco_result ) : ?>
					<?php
					if ( ! is_array( $ccdco_result ) ) {
						continue;
					}
					if ( 'table_size' === $id ) {
						continue; // table_size is meta-only, not a cleanup target
					}

					$ccdco_sc          = isset( $ccdco_scanner_map[ $id ] ) ? $ccdco_scanner_map[ $id ] : null;
					$ccdco_title       = ! empty( $ccdco_result['title'] ) ? $ccdco_result['title'] : ( $ccdco_sc ? $ccdco_sc->get_label() : $id );
					$ccdco_description = $ccdco_sc ? $ccdco_sc->get_description() : '';
					$ccdco_severity    = isset( $ccdco_result['severity'] ) ? $ccdco_result['severity'] : 'info';
					$ccdco_can_cleanup = ! empty( $ccdco_result['can_cleanup'] );
					$ccdco_count       = (int) ( $ccdco_result['count'] ?? 0 );
					$ccdco_size        = (int) ( $ccdco_result['size'] ?? 0 );
					$ccdco_icon        = ccdco_scanner_icon( $id, $ccdco_icon_map );
					$ccdco_is_orphan   = false !== strpos( $id, 'orphaned' );
					?>
					<div class="ccdco-cleanup-row<?php echo $ccdco_can_cleanup ? ' ccdco-has-action' : ''; ?>"
					     data-scanner="<?php echo esc_attr( $id ); ?>"
					     data-count="<?php echo esc_attr( $ccdco_count ); ?>"
					     data-size="<?php echo esc_attr( $ccdco_size ); ?>">

						<!-- Icon -->
						<div class="ccdco-row-icon ccdco-row-icon-<?php echo esc_attr( $ccdco_severity ); ?>">
							<span class="dashicons <?php echo esc_attr( $ccdco_icon ); ?>" aria-hidden="true"></span>
						</div>

						<?php $ccdco_preview_items = ( $ccdco_is_orphan && ! empty( $ccdco_result['details'] ) && is_array( $ccdco_result['details'] ) ) ? $ccdco_result['details'] : array(); ?>

						<!-- Title + description -->
						<div class="ccdco-row-info">
							<div class="ccdco-row-title-line">
								<strong class="ccdco-row-title"><?php echo esc_html( $ccdco_title ); ?></strong>
								<?php if ( $ccdco_is_orphan && ! empty( $ccdco_preview_items ) ) : ?>
									<button type="button" class="ccdco-pill ccdco-pill-warning ccdco-toggle-preview" aria-expanded="false">
										<?php esc_html_e( 'Review', 'codecaste-database-cleaner-optimizer' ); ?>
									</button>
								<?php elseif ( $ccdco_is_orphan ) : ?>
									<span class="ccdco-pill ccdco-pill-warning"><?php esc_html_e( 'Review', 'codecaste-database-cleaner-optimizer' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( $ccdco_description ) : ?>
								<span class="ccdco-row-desc"><?php echo esc_html( $ccdco_description ); ?></span>
							<?php endif; ?>
						</div>

						<!-- Count + size -->
						<div class="ccdco-row-meta">
							<?php if ( $ccdco_can_cleanup && $ccdco_count > 0 ) : ?>
								<span class="ccdco-count-badge"><?php echo esc_html( number_format_i18n( $ccdco_count ) ); ?></span>
							<?php else : ?>
								<span class="ccdco-count-badge ccdco-count-zero">0</span>
							<?php endif; ?>
							<span class="ccdco-size-label"><?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_size ) ); ?></span>
						</div>

						<!-- Action -->
						<div class="ccdco-row-action">
							<?php if ( $ccdco_can_cleanup && $ccdco_count > 0 ) : ?>
								<button type="button"
								        class="ccdco-btn ccdco-btn-clean ccdco-start-cleanup"
								        data-scanner="<?php echo esc_attr( $id ); ?>"
								        data-count="<?php echo esc_attr( $ccdco_count ); ?>"
								        data-size="<?php echo esc_attr( CCDCO_DB_Helper::format_bytes( $ccdco_size ) ); ?>"
								        data-size-bytes="<?php echo esc_attr( (int) $ccdco_size ); ?>">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									<?php esc_html_e( 'Clean', 'codecaste-database-cleaner-optimizer' ); ?>
								</button>
							<?php else : ?>
								<span class="ccdco-clean-done">
									<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
									<?php esc_html_e( 'Clean', 'codecaste-database-cleaner-optimizer' ); ?>
								</span>
							<?php endif; ?>
						</div>

						<!-- Progress (hidden until running) -->
						<div class="ccdco-row-progress" hidden aria-live="polite">
							<div class="ccdco-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
								<div class="ccdco-progress-fill"></div>
							</div>
							<span class="ccdco-progress-label"></span>
						</div>

						<?php if ( ! empty( $ccdco_preview_items ) ) : ?>
							<div class="ccdco-row-preview" hidden>
								<div class="ccdco-table-wrap">
									<table class="ccdco-preview-table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Meta ID', 'codecaste-database-cleaner-optimizer' ); ?></th>
												<th><?php esc_html_e( 'Missing Parent ID', 'codecaste-database-cleaner-optimizer' ); ?></th>
												<th><?php esc_html_e( 'Meta Key', 'codecaste-database-cleaner-optimizer' ); ?></th>
												<th><?php esc_html_e( 'Meta Value (preview)', 'codecaste-database-cleaner-optimizer' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $ccdco_preview_items as $ccdco_preview_item ) : ?>
												<tr>
													<td><?php echo esc_html( $ccdco_preview_item['id'] ?? '' ); ?></td>
													<td><?php echo esc_html( $ccdco_preview_item['parent_id'] ?? '' ); ?></td>
													<td><code><?php echo esc_html( $ccdco_preview_item['meta_key_name'] ?? '' ); ?></code></td>
													<td><?php echo esc_html( $ccdco_preview_item['meta_preview'] ?? '' ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<?php if ( $ccdco_count > count( $ccdco_preview_items ) ) : ?>
									<p class="ccdco-preview-more">
										<?php
										printf(
											/* translators: %1$s: number of rows shown, %2$s: total eligible rows */
											esc_html__( 'Showing %1$s of %2$s eligible records.', 'codecaste-database-cleaner-optimizer' ),
											esc_html( number_format_i18n( count( $ccdco_preview_items ) ) ),
											esc_html( number_format_i18n( $ccdco_count ) )
										);
										?>
									</p>
								<?php endif; ?>
							</div>
						<?php endif; ?>

					</div>
				<?php endforeach; ?>
			</div>
		</div><!-- #ccdco-cleanup-results -->
	</div>

	<!-- ====================================================
	     PANEL: DATABASE TABLES
	===================================================== -->
	<div id="ccdco-panel-tables" class="ccdco-panel" role="tabpanel" hidden>

		<div class="ccdco-panel-header">
			<div>
				<h2><?php esc_html_e( 'Database Tables', 'codecaste-database-cleaner-optimizer' ); ?></h2>
				<p class="ccdco-panel-desc"><?php esc_html_e( 'Scan for tables that may belong to deactivated or deleted plugins. Review carefully before deleting — some tables may be shared across plugins.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
			<div class="ccdco-panel-header-actions">
				<label class="ccdco-checkbox-inline">
					<input type="checkbox" id="ccdco-tables-force-refresh" />
					<?php esc_html_e( 'Force full rescan', 'codecaste-database-cleaner-optimizer' ); ?>
				</label>
				<button type="button" class="ccdco-btn ccdco-btn-secondary-light" id="ccdco-tables-scan">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<?php esc_html_e( 'Scan Tables', 'codecaste-database-cleaner-optimizer' ); ?>
				</button>
			</div>
		</div>

		<p class="ccdco-panel-note"><?php esc_html_e( 'Results are cached for 30 minutes for speed. Tick "Force full rescan" after installing or removing a plugin/theme to get fully up-to-date results.', 'codecaste-database-cleaner-optimizer' ); ?></p>

		<div id="ccdco-tables-wrap">
			<?php if ( ! empty( $ccdco_tables ) ) : ?>
				<?php include CCDCO_PLUGIN_DIR . 'admin/views/database-tables.php'; ?>
			<?php else : ?>
				<div class="ccdco-empty-panel">
					<span class="dashicons dashicons-list-view ccdco-empty-icon" aria-hidden="true"></span>
					<p><?php esc_html_e( 'Click "Scan Tables" to detect tables that may no longer be in use.', 'codecaste-database-cleaner-optimizer' ); ?></p>
					<button type="button" class="ccdco-btn ccdco-btn-primary" id="ccdco-tables-scan-trigger" style="margin-top:14px">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<?php esc_html_e( 'Scan Tables Now', 'codecaste-database-cleaner-optimizer' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>

	</div>

	<!-- ====================================================
	     PANEL: HISTORY
	===================================================== -->
	<div id="ccdco-panel-history" class="ccdco-panel" role="tabpanel" hidden>

		<div class="ccdco-panel-header">
			<div>
				<h2><?php esc_html_e( 'Scan History', 'codecaste-database-cleaner-optimizer' ); ?></h2>
				<p class="ccdco-panel-desc"><?php esc_html_e( 'A record of every scan run on this site.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
			<?php if ( ! empty( $ccdco_history ) ) : ?>
				<button type="button" class="ccdco-btn ccdco-btn-danger" id="ccdco-clear-history">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<?php esc_html_e( 'Clear History', 'codecaste-database-cleaner-optimizer' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( empty( $ccdco_history ) ) : ?>
			<div class="ccdco-empty-panel">
				<span class="dashicons dashicons-chart-line ccdco-empty-icon" aria-hidden="true"></span>
				<p><?php esc_html_e( 'No scan history yet. Run a scan to start tracking.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			</div>
		<?php else : ?>
			<div class="ccdco-table-wrap">
				<table class="ccdco-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'DB Size', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Health Score', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Issues', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Reclaimable', 'codecaste-database-cleaner-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'codecaste-database-cleaner-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ccdco_history as $ccdco_row ) : ?>
						<?php
						$ccdco_score       = (int) ( $ccdco_row['health_score'] ?? 0 );
						$ccdco_score_grade = $ccdco_score >= 90 ? 'excellent' : ( $ccdco_score >= 75 ? 'good' : ( $ccdco_score >= 50 ? 'notice' : 'warning' ) );
						$ccdco_scan_label  = isset( $ccdco_row['scan_date'] ) ? CCDCO_Scan_History::format_scan_date( $ccdco_row['scan_date'] ) : '';
						?>
						<tr>
							<td><?php echo esc_html( '' !== $ccdco_scan_label ? $ccdco_scan_label : '—' ); ?></td>
							<td><?php echo esc_html( CCDCO_DB_Helper::format_bytes( (int) ( $ccdco_row['database_size'] ?? 0 ) ) ); ?></td>
							<td>
								<span class="ccdco-score-chip ccdco-score-<?php echo esc_attr( $ccdco_score_grade ); ?>">
									<?php echo esc_html( $ccdco_score ); ?>/100
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $ccdco_row['issue_count'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( CCDCO_DB_Helper::format_bytes( (int) ( $ccdco_row['cleanable_size'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( round( (float) ( $ccdco_row['scan_duration'] ?? 0 ), 2 ) . 's' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- ====================================================
	     PANEL: SETTINGS
	===================================================== -->
	<div id="ccdco-panel-settings" class="ccdco-panel" role="tabpanel" hidden>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=codecaste-database-cleaner-optimizer' ) ); ?>">
			<?php wp_nonce_field( 'ccdco_save_settings', 'ccdco_settings_nonce' ); ?>

			<!-- General settings -->
			<div class="ccdco-settings-section">
				<h2><?php esc_html_e( 'General', 'codecaste-database-cleaner-optimizer' ); ?></h2>

				<div class="ccdco-setting-row">
					<div class="ccdco-setting-label">
						<label for="ccdco-enable-history"><?php esc_html_e( 'Scan History', 'codecaste-database-cleaner-optimizer' ); ?></label>
						<p class="ccdco-setting-desc"><?php esc_html_e( 'Store a record of every scan (date, size, score, issues, duration).', 'codecaste-database-cleaner-optimizer' ); ?></p>
					</div>
					<div class="ccdco-setting-control">
						<label class="ccdco-toggle">
							<input type="checkbox" id="ccdco-enable-history" name="ccdco_settings[enable_scan_history]" value="1" <?php checked( ! empty( $ccdco_settings['enable_scan_history'] ) ); ?> />
							<span class="ccdco-toggle-track"></span>
						</label>
					</div>
				</div>

				<div class="ccdco-setting-row">
					<div class="ccdco-setting-label">
						<label for="ccdco-batch-size"><?php esc_html_e( 'Cleanup Batch Size', 'codecaste-database-cleaner-optimizer' ); ?></label>
						<p class="ccdco-setting-desc"><?php esc_html_e( 'How many records are deleted in one step while cleaning. Lower it if cleanup times out or your host is slow.', 'codecaste-database-cleaner-optimizer' ); ?></p>
						<details class="ccdco-help">
							<summary><?php esc_html_e( 'What does this mean? (example)', 'codecaste-database-cleaner-optimizer' ); ?></summary>
							<div class="ccdco-help-body">
								<p><?php esc_html_e( 'Cleaning a big database in one go can overload your server. The plugin therefore cleans in small steps, and this number is the size of each step. It does not limit how much gets cleaned in total — it keeps going until everything eligible is gone.', 'codecaste-database-cleaner-optimizer' ); ?></p>
								<p class="ccdco-help-example"><strong><?php esc_html_e( 'Example:', 'codecaste-database-cleaner-optimizer' ); ?></strong> <?php esc_html_e( 'You have 10,000 expired transients.', 'codecaste-database-cleaner-optimizer' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Batch size 500 → 20 steps of 500 records (default, fine for most sites).', 'codecaste-database-cleaner-optimizer' ); ?></li>
									<li><?php esc_html_e( 'Batch size 100 → 100 smaller steps. Slower overall, but gentler on cheap shared hosting.', 'codecaste-database-cleaner-optimizer' ); ?></li>
									<li><?php esc_html_e( 'Batch size 2,000 → 5 big steps. Faster, but only use on a strong server.', 'codecaste-database-cleaner-optimizer' ); ?></li>
								</ul>
								<p class="ccdco-help-tip"><?php esc_html_e( 'Not sure? Leave it at 500. Only lower it if you see "Something went wrong" or a timeout while cleaning. Allowed range: 50–5,000.', 'codecaste-database-cleaner-optimizer' ); ?></p>
							</div>
						</details>
					</div>
					<div class="ccdco-setting-control">
						<input type="number" id="ccdco-batch-size" name="ccdco_settings[batch_size]"
						       min="50" max="5000" step="50"
						       value="<?php echo esc_attr( $ccdco_settings['batch_size'] ?? 500 ); ?>"
						       class="ccdco-number-input" />
					</div>
				</div>

				<div class="ccdco-setting-row">
					<div class="ccdco-setting-label">
						<label for="ccdco-keep-revisions"><?php esc_html_e( 'Revisions to Keep', 'codecaste-database-cleaner-optimizer' ); ?></label>
						<p class="ccdco-setting-desc"><?php esc_html_e( 'Every time you click Update on a post or page, WordPress saves an old copy (a "revision"). This is how many of the newest copies are kept for each post — older ones are removed.', 'codecaste-database-cleaner-optimizer' ); ?></p>
						<details class="ccdco-help">
							<summary><?php esc_html_e( 'What does this mean? (example)', 'codecaste-database-cleaner-optimizer' ); ?></summary>
							<div class="ccdco-help-body">
								<p><?php esc_html_e( 'Revisions let you go back to an earlier version of a post, but they pile up quickly and make the database bigger. The number here is applied to each post separately.', 'codecaste-database-cleaner-optimizer' ); ?></p>
								<p class="ccdco-help-example"><strong><?php esc_html_e( 'Example:', 'codecaste-database-cleaner-optimizer' ); ?></strong> <?php esc_html_e( 'Your post "About Us" has 30 saved revisions.', 'codecaste-database-cleaner-optimizer' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Revisions to keep = 5 → the 5 newest are kept, 25 older ones can be cleaned (default).', 'codecaste-database-cleaner-optimizer' ); ?></li>
									<li><?php esc_html_e( 'Revisions to keep = 10 → the 10 newest are kept, 20 can be cleaned.', 'codecaste-database-cleaner-optimizer' ); ?></li>
									<li><?php esc_html_e( 'Revisions to keep = 0 → all 30 can be cleaned. You will not be able to restore earlier versions.', 'codecaste-database-cleaner-optimizer' ); ?></li>
								</ul>
								<p class="ccdco-help-tip"><?php esc_html_e( 'Tip: after changing this, click "Scan Database" again so the Cleanup list uses the new number. Allowed range: 0–50.', 'codecaste-database-cleaner-optimizer' ); ?></p>
							</div>
						</details>
					</div>
					<div class="ccdco-setting-control">
						<input type="number" id="ccdco-keep-revisions" name="ccdco_settings[revisions_keep_latest]"
						       min="0" max="50"
						       value="<?php echo esc_attr( $ccdco_settings['revisions_keep_latest'] ?? 5 ); ?>"
						       class="ccdco-number-input" />
					</div>
				</div>

				<div class="ccdco-setting-row">
					<div class="ccdco-setting-label">
						<label for="ccdco-as-days"><?php esc_html_e( 'Action Scheduler Retention (days)', 'codecaste-database-cleaner-optimizer' ); ?></label>
						<p class="ccdco-setting-desc"><?php esc_html_e( 'Plugins such as WooCommerce use "Action Scheduler" to run background tasks (emails, syncs, renewals). Finished, failed or cancelled tasks older than this many days can be cleaned.', 'codecaste-database-cleaner-optimizer' ); ?></p>
						<details class="ccdco-help">
							<summary><?php esc_html_e( 'What does this mean? (example)', 'codecaste-database-cleaner-optimizer' ); ?></summary>
							<div class="ccdco-help-body">
								<p><?php esc_html_e( 'Every background task leaves a record in the database, even after it has finished. On busy stores this can grow to hundreds of thousands of rows. This setting decides how long those old records are kept as a log before they become eligible for cleanup. Pending tasks that still have to run are never touched.', 'codecaste-database-cleaner-optimizer' ); ?></p>
								<p class="ccdco-help-example"><strong><?php esc_html_e( 'Example:', 'codecaste-database-cleaner-optimizer' ); ?></strong> <?php esc_html_e( 'Today is 30 September. A finished task ran on 5 September (25 days ago) and another on 10 August (51 days ago).', 'codecaste-database-cleaner-optimizer' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Retention = 30 days → only the 10 August task can be cleaned; the 5 September task is kept (default).', 'codecaste-database-cleaner-optimizer' ); ?></li>
									<li><?php esc_html_e( 'Retention = 7 days → both tasks can be cleaned.', 'codecaste-database-cleaner-optimizer' ); ?></li>
									<li><?php esc_html_e( 'Retention = 90 days → neither can be cleaned yet; you keep a longer log for troubleshooting.', 'codecaste-database-cleaner-optimizer' ); ?></li>
								</ul>
								<p class="ccdco-help-tip"><?php esc_html_e( 'Only applies if Action Scheduler exists on your site (for example WooCommerce is active). Allowed range: 1–365 days.', 'codecaste-database-cleaner-optimizer' ); ?></p>
							</div>
						</details>
					</div>
					<div class="ccdco-setting-control">
						<input type="number" id="ccdco-as-days" name="ccdco_settings[action_scheduler_retention_days]"
						       min="1" max="365"
						       value="<?php echo esc_attr( $ccdco_settings['action_scheduler_retention_days'] ?? 30 ); ?>"
						       class="ccdco-number-input" />
					</div>
				</div>
			</div>

			<!-- Scanners -->
			<div class="ccdco-settings-section">
				<h2><?php esc_html_e( 'Active Scanners', 'codecaste-database-cleaner-optimizer' ); ?></h2>
				<p class="ccdco-panel-desc"><?php esc_html_e( 'Disable any scanner you do not want to include in a scan.', 'codecaste-database-cleaner-optimizer' ); ?></p>

				<?php foreach ( $ccdco_all_scanners as $ccdco_sc ) : ?>
					<?php if ( 'table_size' === $ccdco_sc->get_id() ) : ?>
						<?php continue; // Always on; the Database Size stat depends on it, so it is not user-toggleable. ?>
					<?php endif; ?>
					<div class="ccdco-setting-row">
						<div class="ccdco-setting-label">
							<label for="ccdco-scanner-<?php echo esc_attr( $ccdco_sc->get_id() ); ?>"><?php echo esc_html( $ccdco_sc->get_label() ); ?></label>
							<p class="ccdco-setting-desc"><?php echo esc_html( $ccdco_sc->get_description() ); ?></p>
							<?php if ( ! $ccdco_sc->is_available() ) : ?>
								<em class="ccdco-unavailable"><?php esc_html_e( 'Not available on this site.', 'codecaste-database-cleaner-optimizer' ); ?></em>
							<?php endif; ?>
						</div>
						<div class="ccdco-setting-control">
							<label class="ccdco-toggle">
								<input type="checkbox"
								       id="ccdco-scanner-<?php echo esc_attr( $ccdco_sc->get_id() ); ?>"
								       name="ccdco_settings[enabled_scanners][]"
								       value="<?php echo esc_attr( $ccdco_sc->get_id() ); ?>"
								       <?php checked( in_array( $ccdco_sc->get_id(), $ccdco_enabled_scanners, true ) ); ?>
								       <?php disabled( ! $ccdco_sc->is_available() ); ?> />
								<span class="ccdco-toggle-track"></span>
							</label>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="ccdco-settings-footer">
				<?php submit_button( __( 'Save Settings', 'codecaste-database-cleaner-optimizer' ), 'primary ccdco-btn-save', 'submit', false ); ?>
			</div>
		</form>
	</div>

</div><!-- .ccdco-wrap -->

<!-- ====================================================
     CONFIRM MODAL
===================================================== -->
<div id="ccdco-confirm-modal" class="ccdco-modal" hidden aria-hidden="true">
	<div class="ccdco-modal-backdrop" tabindex="-1"></div>
	<div class="ccdco-modal-box" role="dialog" aria-modal="true" aria-labelledby="ccdco-confirm-title">
		<button type="button" class="ccdco-modal-close" id="ccdco-modal-close" aria-label="<?php esc_attr_e( 'Close', 'codecaste-database-cleaner-optimizer' ); ?>">&times;</button>
		<div class="ccdco-modal-icon">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		</div>
		<h2 id="ccdco-confirm-title"><?php esc_html_e( 'Confirm Cleanup', 'codecaste-database-cleaner-optimizer' ); ?></h2>
		<p id="ccdco-confirm-desc"><?php esc_html_e( 'This will permanently remove all currently eligible records. A live check runs again when the operation starts.', 'codecaste-database-cleaner-optimizer' ); ?></p>
		<div class="ccdco-confirm-stats">
			<div>
				<span><?php esc_html_e( 'Records', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong id="ccdco-modal-count">—</strong>
			</div>
			<div>
				<span><?php esc_html_e( 'Est. size', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong id="ccdco-modal-size">—</strong>
			</div>
		</div>
		<div class="ccdco-modal-actions">
			<button type="button" class="ccdco-btn ccdco-btn-ghost" id="ccdco-modal-cancel"><?php esc_html_e( 'Cancel', 'codecaste-database-cleaner-optimizer' ); ?></button>
			<button type="button" class="ccdco-btn ccdco-btn-danger" id="ccdco-modal-confirm"><?php esc_html_e( 'Yes, Clean Now', 'codecaste-database-cleaner-optimizer' ); ?></button>
		</div>
	</div>
</div>

<!-- ====================================================
     UNSAVED SETTINGS MODAL
===================================================== -->
<div id="ccdco-unsaved-modal" class="ccdco-modal" hidden aria-hidden="true">
	<div class="ccdco-modal-backdrop" tabindex="-1"></div>
	<div class="ccdco-modal-box" role="alertdialog" aria-modal="true" aria-labelledby="ccdco-unsaved-title" aria-describedby="ccdco-unsaved-desc">
		<button type="button" class="ccdco-modal-close" id="ccdco-unsaved-close" aria-label="<?php esc_attr_e( 'Close', 'codecaste-database-cleaner-optimizer' ); ?>">&times;</button>
		<div class="ccdco-modal-icon">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		</div>
		<h2 id="ccdco-unsaved-title"><?php esc_html_e( 'Unsaved Changes', 'codecaste-database-cleaner-optimizer' ); ?></h2>
		<p id="ccdco-unsaved-desc"><?php esc_html_e( 'You changed some settings but have not saved them. Do you want to save your changes before leaving this tab?', 'codecaste-database-cleaner-optimizer' ); ?></p>
		<div class="ccdco-modal-actions ccdco-modal-actions-3">
			<button type="button" class="ccdco-btn ccdco-btn-ghost" id="ccdco-unsaved-stay"><?php esc_html_e( 'Keep Editing', 'codecaste-database-cleaner-optimizer' ); ?></button>
			<button type="button" class="ccdco-btn ccdco-btn-danger" id="ccdco-unsaved-discard"><?php esc_html_e( 'Discard Changes', 'codecaste-database-cleaner-optimizer' ); ?></button>
			<button type="button" class="ccdco-btn ccdco-btn-primary" id="ccdco-unsaved-save"><?php esc_html_e( 'Save & Continue', 'codecaste-database-cleaner-optimizer' ); ?></button>
		</div>
	</div>
</div>

<!-- ====================================================
     RESULT MODAL
===================================================== -->
<div id="ccdco-result-modal" class="ccdco-modal" hidden aria-hidden="true">
	<div class="ccdco-modal-backdrop" tabindex="-1"></div>
	<div class="ccdco-modal-box" role="dialog" aria-modal="true" aria-labelledby="ccdco-result-title">
		<button type="button" class="ccdco-modal-close" data-close-result aria-label="<?php esc_attr_e( 'Close', 'codecaste-database-cleaner-optimizer' ); ?>">&times;</button>
		<div class="ccdco-modal-icon ccdco-modal-icon-success">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
		</div>
		<h2 id="ccdco-result-title"><?php esc_html_e( 'Cleanup Complete', 'codecaste-database-cleaner-optimizer' ); ?></h2>
		<div class="ccdco-confirm-stats">
			<div>
				<span><?php esc_html_e( 'Cleaned', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong id="ccdco-result-processed">0</strong>
			</div>
			<div>
				<span><?php esc_html_e( 'Remaining', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong id="ccdco-result-remaining">0</strong>
			</div>
			<div>
				<span><?php esc_html_e( 'Space freed', 'codecaste-database-cleaner-optimizer' ); ?></span>
				<strong id="ccdco-result-payload">0 B</strong>
			</div>
		</div>
		<div class="ccdco-modal-actions">
			<button type="button" class="ccdco-btn ccdco-btn-primary" data-close-result><?php esc_html_e( 'Done', 'codecaste-database-cleaner-optimizer' ); ?></button>
		</div>
	</div>
</div>
