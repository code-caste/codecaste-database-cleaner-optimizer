<?php
/**
 * Database health score calculator.
 *
 * Produces an indicative 0-100 score from the combined scan results.
 * This is a heuristic, not an absolute technical measurement -- large
 * legitimate tables (e.g. WooCommerce order/analytics tables) are not
 * automatically treated as unhealthy.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Health_Score
 */
class CCDCO_Health_Score {

	/**
	 * Per-scanner weight and severity penalty table.
	 *
	 * @var array
	 */
	private static $weights = array(
		'autoloaded_options'   => array(
			'max_penalty' => 20,
			'thresholds'  => array(
				'notice'  => 8,
				'warning' => 20,
			),
		),
		'expired_transients'   => array(
			'max_penalty' => 10,
			'thresholds'  => array(
				'notice'  => 4,
				'warning' => 10,
			),
		),
		'post_revisions'       => array(
			'max_penalty' => 15,
			'thresholds'  => array(
				'notice'  => 6,
				'warning' => 15,
			),
		),
		'auto_drafts'          => array(
			'max_penalty' => 8,
			'thresholds'  => array(
				'notice'  => 3,
				'warning' => 8,
			),
		),
		'orphaned_postmeta'    => array(
			'max_penalty' => 15,
			'thresholds'  => array(
				'notice'  => 6,
				'warning' => 15,
			),
		),
		'orphaned_termmeta'    => array(
			'max_penalty' => 8,
			'thresholds'  => array(
				'notice'  => 3,
				'warning' => 8,
			),
		),
		'orphaned_commentmeta' => array(
			'max_penalty' => 8,
			'thresholds'  => array(
				'notice'  => 3,
				'warning' => 8,
			),
		),
		'action_scheduler'     => array(
			'max_penalty' => 16,
			'thresholds'  => array(
				'notice'  => 6,
				'warning' => 16,
			),
		),
	);

	/**
	 * Calculate the health score from a set of scan results.
	 *
	 * @param array[] $results Scan results keyed by scanner id, as returned by CCDCO_Scanner_Engine::run_all().
	 * @return array {
	 *     @type int   $score           0-100 score.
	 *     @type string $label          Human readable label.
	 *     @type array $issue_summary   Count of issues by severity.
	 * }
	 */
	public static function calculate( $results ) {
		$score          = 100;
		$issue_summary  = array(
			'warning' => 0,
			'notice'  => 0,
			'info'    => 0,
		);

		foreach ( $results as $id => $result ) {
			if ( isset( $result['severity'] ) && isset( $issue_summary[ $result['severity'] ] ) ) {
				++$issue_summary[ $result['severity'] ];
			}

			if ( ! isset( self::$weights[ $id ] ) ) {
				continue;
			}

			$weight   = self::$weights[ $id ];
			$severity = isset( $result['severity'] ) ? $result['severity'] : 'info';

			if ( 'warning' === $severity ) {
				$score -= $weight['max_penalty'];
			} elseif ( 'notice' === $severity ) {
				$score -= (int) round( $weight['max_penalty'] * 0.5 );
			}
		}

		/**
		 * Filter the raw calculated health score (before clamping) so
		 * integrations (e.g. "don't penalize known WooCommerce tables")
		 * can adjust it.
		 *
		 * @param int     $score   Calculated score.
		 * @param array[] $results Raw scan results.
		 */
		$score = (int) apply_filters( 'ccdco_health_score', $score, $results );
		$score = max( 0, min( 100, $score ) );

		return array(
			'score'         => $score,
			'label'         => self::get_label( $score ),
			'issue_summary' => $issue_summary,
		);
	}

	/**
	 * Get a human readable label for a score.
	 *
	 * @param int $score Score 0-100.
	 * @return string
	 */
	public static function get_label( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'codecaste-database-cleaner-optimizer' );
		}
		if ( $score >= 75 ) {
			return __( 'Good', 'codecaste-database-cleaner-optimizer' );
		}
		if ( $score >= 50 ) {
			return __( 'Needs Attention', 'codecaste-database-cleaner-optimizer' );
		}
		return __( 'Poor', 'codecaste-database-cleaner-optimizer' );
	}
}
