<?php
/**
 * Email notifications for Zeko Jobs.
 *
 * Sends transactional emails for job lifecycle events via the unified renderer.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Email. */
final class Zeko_Jobs_Email {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	private const TEXT_DOMAIN = 'zeko-jobs';

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Jobs_Email {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'zeko_job_application_submitted', array( $this, 'on_application_submitted' ), 10, 3 );
		add_action( 'zeko_job_application_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'zeko_job_application_withdrawn', array( $this, 'on_application_withdrawn' ), 10, 2 );
		add_action( 'zeko_job_interview_scheduled', array( $this, 'on_interview_scheduled' ), 10, 3 );
		add_action( 'zeko_job_expired', array( $this, 'on_job_expired' ), 10, 3 );
		add_action( 'user_register', array( $this, 'on_user_registered' ), 10, 1 );
	}

	/**
	 * Renderer.
	 */
	private function renderer(): Zeko_Jobs_Email_Renderer {
		return Zeko_Jobs_Email_Renderer::get_instance();
	}

	/**
	 * Enabled.
	 */
	private function is_enabled(): bool {
		return (bool) apply_filters( 'zeko_jobs_email_notifications_enabled', get_option( 'zeko_jobs_send_status_emails', 1 ) );
	}

	/**
	 * Notify employer when a new application is received.
	 *
	 * @param int $application_id Application id.
	 * @param int $job_id Job id.
	 * @param int $applicant_id Applicant id.
	 */
	public function on_application_submitted( int $application_id, int $job_id, int $applicant_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$db  = Zeko_Jobs_DB::get_instance();
		$job = $db->get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		$employer = get_userdata( (int) $job['employer_id'] );
		if ( ! $employer || empty( $employer->user_email ) ) {
			return;
		}

		$applicant      = get_userdata( $applicant_id );
		$applicant_name = $applicant ? $applicant->display_name : __( 'A candidate', 'zeko-jobs' );

		$subject = sprintf(
			/* translators: 1: applicant name, 2: job title */
			__( 'New Application: %1$s applied for "%2$s"', 'zeko-jobs' ),
			$applicant_name,
			$job['title']
		);

		$r = $this->renderer();

		/* translators: %s: recipient display name */
		$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $employer->display_name ) ) );
		$html .= $r->p(
			sprintf(
			/* translators: 1: applicant name, 2: job title */
				__( '%1$s has just applied for the "%2$s" position.', 'zeko-jobs' ),
				'<strong>' . esc_html( $applicant_name ) . '</strong>',
				'<strong>' . esc_html( $job['title'] ) . '</strong>'
			)
		);
		$html .= $r->button( home_url( '/job-dashboard/' ), __( 'View Application', 'zeko-jobs' ) );
		$html .= $r->p( __( 'Log in to your dashboard to review the application, update its status, or schedule an interview.', 'zeko-jobs' ) );

		$r->send(
			$employer->user_email,
			$subject,
			$html,
			'application_submitted',
			$application_id,
			(int) $job['employer_id']
		);
	}

	/**
	 * Notify applicant when their application status changes.
	 *
	 * @param int    $application_id Application id.
	 * @param string $new_status New status.
	 * @param int    $employer_id Employer id.
	 */
	public function on_status_changed( int $application_id, string $new_status, int $employer_id ): void {
		unset( $employer_id );
		if ( ! $this->is_enabled() ) {
			return;
		}

		$db  = Zeko_Jobs_DB::get_instance();
		$app = $db->get_application( $application_id );
		if ( ! $app ) {
			return;
		}

		$seeker = get_userdata( (int) $app['seeker_id'] );
		if ( ! $seeker || empty( $seeker->user_email ) ) {
			return;
		}

		$job = $db->get_job( (int) $app['job_id'] );
		if ( ! $job ) {
			return;
		}

		$status_labels = array(
			'awaiting_review' => __( 'Awaiting Review', 'zeko-jobs' ),
			'reviewed'        => __( 'Reviewed', 'zeko-jobs' ),
			'contacting'      => __( 'Employer is Contacting You', 'zeko-jobs' ),
			'interviewing'    => __( 'Interview Stage', 'zeko-jobs' ),
			'offered'         => __( 'You\'ve Been Offered the Position', 'zeko-jobs' ),
			'hired'           => __( 'Hired', 'zeko-jobs' ),
			'rejected'        => __( 'Not Selected', 'zeko-jobs' ),
			'archived'        => __( 'Archived', 'zeko-jobs' ),
			'withdrawn'       => __( 'Withdrawn', 'zeko-jobs' ),
		);

		$status_label = $status_labels[ $new_status ] ?? ucfirst( str_replace( '_', ' ', $new_status ) );

		$subject = sprintf(
			/* translators: 1: job title, 2: status */
			__( 'Application Update: "%1$s" — %2$s', 'zeko-jobs' ),
			$job['title'],
			$status_label
		);

		$r = $this->renderer();

		/* translators: %s: recipient display name */
		$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $seeker->display_name ) ) );
		$html .= $r->p(
			sprintf(
			/* translators: 1: job title, 2: status badge */
				__( 'Your application for "%1$s" has been updated to: %2$s', 'zeko-jobs' ),
				'<strong>' . esc_html( $job['title'] ) . '</strong>',
				$r->badge( $status_label )
			)
		);

		if ( in_array( $new_status, array( 'contacting', 'interviewing', 'offered', 'hired' ), true ) ) {
			$html .= $r->p( __( 'Great news! The employer is actively engaging with your application. Check your dashboard for next steps.', 'zeko-jobs' ) );
		}

		if ( 'rejected' === $new_status ) {
			$html .= $r->p( __( 'While this particular role wasn\'t the right fit, keep applying — the right opportunity is out there!', 'zeko-jobs' ) );
		}

		$html .= $r->button( home_url( '/job-dashboard/' ), __( 'View Your Dashboard', 'zeko-jobs' ) );

		$r->send(
			$seeker->user_email,
			$subject,
			$html,
			'status_changed',
			$application_id,
			(int) $app['seeker_id']
		);
	}

	/**
	 * Notify employer when an applicant withdraws.
	 *
	 * @param int $application_id Application id.
	 * @param int $seeker_id Seeker id.
	 */
	public function on_application_withdrawn( int $application_id, int $seeker_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$db  = Zeko_Jobs_DB::get_instance();
		$app = $db->get_application( $application_id );
		if ( ! $app ) {
			return;
		}

		$job = $db->get_job( (int) $app['job_id'] );
		if ( ! $job ) {
			return;
		}

		$employer = get_userdata( (int) $job['employer_id'] );
		if ( ! $employer || empty( $employer->user_email ) ) {
			return;
		}

		$seeker      = get_userdata( $seeker_id );
		$seeker_name = $seeker ? $seeker->display_name : __( 'A candidate', 'zeko-jobs' );

		$subject = sprintf(
			/* translators: 1: applicant name, 2: job title */
			__( 'Application Withdrawn: %1$s from "%2$s"', 'zeko-jobs' ),
			$seeker_name,
			$job['title']
		);

		$r = $this->renderer();

		/* translators: %s: recipient display name */
		$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $employer->display_name ) ) );
		$html .= $r->p(
			sprintf(
			/* translators: 1: applicant name, 2: job title */
				__( '%1$s has withdrawn their application for "%2$s".', 'zeko-jobs' ),
				'<strong>' . esc_html( $seeker_name ) . '</strong>',
				'<strong>' . esc_html( $job['title'] ) . '</strong>'
			)
		);

		$r->send(
			$employer->user_email,
			$subject,
			$html,
			'application_withdrawn',
			$application_id,
			(int) $job['employer_id']
		);
	}

	/**
	 * Notify both parties when an interview is scheduled.
	 *
	 * @param int $interview_id Interview id.
	 * @param int $application_id Application id.
	 * @param int $employer_id Employer id.
	 */
	public function on_interview_scheduled( int $interview_id, int $application_id, int $employer_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$db        = Zeko_Jobs_DB::get_instance();
		$interview = $db->get_interview_by_id( $interview_id );
		$app       = $db->get_application( $application_id );
		if ( ! $interview || ! $app ) {
			return;
		}

		$job = $db->get_job( (int) $app['job_id'] );
		if ( ! $job ) {
			return;
		}

		$seeker   = get_userdata( (int) $interview['seeker_id'] );
		$employer = get_userdata( $employer_id );
		$r        = $this->renderer();

		$scheduled_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $interview['scheduled_at'] ) );

		// Notify seeker.
		if ( $seeker && ! empty( $seeker->user_email ) ) {
			$subject = sprintf(
				/* translators: 1: job title, 2: date/time */
				__( 'Interview Scheduled: "%1$s" — %2$s', 'zeko-jobs' ),
				$job['title'],
				$scheduled_date
			);

			/* translators: %s: recipient display name */
			$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $seeker->display_name ) ) );
			$html .= $r->p(
				sprintf(
				/* translators: 1: job title, 2: date/time */
					__( 'An interview has been scheduled for your application to "%1$s". It will take place on %2$s.', 'zeko-jobs' ),
					'<strong>' . esc_html( $job['title'] ) . '</strong>',
					'<strong>' . esc_html( $scheduled_date ) . '</strong>'
				)
			);

			if ( ! empty( $interview['location'] ) ) {
				$html .= $r->label( __( 'Location', 'zeko-jobs' ), $interview['location'] );
			}
			if ( ! empty( $interview['meeting_url'] ) ) {
				$html .= $r->p(
					sprintf(
						'<a href="%s" style="color:%s;font-weight:600;">%s</a>',
						esc_url( $interview['meeting_url'] ),
						'#4f46e5',
						esc_html__( 'Join Video Call', 'zeko-jobs' )
					)
				);
			}
			if ( ! empty( $interview['notes'] ) ) {
				$html .= $r->label( __( 'Notes', 'zeko-jobs' ), $interview['notes'] );
			}

			$html .= $r->p( __( 'Don\'t forget to add this to your calendar!', 'zeko-jobs' ) );

			$r->send(
				$seeker->user_email,
				$subject,
				$html,
				'interview_scheduled',
				$application_id,
				(int) $app['seeker_id']
			);
		}

		// Notify employer.
		if ( $employer && ! empty( $employer->user_email ) ) {
			$subject = sprintf(
				/* translators: 1: applicant name, 2: date/time */
				__( 'Interview Scheduled: %1$s — %2$s', 'zeko-jobs' ),
				$seeker ? $seeker->display_name : __( 'Candidate', 'zeko-jobs' ),
				$scheduled_date
			);

			/* translators: %s: recipient display name */
			$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $employer->display_name ) ) );
			$html .= $r->p(
				sprintf(
				/* translators: 1: applicant name, 2: job title, 3: date/time */
					__( 'You have scheduled an interview with %1$s for the "%2$s" position on %3$s.', 'zeko-jobs' ),
					'<strong>' . esc_html( $seeker->display_name ?? __( 'Candidate', 'zeko-jobs' ) ) . '</strong>',
					'<strong>' . esc_html( $job['title'] ) . '</strong>',
					'<strong>' . esc_html( $scheduled_date ) . '</strong>'
				)
			);

			$r->send(
				$employer->user_email,
				$subject,
				$html,
				'interview_scheduled',
				$application_id,
				(int) $job['employer_id']
			);
		}
	}

	/**
	 * Notify employer when their job listing expires.
	 *
	 * @param int    $job_id Job id.
	 * @param int    $employer_id Employer id.
	 * @param string $title Title.
	 */
	public function on_job_expired( int $job_id, int $employer_id, string $title ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$user = get_userdata( $employer_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: job title */
			__( 'Your job "%s" has expired', 'zeko-jobs' ),
			$title
		);

		$r = $this->renderer();

		/* translators: %s: recipient display name */
		$html  = $r->p( sprintf( __( 'Hi %s,', 'zeko-jobs' ), esc_html( $user->display_name ) ) );
		$html .= $r->p(
			sprintf(
			/* translators: %s: job title */
				__( 'Your job listing "%s" has expired and is no longer visible in search results.', 'zeko-jobs' ),
				'<strong>' . esc_html( $title ) . '</strong>'
			)
		);
		$html .= $r->p( __( 'To continue receiving applications, please post a new listing or contact our support team.', 'zeko-jobs' ) );
		$html .= $r->button( home_url( '/jobs/dashboard/' ), __( 'Go to Dashboard', 'zeko-jobs' ) );

		$r->send(
			$user->user_email,
			$subject,
			$html,
			'job_expired',
			0,
			$employer_id
		);
	}

	/**
	 * Send a welcome email to newly registered users.
	 * Introduces the site and points to the actions the new member can take
	 * across every active module.
	 *
	 * @param int $user_id User id.
	 */
	public function on_user_registered( int $user_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Welcome to %s!', 'zeko-jobs' ),
			get_bloginfo( 'name' )
		);

		$r = $this->renderer();

		$html = $r->p(
			sprintf(
			/* translators: 1: user name, 2: site name */
				__( 'Hi %1$s, welcome to %2$s!', 'zeko-jobs' ),
				esc_html( $user->display_name ),
				esc_html( get_bloginfo( 'name' ) )
			)
		);
		$html .= $r->p( __( 'You\'re all set up. Before you dive in, here\'s a quick tour of everything you can do — all with one account.', 'zeko-jobs' ) );

		$html .= $r->button(
			$this->module_page_url( 'zeko', 'dashboard' ),
			__( 'Go to Your Dashboard', 'zeko-jobs' )
		);
		$html .= $r->p( __( 'Your dashboard is the starting point: finish your profile, then jump into whatever catches your eye below.', 'zeko-jobs' ) );

		$html .= $r->divider();
		$html .= $r->h2( __( 'What you can do here', 'zeko-jobs' ) );
		$html .= $this->welcome_module_cards();

		$html .= $r->divider();
		$html .= $r->p( __( 'One account, every module. Need a hand? Reply to this email any time, or ask the AI assistant on the site — it knows the whole place.', 'zeko-jobs' ) );

		$r->send(
			$user->user_email,
			$subject,
			$html,
			'welcome',
			0,
			$user_id
		);
	}

	/**
	 * Resolve a logical module page URL through the ecosystem page registry,
	 * degrading to a best-effort permalink when Core/theme helpers are absent.
	 *
	 * @return string Page URL.
	 * @param string $module Module key.
	 * @param string $slug Logical page slug.
	 */
	private function module_page_url( string $module, string $slug ): string {
		if ( function_exists( 'zeko_get_page_url' ) ) {
			return zeko_get_page_url( $module, $slug );
		}
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			return Zeko_Core_Helpers::get_instance()->get_page_url( $module, $slug );
		}
		return home_url( '/' . sanitize_title( $slug ) . '/' );
	}

	/**
	 * Build the welcome email's ecosystem card grid.
	 * Only modules whose plugin is active are shown, keyed by their main class.
	 *
	 * @return string HTML card grid.
	 */
	private function welcome_module_cards(): string {
		$modules = array(
			array( 'jobs', 'jobs', __( 'Jobs', 'zeko-jobs' ), __( 'Browse openings, apply in seconds, and track every application from one dashboard.', 'zeko-jobs' ), 'Zeko_Jobs' ),
			array( 'qa', 'questions', __( 'Q&A', 'zeko-jobs' ), __( 'Ask anything and get helpful answers from the community.', 'zeko-jobs' ), 'Zeko_QA' ),
			array( 'learn', 'courses', __( 'Learning', 'zeko-jobs' ), __( 'Take courses, pass quizzes, and earn certificates worth sharing.', 'zeko-jobs' ), 'Zeko_Learn' ),
			array( 'mentor', 'mentors', __( 'Mentorship', 'zeko-jobs' ), __( 'Book 1:1 sessions with mentors who have been where you are.', 'zeko-jobs' ), 'Zeko_Mentor' ),
			array( 'freelance', 'freelance', __( 'Freelance', 'zeko-jobs' ), __( 'Post a project or bid on one — get paid milestone by milestone.', 'zeko-jobs' ), 'Zeko_Freelance' ),
			array( 'shop', 'shop', __( 'Marketplace', 'zeko-jobs' ), __( 'Discover products and digital goods made by people on the site.', 'zeko-jobs' ), 'Zeko_Shop' ),
			array( 'love', 'dating-matches', __( 'Dating', 'zeko-jobs' ), __( 'Set up your profile and connect with people who share your interests.', 'zeko-jobs' ), 'Zeko_Love' ),
			array( 'rewards', 'rewards', __( 'Rewards', 'zeko-jobs' ), __( 'Earn points for activity and unlock perks, badges, and tiers.', 'zeko-jobs' ), 'Zeko_Rewards' ),
			array( 'wallet', 'wallet', __( 'Wallet & Pay', 'zeko-jobs' ), __( 'Top up, send money to members, and manage your payouts.', 'zeko-jobs' ), 'Zeko_Pay' ),
			array( 'ai', 'ai-search', __( 'AI Assistant', 'zeko-jobs' ), __( 'Ask your AI assistant anything — it knows the whole site.', 'zeko-jobs' ), 'Zeko_AI' ),
		);

		$cards       = array();
		$explore     = __( 'Explore', 'zeko-jobs' );
		$card_color  = '#4f46e5';
		$border_hint = '#e2e8f0';

		foreach ( $modules as $module ) {
			if ( ! class_exists( $module[4] ) ) {
				continue;
			}

			$cards[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . $border_hint . ';border-radius:10px;margin-bottom:16px;">'
				. '<tr><td style="padding:16px 18px;">'
				. '<h3 style="margin:0 0 6px;font-size:15px;font-weight:700;color:#1e293b;">' . esc_html( $module[2] ) . '</h3>'
				. '<p style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#475569;">' . esc_html( $module[3] ) . '</p>'
				. '<a href="' . esc_url( $this->module_page_url( $module[0], $module[1] ) ) . '" style="font-size:13px;font-weight:600;color:' . $card_color . ';text-decoration:none;">' . esc_html( $explore ) . ' &rarr;</a>'
				. '</td></tr></table>';
		}

		if ( empty( $cards ) ) {
			return '';
		}

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
		$rows = array_chunk( $cards, 2 );
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $row as $i => $card ) {
				$cell_style = ( 0 === $i ) ? 'vertical-align:top;padding:0 8px 0 0;' : 'vertical-align:top;padding:0 0 0 8px;';
				$html     .= '<td width="50%" style="' . $cell_style . '">' . $card . '</td>';
			}
			if ( 1 === count( $row ) ) {
				$html .= '<td width="50%" style="vertical-align:top;padding:0 0 0 8px;"></td>';
			}
			$html .= '</tr>';
		}
		$html .= '</table>';

		return $html;
	}
}
