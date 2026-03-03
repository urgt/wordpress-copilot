<?php
defined( 'ABSPATH' ) || exit;

class WPC_Settings {

    const OPTION_KEY = 'wpc_settings_v2';

    /* ── Getter ─────────────────────────────────────────────────── */
    public static function get( string $key, $default = '' ) {
        static $cache = null;
        if ( $cache === null ) {
            $cache = get_option( self::OPTION_KEY, [] );
        }
        return $cache[ $key ] ?? $default;
    }

    public static function bust_cache(): void {
        wp_cache_delete( self::OPTION_KEY, 'options' );
    }

    /* ── Providers config ───────────────────────────────────────── */
    public static function get_providers(): array {
        return [
            'anthropic' => [
                'label'   => 'Anthropic (Claude)',
                'models'  => [
                    'claude-opus-4-6'           => 'Claude Opus 4.6',
                    'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
                    'claude-opus-4-5'           => 'Claude Opus 4.5',
                    'claude-sonnet-4-5'         => 'Claude Sonnet 4.5',
                    'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
                    'claude-sonnet-4-0'         => 'Claude Sonnet 4.0',
                ],
                'default_model' => 'claude-sonnet-4-5',
            ],
            'openai' => [
                'label'   => 'OpenAI (GPT)',
                'models'  => [
                    'gpt-5.2'            => 'GPT-5.2',
                    'gpt-5.1'            => 'GPT-5.1',
                    'gpt-5.1-codex'      => 'GPT-5.1 Codex',
                    'gpt-5.1-codex-mini' => 'GPT-5.1 Codex Mini',
                    'gpt-5-mini'         => 'GPT-5 Mini',
                    'gpt-4.1'            => 'GPT-4.1',
                    'gpt-4o'             => 'GPT-4o',
                    'gpt-4o-mini'        => 'GPT-4o Mini',
                ],
                'default_model' => 'gpt-4o',
            ],
            'google' => [
                'label'   => 'Google (Gemini)',
                'models'  => [
                    'gemini-3-pro-preview'      => 'Gemini 3 Pro (Preview)',
                    'gemini-3-flash-preview'    => 'Gemini 3 Flash (Preview)',
                    'gemini-2.5-pro'            => 'Gemini 2.5 Pro',
                    'gemini-2.5-flash'          => 'Gemini 2.5 Flash',
                ],
                'default_model' => 'gemini-2.5-flash',
            ],
        ];
    }

    /** Default column patterns to anonymize */
    public static function default_anon_columns(): string {
        return implode( "\n", [
            'email', 'user_email', 'billing_email', 'shipping_email',
            'phone', 'billing_phone', 'shipping_phone',
            'user_pass', 'password',
            'first_name', 'last_name', 'display_name',
            'billing_first_name', 'billing_last_name',
            'billing_address_1', 'billing_address_2',
            'billing_city', 'billing_postcode',
            'card_number', 'credit_card',
        ] );
    }

    /* ── Registration ───────────────────────────────────────────── */
    public static function init(): void {
        add_action( 'admin_menu',  [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_wpc_flush_schema', [ __CLASS__, 'ajax_flush_schema' ] );
    }

    public static function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'settings_page_wordpress-copilot' ) return;
        wp_enqueue_style(
            'wpc-admin',
            WPC_URL . 'assets/css/admin.css',
            [],
            WPC_VERSION
        );
    }

    public static function register_settings(): void {
        register_setting( 'wpc_group', self::OPTION_KEY, [
            'sanitize_callback' => [ __CLASS__, 'sanitize' ],
        ] );
    }

