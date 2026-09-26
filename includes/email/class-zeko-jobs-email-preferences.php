<?php
/**
 * Per-user email preferences and one-click unsubscribe for Zeko Jobs.
 *
 * Provides:
 * - Signed per-user, per-type unsubscribe/manage links (no login required)
 * - Storage in user_meta (`zeko_jobs_email_prefs`)
 * - A standalone preferences page rendered via template_redirect
 * - A central opt-in check used by the email renderer
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Email_Preferences. */
final class Zeko_Jobs_Email_Preferences {

	/**
	 * Instance.
	 *
	 * @var ?self Instance.
	 */
	private static ?self $instance = null;

	/**
	 * EMAIL TYPES.
	 *
	 * @var array<string,string>
	 */
	private const EMAIL_TYPES = array(
		'application_submitted' => 'New applications received',
		'status_changed'        => 'Application status updates',
		'application_withdrawn' => 'Application withdrawals',
		'interview_scheduled'   => 'Interview scheduling',
		'interview_reminder'    => 'Interview reminders',
		'job_expired'           => 'Job expiry notifications',
		'expiry_reminder'       => 'Listing expiry reminders',
		'job_alert'             => 'Job alerts & digests',
		'welcome'               => 'Welcome & account emails',
	);

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
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
	}

	/**
	 * Get the registered email type slugs.
	 *
	 * @return string[]
	 */
	public function get_email_types(): array {
		return array_keys( self::EMAIL_TYPES );
	}

	/**
	 * Label for a single email type slug.
	 *
	 * @param string $type Type.
	 */
	public function get_type_label( string $type ): string {
		return self::EMAIL_TYPES[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * All email type labels (slug => label).
	 *
	 * @return array<string,string>
	 */
	public function get_type_labels(): array {
		return self::EMAIL_TYPES;
	}

	/**
	 * Get the user's preferences array, merged with defaults (all enabled).
	 *
	 * @return array<string,string> Type => '1'|'0'. Also includes 'all' switch.
	 * @param int $user_id User id.
	 */
	public function get_prefs( int $user_id ): array {
		$saved = get_user_meta( $user_id, 'zeko_jobs_email_prefs', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$prefs = array();
		foreach ( array_keys( self::EMAIL_TYPES ) as $type ) {
			$prefs[ $type ] = isset( $saved[ $type ] ) ? ( '0' === $saved[ $type ] ? '0' : '1' ) : '1';
		}
		$prefs['all']        = isset( $saved['all'] ) ? ( '0' === $saved['all'] ? '0' : '1' ) : '1';
		$prefs['track_opens'] = isset( $saved['track_opens'] ) ? ( '0' === $saved['track_opens'] ? '0' : '1' ) : '1';

		return $prefs;
	}

	/**
	 * Whether a user is opted in to a given email type (default true).
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 */
	public function is_opted_in( int $user_id, string $type ): bool {
		if ( $user_id <= 0 || empty( $type ) ) {
			return true;
		}
		$prefs = $this->get_prefs( $user_id );
		if ( '0' === ( $prefs['all'] ?? '1' ) ) {
			return false;
		}
		return '0' !== ( $prefs[ $type ] ?? '1' );
	}

	/**
	 * Save preferences from form input.
	 *
	 * @param int   $user_id User id.
	 * @param array $input Assoc array of type => truthy.
	 */
	public function update_prefs( int $user_id, array $input ): bool {
		$prefs = array();
		foreach ( array_keys( self::EMAIL_TYPES ) as $type ) {
			$prefs[ $type ] = ! empty( $input[ $type ] ) ? '1' : '0';
		}
		// Saving the form re-enables the global switch (turned off by unsubscribe).
		$prefs['all']        = '1';
		$prefs['track_opens'] = ! empty( $input['track_opens'] ) ? '1' : '0';

		return (bool) update_user_meta( $user_id, 'zeko_jobs_email_prefs', $prefs );
	}

	/**
	 * Whether a user has opted in to email open-tracking (read-receipt) pixels.
	 * Defaults to enabled so transactional emails keep their delivery
	 * confirmation; the preferences page exposes the opt-out. Pixels are never
	 * sent to opted-out recipients, and the tracking endpoint re-checks consent
	 * so a later opt-out also stops future activity being recorded.
	 *
	 * @param int $user_id User id.
	 */
	public function open_tracking_consent( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return true;
		}
		$prefs   = $this->get_prefs( $user_id );
		$consent = '0' !== ( $prefs['track_opens'] ?? '1' );

		/**
		 * Filters whether a user's email opens may be tracked.
		 *
		 * @param bool $consent Whether the recipient allows open tracking.
		 * @param int  $user_id Recipient user ID.
		 */
		return (bool) apply_filters( 'zeko_jobs_email_open_tracking_consent', $consent, $user_id );
	}

	/**
	 * Signing token for a user + type. Includes the auth salt, so it cannot be forged.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 */
	public function token( int $user_id, string $type ): string {
		return wp_hash( 'zeko_jobs_email_prefs|' . (int) $user_id . '|' . $type, 'auth' );
	}

	/**
	 * Verify a signing token in constant time.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $token Token.
	 */
	public function verify_token( int $user_id, string $type, string $token ): bool {
		if ( $user_id <= 0 || '' === $token ) {
			return false;
		}
		return hash_equals( $this->token( $user_id, $type ), (string) $token );
	}

	/**
	 * Manage-preferences URL for a user + type.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 */
	public function preferences_url( int $user_id, string $type = 'all' ): string {
		return add_query_arg(
			array(
				'zeko_jobs_prefs' => 1,
				'u'               => (int) $user_id,
				't'               => sanitize_key( $type ),
				'k'               => $this->token( $user_id, $type ),
			),
			home_url( '/' )
		);
	}

	/**
	 * One-click unsubscribe URL for a user + type.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 */
	public function unsubscribe_url( int $user_id, string $type ): string {
		return add_query_arg(
			array(
				'zeko_jobs_prefs' => 1,
				'action'          => 'unsubscribe',
				'u'               => (int) $user_id,
				't'               => sanitize_key( $type ),
				'k'               => $this->token( $user_id, $type ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Email footer snippet appended to transactional emails.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 */
	public function footer( int $user_id, string $type ): string {
		if ( $user_id <= 0 || empty( $type ) ) {
			return '';
		}

		$prefs_url = $this->preferences_url( $user_id, 'all' );
		$unsub_url = $this->unsubscribe_url( $user_id, $type );

		return '<p style="margin:20px 0 0;padding-top:14px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center;">'
			/* translators: %s: site name */
			. esc_html( sprintf( __( 'You are receiving this email because you have an account on %s.', 'zeko-jobs' ), get_bloginfo( 'name' ) ) )
			. ' <a href="' . esc_url( $prefs_url ) . '" style="color:#64748b;">' . esc_html__( 'Manage email preferences', 'zeko-jobs' ) . '</a>'
			. ' &middot; <a href="' . esc_url( $unsub_url ) . '" style="color:#64748b;">' . esc_html__( 'Unsubscribe', 'zeko-jobs' ) . '</a></p>'
			. '<p style="margin:6px 0 0;font-size:11px;color:#94a3b8;text-align:center;">' . esc_html__( 'These emails may include read tracking; you can disable it under Manage email preferences.', 'zeko-jobs' ) . '</p>';
	}

	/**
	 * Template_redirect handler for the preferences page.
	 */
	public function maybe_handle(): void {
		// Direct query-arg access; the pretty /jobs/email-preferences/ rewrite.
		// also resolves to this via the public query var.
		if ( ! get_query_var( 'zeko_jobs_prefs' ) && ! isset( $_GET['zeko_jobs_prefs'] ) ) {
			return;
		}

		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'prefs';
		$user_id = isset( $_GET['u'] ) ? absint( $_GET['u'] ) : 0;
		$type    = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : 'all';
		$token   = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';

		if ( ! $this->verify_token( $user_id, $type, $token ) ) {
			$this->render_page( esc_html__( 'Invalid or expired link.', 'zeko-jobs' ), 'error' );
			return;
		}

		// POST save.
		if ( 'prefs' === $action && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['zeko_jobs_email_prefs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zeko_jobs_email_prefs_nonce'] ) ), 'zeko_jobs_email_prefs' ) ) {
				$this->render_page( esc_html__( 'Security check failed. Please try again.', 'zeko-jobs' ), 'error' );
				return;
			}
			$input = isset( $_POST['zeko_email_type'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['zeko_email_type'] ) ) : array();
			$input['track_opens'] = ! empty( $_POST['zeko_jobs_email_track_opens'] );
			$this->update_prefs( $user_id, $input );
			$this->render_page( esc_html__( 'Your email preferences have been saved.', 'zeko-jobs' ), 'success' );
			return;
		}

		// One-click unsubscribe.
		if ( 'unsubscribe' === $action ) {
			$prefs        = $this->get_prefs( $user_id );
			$prefs['all'] = '0';
			update_user_meta( $user_id, 'zeko_jobs_email_prefs', $prefs );
			$this->render_page( esc_html__( 'You have been unsubscribed from Zeko Jobs emails.', 'zeko-jobs' ), 'success' );
			return;
		}

		$this->render_preferences_form( $user_id );
	}

	/**
	 * Render the standalone preferences page (uses active theme chrome).
	 *
	 * @param int $user_id User id.
	 */
	private function render_preferences_form( int $user_id ): void {
		$prefs  = $this->get_prefs( $user_id );
		$types  = $this->get_type_labels();
		$action = $this->preferences_url( $user_id, 'all' );
		$nonce  = wp_create_nonce( 'zeko_jobs_email_prefs' );

		get_header();
		?>
		<div class="zeko-prefs-wrap" style="max-width:640px;margin:32px auto;padding:0 16px;">
			<h1><?php esc_html_e( 'Email Preferences', 'zeko-jobs' ); ?></h1>
			<p><?php esc_html_e( 'Choose which Zeko Jobs emails you would like to receive.', 'zeko-jobs' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<input type="hidden" name="zeko_jobs_email_prefs_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<?php foreach ( $types as $slug => $label ) : ?>
					<label style="display:block;padding:8px 0;border-bottom:1px solid #e2e8f0;cursor:pointer;">
						<input type="checkbox" name="zeko_email_type[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( '1', $prefs[ $slug ] ); ?>>
						<strong><?php echo esc_html( $label ); ?></strong>
					</label>
				<?php endforeach; ?>
				<label style="display:block;padding:8px 0;border-bottom:1px solid #e2e8f0;cursor:pointer;">
					<input type="checkbox" name="zeko_jobs_email_track_opens" value="1" <?php checked( '1', $prefs['track_opens'] ); ?>>
					<strong><?php esc_html_e( 'Allow read tracking on transactional emails', 'zeko-jobs' ); ?></strong>
					<span style="color:#64748b;">&nbsp;<?php esc_html_e( 'A hidden image may record when you open a job email. You can disable this at any time.', 'zeko-jobs' ); ?></span>
				</label>
				<p style="margin:20px 0;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Preferences', 'zeko-jobs' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		get_footer();
		exit;
	}

	/**
	 * Render a simple confirmation page.
	 *
	 * @param string $message Message.
	 * @param string $type Type.
	 */
	private function render_page( string $message, string $type = 'success' ): void {
		get_header();
		?>
		<div class="zeko-prefs-wrap" style="max-width:640px;margin:32px auto;padding:0 16px;">
			<h1><?php esc_html_e( 'Email Preferences', 'zeko-jobs' ); ?></h1>
			<p style="<?php echo 'error' === $type ? 'color:#b91c1c;' : 'color:#15803d;'; ?>"><?php echo esc_html( $message ); ?></p>
			<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; <?php esc_html_e( 'Back to site', 'zeko-jobs' ); ?></a></p>
		</div>
		<?php
		get_footer();
		exit;
	}
}
