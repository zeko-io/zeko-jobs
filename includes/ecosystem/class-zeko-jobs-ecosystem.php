<?php
/**
 * Ecosystem integration for Zeko Jobs.
 *
 * Connects the jobs module to the Zeko theme dashboard, activity feed,
 * and user profile sections — following the pattern established by
 * Zeko Learn and Zeko QA ecosystem classes.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Ecosystem. */
final class Zeko_Jobs_Ecosystem {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Jobs_Ecosystem {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// Dashboard tab integration.
		add_filter( 'zeko_dashboard_tabs', array( $this, 'add_dashboard_tab' ) );
		add_action( 'zeko_dashboard_tab_content_jobs', array( $this, 'render_dashboard_tab' ) );

		// Activity feed integration.
		add_filter( 'zeko_activity_feed_items', array( $this, 'inject_activity_items' ) );

		// Profile section integration.
		add_action( 'zeko_profile_view_sections', array( $this, 'render_profile_section' ) );
		add_action( 'zeko_theme_profile_stats', array( $this, 'render_profile_stats' ) );

		// Payment integration hooks (fire events that Zeko-Pay listens to).
		add_action( 'zeko_job_created', array( $this, 'on_job_created' ), 10, 3 );
		add_action( 'zeko_job_featured_toggled', array( $this, 'on_job_featured_toggled' ), 10, 2 );

		// Nav items registry.
		add_filter( 'zeko_nav_items', array( $this, 'register_nav_items' ) );

		// Notification source registry.
		add_filter( 'zeko_register_notification_sources', array( $this, 'register_notification_source' ) );
	}

	/**
	 * Register Jobs as a notification source for the core bell.
	 *
	 * @param array $sources Sources.
	 */
	public function register_notification_source( array $sources ): array {
		global $wpdb;
		$sources['jobs'] = array(
			'table'       => $wpdb->prefix . 'zeko_job_notifications',
			'type_column' => 'type',
			'has_title'   => true,
			'has_message' => true,
			'icon'        => 'briefcase',
			'label'       => __( 'Jobs', 'zeko-jobs' ),
		);
		return $sources;
	}

	/**
	 * Register the "Jobs" tab in the Zeko dashboard.
	 *
	 * @return array Modified tabs.
	 * @param array $tabs Existing dashboard tabs.
	 */
	public function add_dashboard_tab( array $tabs ): array {
		$tabs['jobs'] = __( 'My Jobs', 'zeko-jobs' );
		return $tabs;
	}

	/**
	 * Register Jobs nav items via the core registry.
	 *
	 * @return array
	 * @param array $locations keyed by location slug.
	 */
	public function register_nav_items( array $locations ): array {
		$locations['primary'][] = array(
			'title'    => __( 'Jobs', 'zeko-jobs' ),
			'url'      => home_url( '/jobs/' ),
			'order'    => 3,
			'children' => array(
				array(
					'title' => __( 'Browse Jobs', 'zeko-jobs' ),
					'url'   => home_url( '/jobs/' ),
				),
				array(
					'title' => __( 'Post a Job', 'zeko-jobs' ),
					'url'   => home_url( '/post-a-job/' ),
				),
				array(
					'title' => __( 'My Dashboard', 'zeko-jobs' ),
					'url'   => home_url( '/job-dashboard/' ),
				),
			),
		);
		$locations['footer'][]  = array(
			'title' => __( 'Jobs', 'zeko-jobs' ),
			'url'   => home_url( '/jobs/' ),
			'order' => 3,
		);
		return $locations;
	}

	/**
	 * Render the Jobs dashboard tab content.
	 */
	public function render_dashboard_tab(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id   = get_current_user_id();
		$plugin    = Zeko_Jobs::get_instance();
		$user_role = $plugin->get_user_job_role( $user_id );
		$db        = Zeko_Jobs_DB::get_instance();

		if ( 'employer' === $user_role ) {
			$this->render_employer_tab( $user_id, $db );
		} else {
			$this->render_seeker_tab( $user_id, $db );
		}
	}

