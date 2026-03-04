<?php
defined( 'ABSPATH' ) || exit;

class DQA_Chat_Widget {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ __CLASS__, 'render_widget' ] );

		// Standard AJAX (non-streaming)
		add_action( 'wp_ajax_dqa_query', [ __CLASS__, 'handle_ajax' ] );

		// Streaming endpoint — also via AJAX but sends SSE headers
		add_action( 'wp_ajax_dqa_stream', [ __CLASS__, 'handle_stream' ] );
	}

	/* ── Permission ─────────────────────────────────────────────── */

	public static function current_user_allowed(): bool {
		$allowed = (array) DQA_Settings::get( 'allowed_roles', [ 'administrator' ] );
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
			'dqa-chat',
			DQA_URL . 'assets/css/chat.css',
			[],
			DQA_VERSION
		);

		wp_enqueue_script(
			'dqa-chat',
			DQA_URL . 'assets/js/chat.js',
			[ 'jquery' ],
			DQA_VERSION,
			true
		);

		$provider_key   = DQA_Settings::get( 'provider', 'anthropic' );
		$provider_label = DQA_Engine_Factory::get_provider_label();
		$providers      = DQA_Settings::get_providers();
		$model_options  = $providers[ $provider_key ]['models'] ?? [];
		$model          = DQA_Settings::get( 'model', '' );
		if ( empty( $model ) ) {
			$model = $providers[ $provider_key ]['default_model'] ?? '';
		}

		// Nonce refresh — same as AI Engine (send new nonce if current has changed)
		$nonce = wp_create_nonce( 'dqa_nonce' );

		wp_localize_script(
			'dqa-chat',
			'dqaAssistant',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => $nonce,
				'streaming'     => (bool) DQA_Settings::get( 'streaming', true ),
				'enableVoice'   => (bool) DQA_Settings::get( 'enable_voice' ),
				'showSql'       => (bool) DQA_Settings::get( 'show_sql' ),
				'provider'      => $provider_label,
				'providerKey'   => $provider_key,
				'providerLabel' => $provider_label,
				'modelOptions'  => $model_options,
				'model'         => $model,
				'settingsUrl'   => admin_url( 'options-general.php?page=data-query-assistant' ),
				'hasApiKey'     => ! empty( DQA_Settings::get( 'api_key' ) ),
				'i18n'          => [
					'placeholder'       => __( 'Ask anything about your data…', 'data-query-assistant' ),
					'thinking'          => __( 'Generating SQL query…', 'data-query-assistant' ),
					'executing'         => __( 'Running query…', 'data-query-assistant' ),
					'error'             => __( 'Something went wrong. Please try again.', 'data-query-assistant' ),
					'modelLabel'        => __( 'Model', 'data-query-assistant' ),
					'retry'             => __( 'Try again', 'data-query-assistant' ),
					'newChat'           => __( 'New chat', 'data-query-assistant' ),
					'chats'             => __( 'Chats', 'data-query-assistant' ),
					'deleteChat'        => __( 'Delete chat', 'data-query-assistant' ),
					'confirmDeleteChat' => __( 'Delete this chat?', 'data-query-assistant' ),
					'emptyChat'         => __( 'Start a new conversation to keep context.', 'data-query-assistant' ),
					'voiceStart'        => __( 'Listening… speak now', 'data-query-assistant' ),
					'noVoice'           => __( 'Voice input is not supported in this browser.', 'data-query-assistant' ),
					'noApiKey'          => __( 'API key not configured', 'data-query-assistant' ),
					'noApiKeyMsg'       => __( 'To use Data Query Assistant, add your AI provider API key in plugin settings.', 'data-query-assistant' ),
					'goToSettings'      => __( 'Open Settings', 'data-query-assistant' ),
					'examples'          => [
						__( '🛒 Top 10 best-selling products this month', 'data-query-assistant' ),
						__( '📦 Products with stock below 5 units', 'data-query-assistant' ),
						__( '👥 New users registered this week', 'data-query-assistant' ),
						__( '💰 Total revenue last 30 days', 'data-query-assistant' ),
						__( '🔁 Orders with status "on-hold"', 'data-query-assistant' ),
						__( '🏷️ Products without a featured image', 'data-query-assistant' ),
						__( '📊 Orders per day for the last 7 days', 'data-query-assistant' ),
						__( '👤 Customers who ordered more than 3 times', 'data-query-assistant' ),
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
		<div id="dqa-widget">
			<button id="dqa-trigger" title="Data Query Assistant" aria-label="Data Query Assistant" aria-expanded="false">
				<span class="dqa-siri-orb" aria-hidden="true">
					<span class="dqa-siri-blob dqa-siri-blob-1"></span>
					<span class="dqa-siri-blob dqa-siri-blob-2"></span>
					<span class="dqa-siri-blob dqa-siri-blob-3"></span>
				</span>
				<svg class="dqa-siri-icon" width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
					<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
				</svg>
			</button>

			<div id="dqa-panel">
				<div class="dqa-layout">
					<aside class="dqa-sidebar">
						<div class="dqa-sidebar-head">
							<span class="dqa-sidebar-title"><?php esc_html_e( 'Chats', 'data-query-assistant' ); ?></span>
							<button id="dqa-new-chat" type="button" class="dqa-new-chat-btn"><?php esc_html_e( '+ New', 'data-query-assistant' ); ?></button>
						</div>
						<div id="dqa-chat-list"></div>
					</aside>

					<div class="dqa-main">
						<div class="dqa-header">
							<div class="dqa-header-left">
								<svg class="dqa-header-icon" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
								<span class="dqa-header-chat-title" id="dqa-header-title"><?php esc_html_e( 'New chat', 'data-query-assistant' ); ?></span>
							</div>
							<div class="dqa-header-right">
								<span class="dqa-provider-badge" id="dqa-provider-badge"></span>
								<button class="dqa-icon-btn" id="dqa-fullscreen" title="<?php echo esc_attr( __( 'Fullscreen', 'data-query-assistant' ) ); ?>">
									<svg class="dqa-fs-expand" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
									<svg class="dqa-fs-collapse" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
								</button>
								<button class="dqa-icon-btn" id="dqa-clear" title="<?php echo esc_attr( __( 'Clear chat', 'data-query-assistant' ) ); ?>">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
								</button>
								<button class="dqa-icon-btn" id="dqa-close" title="<?php echo esc_attr( __( 'Close', 'data-query-assistant' ) ); ?>">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
								</button>
							</div>
						</div>

						<div id="dqa-messages">
							<div class="dqa-welcome">
								<p><?php esc_html_e( 'Ask anything about your WordPress data in plain language.', 'data-query-assistant' ); ?></p>
								<div id="dqa-examples"></div>
							</div>
						</div>

						<div class="dqa-input-wrap">
							<textarea id="dqa-input" rows="2" placeholder="<?php echo esc_attr( __( 'Ask anything about your data…', 'data-query-assistant' ) ); ?>"></textarea>
							<div class="dqa-input-actions">
								<div class="dqa-model-wrap">
									<div class="dqa-model-picker" id="dqa-model-picker">
										<button type="button" class="dqa-model-btn" id="dqa-model-btn">
											<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" class="dqa-model-spark"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
											<span class="dqa-model-btn-label" id="dqa-model-label"><?php esc_html_e( 'Model', 'data-query-assistant' ); ?></span>
											<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="dqa-model-chevron"><polyline points="6 9 12 15 18 9"/></svg>
										</button>
										<ul class="dqa-model-dropdown" id="dqa-model-dropdown"></ul>
									</div>
								</div>
								<div class="dqa-send-group">
									<button id="dqa-voice" class="dqa-icon-btn dqa-voice-btn" title="<?php echo esc_attr( __( 'Voice input', 'data-query-assistant' ) ); ?>" style="display:none">
										<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
									</button>
									<button id="dqa-send" class="dqa-send-btn">
										<?php esc_html_e( 'Send', 'data-query-assistant' ); ?>
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
									</button>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div id="dqa-onboarding" style="display:none" role="dialog" aria-modal="true">
					<div class="dqa-ob-card">
						<div class="dqa-ob-dots">
							<span class="dqa-ob-dot active" data-slide="0"></span>
							<span class="dqa-ob-dot" data-slide="1"></span>
							<span class="dqa-ob-dot" data-slide="2"></span>
							<span class="dqa-ob-dot" data-slide="3"></span>
							<span class="dqa-ob-dot" data-slide="4"></span>
						</div>

						<div class="dqa-ob-slide active" data-slide="0">
							<div class="dqa-ob-emoji">✨</div>
							<h2><?php esc_html_e( 'Meet Data Query Assistant', 'data-query-assistant' ); ?></h2>
							<p><?php esc_html_e( 'Ask questions about your WordPress data in your native language — no SQL knowledge needed.', 'data-query-assistant' ); ?></p>
							<p class="dqa-ob-sub"><?php esc_html_e( 'Powered by AI. Runs only on your WordPress admin.', 'data-query-assistant' ); ?></p>
						</div>

						<div class="dqa-ob-slide" data-slide="1">
							<div class="dqa-ob-emoji">🔑</div>
							<h2><?php esc_html_e( 'Add your API key', 'data-query-assistant' ); ?></h2>
							<p><?php esc_html_e( 'Data Query Assistant uses an AI provider to generate queries. An API key is required — without it, nothing will work.', 'data-query-assistant' ); ?></p>
							<div class="dqa-ob-steps">
								<div class="dqa-ob-api-step">
									<strong>1.</strong> <?php esc_html_e( 'Choose a provider: Anthropic, OpenAI, or Google', 'data-query-assistant' ); ?>
								</div>
								<div class="dqa-ob-api-step">
									<strong>2.</strong> <?php esc_html_e( 'Get an API key from your provider\'s dashboard', 'data-query-assistant' ); ?>
								</div>
								<div class="dqa-ob-api-step">
									<strong>3.</strong> <?php esc_html_e( 'Paste it in plugin settings', 'data-query-assistant' ); ?>
								</div>
							</div>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=data-query-assistant' ) ); ?>" class="dqa-ob-settings-btn"><?php esc_html_e( 'Open Settings', 'data-query-assistant' ); ?> →</a>
						</div>

						<div class="dqa-ob-slide" data-slide="2">
							<div class="dqa-ob-emoji">⚙️</div>
							<h2><?php esc_html_e( 'How it works', 'data-query-assistant' ); ?></h2>
							<ol class="dqa-ob-steps">
								<li><strong><?php esc_html_e( 'You ask', 'data-query-assistant' ); ?></strong> <?php esc_html_e( 'a question in plain language', 'data-query-assistant' ); ?></li>
								<li><strong><?php esc_html_e( 'AI generates', 'data-query-assistant' ); ?></strong> <?php esc_html_e( 'a safe SQL SELECT query', 'data-query-assistant' ); ?></li>
								<li><strong><?php esc_html_e( 'WordPress runs', 'data-query-assistant' ); ?></strong> <?php esc_html_e( 'the query on your database', 'data-query-assistant' ); ?></li>
								<li><strong><?php esc_html_e( 'Results shown', 'data-query-assistant' ); ?></strong> <?php esc_html_e( 'as a table or chart', 'data-query-assistant' ); ?></li>
							</ol>
						</div>

						<div class="dqa-ob-slide" data-slide="3">
							<div class="dqa-ob-emoji">🔒</div>
							<h2><?php esc_html_e( 'Is it safe?', 'data-query-assistant' ); ?></h2>
							<ul class="dqa-ob-safety">
								<li>✅ <strong><?php esc_html_e( 'Read-only', 'data-query-assistant' ); ?></strong> — <?php esc_html_e( 'only SELECT queries run', 'data-query-assistant' ); ?></li>
								<li>✅ <strong><?php esc_html_e( 'Admin only', 'data-query-assistant' ); ?></strong> — <?php esc_html_e( 'requires WordPress admin access', 'data-query-assistant' ); ?></li>
								<li>✅ <strong><?php esc_html_e( 'Your data stays private', 'data-query-assistant' ); ?></strong> — <?php esc_html_e( 'only schema structure is sent to AI, not actual data values', 'data-query-assistant' ); ?></li>
								<li>✅ <strong><?php esc_html_e( 'Validated', 'data-query-assistant' ); ?></strong> — <?php esc_html_e( 'all queries are checked before execution', 'data-query-assistant' ); ?></li>
							</ul>
						</div>

						<div class="dqa-ob-slide" data-slide="4">
							<div class="dqa-ob-emoji">💡</div>
							<h2><?php esc_html_e( 'Try these examples', 'data-query-assistant' ); ?></h2>
							<div class="dqa-ob-examples">
								<button class="dqa-ob-example" data-query="Show me the 10 most recent posts with their authors">📝 <?php esc_html_e( 'Recent posts with authors', 'data-query-assistant' ); ?></button>
								<button class="dqa-ob-example" data-query="How many users registered each month this year?">👥 <?php esc_html_e( 'User registrations by month', 'data-query-assistant' ); ?></button>
								<button class="dqa-ob-example" data-query="What are the top 5 most commented posts?">💬 <?php esc_html_e( 'Top commented posts', 'data-query-assistant' ); ?></button>
								<button class="dqa-ob-example" data-query="Show me all products with stock less than 5">📦 <?php esc_html_e( 'Low stock products', 'data-query-assistant' ); ?></button>
								<button class="dqa-ob-example" data-query="Count posts by category">📂 <?php esc_html_e( 'Posts by category', 'data-query-assistant' ); ?></button>
								<button class="dqa-ob-example" data-query="Show me orders placed today">🛒 <?php esc_html_e( "Today's orders", 'data-query-assistant' ); ?></button>
							</div>
						</div>

						<div class="dqa-ob-nav">
							<button class="dqa-ob-skip" id="dqa-ob-skip"><?php esc_html_e( 'Skip', 'data-query-assistant' ); ?></button>
							<div class="dqa-ob-nav-right">
								<button class="dqa-ob-prev" id="dqa-ob-prev" style="display:none">← <?php esc_html_e( 'Back', 'data-query-assistant' ); ?></button>
								<button class="dqa-ob-next" id="dqa-ob-next"><?php esc_html_e( 'Next', 'data-query-assistant' ); ?> →</button>
								<button class="dqa-ob-finish" id="dqa-ob-finish" style="display:none"><?php esc_html_e( "Let's go!", 'data-query-assistant' ); ?> 🚀</button>
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
		check_ajax_referer( 'dqa_nonce', 'nonce' );

		if ( ! self::current_user_allowed() ) {
			self::clean_output_buffer();
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'data-query-assistant' ) ], 403 );
		}

		$start          = microtime( true );
		$user_query     = sanitize_textarea_field( wp_unslash( $_POST['query'] ?? '' ) );
		$chat_context   = self::sanitize_context( wp_unslash( $_POST['context'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selected_model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );

		// Engine
		$engine = DQA_Engine_Factory::make( $selected_model );
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
		$schema = DQA_DB_Schema::get_schema_prompt();

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
		$rows = DQA_Query_Executor::execute( $sql );

		if ( is_wp_error( $rows ) ) {
			self::clean_output_buffer();
			wp_send_json_error(
				[
					'message' => $rows->get_error_message(),
					'sql'     => DQA_Settings::get( 'show_sql' ) ? $sql : null,
				]
			);
		}

		$explanation = self::build_answer_with_data( $engine, $user_query, $sql, $rows, $explanation );
		$formatted   = DQA_Query_Executor::format_results( $rows, $explanation );
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
			'sql'         => DQA_Settings::get( 'show_sql' ) ? $sql : null,
			'tokens'      => [
				'in'  => $engine->get_in_tokens(),
				'out' => $engine->get_out_tokens(),
			],
		];

		// Refresh nonce if needed
		$new_nonce    = wp_create_nonce( 'dqa_nonce' );
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
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'dqa_nonce' ) ) {
			http_response_code( 403 );
			echo 'data: ' . wp_json_encode(
				[
					'type' => 'error',
					'data' => __( 'Security check failed.', 'data-query-assistant' ),
				]
			) . "\n\n";
			die();
		}

		if ( ! self::current_user_allowed() ) {
			http_response_code( 403 );
			echo 'data: ' . wp_json_encode(
				[
					'type' => 'error',
					'data' => __( 'Access denied.', 'data-query-assistant' ),
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
					'data' => __( 'Please enter a question.', 'data-query-assistant' ),
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
			$engine = DQA_Engine_Factory::make( $selected_model );
			if ( is_wp_error( $engine ) ) {
				self::sse_push( 'error', $engine->get_error_message(), [ 'code' => $engine->get_error_code() ] );
				die();
			}

			// Signal that we're thinking
			self::sse_push( 'status', __( 'Generating SQL query…', 'data-query-assistant' ) );

			// Schema
			$schema = DQA_DB_Schema::get_schema_prompt();

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
			self::sse_push( 'status', __( 'Running query…', 'data-query-assistant' ) );

			// Execute
			$rows = DQA_Query_Executor::execute( $sql );

			if ( is_wp_error( $rows ) ) {
				self::sse_push(
					'error',
					$rows->get_error_message(),
					[
						'sql' => DQA_Settings::get( 'show_sql' ) ? $sql : null,
					]
				);
				die();
			}

			self::sse_push( 'status', __( 'Analyzing results…', 'data-query-assistant' ) );
			$explanation = self::build_answer_with_data( $engine, $user_query, $sql, $rows, $explanation );
			$formatted   = DQA_Query_Executor::format_results( $rows, $explanation );
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
						'sql'         => DQA_Settings::get( 'show_sql' ) ? $sql : null,
						'tokens'      => [
							'in'  => $engine->get_in_tokens(),
							'out' => $engine->get_out_tokens(),
						],
						'new_nonce'   => wp_create_nonce( 'dqa_nonce' ),
					]
				)
			);

		} catch ( \Throwable $e ) {
			DQA_Logger::error( 'Stream handler exception: ' . $e->getMessage() );
			self::sse_push( 'error', __( 'An unexpected error occurred. Check PHP error log.', 'data-query-assistant' ) );
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
		DQA_Engine_Core $engine,
		string $user_query,
		string $schema,
		?callable $on_chunk = null
	) {
		$ai_result = $engine->generate_sql( $user_query, $schema, $on_chunk );
		if ( is_wp_error( $ai_result ) && $ai_result->get_error_code() === 'parse_error' ) {
			DQA_Logger::warn( 'Retrying SQL generation after parse error.' );
			return $engine->generate_sql( $user_query, $schema, null );
		}
		return $ai_result;
	}

	private static function build_answer_with_data(
		DQA_Engine_Core $engine,
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
			DQA_Logger::warn( 'Post-processing failed: ' . $answer->get_error_message() );
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
		DQA_Engine_Core $engine,
		int $row_count,
		int $exec_ms,
		string $status,
		string $error_msg = ''
	): void {
		if ( ! DQA_Settings::get( 'log_queries' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dqa_logs';
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
				'provider'      => DQA_Settings::get( 'provider' ),
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
