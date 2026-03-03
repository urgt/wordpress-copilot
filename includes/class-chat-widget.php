<?php
defined( 'ABSPATH' ) || exit;

class WPC_Chat_Widget {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ __CLASS__, 'render_widget' ] );

		// Standard AJAX (non-streaming)
		add_action( 'wp_ajax_wpc_query', [ __CLASS__, 'handle_ajax' ] );

		// Streaming endpoint — also via AJAX but sends SSE headers
		add_action( 'wp_ajax_wpc_stream', [ __CLASS__, 'handle_stream' ] );
	}

	/* ── Permission ─────────────────────────────────────────────── */

	public static function current_user_allowed(): bool {
		$allowed = (array) WPC_Settings::get( 'allowed_roles', [ 'administrator' ] );
		$user    = wp_get_current_user();
		if ( ! $user->ID ) {
			return false;
		}
		foreach ( $allowed as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ── Assets ─────────────────────────────────────────────────── */

	public static function enqueue_assets(): void {
		if ( ! self::current_user_allowed() ) {
			return;
		}

		wp_enqueue_style(
			'wpc-chat',
			WPC_URL . 'assets/css/chat.css',
			[],
			WPC_VERSION
		);

		wp_enqueue_script(
			'wpc-chat',
			WPC_URL . 'assets/js/chat.js',
			[ 'jquery' ],
			WPC_VERSION,
			true
		);

		$provider_key   = WPC_Settings::get( 'provider', 'anthropic' );
		$provider_label = WPC_Engine_Factory::get_provider_label();
		$providers      = WPC_Settings::get_providers();
		$model_options  = $providers[ $provider_key ]['models'] ?? [];
		$model          = WPC_Settings::get( 'model', '' );
		if ( empty( $model ) ) {
			$model = $providers[ $provider_key ]['default_model'] ?? '';
		}

		// Nonce refresh — same as AI Engine (send new nonce if current has changed)
		$nonce = wp_create_nonce( 'wpc_nonce' );

		wp_localize_script(
			'wpc-chat',
			'wpCopilot',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => $nonce,
				'streaming'     => (bool) WPC_Settings::get( 'streaming', true ),
				'enableVoice'   => (bool) WPC_Settings::get( 'enable_voice' ),
				'showSql'       => (bool) WPC_Settings::get( 'show_sql' ),
				'provider'      => $provider_label,
				'providerKey'   => $provider_key,
				'providerLabel' => $provider_label,
				'modelOptions'  => $model_options,
				'model'         => $model,
				'settingsUrl'   => admin_url( 'options-general.php?page=wordpress-copilot' ),
				'hasApiKey'     => ! empty( WPC_Settings::get( 'api_key' ) ),
				'i18n'          => [
					'placeholder'       => __( 'Ask anything about your data…', 'wordpress-copilot' ),
					'thinking'          => __( 'Generating SQL query…', 'wordpress-copilot' ),
					'executing'         => __( 'Running query…', 'wordpress-copilot' ),
					'error'             => __( 'Something went wrong. Please try again.', 'wordpress-copilot' ),
					'modelLabel'        => __( 'Model', 'wordpress-copilot' ),
					'retry'             => __( 'Try again', 'wordpress-copilot' ),
					'newChat'           => __( 'New chat', 'wordpress-copilot' ),
					'chats'             => __( 'Chats', 'wordpress-copilot' ),
					'deleteChat'        => __( 'Delete chat', 'wordpress-copilot' ),
					'confirmDeleteChat' => __( 'Delete this chat?', 'wordpress-copilot' ),
					'emptyChat'         => __( 'Start a new conversation to keep context.', 'wordpress-copilot' ),
					'voiceStart'        => __( 'Listening… speak now', 'wordpress-copilot' ),
					'noVoice'           => __( 'Voice input is not supported in this browser.', 'wordpress-copilot' ),
					'noApiKey'          => __( 'API key not configured', 'wordpress-copilot' ),
					'noApiKeyMsg'       => __( 'To use WordPress Copilot, add your AI provider API key in plugin settings.', 'wordpress-copilot' ),
					'goToSettings'      => __( 'Open Settings', 'wordpress-copilot' ),
					'examples'          => [
						__( '🛒 Top 10 best-selling products this month', 'wordpress-copilot' ),
						__( '📦 Products with stock below 5 units', 'wordpress-copilot' ),
						__( '👥 New users registered this week', 'wordpress-copilot' ),
						__( '💰 Total revenue last 30 days', 'wordpress-copilot' ),
						__( '🔁 Orders with status "on-hold"', 'wordpress-copilot' ),
						__( '🏷️ Products without a featured image', 'wordpress-copilot' ),
						__( '📊 Orders per day for the last 7 days', 'wordpress-copilot' ),
						__( '👤 Customers who ordered more than 3 times', 'wordpress-copilot' ),
					],
				],
			]
		);
	}

	/* ── Widget HTML ────────────────────────────────────────────── */

	public static function render_widget(): void {
		if ( ! self::current_user_allowed() ) {
			return;
		}
		?>
		<div id="wpc-widget">
			<button id="wpc-trigger" title="WordPress Copilot" aria-label="WordPress Copilot" aria-expanded="false">
				<span class="wpc-siri-orb" aria-hidden="true">
					<span class="wpc-siri-blob wpc-siri-blob-1"></span>
					<span class="wpc-siri-blob wpc-siri-blob-2"></span>
					<span class="wpc-siri-blob wpc-siri-blob-3"></span>
				</span>
				<svg class="wpc-siri-icon" width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
					<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
				</svg>
			</button>

			<div id="wpc-panel">
				<div class="wpc-layout">
					<aside class="wpc-sidebar">
						<div class="wpc-sidebar-head">
							<span class="wpc-sidebar-title"><?php esc_html_e( 'Chats', 'wordpress-copilot' ); ?></span>
							<button id="wpc-new-chat" type="button" class="wpc-new-chat-btn"><?php esc_html_e( '+ New', 'wordpress-copilot' ); ?></button>
						</div>
						<div id="wpc-chat-list"></div>
					</aside>

					<div class="wpc-main">
						<div class="wpc-header">
							<div class="wpc-header-left">
								<svg class="wpc-header-icon" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
								<span class="wpc-header-chat-title" id="wpc-header-title"><?php esc_html_e( 'New chat', 'wordpress-copilot' ); ?></span>
							</div>
							<div class="wpc-header-right">
								<span class="wpc-provider-badge" id="wpc-provider-badge"></span>
								<button class="wpc-icon-btn" id="wpc-fullscreen" title="<?php echo esc_attr( __( 'Fullscreen', 'wordpress-copilot' ) ); ?>">
									<svg class="wpc-fs-expand" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
									<svg class="wpc-fs-collapse" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
								</button>
								<button class="wpc-icon-btn" id="wpc-clear" title="<?php echo esc_attr( __( 'Clear chat', 'wordpress-copilot' ) ); ?>">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
								</button>
								<button class="wpc-icon-btn" id="wpc-close" title="<?php echo esc_attr( __( 'Close', 'wordpress-copilot' ) ); ?>">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
								</button>
							</div>
						</div>

						<div id="wpc-messages">
							<div class="wpc-welcome">
								<p><?php esc_html_e( 'Ask anything about your WordPress data in plain language.', 'wordpress-copilot' ); ?></p>
								<div id="wpc-examples"></div>
							</div>
						</div>

						<div class="wpc-input-wrap">
							<textarea id="wpc-input" rows="2" placeholder="<?php echo esc_attr( __( 'Ask anything about your data…', 'wordpress-copilot' ) ); ?>"></textarea>
							<div class="wpc-input-actions">
								<div class="wpc-model-wrap">
									<div class="wpc-model-picker" id="wpc-model-picker">
										<button type="button" class="wpc-model-btn" id="wpc-model-btn">
											<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" class="wpc-model-spark"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
											<span class="wpc-model-btn-label" id="wpc-model-label"><?php esc_html_e( 'Model', 'wordpress-copilot' ); ?></span>
											<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="wpc-model-chevron"><polyline points="6 9 12 15 18 9"/></svg>
										</button>
										<ul class="wpc-model-dropdown" id="wpc-model-dropdown"></ul>
									</div>
								</div>
								<div class="wpc-send-group">
									<button id="wpc-voice" class="wpc-icon-btn wpc-voice-btn" title="<?php echo esc_attr( __( 'Voice input', 'wordpress-copilot' ) ); ?>" style="display:none">
										<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
									</button>
									<button id="wpc-send" class="wpc-send-btn">
										<?php esc_html_e( 'Send', 'wordpress-copilot' ); ?>
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
									</button>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div id="wpc-onboarding" style="display:none" role="dialog" aria-modal="true">
					<div class="wpc-ob-card">
						<div class="wpc-ob-dots">
							<span class="wpc-ob-dot active" data-slide="0"></span>
							<span class="wpc-ob-dot" data-slide="1"></span>
							<span class="wpc-ob-dot" data-slide="2"></span>
							<span class="wpc-ob-dot" data-slide="3"></span>
							<span class="wpc-ob-dot" data-slide="4"></span>
						</div>

						<div class="wpc-ob-slide active" data-slide="0">
							<div class="wpc-ob-emoji">✨</div>
							<h2><?php esc_html_e( 'Meet WordPress Copilot', 'wordpress-copilot' ); ?></h2>
							<p><?php esc_html_e( 'Ask questions about your WordPress data in your native language — no SQL knowledge needed.', 'wordpress-copilot' ); ?></p>
							<p class="wpc-ob-sub"><?php esc_html_e( 'Powered by AI. Runs only on your WordPress admin.', 'wordpress-copilot' ); ?></p>
						</div>

						<div class="wpc-ob-slide" data-slide="1">
							<div class="wpc-ob-emoji">🔑</div>
							<h2><?php esc_html_e( 'Add your API key', 'wordpress-copilot' ); ?></h2>
							<p><?php esc_html_e( 'WordPress Copilot uses an AI provider to generate queries. An API key is required — without it, nothing will work.', 'wordpress-copilot' ); ?></p>
							<div class="wpc-ob-steps">
								<div class="wpc-ob-api-step">
									<strong>1.</strong> <?php esc_html_e( 'Choose a provider: Anthropic, OpenAI, or Google', 'wordpress-copilot' ); ?>
								</div>
								<div class="wpc-ob-api-step">
									<strong>2.</strong> <?php esc_html_e( 'Get an API key from your provider\'s dashboard', 'wordpress-copilot' ); ?>
								</div>
								<div class="wpc-ob-api-step">
									<strong>3.</strong> <?php esc_html_e( 'Paste it in plugin settings', 'wordpress-copilot' ); ?>
								</div>
							</div>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wordpress-copilot' ) ); ?>" class="wpc-ob-settings-btn"><?php esc_html_e( 'Open Settings', 'wordpress-copilot' ); ?> →</a>
						</div>

						<div class="wpc-ob-slide" data-slide="2">
							<div class="wpc-ob-emoji">⚙️</div>
							<h2><?php esc_html_e( 'How it works', 'wordpress-copilot' ); ?></h2>
							<ol class="wpc-ob-steps">
								<li><strong><?php esc_html_e( 'You ask', 'wordpress-copilot' ); ?></strong> <?php esc_html_e( 'a question in plain language', 'wordpress-copilot' ); ?></li>
								<li><strong><?php esc_html_e( 'AI generates', 'wordpress-copilot' ); ?></strong> <?php esc_html_e( 'a safe SQL SELECT query', 'wordpress-copilot' ); ?></li>
								<li><strong><?php esc_html_e( 'WordPress runs', 'wordpress-copilot' ); ?></strong> <?php esc_html_e( 'the query on your database', 'wordpress-copilot' ); ?></li>
								<li><strong><?php esc_html_e( 'Results shown', 'wordpress-copilot' ); ?></strong> <?php esc_html_e( 'as a table or chart', 'wordpress-copilot' ); ?></li>
							</ol>
						</div>

						<div class="wpc-ob-slide" data-slide="3">
							<div class="wpc-ob-emoji">🔒</div>
							<h2><?php esc_html_e( 'Is it safe?', 'wordpress-copilot' ); ?></h2>
							<ul class="wpc-ob-safety">
								<li>✅ <strong><?php esc_html_e( 'Read-only', 'wordpress-copilot' ); ?></strong> — <?php esc_html_e( 'only SELECT queries run', 'wordpress-copilot' ); ?></li>
								<li>✅ <strong><?php esc_html_e( 'Admin only', 'wordpress-copilot' ); ?></strong> — <?php esc_html_e( 'requires WordPress admin access', 'wordpress-copilot' ); ?></li>
								<li>✅ <strong><?php esc_html_e( 'Your data stays private', 'wordpress-copilot' ); ?></strong> — <?php esc_html_e( 'only schema structure is sent to AI, not actual data values', 'wordpress-copilot' ); ?></li>
								<li>✅ <strong><?php esc_html_e( 'Validated', 'wordpress-copilot' ); ?></strong> — <?php esc_html_e( 'all queries are checked before execution', 'wordpress-copilot' ); ?></li>
							</ul>
						</div>

						<div class="wpc-ob-slide" data-slide="4">
							<div class="wpc-ob-emoji">💡</div>
							<h2><?php esc_html_e( 'Try these examples', 'wordpress-copilot' ); ?></h2>
							<div class="wpc-ob-examples">
								<button class="wpc-ob-example" data-query="Show me the 10 most recent posts with their authors">📝 <?php esc_html_e( 'Recent posts with authors', 'wordpress-copilot' ); ?></button>
								<button class="wpc-ob-example" data-query="How many users registered each month this year?">👥 <?php esc_html_e( 'User registrations by month', 'wordpress-copilot' ); ?></button>
								<button class="wpc-ob-example" data-query="What are the top 5 most commented posts?">💬 <?php esc_html_e( 'Top commented posts', 'wordpress-copilot' ); ?></button>
								<button class="wpc-ob-example" data-query="Show me all products with stock less than 5">📦 <?php esc_html_e( 'Low stock products', 'wordpress-copilot' ); ?></button>
								<button class="wpc-ob-example" data-query="Count posts by category">📂 <?php esc_html_e( 'Posts by category', 'wordpress-copilot' ); ?></button>
								<button class="wpc-ob-example" data-query="Show me orders placed today">🛒 <?php esc_html_e( "Today's orders", 'wordpress-copilot' ); ?></button>
							</div>
						</div>

						<div class="wpc-ob-nav">
							<button class="wpc-ob-skip" id="wpc-ob-skip"><?php esc_html_e( 'Skip', 'wordpress-copilot' ); ?></button>
							<div class="wpc-ob-nav-right">
								<button class="wpc-ob-prev" id="wpc-ob-prev" style="display:none">← <?php esc_html_e( 'Back', 'wordpress-copilot' ); ?></button>
								<button class="wpc-ob-next" id="wpc-ob-next"><?php esc_html_e( 'Next', 'wordpress-copilot' ); ?> →</button>
								<button class="wpc-ob-finish" id="wpc-ob-finish" style="display:none"><?php esc_html_e( "Let's go!", 'wordpress-copilot' ); ?> 🚀</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ── AJAX: standard (non-streaming) ─────────────────────────── */

	public static function handle_ajax(): void {
		check_ajax_referer( 'wpc_nonce', 'nonce' );

		if ( ! self::current_user_allowed() ) {
			self::clean_output_buffer();
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'wordpress-copilot' ) ], 403 );
		}

		$user_query     = sanitize_textarea_field( wp_unslash( $_POST['query'] ?? '' ) );
		$chat_context   = self::sanitize_context( wp_unslash( $_POST['context'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selected_model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );

		// Engine
		$engine = WPC_Engine_Factory::make( $selected_model );
		if ( is_wp_error( $engine ) ) {
			self::clean_output_buffer();
			wp_send_json_error(
				[
					'message' => $engine->get_error_message(),
					'code'    => $engine->get_error_code(),
				]
			);
		}

		// Schema
		$schema = WPC_DB_Schema::get_schema_prompt();

		// AI → SQL
		$ai_result = self::generate_sql_with_retry(
			$engine,
			self::build_sql_request_prompt( $user_query, $chat_context ),
			$schema
		);

		if ( is_wp_error( $ai_result ) ) {
			self::clean_output_buffer();
			wp_send_json_error( [ 'message' => $ai_result->get_error_message() ] );
		}

		$sql         = $ai_result['sql'];
		$explanation = $ai_result['explanation'] ?? '';

		// Execute
		$rows = WPC_Query_Executor::execute( $sql );

		if ( is_wp_error( $rows ) ) {
			self::clean_output_buffer();
			wp_send_json_error(
				[
					'message' => $rows->get_error_message(),
					'sql'     => WPC_Settings::get( 'show_sql' ) ? $sql : null,
				]
			);
		}

		$explanation = self::build_answer_with_data( $engine, $user_query, $sql, $rows, $explanation );
		$formatted   = WPC_Query_Executor::format_results( $rows, $explanation );
		$exec_ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Log
		self::log_query( $user_query, $sql, $engine, $formatted['count'], $exec_ms, 'success' );

		// Nonce refresh (AI Engine pattern)
		$response = [
			'html'        => $formatted['html'],
			'summary'     => $formatted['summary'],
			'count'       => $formatted['count'],
			'explanation' => $explanation,
			'exec_ms'     => $exec_ms,
			'sql'         => WPC_Settings::get( 'show_sql' ) ? $sql : null,
			'tokens'      => [
				'in'  => $engine->get_in_tokens(),
				'out' => $engine->get_out_tokens(),
			],
		];

		// Refresh nonce if needed
		$new_nonce    = wp_create_nonce( 'wpc_nonce' );
		$client_nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) );
		if ( $new_nonce !== $client_nonce ) {
			$response['new_nonce'] = $new_nonce;
		}

		self::clean_output_buffer();
		wp_send_json_success( $response );
	}

	/* ── AJAX: streaming (SSE) ──────────────────────────────────── */

	public static function handle_stream(): void {
		// Manual nonce check for SSE (can't use check_ajax_referer with die())
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'wpc_nonce' ) ) {
			http_response_code( 403 );
			echo 'data: ' . wp_json_encode(
				[
					'type' => 'error',
					'data' => __( 'Security check failed.', 'wordpress-copilot' ),
				]
			) . "\n\n";
			die();
		}

		if ( ! self::current_user_allowed() ) {
			http_response_code( 403 );
			echo 'data: ' . wp_json_encode(
				[
					'type' => 'error',
					'data' => __( 'Access denied.', 'wordpress-copilot' ),
				]
			) . "\n\n";
			die();
		}

		$user_query     = sanitize_textarea_field( wp_unslash( $_POST['query'] ?? '' ) );
		$chat_context   = self::sanitize_context( wp_unslash( $_POST['context'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selected_model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
		if ( empty( $user_query ) ) {
			echo 'data: ' . wp_json_encode(
				[
					'type' => 'error',
					'data' => __( 'Please enter a question.', 'wordpress-copilot' ),
				]
			) . "\n\n";
			die();
		}

		// SSE headers
		self::clean_output_buffer();
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );

		$start = microtime( true );

		try {
			// Engine
			$engine = WPC_Engine_Factory::make( $selected_model );
			if ( is_wp_error( $engine ) ) {
				self::sse_push( 'error', $engine->get_error_message(), [ 'code' => $engine->get_error_code() ] );
				die();
			}

			// Signal that we're thinking
			self::sse_push( 'status', __( 'Generating SQL query…', 'wordpress-copilot' ) );

			// Schema
			$schema = WPC_DB_Schema::get_schema_prompt();

			// Buffer for streaming tokens
			$token_buffer = '';

			// AI → SQL (streaming)
			$ai_result = self::generate_sql_with_retry(
				$engine,
				self::build_sql_request_prompt( $user_query, $chat_context ),
				$schema,
				function ( string $token ) use ( &$token_buffer ) {
					$token_buffer .= $token;
					// Push each token to client
					self::sse_push( 'token', $token );
				}
			);

			if ( is_wp_error( $ai_result ) ) {
				self::sse_push( 'error', $ai_result->get_error_message() );
				die();
			}

			$sql         = $ai_result['sql'];
			$explanation = $ai_result['explanation'] ?? '';

			// Signal we're executing SQL
			self::sse_push( 'status', __( 'Running query…', 'wordpress-copilot' ) );

			// Execute
			$rows = WPC_Query_Executor::execute( $sql );

			if ( is_wp_error( $rows ) ) {
				self::sse_push(
					'error',
					$rows->get_error_message(),
					[
						'sql' => WPC_Settings::get( 'show_sql' ) ? $sql : null,
					]
				);
				die();
			}

			self::sse_push( 'status', __( 'Analyzing results…', 'wordpress-copilot' ) );
			$explanation = self::build_answer_with_data( $engine, $user_query, $sql, $rows, $explanation );
			$formatted   = WPC_Query_Executor::format_results( $rows, $explanation );
			$exec_ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

			// Log
			self::log_query( $user_query, $sql, $engine, $formatted['count'], $exec_ms, 'success' );

			// Final result
			self::sse_push(
				'end',
				wp_json_encode(
					[
						'html'        => $formatted['html'],
						'summary'     => $formatted['summary'],
						'count'       => $formatted['count'],
						'explanation' => $explanation,
						'exec_ms'     => $exec_ms,
						'sql'         => WPC_Settings::get( 'show_sql' ) ? $sql : null,
						'tokens'      => [
							'in'  => $engine->get_in_tokens(),
							'out' => $engine->get_out_tokens(),
						],
						'new_nonce'   => wp_create_nonce( 'wpc_nonce' ),
					]
				)
			);

		} catch ( \Throwable $e ) {
			WPC_Logger::error( 'Stream handler exception: ' . $e->getMessage() );
			self::sse_push( 'error', __( 'An unexpected error occurred. Check PHP error log.', 'wordpress-copilot' ) );
		}

		die();
	}

	/* ── SSE helper (AI Engine stream_push pattern) ─────────────── */

	private static function clean_output_buffer(): void {
		if ( ob_get_level() > 0 ) {
			ob_clean();
		}
	}

	private static function sse_push( string $type, string $data, array $extra = [] ): void {
		$payload = array_merge(
			[
				'type' => $type,
				'data' => $data,
			],
			$extra
		);
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
		if ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();
	}

	private static function sanitize_context( $raw_context ): string {
		if ( ! is_scalar( $raw_context ) ) {
			return '';
		}
		$context = sanitize_textarea_field( (string) $raw_context );
		return mb_strimwidth( $context, 0, 4000, '' );
	}

	private static function build_sql_request_prompt( string $query, string $context ): string {
		if ( '' === $context ) {
			return $query;
		}

		return "Conversation context:\n{$context}\n\nCurrent user question:\n{$query}\n\nUse the context only when it is relevant to the current question.";
	}

	private static function generate_sql_with_retry(
		WPC_Engine_Core $engine,
		string $user_query,
		string $schema,
		?callable $on_chunk = null
	) {
		$ai_result = $engine->generate_sql( $user_query, $schema, $on_chunk );
		if ( is_wp_error( $ai_result ) && $ai_result->get_error_code() === 'parse_error' ) {
			WPC_Logger::warn( 'Retrying SQL generation after parse error.' );
			return $engine->generate_sql( $user_query, $schema, null );
		}
		return $ai_result;
	}

	private static function build_answer_with_data(
		WPC_Engine_Core $engine,
		string $user_query,
		string $sql,
		array $rows,
		string $fallback
	): string {
		if ( empty( $rows ) ) {
			return $fallback;
		}

		$rows_json = wp_json_encode( self::trim_rows_for_ai( $rows ) );
		if ( empty( $rows_json ) ) {
			return $fallback;
		}

		$system_prompt = 'You are a WordPress data analyst. Use only the provided query result rows, do not invent facts, and answer in the same language as the user. Keep response concise and useful.';
		$user_prompt   = "User question:\n{$user_query}\n\nSQL used:\n{$sql}\n\nRows JSON:\n{$rows_json}\n\nWrite a direct answer with key insights.";

		$answer = $engine->complete_text( $system_prompt, $user_prompt );
		if ( is_wp_error( $answer ) ) {
			WPC_Logger::warn( 'Post-processing failed: ' . $answer->get_error_message() );
			return $fallback;
		}

		$answer = trim( (string) $answer );
		return '' !== $answer ? $answer : $fallback;
	}

	private static function trim_rows_for_ai( array $rows ): array {
		$rows    = array_slice( $rows, 0, 30 );
		$trimmed = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean_row = [];
			foreach ( $row as $key => $value ) {
				if ( ! is_scalar( $value ) && null !== $value ) {
					continue;
				}
				$cell = (string) ( $value ?? '' );

				// JSON blob — extract meaningful keys instead of truncating blindly
				if ( str_starts_with( ltrim( $cell ), '{' ) || str_starts_with( ltrim( $cell ), '[' ) ) {
					$decoded = json_decode( $cell, true );
					if ( is_array( $decoded ) ) {
						// For update_plugins: extract plugin slugs from 'response' key
						if ( isset( $decoded['response'] ) && is_array( $decoded['response'] ) ) {
							$plugins = [];
							foreach ( $decoded['response'] as $slug => $info ) {
								$plugins[] = [
									'plugin'      => $slug,
									'new_version' => $info['new_version'] ?? null,
									'name'        => $info['plugin'] ?? $info['slug'] ?? $slug,
								];
							}
							$clean_row[ (string) $key ] = wp_json_encode( $plugins );
							continue;
						}
						// General: pass top-level keys with truncated scalar values
						$flat = [];
						foreach ( $decoded as $k => $v ) {
							if ( is_scalar( $v ) ) {
								$flat[ $k ] = mb_strimwidth( (string) $v, 0, 100 );
							} elseif ( is_array( $v ) ) {
								$flat[ $k ] = '[' . count( $v ) . ' items]';
							}
						}
						$clean_row[ (string) $key ] = wp_json_encode( $flat );
						continue;
					}
				}

				$clean_row[ (string) $key ] = mb_strimwidth( $cell, 0, 280, '…' );
			}
			if ( ! empty( $clean_row ) ) {
				$trimmed[] = $clean_row;
			}
		}

		return $trimmed;
	}

	/* ── Query logger ───────────────────────────────────────────── */

	private static function log_query(
		string $user_query,
		string $sql,
		WPC_Engine_Core $engine,
		int $row_count,
		int $exec_ms,
		string $status,
		string $error_msg = ''
	): void {
		if ( ! WPC_Settings::get( 'log_queries' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'copilot_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES existence check before INSERT; caching not appropriate.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT write operation; not cacheable.
		$wpdb->insert(
			$table,
			[
				'user_id'       => get_current_user_id(),
				'user_query'    => $user_query,
				'generated_sql' => $sql,
				'provider'      => WPC_Settings::get( 'provider' ),
				'model'         => $engine->get_model(),
				'in_tokens'     => $engine->get_in_tokens(),
				'out_tokens'    => $engine->get_out_tokens(),
				'row_count'     => $row_count,
				'exec_ms'       => $exec_ms,
				'status'        => $status,
				'error_msg'     => $error_msg,
				'executed_at'   => current_time( 'mysql' ),
			]
		);
	}
}