	/**
	 * Render employer dashboard tab content.
	 *
	 * @param int          $user_id User id.
	 * @param Zeko_Jobs_DB $db Db.
	 */
	private function render_employer_tab( int $user_id, Zeko_Jobs_DB $db ): void {
		$job_count   = $db->count_jobs_by_employer( $user_id );
		$app_count   = (int) $db->count_applications_for_employer( $user_id );
		$recent_apps = $db->get_recent_applications_for_employer( $user_id, 5 );

		?>
		<div class="zeko-jobs-ecosystem-tab">
			<div class="zeko-jobs-stats-row" style="display:flex;gap:16px;margin-bottom:20px;">
				<div class="zeko-stat-card" style="flex:1;padding:16px;background:#f0f9ff;border-radius:8px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#2563eb;"><?php echo esc_html( $job_count ); ?></div>
					<div style="color:#64748b;font-size:13px;"><?php esc_html_e( 'Active Jobs', 'zeko-jobs' ); ?></div>
				</div>
				<div class="zeko-stat-card" style="flex:1;padding:16px;background:#f0fdf4;border-radius:8px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#16a34a;"><?php echo esc_html( $app_count ); ?></div>
					<div style="color:#64748b;font-size:13px;"><?php esc_html_e( 'Total Applications', 'zeko-jobs' ); ?></div>
				</div>
			</div>

			<h3 style="margin-bottom:12px;"><?php esc_html_e( 'Recent Applications', 'zeko-jobs' ); ?></h3>
			<?php if ( empty( $recent_apps ) ) : ?>
				<p style="color:#94a3b8;"><?php esc_html_e( 'No applications received yet.', 'zeko-jobs' ); ?></p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;">
					<thead>
						<tr style="border-bottom:2px solid #e2e8f0;text-align:left;">
							<th style="padding:8px;"><?php esc_html_e( 'Applicant', 'zeko-jobs' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Job', 'zeko-jobs' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Status', 'zeko-jobs' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Applied', 'zeko-jobs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_apps as $app ) : ?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:8px;"><?php echo esc_html( $app['applicant_name'] ); ?></td>
								<td style="padding:8px;"><?php echo esc_html( $app['job_title'] ); ?></td>
								<td style="padding:8px;">
									<span style="padding:2px 8px;border-radius:12px;font-size:12px;background:#dbeafe;color:#1d4ed8;">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $app['status'] ) ) ); ?>
									</span>
								</td>
								<td style="padding:8px;color:#64748b;font-size:13px;">
									<?php echo esc_html( human_time_diff( strtotime( $app['applied_at'] ), time() ) . ' ago' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:16px;">
				<a href="<?php echo esc_url( home_url( '/job-dashboard/' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Job Dashboard', 'zeko-jobs' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render seeker dashboard tab content.
	 *
	 * @param int          $user_id User id.
	 * @param Zeko_Jobs_DB $db Db.
	 */
	private function render_seeker_tab( int $user_id, Zeko_Jobs_DB $db ): void {
		$app_count       = $db->count_applications_for_seeker( $user_id );
		$interview_count = $db->count_upcoming_interviews_for_seeker( $user_id );

		$bookmarks   = get_user_meta( $user_id, 'zeko_bookmarked_jobs', true );
		$saved_count = is_array( $bookmarks ) ? count( $bookmarks ) : 0;

		$recent_apps = $db->get_recent_applications_for_seeker( $user_id, 5 );

		?>
		<div class="zeko-jobs-ecosystem-tab">
			<div class="zeko-jobs-stats-row" style="display:flex;gap:16px;margin-bottom:20px;">
				<div class="zeko-stat-card" style="flex:1;padding:16px;background:#f0f9ff;border-radius:8px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#2563eb;"><?php echo esc_html( $app_count ); ?></div>
					<div style="color:#64748b;font-size:13px;"><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></div>
				</div>
				<div class="zeko-stat-card" style="flex:1;padding:16px;background:#fefce8;border-radius:8px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#ca8a04;"><?php echo esc_html( $interview_count ); ?></div>
					<div style="color:#64748b;font-size:13px;"><?php esc_html_e( 'Interviews', 'zeko-jobs' ); ?></div>
				</div>
				<div class="zeko-stat-card" style="flex:1;padding:16px;background:#fdf2f8;border-radius:8px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#db2777;"><?php echo esc_html( $saved_count ); ?></div>
					<div style="color:#64748b;font-size:13px;"><?php esc_html_e( 'Saved Jobs', 'zeko-jobs' ); ?></div>
				</div>
			</div>

			<h3 style="margin-bottom:12px;"><?php esc_html_e( 'Recent Applications', 'zeko-jobs' ); ?></h3>
			<?php if ( empty( $recent_apps ) ) : ?>
				<p style="color:#94a3b8;"><?php esc_html_e( 'You haven\'t applied to any jobs yet.', 'zeko-jobs' ); ?></p>
				<a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Browse Jobs', 'zeko-jobs' ); ?>
				</a>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;">
					<thead>
						<tr style="border-bottom:2px solid #e2e8f0;text-align:left;">
							<th style="padding:8px;"><?php esc_html_e( 'Job', 'zeko-jobs' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Status', 'zeko-jobs' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Applied', 'zeko-jobs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_apps as $app ) : ?>
							<tr style="border-bottom:1px solid #f1f5f9;">
								<td style="padding:8px;">
									<a href="<?php echo esc_url( home_url( '/jobs/' . $app['job_slug'] . '/' ) ); ?>">
										<?php echo esc_html( $app['job_title'] ); ?>
									</a>
								</td>
								<td style="padding:8px;">
									<span style="padding:2px 8px;border-radius:12px;font-size:12px;background:#dbeafe;color:#1d4ed8;">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $app['status'] ) ) ); ?>
									</span>
								</td>
								<td style="padding:8px;color:#64748b;font-size:13px;">
									<?php echo esc_html( human_time_diff( strtotime( $app['applied_at'] ), time() ) . ' ago' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:16px;">
				<a href="<?php echo esc_url( home_url( '/job-dashboard/' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Dashboard', 'zeko-jobs' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Inject job activities into the global activity feed.
	 *
	 * @return array Merged items including job activities.
	 * @param array $items Existing activity items.
	 */
	public function inject_activity_items( array $items ): array {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$user_id        = get_current_user_id();
		$db             = Zeko_Jobs_DB::get_instance();
		$job_activities = $db->get_job_activities_for_feed( $user_id );

		foreach ( $job_activities as $activity ) {
			$link = '';
			if ( ! empty( $activity['activity_item_id'] ) && in_array( $activity['activity_type'], array( 'job_posted', 'job_updated' ), true ) ) {
				$db   = Zeko_Jobs_DB::get_instance();
				$job  = $db->get_job( (int) $activity['activity_item_id'] );
				$link = $job ? home_url( '/jobs/' . $job['slug'] . '/' ) : '';
			} elseif ( 'job_applied' === $activity['activity_type'] && ! empty( $activity['activity_item_id'] ) ) {
				$db   = Zeko_Jobs_DB::get_instance();
				$job  = $db->get_job( (int) $activity['activity_item_id'] );
				$link = $job ? home_url( '/jobs/' . $job['slug'] . '/' ) : '';
			}

			$items[] = array(
				'module'    => 'zeko-jobs',
				'action'    => $activity['activity_type'],
				'message'   => $activity['activity_content'],
				'timestamp' => $activity['activity_date'],
				'link'      => $link,
			);
		}

		return $items;
	}

	/**
	 * Render jobs section on user profile.
	 */
	public function render_profile_section(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id   = get_queried_object_id() ?: get_current_user_id();
		$plugin    = Zeko_Jobs::get_instance();
		$user_role = $plugin->get_user_job_role( $user_id );
		$db        = Zeko_Jobs_DB::get_instance();

		if ( 'employer' === $user_role ) {
			$this->render_employer_profile_section( $user_id, $db );
		} elseif ( 'seeker' === $user_role ) {
			$this->render_seeker_profile_section( $user_id, $db );
		}
	}

	/**
	 * Render employer profile section.
	 *
	 * @param int          $user_id User id.
	 * @param Zeko_Jobs_DB $db Db.
	 */
	private function render_employer_profile_section( int $user_id, Zeko_Jobs_DB $db ): void {
		$jobs = $db->get_jobs(
			array(
				'status' => 'publish',
				'limit'  => 5,
			)
		);
		if ( empty( $jobs ) ) {
			return;
		}
		?>
		<div class="zeko-jobs-profile-section" style="margin-top:20px;">
			<h3><?php esc_html_e( 'Job Listings', 'zeko-jobs' ); ?></h3>
			<ul style="list-style:none;padding:0;">
				<?php foreach ( $jobs as $job ) : ?>
					<li style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
						<a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] . '/' ) ); ?>">
							<?php echo esc_html( $job['title'] ); ?>
						</a>
						<span style="color:#94a3b8;font-size:13px;"> — <?php echo esc_html( $job['location'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render seeker profile section.
	 *
	 * @param int          $user_id User id.
	 * @param Zeko_Jobs_DB $db Db.
	 */
	private function render_seeker_profile_section( int $user_id, Zeko_Jobs_DB $db ): void {
		$plugin     = Zeko_Jobs::get_instance();
		$resume_url = $plugin->get_user_resume( $user_id );
		if ( ! empty( $resume_url ) ) {
			?>
			<div class="zeko-jobs-profile-section" style="margin-top:20px;">
				<h3><?php esc_html_e( 'Resume', 'zeko-jobs' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( $resume_url ); ?>" target="_blank" rel="noopener" class="button button-secondary">
						<?php esc_html_e( 'View Resume', 'zeko-jobs' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		$this->render_learn_profile_sections( $user_id, $db );
	}

	/**
	 * Render the Zeko-Learn powered skills, certifications and course
	 * recommendation sections on a seeker profile (fail-soft when Learn is off).
	 *
	 * @param int          $user_id User id.
	 * @param Zeko_Jobs_DB $db Db.
	 */
	private function render_learn_profile_sections( int $user_id, Zeko_Jobs_DB $db ): void {
		$skills = $db->get_user_learn_skills( $user_id );
		$certs  = $db->get_user_learn_certificates( $user_id );

		if ( ! empty( $skills ) ) {
			?>
			<div class="zeko-jobs-profile-section" style="margin-top:20px;">
				<h3><?php esc_html_e( 'Skills', 'zeko-jobs' ); ?></h3>
				<div class="zeko-skill-tags">
					<?php foreach ( array_slice( $skills, 0, 25 ) as $skill ) : ?>
						<span class="zeko-skill-tag"><?php echo esc_html( $skill ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		if ( ! empty( $certs ) ) {
			?>
			<div class="zeko-jobs-profile-section" style="margin-top:20px;">
				<h3><?php esc_html_e( 'Certifications', 'zeko-jobs' ); ?></h3>
				<ul style="list-style:none;padding:0;margin:0;">
					<?php foreach ( $certs as $cert ) : ?>
						<li style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
							<strong><?php echo esc_html( $cert['course_title'] ?? __( 'Certificate', 'zeko-jobs' ) ); ?></strong>
							<?php if ( ! empty( $cert['certificate_number'] ) ) : ?>
								<span style="color:#94a3b8;font-size:13px;"> — <?php echo esc_html( $cert['certificate_number'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $cert['issued_at'] ) ) : ?>
								<div style="color:#94a3b8;font-size:13px;"><?php /* translators: %s: certificate issue date */ printf( esc_html__( 'Issued %s', 'zeko-jobs' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $cert['issued_at'] ) ) ) ); ?></div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		// Course recommendations based on the user's saved searches / applications.
		// (learning-path recommendations).
		$recommendations = $this->get_learning_path_recommendations( $user_id, $db );
		if ( ! empty( $recommendations ) ) {
			?>
			<div class="zeko-jobs-profile-section" style="margin-top:20px;">
				<h3><?php esc_html_e( 'Recommended Courses', 'zeko-jobs' ); ?></h3>
				<p style="color:#64748b;font-size:14px;"><?php esc_html_e( 'Based on your job search, these courses will strengthen your profile.', 'zeko-jobs' ); ?></p>
				<ul style="list-style:none;padding:0;margin:0;">
					<?php foreach ( $recommendations as $rec ) : ?>
						<li style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
							<a href="<?php echo esc_url( $rec['url'] ); ?>" target="_blank" rel="noopener">
								<strong><?php echo esc_html( $rec['title'] ); ?></strong>
							</a>
							<span style="color:#94a3b8;font-size:13px;"> — <?php echo esc_html( implode( ', ', $rec['covers'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}

	/**
	 * Build learning-path recommendations from a seeker's recent job targets.
	 *
	 * @param int          $user_id User id.
	 * @param Zeko_Jobs_DB $db Db.
	 */
	private function get_learning_path_recommendations( int $user_id, Zeko_Jobs_DB $db ): array {
		if ( ! class_exists( 'Zeko_Jobs_DB' ) ) {
			return array();
		}

		// Pick the seeker's most recent application as the job target.
		$apps = $db->get_recent_applications_for_seeker( $user_id, 1 );
		if ( empty( $apps ) ) {
			return array();
		}

		$job = $db->get_job( (int) $apps[0]['job_id'] );
		if ( ! $job ) {
			return array();
		}

		$recs = $db->recommend_courses_for_job( $user_id, $job, 4 );
		$out  = array();
		foreach ( $recs as $rec ) {
			$course = $rec['course'];
			$out[]  = array(
				'title'  => $course['title'] ?? '',
				'url'    => ! empty( $course['slug'] ) ? home_url( '/courses/' . $course['slug'] . '/' ) : '',
				'covers' => array_map( 'sanitize_text_field', $rec['covers'] ),
			);
		}
		return $out;
	}

	/**
	 * Render job stats on user profile.
	 */
	public function render_profile_stats(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id   = get_queried_object_id() ?: get_current_user_id();
		$plugin    = Zeko_Jobs::get_instance();
		$user_role = $plugin->get_user_job_role( $user_id );
		$db        = Zeko_Jobs_DB::get_instance();

		if ( 'seeker' === $user_role ) {
			$app_count = $db->count_applications_for_seeker( $user_id );
			if ( $app_count > 0 ) {
				printf(
					'<span class="zeko-stat">%s</span>',
					sprintf(
						/* translators: %d: number of applications */
						esc_html( _n( '%d application submitted', '%d applications submitted', $app_count, 'zeko-jobs' ) ),
						absint( $app_count )
					)
				);
			}
		}
	}

	/**
	 * Fire payment integration hook when a job is created.
	 *
	 * @param int   $job_id The new job ID.
	 * @param int   $user_id The employer user ID.
	 * @param array $job_data The job data.
	 */
	public function on_job_created( int $job_id, int $user_id, array $job_data ): void {
		unset( $job_data );
		/**
		 * Fires when a job is posted, for integration with Zeko-Pay.
		 *
		 * @param int   $job_id  The job ID.
		 * @param int   $user_id The employer user ID.
		 */
		do_action( 'zeko_jobs_job_posted', $job_id, $user_id );
	}

	/**
	 * Fire payment integration hook when a job is boosted/featured.
	 *
	 * @param int  $job_id The job ID.
	 * @param bool $is_featured Whether the job is now featured.
	 */
	public function on_job_featured_toggled( int $job_id, bool $is_featured ): void {
		if ( ! $is_featured ) {
			return;
		}

		$job = Zeko_Jobs_DB::get_instance()->get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		/**
		 * Fires when a job is boosted/featured, for integration with Zeko-Pay.
		 *
		 * @param int $job_id  The job ID.
		 * @param int $user_id The employer user ID.
		 */
		do_action( 'zeko_jobs_job_boosted', $job_id, (int) $job['employer_id'] );
	}
}
