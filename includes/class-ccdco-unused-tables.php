<?php
/**
 * Automatic orphan-table detection and source tracking.
 *
 * The scanner learns table/source relationships from installed plugin and theme
 * source code and keeps that information in a persistent ownership index. This
 * allows a later scan to identify tables left behind after a plugin is removed.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Unused_Tables
 */
class CCDCO_Unused_Tables {

	/**
	 * Transient containing the current source scan.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'ccdco_table_reference_cache_v11';

	/**
	 * Persistent table/source ownership index.
	 *
	 * @var string
	 */
	const OWNERSHIP_OPTION = 'ccdco_table_ownership_index_v2';

	/**
	 * Internal rules version for stored records.
	 *
	 * @var string
	 */
	const RULES_VERSION = '4';

	/**
	 * Maximum PHP files scanned for one component.
	 *
	 * @var int
	 */
	const MAX_FILES_PER_COMPONENT = 2000;

	/**
	 * Maximum file size inspected by the source scanner.
	 *
	 * @var int
	 */
	const MAX_FILE_BYTES = 524288;

	/**
	 * Run a fresh automatic orphan scan.
	 *
	 * Only possible orphaned WordPress-prefixed tables are returned.
	 *
	 * @param bool $force_refresh Force a fresh source scan.
	 * @return array[]
	 */
	public static function scan( $force_refresh = false ) {
		$tables = CCDCO_DB_Helper::get_all_table_sizes();
		$core   = self::get_core_tables();
		$prefix = self::get_prefix();

		$candidates = array();
		foreach ( $tables as $table ) {
			$name = isset( $table['name'] ) ? (string) $table['name'] : '';
			if ( '' === $name || isset( $core[ $name ] ) || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}

			$suffix = substr( $name, strlen( $prefix ) );
			if ( '' !== $suffix ) {
				$candidates[ $name ] = $suffix;
			}
		}

		$discovery = self::discover_sources( $candidates, $force_refresh );
		$ownership  = self::update_ownership_index( $discovery['references'] );
		$out        = array();

		foreach ( $candidates as $name => $suffix ) {
			// A current source reference means the table is still in use.
			if ( isset( $discovery['references'][ $name ] ) && ! empty( $discovery['references'][ $name ] ) ) {
				continue;
			}

			// Any table that clearly belongs to a component which is still installed
			// must never be classified as an orphan. Orphan-table cleanup is intended
			// for tables left behind by removed components, not merely deactivated ones.
			if ( self::has_strong_installed_component_name_match( $suffix ) ) {
				continue;
			}

			$table = self::find_table_row( $tables, $name );
			$source = self::choose_source( $name, $suffix, $ownership, $discovery['prefixes'] );

			$out[] = array(
				'name'                 => $name,
				'table_name'           => $name,
				'rows'                 => $table ? (int) $table['table_rows'] : 0,
				'row_count'            => $table ? (int) $table['table_rows'] : 0,
				'data_size'            => $table ? (int) $table['data_size'] : 0,
				'index_size'           => $table ? (int) $table['index_size'] : 0,
				'total_size'           => $table ? (int) $table['total_size'] : 0,
				'data_bytes'           => $table ? (int) $table['total_size'] : 0,
				'status'               => 'possible_orphan',
				'label'                => __( 'Possible orphan', 'codecaste-database-cleaner-optimizer' ),
				'possible_source'      => $source['label'],
				'source_name'          => $source['name'],
				'source_type'          => $source['type'],
				'source_confidence'    => $source['confidence'],
				'source_detection'     => $source['detection'],
				'source_note'          => $source['note'],
				'reason'               => __( 'No currently active plugin/theme component references this table. The source information below is based on previously learned ownership or table-name analysis.', 'codecaste-database-cleaner-optimizer' ),
				'can_delete'           => true,
				'orphan_rules_version' => self::RULES_VERSION,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
			return $b['total_size'] <=> $a['total_size'];
			}
		);

		return $out;
	}

	/**
	 * Delete a currently verified orphan table.
	 *
	 * @param string $table_name Full table name.
	 * @return array|WP_Error
	 */
	public static function delete_table( $table_name ) {
		global $wpdb;

		$table_name = sanitize_text_field( $table_name );
		$candidate  = null;

		foreach ( self::scan( false ) as $row ) {
			if ( $row['name'] === $table_name ) {
				$candidate = $row;
				break;
			}
		}

		if ( ! $candidate ) {
			return new WP_Error( 'ccdco_table_not_safe', __( 'This table is not currently verified as an orphaned table. Nothing was deleted.', 'codecaste-database-cleaner-optimizer' ) );
		}

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			return new WP_Error( 'ccdco_invalid_table', __( 'Invalid table name.', 'codecaste-database-cleaner-optimizer' ) );
		}

		$prefix = self::get_prefix();
		if ( 0 !== strpos( $table_name, $prefix ) ) {
			return new WP_Error( 'ccdco_external_table', __( 'Only WordPress-prefixed tables can be deleted by this tool.', 'codecaste-database-cleaner-optimizer' ) );
		}

		$core = self::get_core_tables();
		if ( isset( $core[ $table_name ] ) ) {
			return new WP_Error( 'ccdco_core_table', __( 'WordPress core tables cannot be deleted.', 'codecaste-database-cleaner-optimizer' ) );
		}

