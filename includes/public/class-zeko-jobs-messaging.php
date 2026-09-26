<?php
/**
 * Messaging system for Zeko Jobs.
 *
 * Provides conversation-based messaging between employers and seekers.
 * Migrated from theme to plugin for proper separation of concerns.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zeko_Jobs_Messaging
 *
 * Singleton managing all messaging functionality.
 */
class Zeko_Jobs_Messaging {

	/**
	 * Instance.
	 *
	 * @var ?self Instance.
	 */
	private static ?self $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Hooks.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'init', array( $this, 'add_endpoints' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 90 );

		// Dashboard tab.
		add_filter( 'zeko_dashboard_tabs', array( $this, 'add_dashboard_tab' ) );
		add_action( 'zeko_dashboard_tab_content_messages', array( $this, 'render_dashboard_tab' ) );

		// AJAX handlers — logged in.
		add_action( 'wp_ajax_zeko_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_zeko_get_messages', array( $this, 'ajax_get_messages' ) );
		add_action( 'wp_ajax_zeko_get_conversations', array( $this, 'ajax_get_conversations' ) );
		add_action( 'wp_ajax_zeko_start_conversation', array( $this, 'ajax_start_conversation' ) );
		add_action( 'wp_ajax_zeko_check_new_messages', array( $this, 'ajax_check_new_messages' ) );
		add_action( 'wp_ajax_zeko_get_conversation_data', array( $this, 'ajax_get_conversation_data' ) );
		add_action( 'wp_ajax_zeko_get_unread_count', array( $this, 'ajax_get_unread_count' ) );
		add_action( 'wp_ajax_zeko_search_users', array( $this, 'ajax_search_users' ) );
		add_action( 'wp_ajax_zeko_search_messages', array( $this, 'ajax_search_messages' ) );
		add_action( 'wp_ajax_zeko_save_message_template', array( $this, 'ajax_save_message_template' ) );
		add_action( 'wp_ajax_zeko_delete_message_template', array( $this, 'ajax_delete_message_template' ) );
		add_action( 'wp_ajax_zeko_get_message_templates', array( $this, 'ajax_get_message_templates' ) );
		add_action( 'wp_ajax_zeko_set_typing', array( $this, 'ajax_set_typing' ) );
		add_action( 'wp_ajax_zeko_get_typing', array( $this, 'ajax_get_typing' ) );
	}

	/*
	=========================================================================
	 * Public API (callable from anywhere)
	 * ======================================================================
	 */

	/**
	 * Send a message between two users. Creates conversation if needed.
	 *
	 * @return int|false Message ID or false on failure.
	 * @param int    $sender_id Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @param string $message_content Message body.
	 */
	public function send_message( int $sender_id, int $recipient_id, string $message_content ) {
		if ( $sender_id <= 0 || $recipient_id <= 0 || empty( $message_content ) ) {
			return false;
		}

		$conversation_id = $this->get_or_create_conversation( $sender_id, $recipient_id );
		if ( ! $conversation_id ) {
			return false;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'zeko_messages',
			array(
				'conversation_id' => $conversation_id,
				'sender_id'       => $sender_id,
				'recipient_id'    => $recipient_id,
				'message_content' => $message_content,
				'message_date'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		$message_id = $wpdb->insert_id;
		if ( ! $message_id ) {
			return false;
		}

		// Update conversation.
		$is_self = ( $sender_id === $recipient_id );
		$wpdb->update(
			$wpdb->prefix . 'zeko_conversations',
			array(
				'last_message_id'    => $message_id,
				'last_message_date'  => current_time( 'mysql' ),
				'unread_count_user1' => $is_self ? 0 : ( $sender_id < $recipient_id ? 1 : 0 ),
				'unread_count_user2' => $is_self ? 0 : ( $sender_id > $recipient_id ? 1 : 0 ),
			),
			array( 'conversation_id' => $conversation_id ),
			array( '%d', '%s', '%d', '%d' ),
			array( '%d' )
		);

		/**
		 * Fires after a message is sent.
		 *
		 * @param int $message_id     The message ID.
		 * @param int $sender_id      Sender user ID.
		 * @param int $recipient_id   Recipient user ID.
		 * @param int $conversation_id The conversation ID.
		 */
		do_action( 'zeko_jobs_message_sent', $message_id, $sender_id, $recipient_id, $conversation_id );

		return $message_id;
	}

	/**
	 * Get or create a conversation between two users.
	 *
	 * @return int Conversation ID.
	 * @param int $user_id1 First user.
	 * @param int $user_id2 Second user.
	 */
	public function get_or_create_conversation( int $user_id1, int $user_id2 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_conversations';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT conversation_id FROM {$table}
				WHERE (user1_id = %d AND user2_id = %d)
				   OR (user1_id = %d AND user2_id = %d)",
				$user_id1,
				$user_id2,
				$user_id2,
				$user_id1
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $existing ) {
			return (int) $existing->conversation_id;
		}

		$wpdb->insert(
			$table,
			array(
				'user1_id' => $user_id1,
				'user2_id' => $user_id2,
				'status'   => 'active',
			),
			array( '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get messages for a conversation.
	 *
	 * @return array Messages in chronological order.
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id Current user (for marking read).
	 * @param int $limit Max messages to return.
	 * @param int $offset Offset for pagination.
	 */
	public function get_messages( int $conversation_id, int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_messages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE conversation_id = %d
				ORDER BY message_date DESC
				LIMIT %d OFFSET %d",
				$conversation_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( ! empty( $messages ) ) {
			// Mark as read.
			$wpdb->update(
				$table,
				array( 'is_read' => 1 ),
				array(
					'conversation_id' => $conversation_id,
					'recipient_id'    => $user_id,
					'is_read'         => 0,
				),
				array( '%d' ),
				array( '%d', '%d', '%d' )
			);

			// Reset unread count.
			$conv = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT user1_id, user2_id FROM {$wpdb->prefix}zeko_conversations WHERE conversation_id = %d",
					$conversation_id
				)
			);
			if ( $conv ) {
				$field = ( $conv->user1_id === $user_id ) ? 'unread_count_user1' : 'unread_count_user2';
				$wpdb->update(
					$wpdb->prefix . 'zeko_conversations',
					array( $field => 0 ),
					array( 'conversation_id' => $conversation_id ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}

		return array_reverse( $messages );
	}

	/**
	 * Get unread message count for a user.
	 *
	 * @return int Total unread messages.
	 * @param int $user_id User ID.
	 */
	public function get_unread_count( int $user_id ): int {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CASE
					WHEN user1_id = %d THEN unread_count_user1
					WHEN user2_id = %d THEN unread_count_user2
					ELSE 0
				END) as total
				FROM {$wpdb->prefix}zeko_conversations
				WHERE (user1_id = %d OR user2_id = %d)
				AND status = 'active'",
				$user_id,
				$user_id,
				$user_id,
				$user_id
			)
		);

		return $count ? (int) $count : 0;
	}

	/**
	 * Get conversation data.
	 *
	 * @return object|null
	 * @param int $conversation_id Conversation ID.
	 */
	public function get_conversation_data( int $conversation_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_conversations WHERE conversation_id = %d",
				$conversation_id
			)
		);
	}

	/**
	 * Get message data.
	 *
	 * @return object|null
	 * @param int $message_id Message ID.
	 */
	public function get_message_data( int $message_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_messages WHERE message_id = %d",
				$message_id
			)
		);
	}

	/**
	 * Get new messages since a given message ID.
	 *
	 * @return array New messages.
	 * @param int $conversation_id Conversation ID.
	 * @param int $last_message_id Last known message ID.
	 */
	public function get_new_messages( int $conversation_id, int $last_message_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_messages
				WHERE conversation_id = %d AND message_id > %d
				ORDER BY message_date ASC",
				$conversation_id,
				$last_message_id
			)
		);
	}

	/**
	 * Validate user has access to a conversation.
	 *
	 * @return bool
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id User to check.
	 */
	public function user_has_access( int $conversation_id, int $user_id ): bool {
		$conv = $this->get_conversation_data( $conversation_id );
		return $conv && ( (int) $conv->user1_id === $user_id || (int) $conv->user2_id === $user_id );
	}

	/*
	=========================================================================
	 * Shortcodes
	 * ======================================================================
	 */

	/**
	 * Shortcodes.
	 */
	public function register_shortcodes(): void {
		add_shortcode( 'zeko_messaging', array( $this, 'shortcode_messaging' ) );
		add_shortcode( 'zeko_messages_form', array( $this, 'shortcode_messaging' ) );
	}

	/**
	 * [zeko_messaging] shortcode output.
	 *
	 * @param array $atts Atts.
	 */
	public function shortcode_messaging( array $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="zeko-not-logged-in">' . esc_html__( 'You must be logged in to view your messages.', 'zeko-jobs' ) . '</p>';
		}

		$user_id       = get_current_user_id();
		$atts          = shortcode_atts(
			array(
				'show_compose' => 'true',
			),
			$atts,
			'zeko_messaging'
		);
		$conversations = $this->get_conversations_list( $user_id );

		ob_start();
		?>
		<div class="zeko-messaging" data-user-id="<?php echo esc_attr( $user_id ); ?>">
			<div class="zeko-messaging-container">
				<div class="zeko-conversations-list">
					<div class="zeko-conversations-header">
						<h2 class="zeko-conversations-title"><?php esc_html_e( 'Messages', 'zeko-jobs' ); ?></h2>
						<div class="zeko-conversations-header-actions">
							<button class="button zeko-templates-btn" type="button" title="<?php esc_attr_e( 'Message templates', 'zeko-jobs' ); ?>">
								<span class="dashicons dashicons-editor-alignleft" aria-hidden="true"></span>
							</button>
							<?php if ( 'true' === $atts['show_compose'] ) : ?>
								<button class="button button-primary zeko-new-message-btn">
									<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</div>
					</div>
					<div class="zeko-search-messages">
						<input type="text" class="zeko-message-search" placeholder="<?php esc_attr_e( 'Search all messages...', 'zeko-jobs' ); ?>">
					</div>
					<div class="zeko-message-search-results" style="display:none;"></div>
					<div class="zeko-conversations">
						<?php echo $conversations; // phpcs:ignore -- escaped in method. ?>
					</div>
				</div>

				<div class="zeko-message-area">
					<div class="zeko-no-conversation-selected">
						<div class="zeko-no-conversation-icon"><span class="dashicons dashicons-email-alt"></span></div>
						<h3><?php esc_html_e( 'Select a conversation', 'zeko-jobs' ); ?></h3>
						<p><?php esc_html_e( 'Choose a conversation from the left to view messages.', 'zeko-jobs' ); ?></p>
					</div>

					<div class="zeko-message-content" style="display:none;">
						<div class="zeko-message-header">
							<button class="zeko-back-to-conversations" aria-label="<?php esc_attr_e( 'Back to conversations', 'zeko-jobs' ); ?>">
								<span class="dashicons dashicons-arrow-left-alt2"></span>
							</button>
							<h3 class="zeko-conversation-title"></h3>
							<span class="zeko-typing-indicator" style="display:none;"><?php esc_html_e( 'typing...', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-messages-container">
							<div class="zeko-messages-list"></div>
							<div class="zeko-load-more-messages" style="display:none;">
								<button class="button zeko-load-more-messages-btn"><?php esc_html_e( 'Load More', 'zeko-jobs' ); ?></button>
							</div>
						</div>
						<div class="zeko-message-compose">
							<textarea class="zeko-message-input" rows="2" placeholder="<?php esc_attr_e( 'Type your message...', 'zeko-jobs' ); ?>"></textarea>
							<div class="zeko-compose-actions">
								<select class="zeko-template-picker" aria-label="<?php esc_attr_e( 'Insert a saved template', 'zeko-jobs' ); ?>">
									<option value=""><?php esc_html_e( 'Insert template...', 'zeko-jobs' ); ?></option>
								</select>
								<button class="button button-primary zeko-send-message-btn"><?php esc_html_e( 'Send', 'zeko-jobs' ); ?></button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- New Message Modal -->
			<div class="zeko-new-message-modal" style="display:none;">
				<div class="zeko-modal-overlay"></div>
				<div class="zeko-new-message-content zeko-modal-dialog">
					<div class="zeko-new-message-header zeko-modal-header">
						<h3><?php esc_html_e( 'New Message', 'zeko-jobs' ); ?></h3>
						<button class="zeko-close-new-message zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
					</div>
					<div class="zeko-new-message-body zeko-modal-body">
						<div class="zeko-field">
							<label for="zeko-recipient"><?php esc_html_e( 'To:', 'zeko-jobs' ); ?></label>
							<input type="text" id="zeko-recipient" class="zeko-recipient-search" placeholder="<?php esc_attr_e( 'Search users...', 'zeko-jobs' ); ?>">
							<div class="zeko-user-search-results"></div>
						</div>
						<div class="zeko-field">
							<label for="zeko-new-message-content"><?php esc_html_e( 'Message:', 'zeko-jobs' ); ?></label>
							<textarea id="zeko-new-message-content" class="zeko-new-message-text" rows="4" placeholder="<?php esc_attr_e( 'Write your message...', 'zeko-jobs' ); ?>"></textarea>
						</div>
					</div>
					<div class="zeko-new-message-footer zeko-modal-footer">
						<button class="button zeko-cancel-new-message"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
						<button class="button button-primary zeko-send-new-message"><?php esc_html_e( 'Send Message', 'zeko-jobs' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Message Templates Modal -->
			<div class="zeko-templates-modal" style="display:none;">
				<div class="zeko-modal-overlay"></div>
				<div class="zeko-templates-content zeko-modal-dialog">
					<div class="zeko-templates-header zeko-modal-header">
						<h3><?php esc_html_e( 'Message Templates', 'zeko-jobs' ); ?></h3>
						<button class="zeko-close-templates zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
					</div>
					<div class="zeko-templates-body zeko-modal-body">
						<div class="zeko-template-list"></div>
						<hr>
						<div class="zeko-template-form">
							<div class="zeko-field">
								<label for="zeko-template-title"><?php esc_html_e( 'Template Title', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-template-title" class="zeko-template-title-input" placeholder="<?php esc_attr_e( 'e.g. Interview invitation', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label for="zeko-template-content"><?php esc_html_e( 'Template Content', 'zeko-jobs' ); ?></label>
								<textarea id="zeko-template-content" class="zeko-template-content-input" rows="4" placeholder="<?php esc_attr_e( 'Write your reusable response...', 'zeko-jobs' ); ?>"></textarea>
							</div>
							<button class="button button-primary zeko-save-template"><?php esc_html_e( 'Save Template', 'zeko-jobs' ); ?></button>
							<span class="zeko-template-message"></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/*
	=========================================================================
	 * Asset Enqueue
	 * ======================================================================
	 */

	/**
	 * Messaging page.
	 */
	public function is_messaging_page(): bool {
		$page_id = get_option( 'zeko_messages_page_id' );
		if ( $page_id && is_page( (int) $page_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Enqueue assets.
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! $this->is_messaging_page() ) {
			return;
		}

		wp_enqueue_script(
			'zeko-messaging',
			plugins_url( 'assets/js/messaging.js', ZEKO_JOBS_PLUGIN_FILE ),
			array( 'jquery' ),
			ZEKO_JOBS_VERSION,
			true
		);

		wp_enqueue_style(
			'zeko-messaging',
			plugins_url( 'assets/css/messaging.css', ZEKO_JOBS_PLUGIN_FILE ),
			array(),
			ZEKO_JOBS_VERSION
		);

		wp_localize_script(
			'zeko-messaging',
			'zekoMessagingData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'zeko_messaging_nonce' ),
				'i18n'     => array(
					'sending'            => __( 'Sending...', 'zeko-jobs' ),
					'sent'               => __( 'Message sent!', 'zeko-jobs' ),
					'error'              => __( 'Error: ', 'zeko-jobs' ),
					'no_messages'        => __( 'No messages yet.', 'zeko-jobs' ),
					'load_more'          => __( 'Load More Messages', 'zeko-jobs' ),
					'loading'            => __( 'Loading...', 'zeko-jobs' ),
					'new_message'        => __( 'New Message', 'zeko-jobs' ),
					'search_placeholder' => __( 'Search users...', 'zeko-jobs' ),
				),
			)
		);
	}

	/*
	=========================================================================
	 * Admin Bar
	 * ======================================================================
	 */

	/**
	 * Add admin bar menu.
	 *
	 * @param mixed $admin_bar Admin bar.
	 */
	public function add_admin_bar_menu( $admin_bar ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$count = $this->get_unread_count( get_current_user_id() );
		$title = __( 'Messages', 'zeko-jobs' );
		if ( $count > 0 ) {
			$title .= ' <span class="zeko-adminbar-unread">' . $count . '</span>';
		}

		$admin_bar->add_menu(
			array(
				'id'    => 'zeko-messaging',
				'title' => $title,
				'href'  => home_url( '/messages/' ),
				'meta'  => array(
					'title' => __( 'Messages', 'zeko-jobs' ),
				),
			)
		);
	}

	/*
	=========================================================================
	 * Endpoints
	 * ======================================================================
	 */

	/**
	 * Add endpoints.
	 */
	public function add_endpoints(): void {
		add_rewrite_endpoint( 'messages', EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( 'conversation', EP_PERMALINK | EP_PAGES );
	}

	/*
	=========================================================================
	 * Dashboard Tab
	 * ======================================================================
	 */

	/**
	 * Add dashboard tab.
	 *
	 * @param array $tabs Tabs.
	 */
	public function add_dashboard_tab( array $tabs ): array {
		$tabs['messages'] = __( 'Messages', 'zeko-jobs' );
		return $tabs;
	}

	/**
	 * Render dashboard tab.
	 */
	public function render_dashboard_tab(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id       = get_current_user_id();
		$unread_count  = $this->get_unread_count( $user_id );
		$conversations = $this->get_conversations_list( $user_id );
		?>
		<div class="zeko-messages-dashboard-tab">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
				<h3 style="margin:0;">
					<?php esc_html_e( 'Messages', 'zeko-jobs' ); ?>
					<?php if ( $unread_count > 0 ) : ?>
						<span style="background:var(--color-primary,#2563eb);color:#fff;border-radius:50%;padding:2px 8px;font-size:12px;margin-left:8px;">
							<?php echo esc_html( $unread_count ); ?>
						</span>
					<?php endif; ?>
				</h3>
				<a href="<?php echo esc_url( home_url( '/messages/' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open Messages', 'zeko-jobs' ); ?>
				</a>
			</div>
			<div class="zeko-dashboard-conversations-list">
				<?php echo $conversations; // phpcs:ignore -- escaped in method. ?>
			</div>
		</div>
		<?php
	}

	/*
	=========================================================================
	 * Helper Functions
	 * ======================================================================
	 */

	/**
	 * Render the conversations list HTML.
	 *
	 * @return string HTML.
	 * @param int $user_id Current user.
	 */
	public function get_conversations_list( int $user_id ): string {
		global $wpdb;
		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_conversations
				WHERE (user1_id = %d OR user2_id = %d) AND status = 'active'
				ORDER BY last_message_date DESC",
				$user_id,
				$user_id
			)
		);

		ob_start();
		if ( ! empty( $conversations ) ) :
			foreach ( $conversations as $conv ) :
				$other_id   = ( (int) $conv->user1_id === $user_id ) ? (int) $conv->user2_id : (int) $conv->user1_id;
				$other_user = get_userdata( $other_id );
				$unread     = ( (int) $conv->user1_id === $user_id ) ? (int) $conv->unread_count_user1 : (int) $conv->unread_count_user2;
				$preview    = $this->get_last_message_preview( (int) $conv->last_message_id );
				?>
				<div class="zeko-conversation-item<?php echo $unread > 0 ? ' has-unread' : ''; ?>" data-conversation-id="<?php echo esc_attr( $conv->conversation_id ); ?>">
					<div class="zeko-conversation-avatar">
						<?php echo get_avatar( $other_id, 48 ); ?>
					</div>
					<div class="zeko-conversation-info">
						<div class="zeko-conversation-user"><?php echo esc_html( $other_user ? $other_user->display_name : __( 'Unknown', 'zeko-jobs' ) ); ?></div>
						<div class="zeko-conversation-preview"><?php echo esc_html( $preview ); ?></div>
					</div>
					<div class="zeko-conversation-meta">
						<?php if ( $unread > 0 ) : ?>
							<span class="zeko-unread-count"><?php echo esc_html( $unread ); ?></span>
						<?php endif; ?>
						<span class="zeko-conversation-time"><?php echo esc_html( $this->time_ago( $conv->last_message_date ) ); ?></span>
					</div>
				</div>
				<?php
			endforeach;
		else :
			?>
			<div class="zeko-no-conversations">
				<p><?php esc_html_e( 'No conversations yet.', 'zeko-jobs' ); ?></p>
				<p><?php esc_html_e( 'Start a new conversation to connect with others!', 'zeko-jobs' ); ?></p>
			</div>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Render a single message bubble.
	 *
	 * @param object $message Message.
	 * @param int    $current_user_id Current user id.
	 */
	public function render_message( object $message, int $current_user_id ): string {
		$sender  = get_userdata( $message->sender_id );
		$is_sent = ( (int) $message->sender_id === $current_user_id );
		$time    = $this->message_time( $message->message_date );

		ob_start();
		?>
		<div class="zeko-message <?php echo $is_sent ? 'zeko-message-sent' : 'zeko-message-received'; ?>" data-message-id="<?php echo esc_attr( $message->message_id ); ?>">
			<?php if ( ! $is_sent ) : ?>
				<div class="zeko-message-avatar"><?php echo get_avatar( $message->sender_id, 32 ); ?></div>
			<?php endif; ?>
			<div class="zeko-message-content">
				<?php if ( ! $is_sent && $sender ) : ?>
					<div class="zeko-message-sender"><?php echo esc_html( $sender->display_name ); ?></div>
				<?php endif; ?>
				<div class="zeko-message-text"><?php echo wp_kses_post( wpautop( esc_html( $message->message_content ) ) ); ?></div>
				<div class="zeko-message-meta">
					<span class="zeko-message-time"><?php echo esc_html( $time ); ?></span>
					<?php if ( $is_sent && ! empty( $message->is_read ) ) : ?>
						<span class="zeko-message-status"><?php esc_html_e( 'Read', 'zeko-jobs' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $is_sent ) : ?>
				<div class="zeko-message-avatar"><?php echo get_avatar( $message->sender_id, 32 ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Last message preview.
	 *
	 * @param int $message_id Message id.
	 */
	private function get_last_message_preview( int $message_id ): string {
		if ( $message_id <= 0 ) {
			return __( 'No messages', 'zeko-jobs' );
		}

		global $wpdb;
		$msg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT message_content, sender_id FROM {$wpdb->prefix}zeko_messages WHERE message_id = %d",
				$message_id
			)
		);

		if ( $msg ) {
			$preview = wp_trim_words( $msg->message_content, 10, '...' );
			$sender  = get_userdata( $msg->sender_id );
			return sprintf( '%s: %s', $sender ? $sender->display_name : __( 'User', 'zeko-jobs' ), $preview );
		}

		return __( 'No messages', 'zeko-jobs' );
	}

	/**
	 * Time ago.
	 *
	 * @param string $date Date.
	 */
	private function time_ago( string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}

		$diff = time() - strtotime( $date );

		if ( $diff < 60 ) {
			/* translators: %s: number of seconds */
			return sprintf( _n( '%s sec', '%s sec', $diff, 'zeko-jobs' ), $diff );
		} elseif ( $diff < 3600 ) {
			$m = (int) floor( $diff / 60 );
			/* translators: %s: number of minutes */
			return sprintf( _n( '%s min', '%s min', $m, 'zeko-jobs' ), $m );
		} elseif ( $diff < 86400 ) {
			$h = (int) floor( $diff / 3600 );
			/* translators: %s: number of hours */
			return sprintf( _n( '%s hour', '%s hours', $h, 'zeko-jobs' ), $h );
		} elseif ( $diff < 2592000 ) {
			$d = (int) floor( $diff / 86400 );
			/* translators: %s: number of days */
			return sprintf( _n( '%s day', '%s days', $d, 'zeko-jobs' ), $d );
		} else {
			$mon = (int) floor( $diff / 2592000 );
			/* translators: %s: number of months */
			return sprintf( _n( '%s month', '%s months', $mon, 'zeko-jobs' ), $mon );
		}
	}

	/**
	 * Message time.
	 *
	 * @param string $date Date.
	 */
	private function message_time( string $date ): string {
		return gmdate( 'g:i a', strtotime( $date ) );
	}

	/*
	=========================================================================
	 * AJAX Handlers
	 * ======================================================================
	 */

	/**
	 * Ajax send message.
	 */
	public function ajax_send_message(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$sender_id    = get_current_user_id();
		$recipient_id = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		$content      = isset( $_POST['message_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message_content'] ) ) : '';

		if ( ! $recipient_id || empty( $content ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid message data.', 'zeko-jobs' ) ) );
		}

		if ( ! get_userdata( $recipient_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Recipient not found.', 'zeko-jobs' ) ) );
		}

		$message_id = $this->send_message( $sender_id, $recipient_id, $content );

		if ( $message_id ) {
			$message = $this->get_message_data( $message_id );
			wp_send_json_success(
				array(
					'message'      => esc_html__( 'Message sent!', 'zeko-jobs' ),
					'message_id'   => $message_id,
					'message_html' => $this->render_message( $message, $sender_id ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to send message.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * Ajax get messages.
	 */
	public function ajax_get_messages(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id         = get_current_user_id();
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$limit           = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;
		$offset          = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( ! $conversation_id || ! $this->user_has_access( $conversation_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'zeko-jobs' ) ) );
		}

		$messages = $this->get_messages( $conversation_id, $user_id, $limit, $offset );

		ob_start();
		if ( ! empty( $messages ) ) {
			foreach ( $messages as $msg ) {
				echo $this->render_message( $msg, $user_id ); // phpcs:ignore
			}
		} else {
			echo '<div class="zeko-no-messages">' . esc_html__( 'No messages yet. Start the conversation!', 'zeko-jobs' ) . '</div>';
		}
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'messages_html' => $html,
				'has_more'      => count( $messages ) >= $limit,
				'next_offset'   => $offset + $limit,
			)
		);
	}

	/**
	 * Ajax get conversations.
	 */
	public function ajax_get_conversations(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		ob_start();
		echo $this->get_conversations_list( $user_id ); // phpcs:ignore
		$html = ob_get_clean();

		wp_send_json_success( array( 'conversations_html' => $html ) );
	}

	/**
	 * Ajax start conversation.
	 */
	public function ajax_start_conversation(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$sender_id    = get_current_user_id();
		$recipient_id = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		$content      = isset( $_POST['message_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message_content'] ) ) : '';

		if ( ! $recipient_id || empty( $content ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid message data.', 'zeko-jobs' ) ) );
		}

		if ( ! get_userdata( $recipient_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Recipient not found.', 'zeko-jobs' ) ) );
		}

		$message_id = $this->send_message( $sender_id, $recipient_id, $content );

		if ( $message_id ) {
			$conversation_id = $this->get_or_create_conversation( $sender_id, $recipient_id );
			wp_send_json_success(
				array(
					'message'         => esc_html__( 'Message sent!', 'zeko-jobs' ),
					'conversation_id' => $conversation_id,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to send message.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * Ajax check new messages.
	 */
	public function ajax_check_new_messages(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id         = get_current_user_id();
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$last_message_id = isset( $_POST['last_message_id'] ) ? absint( $_POST['last_message_id'] ) : 0;

		if ( ! $conversation_id || ! $this->user_has_access( $conversation_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'zeko-jobs' ) ) );
		}

		$new_messages = $this->get_new_messages( $conversation_id, $last_message_id );

		if ( ! empty( $new_messages ) ) {
			$ids = wp_list_pluck( $new_messages, 'message_id' );

			// Mark as read.
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}zeko_messages SET is_read = 1
					WHERE message_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					$ids
				)
			);

			// Reset unread count.
			$conv = $this->get_conversation_data( $conversation_id );
			if ( $conv ) {
				$field = ( (int) $conv->user1_id === $user_id ) ? 'unread_count_user1' : 'unread_count_user2';
				$wpdb->update(
					$wpdb->prefix . 'zeko_conversations',
					array( $field => 0 ),
					array( 'conversation_id' => $conversation_id ),
					array( '%d' ),
					array( '%d' )
				);
			}

			ob_start();
			foreach ( $new_messages as $msg ) {
				echo $this->render_message( $msg, $user_id ); // phpcs:ignore
			}
			$html = ob_get_clean();

			wp_send_json_success(
				array(
					'new_messages'  => true,
					'messages_html' => $html,
					'new_count'     => count( $new_messages ),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'new_messages' => false,
					'new_count'    => 0,
				)
			);
		}
	}

	/**
	 * Ajax get conversation data.
	 */
	public function ajax_get_conversation_data(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id         = get_current_user_id();
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		if ( ! $conversation_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid conversation.', 'zeko-jobs' ) ) );
		}

		$conv = $this->get_conversation_data( $conversation_id );
		if ( ! $conv || ! $this->user_has_access( $conversation_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'zeko-jobs' ) ) );
		}

		$other_id   = ( (int) $conv->user1_id === $user_id ) ? (int) $conv->user2_id : (int) $conv->user1_id;
		$other_user = get_userdata( $other_id );

		wp_send_json_success(
			array(
				'conversation' => $conv,
				'other_user'   => $other_user ? array(
					'ID'           => $other_user->ID,
					'display_name' => $other_user->display_name,
					'user_login'   => $other_user->user_login,
				) : null,
			)
		);
	}

	/**
	 * Ajax get unread count.
	 */
	public function ajax_get_unread_count(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		wp_send_json_success(
			array(
				'unread_count' => $this->get_unread_count( get_current_user_id() ),
			)
		);
	}

	/**
	 * Ajax search users.
	 */
	public function ajax_search_users(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$search_term     = isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : '';
		$current_user_id = get_current_user_id();

		if ( empty( $search_term ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter a search term.', 'zeko-jobs' ) ) );
		}

		$users = get_users(
			array(
				'search'         => '*' . $search_term . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
				'exclude'        => array( $current_user_id ),
				'number'         => 10,
			)
		);

		ob_start();
		?>
		<div class="zeko-user-search-results">
			<?php if ( ! empty( $users ) ) : ?>
				<?php foreach ( $users as $u ) : ?>
					<div class="zeko-user-result" data-user-id="<?php echo esc_attr( $u->ID ); ?>">
						<div class="zeko-user-avatar"><?php echo get_avatar( $u->ID, 36 ); ?></div>
						<div class="zeko-user-info">
							<div class="zeko-user-name"><?php echo esc_html( $u->display_name ); ?></div>
							<div class="zeko-user-username">@<?php echo esc_html( $u->user_login ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="zeko-no-results"><?php esc_html_e( 'No users found.', 'zeko-jobs' ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		$html = ob_get_clean();

		wp_send_json_success( array( 'results_html' => $html ) );
	}

	/*
	=========================================================================
	 * AJAX: Message search, templates, and typing indicators
	 * ======================================================================
	 */

	/**
	 * AJAX: Search the user's messages across all conversations.
	 */
	public function ajax_search_messages(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$term  = isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : '';
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;

		if ( '' === $term ) {
			wp_send_json_success( array( 'results_html' => '' ) );
		}

		$results = Zeko_Jobs_DB::get_instance()->search_messages( get_current_user_id(), $term, $limit );

		ob_start();
		if ( empty( $results ) ) {
			echo '<div class="zeko-no-results">' . esc_html__( 'No messages matched your search.', 'zeko-jobs' ) . '</div>';
		} else {
			foreach ( $results as $row ) {
				$snippet = wp_trim_words( $row['message_content'], 20 );
				?>
				<div class="zeko-search-result" data-conversation-id="<?php echo esc_attr( $row['conversation_id'] ); ?>">
					<div class="zeko-search-result-name"><?php echo esc_html( $row['other_name'] ); ?></div>
					<div class="zeko-search-result-snippet"><?php echo esc_html( $snippet ); ?></div>
					<div class="zeko-search-result-date"><?php echo esc_html( human_time_diff( strtotime( $row['message_date'] ), time() ) . ' ' . __( 'ago', 'zeko-jobs' ) ); ?></div>
				</div>
				<?php
			}
		}
		$html = ob_get_clean();

		wp_send_json_success( array( 'results_html' => $html ) );
	}

	/**
	 * AJAX: Save (create or update) a pre-written message template.
	 */
	public function ajax_save_message_template(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		$title   = isset( $_POST['template_title'] ) ? sanitize_text_field( wp_unslash( $_POST['template_title'] ) ) : '';
		$content = isset( $_POST['template_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template_content'] ) ) : '';
		$temp_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		if ( '' === $title || '' === $content ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Template title and content are required.', 'zeko-jobs' ) ) );
		}

		$db = Zeko_Jobs_DB::get_instance();
		if ( $temp_id ) {
			$saved = $db->update_message_template( $temp_id, $user_id, $title, $content );
			if ( ! $saved ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Template not found or you cannot edit it.', 'zeko-jobs' ) ) );
			}
		} else {
			$temp_id = $db->insert_message_template( $user_id, $title, $content );
			if ( ! $temp_id ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Failed to save template.', 'zeko-jobs' ) ) );
			}
		}

		wp_send_json_success(
			array(
				'message'     => esc_html__( 'Template saved.', 'zeko-jobs' ),
				'template_id' => $temp_id,
			)
		);
	}

	/**
	 * AJAX: Delete a message template.
	 */
	public function ajax_delete_message_template(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$temp_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( ! $temp_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid template.', 'zeko-jobs' ) ) );
		}

		$deleted = Zeko_Jobs_DB::get_instance()->delete_message_template( $temp_id, get_current_user_id() );
		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Template not found.', 'zeko-jobs' ) ) );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Template deleted.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get the user's message templates (rendered list + picker).
	 */
	public function ajax_get_message_templates(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$templates = Zeko_Jobs_DB::get_instance()->get_message_templates( get_current_user_id() );

		ob_start();
		if ( empty( $templates ) ) {
			echo '<div class="zeko-no-results">' . esc_html__( 'No templates yet.', 'zeko-jobs' ) . '</div>';
		} else {
			foreach ( $templates as $t ) {
				?>
				<div class="zeko-template-item" data-template-id="<?php echo esc_attr( $t['id'] ); ?>" data-content="<?php echo esc_attr( $t['content'] ); ?>">
					<div class="zeko-template-title"><?php echo esc_html( $t['title'] ); ?></div>
					<div class="zeko-template-actions">
						<button type="button" class="button button-small zeko-template-use" data-template-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Use', 'zeko-jobs' ); ?></button>
						<button type="button" class="button button-small zeko-template-delete" data-template-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Delete', 'zeko-jobs' ); ?></button>
					</div>
				</div>
				<?php
			}
		}
		$html = ob_get_clean();

		wp_send_json_success( array( 'templates_html' => $html ) );
	}

	/**
	 * AJAX: Mark the current user as typing (or stopped) in a conversation.
	 */
	public function ajax_set_typing(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id         = get_current_user_id();
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		if ( ! $conversation_id || ! $this->user_has_access( $conversation_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'zeko-jobs' ) ) );
		}

		$typing = isset( $_POST['typing'] ) && '1' === $_POST['typing'];

		if ( $typing ) {
			set_transient( 'zeko_typing_' . $conversation_id . '_' . $user_id, time(), 15 );
		} else {
			delete_transient( 'zeko_typing_' . $conversation_id . '_' . $user_id );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Check whether the other participant in a conversation is typing.
	 */
	public function ajax_get_typing(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id         = get_current_user_id();
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		if ( ! $conversation_id || ! $this->user_has_access( $conversation_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'zeko-jobs' ) ) );
		}

		$conv = $this->get_conversation_data( $conversation_id );
		if ( ! $conv ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Conversation not found.', 'zeko-jobs' ) ) );
		}

		$other_id = ( (int) $conv->user1_id === $user_id ) ? (int) $conv->user2_id : (int) $conv->user1_id;
		$typing   = get_transient( 'zeko_typing_' . $conversation_id . '_' . $other_id );

		if ( $typing ) {
			$other = get_userdata( $other_id );
			wp_send_json_success(
				array(
					'typing'     => true,
					'other_name' => $other ? $other->display_name : '',
				)
			);
		}

		wp_send_json_success( array( 'typing' => false ) );
	}
}

/**
 * Global wrapper for backward compatibility.
 * Delegates to Zeko_Core_Messaging when zeko-core is active (canonical API +
 * one canonical definition), otherwise to Zeko_Jobs_Messaging::send_message().
 */
if ( ! function_exists( 'zeko_send_message' ) ) {
	/**
	 * Zeko send message.
	 *
	 * @param int    $sender_id Sender id.
	 * @param int    $recipient_id Recipient id.
	 * @param string $message_content Message content.
	 * @param string $source Source.
	 */
	function zeko_send_message( int $sender_id, int $recipient_id, string $message_content, string $source = '' ) {
		if ( class_exists( 'Zeko_Core_Messaging' ) ) {
			return Zeko_Core_Messaging::get_instance()->send_message( $sender_id, $recipient_id, $message_content, $source );
		}
		return Zeko_Jobs_Messaging::get_instance()->send_message( $sender_id, $recipient_id, $message_content );
	}
}
