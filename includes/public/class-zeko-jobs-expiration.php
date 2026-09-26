<?php
/**
 * Job expiration system for Zeko Jobs.
 *
 * Handles auto-closing expired jobs and sending expiry reminders.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Expiration. */
final class Zeko_Jobs_Expiration {

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
		add_action( 'zeko_jobs_check_expirations', array( $this, 'process_expirations' ) );
		add_action( 'zeko_jobs_expiry_reminders', array( $this, 'send_expiry_reminders' ) );
		add_action( 'zeko_jobs_interview_reminders', array( $this, 'send_interview_reminders' ) );
	}

	/**
	 * Schedule events.
	 */
	public function schedule_events(): void {
		if ( ! wp_next_scheduled( 'zeko_jobs_check_expirations' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_jobs_check_expirations' );
		}
		if ( ! wp_next_scheduled( 'zeko_jobs_expiry_reminders' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_jobs_expiry_reminders' );
		}
		if ( ! wp_next_scheduled( 'zeko_jobs_interview_reminders' ) ) {
			wp_schedule_event( time(), 'hourly', 'zeko_jobs_interview_reminders' );
		}
	}

	/**
	 * Close jobs that have passed their expires_at date.
	 */
	public function process_expirations(): void {
		$zeko_db      = Zeko_Jobs_DB::get_instance();
		$expired_jobs = $zeko_db->get_expired_jobs();

		if ( empty( $expired_jobs ) ) {
			return;
		}

		foreach ( $expired_jobs as $job ) {
			$zeko_db->expire_job( (int) $job['id'] );
			do_action( 'zeko_job_expired', (int) $job['id'], (int) $job['employer_id'], $job['title'] );
		}
	}

	/**
	 * Send reminders to employers for jobs expiring soon.
	 */
	public function send_expiry_reminders(): void {
		$zeko_db       = Zeko_Jobs_DB::get_instance();
		$reminder_days = (int) ( get_option( 'zeko_jobs_settings', array() )['expiry_reminder_days'] ?? 7 );
		$reminder_date = gmdate( 'Y-m-d H:i:s', strtotime( "+{$reminder_days} days" ) );
		$now           = current_time( 'mysql' );

		$expiring_jobs = $zeko_db->get_jobs_expiring_soon( $now, $reminder_date );

		if ( empty( $expiring_jobs ) ) {
			return;
		}

		$reminder_sent_key = 'zeko_jobs_expiry_reminder_sent_';

		foreach ( $expiring_jobs as $job ) {
			$reminder_key = $reminder_sent_key . $job['id'] . '_' . gmdate( 'Y-m-d' );
			if ( get_transient( $reminder_key ) ) {
				continue;
			}

			$this->send_reminder_email( $job );
			set_transient( $reminder_key, true, DAY_IN_SECONDS );
		}
	}

	/**
	 * Send a single expiry reminder email.
	 *
	 * @param array $job Job.
	 */
	private function send_reminder_email( array $job ): bool {
		$user = get_userdata( (int) $job['employer_id'] );
		if ( ! $user ) {
			return false;
		}

		$days_left   = max( 0, (int) ( ( strtotime( $job['expires_at'] ) - strtotime( current_time( 'mysql' ) ) ) / DAY_IN_SECONDS ) );
		$expiry_date = date_i18n( get_option( 'date_format' ), strtotime( $job['expires_at'] ) );

		$subject = sprintf(
			/* translators: 1: job title, 2: number of days */
			_n( 'Your job "%1$s" expires in %2$d day', 'Your job "%1$s" expires in %2$d days', $days_left, 'zeko-jobs' ),
			$job['title'],
			$days_left
		);

		$r = $this->renderer();

		/* translators: %s: recipient display name */
		$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $user->display_name ) ) );
		$html .= $r->p(
			sprintf(
			/* translators: 1: job title, 2: expiry date */
				__( 'Your job listing "%1$s" is expiring on %2$s.', 'zeko-jobs' ),
				'<strong>' . esc_html( $job['title'] ) . '</strong>',
				esc_html( $expiry_date )
			)
		);
		$html .= $r->p( __( 'To keep your listing active, please renew it before it expires.', 'zeko-jobs' ) );
		$html .= $r->button(
			add_query_arg( array( 'renew' => $job['id'] ), home_url( '/jobs/dashboard/' ) ),
			__( 'Renew Now', 'zeko-jobs' )
		);
		$html .= '<p style="margin:0 0 16px;"><a href="' . esc_url( home_url( '/jobs/' . $job['slug'] ) ) . '">' . esc_html__( 'View Job Listing', 'zeko-jobs' ) . '</a></p>';
		$html .= $r->p( __( 'If you don\'t renew, the job will be automatically closed and hidden from search results.', 'zeko-jobs' ) );

		return $r->send_html(
			$user->user_email,
			$subject,
			$html,
			'expiry_reminder',
			0,
			(int) $job['employer_id']
		);
	}

	/**
	 * Send interview reminder emails (24h and 1h before).
	 */
	public function send_interview_reminders(): void {
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$upcoming = $zeko_db->get_upcoming_interviews();

		if ( ! $upcoming ) {
			return;
		}

		$r    = $this->renderer();
		$now  = time();
		$seen = array();

		foreach ( $upcoming as $interview ) {
			$interview_time = strtotime( $interview['scheduled_at'] );
			$hours_until    = ( $interview_time - $now ) / 3600;
			$reminder_type  = ( $hours_until <= 1.5 ) ? '1h' : '24h';

			$dedup_key = (int) $interview['seeker_id'] . '_' . $reminder_type . '_' . gmdate( 'Y-m-d', $interview_time );
			if ( isset( $seen[ $dedup_key ] ) ) {
				continue;
			}

			$already_sent = $zeko_db->count_reminder_sent(
				(int) $interview['seeker_id'],
				$reminder_type,
				'%' . $interview['id'] . '%'
			);

			if ( $already_sent ) {
				continue;
			}

			$seen[ $dedup_key ] = true;

			$time_str = date_i18n( get_option( 'time_format' ), $interview_time );
			$date_str = date_i18n( get_option( 'date_format' ), $interview_time );

			if ( '24h' === $reminder_type ) {
				$subject = sprintf(
					/* translators: 1: job title, 2: interview date */
					__( 'Reminder: Interview for %1$s tomorrow at %2$s', 'zeko-jobs' ),
					$interview['job_title'],
					$time_str
				);
				/* translators: %s: recipient display name */
				$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $interview['seeker_name'] ) ) );
				$html .= $r->p(
					sprintf(
					/* translators: 1: job title */
						__( 'This is a friendly reminder that you have an interview scheduled for the %s position.', 'zeko-jobs' ),
						'<strong>' . esc_html( $interview['job_title'] ) . '</strong>'
					)
				);
			} else {
				$subject = sprintf(
					/* translators: %s: job title */
					__( 'Interview starting soon: %s', 'zeko-jobs' ),
					$interview['job_title']
				);
				/* translators: %s: recipient display name */
				$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $interview['seeker_name'] ) ) );
				$html .= $r->p(
					sprintf(
					/* translators: 1: job title */
						__( 'Your interview for %s starts in about an hour.', 'zeko-jobs' ),
						'<strong>' . esc_html( $interview['job_title'] ) . '</strong>'
					)
				);
			}

			$html .= $r->label( __( 'Date', 'zeko-jobs' ), $date_str );
			$html .= $r->label( __( 'Time', 'zeko-jobs' ), $time_str );
			$html .= $r->label( __( 'Type', 'zeko-jobs' ), ucfirst( $interview['type'] ) );

			if ( ! empty( $interview['meeting_url'] ) ) {
				$html .= $r->p(
					sprintf(
						'<a href="%s" style="color:#4f46e5;font-weight:600;">%s</a>',
						esc_url( $interview['meeting_url'] ),
						esc_html__( 'Join Video Call', 'zeko-jobs' )
					)
				);
			}

			if ( '1h' === $reminder_type ) {
				$html .= $r->p( __( 'Make sure you\'re ready!', 'zeko-jobs' ) );
			} else {
				$html .= $r->p( __( 'Good luck!', 'zeko-jobs' ) );
			}

			$r->send_html(
				$interview['seeker_email'],
				$subject,
				$html,
				'interview_reminder',
				0,
				(int) $interview['seeker_id']
			);

			$zeko_db->insert_reminder(
				array(
					'user_id'       => $interview['seeker_id'],
					'reminder_type' => 'interview_' . $reminder_type,
					'scheduled_at'  => $interview['scheduled_at'],
					'sent_at'       => current_time( 'mysql' ),
					'note'          => 'Interview #' . $interview['id'] . ' reminder',
				)
			);
		}
	}

	/**
	 * Renderer.
	 */
	private function renderer(): Zeko_Jobs_Email_Renderer {
		return Zeko_Jobs_Email_Renderer::get_instance();
	}
}
