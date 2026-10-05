<?php
/**
 * Possible orphaned database tables view.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccdco_tables = array_values( array_filter( (array) $ccdco_tables, function ( $row ) {
	return is_array( $row ) && 'possible_orphan' === ( $row['status'] ?? '' );
} ) );
?>
<div class="ccdco-tables-container" data-has-server-tables="1">
	<div class="ccdco-table-wrap">
		<table class="ccdco-table widefat striped ccdco-paginated-table" id="ccdco-database-tables-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Table Name', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Possible Source', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Detection', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Confidence', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Rows', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Size', 'codecaste-database-cleaner-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Action', 'codecaste-database-cleaner-optimizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $ccdco_tables ) ) : ?>
				<tr><td colspan="7" style="text-align:center;padding:32px"><?php esc_html_e( 'No possible orphaned tables were found. Run Scan Tables to refresh detection.', 'codecaste-database-cleaner-optimizer' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $ccdco_tables as $ccdco_table ) : ?>
					<?php
					$ccdco_name       = isset( $ccdco_table['table_name'] ) ? (string) $ccdco_table['table_name'] : (string) ( $ccdco_table['name'] ?? '' );
					$ccdco_source     = isset( $ccdco_table['possible_source'] ) ? (string) $ccdco_table['possible_source'] : __( 'Source not recorded', 'codecaste-database-cleaner-optimizer' );
					$ccdco_detection  = isset( $ccdco_table['source_detection'] ) ? (string) $ccdco_table['source_detection'] : (string) ( $ccdco_table['reason'] ?? '' );
					$ccdco_confidence = isset( $ccdco_table['source_confidence'] ) ? (string) $ccdco_table['source_confidence'] : 'none';
					$ccdco_rows      = isset( $ccdco_table['row_count'] ) ? (int) $ccdco_table['row_count'] : (int) ( $ccdco_table['rows'] ?? 0 );
					$ccdco_size      = isset( $ccdco_table['total_size'] ) ? (int) $ccdco_table['total_size'] : 0;
					?>
					<tr data-status="possible_orphan">
						<td><code><?php echo esc_html( $ccdco_name ); ?></code></td>
						<td><strong><?php echo esc_html( $ccdco_source ); ?></strong></td>
						<td style="font-size:12px;color:var(--ccdco-ink-muted)"><?php echo esc_html( $ccdco_detection ); ?></td>
						<td><?php echo esc_html( ucfirst( $ccdco_confidence ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $ccdco_rows ) ); ?></td>
						<td><?php echo esc_html( CCDCO_DB_Helper::format_bytes( $ccdco_size ) ); ?></td>
						<td style="text-align:right">
							<button type="button" class="ccdco-btn ccdco-btn-danger ccdco-delete-table" style="padding:5px 10px;font-size:12px" data-table="<?php echo esc_attr( $ccdco_name ); ?>">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								<?php esc_html_e( 'Delete', 'codecaste-database-cleaner-optimizer' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<div class="ccdco-pagination" aria-label="<?php esc_attr_e( 'Possible orphaned tables pagination', 'codecaste-database-cleaner-optimizer' ); ?>"></div>
</div>
