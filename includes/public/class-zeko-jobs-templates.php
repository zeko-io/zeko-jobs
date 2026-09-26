<?php
/**
 * Template redirect, title filtering, query vars, and cache headers for Zeko Jobs.
 *
 * Responsibilities:
 * - Register custom query vars
 * - Template redirect for single job pages
 * - Filter document title parts for job pages
 * - Set cache headers on jobs archive pages
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Templates. */
final class Zeko_Jobs_Templates {

	/**
	 * Construct.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'template_redirect', array( $this, 'set_cache_headers' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ), 10, 1 );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Vars.
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'zeko_job_slug';
		$vars[] = 'zeko_jobs_feed';
		$vars[] = 'zeko_jobs_prefs';
		return $vars;
	}

	/**
	 * Jobs archive context.
	 */
	private function is_jobs_archive_context(): bool {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return ( is_page() || is_home() || is_singular() || is_archive() ) && ( false !== strpos( $request_uri, '/jobs' ) || has_shortcode( get_post_field( 'post_content', get_queried_object_id() ), 'zeko_jobs_archive' ) );
	}

	/**
	 * Cache headers.
	 */
	public function set_cache_headers(): void {
		if ( ! $this->is_jobs_archive_context() ) {
			return;
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: public, max-age=300' );
		}
	}

	/**
	 * Template redirect.
	 */
	public function template_redirect(): void {
		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return;
		}

		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$job_data = $zeko_db->get_job_by_slug( $job_slug );

		if ( empty( $job_data ) ) {
			return;
		}

		global $wp_query;
		$wp_query->is_404                  = false;
		$wp_query->is_single               = true;
		$wp_query->is_archive              = false;
		$wp_query->query_vars['post_type'] = 'job';

		$post                 = new stdClass();
		$post->ID             = (int) $job_data['id'];
		$post->post_author    = (int) $job_data['employer_id'];
		$post->post_date      = $job_data['created_at'];
		$post->post_date_gmt  = $job_data['created_at'];
		$post->post_content   = $job_data['description'];
		$post->post_title     = $job_data['title'];
		$post->post_status    = 'publish';
		$post->comment_status = 'closed';
		$post->ping_status    = 'closed';
		$post->post_name      = $job_slug;
		$post->guid           = home_url( '/jobs/' . $job_slug );
		$post->post_type      = 'job';
		$post->filter         = 'raw';

		setup_postdata( $post );

		$post->location   = $job_data['location'];
		$post->type       = $job_data['type'];
		$post->salary_min = $job_data['salary_min'];
		$post->salary_max = $job_data['salary_max'];
		$post->views      = isset( $job_data['views'] ) ? (int) $job_data['views'] : 0;
		$post->status     = isset( $job_data['status'] ) ? $job_data['status'] : 'publish';

		$viewed_cookie = 'zeko_viewed_jobs';
		$viewed_jobs   = array();
		if ( isset( $_COOKIE[ $viewed_cookie ] ) ) {
			$viewed_jobs = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_COOKIE[ $viewed_cookie ] ) ) ) );
		}
		if ( ! in_array( (int) $job_data['id'], $viewed_jobs, true ) ) {
			$zeko_db->increment_job_views( (int) $job_data['id'] );
			$viewed_jobs[] = (int) $job_data['id'];
			$viewed_jobs   = array_slice( $viewed_jobs, -100 );
			setcookie( $viewed_cookie, implode( ',', $viewed_jobs ), time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		$template_path = plugin_dir_path( __FILE__ ) . '../../templates/single-job.php';

		/**
		 * Filter the single job template path.
		 *
		 * @param string $template_path Default template path.
		 * @param array  $job_data      The job data array.
		 */
		$template_path = apply_filters( 'zeko_job_single_template', $template_path, $job_data );

		include $template_path;
		exit;
	}

	/**
	 * Filter document title parts.
	 *
	 * @param array $title_parts Title parts.
	 */
	public function filter_document_title_parts( array $title_parts ): array {
		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return $title_parts;
		}

		$job_data = Zeko_Jobs_DB::get_instance()->get_job_by_slug( $job_slug );
		if ( ! empty( $job_data['title'] ) ) {
			$title_parts['title'] = $job_data['title'];
		}
		return $title_parts;
	}
}