		// The source discovery above is repeated immediately before DROP TABLE to close the
		// race where a component becomes installed/referenced after the initial scan. This
		// re-check only covers the single table being deleted, so it must NOT be written to
		// the shared reference cache (persist_cache = false) — doing so would overwrite the
		// full-candidate-set cache with a result scoped to one table, making every other
		// table look reference-free on the next listing and skewing the orphan count/list
		// shown right after a delete.
		$live = self::discover_sources( array( $table_name => substr( $table_name, strlen( $prefix ) ) ), true, false );
		if ( ! empty( $live['references'][ $table_name ] ) ) {
			return new WP_Error( 'ccdco_table_now_referenced', __( 'An installed component now references this table. Nothing was deleted.', 'codecaste-database-cleaner-optimizer' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional deletion of a table verified as a WordPress-prefixed orphan.
		$result = $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table_name ) );

		if ( false === $result ) {
			return new WP_Error( 'ccdco_drop_failed', __( 'MySQL could not delete the table.', 'codecaste-database-cleaner-optimizer' ) );
		}

		$ownership = self::get_ownership_index();
		unset( $ownership[ $table_name ] );
		update_option( self::OWNERSHIP_OPTION, $ownership, false );

		return array(
			'deleted' => true,
			'table'   => $table_name,
			'size'    => (int) $candidate['total_size'],
		);
	}

