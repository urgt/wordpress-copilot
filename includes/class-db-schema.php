<?php
defined( 'ABSPATH' ) || exit;

class WPC_DB_Schema {

    const CACHE_KEY = 'wpc_schema_v2';
    const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Returns a compact schema prompt suitable for injection into the AI system prompt.
     * Result is cached via WordPress transients (TTL configurable in settings).
     */
    public static function get_schema_prompt(): string {
        $ttl = (int) WPC_Settings::get( 'schema_cache_ttl', HOUR_IN_SECONDS );

        if ( $ttl > 0 ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                WPC_Logger::log( 'Schema loaded from transient cache.' );
                return $cached;
            }
        }

        WPC_Logger::log( 'Building fresh DB schema...' );
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection must use direct SHOW TABLES; cached via transient at higher level.
        $all_tables    = $wpdb->get_col( 'SHOW TABLES' );
        $excluded      = self::get_excluded_patterns();
        $max_tables    = (int) WPC_Settings::get( 'max_schema_tables', 80 );
        $anon_enabled  = (bool) WPC_Settings::get( 'anonymize_enabled', false );
        $anon_schema   = $anon_enabled && (bool) WPC_Settings::get( 'anonymize_schema', false );
        $anon_cols     = [];
        if ( $anon_schema ) {
            $raw = WPC_Settings::get( 'anonymize_columns', WPC_Settings::default_anon_columns() );
            $anon_cols = array_filter( array_map( 'strtolower', array_map( 'trim', explode( "\n", $raw ) ) ) );
        }

        // Filter excluded tables
        $tables = array_filter( $all_tables, function ( $t ) use ( $excluded ) {
            foreach ( $excluded as $pattern ) {
                if ( fnmatch( $pattern, $t ) ) return false;
            }
            return true;
        } );

        // Prioritise: WP core tables first, then others
        $prefix = $wpdb->prefix;
        $core_prefixes = [ $prefix . 'posts', $prefix . 'postmeta', $prefix . 'users', $prefix . 'usermeta',
                           $prefix . 'terms', $prefix . 'termmeta', $prefix . 'term_taxonomy',
                           $prefix . 'term_relationships', $prefix . 'options', $prefix . 'comments' ];
        usort( $tables, function ( $a, $b ) use ( $core_prefixes ) {
            $a_core = in_array( $a, $core_prefixes, true );
            $b_core = in_array( $b, $core_prefixes, true );
            if ( $a_core && ! $b_core ) return -1;
            if ( ! $a_core && $b_core ) return 1;
            return strcmp( $a, $b );
        } );

        $tables = array_slice( $tables, 0, $max_tables );
        $skipped = count( $all_tables ) - count( $tables );

        $lines = [
            'DATABASE: `wordpress_db`',
            'WordPress table prefix: `' . $wpdb->prefix . '`',
            '',
            '=== TABLES (' . count($tables) . ' of ' . count($all_tables) . ') ===',
        ];
        if ( $skipped > 0 ) {
            $lines[] = "(Note: {$skipped} tables excluded/truncated from schema)";
        }

        foreach ( $tables as $table ) {
            // Validate table name against the allowlist before issuing DESCRIBE
            if ( ! in_array( $table, $all_tables, true ) ) continue;

            $lines[] = '';
            $lines[] = "TABLE `{$table}`:";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DESCRIBE introspects live schema; cached at higher level.
            $columns = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE `%i`', $table ) );
            if ( $columns ) {
                foreach ( $columns as $col ) {
                    // Skip anonymized columns from schema
                    if ( $anon_schema && in_array( strtolower( $col->Field ), $anon_cols, true ) ) continue;

                    $parts = "  {$col->Field} {$col->Type}";
                    if ( $col->Null === 'NO' ) $parts .= ' NOT NULL';
                    if ( $col->Key         ) $parts .= " [{$col->Key}]";
                    $lines[] = $parts;
                }
            }
        }

        // ── WooCommerce hints ────────────────────────────────────
        if ( class_exists( 'WooCommerce' ) ) {
            $lines[] = '';
            $lines[] = '=== WOOCOMMERCE HINTS ===';
            $lines[] = "Products: post_type='product' in {$wpdb->posts}";
            $lines[] = "Product meta (price,stock,sku): {$wpdb->postmeta}";
            $lines[] = "Orders: post_type='shop_order' in {$wpdb->posts} (or wc_orders if HPOS enabled)";
            $lines[] = "Order items: {$wpdb->prefix}woocommerce_order_items";
            $lines[] = "Order item meta (product_id,qty,total): {$wpdb->prefix}woocommerce_order_itemmeta";
            $lines[] = "Product categories: taxonomy='product_cat' in {$wpdb->term_taxonomy}";

            // Check if HPOS is active
            if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
                $hpos_enabled = wc_get_container()
                    ->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
                    ->custom_orders_table_usage_is_enabled();
                if ( $hpos_enabled ) {
                    $lines[] = "HPOS is ACTIVE — use {$wpdb->prefix}wc_orders instead of wp_posts for orders";
                }
            }
        }

        // ── EDD hints ────────────────────────────────────────────
        if ( class_exists( 'Easy_Digital_Downloads' ) ) {
            $lines[] = '';
            $lines[] = '=== EASY DIGITAL DOWNLOADS HINTS ===';
            $lines[] = "Products: post_type='download' in {$wpdb->posts}";
            $lines[] = "Payments: {$wpdb->prefix}edd_orders";
        }

        // ── Common meta keys ─────────────────────────────────────
        $lines[] = '';
        $lines[] = '=== COMMON META KEYS ===';
        $lines[] = 'User: wp_capabilities, first_name, last_name, billing_*, shipping_*';
        $lines[] = 'Post: _price, _sale_price, _regular_price, _stock, _sku, _product_attributes';
        $lines[] = 'Term: thumbnail_id, display_type';

        $result = implode( "\n", $lines );
        if ( $ttl > 0 ) {
            set_transient( self::CACHE_KEY, $result, $ttl );
        }

        WPC_Logger::log( 'Schema built: ' . strlen($result) . ' chars, ' . count($tables) . ' tables.' );
        return $result;
    }

    public static function flush_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    private static function get_excluded_patterns(): array {
        $raw = WPC_Settings::get( 'excluded_tables', '' );
        if ( empty( trim( $raw ) ) ) return [];
        return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
    }
}
