<?php
/**
 * Database schema introspection for AI prompt generation.
 *
 * @package DataQueryAssistant
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a structured schema prompt from the live WordPress database.
 *
 * Inspects table structures, meta keys, plugin-specific patterns,
 * and caches the result for fast AI consumption.
 */
class DQA_DB_Schema {

	const CACHE_KEY = 'dqa_schema_v4';
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Returns a rich, structured schema prompt for injection into the AI system prompt.
	 * Includes: table columns, FK hints, sample values, postmeta key sampling,
	 * and active-plugin-aware hint blocks.
	 */
	public static function get_schema_prompt(): string {
		$ttl = (int) DQA_Settings::get( 'schema_cache_ttl', HOUR_IN_SECONDS );

		if ( $ttl > 0 ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				DQA_Logger::log( 'Schema loaded from transient cache.' );
				return $cached;
			}
		}

		DQA_Logger::log( 'Building fresh DB schema...' );
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection must use direct SHOW TABLES; cached via transient at higher level.
		$all_tables   = $wpdb->get_col( 'SHOW TABLES' );
		$excluded     = self::get_excluded_patterns();
		$max_tables   = (int) DQA_Settings::get( 'max_schema_tables', 80 );
		$anon_enabled = (bool) DQA_Settings::get( 'anonymize_enabled', false );
		$anon_schema  = $anon_enabled && (bool) DQA_Settings::get( 'anonymize_schema', false );
		$anon_cols    = array();
		if ( $anon_schema ) {
			$raw       = DQA_Settings::get( 'anonymize_columns', DQA_Settings::default_anon_columns() );
			$anon_cols = array_filter( array_map( 'strtolower', array_map( 'trim', explode( "\n", $raw ) ) ) );
		}

		// Filter excluded tables.
		$tables = array_filter(
			$all_tables,
			function ( $t ) use ( $excluded ) {
				foreach ( $excluded as $pattern ) {
					if ( fnmatch( $pattern, $t ) ) {
						return false;
					}
				}
				return true;
			}
		);

		// Prioritise: WP core tables first, then others alphabetically.
		$prefix      = $wpdb->prefix;
		$core_tables = array(
			$prefix . 'posts',
			$prefix . 'postmeta',
			$prefix . 'users',
			$prefix . 'usermeta',
			$prefix . 'terms',
			$prefix . 'termmeta',
			$prefix . 'term_taxonomy',
			$prefix . 'term_relationships',
			$prefix . 'options',
			$prefix . 'comments',
			$prefix . 'commentmeta',
		);
		usort(
			$tables,
			function ( $a, $b ) use ( $core_tables ) {
				$a_core = in_array( $a, $core_tables, true );
				$b_core = in_array( $b, $core_tables, true );
				if ( $a_core && ! $b_core ) {
					return -1;
				}
				if ( ! $a_core && $b_core ) {
					return 1;
				}
				return strcmp( $a, $b );
			}
		);

		$tables  = array_slice( $tables, 0, $max_tables );
		$skipped = count( $all_tables ) - count( $tables );

		// ── Classify tables into groups ───────────────────────────
		$wc_prefix_tables      = array_filter( $tables, fn( $t ) => str_contains( $t, '_wc_' ) || str_contains( $t, '_woocommerce_' ) );
		$wc_prefix_table_names = array_values( $wc_prefix_tables );
		$core_table_names      = array_values( array_filter( $tables, fn( $t ) => in_array( $t, $core_tables, true ) ) );
		$plugin_table_names    = array_values( array_filter( $tables, fn( $t ) => ! in_array( $t, $core_tables, true ) && ! in_array( $t, $wc_prefix_table_names, true ) ) );

		$lines = array(
			'DATABASE: `wordpress_db`',
			'WordPress table prefix: `' . $prefix . '`',
			'',
			'NOTE: This is a WordPress database. Post metadata, user metadata, and term metadata',
			'are stored as key-value rows in *meta tables. Always JOIN on the relevant meta_key.',
		);

		if ( $skipped > 0 ) {
			$lines[] = "(Note: {$skipped} tables excluded/truncated from schema)";
		}

		// ── Helper: describe one table ────────────────────────────
		$describe_table = function ( string $table ) use ( $wpdb, $all_tables, $anon_schema, $anon_cols, $prefix ): array {
			if ( ! in_array( $table, $all_tables, true ) ) {
				return array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DESCRIBE introspects live schema; cached at higher level.
			$columns = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ) );
			if ( ! $columns ) {
				return array();
			}