	/**
	 * Discover current plugin/theme references and table declarations.
	 *
	 * @param array $candidates    Candidate table map.
	 * @param bool  $force_refresh Force a fresh component scan.
	 * @param bool  $persist_cache Whether to store this result in the shared 30-minute
	 *                             transient cache. Must be false for any call that scans
	 *                             a partial/scoped candidate set (e.g. the single-table
	 *                             re-check before DROP TABLE) — the cache is meant to hold
	 *                             results for the *full* current candidate set only, and
	 *                             writing a partial result there corrupts every subsequent
	 *                             non-forced scan until the cache expires or is force-refreshed.
	 * @return array{references:array,components:array,signatures:array,prefixes:array,component_fingerprint:string,candidate_fingerprint:string}
	 */
	private static function discover_sources( $candidates, $force_refresh = false, $persist_cache = true ) {
		// Nothing to search for — skip the expensive plugin/theme file walk entirely.
		if ( empty( $candidates ) ) {
			return array(
				'references' => array(),
				'components' => array(),
				'signatures' => array(),
				'prefixes'   => array(),
				'component_fingerprint' => '',
				'candidate_fingerprint' => '',
			);
		}

		$component_fingerprint = self::get_component_fingerprint();
		$candidate_fingerprint = self::get_candidate_fingerprint( $candidates );
		$cache = false;
		if ( ! $force_refresh ) {
			$cache = get_transient( self::CACHE_KEY );
		}
		if (
			is_array( $cache )
			&& isset( $cache['references'], $cache['components'], $cache['signatures'], $cache['prefixes'], $cache['component_fingerprint'], $cache['candidate_fingerprint'] )
			&& hash_equals( (string) $cache['component_fingerprint'], $component_fingerprint )
			&& hash_equals( (string) $cache['candidate_fingerprint'], $candidate_fingerprint )
		) {
			return $cache;
		}

		// Scan every currently installed plugin/theme component, not only active ones.
		// A deactivated plugin can still own its custom tables, and an installed plugin
		// must never be mistaken for a deleted plugin merely because it is inactive.
		$components = self::get_components( false );
		$references = array();
		$signatures = array();
		$prefixes   = array();

		foreach ( $components as $component ) {
			$files = self::component_files( $component );
			foreach ( $files as $file ) {
				if ( ! is_readable( $file ) ) {
					continue;
				}

				$contents = file_get_contents( $file );
				if ( false === $contents || '' === $contents ) {
					continue;
				}

				// Exact table-name matching catches hard-coded names and tables assembled
				// through variables/constants that do not expose a simple $wpdb->prefix pattern.
				self::record_exact_table_matches( $contents, $component, $file, $candidates, $references );

				$found = self::extract_signatures( $contents );
				self::extract_global_prefixes( $contents, $component, $file, $prefixes );
				self::record_global_prefix_matches( $component, $file, $candidates, $prefixes, $references );
				foreach ( $found as $signature ) {
					$actual_name = '';
					if ( ! empty( $signature['is_full_name'] ) ) {
						$actual_name = self::normalize_full_table_name( $signature['value'] );
					} else {
						$actual_name = self::get_prefix() . $signature['value'];
					}

					if ( '' !== $actual_name && isset( $candidates[ $actual_name ] ) ) {
						if ( ! isset( $references[ $actual_name ] ) ) {
							$references[ $actual_name ] = array();
						}
						$source_key = $component['type'] . ':' . $component['slug'];
						$references[ $actual_name ][ $source_key ] = array(
							'name'       => $component['name'],
							'type'       => $component['type'],
							'slug'       => $component['slug'],
							'file'       => $file,
							'detection'  => $signature['detection'],
							'score'      => $signature['score'],
						);
					}
					if ( ! empty( $signature['value'] ) ) {
						$normalized_signature = self::normalize_source_token( $signature['value'] );
						if ( '' !== $normalized_signature && ! isset( $signatures[ $normalized_signature ] ) ) {
							$signatures[ $normalized_signature ] = array(
								'name' => $component['name'],
								'type' => $component['type'],
								'slug' => $component['slug'],
							);
						}
					}
				}
			}
		}

		$result = array(
			'references' => $references,
			'components' => $components,
			'signatures' => $signatures,
			'prefixes'   => $prefixes,
			'component_fingerprint' => $component_fingerprint,
			'candidate_fingerprint' => $candidate_fingerprint,
		);

		if ( $persist_cache ) {
			set_transient( self::CACHE_KEY, $result, 30 * MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Record exact table-name matches from source code.
	 *
	 * This catches literal CREATE/INSERT/SELECT references and variable-based
	 * code where the final table name is present in the source text.
	 *
	 * @param string $contents Source contents.
	 * @param array  $component Component metadata.
	 * @param string $file Source file.
	 * @param array  $candidates Candidate table map.
	 * @param array  &$references Reference map.
	 * @return void
	 */
	private static function record_exact_table_matches( $contents, $component, $file, $candidates, &$references ) {
		if ( empty( $candidates ) ) {
			return;
		}

		$tokens = array();
		foreach ( $candidates as $table_name => $suffix ) {
			$tokens[ $table_name ] = $table_name;
			if ( '' !== $suffix ) {
				$tokens[ $suffix ] = $table_name;
			}
		}

		$token_keys = array_keys( $tokens );
		if ( count( $token_keys ) > 1000 ) {
			$token_keys = array_slice( $token_keys, 0, 1000 );
		}

		$alternatives = array();
		foreach ( $token_keys as $token ) {
			$alternatives[] = preg_quote( $token, '/' );
		}
		$pattern = '/(?<![A-Za-z0-9_])(?:' . implode( '|', $alternatives ) . ')(?![A-Za-z0-9_])/i';

		if ( ! preg_match_all( $pattern, $contents, $matches ) ) {
			return;
		}

		$source_key = $component['type'] . ':' . $component['slug'];
		foreach ( array_unique( $matches[0] ) as $matched_token ) {
			$table_name = '';
			foreach ( $tokens as $token => $candidate_name ) {
				if ( 0 === strcasecmp( $token, $matched_token ) ) {
					$table_name = $candidate_name;
					break;
				}
			}

			if ( '' === $table_name || ! isset( $candidates[ $table_name ] ) ) {
				continue;
			}
			if ( ! isset( $references[ $table_name ] ) ) {
				$references[ $table_name ] = array();
			}
			$references[ $table_name ][ $source_key ] = array(
				'name'      => $component['name'],
				'type'      => $component['type'],
				'slug'      => $component['slug'],
				'file'      => $file,
				'detection' => __( 'Database table name or table identifier found in component source.', 'codecaste-database-cleaner-optimizer' ),
				'score'     => $matched_token === $table_name ? 95 : 92,
			);
		}
	}

	/**
	 * Extract table signatures from plugin/theme source.
	 *
	 * @param string $contents PHP source.
	 * @return array[]
	 */
	private static function extract_signatures( $contents ) {
		$found = array();

		$patterns = array(
			array(
				'regex'      => '/\$wpdb->(?:prefix|base_prefix)\s*\.\s*[\'"]([A-Za-z0-9_]+)[\'"]/i',
				'detection' => 'WordPress table prefix concatenation',
				'score'     => 90,
			),
			array(
				'regex'      => '/\{\$wpdb->(?:prefix|base_prefix)\}([A-Za-z0-9_]+)/i',
				'detection' => 'WordPress table prefix interpolation',
				'score'     => 90,
			),
			array(
				'regex'      => '/\$wpdb->([A-Za-z0-9_]+)\b/i',
				'detection' => 'wpdb table property reference',
				'score'     => 80,
			),
			array(
				'regex'      => '/\bCREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+[`\'"]?([A-Za-z0-9_]+)[`\'"]?/i',
				'detection' => 'CREATE TABLE definition',
				'score'     => 100,
			),
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern['regex'], $contents, $matches ) ) {
				foreach ( $matches[1] as $value ) {
					if ( '' === $value ) {
						continue;
					}
					$found[] = array(
						'value'        => $value,
						'is_full_name' => 'CREATE TABLE definition' === $pattern['detection'],
						'detection'     => $pattern['detection'],
						'score'         => $pattern['score'],
					);
				}
			}
		}

		return $found;
	}

	/**
	 * Extract globally defined table/database prefixes from component source.
	 *
	 * Supported patterns include define(), class constants, and common global
	 * or member variables containing table_prefix/db_prefix/database_prefix.
	 *
	 * @param string $contents Component source.
	 * @param array  $component Component metadata.
	 * @param string $file Component file.
	 * @param array  &$prefixes Prefix index.
	 * @return void
	 */
	private static function extract_global_prefixes( $contents, $component, $file, &$prefixes ) {
		$patterns = array(
			// define( 'PLUGIN_TABLE_PREFIX', 'foo_' );
			'/define\s*\(\s*[\'\"]([A-Za-z0-9_]+(?:_DB_PREFIX|_DATABASE_PREFIX|_TABLE_PREFIX|_PLUGIN_PREFIX|_PREFIX))\s*[\'\"]\s*,\s*[\'\"]([A-Za-z0-9_-]{3,64})[\'\"]\s*\)/i',
			// define( 'PLUGIN_TABLE_PREFIX', $wpdb->prefix . 'foo_' );
			'/define\s*\(\s*[\'\"]([A-Za-z0-9_]+(?:_DB_PREFIX|_DATABASE_PREFIX|_TABLE_PREFIX|_PLUGIN_PREFIX|_PREFIX))\s*[\'\"]\s*,\s*\$wpdb->(?:prefix|base_prefix)\s*\.\s*[\'\"]([A-Za-z0-9_-]{3,64})[\'\"]\s*\)/i',
			// const PLUGIN_TABLE_PREFIX = 'foo_';
			'/\bconst\s+([A-Za-z0-9_]+(?:_DB_PREFIX|_DATABASE_PREFIX|_TABLE_PREFIX|_PLUGIN_PREFIX|_PREFIX))\s*=\s*[\'\"]([A-Za-z0-9_-]{3,64})[\'\"]\s*;/i',
			// $table_prefix = 'foo_'; / $dbPrefix = 'foo_';
			'/\$([A-Za-z0-9_]*(?:db|database|table|plugin)[A-Za-z0-9_]*(?:prefix|Prefix))\s*=\s*[\'\"]([A-Za-z0-9_-]{3,64})[\'\"]\s*;/i',
			// $table_prefix = $wpdb->prefix . 'foo_';
			'/\$([A-Za-z0-9_]*(?:db|database|table|plugin)[A-Za-z0-9_]*(?:prefix|Prefix))\s*=\s*\$wpdb->(?:prefix|base_prefix)\s*\.\s*[\'\"]([A-Za-z0-9_-]{3,64})[\'\"]\s*;/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$prefix = self::normalize_source_prefix( $match[2] ?? '' );
				if ( strlen( $prefix ) < 3 ) {
					continue;
				}

				$key = $prefix . '|' . $component['type'] . ':' . $component['slug'];
				$prefixes[ $key ] = array(
					'prefix'     => $prefix,
					'name'       => $component['name'],
					'type'       => $component['type'],
					'slug'       => $component['slug'],
					'file'       => $file,
					'detection'  => __( 'Global plugin/component database or table prefix found in source.', 'codecaste-database-cleaner-optimizer' ),
					'confidence' => 'high',
				);
			}
		}
	}

