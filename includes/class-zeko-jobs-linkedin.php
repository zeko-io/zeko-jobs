<?php
/**
 * LinkedIn OAuth integration for Zeko Jobs.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles LinkedIn OAuth 2.0 flow, profile import, and "Apply with LinkedIn".
 */
class Zeko_Jobs_Linkedin {

	/**
	 * Instance.
	 *
	 * @var ?Zeko_Jobs_Linkedin Instance.
	 */
	private static ?Zeko_Jobs_Linkedin $instance = null;

	/**
	 * Get singleton instance.
	 */
	public static function get_instance(): Zeko_Jobs_Linkedin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'wp_ajax_zeko_linkedin_connect', array( $this, 'handle_connect' ) );
		add_action( 'wp_ajax_zeko_linkedin_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'wp_ajax_zeko_linkedin_apply', array( $this, 'handle_apply_with_linkedin' ) );

		if ( isset( $_GET['zeko_linkedin_callback'] ) && '1' === $_GET['zeko_linkedin_callback'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only OAuth redirect-callback flag; the callback's state parameter carries and verifies its own nonce via wp_verify_nonce().
			add_action( 'init', array( $this, 'handle_callback' ) );
		}
	}

	/**
	 * Get LinkedIn OAuth settings.
	 */
	public static function get_settings(): array {
		return array(
			'client_id'     => get_option( 'zeko_linkedin_client_id', '' ),
			'client_secret' => get_option( 'zeko_linkedin_client_secret', '' ),
			'redirect_uri'  => home_url( '/?zeko_linkedin_callback=1' ),
		);
	}

	/**
	 * Check if LinkedIn is configured.
	 */
	public static function is_configured(): bool {
		$s = self::get_settings();
		return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] );
	}

	/**
	 * Get the LinkedIn authorization URL.
	 */
	public function get_auth_url(): string {
		$settings = self::get_settings();
		$state    = wp_create_nonce( 'zeko_linkedin_oauth' );

		return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query(
			array(
				'response_type' => 'code',
				'client_id'     => $settings['client_id'],
				'redirect_uri'  => $settings['redirect_uri'],
				'scope'         => 'openid profile email',
				'state'         => $state,
			)
		);
	}

	/**
	 * AJAX: Redirect to LinkedIn OAuth.
	 */
	public function handle_connect(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! self::is_configured() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'LinkedIn is not configured. Please ask an administrator to set up LinkedIn integration.', 'zeko-jobs' ) ) );
		}

		// Store a flag so we know to redirect after AJAX returns.
		update_user_meta( get_current_user_id(), 'zeko_linkedin_pending', true );

		wp_send_json_success( array( 'redirect' => $this->get_auth_url() ) );
	}

	/**
	 * Handle LinkedIn OAuth callback.
	 */
	public function handle_callback(): void {
		if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
			return;
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );

		if ( ! wp_verify_nonce( $state, 'zeko_linkedin_oauth' ) ) {
			wp_die( esc_html__( 'Invalid OAuth state.', 'zeko-jobs' ) );
		}

		$settings  = self::get_settings();
		$token_url = 'https://www.linkedin.com/oauth/v2/accessToken';

		$response = wp_remote_post(
			$token_url,
			array(
				'body' => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $settings['redirect_uri'],
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html__( 'LinkedIn authentication failed.', 'zeko-jobs' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			wp_die( esc_html__( 'Could not obtain LinkedIn access token.', 'zeko-jobs' ) );
		}

		$token = $body['access_token'];

		// Fetch user profile.
		$profile_resp = wp_remote_get(
			'https://api.linkedin.com/v2/userinfo',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $profile_resp ) ) {
			wp_die( esc_html__( 'Could not fetch LinkedIn profile.', 'zeko-jobs' ) );
		}

		$profile = json_decode( wp_remote_retrieve_body( $profile_resp ), true );

		// Store LinkedIn profile data.
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$linked_data = array(
				'linkedin_id'  => $profile['sub'] ?? '',
				'name'         => $profile['name'] ?? '',
				'given_name'   => $profile['given_name'] ?? '',
				'family_name'  => $profile['family_name'] ?? '',
				'email'        => $profile['email'] ?? '',
				'picture'      => $profile['picture'] ?? '',
				'headline'     => '', // Not available in OIDC but stored if present.
				'profile_url'  => 'https://www.linkedin.com/in/' . ( $profile['sub'] ?? '' ),
				'access_token' => $token,
				'connected_at' => current_time( 'mysql' ),
			);

			update_user_meta( $user_id, 'zeko_linkedin_profile', $linked_data );
			delete_user_meta( $user_id, 'zeko_linkedin_pending' );

			/**
			 * Fires after a user connects their LinkedIn account.
			 *
			 * @param int   $user_id The WordPress user ID.
			 * @param array $linked_data LinkedIn profile data.
			 */
			do_action( 'zeko_jobs_linkedin_connected', $user_id, $linked_data );
		}

		// Redirect back to dashboard.
		wp_safe_redirect( home_url( '/dashboard/?section=profile&linkedin=connected' ) );
		exit;
	}

	/**
	 * AJAX: Disconnect LinkedIn.
	 */
	public function handle_disconnect(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'zeko_linkedin_profile' );

		wp_send_json_success( array( 'message' => esc_html__( 'LinkedIn account disconnected.', 'zeko-jobs' ) ) );
	}

	/**
	 * Get stored LinkedIn profile for a user.
	 *
	 * @param int $user_id User id.
	 */
	public static function get_profile( int $user_id ): ?array {
		$data = get_user_meta( $user_id, 'zeko_linkedin_profile', true );
		return is_array( $data ) && ! empty( $data['linkedin_id'] ) ? $data : null;
	}

	/**
	 * Check if user has connected LinkedIn.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_connected( int $user_id ): bool {
		return null !== self::get_profile( $user_id );
	}

	/**
	 * AJAX: Apply with LinkedIn — pre-fills application from LinkedIn profile.
	 */
	public function handle_apply_with_linkedin(): void {
		check_ajax_referer( 'zeko_job_bookmark', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$profile = self::get_profile( $user_id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please connect your LinkedIn account first.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		// Return LinkedIn data for pre-filling.
		wp_send_json_success(
			array(
				'name'     => $profile['name'] ?? '',
				'email'    => $profile['email'] ?? '',
				'picture'  => $profile['picture'] ?? '',
				'linkedin' => $profile['profile_url'] ?? '',
			)
		);
	}
}
