<?php
/**
 * Job Alerts — cron-based email delivery for saved job alerts.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Alerts. */
final class Zeko_Jobs_Alerts {

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
	private function __construct() {}

	/**
	 * Init.
	 */
	public function init(): void {
		add_action( 'zeko_job_created', array( $this, 'on_job_created' ), 10, 3 );
		add_action( 'zeko_jobs_daily_alerts', array( $this, 'send_daily_digests' ) );
		add_action( 'zeko_jobs_weekly_alerts', array( $this, 'send_weekly_digests' ) );
	}

	/**
	 * Schedule events.
	 */
	public function schedule_events(): void {
		if ( ! wp_next_scheduled( 'zeko_jobs_daily_alerts' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'zeko_jobs_daily_alerts' );
		}
		if ( ! wp_next_scheduled( 'zeko_jobs_weekly_alerts' ) ) {
			wp_schedule_event( strtotime( 'next monday 08:00:00' ), 'weekly', 'zeko_jobs_weekly_alerts' );
		}
	}

	/**
	 * When a new job is created, fire instant alerts to matching users and
	 * notify company followers.
	 *
	 * @param int   $job_id Job id.
	 * @param int   $employer_id Employer id.
	 * @param array $job_data Job data.
	 */
	public function on_job_created( int $job_id, int $employer_id, array $job_data ): void {
		unset( $job_data );
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job( $job_id );

		// Only notify when the job is actually live.
		if ( empty( $job ) || 'publish' !== $job['status'] ) {
			return;
		}

		// Instant alerts (frequency = 'instant') to saved-search matches.
		$alerts = $zeko_db->get_active_alerts_by_frequency( 'instant' );
		foreach ( $alerts as $alert ) {
			$matches = $zeko_db->get_jobs_matching_alert( $alert['search_params'], 5 );
			$found   = false;
			foreach ( $matches as $m ) {
				if ( (int) $m['id'] === $job_id ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				continue;
			}

			$this->send_alert_email( $alert, array( $job ), 'instant' );
			$zeko_db->mark_alert_sent( $alert['id'] );
		}

		// Notify followers of the company about the new job.
		$company = $zeko_db->get_company_by_employer( $employer_id );
		$zeko_db->notify_company_followers(
			$employer_id,
			$job_id,
			$job['title'],
			! empty( $company['name'] ) ? $company['name'] : ( ! empty( $job['company_name'] ) ? $job['company_name'] : $job['title'] )
		);
	}

	/**
	 * Send daily digests.
	 */
	public function send_daily_digests(): void {
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$alerts  = $zeko_db->get_active_alerts_by_frequency( 'daily' );

		foreach ( $alerts as $alert ) {
			$jobs = $zeko_db->get_jobs_matching_alert( $alert['search_params'], 10 );
			if ( empty( $jobs ) ) {
				continue;
			}
			$this->send_alert_email( $alert, $jobs, 'daily' );
			$zeko_db->mark_alert_sent( $alert['id'] );
		}
	}

	/**
	 * Send weekly digests.
	 */
	public function send_weekly_digests(): void {
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$alerts  = $zeko_db->get_active_alerts_by_frequency( 'weekly' );

		foreach ( $alerts as $alert ) {
			$jobs = $zeko_db->get_jobs_matching_alert( $alert['search_params'], 20 );
			if ( empty( $jobs ) ) {
				continue;
			}
			$this->send_alert_email( $alert, $jobs, 'weekly' );
			$zeko_db->mark_alert_sent( $alert['id'] );
		}
	}

	/**
	 * Build and send an alert email.
	 *
	 * @param array  $alert Alert.
	 * @param array  $jobs Jobs.
	 * @param string $frequency Frequency.
	 */
	private function send_alert_email( array $alert, array $jobs, string $frequency ): void {
		$user = get_userdata( $alert['user_id'] );
		if ( ! $user ) {
			return;
		}

		$frequency_label = match ( $frequency ) {
			'instant' => __( 'New Job Match', 'zeko-jobs' ),
			'weekly'  => __( 'Weekly Job Digest', 'zeko-jobs' ),
			default   => __( 'Daily Job Digest', 'zeko-jobs' ),
		};

		$subject = sprintf( '[%s] %s — %s', get_bloginfo( 'name' ), $frequency_label, $alert['name'] );

		$r = $this->renderer();

		$html  = $r->h2( $subject );
		$html .= $r->p(
			sprintf(
			/* translators: 1: user name, 2: number of jobs found, 3: alert name */
				__( 'Hi %1$s, we found %2$d new job(s) matching your alert "%3$s".', 'zeko-jobs' ),
				esc_html( $user->display_name ),
				count( $jobs ),
				esc_html( $alert['name'] )
			)
		);

		$html .= '<ul style="list-style:none;padding:0;margin:0 0 16px;">';
		foreach ( $jobs as $job ) {
			$url    = home_url( '/jobs/' . ( $job['slug'] ?? $job['id'] ) );
			$salary = '';
			if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
				$salary = ' — ' . ( $job['salary_min'] ? '$' . number_format( $job['salary_min'] ) : '' )
					. ( $job['salary_min'] && $job['salary_max'] ? ' – ' : '' )
					. ( $job['salary_max'] ? '$' . number_format( $job['salary_max'] ) : '' );
			}
			$featured = ! empty( $job['is_featured'] ) ? ' <span style="display:inline-block;padding:2px 8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle;">' . esc_html__( 'Featured', 'zeko-jobs' ) . '</span>' : '';
			$html    .= sprintf(
				'<li style="padding:8px 0;border-bottom:1px solid #e2e8f0;"><a href="%s" style="color:#4f46e5;font-weight:600;">%s</a>%s<br><span style="color:#64748b;font-size:13px;">%s%s%s</span></li>',
				esc_url( $url ),
				esc_html( $job['title'] ),
				$featured,
				esc_html( $job['location'] ?? '' ),
				( $job['location'] ?? '' ) && ( $job['type'] ?? '' ) ? ' · ' : '',
				esc_html( ucfirst( $job['type'] ?? '' ) )
			);
		}
		$html .= '</ul>';

		$jobs_url = add_query_arg( 's', $alert['keyword'], home_url( '/jobs/' ) );
		$html    .= $r->button( $jobs_url, __( 'View All Matching Jobs', 'zeko-jobs' ) );

		$html .= $r->divider();
		$html .= '<p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;">' . sprintf(
			/* translators: %s: site name */
			esc_html__( 'You are receiving this because you have a job alert on %s. Manage your alerts in your dashboard.', 'zeko-jobs' ),
			esc_html( get_bloginfo( 'name' ) )
		) . '</p>';

		$r->send_html( $user->user_email, $subject, $html, 'job_alert', 0, (int) $user->ID );
	}

	/**
	 * Renderer.
	 */
	private function renderer(): Zeko_Jobs_Email_Renderer {
		return Zeko_Jobs_Email_Renderer::get_instance();
	}
}