	/**
	 * Treat tables matching a global component prefix as currently referenced.
	 *
	 * @param array $component Component metadata.
	 * @param string $file Source file where the prefix was found.
	 * @param array $candidates Candidate table map.
	 * @param array $prefixes Prefix index.
	 * @param array &$references Reference map.
	 * @return void
	 */
	private static function record_global_prefix_matches( $component, $file, $candidates, $prefixes, &$references ) {
		foreach ( $prefixes as $prefix_data ) {
			if ( ! is_array( $prefix_data ) || empty( $prefix_data['prefix'] ) ) {
				continue;
			}

			if ( $prefix_data['type'] !== $component['type'] || $prefix_data['slug'] !== $component['slug'] || $prefix_data['file'] !== $file ) {
				continue;
			}

			$prefix = self::normalize_source_prefix( $prefix_data['prefix'] );
			if ( strlen( $prefix ) < 3 ) {
				continue;
			}

			foreach ( $candidates as $table_name => $suffix ) {
				$normalized_suffix = self::normalize_source_prefix( $suffix );
				if ( $normalized_suffix === $prefix || 0 === strpos( $normalized_suffix, $prefix . '_' ) || 0 === strpos( $normalized_suffix, $prefix ) ) {
					$source_key = $component['type'] . ':' . $component['slug'];
					if ( ! isset( $references[ $table_name ] ) ) {
						$references[ $table_name ] = array();
					}
					$references[ $table_name ][ $source_key ] = array(
						'name'      => $component['name'],
						'type'      => $component['type'],
						'slug'      => $component['slug'],
						'file'      => $file,
						'detection' => sprintf(
							/* translators: %s: detected database/table prefix */
							__( 'Global database/table prefix match: %s', 'codecaste-database-cleaner-optimizer' ),
							$prefix
						),
						'score'     => 93,
					);
				}
			}
		}
	}