			$col_lines = array();
			foreach ( $columns as $col ) {
				if ( $anon_schema && in_array( strtolower( $col->Field ), $anon_cols, true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				$parts = "  {$col->Field} {$col->Type}";
				if ( 'NO' === $col->Null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$parts .= ' NOT NULL';
				}
				// Annotate key types.
				if ( 'PRI' === $col->Key ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$parts .= ' [PK]';
				} elseif ( 'MUL' === $col->Key || 'UNI' === $col->Key ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$parts .= " [{$col->Key}]"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}

				// Helpful FK annotations for well-known columns.
				$col_lower = strtolower( $col->Field ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( 'post_id' === $col_lower || 'object_id' === $col_lower ) {
					$parts .= ' -- FK→' . $prefix . 'posts.ID';
				} elseif ( 'user_id' === $col_lower ) {
					$parts .= ' -- FK→' . $prefix . 'users.ID';
				} elseif ( 'comment_id' === $col_lower ) {
					$parts .= ' -- FK→' . $prefix . 'comments.comment_ID';
				} elseif ( 'term_id' === $col_lower ) {
					$parts .= ' -- FK→' . $prefix . 'terms.term_id';
				} elseif ( 'term_taxonomy_id' === $col_lower ) {
					$parts .= ' -- FK→' . $prefix . 'term_taxonomy.term_taxonomy_id';
				}

				// Sample values for highly semantic enum-like columns.
				if ( $table === $prefix . 'posts' ) {
					if ( 'post_type' === $col_lower ) {
						$parts .= " -- e.g. 'post','page','product','attachment','shop_order'";
					} elseif ( 'post_status' === $col_lower ) {
						$parts .= " -- e.g. 'publish','draft','trash','pending','private'";
					}
				}

				$col_lines[] = $parts;
			}

			return $col_lines;
		};

		// ── Section 1: WP Core ────────────────────────────────────
		$lines[] = '';
		$lines[] = '## SECTION: WP CORE TABLES';

		foreach ( $core_table_names as $table ) {
			$col_lines = $describe_table( $table );
			if ( empty( $col_lines ) ) {
				continue;
			}
			$lines[] = '';
			$lines[] = "TABLE `{$table}`:";
			foreach ( $col_lines as $l ) {
				$lines[] = $l;
			}
		}

		// ── Core relationship summary ─────────────────────────────
		$lines[] = '';
		$lines[] = '## CORE RELATIONSHIPS';
		$lines[] = "posts ←→ postmeta   via {$prefix}posts.ID = {$prefix}postmeta.post_id";
		$lines[] = "posts ←→ term_relationships via {$prefix}posts.ID = {$prefix}term_relationships.object_id";
		$lines[] = 'term_relationships ←→ term_taxonomy ←→ terms (taxonomy hierarchy)';
		$lines[] = "users ←→ usermeta   via {$prefix}users.ID = {$prefix}usermeta.user_id";
		$lines[] = "posts ←→ comments   via {$prefix}posts.ID = {$prefix}comments.comment_post_ID";

		// ── Section 2: WooCommerce ────────────────────────────────
		if ( class_exists( 'WooCommerce' ) || ! empty( $wc_prefix_table_names ) ) {
			$lines[] = '';
			$lines[] = '## SECTION: WOOCOMMERCE TABLES';
			$lines[] = "Products:      post_type='product' in {$prefix}posts";
			$lines[] = "Product price/stock/sku:  meta_keys _price, _regular_price, _sale_price, _stock, _stock_status, _sku in {$prefix}postmeta";
			$lines[] = "Product popularity (WC):  meta_key total_sales in {$prefix}postmeta (cumulative sold count)";
			$lines[] = "Orders (CPT mode): post_type='shop_order' in {$prefix}posts, status = wc-completed/wc-processing/wc-pending/wc-cancelled";
			$lines[] = "Order total: meta_key _order_total in {$prefix}postmeta (use this for revenue sums when HPOS is off)";
			$lines[] = "Order items:   {$prefix}woocommerce_order_items (order_item_id, order_id, order_item_name, order_item_type)";
			$lines[] = "Order item meta: {$prefix}woocommerce_order_itemmeta — keys: _product_id, _variation_id, _qty, _line_total, _line_subtotal";
			$lines[] = "Customer: billing/shipping meta in {$prefix}usermeta";

			if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
				$hpos_enabled = false;
				try {
					$hpos_enabled = wc_get_container()
						->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
						->custom_orders_table_usage_is_enabled();
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Ignore HPOS detection failures.
				}
				if ( $hpos_enabled ) {
					$lines[] = "⚠ HPOS is ACTIVE — use {$prefix}wc_orders + {$prefix}wc_order_addresses + {$prefix}wc_order_operational_data INSTEAD of wp_posts for orders";
					$lines[] = "  {$prefix}wc_orders columns: id, status (wc-completed etc.), currency, total_amount, customer_id, date_created_gmt";
				} else {
					$lines[] = "⚠ HPOS is DISABLED — {$prefix}wc_order_stats may be empty or stale; DO NOT use it for revenue/totals.";
					$lines[] = "  For order revenue always use: SUM(CAST(pm.meta_value AS DECIMAL(10,2))) from {$prefix}postmeta WHERE meta_key='_order_total' joined to {$prefix}posts WHERE post_type='shop_order'";
				}
			} else {
				// WC not installed or very old — safe default.
				$lines[] = "⚠ For order revenue use {$prefix}posts (post_type='shop_order') + {$prefix}postmeta (meta_key='_order_total'). Do NOT rely on {$prefix}wc_order_stats.";
			}

			foreach ( $wc_prefix_table_names as $table ) {
				$col_lines = $describe_table( $table );
				if ( empty( $col_lines ) ) {
					continue;
				}
				$lines[] = '';
				$lines[] = "TABLE `{$table}`:";
				foreach ( $col_lines as $l ) {
					$lines[] = $l;
				}
			}
		}

		// ── Section 3: EDD ────────────────────────────────────────
		if ( class_exists( 'Easy_Digital_Downloads' ) ) {
			$lines[] = '';
			$lines[] = '## SECTION: EASY DIGITAL DOWNLOADS';
			$lines[] = "Products: post_type='download' in {$prefix}posts";
			$lines[] = "Payments: {$prefix}edd_orders";
		}

		// ── Section 4: Plugin tables (non-WC) ────────────────────
		if ( ! empty( $plugin_table_names ) ) {
			$lines[] = '';
			$lines[] = '## SECTION: PLUGIN TABLES';
			foreach ( $plugin_table_names as $table ) {
				$col_lines = $describe_table( $table );
				if ( empty( $col_lines ) ) {
					continue;
				}
				$lines[] = '';
				$lines[] = "TABLE `{$table}`:";
				foreach ( $col_lines as $l ) {
					$lines[] = $l;
				}
			}
		}

		// ── Section 5: Active plugins list ────────────────────────
		$active_plugins = self::build_active_plugins_list();
		if ( ! empty( $active_plugins ) ) {
			$lines[] = '';
			$lines[] = '## SECTION: ACTIVE PLUGINS (' . count( $active_plugins ) . ')';
			$lines[] = 'These plugins are currently active on this WordPress site.';
			$lines[] = 'Consider their data storage patterns when generating queries.';
			foreach ( $active_plugins as $plugin_name ) {
				$lines[] = '  - ' . $plugin_name;
			}
		}

		// ── Section 6: Detected plugin data hints ─────────────────
		$plugin_hints = self::build_plugin_hints( $prefix );
		if ( ! empty( $plugin_hints ) ) {
			$lines[] = '';
			$lines[] = '## SECTION: DETECTED PLUGIN DATA PATTERNS';
			foreach ( $plugin_hints as $hint ) {
				$lines[] = $hint;
			}
		}

		// ── Section 6: Postmeta key sampling ─────────────────────
		$meta_samples = self::build_postmeta_samples( $prefix );
		if ( ! empty( $meta_samples ) ) {
			$lines[] = '';
			$lines[] = '## SECTION: POSTMETA KEY INVENTORY (actual keys in this DB)';
			$lines[] = 'Use these to answer questions about specific data stored per post/product.';
			foreach ( $meta_samples as $type => $keys ) {
				$lines[] = '';
				$lines[] = "  post_type='{$type}': " . implode( ', ', $keys );
			}
		}

		// ── Section 7: Common meta key reference ─────────────────
		$lines[] = '';
		$lines[] = '## SECTION: COMMON META KEYS REFERENCE';
		$lines[] = 'User meta:  wp_capabilities, first_name, last_name, billing_email, billing_phone, billing_address_1, shipping_address_1';
		$lines[] = 'Post meta:  _price, _sale_price, _regular_price, _stock, _sku, _product_attributes, _thumbnail_id';
		$lines[] = 'Term meta:  thumbnail_id, display_type, order';

		$result = implode( "\n", $lines );
		if ( $ttl > 0 ) {
			set_transient( self::CACHE_KEY, $result, $ttl );
		}

		DQA_Logger::log( 'Schema built: ' . strlen( $result ) . ' chars, ' . count( $tables ) . ' tables.' );
		return $result;
	}

