<?php
/**
 * Email digest for Zeko Jobs.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Digest. */
final class Zeko_Jobs_Digest {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

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
	 * Init.
	 */
	public function init(): void {
		add_action( 'zeko_jobs_daily_digest', array( $this, 'send_daily_digest' ) );
		add_action( 'wp_ajax_zeko_job_send_digest', array( $this, 'handle_send_digest' ) );
	}

	/**
	 * Schedule digest.
	 */
	public function schedule_digest(): void {
		if ( ! wp_next_scheduled( 'zeko_jobs_daily_digest' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_jobs_daily_digest' );
		}
	}

	/**
	 * Send daily digest to users with saved search alerts.
	 */
	public function send_daily_digest(): void {
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$searches = $zeko_db->get_saved_searches_with_alerts();

		if ( empty( $searches ) ) {
			return;
		}

		foreach ( $searches as $search ) {
			$user_id = (int) $search['user_id'];
			$user    = get_userdata( $user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			$matching_jobs = $this->get_matching_jobs( $search );
			if ( empty( $matching_jobs ) ) {
				continue;
			}

			$subject = sprintf( 'New jobs matching "%s"', $search['name'] );
			$html    = $this->build_digest_email( $search, $matching_jobs );

			$this->renderer()->send_html( $user->user_email, $subject, $html, 'job_alert', 0, $user_id );

			$zeko_db->update_saved_search_sent( (int) $search['id'] );
		}
	}

	/**
	 * Matching jobs.
	 *
	 * @param array $search Search.
	 */
	private function get_matching_jobs( array $search ): array {
		return Zeko_Jobs_DB::get_instance()->get_jobs_matching_search( $search );
	}

	/**
	 * Build digest email HTML.
	 *
	 * @param array $search Search.
	 * @param array $jobs Jobs.
	 */
	private function build_digest_email( array $search, array $jobs ): string {
		$r = $this->renderer();

		$html  = $r->h2( sprintf( 'New jobs matching "%s"', $search['name'] ) );
		$html .= $r->p(
			sprintf(
			/* translators: 1: search name, 2: job count */
				__( 'Here are %2$d new job(s) matching your saved search "%1$s".', 'zeko-jobs' ),
				esc_html( $search['name'] ),
				count( $jobs )
			)
		);

		$html .= '<ul style="list-style:none;padding:0;margin:0 0 16px;">';
		foreach ( $jobs as $job ) {
			$url      = home_url( '/jobs/' . $job['slug'] . '/' );
			$featured = ! empty( $job['is_featured'] ) ? ' <span style="display:inline-block;padding:2px 8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle;">' . esc_html__( 'Featured', 'zeko-jobs' ) . '</span>' : '';
			$html    .= sprintf(
				'<li style="padding:8px 0;border-bottom:1px solid #e2e8f0;"><a href="%s" style="color:#4f46e5;font-weight:600;">%s</a>%s<br><span style="color:#64748b;font-size:13px;">%s (%s)</span></li>',
				esc_url( $url ),
				esc_html( $job['title'] ),
				$featured,
				esc_html( $job['location'] ),
				esc_html( ucfirst( $job['type'] ) )
			);
		}
		$html .= '</ul>';

		$html .= $r->button( home_url( '/jobs/' ), __( 'View All Jobs', 'zeko-jobs' ) );

		return $html;
	}

	/**
	 * AJAX: Manually trigger digest for current user.
	 */
	public function handle_send_digest(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$searches = $zeko_db->get_saved_searches( get_current_user_id() );

		if ( empty( $searches ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No saved searches to send.', 'zeko-jobs' ) ) );
		}

		$sent = 0;
		foreach ( $searches as $search ) {
			if ( empty( $search['email_alerts'] ) ) {
				continue;
			}

			$matching_jobs = $this->get_matching_jobs( $search );
			if ( empty( $matching_jobs ) ) {
				continue;
			}

			$user = get_userdata( (int) $search['user_id'] );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			$subject = sprintf( 'New jobs matching "%s"', $search['name'] );
			$html    = $this->build_digest_email( $search, $matching_jobs );

			$this->renderer()->send_html( $user->user_email, $subject, $html, 'job_alert', 0, (int) $user->ID );
			$zeko_db->update_saved_search_sent( (int) $search['id'] );
			++$sent;
		}

		if ( $sent > 0 ) {
			/* translators: %d: number of matching jobs sent */
			wp_send_json_success( array( 'message' => sprintf( esc_html__( 'Digest sent with %d matching jobs.', 'zeko-jobs' ), $sent ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'No new matching jobs found.', 'zeko-jobs' ) ) );
	}

	/**
	 * Renderer.
	 */
	private function renderer(): Zeko_Jobs_Email_Renderer {
		return Zeko_Jobs_Email_Renderer::get_instance();
	}
}
