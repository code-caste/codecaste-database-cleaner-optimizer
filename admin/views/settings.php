<?php
/**
 * Settings view.
 *
 * Available variables: $ccdco_settings, $ccdco_all_scanners (CCDCO_Scanner_Interface[]).
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccdco_active_tab      = 'settings';
$ccdco_enabled_scanners = isset( $ccdco_settings['enabled_scanners'] ) ? (array) $ccdco_settings['enabled_scanners'] : array();
?>
<div class="wrap ccdco-wrap">
	<?php include __DIR__ . '/nav.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=codecaste-database-cleaner-optimizer-settings' ) ); ?>">
		<?php wp_nonce_field( 'ccdco_save_settings', 'ccdco_settings_nonce' ); ?>

		<div class="ccdco-card">
			<h2><?php esc_html_e( 'General', 'codecaste-database-cleaner-optimizer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Scan History', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ccdco_settings[enable_scan_history]" value="1" <?php checked( ! empty( $ccdco_settings['enable_scan_history'] ) ); ?> />
							<?php esc_html_e( 'Store a record of every scan (date, size, score, issues, duration).', 'codecaste-database-cleaner-optimizer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ccdco-batch-size"><?php esc_html_e( 'Cleanup Batch Size', 'codecaste-database-cleaner-optimizer' ); ?></label>
					</th>
					<td>
						<input type="number" id="ccdco-batch-size" name="ccdco_settings[batch_size]" min="50" max="5000" step="50" value="<?php echo esc_attr( $ccdco_settings['batch_size'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of records processed per cleanup AJAX batch. Lower this on shared or slow hosting.', 'codecaste-database-cleaner-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ccdco-keep-latest"><?php esc_html_e( 'Revisions to Keep', 'codecaste-database-cleaner-optimizer' ); ?></label>
					</th>
					<td>
						<input type="number" id="ccdco-keep-latest" name="ccdco_settings[revisions_keep_latest]" min="0" max="50" value="<?php echo esc_attr( $ccdco_settings['revisions_keep_latest'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of most recent revisions to always keep per post.', 'codecaste-database-cleaner-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ccdco-as-retention"><?php esc_html_e( 'Action Scheduler Retention (days)', 'codecaste-database-cleaner-optimizer' ); ?></label>
					</th>
					<td>
						<input type="number" id="ccdco-as-retention" name="ccdco_settings[action_scheduler_retention_days]" min="1" max="365" value="<?php echo esc_attr( $ccdco_settings['action_scheduler_retention_days'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Only completed, failed or canceled actions older than this are eligible for cleanup.', 'codecaste-database-cleaner-optimizer' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="ccdco-card">
			<h2><?php esc_html_e( 'Scanners', 'codecaste-database-cleaner-optimizer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Disable any scanner you do not want to run during a scan.', 'codecaste-database-cleaner-optimizer' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $ccdco_all_scanners as $ccdco_scanner ) : ?>
					<?php if ( 'table_size' === $ccdco_scanner->get_id() ) : ?>
						<?php continue; // Always on; the Database Size stat depends on it, so it is not user-toggleable. ?>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html( $ccdco_scanner->get_label() ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="ccdco_settings[enabled_scanners][]"
									value="<?php echo esc_attr( $ccdco_scanner->get_id() ); ?>"
									<?php checked( in_array( $ccdco_scanner->get_id(), $ccdco_enabled_scanners, true ) ); ?>
									<?php disabled( ! $ccdco_scanner->is_available() ); ?>
								/>
								<?php echo esc_html( $ccdco_scanner->get_description() ); ?>
							</label>
							<?php if ( ! $ccdco_scanner->is_available() ) : ?>
								<p class="description"><em><?php esc_html_e( 'Not available on this site (required table not found).', 'codecaste-database-cleaner-optimizer' ); ?></em></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'codecaste-database-cleaner-optimizer' ) ); ?>
	</form>
</div>