	/**
	 * Flush the cached schema transient.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/* ── Active plugins list ────────────────────────────────────── */

	/**
	 * Returns a clean list of human-readable active plugin names.
	 *
	 * Uses get_plugins() to resolve file paths to plugin Name headers.
	 * Falls back to slug derivation if plugin data is unavailable.
	 *
	 * @return string[]
	 */
	private static function build_active_plugins_list(): array {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$all     = array_unique( array_merge( $active, $network ) );

		if ( empty( $all ) ) {
			return array();
		}

		// Load plugin data for name resolution.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		$names = array();
		foreach ( $all as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ]['Name'] ) ) {
				$names[] = $all_plugins[ $plugin_file ]['Name'];
			} else {
				// Fallback: derive name from directory slug.
				$slug    = dirname( $plugin_file );
				$names[] = ( '.' === $slug ) ? basename( $plugin_file, '.php' ) : $slug;
			}
		}

		sort( $names );
		return $names;
	}

	/* ── Plugin-aware hint blocks ───────────────────────────────── */

	/**
	 * Detects active plugins and returns targeted data-pattern hints for the AI.
	 *
	 * @param string $prefix WP table prefix.
	 * @return string[]
	 */
	private static function build_plugin_hints( string $prefix ): array {
		$active = (array) get_option( 'active_plugins', array() );
		// Also check multisite network-activated plugins.
		$network    = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		$all_active = array_merge( $active, array_keys( $network ) );
		$active_str = implode( ' ', $all_active );

		$hints = array();

		// ── View counters ────────────────────────────────────────
		if ( str_contains( $active_str, 'post-views-counter' ) || class_exists( 'Post_Views_Counter' ) ) {
			$hints[] = '[Plugin: Post Views Counter] View counts stored in postmeta: meta_key = "_post_views_count" (integer). Example: ORDER BY CAST(pm_views.meta_value AS UNSIGNED) DESC';
			$hints[] = '  Also stores monthly/daily stats in table `' . $prefix . 'post_views` if it exists.';
		}
		if ( str_contains( $active_str, 'wp-postviews' ) || function_exists( 'the_views' ) ) {
			$hints[] = '[Plugin: WP-PostViews] View counts stored in postmeta: meta_key = "views" (integer).';
		}
		if ( str_contains( $active_str, 'jetpack' ) || class_exists( 'Jetpack' ) ) {
			$hints[] = '[Plugin: Jetpack Stats] Does NOT store views in postmeta. Provides stats via Jetpack REST API only.';
			$hints[] = '  For Jetpack: you cannot query views directly via SQL. Suggest using total_sales or comment_count as a proxy for popularity.';
		}
		if ( str_contains( $active_str, 'statpress' ) || str_contains( $active_str, 'slimstat' ) ) {
			$hints[] = '[Plugin: Slimstat/StatPress] Stores pageviews in table `' . $prefix . 'slim_stats`. Key columns: id, ip, other (user agent), referer, resource (URL/post path), dt (timestamp), browser, os, country.';
			$hints[] = '  To get views per post, JOIN on the resource column matching post permalink or post_id.';
		}
		if ( str_contains( $active_str, 'popular-posts' ) || class_exists( 'WordPressPopularPosts' ) ) {
			$hints[] = '[Plugin: WordPress Popular Posts] Stores daily/total views in tables: `' . $prefix . 'popularpostssummary` (columns: postid, pageviews, last_viewed) and `' . $prefix . 'popularpostsdata` (columns: postid, day_views, last_viewed). Example: SELECT postid, pageviews FROM ' . $prefix . 'popularpostssummary ORDER BY pageviews DESC LIMIT 10.';
		}
		if ( str_contains( $active_str, 'really-simple-csv-importer' ) || str_contains( $active_str, 'analytics' ) || str_contains( $active_str, 'monsterinsights' ) ) {
			$hints[] = '[Plugin: MonsterInsights / GA] Analytics data is not stored in WordPress DB — stats live in Google Analytics.';
		}

		// ── SEO plugins ─────────────────────────────────────────
		if ( str_contains( $active_str, 'wordpress-seo' ) || class_exists( 'WPSEO_Options' ) ) {
			$hints[] = '[Plugin: Yoast SEO] SEO data in postmeta: _yoast_wpseo_focuskw (focus keyword), _yoast_wpseo_metadesc, _yoast_wpseo_title, _yoast_wpseo_canonical, _yoast_wpseo_opengraph-image.';
		}
		if ( str_contains( $active_str, 'seo-by-rank-math' ) || class_exists( 'RankMath' ) ) {
			$hints[] = '[Plugin: Rank Math SEO] SEO data in postmeta: rank_math_focus_keyword, rank_math_seo_score, rank_math_description, rank_math_title.';
		}

		// ── Forms ────────────────────────────────────────────────
		if ( str_contains( $active_str, 'wpforms' ) || class_exists( 'WPForms' ) ) {
			$hints[] = '[Plugin: WPForms] Form entries in tables: `' . $prefix . 'wpforms_entries` (columns: id, form_id, post_id, user_id, status, type, date, date_modified, fields) and `' . $prefix . 'wpforms_entry_fields`. Fields are stored as JSON in the `fields` column.';
		}
		if ( str_contains( $active_str, 'gravityforms' ) || class_exists( 'GFCommon' ) ) {
			$hints[] = '[Plugin: Gravity Forms] Form entries in `' . $prefix . 'gf_entry` (columns: id, form_id, post_id, date_created, date_updated, is_starred, is_read, ip, source_url, user_agent, status). Field values in `' . $prefix . 'gf_entry_meta`.';
		}
		if ( str_contains( $active_str, 'contact-form-7' ) || class_exists( 'WPCF7' ) ) {
			$hints[] = '[Plugin: Contact Form 7] Does not store submissions in DB by default (use Flamingo add-on). With Flamingo: `' . $prefix . 'flamingo_inbound_messages`.';
		}

		// ── ACF ──────────────────────────────────────────────────
		if ( str_contains( $active_str, 'advanced-custom-fields' ) || class_exists( 'ACF' ) || function_exists( 'get_field' ) ) {
			$hints[] = '[Plugin: Advanced Custom Fields] Field values are stored in postmeta. Each field has TWO rows: one with the actual meta_key (e.g. "my_field") and one with a hidden key (e.g. "_my_field") pointing to the field group. To query: JOIN on the real meta_key, ignore the underscore-prefixed reference key.';
		}

		// ── eCommerce extras ─────────────────────────────────────
		if ( class_exists( 'WooCommerce' ) ) {
			$hints[] = '[WooCommerce] Product popularity/sales: meta_key "total_sales" in postmeta contains cumulative order count. For revenue: sum _line_total in woocommerce_order_itemmeta.';
			$hints[] = '[WooCommerce] Product rating: meta_keys "_wc_average_rating" and "_wc_rating_count" in postmeta.';
		}

		return $hints;
	}

	/* ── Postmeta key sampling ──────────────────────────────────── */

	/**
	 * For each active post_type, samples the top-used meta_keys.
	 * Results are included in the schema so the LLM knows what data exists.
	 *
	 * @param string $prefix WP table prefix.
	 * @return array<string, string[]> Map of post_type => [meta_key, ...]
	 */
	private static function build_postmeta_samples( string $prefix ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema introspection; cached via parent transient.
		$post_types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_type FROM %i WHERE post_status = 'publish' AND post_type NOT IN ('attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache') ORDER BY post_type LIMIT 8",
				$prefix . 'posts'
			)
		);

		if ( ! $post_types ) {
			return array();
		}

		$result = array();
		foreach ( $post_types as $post_type ) {
			/*
			 * Include ALL meta_keys (including underscore-prefixed WooCommerce keys like
			 * _price, _sku, _order_total, _stock, etc.) but exclude WP-internal noise
			 * that provides no analytical value (edit locks, oEmbed cache, etc.).
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sampling for schema; cached at higher level.
			$keys = $wpdb->get_col(
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- Static exclusion patterns, not user input.
				$wpdb->prepare(
					"SELECT pm.meta_key
					 FROM %i pm
					 INNER JOIN %i p ON p.ID = pm.post_id
					 WHERE p.post_type = %s AND p.post_status = 'publish'
					   AND pm.meta_key NOT LIKE '\\_edit\\_%%'
					   AND pm.meta_key NOT LIKE '\\_oembed\\_%%'
					   AND pm.meta_key NOT LIKE '\\_menu\\_item\\_%%'
					   AND pm.meta_key NOT IN ('_encloseme', '_pingme')
					 GROUP BY pm.meta_key
					 ORDER BY COUNT(*) DESC
					 LIMIT 50",
					$prefix . 'postmeta',
					$prefix . 'posts',
					$post_type
				)
				// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
			);

			if ( ! empty( $keys ) ) {
				$result[ $post_type ] = $keys;
			}
		}

		return $result;
	}

	/**
	 * Get the list of excluded table patterns from settings.
	 *
	 * @return string[]
	 */
	private static function get_excluded_patterns(): array {
		$raw = DQA_Settings::get( 'excluded_tables', '' );
		if ( empty( trim( $raw ) ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
	}
}
