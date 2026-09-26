<?php
/**
 * Public facing functionality for Zeko Jobs.
 *
 * Responsibilities:
 * - Render jobs archive via [zeko_jobs_archive]
 * - Render single jobs via rewrite + template_redirect using templates/single-job.php
 * - Enqueue assets on jobs pages
 * - Register REST API routes
 *
 * Sub-modules (instantiated in constructor):
 * - Zeko_Jobs_Ajax: All AJAX handlers
 * - Zeko_Jobs_Shortcodes: Shortcodes and template output
 * - Zeko_Jobs_Templates: Template redirect, title, query vars, cache
 * - Zeko_Jobs_SEO: Schema, OG tags, sitemap, feeds
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Public. */
final class Zeko_Jobs_Public {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Jobs_Public {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// Instantiate sub-modules (each registers its own hooks).
		new Zeko_Jobs_Ajax();
		new Zeko_Jobs_Shortcodes();
		new Zeko_Jobs_Templates();
		new Zeko_Jobs_SEO();

		// Rewrite rules for single job and company profile.
		add_rewrite_rule( '^jobs/([^/]+)/?$', 'index.php?zeko_job_slug=$matches[1]', 'top' );
		add_rewrite_rule( '^jobs/feed/?$', 'index.php?zeko_jobs_feed=1', 'top' );
		add_rewrite_rule( '^jobs/email-preferences/?$', 'index.php?zeko_jobs_prefs=1', 'top' );
		add_rewrite_rule( '^companies/([^/]+)/?$', 'index.php?zeko_company_slug=$matches[1]', 'top' );

		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'company_template_redirect' ) );

		// REST API.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Strip jquery-migrate from jQuery's dependency list at registration time.
		// jquery-migrate 3.4.1 wraps Sizzle.find and causes infinite recursion in.
		// delegated event dispatch on document. All our jQuery code uses modern APIs.
		// Late priority: core's own wp_default_scripts callback (priority 10).
		// registers jquery with jquery-migrate in its deps, and plugins load before.
		// default-filters.php, so an early hook here would run before jquery exists.
		add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'remove_jquery_migrate_dequeue' ), 999 );

		// ICS download.
		add_action( 'template_redirect', array( $this, 'handle_ics_download' ) );

		// Email open tracking pixel.
		add_action( 'template_redirect', array( $this, 'handle_email_tracking' ) );
	}
	/**
	 * Enqueue assets.
	 */
	public function enqueue_assets(): void {
		$job_slug       = get_query_var( 'zeko_job_slug' );
		$should_enqueue = false;

		if ( $job_slug ) {
			$should_enqueue = true;
		}

		$queried_id = get_queried_object_id();
		if ( $queried_id ) {
			$content = get_post_field( 'post_content', $queried_id );
			if ( $content && has_shortcode( $content, 'zeko_jobs_archive' ) ) {
				$should_enqueue = true;
			}
			if ( $content && has_shortcode( $content, 'zeko_jobs_post_form' ) ) {
				$should_enqueue = true;
			}
			if ( $content && has_shortcode( $content, 'zeko_jobs_dashboard' ) ) {
				$should_enqueue = true;
			}
		}

		if ( ! $should_enqueue ) {
			return;
		}

		$version = '2.0.0';

		wp_enqueue_style(
			'zeko-jobs-css',
			plugin_dir_url( __FILE__ ) . '../../assets/css/zeko-jobs.css',
			array( 'dashicons', 'zeko-core' ),
			$version
		);

		wp_enqueue_script(
			'zeko-jobs-js',
			plugin_dir_url( __FILE__ ) . '../../assets/js/zeko-jobs.js',
			array( 'jquery-core' ),
			$version,
			true
		);

		$is_archive = false;
		if ( $queried_id ) {
			$is_archive = has_shortcode( get_post_field( 'post_content', $queried_id ), 'zeko_jobs_archive' );
		}

		if ( $is_archive ) {
			wp_enqueue_style( 'leaflet-css', plugin_dir_url( __FILE__ ) . '../../assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'leaflet-js', plugin_dir_url( __FILE__ ) . '../../assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
			wp_localize_script(
				'zeko-jobs-js',
				'zeko_map_data',
				array(
					'map_center' => array( 39.8283, -98.5795 ),
					'map_zoom'   => 4,
				)
			);
		}

		if ( $queried_id && has_shortcode( get_post_field( 'post_content', $queried_id ), 'zeko_jobs_post_form' ) ) {
			wp_enqueue_editor();
		}

		// Load Chart.js for employer analytics charts on the dashboard.
		if ( $queried_id && has_shortcode( get_post_field( 'post_content', $queried_id ), 'zeko_jobs_dashboard' ) ) {
			wp_enqueue_script( 'chartjs', plugin_dir_url( __FILE__ ) . '../../assets/vendor/chartjs/chart.umd.min.js', array(), '4.4.7', true );
			wp_enqueue_script( 'sortable', plugin_dir_url( __FILE__ ) . '../../assets/vendor/sortable/Sortable.min.js', array(), '1.15.0', true );
		}

		wp_localize_script(
			'zeko-jobs-js',
			'zeko_jobs_ajax',
			array(
				'ajax_url'                   => admin_url( 'admin-ajax.php' ),
				'job_apply_nonce'            => wp_create_nonce( 'zeko_job_apply' ),
				'job_create_nonce'           => wp_create_nonce( 'zeko_job_create' ),
				'job_bookmark_nonce'         => wp_create_nonce( 'zeko_job_bookmark' ),
				'job_message_employer_nonce' => wp_create_nonce( 'zeko_job_message_employer' ),
				'dashboard_nonce'            => wp_create_nonce( 'zeko_job_dashboard' ),
				'i18n'                       => array(
					'bookmarkSuccess' => esc_html__( 'Job bookmarked.', 'zeko-jobs' ),
					'bookmarkRemoved' => esc_html__( 'Bookmark removed.', 'zeko-jobs' ),
					'applySuccess'    => esc_html__( 'Application submitted.', 'zeko-jobs' ),
					'applyError'      => esc_html__( 'Application failed.', 'zeko-jobs' ),
					'statusUpdated'   => esc_html__( 'Status updated.', 'zeko-jobs' ),
					'withdrawSuccess' => esc_html__( 'Application withdrawn.', 'zeko-jobs' ),
					'withdrawConfirm' => esc_html__( 'Withdraw this application? This cannot be undone.', 'zeko-jobs' ),
					'noteAdded'       => esc_html__( 'Note added.', 'zeko-jobs' ),
					'noteFailed'      => esc_html__( 'Could not add note.', 'zeko-jobs' ),
					'deleteReminder'  => esc_html__( 'Delete this reminder?', 'zeko-jobs' ),
					'deleteSuccess'   => esc_html__( 'Reminder removed.', 'zeko-jobs' ),
					'deleteFailed'    => esc_html__( 'Could not delete reminder.', 'zeko-jobs' ),
					'networkError'    => esc_html__( 'Network error.', 'zeko-jobs' ),
					'loading'         => esc_html__( 'Loading...', 'zeko-jobs' ),
					'noHistory'       => esc_html__( 'No history.', 'zeko-jobs' ),
					'historyError'    => esc_html__( 'Could not load history.', 'zeko-jobs' ),
				),
				'coverTemplates'             => is_user_logged_in() ? (array) get_user_meta( get_current_user_id(), 'zeko_cover_templates', true ) : array(),
			)
		);
	}

	/**
	 * Remove jquery-migrate from jQuery's registered dependency list.
	 * WordPress registers jquery with deps [jquery-core, jquery-migrate].
	 * jquery-migrate 3.4.1 wraps Sizzle.find and causes infinite recursion
	 * in delegated event dispatch on document. All our jQuery code uses modern APIs.
	 *
	 * @param mixed $scripts Scripts.
	 */
	public function remove_jquery_migrate( $scripts ): void {
		if ( ! empty( $scripts->registered['jquery'] ) ) {
			$deps                                = $scripts->registered['jquery']->deps;
			$scripts->registered['jquery']->deps = array_values( array_diff( $deps, array( 'jquery-migrate' ) ) );
		}
	}

	/**
	 * Belt-and-suspenders dequeue of jquery-migrate at late priority.
	 * Catches any script that re-enqueued it after wp_default_scripts.
	 */
	public function remove_jquery_migrate_dequeue(): void {
		wp_dequeue_script( 'jquery-migrate' );
		wp_deregister_script( 'jquery-migrate' );

		// wp_default_scripts may have already run (WP_Scripts is initialized at.
		// init priority 0, before this plugin registers its hook), leaving.
		// jquery-migrate in jquery's deps. Fix the registered deps here so the.
		// dependency no longer dangles after we deregister it above.
		$scripts = wp_scripts();
		if ( ! empty( $scripts->registered['jquery'] ) ) {
			$deps = $scripts->registered['jquery']->deps;
			if ( in_array( 'jquery-migrate', $deps, true ) ) {
				$scripts->registered['jquery']->deps = array_values( array_diff( $deps, array( 'jquery-migrate' ) ) );
			}
		}
	}

	/**
	 * Rest routes.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'zeko-jobs/v1',
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_jobs' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search'   => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'location' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'     => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'per_page' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 20,
					),
				),
			)
		);

		// Create job (employer only).
		register_rest_route(
			'zeko-jobs/v1',
			'/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_create_job' ),
				'permission_callback' => array( $this, 'rest_check_employer' ),
				'args'                => array(
					'title'            => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description'      => array(
						'required'          => false,
						'sanitize_callback' => 'wp_kses_post',
					),
					'location'         => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'             => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'salary_min'       => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'salary_max'       => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'category'         => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'remote_option'    => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'experience_level' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'zeko-jobs/v1',
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_job_by_id' ),
				'permission_callback' => '__return_true',
			)
		);

		// Update job (employer only, must own).
		register_rest_route(
			'zeko-jobs/v1',
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'PUT,PATCH',
				'callback'            => array( $this, 'rest_update_job' ),
				'permission_callback' => array( $this, 'rest_check_employer' ),
			)
		);

		// Delete job (employer only, must own).
		register_rest_route(
			'zeko-jobs/v1',
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'rest_delete_job' ),
				'permission_callback' => array( $this, 'rest_check_employer' ),
			)
		);

		// Applications: list (seeker sees own, employer sees own jobs').
		register_rest_route(
			'zeko-jobs/v1',
			'/applications',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_applications' ),
				'permission_callback' => array( $this, 'rest_check_logged_in' ),
			)
		);

		// Apply to job (seeker only).
		register_rest_route(
			'zeko-jobs/v1',
			'/applications',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_apply_to_job' ),
				'permission_callback' => array( $this, 'rest_check_logged_in' ),
				'args'                => array(
					'job_id'       => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'cover_letter' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Update application status (employer only).
		register_rest_route(
			'zeko-jobs/v1',
			'/applications/(?P<id>\d+)',
			array(
				'methods'             => 'PUT,PATCH',
				'callback'            => array( $this, 'rest_update_application' ),
				'permission_callback' => array( $this, 'rest_check_employer' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Companies list.
		register_rest_route(
			'zeko-jobs/v1',
			'/companies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_companies' ),
				'permission_callback' => '__return_true',
			)
		);

		// Single company by slug.
		register_rest_route(
			'zeko-jobs/v1',
			'/companies/(?P<slug>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_company' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST API: Get list of jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_get_jobs( WP_REST_Request $request ): WP_REST_Response {
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$search   = $request->get_param( 'search' );
		$location = $request->get_param( 'location' );
		$type     = $request->get_param( 'type' );
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$offset   = ( $page - 1 ) * $per_page;

		$filters = array();
		if ( $search ) {
			$filters['search_term'] = $search;
		}
		if ( $location ) {
			$filters['location'] = $location;
		}
		if ( $type ) {
			$filters['job_type'] = $type;
		}

		$total = $zeko_db->count_jobs_filtered( $filters );
		$jobs  = $zeko_db->get_jobs_paginated( $filters, $per_page, $offset );

		$data = array();
		foreach ( $jobs as $job ) {
			$data[] = array(
				'id'         => (int) $job['id'],
				'title'      => $job['title'],
				'slug'       => $job['slug'],
				'url'        => home_url( '/jobs/' . $job['slug'] . '/' ),
				'location'   => $job['location'],
				'type'       => $job['type'],
				'salary_min' => (float) $job['salary_min'],
				'salary_max' => (float) $job['salary_max'],
				'created_at' => $job['created_at'],
				'views'      => (int) $job['views'],
			);
		}

		return new WP_REST_Response(
			array(
				'jobs'        => $data,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			),
			200
		);
	}

	/**
	 * REST permission: must be logged in.
	 */
	public function rest_check_logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * REST permission: must be an employer.
	 */
	public function rest_check_employer(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( 'manage_options' ) || ( function_exists( 'get_user_meta' ) && get_user_meta( get_current_user_id(), 'zeko_job_role', true ) === 'employer' );
	}

	/**
	 * REST API: Get single job by ID.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_get_job_by_id( WP_REST_Request $request ): WP_REST_Response {
		$zeko_db = Zeko_Jobs_DB::get_instance();

		// Only published jobs surface to the public API. Employers and admins.
		// may still view their own unpublished listings.
		if ( current_user_can( 'manage_options' ) || ( function_exists( 'get_user_meta' ) && get_user_meta( get_current_user_id(), 'zeko_job_role', true ) === 'employer' ) ) {
			$job = $zeko_db->get_job( (int) $request->get_param( 'id' ) );
			if ( empty( $job ) || ( get_current_user_id() !== (int) $job['employer_id'] && ! current_user_can( 'manage_options' ) ) ) {
				return new WP_REST_Response( array( 'message' => 'Job not found.' ), 404 );
			}
		} else {
			$job = $zeko_db->get_published_job( (int) $request->get_param( 'id' ) );
		}

		if ( empty( $job ) ) {
			return new WP_REST_Response( array( 'message' => 'Job not found.' ), 404 );
		}
		return new WP_REST_Response( $this->rest_format_job( $job ), 200 );
	}

	/**
	 * REST API: Create a job (employer).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_create_job( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Zeko_Jobs_Rate_Limiter::check( 'post_job' ) ) {
			return new WP_REST_Response( array( 'message' => 'Too many job posts. Please try again later.' ), 429 );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$user_id = get_current_user_id();

		$active = (int) $zeko_db->count_published_jobs( $user_id );
		$cap    = (int) apply_filters( 'zeko_jobs_limit_active_posts', 5, $user_id, $active );
		if ( $cap > 0 && $active >= $cap ) {
			return new WP_REST_Response( array( 'message' => sprintf( 'You can keep up to %d active job posts on the free plan. Close one to post another, or upgrade to post more.', $cap ) ), 403 );
		}
		$data = array(
			'employer_id'      => $user_id,
			'title'            => $request->get_param( 'title' ),
			'description'      => $request->get_param( 'description' ) ?? '',
			'location'         => $request->get_param( 'location' ) ?? '',
			'type'             => $request->get_param( 'type' ) ?? 'full-time',
			'salary_min'       => $request->get_param( 'salary_min' ) ?? 0,
			'salary_max'       => $request->get_param( 'salary_max' ) ?? 0,
			'category'         => $request->get_param( 'category' ) ?? '',
			'remote_option'    => $request->get_param( 'remote_option' ) ?? '',
			'experience_level' => $request->get_param( 'experience_level' ) ?? '',
			'status'           => 'publish',
		);

		// ZekoPay: charge the posting fee (same rules as the AJAX path) so the.
		// REST endpoint can't be used to post jobs without paying.
		$billing_result = \Zeko_Jobs_Ajax::maybe_charge_posting_fee( $user_id );
		if ( is_wp_error( $billing_result ) ) {
			return new WP_REST_Response(
				array(
					'message'  => $billing_result->get_error_message(),
					'redirect' => $billing_result->get_error_data( 'redirect' ) ?? home_url( '/wallet/' ),
					'payment'  => true,
				),
				402
			);
		}

		$job_id = $zeko_db->insert_job( $data );
		if ( ! $job_id ) {
			return new WP_REST_Response( array( 'message' => 'Could not create job.' ), 500 );
		}

		do_action( 'zeko_job_created', $job_id, $user_id, $data );
		return new WP_REST_Response(
			array(
				'id'      => $job_id,
				'message' => 'Job created.',
			),
			201
		);
	}

	/**
	 * REST API: Update a job (employer, must own).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_update_job( WP_REST_Request $request ): WP_REST_Response {
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job_id  = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$job     = $zeko_db->get_job( $job_id );

		if ( empty( $job ) ) {
			return new WP_REST_Response( array( 'message' => 'Job not found.' ), 404 );
		}
		if ( (int) $job['employer_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => 'Permission denied.' ), 403 );
		}

		$allowed = array( 'title', 'description', 'location', 'type', 'salary_min', 'salary_max', 'category', 'remote_option', 'experience_level', 'status' );
		$updates = array();
		foreach ( $allowed as $field ) {
			$val = $request->get_param( $field );
			if ( null !== $val ) {
				$updates[ $field ] = $val;
			}
		}

		if ( empty( $updates ) ) {
			return new WP_REST_Response( array( 'message' => 'Nothing to update.' ), 400 );
		}

		$zeko_db->update_job( $job_id, $updates );
		do_action( 'zeko_job_updated', $job_id, $user_id, $updates );
		return new WP_REST_Response( array( 'message' => 'Job updated.' ), 200 );
	}

	/**
	 * REST API: Delete a job (employer, must own).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_delete_job( WP_REST_Request $request ): WP_REST_Response {
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job_id  = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$job     = $zeko_db->get_job( $job_id );

		if ( empty( $job ) ) {
			return new WP_REST_Response( array( 'message' => 'Job not found.' ), 404 );
		}
		if ( (int) $job['employer_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => 'Permission denied.' ), 403 );
		}

		$zeko_db->delete_job( $job_id, (int) $job['employer_id'] );
		do_action( 'zeko_job_deleted', $job_id, $user_id );
		return new WP_REST_Response( array( 'message' => 'Job deleted.' ), 200 );
	}

	/**
	 * REST API: List applications (seeker sees own, employer sees their jobs').
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_get_applications( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$user_id = get_current_user_id();

		if ( current_user_can( 'manage_options' ) || get_user_meta( $user_id, 'zeko_job_role', true ) === 'employer' ) {
			$job_ids      = array_column( $zeko_db->get_jobs_by_employer( $user_id ), 'id' );
			$applications = ! empty( $job_ids ) ? $zeko_db->get_applications_for_jobs( $job_ids, 50 ) : array();
		} else {
			$applications = $zeko_db->get_user_applications( $user_id );
		}

		return new WP_REST_Response( array( 'applications' => $applications ), 200 );
	}

	/**
	 * REST API: Apply to a job.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_apply_to_job( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Zeko_Jobs_Rate_Limiter::check( 'apply' ) ) {
			return new WP_REST_Response( array( 'message' => 'Too many applications. Please try again later.' ), 429 );
		}

		$zeko_db      = Zeko_Jobs_DB::get_instance();
		$user_id      = get_current_user_id();
		$job_id       = (int) $request->get_param( 'job_id' );
		$cover_letter = $request->get_param( 'cover_letter' ) ?? '';

		$job = $zeko_db->get_job( $job_id );
		if ( empty( $job ) ) {
			return new WP_REST_Response( array( 'message' => 'Job not found.' ), 404 );
		}

		if ( $zeko_db->has_applied( $job_id, $user_id ) ) {
			return new WP_REST_Response( array( 'message' => 'Already applied.' ), 409 );
		}

		$success = $zeko_db->insert_application(
			array(
				'job_id'       => $job_id,
				'seeker_id'    => $user_id,
				'cover_letter' => $cover_letter,
				'status'       => 'awaiting_review',
			)
		);

		if ( ! $success ) {
			return new WP_REST_Response( array( 'message' => 'Could not submit application.' ), 500 );
		}

		do_action( 'zeko_job_application_submitted', 0, $job_id, $user_id );
		return new WP_REST_Response( array( 'message' => 'Application submitted.' ), 201 );
	}

	/**
	 * REST API: Update application status (employer).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_update_application( WP_REST_Request $request ): WP_REST_Response {
		$zeko_db    = Zeko_Jobs_DB::get_instance();
		$app_id     = (int) $request->get_param( 'id' );
		$new_status = $request->get_param( 'status' );
		$user_id    = get_current_user_id();

		$app = $zeko_db->get_application( $app_id );
		if ( empty( $app ) ) {
			return new WP_REST_Response( array( 'message' => 'Application not found.' ), 404 );
		}

		$job = $zeko_db->get_job( (int) $app['job_id'] );
		if ( empty( $job ) || ( (int) $job['employer_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
			return new WP_REST_Response( array( 'message' => 'Permission denied.' ), 403 );
		}

		$valid = array( 'awaiting_review', 'reviewed', 'contacting', 'interviewing', 'offered', 'hired', 'rejected', 'archived' );
		if ( ! in_array( $new_status, $valid, true ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid status.' ), 400 );
		}

		$old_status = $app['status'];
		$zeko_db->update_application_status( $app_id, $new_status, $user_id );
		do_action( 'zeko_job_application_status_changed', $app_id, $old_status, $new_status, $user_id );
		return new WP_REST_Response( array( 'message' => 'Status updated.' ), 200 );
	}

	/**
	 * REST API: List companies.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_get_companies( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$companies = $zeko_db->get_companies();
		return new WP_REST_Response( array( 'companies' => $companies ), 200 );
	}

	/**
	 * REST API: Get company by slug.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest_get_company( WP_REST_Request $request ): WP_REST_Response {
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$company = $zeko_db->get_company_by_slug( $request->get_param( 'slug' ) );
		if ( empty( $company ) ) {
			return new WP_REST_Response( array( 'message' => 'Company not found.' ), 404 );
		}
		return new WP_REST_Response( array( 'company' => $company ), 200 );
	}

	/**
	 * Format a job array for REST output.
	 *
	 * @param array $job Job.
	 */
	private function rest_format_job( array $job ): array {
		$employer_id = (int) $job['employer_id'];
		$employer    = get_userdata( $employer_id );
		return array(
			'id'               => (int) $job['id'],
			'title'            => $job['title'],
			'slug'             => $job['slug'],
			'url'              => home_url( '/jobs/' . $job['slug'] . '/' ),
			'description'      => $job['description'],
			'location'         => $job['location'],
			'type'             => $job['type'],
			'salary_min'       => (float) $job['salary_min'],
			'salary_max'       => (float) $job['salary_max'],
			'experience_level' => $job['experience_level'],
			'remote_option'    => $job['remote_option'],
			'category'         => $job['category'] ?? '',
			'status'           => $job['status'],
			'is_featured'      => (bool) ( $job['is_featured'] ?? false ),
			'views'            => (int) ( $job['views'] ?? 0 ),
			'created_at'       => $job['created_at'],
			'employer'         => $employer ? array(
				'id'   => $employer->ID,
				'name' => $employer->display_name,
			) : null,
		);
	}

	/**
	 * Handle ICS calendar download for interviews.
	 */
	public function handle_ics_download(): void {
		if ( ! is_user_logged_in() || ! isset( $_GET['zeko_ics_download'] ) ) {
			return;
		}

		$interview_id = absint( $_GET['zeko_ics_download'] );
		if ( ! wp_verify_nonce( (string) wp_unslash( $_GET['_wpnonce'] ?? '' ), 'zeko_ics_' . $interview_id ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce must stay verbatim (unslashed only) for wp_verify_nonce(); it is compared, never echoed or stored.
			return;
		}

		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$interview = $zeko_db->get_interview_by_id( $interview_id );
		if ( ! $interview ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( (int) $interview['seeker_id'] !== $user_id && (int) $interview['employer_id'] !== $user_id ) {
			return;
		}

		$ics = $zeko_db->generate_ics( $interview );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="interview-' . $interview_id . '.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS calendar file download served with text/calendar headers; content is constructed with addcslashes() escaping, not HTML.
		exit;
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array $vars Vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'zeko_company_slug';
		return $vars;
	}

	/**
	 * Redirect company slug to template.
	 */
	public function company_template_redirect(): void {
		$slug = get_query_var( 'zeko_company_slug' );
		if ( ! $slug ) {
			return;
		}

		$template = plugin_dir_path( __DIR__ ) . '../templates/company-profile.php';
		if ( file_exists( $template ) ) {
			load_template( $template, false );
			exit;
		}
	}

	/**
	 * Handle email open tracking pixel.
	 * Records when an email recipient opens the email, then serves a 1x1 transparent GIF.
	 */
	public function handle_email_tracking(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public email tracking pixel: email clients strip custom params, so a nonce cannot be included; the tracking value is unauthenticated by design.
		if ( empty( $_GET['zeko_email_track'] ) ) {
			return;
		}

		$tracking_id    = sanitize_text_field( wp_unslash( $_GET['zeko_email_track'] ) );
		$email_type     = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$application_id = isset( $_GET['app'] ) ? absint( wp_unslash( $_GET['app'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$zeko_db = Zeko_Jobs_DB::get_instance();

		// Honor the recipient's open-tracking consent (secondary check; opted-out
		// recipients already never receive the pixel, so nothing is recorded).
		$track = true;
		if ( $application_id > 0 ) {
			$app      = $zeko_db->get_application( $application_id );
			$owner_id = ! empty( $app['seeker_id'] ) ? absint( $app['seeker_id'] ) : 0;
			if ( $owner_id > 0 && class_exists( 'Zeko_Jobs_Email_Preferences' ) && ! Zeko_Jobs_Email_Preferences::get_instance()->open_tracking_consent( $owner_id ) ) {
				$track = false;
			}
		}

		if ( $track ) {
			$zeko_db->log_email_open( $tracking_id, $application_id > 0 ? $application_id : null, null, $email_type );
		}

		// Serve a 1x1 transparent GIF.
		header( 'Content-Type: image/gif' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary 1x1 transparent GIF payload served with image/gif header; not HTML.
		exit;
	}

	/**
	 * Render the dashboard "My Posted Jobs" widget for a user.
	 * Used by the Zeko theme dashboard (themes/zeko/inc/dashboard.php), which
	 * delegates to this method when the class is present, falling back to its
	 * own inline query otherwise.
	 *
	 * @return string Widget HTML.
	 * @param int $user_id User ID.
	 */
	public function get_my_posted_jobs_widget( int $user_id ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, slug, status, created_at FROM $table_name WHERE employer_id = %d ORDER BY created_at DESC LIMIT 5",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		ob_start();
		?>
		<div class="zeko-my-jobs-widget">
			<h4><?php esc_html_e( 'My Posted Jobs', 'zeko-jobs' ); ?></h4>
			<?php if ( ! empty( $jobs ) ) : ?>
				<ul>
					<?php foreach ( $jobs as $job ) : ?>
						<li>
							<a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] ) ); ?>">
								<?php echo esc_html( $job['title'] ); ?>
							</a>
							<span class="zeko-job-status status-<?php echo esc_attr( $job['status'] ); ?>">
								(<?php echo esc_html( ucfirst( $job['status'] ) ); ?>)
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( home_url( '/job-dashboard/' ) ); ?>"><?php esc_html_e( 'View All My Jobs', 'zeko-jobs' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'You haven\'t posted any jobs yet.', 'zeko-jobs' ); ?></p>
				<p><a href="<?php echo esc_url( home_url( '/post-a-job/' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Post a New Job', 'zeko-jobs' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the dashboard "My Job Applications" widget for a user.
	 * Used by the Zeko theme dashboard (themes/zeko/inc/dashboard.php), which
	 * delegates to this method when the class is present, falling back to its
	 * own inline query otherwise.
	 *
	 * @return string Widget HTML.
	 * @param int $user_id User ID.
	 */
	public function get_my_applications_widget( int $user_id ): string {
		global $wpdb;
		$applications_table = $wpdb->prefix . 'zeko_job_applications';
		$jobs_table         = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$applications = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT app.id, app.status, app.applied_at, job.title, job.slug FROM $applications_table app JOIN $jobs_table job ON app.job_id = job.id WHERE app.seeker_id = %d ORDER BY app.applied_at DESC LIMIT 5",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		ob_start();
		?>
		<div class="zeko-my-applications-widget">
			<h4><?php esc_html_e( 'My Job Applications', 'zeko-jobs' ); ?></h4>
			<?php if ( ! empty( $applications ) ) : ?>
				<ul>
					<?php foreach ( $applications as $app ) : ?>
						<li>
							<a href="<?php echo esc_url( home_url( '/jobs/' . $app['slug'] ) ); ?>">
								<?php echo esc_html( $app['title'] ); ?>
							</a>
							<span class="zeko-application-status status-<?php echo esc_attr( $app['status'] ); ?>">
								(<?php echo esc_html( ucfirst( $app['status'] ) ); ?>)
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( home_url( '/job-dashboard/' ) ); ?>"><?php esc_html_e( 'View All My Applications', 'zeko-jobs' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'You haven\'t applied for any jobs yet.', 'zeko-jobs' ); ?></p>
				<p><a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Browse Jobs', 'zeko-jobs' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

// Initialize.
Zeko_Jobs_Public::get_instance();