	/**
	 * Get scannable plugin/theme components.
	 *
	 * When $active_only is true, only currently active plugins, the active
	 * theme (plus its parent), and MU plugins are returned. The all-components
	 * inventory is used only for source attribution of inactive/deleted tables.
	 *
	 * @param bool $active_only Return only components currently in use.
	 * @return array[]
	 */
	private static function get_components( $active_only = true ) {
		static $cached = array();
		$cache_key = $active_only ? 'active' : 'all';
		if ( isset( $cached[ $cache_key ] ) ) {
			return $cached[ $cache_key ];
		}

		$components = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'get_plugins' ) ) {
			$active_plugins = array();
			if ( $active_only ) {
				$active_plugins = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
				if ( is_multisite() ) {
					$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
					$active_plugins = array_values( array_unique( array_merge( $active_plugins, array_keys( $network_active ) ) ) );
				}
			}

			foreach ( (array) get_plugins() as $plugin_file => $plugin_data ) {
				if ( $active_only && ! in_array( $plugin_file, $active_plugins, true ) ) {
					continue;
				}

				$parts = explode( '/', $plugin_file );
				$slug  = count( $parts ) > 1 ? sanitize_key( $parts[0] ) : sanitize_key( basename( $plugin_file, '.php' ) );
				$name  = ! empty( $plugin_data['Name'] ) ? wp_strip_all_tags( $plugin_data['Name'] ) : $slug;
				$entry = WP_PLUGIN_DIR . '/' . $plugin_file;
				$root  = dirname( $entry );
				$components[] = array(
					'type'        => 'plugin',
					'slug'        => $slug,
					'name'        => $name,
					'plugin_file' => $plugin_file,
					'root'        => is_dir( $root ) ? $root : WP_PLUGIN_DIR,
					'entry'       => $entry,
				);
			}
		}

		$active_stylesheet = get_option( 'stylesheet' );
		foreach ( (array) wp_get_themes() as $stylesheet => $theme ) {
			if ( $active_only && $stylesheet !== $active_stylesheet ) {
				$active_theme = wp_get_theme( $active_stylesheet );
				$parent = $active_theme ? $active_theme->parent() : false;
				if ( ! $parent || $parent->get_stylesheet() !== $stylesheet ) {
					continue;
				}
			}

			$name = wp_strip_all_tags( $theme->get( 'Name' ) );
			if ( '' === $name ) {
				continue;
			}
			$components[] = array(
				'type'  => 'theme',
				'slug'  => sanitize_key( $stylesheet ),
				'name'  => $name,
				'root'  => $theme->get_stylesheet_directory(),
				'entry' => $theme->get_stylesheet_directory() . '/functions.php',
			);
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$files = glob( WPMU_PLUGIN_DIR . '/*.php' );
			foreach ( (array) $files as $file ) {
				$slug = sanitize_key( basename( $file, '.php' ) );
				$components[] = array(
					'type'  => 'mu-plugin',
					'slug'  => $slug,
					'name'  => $slug,
					'root'  => $file,
					'entry' => $file,
				);
			}
		}

		$cached[ $cache_key ] = $components;
		return $components;
	}

	/**
	 * Build a fingerprint for the current candidate table set.
	 *
	 * A plugin can create its custom tables after the source-reference cache
	 * was generated, for example when a knowledge-base importer creates its
	 * first chunk table. The cache must be invalidated when the database table
	 * set changes, otherwise a newly created table has no cached reference and
	 * can be incorrectly reported as an orphan.
	 *
	 * @param array $candidates Candidate table map.
	 * @return string
	 */
	private static function get_candidate_fingerprint( $candidates ) {
		$names = array_keys( (array) $candidates );
		sort( $names, SORT_STRING );
		return md5( wp_json_encode( $names ) );
	}

	/**
	 * Build a fingerprint for the currently installed component inventory.
	 *
	 * The source-reference cache must be invalidated when a plugin/theme is
	 * installed or removed. Otherwise a newly installed plugin could be absent
	 * from the cached reference set and its custom tables could be misclassified
	 * until the transient expired.
	 *
	 * @return string
	 */
	private static function get_component_fingerprint() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$inventory = array(
			'plugins' => array(),
			'themes'  => array(),
			'mu'      => array(),
		);

		foreach ( (array) get_plugins() as $plugin_file => $plugin_data ) {
			$inventory['plugins'][ $plugin_file ] = array(
				'name'    => isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '',
				'version' => isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '',
			);
		}

		foreach ( (array) wp_get_themes() as $stylesheet => $theme ) {
			$inventory['themes'][ $stylesheet ] = array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			);
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			foreach ( (array) glob( WPMU_PLUGIN_DIR . '/*.php' ) as $file ) {
				$inventory['mu'][] = basename( $file );
			}
			sort( $inventory['mu'] );
		}

		return md5( wp_json_encode( $inventory ) );
	}

	/**
	 * Get PHP files for one component.
	 *
	 * @param array $component Component metadata.
	 * @return string[]
	 */
	private static function component_files( $component ) {
		$files = array();
		$root  = isset( $component['root'] ) ? $component['root'] : '';
		$entry = isset( $component['entry'] ) ? $component['entry'] : '';

		if ( is_file( $entry ) ) {
			$files[] = $entry;
		}
		if ( ! is_dir( $root ) || ( defined( 'WP_PLUGIN_DIR' ) && WP_PLUGIN_DIR === $root ) ) {
			return array_values( array_unique( $files ) );
		}

		$ignore_dirs = array( '.', '..', '.git', 'node_modules', 'tests', 'test', 'cache', 'uploads', 'dist', 'build' );
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					function ( $current, $key, $iterator ) use ( $ignore_dirs ) {
						if ( $iterator->hasChildren() ) {
							return ! in_array( strtolower( $current->getFilename() ), $ignore_dirs, true );
						}
						return $current->isFile() && 'php' === strtolower( $current->getExtension() ) && ( false === $current->getSize() || $current->getSize() <= self::MAX_FILE_BYTES );
					}
				)
			);

			foreach ( $iterator as $file ) {
				if ( count( $files ) >= self::MAX_FILES_PER_COMPONENT ) {
					break;
				}
				$files[] = $file->getPathname();
			}
		} catch ( Exception $e ) {
			// A component that cannot be fully read is handled conservatively.
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Update persistent ownership history from current live references.
	 *
	 * @param array $references Current references.
	 * @return array
	 */
	private static function update_ownership_index( $references ) {
		$ownership = self::get_ownership_index();
		$now       = current_time( 'mysql', true );

		foreach ( $references as $table_name => $sources ) {
			if ( ! isset( $ownership[ $table_name ] ) || ! is_array( $ownership[ $table_name ] ) ) {
				$ownership[ $table_name ] = array();
			}

			foreach ( $sources as $source_key => $source ) {
				$existing = isset( $ownership[ $table_name ][ $source_key ] ) && is_array( $ownership[ $table_name ][ $source_key ] ) ? $ownership[ $table_name ][ $source_key ] : array();
				$ownership[ $table_name ][ $source_key ] = array(
					'name'       => $source['name'],
					'type'       => $source['type'],
					'slug'       => $source['slug'],
					'detection'  => $source['detection'],
					'pattern'    => $source['detection'],
					'first_seen' => ! empty( $existing['first_seen'] ) ? $existing['first_seen'] : $now,
					'last_seen'  => $now,
					'rule_ver'   => self::RULES_VERSION,
				);
			}
		}

		update_option( self::OWNERSHIP_OPTION, $ownership, false );
		return $ownership;
	}

	/**
	 * Get stored ownership index.
	 *
	 * @return array
	 */
	private static function get_ownership_index() {
		$ownership = get_option( self::OWNERSHIP_OPTION, array() );
		return is_array( $ownership ) ? $ownership : array();
	}

	/**
	 * Choose the best historical/current source description for an orphan.
	 *
	 * @param string $table_name Full table name.
	 * @param string $suffix Unprefixed table name.
	 * @param array  $ownership Persistent ownership index.
	 * @param array  $prefixes Automatically discovered global table/database prefixes from active components.
	 * @return array{label:string,name:string,type:string,confidence:string,detection:string,note:string}
	 */
	private static function choose_source( $table_name, $suffix, $ownership, $prefixes = array() ) {
		$history = isset( $ownership[ $table_name ] ) && is_array( $ownership[ $table_name ] ) ? $ownership[ $table_name ] : array();
		if ( ! empty( $history ) ) {
			$best = null;
			foreach ( $history as $source ) {
				if ( ! is_array( $source ) ) {
					continue;
				}
				if ( null === $best || ( isset( $source['last_seen'] ) && $source['last_seen'] > ( $best['last_seen'] ?? '' ) ) ) {
					$best = $source;
				}
			}
			if ( $best ) {
				/* translators: %s: component name */
				$label = sprintf( __( 'Previously detected: %s', 'codecaste-database-cleaner-optimizer' ), $best['name'] );
				return array(
					'label'      => $label,
					'name'       => $best['name'],
					'type'       => $best['type'],
					'confidence' => 'high',
					'detection'  => $best['detection'],
					'note'       => __( 'The scanner previously detected this table in that component’s source while the component was installed. This is historical evidence, not a guarantee of current ownership.', 'codecaste-database-cleaner-optimizer' ),
				);
			}
		}

		$prefix_match = self::match_global_prefix( $suffix, $prefixes );
		if ( $prefix_match ) {
			/* translators: %s: component name */
			$label = sprintf( __( 'Possible source: %s', 'codecaste-database-cleaner-optimizer' ), $prefix_match['name'] );
			return array(
				'label'      => $label,
				'name'       => $prefix_match['name'],
				'type'       => $prefix_match['type'],
				'confidence' => $prefix_match['confidence'],
				'detection'  => sprintf(
					/* translators: %1$s: table prefix, %2$s: component name */
					__( 'Global table/database prefix "%1$s" matched %2$s.', 'codecaste-database-cleaner-optimizer' ),
					$prefix_match['prefix'],
					$prefix_match['name']
				),
				'note'       => __( 'The source was inferred from a prefix defined in the component source. This is stronger than a name-only match, but it is still a source inference rather than a guaranteed database ownership record.', 'codecaste-database-cleaner-optimizer' ),
			);
		}

		$component_match = self::match_installed_component_name( $suffix, false );
		if ( $component_match ) {
			$is_active = self::component_is_active( $component_match );

			if ( $is_active ) {
				$label = sprintf(
					/* translators: %s: component name. */
					__( 'Possible source: %s', 'codecaste-database-cleaner-optimizer' ),
					$component_match['name']
				);
			} else {
				$label = sprintf(
					/* translators: %s: component name. */
					__( 'Possible source: %s (installed but inactive)', 'codecaste-database-cleaner-optimizer' ),
					$component_match['name']
				);
			}
			return array(
				'label'      => $label,
				'name'       => $component_match['name'],
				'type'       => $component_match['type'],
				'confidence' => 'medium',
				'detection'  => $is_active
					? __( 'Table-name prefix/signature matched a currently active component name or slug.', 'codecaste-database-cleaner-optimizer' )
					: __( 'Table-name prefix/signature matched an installed but inactive component name or slug.', 'codecaste-database-cleaner-optimizer' ),
				'note'       => $is_active
					? __( 'The component is active, but the exact table reference was not found in source. The name match is treated as supporting evidence only.', 'codecaste-database-cleaner-optimizer' )
					: __( 'The component is installed but inactive. Its table can be shown as unused because inactive components are not treated as current owners. This is still a naming-based inference, not proof of ownership.', 'codecaste-database-cleaner-optimizer' ),
			);
		}

		// No installed plugin/theme matches. Check for a leftover row in
		// wp_options: deleting a plugin through WordPress removes its files
		// but usually leaves its settings/options behind unless the plugin's
		// uninstall.php explicitly cleaned them up. A leftover option whose
		// name matches this table's suffix is evidence the table belonged
		// to a plugin that is no longer installed.
		$options_match = self::match_options_leftover( $suffix );
		if ( $options_match ) {
			/* translators: %s: guessed plugin/component name */
			$label = sprintf( __( 'Possible source: %s (plugin appears removed)', 'codecaste-database-cleaner-optimizer' ), $options_match['name'] );
			return array(
				'label'      => $label,
				'name'       => $options_match['name'],
				'type'       => 'option',
				'confidence' => 'low',
				'detection'  => sprintf(
					/* translators: %1$s: leftover option name, %2$s: guessed plugin/component name */
					__( 'No installed plugin or theme matches this table, but a leftover site option "%1$s" shares its name. This suggests "%2$s" was previously installed and later deleted.', 'codecaste-database-cleaner-optimizer' ),
					$options_match['option_name'],
					$options_match['name']
				),
				'note'       => __( 'The plugin itself is no longer installed, so this is inferred only from a leftover entry in the site options table. Deleting a plugin from the Plugins screen does not always remove its options or custom tables — only a proper uninstall does.', 'codecaste-database-cleaner-optimizer' ),
			);
		}

		return array(
			'label'      => __( 'Source not recorded', 'codecaste-database-cleaner-optimizer' ),
			'name'       => '',
			'type'       => 'unknown',
			'confidence' => 'none',
			'detection'  => __( 'No saved source relationship or reliable component-name match was found.', 'codecaste-database-cleaner-optimizer' ),
			'note'       => __( 'The scanner can only name a source when it has evidence from current source code, previously saved ownership history, or a strong naming match. WordPress/MySQL does not retain a universal creator field for arbitrary plugin tables.', 'codecaste-database-cleaner-optimizer' ),
		);
	}

	/**
	 * Match a table suffix against automatically discovered global prefixes.
	 *
	 * @param string $suffix Table name without WordPress prefix.
	 * @param array  $prefixes Discovered prefix records.
	 * @return array|null
	 */
	private static function match_global_prefix( $suffix, $prefixes ) {
		$target = self::normalize_source_prefix( $suffix );
		if ( '' === $target || empty( $prefixes ) ) {
			return null;
		}

		$best  = null;
		$score = 0;

		foreach ( $prefixes as $prefix_data ) {
			if ( ! is_array( $prefix_data ) || empty( $prefix_data['prefix'] ) || empty( $prefix_data['name'] ) ) {
				continue;
			}

			$prefix = self::normalize_source_prefix( $prefix_data['prefix'] );
			if ( '' === $prefix || strlen( $prefix ) < 3 || 0 !== strpos( $target, $prefix ) ) {
				continue;
			}

			$current = 90 + min( 10, strlen( $prefix ) );
			if ( $target === $prefix ) {
				$current += 10;
			} elseif ( 0 === strpos( $target, $prefix . '_' ) ) {
				$current += 5;
			}

			if ( $current > $score ) {
				$score = $current;
				$best  = $prefix_data;
			}
		}

		return $best;
	}

	/**
	 * Normalize a discovered database/table prefix.
	 *
	 * @param string $value Prefix value.
	 * @return string
	 */
	private static function normalize_source_prefix( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '_', $value );
		return trim( (string) $value, '_-' );
	}

	/**
	 * Match a table suffix to an installed plugin/theme name or slug.
	 *
	 * @param string $suffix Unprefixed table name.
	 * @param bool $active_only Whether to match only currently active components.
	 * @return array|null
	 */
	private static function match_installed_component_name( $suffix, $active_only = true ) {
		$normalized = self::normalize_source_token( $suffix );
		if ( '' === $normalized ) {
			return null;
		}

		$best  = null;
		$score = 0;
		foreach ( self::get_components( $active_only ) as $component ) {
			$slug = self::normalize_source_token( $component['slug'] );
			$name = self::normalize_source_token( $component['name'] );
			foreach ( array( $slug, $name ) as $token ) {
				if ( strlen( $token ) < 4 ) {
					continue;
				}
				$current = 0;
				if ( $normalized === $token ) {
					$current = 100;
				} elseif ( 0 === strpos( $normalized, $token . '_' ) ) {
					$current = 90;
				} elseif ( 0 === strpos( $normalized, $token ) ) {
					$current = 75;
				}
				if ( $current > $score ) {
					$score = $current;
					$best  = $component;
				}
			}
		}
		return $best;
	}

	/**
	 * Determine whether a table suffix strongly matches the slug/name of an
	 * installed component. This is a conservative protection against false
	 * orphan detections when table construction is too dynamic for static
	 * source parsing to resolve the final name.
	 *
	 * @param string $suffix Unprefixed table name.
	 * @return bool
	 */
	private static function has_strong_installed_component_name_match( $suffix ) {
		$normalized = self::normalize_source_token( $suffix );
		if ( '' === $normalized ) {
			return false;
		}

		foreach ( self::get_components( false ) as $component ) {
			$tokens = array(
				self::normalize_source_token( $component['slug'] ?? '' ),
				self::normalize_source_token( $component['name'] ?? '' ),
			);

			foreach ( array_unique( $tokens ) as $token ) {
				if ( strlen( $token ) < 4 ) {
					continue;
				}

				if ( $normalized === $token || 0 === strpos( $normalized, $token . '_' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether an individual component is currently active.
	 *
	 * @param array $component Component metadata.
	 * @return bool
	 */
	private static function component_is_active( $component ) {
		if ( empty( $component['type'] ) ) {
			return false;
		}

		if ( 'mu-plugin' === $component['type'] ) {
			return true;
		}

		if ( 'theme' === $component['type'] ) {
			$active_theme = wp_get_theme();
			if ( $active_theme && $active_theme->get_stylesheet() === ( $component['slug'] ?? '' ) ) {
				return true;
			}

			$parent = $active_theme ? $active_theme->parent() : false;
			return $parent && $parent->get_stylesheet() === ( $component['slug'] ?? '' );
		}

		if ( 'plugin' === $component['type'] ) {
			$entry = isset( $component['plugin_file'] ) ? $component['plugin_file'] : '';
			if ( '' === $entry ) {
				return false;
			}

			$active = (array) get_option( 'active_plugins', array() );
			if ( in_array( $entry, $active, true ) ) {
				return true;
			}

			if ( is_multisite() ) {
				$network = (array) get_site_option( 'active_sitewide_plugins', array() );
				return isset( $network[ $entry ] );
			}
		}

		return false;
	}

	/**
	 * Generic option-name segments that are too common to trust as a plugin
	 * slug on their own (would produce false-positive matches).
	 *
	 * @var string[]
	 */
	private static $generic_option_tokens = array(
		'settings', 'options', 'option', 'data', 'cache', 'log', 'logs',
		'meta', 'config', 'items', 'item', 'entries', 'entry', 'queue',
		'history', 'index', 'db', 'table', 'tables', 'temp', 'tmp',
		'version', 'backup', 'export', 'import',
	);

	/**
	 * Look for a leftover wp_options row whose name matches an orphaned
	 * table's suffix, to identify a plugin that is no longer installed.
	 *
	 * Many plugins store their settings under an option key derived from
	 * their slug (e.g. "myplugin_settings", "myplugin_version"). Deleting
	 * the plugin through wp-admin removes its files but commonly leaves
	 * those options behind, so they remain usable evidence of what the
	 * table belonged to even after the plugin itself is gone.
	 *
	 * @param string $suffix Unprefixed table name.
	 * @return array{name:string,option_name:string,token:string}|null
	 */
	private static function match_options_leftover( $suffix ) {
		global $wpdb;

		$normalized = self::normalize_source_token( $suffix );
		if ( '' === $normalized ) {
			return null;
		}

		// Build candidate slugs from cumulative underscore-separated
		// segments (longest/most specific first), e.g. "acme_crm_leads"
		// yields "acme_crm_leads", "acme_crm", "acme".
		$segments   = explode( '_', $normalized );
		$candidates = array();
		$running    = '';
		foreach ( $segments as $segment ) {
			$running      = ( '' === $running ) ? $segment : $running . '_' . $segment;
			$candidates[] = $running;
		}
		$candidates = array_slice( array_reverse( array_values( array_unique( $candidates ) ) ), 0, 3 );

		foreach ( $candidates as $token ) {
			if ( strlen( $token ) < 4 || in_array( $token, self::$generic_option_tokens, true ) ) {
				continue;
			}

			// Strong match: an option literally named after the slug, or
			// prefixed by it (myplugin_settings, myplugin_db_version, ...).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$option_name = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s LIMIT 1",
					$token,
					$wpdb->esc_like( $token . '_' ) . '%'
				)
			);

			// Weaker fallback: the slug appears anywhere in an option name.
			// Only attempted for longer, more specific tokens to limit
			// false positives from short/common words.
			if ( ! $option_name && strlen( $token ) >= 6 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$option_name = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
						'%' . $wpdb->esc_like( $token ) . '%'
					)
				);
			}

			if ( $option_name ) {
				return array(
					'name'        => ucwords( str_replace( array( '_', '-' ), ' ', $token ) ),
					'option_name' => (string) $option_name,
					'token'       => $token,
				);
			}
		}

		return null;
	}

	/**
	 * Find one table row from the current information_schema result.
	 *
	 * @param array  $tables Table rows.
	 * @param string $name Table name.
	 * @return array|null
	 */
	private static function find_table_row( $tables, $name ) {
		foreach ( $tables as $table ) {
			if ( isset( $table['name'] ) && $table['name'] === $name ) {
				return $table;
			}
		}
		return null;
	}

	/**
	 * WordPress core tables.
	 *
	 * @return array<string,bool>
	 */
	private static function get_core_tables() {
		global $wpdb;
		$core = array();
		foreach ( (array) $wpdb->tables( 'all' ) as $name ) {
			$core[ $name ] = true;
		}
		foreach ( array( 'users', 'usermeta', 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'options', 'links', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' ) as $base ) {
			$core[ $wpdb->prefix . $base ] = true;
		}
		return $core;
	}

	/**
	 * Get WordPress table prefix.
	 *
	 * @return string
	 */
	private static function get_prefix() {
		global $wpdb;
		return (string) $wpdb->prefix;
	}

	/**
	 * Normalize a full table name.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalize_full_table_name( $value ) {
		$value = trim( (string) $value, " `\t\r\n\"\'" );
		if ( 0 === strpos( $value, self::get_prefix() ) ) {
			return $value;
		}
		return self::get_prefix() . ltrim( $value, '_' );
	}

	/**
	 * Normalize a source token for name matching.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalize_source_token( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( (string) $value, '_' );
	}
}