    public static function sanitize( $input ): array {
        $providers = array_keys( self::get_providers() );
        $clean = [];

        // AI Provider
        $clean['provider']      = in_array( $input['provider'] ?? '', $providers, true )
                                  ? $input['provider'] : 'anthropic';
        $clean['api_key']       = sanitize_text_field( $input['api_key'] ?? '' );
        $clean['model']         = sanitize_text_field( $input['model'] ?? '' );

        // Access
        $clean['allowed_roles'] = array_map( 'sanitize_key', (array)( $input['allowed_roles'] ?? ['administrator'] ) );
        $clean['max_rows']      = max( 1, min( 5000, intval( $input['max_rows'] ?? 100 ) ) );

        // Privacy / Anonymizer — single level control
        $valid_levels = [ 'off', 'results', 'full' ];
        $clean['anonymize_level']   = in_array( $input['anonymize_level'] ?? 'off', $valid_levels, true )
                                      ? $input['anonymize_level'] : 'off';
        $clean['anonymize_columns'] = sanitize_textarea_field( $input['anonymize_columns'] ?? self::default_anon_columns() );
        // Back-compat aliases
        $clean['anonymize_enabled'] = $clean['anonymize_level'] !== 'off';
        $clean['anonymize_schema']  = $clean['anonymize_level'] === 'full';

        // Performance / Large DB
        $valid_ttls = [ '0', '600', '3600', '21600', '86400' ];
        $clean['schema_cache_ttl'] = in_array( $input['schema_cache_ttl'] ?? '3600', $valid_ttls, true )
                                     ? $input['schema_cache_ttl'] : '3600';
        $clean['excluded_tables']  = sanitize_textarea_field( $input['excluded_tables'] ?? '' );
        $clean['max_schema_tables']= max( 5, min( 500, intval( $input['max_schema_tables'] ?? 80 ) ) );
        $clean['compact_schema']   = true; // always compact — COUNT(*) removed
        $clean['query_timeout']    = max( 0, min( 120, intval( $input['query_timeout'] ?? 15 ) ) );

        // Advanced
        $clean['streaming']     = ! empty( $input['streaming'] );
        $clean['enable_voice']  = ! empty( $input['enable_voice'] );
        $clean['show_sql']      = ! empty( $input['show_sql'] );
        $clean['log_queries']   = ! empty( $input['log_queries'] );
        $clean['debug_mode']    = ! empty( $input['debug_mode'] );

        self::bust_cache();
        WPC_DB_Schema::flush_cache();
        return $clean;
    }

