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
				'features'      => [
					'savedQueries' => DQA_Feature_Gates::is_enabled( 'saved_queries' ),
					'csvExport'    => DQA_Feature_Gates::is_enabled( 'csv_export' ),
					'queryHealth'  => DQA_Feature_Gates::is_enabled( 'query_health' ),
				],
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
					'templates'         => __( 'Templates', 'data-query-assistant' ),
					'saveQuery'         => __( 'Save query', 'data-query-assistant' ),
					'exportCsv'         => __( 'Export CSV', 'data-query-assistant' ),
					'querySaved'        => __( 'Saved.', 'data-query-assistant' ),
					'noSavedQueries'    => __( 'No saved queries yet. Use "Save query" to save one.', 'data-query-assistant' ),
					'confirmDeleteSaved'=> __( 'Delete this saved query?', 'data-query-assistant' ),
					'savedTab'          => __( 'Saved', 'data-query-assistant' ),
					'applyQuery'        => __( 'Apply', 'data-query-assistant' ),
					'editTitle'         => __( 'Rename', 'data-query-assistant' ),
					'saveAsTemplate'    => __( 'Save as reusable template', 'data-query-assistant' ),
					'featureLocked'     => __( 'This feature is available in Pro mode.', 'data-query-assistant' ),
					'slowQuery'         => __( 'Slow query', 'data-query-assistant' ),
					'hitRowLimit'       => __( 'Result hit row limit', 'data-query-assistant' ),
					'highTokenUsage'    => __( 'High token usage', 'data-query-assistant' ),
					'pipelineAgentic'   => __( 'Deep', 'data-query-assistant' ),
					'pipelineSimple'    => __( 'Fast', 'data-query-assistant' ),
					'pipelineAgenticTitle' => __( 'Deep mode: AI discovers your data first, then generates accurate SQL (2 LLM calls)', 'data-query-assistant' ),
					'pipelineSimpleTitle'  => __( 'Fast mode: AI generates SQL directly in one call', 'data-query-assistant' ),
					'confirmClearChat'  => __( 'Clear all messages in this chat? This cannot be undone.', 'data-query-assistant' ),
					'confirmDeleteChat' => __( 'Delete this chat? All messages will be lost.', 'data-query-assistant' ),
					'clearChatTitle'    => __( 'Clear chat', 'data-query-assistant' ),
					'deleteChatTitle'   => __( 'Delete chat', 'data-query-assistant' ),
					'voiceStart'        => __( 'Listening… speak now', 'data-query-assistant' ),
					'noVoice'           => __( 'Voice input is not supported in this browser.', 'data-query-assistant' ),
					'noApiKey'          => __( 'API key not configured', 'data-query-assistant' ),
					'noApiKeyMsg'       => __( 'To use Data Query Assistant, add your AI provider API key in plugin settings.', 'data-query-assistant' ),
					'goToSettings'      => __( 'Open Settings', 'data-query-assistant' ),
					'example_groups'    => [
						[
							'group' => __( '🛒 Store', 'data-query-assistant' ),
							'items' => [
								__( 'Top 10 best-selling products this month', 'data-query-assistant' ),
								__( 'Monthly revenue for the last 12 months', 'data-query-assistant' ),
								__( 'Top 10 customers by lifetime spending', 'data-query-assistant' ),
								__( 'Customers with no orders in the last 90 days', 'data-query-assistant' ),
								__( 'Products with zero sales in the last 30 days', 'data-query-assistant' ),
							],
						],
						[
							'group' => __( '📝 Content', 'data-query-assistant' ),
							'items' => [
								__( 'Top 10 most commented posts', 'data-query-assistant' ),
								__( 'Scheduled posts in the next 14 days', 'data-query-assistant' ),
								__( 'Draft posts not updated in the last 30 days', 'data-query-assistant' ),
								__( 'Posts per author published this year', 'data-query-assistant' ),
								__( 'Posts with no tags', 'data-query-assistant' ),
							],
						],
						[
							'group' => __( '👥 Growth', 'data-query-assistant' ),
							'items' => [
								__( 'New user registrations by day for the last 30 days', 'data-query-assistant' ),
								__( 'Customers who ordered 3 or more times', 'data-query-assistant' ),
								__( 'Users registered but never placed an order', 'data-query-assistant' ),
								__( 'Most used coupon codes this month', 'data-query-assistant' ),
								__( 'Average order value by month this year', 'data-query-assistant' ),
							],
						],
						[
							'group' => __( '⚡ Site Health', 'data-query-assistant' ),
							'items' => [
								__( 'Top 20 largest autoloaded options', 'data-query-assistant' ),
								__( 'Posts with the most revisions', 'data-query-assistant' ),
								__( 'Orders stuck in pending status', 'data-query-assistant' ),
								__( 'Scheduled WordPress cron tasks', 'data-query-assistant' ),
								__( 'Users with administrator role', 'data-query-assistant' ),
							],
						],
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
						<div class="dqa-sidebar-tabs">
							<button type="button" class="dqa-sidebar-tab active" data-sidebar-tab="chats"><?php esc_html_e( 'Chats', 'data-query-assistant' ); ?></button>
							<button type="button" class="dqa-sidebar-tab" data-sidebar-tab="saved"><?php esc_html_e( 'Saved', 'data-query-assistant' ); ?></button>
						</div>
						<div class="dqa-sidebar-pane" id="dqa-pane-chats">
							<div class="dqa-sidebar-head">
								<button id="dqa-new-chat" type="button" class="dqa-new-chat-btn"><?php esc_html_e( '+ New', 'data-query-assistant' ); ?></button>
							</div>
							<div id="dqa-chat-list"></div>
						</div>
						<div class="dqa-sidebar-pane dqa-hidden" id="dqa-pane-saved">
							<div id="dqa-saved-list"></div>
						</div>
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
									<button type="button" class="dqa-pipeline-toggle is-simple" id="dqa-pipeline-toggle" title="">
										<span class="dqa-pt-opt dqa-pt-fast"><?php esc_html_e( 'Fast', 'data-query-assistant' ); ?></span>
										<span class="dqa-pt-track"><span class="dqa-pt-thumb"></span></span>
										<span class="dqa-pt-opt dqa-pt-deep"><?php esc_html_e( 'Deep', 'data-query-assistant' ); ?></span>
									</button>
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
				<!-- Save Query Modal -->
				<div id="dqa-save-modal" class="dqa-modal" style="display:none" role="dialog" aria-modal="true">
					<div class="dqa-modal-card">
						<div class="dqa-modal-header">
							<span class="dqa-modal-title"><?php esc_html_e( 'Save Query', 'data-query-assistant' ); ?></span>
							<button type="button" class="dqa-modal-close" id="dqa-save-modal-cancel" aria-label="<?php esc_attr_e( 'Close', 'data-query-assistant' ); ?>">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
							</button>
						</div>
						<div class="dqa-modal-body">
							<div class="dqa-modal-field">
								<label for="dqa-save-title"><?php esc_html_e( 'Title', 'data-query-assistant' ); ?></label>
								<input type="text" id="dqa-save-title" class="dqa-modal-input" maxlength="190" autocomplete="off">
							</div>
							<label class="dqa-modal-check">
								<input type="checkbox" id="dqa-save-as-template">
								<span><?php esc_html_e( 'Save as reusable template', 'data-query-assistant' ); ?></span>
							</label>
						</div>
						<div class="dqa-modal-footer">
							<button type="button" class="dqa-modal-btn-ghost" id="dqa-save-modal-cancel2"><?php esc_html_e( 'Cancel', 'data-query-assistant' ); ?></button>
							<button type="button" class="dqa-modal-btn-primary" id="dqa-save-modal-confirm"><?php esc_html_e( 'Save', 'data-query-assistant' ); ?></button>
						</div>
					</div>
				</div>

				<div id="dqa-confirm-modal" class="dqa-modal" style="display:none" role="dialog" aria-modal="true">
					<div class="dqa-modal-card dqa-modal-card--sm">
						<div class="dqa-modal-header">
							<span class="dqa-modal-title" id="dqa-confirm-title"><?php esc_html_e( 'Are you sure?', 'data-query-assistant' ); ?></span>
						</div>
						<div class="dqa-modal-body">
							<p class="dqa-confirm-msg" id="dqa-confirm-msg"></p>
						</div>
						<div class="dqa-modal-footer">
							<button type="button" class="dqa-modal-btn-ghost" id="dqa-confirm-cancel"><?php esc_html_e( 'Cancel', 'data-query-assistant' ); ?></button>
							<button type="button" class="dqa-modal-btn-danger" id="dqa-confirm-ok"><?php esc_html_e( 'Delete', 'data-query-assistant' ); ?></button>
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
		$pipeline_mode  = sanitize_text_field( wp_unslash( $_POST['pipeline'] ?? 'agentic' ) );

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

		// AI → SQL (agentic pipeline, no streaming in AJAX mode)
		$ai_result = self::run_agentic_pipeline(
			$engine,
			$user_query,
			$chat_context,
			$schema,
			null,
			null,
			$pipeline_mode
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
		$health      = self::build_query_health( $engine, $formatted['count'], $exec_ms );

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
			'model'       => $engine->get_model(),
			'pipeline'    => $pipeline_mode,
			'tokens'      => [
				'in'  => $engine->get_in_tokens(),
				'out' => $engine->get_out_tokens(),
			],
			'health'      => $health,
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
		$pipeline_mode  = sanitize_text_field( wp_unslash( $_POST['pipeline'] ?? 'agentic' ) );
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

			// Schema
			$schema = DQA_DB_Schema::get_schema_prompt();

			// AI → SQL via agentic pipeline (with streaming + status SSE callbacks)
			$ai_result = self::run_agentic_pipeline(
				$engine,
				$user_query,
				$chat_context,
				$schema,
				function ( string $token ) {
					self::sse_push( 'token', $token );
				},
				function ( string $status ) {
					self::sse_push( 'status', $status );
				},
				$pipeline_mode
			);

			if ( is_wp_error( $ai_result ) ) {
				self::sse_push( 'error', $ai_result->get_error_message() );
				die();
			}

			$sql         = $ai_result['sql'];
			$explanation = $ai_result['explanation'] ?? '';

			// Execute.
			$rows = DQA_Query_Executor::execute( $sql );

			if ( is_wp_error( $rows ) ) {
				self::sse_push(
					'error',
					$rows->get_error_message(),
					array(
						'sql' => DQA_Settings::get( 'show_sql' ) ? $sql : null,
					)
				);
				die();
			}

			// ── Auto-retry on empty results ────────────────────────
			// If the query returned 0 rows, ask the LLM to try a different approach.
			if ( empty( $rows ) ) {
				self::sse_push( 'status', __( 'No results — retrying with a different approach…', 'data-query-assistant' ) );
				DQA_Logger::log( 'Auto-retry: first query returned 0 rows. SQL: ' . mb_strimwidth( $sql, 0, 200 ) );

				$retry_prompt = $engine->build_empty_result_retry_prompt( $user_query, $sql, $schema );
				$retry_result = $engine->generate_sql( $retry_prompt, $schema, null );

				if ( ! is_wp_error( $retry_result ) && ! empty( $retry_result['sql'] ) && $retry_result['sql'] !== $sql ) {
					$retry_sql = $retry_result['sql'];
					DQA_Logger::log( 'Auto-retry SQL: ' . mb_strimwidth( $retry_sql, 0, 200 ) );

					$retry_rows = DQA_Query_Executor::execute( $retry_sql );

					if ( ! is_wp_error( $retry_rows ) && ! empty( $retry_rows ) ) {
						$sql         = $retry_sql;
						$explanation = $retry_result['explanation'] ?? $explanation;
						$rows        = $retry_rows;
						DQA_Logger::log( 'Auto-retry succeeded: ' . count( $rows ) . ' rows.' );
					} else {
						DQA_Logger::log( 'Auto-retry also returned 0 rows or error — using original result.' );
					}
				} else {
					DQA_Logger::log( 'Auto-retry: LLM returned same SQL or error — keeping original result.' );
				}
			}

			self::sse_push( 'status', __( 'Analyzing results…', 'data-query-assistant' ) );
			$explanation = self::build_answer_with_data( $engine, $user_query, $sql, $rows, $explanation );
			$formatted   = DQA_Query_Executor::format_results( $rows, $explanation );
			$exec_ms     = (int) round( ( microtime( true ) - $start ) * 1000 );
			$health      = self::build_query_health( $engine, $formatted['count'], $exec_ms );

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
						'model'       => $engine->get_model(),
						'pipeline'    => $pipeline_mode,
						'tokens'      => [
							'in'  => $engine->get_in_tokens(),
							'out' => $engine->get_out_tokens(),
						],
						'health'      => $health,
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

	/**
	 * 2-phase agentic pipeline:
	 *  Phase 1 — LLM plans: returns "direct" SQL or "discover" intent.
	 *  Phase 2 — If "discover": run the lightweight discovery query, feed results back to LLM.
	 *
	 * @param DQA_Engine_Core  $engine        AI engine instance.
	 * @param string           $user_question Natural language question.
	 * @param string           $chat_context  Conversation context.
	 * @param string           $schema        Full DB schema prompt.
	 * @param callable|null    $on_chunk      Token streaming callback (Phase 1 only).
	 * @param callable|null    $on_status     Status message callback: fn(string $message).
	 * @return array{sql:string,explanation:string}|WP_Error
	 */
	private static function run_agentic_pipeline(
		DQA_Engine_Core $engine,
		string $user_question,
		string $chat_context,
		string $schema,
		?callable $on_chunk,
		?callable $on_status,
		string $mode = 'agentic'
	): array|WP_Error {
		$emit = function ( string $msg ) use ( $on_status ): void {
			if ( $on_status ) {
				( $on_status )( $msg );
			}
		};

		$prompt = self::build_sql_request_prompt( $user_question, $chat_context );

		// ── Simple (fast) mode: single LLM call, no discovery ─────
		if ( 'simple' === $mode ) {
			$emit( __( 'Generating SQL query…', 'data-query-assistant' ) );
			$result = $engine->generate_sql( $prompt, $schema, $on_chunk );
			if ( is_wp_error( $result ) && 'parse_error' === $result->get_error_code() ) {
				$emit( __( 'Retrying…', 'data-query-assistant' ) );
				$result = $engine->generate_sql( $prompt, $schema, null );
			}
			if ( ! is_wp_error( $result ) ) {
				$emit( __( 'Running query…', 'data-query-assistant' ) );
			}
			return $result;
		}

		// ── Deep (agentic) mode: always forced 2-phase pipeline ───
		// Phase 1: LLM MUST return "discover" with a targeted exploration query.
		$emit( __( 'Analysing your question…', 'data-query-assistant' ) );

		$force_prompt = $engine->build_forced_discover_prompt( $user_question, $chat_context );
		$phase1       = $engine->generate_sql( $force_prompt, $schema, $on_chunk );

		// If Phase 1 fails or LLM still went direct despite instruction, fall back to direct.
		if ( is_wp_error( $phase1 ) || ( $phase1['mode'] ?? '' ) !== 'discover' || empty( $phase1['discovery_sql'] ) ) {
			DQA_Logger::warn( 'Deep mode Phase 1 did not return discover — falling back to single-call.' );
			$emit( __( 'Generating SQL query…', 'data-query-assistant' ) );
			$direct = $engine->generate_sql( $prompt, $schema, null );
			if ( ! is_wp_error( $direct ) ) {
				$emit( __( 'Running query…', 'data-query-assistant' ) );
			}
			return $direct;
		}

		$discovery_sql    = (string) $phase1['discovery_sql'];
		$discovery_reason = (string) ( $phase1['reason'] ?? '' );

		// Phase 2: Run the discovery query against the real DB.
		$emit( __( 'Exploring your database…', 'data-query-assistant' ) );
		DQA_Logger::log( 'Deep mode discovery: ' . mb_strimwidth( $discovery_sql, 0, 200 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discovery query is a safe SELECT generated by LLM and validated by execute(); not cacheable.
		$discovery_rows = DQA_Query_Executor::execute( $discovery_sql );

		if ( is_wp_error( $discovery_rows ) ) {
			DQA_Logger::warn( 'Discovery query failed: ' . $discovery_rows->get_error_message() . '. Falling back to direct.' );
			$emit( __( 'Generating SQL query…', 'data-query-assistant' ) );
			$fallback = $engine->generate_sql( $prompt, $schema, null );
			if ( ! is_wp_error( $fallback ) ) {
				$emit( __( 'Running query…', 'data-query-assistant' ) );
			}
			return $fallback;
		}

		// Phase 3: Generate the final SQL using the discovery results.
		$discovery_json = wp_json_encode( array_slice( $discovery_rows, 0, 50 ) );
		$enriched       = $engine->build_discovery_followup( $user_question, $discovery_reason, (string) $discovery_json );

		$emit( __( 'Generating SQL with discovered data…', 'data-query-assistant' ) );

		$final = $engine->generate_sql( $enriched, $schema, null );

		if ( is_wp_error( $final ) ) {
			return $final;
		}

		// Safety: if Phase 3 returned discover again, do one direct retry.
		if ( 'discover' === ( $final['mode'] ?? '' ) ) {
			DQA_Logger::warn( 'Phase 3 returned discover mode — falling back to direct retry.' );
			$final = $engine->generate_sql( $prompt, $schema, null );
			if ( is_wp_error( $final ) ) {
				return $final;
			}
		}

		$emit( __( 'Running query…', 'data-query-assistant' ) );
		return $final;
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

		$system_prompt = 'You are a WordPress data analyst. Use only the provided query result rows, do not invent facts. CRITICAL: You MUST reply in the EXACT same language as the user\'s question — detect the language from the question and use it. Keep response concise and useful.';
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

	/**
	 * Query health diagnostics for UI badges/warnings.
	 */
	private static function build_query_health(
		DQA_Engine_Core $engine,
		int $row_count,
		int $exec_ms
	): array {
		if ( ! DQA_Feature_Gates::is_enabled( 'query_health' ) ) {
			return [];
		}

		$max_rows     = (int) DQA_Settings::get( 'max_rows', 100 );
		$tokens_total = (int) $engine->get_in_tokens() + (int) $engine->get_out_tokens();

		return [
			'row_limit'      => $max_rows,
			'hit_row_limit'  => $row_count >= $max_rows,
			'slow_query'     => $exec_ms >= 3000,
			'high_tokens'    => $tokens_total >= 4000,
			'tokens_total'   => $tokens_total,
			'rows_returned'  => $row_count,
			'execution_time' => $exec_ms,
		];
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