    public static function ajax_flush_schema(): void {
        check_ajax_referer( 'wpc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        WPC_DB_Schema::flush_cache();
        wp_send_json_success( [ 'message' => 'Schema cache cleared.' ] );
    }

    /* ── Settings Page ──────────────────────────────────────────── */
    public static function add_settings_page(): void {
        add_options_page(
            'WordPress Copilot',
            'WP Copilot',
            'manage_options',
            'wordpress-copilot',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /** Known plugin table patterns → [label, description] */
    private static function known_noisy_patterns(): array {
        return [
            'wp_yoast_*'           => [ 'Yoast SEO',          'Indexables, primary terms, SEO meta cache' ],
            'wp_rank_math_*'       => [ 'Rank Math SEO',       'Keyword tracking, analytics cache' ],
            'wp_wc_admin_*'        => [ 'WooCommerce Admin',   'Reports cache, notes, onboarding' ],
            'wp_actionscheduler_*' => [ 'Action Scheduler',    'Background job queue (used by WooCommerce)' ],
            'wp_wfls_*'            => [ 'Wordfence',           'Security scan logs and settings' ],
            'wp_wf_*'              => [ 'Wordfence',           'Firewall rules and attack data' ],
            'wp_itsec_*'           => [ 'iThemes Security',    'Security logs and temp data' ],
            'wp_elementor_*'       => [ 'Elementor',           'Page builder CSS/cache' ],
            'wp_e_*'               => [ 'Elementor',           'Elementor kit and session data' ],
            'wp_redirection_*'     => [ 'Redirection',         'Redirect rules and 404 log' ],
            'wp_icl_*'             => [ 'WPML',                'Translation strings and settings' ],
            'wp_learnpress_*'      => [ 'LearnPress LMS',      'Course, lesson, quiz data' ],
            'wp_litespeed_*'       => [ 'LiteSpeed Cache',     'Page cache and optimization data' ],
            'wp_imagify_*'         => [ 'Imagify',             'Image optimization records' ],
            'wp_smush_*'           => [ 'WP Smush',            'Image compression data' ],
            'wp_revslider_*'       => [ 'Revolution Slider',   'Slider data and navigation' ],
            'wp_frm_*'             => [ 'Formidable Forms',    'Form entries and stats' ],
            'wp_gf_*'              => [ 'Gravity Forms',       'Form entries (may be useful to keep)' ],
            'wp_sessions'          => [ 'PHP Sessions',        'Server session storage' ],
            'wp_ewwwio_*'          => [ 'EWWW Image Optimizer','Image processing records' ],
        ];
    }

    /** Returns matched recommendations: [pattern, label, desc, count] */
    public static function get_recommendations(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES for recommendations UI; not cacheable (must reflect current state).
        $all_tables = $wpdb->get_col( 'SHOW TABLES' );
        $existing_exclusions = array_filter( array_map( 'trim',
            explode( "\n", self::get( 'excluded_tables', '' ) ) ) );

        $recs = [];
        foreach ( self::known_noisy_patterns() as $pattern => $info ) {
            if ( in_array( $pattern, $existing_exclusions, true ) ) continue;
            $matched = array_filter( $all_tables, fn( $t ) => fnmatch( $pattern, $t ) );
            if ( ! empty( $matched ) ) {
                $recs[] = [
                    'pattern' => $pattern,
                    'label'   => $info[0],
                    'desc'    => $info[1],
                    'count'   => count( $matched ),
                    'tables'  => array_values( $matched ),
                ];
            }
        }
        return $recs;
    }

    /** Schema cache status with expiry and token estimate */
    public static function get_schema_status(): array {
        $cached_schema = get_transient( WPC_DB_Schema::CACHE_KEY );
        if ( $cached_schema === false ) {
            return [ 'cached' => false, 'expires_in' => 0, 'tokens' => 0, 'chars' => 0 ];
        }

        global $wpdb;
        $ttl_key = '_transient_timeout_' . WPC_DB_Schema::CACHE_KEY;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading transient timeout directly to show accurate expiry countdown.
        $expires = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $ttl_key )
        );
        $expires_in  = max( 0, $expires - time() );
        $chars       = strlen( $cached_schema );
        $tokens      = (int) ceil( $chars / 4 );

        return [
            'cached'     => true,
            'expires_in' => $expires_in,
            'tokens'     => $tokens,
            'chars'      => $chars,
        ];
    }

    public static function render_settings_page(): void {
        $opts      = get_option( self::OPTION_KEY, [] );
        $roles     = wp_roles()->roles;
        $providers = self::get_providers();
        $cur_prov  = $opts['provider'] ?? 'anthropic';

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table count and DB size for settings dashboard; reflects live state.
        $table_count    = count( $wpdb->get_col( 'SHOW TABLES' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- information_schema query; no user input; reflect live DB size.
        $db_size_mb     = (float) $wpdb->get_var(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1)
             FROM information_schema.TABLES WHERE table_schema = DATABASE()"
        );
        $schema_status  = self::get_schema_status();
        $recommendations = self::get_recommendations();
        $admin_nonce    = wp_create_nonce( 'wpc_admin_nonce' );
        $anon_level     = $opts['anonymize_level'] ?? 'off';
        ?>
        <div class="wpc-admin-page">

        <div class="wpc-admin-header">
            <h1>⚡ WordPress Copilot <span>v<?php echo esc_html( WPC_VERSION ); ?></span></h1>
        </div>

        <?php settings_errors( 'wpc_group' ); ?>

        <!-- Tabs -->
        <div class="wpc-tabs">
            <button class="wpc-tab-btn active" data-tab="provider">🤖 AI Provider</button>
            <button class="wpc-tab-btn" data-tab="privacy">🔒 Privacy</button>
            <button class="wpc-tab-btn" data-tab="performance">⚡ Performance</button>
            <button class="wpc-tab-btn" data-tab="access">👥 Access</button>
            <button class="wpc-tab-btn" data-tab="advanced">⚙️ Advanced</button>
        </div>

        <form method="post" action="options.php" id="wpc-settings-form">
            <?php settings_fields( 'wpc_group' ); ?>

            <!-- ── Tab: AI Provider ─────────────────────────────── -->
            <div class="wpc-tab-panel active" data-panel="provider">

                <div class="wpc-card">
                    <h3>Provider</h3>
                    <?php foreach ( $providers as $key => $info ) :
                        $icons = [ 'anthropic' => '🟤', 'openai' => '🟢', 'google' => '🔵' ];
                    ?>
                    <label class="wpc-provider-card">
                        <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[provider]"
                               value="<?php echo esc_attr($key); ?>"
                               <?php checked( $cur_prov, $key ); ?>
                               data-provider="<?php echo esc_attr($key); ?>">
                        <div>
                            <div class="prov-name"><?php echo esc_html( $icons[$key] ?? '⚫' ); ?> <?php echo esc_html($info['label']); ?></div>
                            <div class="prov-desc"><?php
                                $descs = [
                                    'anthropic' => 'Claude models — great for nuanced analysis and long contexts',
                                    'openai'    => 'GPT models — fast, reliable, excellent at structured output',
                                    'google'    => 'Gemini models — cost-effective, strong multilingual support',
                                ];
                                echo esc_html( $descs[$key] ?? '' );
                            ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="wpc-card">
                    <h3>Model</h3>
                    <?php foreach ( $providers as $key => $info ) : ?>
                    <div class="wpc-model-group" data-provider="<?php echo esc_attr($key); ?>"
                         style="display:<?php echo $cur_prov === $key ? 'block' : 'none'; ?>">
                        <div class="wpc-field">
                            <label>Active model</label>
                            <div>
                                <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]">
                                    <?php foreach ( $info['models'] as $mid => $mname ) : ?>
                                        <option value="<?php echo esc_attr($mid); ?>"
                                            <?php selected( ($opts['model'] ?? $info['default_model']), $mid ); ?>>
                                            <?php echo esc_html($mname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wpc-desc">Default model for the chat. Can be overridden per-session in the chat UI.</p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="wpc-card">
                    <h3>API Key</h3>
                    <div class="wpc-field">
                        <label>Secret key</label>
                        <div>
                            <input type="password"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
                                   value="<?php echo esc_attr( $opts['api_key'] ?? '' ); ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="wpc-desc" id="wpc-api-key-hint"></p>
                        </div>
                    </div>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </div>

            <!-- ── Tab: Privacy ─────────────────────────────────── -->
            <div class="wpc-tab-panel" data-panel="privacy">

                <div class="wpc-card">
                    <h3>Data Sharing</h3>
                    <div class="wpc-privacy-note">
                        <strong>Sent on every request:</strong> database structure (table names, column names, types),
                        your question in natural language, and the generated SQL query.<br>
                        <strong>Sent for analysis:</strong> query result rows are forwarded to the AI provider so it can
                        summarize the answer. Without protection enabled, this may include personal data
                        (emails, names, addresses, phone numbers).
                    </div>
                </div>

                <div class="wpc-card">
                    <h3>Protection Level</h3>
                    <div class="wpc-radio-group">
                        <label class="wpc-radio-opt">
                            <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anonymize_level]"
                                   value="off" <?php checked( $anon_level, 'off' ); ?>>
                            <div class="opt-body">
                                <div class="opt-title">Disabled</div>
                                <div class="opt-desc">No data masking. All column values including personal data are visible in results and sent to the AI provider for analysis.</div>
                            </div>
                        </label>
                        <label class="wpc-radio-opt">
                            <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anonymize_level]"
                                   value="results" <?php checked( $anon_level, 'results' ); ?>>
                            <div class="opt-body">
                                <div class="opt-title">Mask query results</div>
                                <div class="opt-desc">Protected column values are replaced with <code>[REDACTED]</code> in query results and in data sent to AI for analysis. The database schema (table and column names) is still fully visible to the AI.</div>
                            </div>
                        </label>
                        <label class="wpc-radio-opt">
                            <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anonymize_level]"
                                   value="full" <?php checked( $anon_level, 'full' ); ?>>
                            <div class="opt-body">
                                <div class="opt-title">Full protection</div>
                                <div class="opt-desc">Values are masked in results, and protected column names are completely removed from the schema sent to AI. The AI cannot reference or query these columns at all.</div>
                                <span class="wpc-opt-badge success">Recommended for production</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="wpc-card" id="wpc-anon-cols-card" style="<?php echo $anon_level === 'off' ? 'opacity:.45;pointer-events:none' : ''; ?>">
                    <h3>Protected Columns</h3>
                    <div class="wpc-field">
                        <label>Column names</label>
                        <div>
                            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anonymize_columns]"
                                      rows="8"><?php echo esc_textarea( $opts['anonymize_columns'] ?? self::default_anon_columns() ); ?></textarea>
                            <p class="wpc-desc">One column name per line. Case-insensitive exact match. Values in matching columns are replaced with <code>[REDACTED]</code>.</p>
                        </div>
                    </div>
                    <div class="wpc-preset-bar">
                        <span class="p-label">Quick presets:</span>
                        <button type="button" class="wpc-inline-btn" data-preset="gdpr">GDPR basics</button>
                        <button type="button" class="wpc-inline-btn" data-preset="woo">WooCommerce billing</button>
                        <button type="button" class="wpc-inline-btn" data-preset="users">WP user fields</button>
                    </div>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </div>

            <!-- ── Tab: Performance ─────────────────────────────── -->
            <div class="wpc-tab-panel" data-panel="performance">

                <?php
                $is_large  = $table_count > 60 || $db_size_mb > 500;
                $cache     = $schema_status;
                $exp_min   = $cache['cached'] ? (int) round( $cache['expires_in'] / 60 ) : 0;
                $exp_color = ! $cache['cached'] ? 'red' : ( $exp_min > 30 ? 'green' : ( $exp_min > 5 ? 'yellow' : 'red' ) );
                $tok_color = $cache['tokens'] > 4000 ? 'yellow' : '';
                ?>

                <!-- Compact DB info bar -->
                <div class="wpc-db-bar">
                    <div class="bar-item">
                        <span class="bar-val <?php echo $table_count > 60 ? 'yellow' : ''; ?>"><?php echo absint( $table_count ); ?></span>
                        <span class="bar-lbl">Tables</span>
                    </div>
                    <div class="bar-item">
                        <span class="bar-val"><?php echo number_format( $db_size_mb, 1 ); ?> MB</span>
                        <span class="bar-lbl">DB size</span>
                    </div>
                    <div class="bar-item">
                        <span class="bar-val <?php echo esc_attr( $exp_color ); ?>">
                            <?php echo $cache['cached'] ? absint( $exp_min ) . ' min' : 'Not cached'; ?>
                        </span>
                        <span class="bar-lbl">Cache TTL</span>
                    </div>
                    <div class="bar-item">
                        <span class="bar-val <?php echo esc_attr( $tok_color ); ?>">
                            <?php echo $cache['cached'] ? '~' . number_format( $cache['tokens'] ) : '—'; ?>
                        </span>
                        <span class="bar-lbl">Schema tokens</span>
                    </div>
                </div>

                <!-- Schema Cache -->
                <div class="wpc-card">
                    <h3>Schema Cache</h3>
                    <?php if ( $cache['tokens'] > 4000 ) : ?>
                    <div class="wpc-token-note">
                        Schema uses <strong>~<?php echo number_format( $cache['tokens'] ); ?> tokens</strong> per request.
                        Exclude unnecessary tables below to reduce cost and improve response quality.
                    </div>
                    <?php endif; ?>
                    <div class="wpc-field">
                        <label>Cache duration</label>
                        <div>
                            <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schema_cache_ttl]">
                                <option value="0"     <?php selected( ($opts['schema_cache_ttl'] ?? '3600'), '0'     ); ?>>Disabled</option>
                                <option value="600"   <?php selected( ($opts['schema_cache_ttl'] ?? '3600'), '600'   ); ?>>10 minutes</option>
                                <option value="3600"  <?php selected( ($opts['schema_cache_ttl'] ?? '3600'), '3600'  ); ?>>1 hour (recommended)</option>
                                <option value="21600" <?php selected( ($opts['schema_cache_ttl'] ?? '3600'), '21600' ); ?>>6 hours</option>
                                <option value="86400" <?php selected( ($opts['schema_cache_ttl'] ?? '3600'), '86400' ); ?>>24 hours</option>
                            </select>
                            <p class="wpc-desc">How long to keep the schema in cache. Longer = fewer rebuilds, but DB structure changes won't be detected until cache expires.</p>
                        </div>
                    </div>
                    <div class="wpc-field">
                        <label>Max tables in schema</label>
                        <div>
                            <input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_schema_tables]"
                                   value="<?php echo esc_attr( $opts['max_schema_tables'] ?? 80 ); ?>"
                                   min="5" max="500" class="small-text" />
                            <p class="wpc-desc">WordPress core tables are always prioritized. Remaining slots filled alphabetically.</p>
                        </div>
                    </div>
                    <hr class="wpc-section-divider">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button type="button" class="wpc-inline-btn" id="wpc-flush-schema">Flush cache</button>
                        <span style="font-size:12px;color:#757575;">Force a schema rebuild on next query.</span>
                    </div>
                </div>

                <!-- Table Exclusions -->
                <div class="wpc-card">
                    <h3>Table Exclusions
                        <?php if ( $is_large ) : ?>
                        <span class="wpc-badge warn">Large database</span>
                        <?php endif; ?>
                    </h3>

                    <?php if ( ! empty( $recommendations ) ) : ?>
                    <p style="font-size:12px;color:#555;margin:0 0 12px;">
                        <?php echo count( $recommendations ); ?> plugin table group<?php echo count( $recommendations ) > 1 ? 's' : ''; ?> detected that can be excluded to reduce schema size:
                    </p>
                    <div class="wpc-rec-list" id="wpc-rec-list">
                        <?php foreach ( $recommendations as $rec ) : ?>
                        <div class="wpc-rec-item" data-pattern="<?php echo esc_attr( $rec['pattern'] ); ?>">
                            <div class="wpc-rec-info">
                                <strong><?php echo esc_html( $rec['label'] ); ?></strong>
                                <span><?php echo esc_html( $rec['desc'] ); ?></span>
                            </div>
                            <code class="wpc-rec-pattern"><?php echo esc_html( $rec['pattern'] ); ?></code>
                            <span class="wpc-rec-count"><?php echo absint( $rec['count'] ); ?> table<?php echo $rec['count'] !== 1 ? 's' : ''; ?></span>
                            <button type="button" class="wpc-inline-btn wpc-add-rec">Exclude</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-bottom:16px;">
                        <button type="button" class="wpc-inline-btn" id="wpc-add-all-recs">Exclude all detected</button>
                    </div>
                    <hr class="wpc-section-divider">
                    <?php else : ?>
                    <p style="font-size:12px;color:#166534;margin:0 0 14px;">No unnecessary plugin tables detected — your schema is clean.</p>
                    <?php endif; ?>

                    <div class="wpc-field">
                        <label>Exclusion patterns</label>
                        <div>
                            <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[excluded_tables]"
                                      rows="6" id="wpc-excluded-tables"><?php echo esc_textarea( $opts['excluded_tables'] ?? '' ); ?></textarea>
                            <p class="wpc-desc">One pattern per line. Use <code>*</code> as wildcard (e.g. <code>wp_yoast_*</code>). Matched tables are completely hidden from AI.</p>
                        </div>
                    </div>
                </div>

                <!-- Query Limits -->
                <div class="wpc-card">
                    <h3>Query Limits</h3>
                    <div class="wpc-field">
                        <label>Execution timeout</label>
                        <div>
                            <input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[query_timeout]"
                                   value="<?php echo esc_attr( $opts['query_timeout'] ?? 15 ); ?>"
                                   min="0" max="120" class="small-text" /> seconds
                            <p class="wpc-desc">MySQL <code>MAX_EXECUTION_TIME</code> per query. Set to 0 for server default.</p>
                        </div>
                    </div>
                    <div class="wpc-field">
                        <label>Maximum result rows</label>
                        <div>
                            <input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_rows]"
                                   value="<?php echo esc_attr( $opts['max_rows'] ?? 100 ); ?>"
                                   min="1" max="5000" class="small-text" /> rows
                            <p class="wpc-desc">AI is instructed to add <code>LIMIT</code> to all queries. Hard safety cap: 1–5000.</p>
                        </div>
                    </div>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </div>

            <!-- ── Tab: Access ───────────────────────────────────── -->
            <div class="wpc-tab-panel" data-panel="access">

                <div class="wpc-card">
                    <h3>Allowed Roles</h3>
                    <p style="font-size:13px;color:#555;margin:0 0 16px;">Only users with these roles can access the Copilot chat widget in the admin.</p>
                    <div class="wpc-roles-grid">
                        <?php foreach ( $roles as $role_key => $role_data ) : ?>
                        <label class="wpc-role-item">
                            <input type="checkbox"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_roles][]"
                                   value="<?php echo esc_attr($role_key); ?>"
                                   <?php checked( in_array( $role_key, (array)($opts['allowed_roles'] ?? ['administrator']), true ) ); ?>>
                            <?php echo esc_html( $role_data['name'] ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </div>

            <!-- ── Tab: Advanced ─────────────────────────────────── -->
            <div class="wpc-tab-panel" data-panel="advanced">

                <div class="wpc-card">
                    <h3>Response</h3>
                    <div class="wpc-toggle-row">
                        <input type="checkbox" id="opt_streaming" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[streaming]" value="1"
                               <?php checked( ! empty( $opts['streaming'] ) ); ?>>
                        <div>
                            <label for="opt_streaming">⚡ Enable streaming</label>
                            <p class="wpc-desc">Real-time token-by-token response. Disable if your server doesn't support long-running requests.</p>
                        </div>
                    </div>
                    <div class="wpc-toggle-row">
                        <input type="checkbox" id="opt_show_sql" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_sql]" value="1"
                               <?php checked( ! empty( $opts['show_sql'] ) ); ?>>
                        <div>
                            <label for="opt_show_sql">🔍 Show generated SQL</label>
                            <p class="wpc-desc">Displays the SQL query generated by AI. Useful for transparency and debugging.</p>
                        </div>
                    </div>
                </div>

                <div class="wpc-card">
                    <h3>Input</h3>
                    <div class="wpc-toggle-row">
                        <input type="checkbox" id="opt_voice" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_voice]" value="1"
                               <?php checked( ! empty( $opts['enable_voice'] ) ); ?>>
                        <div>
                            <label for="opt_voice">🎙️ Enable voice input</label>
                            <p class="wpc-desc">Web Speech API — Chrome/Edge only. Allows dictating questions.</p>
                        </div>
                    </div>
                </div>

                <div class="wpc-card">
                    <h3>Logging & Debug</h3>
                    <div class="wpc-toggle-row">
                        <input type="checkbox" id="opt_log" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[log_queries]" value="1"
                               <?php checked( ! empty( $opts['log_queries'] ) ); ?>>
                        <div>
                            <label for="opt_log">📋 Log queries to database</label>
                            <p class="wpc-desc">Stores every query, generated SQL, token usage and status in the <code>wp_copilot_logs</code> table.</p>
                        </div>
                    </div>
                    <div class="wpc-toggle-row">
                        <input type="checkbox" id="opt_debug" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[debug_mode]" value="1"
                               <?php checked( ! empty( $opts['debug_mode'] ) ); ?>>
                        <div>
                            <label for="opt_debug">🐛 Debug mode</label>
                            <p class="wpc-desc">Verbose logging to PHP error log. Disable in production.</p>
                        </div>
                    </div>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </div>

        </form>

        <!-- Query Log -->
        <?php self::render_query_log( $opts ); ?>

        </div><!-- .wpc-admin-page -->

        <script>
        jQuery(function($){
            // Tabs
            $('.wpc-tab-btn').on('click', function(){
                var tab = $(this).data('tab');
                $('.wpc-tab-btn').removeClass('active');
                $('.wpc-tab-panel').removeClass('active');
                $(this).addClass('active');
                $('[data-panel="'+tab+'"]').addClass('active');
            });

            // Provider change → model group + API key hint
            var hints = {
                anthropic: 'Get your key at <a href="https://console.anthropic.com/keys" target="_blank">console.anthropic.com</a>',
                openai:    'Get your key at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>',
                google:    'Get your key at <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com</a>',
            };
            function updateProvider() {
                var prov = $('input[name="<?php echo esc_attr( self::OPTION_KEY ); ?>[provider]"]:checked').val();
                $('.wpc-model-group').hide();
                $('.wpc-model-group[data-provider="'+prov+'"]').show();
                $('#wpc-api-key-hint').html(hints[prov] || '');
            }
            $('input[name="<?php echo esc_attr( self::OPTION_KEY ); ?>[provider]"]').on('change', updateProvider);
            updateProvider();

            // Anonymizer level toggle
            $('input[name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anonymize_level]"]').on('change', function(){
                var off = this.value === 'off';
                $('#wpc-anon-cols-card').css({ opacity: off ? .45 : 1, pointerEvents: off ? 'none' : '' });
            });

            // Column presets
            var wpcPresets = {
                gdpr:  'email\nfirst_name\nlast_name\nphone\naddress_1\naddress_2\ncity\npostcode\nstate\ncountry\nip_address',
                woo:   'billing_first_name\nbilling_last_name\nbilling_email\nbilling_phone\nbilling_address_1\nbilling_address_2\nbilling_city\nbilling_postcode\nbilling_state\nbilling_country\nshipping_first_name\nshipping_last_name\nshipping_address_1\nshipping_address_2\nshipping_city\nshipping_postcode',
                users: 'user_email\nuser_pass\nuser_login\ndisplay_name\nuser_nicename\nuser_registered'
            };
            $('[data-preset]').on('click', function(){
                var cols = wpcPresets[$(this).data('preset')] || '';
                var $ta  = $('textarea[name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anonymize_columns]"]');
                var cur  = $ta.val().trim();
                var existing = cur.split('\n').map(function(s){ return s.trim().toLowerCase(); });
                var add = cols.split('\n').filter(function(c){ return c && existing.indexOf(c.toLowerCase()) === -1; });
                if (add.length) $ta.val( (cur ? cur + '\n' : '') + add.join('\n') );
            });

            // Flush schema cache
            $('#wpc-flush-schema').on('click', function(){
                var $btn = $(this);
                $btn.text('Flushing…').prop('disabled', true);
                $.post(ajaxurl, { action: 'wpc_flush_schema', nonce: '<?php echo esc_attr( $admin_nonce ); ?>' }, function(){
                    $btn.addClass('success').text('✓ Done').prop('disabled', false);
                    setTimeout(function(){ $btn.removeClass('success').text('Flush cache'); }, 2500);
                });
            });

            // Add recommendation to exclusions
            function addPatternToExclusions(pattern) {
                var $ta = $('#wpc-excluded-tables');
                var cur = $ta.val().trim();
                var lines = cur.split('\n').map(function(s){ return s.trim(); });
                if (lines.indexOf(pattern) === -1) {
                    $ta.val( (cur ? cur + '\n' : '') + pattern );
                }
            }
            $('.wpc-add-rec').on('click', function(){
                var $item = $(this).closest('.wpc-rec-item');
                addPatternToExclusions($item.data('pattern'));
                $item.css({ opacity: .4, pointerEvents: 'none' }).find('button').text('✓ Added');
            });
            $('#wpc-add-all-recs').on('click', function(){
                $('#wpc-rec-list .wpc-rec-item').each(function(){
                    addPatternToExclusions($(this).data('pattern'));
                    $(this).css({ opacity: .4, pointerEvents: 'none' }).find('button').text('✓ Added');
                });
                $(this).text('✓ All excluded').prop('disabled', true);
            });
        });
        </script>
        <?php
    }

    private static function render_query_log( array $opts ): void {
        if ( empty( $opts['log_queries'] ) ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'copilot_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check for log display; not cacheable.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $logs = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT l.*, u.display_name FROM %i l LEFT JOIN %i u ON u.ID = l.user_id ORDER BY l.executed_at DESC LIMIT 50',
                $table,
                $wpdb->users
            )
        );
        if ( empty( $logs ) ) return;
        ?>
        <div class="wpc-card" style="margin-top:24px;">
            <h3 style="margin:0 0 16px;">📋 Query Log <span style="font-size:12px;color:#999;font-weight:400">(last 50)</span></h3>
            <table class="widefat striped" style="font-size:12px;">
                <thead>
                    <tr><th>#</th><th>User</th><th>Question</th><th>SQL</th><th>Provider</th>
                    <th>Tokens</th><th>Rows</th><th>ms</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html($log->id); ?></td>
                        <td><?php echo esc_html($log->display_name ?? '?'); ?></td>
                        <td style="max-width:180px;word-break:break-word;"><?php echo esc_html($log->user_query); ?></td>
                        <td><code style="font-size:10px;word-break:break-all;"><?php echo esc_html( mb_strimwidth($log->generated_sql, 0, 90, '…') ); ?></code></td>
                        <td><?php echo esc_html($log->provider); ?></td>
                        <td><?php echo esc_html($log->in_tokens + $log->out_tokens); ?></td>
                        <td><?php echo esc_html($log->row_count); ?></td>
                        <td><?php echo esc_html($log->exec_ms); ?></td>
                        <td style="color:<?php echo $log->status==='success'?'#0a8a3c':'#c0392b'; ?>;font-weight:600;"><?php echo esc_html($log->status); ?></td>
                        <td style="white-space:nowrap;color:#777;"><?php echo esc_html($log->executed_at); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
