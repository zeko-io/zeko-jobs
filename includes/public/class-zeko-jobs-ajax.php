<?php
/**
 * AJAX handlers for Zeko Jobs.
 *
 * Responsibilities:
 *   - Job CRUD (create, duplicate, delete)
 *   - Applications (apply, bulk apply, easy apply, withdraw)
 *   - Bookmarks, messaging, status updates
 *   - Saved searches, documents, interviews, notifications
 *   - Reviews, reminders, bulk actions
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Ajax. */
final class Zeko_Jobs_Ajax {

	/**
	 * Sanitize rich-text (Quill) content with the narrow ecosystem
	 * allow-list; falls back to $this->sanitize_rich() when zeko-core is absent.
	 *
	 * @param mixed $html Html.
	 */
	private function sanitize_rich( $html ): string {
		if ( class_exists( 'Zeko_Core_Sanitize' ) ) {
			return Zeko_Core_Sanitize::rich_text( (string) $html );
		}
		return $this->sanitize_rich( $html );
	}

	/**
	 * Construct.
	 */
	public function __construct() {
		add_action( 'wp_ajax_zeko_job_apply', array( $this, 'handle_job_application' ) );
		add_action( 'wp_ajax_zeko_job_bulk_apply', array( $this, 'handle_bulk_apply' ) );
		add_action( 'wp_ajax_zeko_job_create', array( $this, 'handle_job_create' ) );
		add_action( 'wp_ajax_zeko_job_bookmark', array( $this, 'handle_job_bookmark' ) );
		add_action( 'wp_ajax_zeko_job_message_employer', array( $this, 'handle_message_employer' ) );
		add_action( 'wp_ajax_zeko_job_update_status', array( $this, 'handle_update_status' ) );
		add_action( 'wp_ajax_zeko_job_withdraw', array( $this, 'handle_withdraw_application' ) );
		add_action( 'wp_ajax_zeko_job_submit_review', array( $this, 'handle_submit_review' ) );
		add_action( 'wp_ajax_zeko_job_toggle_featured', array( $this, 'handle_toggle_featured' ) );
		add_action( 'wp_ajax_zeko_job_export_apps', array( $this, 'handle_export_applications' ) );
		add_action( 'wp_ajax_zeko_job_add_note', array( $this, 'handle_add_application_note' ) );
		add_action( 'wp_ajax_zeko_job_get_notes', array( $this, 'handle_get_application_notes' ) );
		add_action( 'wp_ajax_zeko_job_get_history', array( $this, 'handle_get_application_history' ) );
		add_action( 'wp_ajax_zeko_job_add_reminder', array( $this, 'handle_add_reminder' ) );
		add_action( 'wp_ajax_zeko_job_delete_reminder', array( $this, 'handle_delete_reminder' ) );
		add_action( 'wp_ajax_zeko_job_get_reminders', array( $this, 'handle_get_reminders' ) );
		add_action( 'wp_ajax_zeko_job_easy_apply', array( $this, 'handle_easy_apply' ) );
		add_action( 'wp_ajax_zeko_job_not_interested', array( $this, 'handle_not_interested' ) );
		add_action( 'wp_ajax_zeko_job_flag', array( $this, 'handle_job_flag' ) );
		add_action( 'wp_ajax_nopriv_zeko_job_flag', array( $this, 'handle_job_flag' ) );
		add_action( 'wp_ajax_zeko_job_follow_company', array( $this, 'handle_follow_company' ) );
		add_action( 'wp_ajax_zeko_job_search_resumes', array( $this, 'handle_search_resumes' ) );
		add_action( 'wp_ajax_zeko_job_save_company_post', array( $this, 'handle_save_company_post' ) );
		add_action( 'wp_ajax_zeko_job_delete_company_post', array( $this, 'handle_delete_company_post' ) );
		add_action( 'wp_ajax_zeko_job_bulk_action', array( $this, 'handle_bulk_action' ) );
		add_action( 'wp_ajax_zeko_job_duplicate', array( $this, 'handle_duplicate_job' ) );
		add_action( 'wp_ajax_zeko_job_delete', array( $this, 'handle_delete_job' ) );
		add_action( 'wp_ajax_zeko_job_get_detail', array( $this, 'handle_get_application_detail' ) );
		add_action( 'wp_ajax_zeko_job_save_search', array( $this, 'handle_save_search' ) );
		add_action( 'wp_ajax_zeko_job_get_saved_searches', array( $this, 'handle_get_saved_searches' ) );
		add_action( 'wp_ajax_zeko_job_delete_saved_search', array( $this, 'handle_delete_saved_search' ) );
		add_action( 'wp_ajax_zeko_job_upload_document', array( $this, 'handle_upload_document' ) );
		add_action( 'wp_ajax_zeko_job_get_documents', array( $this, 'handle_get_documents' ) );
		add_action( 'wp_ajax_zeko_job_delete_document', array( $this, 'handle_delete_document' ) );
		add_action( 'admin_post_zeko_job_download_document', array( $this, 'handle_download_document' ) );
		add_action( 'wp_ajax_zeko_job_download_document', array( $this, 'handle_download_document' ) );
		add_action( 'wp_ajax_zeko_job_propose_times', array( $this, 'handle_propose_times' ) );
		add_action( 'wp_ajax_zeko_job_accept_time', array( $this, 'handle_accept_time' ) );
		add_action( 'wp_ajax_zeko_job_import_csv', array( $this, 'handle_import_csv' ) );
		add_action( 'wp_ajax_zeko_job_compare_apps', array( $this, 'handle_compare_apps' ) );
		add_action( 'wp_ajax_zeko_job_save_resume', array( $this, 'handle_save_resume' ) );
		add_action( 'wp_ajax_zeko_job_delete_resume', array( $this, 'handle_delete_resume' ) );
		add_action( 'wp_ajax_zeko_job_set_default_resume', array( $this, 'handle_set_default_resume' ) );
		add_action( 'wp_ajax_zeko_job_schedule_interview', array( $this, 'handle_schedule_interview' ) );
		add_action( 'wp_ajax_zeko_job_get_interviews', array( $this, 'handle_get_interviews' ) );
		add_action( 'wp_ajax_zeko_job_download_ics', array( $this, 'handle_download_ics' ) );
		add_action( 'wp_ajax_zeko_job_mark_notification_read', array( $this, 'handle_mark_notification_read' ) );
		add_action( 'wp_ajax_zeko_job_get_notifications', array( $this, 'handle_get_notifications' ) );
		add_action( 'wp_ajax_zeko_job_notif_read_all', array( $this, 'handle_notif_read_all' ) );
		add_action( 'wp_ajax_zeko_job_renew', array( $this, 'handle_renew_job' ) );
		add_action( 'wp_ajax_zeko_job_save_template', array( $this, 'handle_save_cover_template' ) );
		add_action( 'wp_ajax_zeko_job_delete_template', array( $this, 'handle_delete_cover_template' ) );
		add_action( 'wp_ajax_zeko_job_get_templates', array( $this, 'handle_get_cover_templates' ) );
		add_action( 'wp_ajax_zeko_job_save_company', array( $this, 'handle_save_company' ) );
		add_action( 'wp_ajax_zeko_job_check_duplicates', array( $this, 'handle_check_duplicates' ) );
		add_action( 'wp_ajax_zeko_job_one_click_apply', array( $this, 'handle_one_click_apply' ) );
		add_action( 'wp_ajax_zeko_job_save_email_template', array( $this, 'handle_save_email_template' ) );
		add_action( 'wp_ajax_zeko_job_delete_email_template', array( $this, 'handle_delete_email_template' ) );
		add_action( 'wp_ajax_zeko_job_bulk_email', array( $this, 'handle_bulk_email' ) );
		add_action( 'wp_ajax_zeko_job_save_rating', array( $this, 'handle_save_rating' ) );
		add_action( 'wp_ajax_zeko_job_save_auto_response', array( $this, 'handle_save_auto_response' ) );
		add_action( 'wp_ajax_zeko_job_send_outreach', array( $this, 'handle_send_outreach' ) );
		add_action( 'wp_ajax_zeko_job_toggle_save', array( $this, 'handle_toggle_save' ) );
		add_action( 'wp_ajax_zeko_job_save_seeker_profile', array( $this, 'handle_save_seeker_profile' ) );
		add_action( 'wp_ajax_zeko_job_get_analytics', array( $this, 'handle_get_job_analytics' ) );
		add_action( 'wp_ajax_zeko_job_export_analytics', array( $this, 'handle_export_analytics' ) );
		add_action( 'wp_ajax_zeko_job_update_doc_label', array( $this, 'handle_update_document_label' ) );
		add_action( 'wp_ajax_zeko_job_save_alert', array( $this, 'handle_save_job_alert' ) );
		add_action( 'wp_ajax_zeko_job_delete_alert', array( $this, 'handle_delete_job_alert' ) );
		add_action( 'wp_ajax_zeko_job_toggle_alert', array( $this, 'handle_toggle_job_alert' ) );
		add_action( 'wp_ajax_zeko_job_compare_candidates', array( $this, 'handle_compare_candidates' ) );
		add_action( 'wp_ajax_zeko_job_get_interview_feedback', array( $this, 'handle_get_interview_feedback' ) );
		add_action( 'wp_ajax_zeko_job_save_interview_feedback', array( $this, 'handle_save_interview_feedback' ) );
		add_action( 'wp_ajax_zeko_job_save_pipeline_stages', array( $this, 'handle_save_pipeline_stages' ) );
		add_action( 'wp_ajax_zeko_job_get_pipeline_stages', array( $this, 'handle_get_pipeline_stages' ) );
		add_action( 'wp_ajax_zeko_jobs_export_data', array( $this, 'handle_export_data' ) );
		add_action( 'wp_ajax_zeko_jobs_request_deletion', array( $this, 'handle_request_deletion' ) );
		add_action( 'wp_ajax_zeko_jobs_cancel_deletion', array( $this, 'handle_cancel_deletion' ) );
		add_action( 'wp_ajax_zeko_job_load_more', array( $this, 'handle_load_more' ) );
		add_action( 'wp_ajax_nopriv_zeko_job_load_more', array( $this, 'handle_load_more' ) );

		// ZekoPay billing actions.
		add_action( 'wp_ajax_zeko_job_check_balance', array( $this, 'handle_check_balance' ) );
		add_action( 'wp_ajax_zeko_job_buy_listing_pack', array( $this, 'handle_buy_listing_pack' ) );
		add_action( 'wp_ajax_zeko_job_billing_history', array( $this, 'handle_billing_history' ) );
	}

	/**
	 * Handle job create.
	 */
	public function handle_job_create(): void {
		check_ajax_referer( 'zeko_job_create', 'zeko_job_create_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id     = get_current_user_id();
		$edit_job_id = isset( $_POST['edit_job_id'] ) ? (int) $_POST['edit_job_id'] : 0;

		if ( ! $edit_job_id && ! Zeko_Jobs_Rate_Limiter::check( 'post_job' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Too many job posts today. Please try again later.', 'zeko-jobs' ) ), 429 );
		}

		if ( ! $edit_job_id ) {
			$active = (int) Zeko_Jobs_DB::get_instance()->count_published_jobs( $user_id );
			$cap    = (int) apply_filters( 'zeko_jobs_limit_active_posts', 5, $user_id, $active );
			if ( $cap > 0 && $active >= $cap ) {
				/* translators: %d: active job post cap. */
				wp_send_json_error( array( 'message' => sprintf( esc_html__( 'You can keep up to %d active job posts on the free plan. Close one to post another, or upgrade to post more.', 'zeko-jobs' ), $cap ) ) );
			}
		}

		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$location    = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$type        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$salary_min  = isset( $_POST['salary_min'] ) ? (float) $_POST['salary_min'] : 0;
		$salary_max  = isset( $_POST['salary_max'] ) ? (float) $_POST['salary_max'] : 0;
		$description = isset( $_POST['description'] ) ? $this->sanitize_rich( wp_unslash( $_POST['description'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rich content sanitized by custom sanitize_rich() (Zeko_Core_Sanitize::rich_text / wp_kses-based allow-list).

		$category             = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$experience_level     = isset( $_POST['experience_level'] ) ? sanitize_text_field( wp_unslash( $_POST['experience_level'] ) ) : '';
		$remote_option        = isset( $_POST['remote_option'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_option'] ) ) : '';
		$application_deadline = isset( $_POST['application_deadline'] ) ? sanitize_text_field( wp_unslash( $_POST['application_deadline'] ) ) : '';
		$responsibilities     = isset( $_POST['responsibilities'] ) ? sanitize_textarea_field( wp_unslash( $_POST['responsibilities'] ) ) : '';
		$requirements         = isset( $_POST['requirements'] ) ? sanitize_textarea_field( wp_unslash( $_POST['requirements'] ) ) : '';
		$qualifications       = isset( $_POST['qualifications'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qualifications'] ) ) : '';
		$benefits             = isset( $_POST['benefits'] ) ? sanitize_textarea_field( wp_unslash( $_POST['benefits'] ) ) : '';
		$company_industry     = isset( $_POST['company_industry'] ) ? sanitize_text_field( wp_unslash( $_POST['company_industry'] ) ) : '';
		$company_size         = isset( $_POST['company_size'] ) ? sanitize_text_field( wp_unslash( $_POST['company_size'] ) ) : '';
		$company_website      = isset( $_POST['company_website'] ) ? esc_url_raw( wp_unslash( $_POST['company_website'] ) ) : '';
		$company_about        = isset( $_POST['company_about'] ) ? sanitize_textarea_field( wp_unslash( $_POST['company_about'] ) ) : '';
		$is_easy_apply        = isset( $_POST['is_easy_apply'] ) ? 1 : 0;
		$required_skills      = isset( $_POST['required_skills'] ) ? sanitize_text_field( wp_unslash( $_POST['required_skills'] ) ) : '';
		$company_id           = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		if ( ! $company_id && $edit_job_id ) {
			$company_id = isset( $_POST['company_id_preserved'] ) ? absint( $_POST['company_id_preserved'] ) : 0;
		}

		$allowed_types = array( 'full-time', 'part-time', 'contract', 'freelance' );

		if ( empty( $title ) || empty( $location ) || empty( $type ) || empty( $description ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing required fields.', 'zeko-jobs' ) ) );
		}
		if ( mb_strlen( $title ) > 120 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Job title must be 120 characters or fewer.', 'zeko-jobs' ) ) );
		}
		if ( mb_strlen( $location ) > 100 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Location must be 100 characters or fewer.', 'zeko-jobs' ) ) );
		}
		if ( mb_strlen( wp_strip_all_tags( $description ) ) > 5000 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Job description must be 5,000 characters or fewer.', 'zeko-jobs' ) ) );
		}
		if ( ! in_array( $type, $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job type.', 'zeko-jobs' ) ) );
		}
		if ( $salary_min < 0 ) {
			$salary_min = 0;
		}
		if ( $salary_max < 0 ) {
			$salary_max = 0;
		}
		if ( $salary_max && $salary_max < $salary_min ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Salary max must be >= salary min.', 'zeko-jobs' ) ) );
		}

		// ZekoPay: charge for new job posting if payments enabled. Runs after.
		// validation so invalid submissions are never billed.
		if ( ! $edit_job_id ) {
			$billing_result = self::maybe_charge_posting_fee( $user_id );
			if ( is_wp_error( $billing_result ) ) {
				wp_send_json_error(
					array(
						'message'  => $billing_result->get_error_message(),
						'redirect' => $billing_result->get_error_data( 'redirect' ) ?? '',
						'payment'  => true,
					)
				);
			}
		}

		$geo       = self::geocode_location( $location );
		$latitude  = $geo ? $geo['latitude'] : null;
		$longitude = $geo ? $geo['longitude'] : null;

		$zeko_db = Zeko_Jobs_DB::get_instance();

		// Record how this posting is paid for (free / pack credit / fee charged).
		$payment_status = 'free';
		if ( ! $edit_job_id ) {
			$payment_type   = $zeko_db->get_posting_payment_type( $user_id );
			$payment_status = ( 'free' === $payment_type ) ? 'free' : 'paid';
		}

		if ( $edit_job_id ) {
			$existing = $zeko_db->get_job_by_id_and_employer( $edit_job_id, $user_id );
			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Job not found or you do not have permission to edit it.', 'zeko-jobs' ) ) );
			}

			$result = $zeko_db->update_job(
				$edit_job_id,
				array(
					'title'                => $title,
					'description'          => $description,
					'location'             => $location,
					'type'                 => $type,
					'salary_min'           => $salary_min,
					'salary_max'           => $salary_max,
					'category'             => $category,
					'experience_level'     => $experience_level,
					'remote_option'        => $remote_option,
					'application_deadline' => $application_deadline ?: null,
					'responsibilities'     => $responsibilities,
					'requirements'         => $requirements,
					'qualifications'       => $qualifications,
					'benefits'             => $benefits,
					'company_industry'     => $company_industry,
					'company_size'         => $company_size,
					'company_website'      => $company_website,
					'company_about'        => $company_about,
					'is_easy_apply'        => $is_easy_apply,
					'required_skills'      => $required_skills,
					'company_id'           => $company_id ?: null,
					'latitude'             => $latitude,
					'longitude'            => $longitude,
				)
			);

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Failed to update job.', 'zeko-jobs' ) ) );
			}

			Zeko_Jobs::get_instance()->log_activity(
				$user_id,
				'job_updated',
				sprintf( 'Job updated: %s', $title ),
				$edit_job_id,
				array(
					'job_title' => $title,
					'job_slug'  => $existing['slug'],
				)
			);

			/**
			 * Fires after a job is updated.
			 *
			 * @param int   $edit_job_id The job ID.
			 * @param int   $user_id     The employer user ID.
			 * @param array $job_data    The updated job data.
			 */
			do_action(
				'zeko_job_updated',
				$edit_job_id,
				$user_id,
				array(
					'title' => $title,
					'slug'  => $existing['slug'],
				)
			);

			wp_send_json_success(
				array(
					'message'  => esc_html__( 'Job updated successfully.', 'zeko-jobs' ),
					'job_slug' => $existing['slug'],
				)
			);
			return;
		}

		$slug        = sanitize_title( $title . '-' . $location . '-' . uniqid() );
		$created_at  = current_time( 'mysql' );
		$expiry_days = (int) Zeko_Jobs_Admin::get_setting( 'expiry_days', 30 );
		$expires_at  = $expiry_days > 0
			? gmdate( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days" ) )
			: gmdate( 'Y-m-d H:i:s', strtotime( '+10 years' ) );

		$initial_status   = 'publish';
		$require_approval = (int) Zeko_Jobs_Admin::get_setting( 'require_approval', 0 );
		if ( $require_approval ) {
			$is_verified = (int) get_user_meta( $user_id, 'zeko_verified_employer', true );
			if ( ! $is_verified ) {
				$initial_status = 'pending';
			}
		}

		$result = $zeko_db->insert_job(
			array(
				'employer_id'          => (int) $user_id,
				'title'                => $title,
				'slug'                 => $slug,
				'description'          => $description,
				'location'             => $location,
				'type'                 => $type,
				'salary_min'           => $salary_min,
				'salary_max'           => $salary_max,
				'status'               => $initial_status,
				'created_at'           => $created_at,
				'expires_at'           => $expires_at,
				'category'             => $category,
				'experience_level'     => $experience_level,
				'remote_option'        => $remote_option,
				'application_deadline' => $application_deadline ?: null,
				'responsibilities'     => $responsibilities,
				'requirements'         => $requirements,
				'qualifications'       => $qualifications,
				'benefits'             => $benefits,
				'company_industry'     => $company_industry,
				'company_size'         => $company_size,
				'company_website'      => $company_website,
				'company_about'        => $company_about,
				'is_easy_apply'        => $is_easy_apply,
				'required_skills'      => $required_skills,
				'company_id'           => $company_id ?: null,
				'payment_status'       => $payment_status,
				'latitude'             => $latitude,
				'longitude'            => $longitude,
			)
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to create job.', 'zeko-jobs' ) ) );
		}

		$job_status = $initial_status;

		Zeko_Jobs::get_instance()->log_activity(
			$user_id,
			'job_posted',
			sprintf( 'New job posted: %s', $title ),
			$result,
			array(
				'job_title' => $title,
				'job_slug'  => $slug,
			)
		);

		/**
		 * Fires after a new job is published.
		 *
		 * @param int   $result    The new job ID.
		 * @param int   $user_id   The employer user ID.
		 * @param array $job_data  The job data array.
		 */
		do_action(
			'zeko_job_created',
			$result,
			$user_id,
			array(
				'title' => $title,
				'slug'  => $slug,
			)
		);

		if ( 'pending' === $job_status ) {
			wp_send_json_success(
				array(
					'message'  => esc_html__( 'Job submitted for review.', 'zeko-jobs' ),
					'job_slug' => $slug,
				)
			);
		} else {
			wp_send_json_success(
				array(
					'message'  => esc_html__( 'Job published successfully.', 'zeko-jobs' ),
					'job_slug' => $slug,
				)
			);
		}
	}

	/**
	 * Handle job application.
	 */
	public function handle_job_application(): void {
		check_ajax_referer( 'zeko_job_apply', 'zeko_job_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		if ( ! Zeko_Jobs_Rate_Limiter::check( 'apply' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Too many applications. Please try again later.', 'zeko-jobs' ) ), 429 );
		}

		$job_id           = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$cover_letter     = isset( $_POST['cover_letter'] ) ? sanitize_text_field( wp_unslash( $_POST['cover_letter'] ) ) : '';
		$user_id          = get_current_user_id();
		$saved_resume_url = isset( $_POST['saved_resume_url'] ) ? esc_url_raw( wp_unslash( $_POST['saved_resume_url'] ) ) : '';
		$use_saved_resume = ( ! empty( $saved_resume_url ) && ( empty( $_FILES['resume']['name'] ) || 'saved' === ( sanitize_text_field( wp_unslash( $_POST['resume_mode'] ?? '' ) ) ) ) );

		if ( ! $job_id || empty( $cover_letter ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing required fields.', 'zeko-jobs' ) ) );
		}

		if ( ! $use_saved_resume && empty( $_FILES['resume']['name'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please upload a resume or use your saved resume.', 'zeko-jobs' ) ) );
		}

		// Prevent duplicate applications.
		$zeko_db = Zeko_Jobs_DB::get_instance();
		if ( $zeko_db->has_applied( $job_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You have already applied for this job.', 'zeko-jobs' ) ) );
		}

		// Rate limit: max applications per day.
		$max_daily   = (int) apply_filters( 'zeko_jobs_max_daily_applications', (int) Zeko_Jobs_Admin::get_setting( 'max_daily_applications', 20 ) );
		$daily_count = $zeko_db->get_user_daily_application_count( $user_id );
		if ( $daily_count >= $max_daily ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
					/* translators: %d: max applications per day */
						esc_html__( 'You have reached the maximum of %d applications per day.', 'zeko-jobs' ),
						$max_daily
					),
				)
			);
		}

		// Only ever store an application (and its resume) for a real job.
		$job_data = $zeko_db->get_job( $job_id );
		if ( ! $job_data ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This job no longer exists.', 'zeko-jobs' ) ) );
		}

		$resume_url = '';
		if ( $use_saved_resume ) {
			$resume_url = $saved_resume_url;
		} else {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$uploaded_file = $_FILES['resume']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES validated via Zeko_Core_Upload::validate_file()/file-type checks below.

			$allowed_mimes = array(
				'pdf'  => 'application/pdf',
				'doc'  => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			);

			if ( class_exists( 'Zeko_Core_Upload' ) ) {
				$valid = Zeko_Core_Upload::validate_file( $uploaded_file, 'document', 5 * 1024 * 1024 );
				if ( is_wp_error( $valid ) ) {
					wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
				}
			} else {
				$file_ext = strtolower( pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) );

				if ( ! isset( $allowed_mimes[ $file_ext ] ) ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Only PDF and DOC/DOCX files are allowed.', 'zeko-jobs' ) ) );
				}

				if ( $uploaded_file['size'] > 5 * 1024 * 1024 ) {
					wp_send_json_error( array( 'message' => esc_html__( 'File size must be under 5MB.', 'zeko-jobs' ) ) );
				}

				$mime_check = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );
				if ( ! $mime_check['ext'] || ! in_array( $mime_check['type'], $allowed_mimes, true ) ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Invalid file type. Only PDF and DOC/DOCX are accepted.', 'zeko-jobs' ) ) );
				}
			}

			$upload_dir = wp_upload_dir();
			$zeko_dir   = $upload_dir['basedir'] . '/zeko/resumes';

			if ( ! file_exists( $zeko_dir ) ) {
				wp_mkdir_p( $zeko_dir );
			}

			$movefile = wp_handle_upload(
				$uploaded_file,
				array(
					'test_form' => false,
					'mimes'     => $allowed_mimes,
					'upload'    => array( 'url' => $upload_dir['baseurl'] . '/zeko/resumes' ),
				)
			);

			if ( ! $movefile || isset( $movefile['error'] ) ) {
				wp_send_json_error( array( 'message' => isset( $movefile['error'] ) ? $movefile['error'] : esc_html__( 'Upload failed.', 'zeko-jobs' ) ) );
			}

			$resume_url = $movefile['url'];

			if ( 'pdf' === $file_ext && class_exists( 'Imagick' ) ) {
				$compressed = self::compress_pdf( $movefile['file'] );
				if ( $compressed ) {
					$resume_url = str_replace( basename( $resume_url ), basename( $compressed ), $resume_url );
				}
			}
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$result  = $zeko_db->insert_application(
			array(
				'job_id'       => $job_id,
				'seeker_id'    => $user_id,
				'resume_url'   => $resume_url,
				'cover_letter' => $cover_letter,
				'status'       => 'pending',
				'applied_at'   => current_time( 'mysql' ),
			)
		);

		if ( $result ) {
			// If Zeko messaging exists, send intro message.
			if ( function_exists( 'zeko_send_message' ) ) {
				$employer_id = (int) $job_data['employer_id'];
				$initial     = sprintf(
					/* translators: %s: job title */
					__( "Hello! I have just applied for the '%s' position you posted. I've attached my resume and filled out the application. Looking forward to discussing this opportunity!", 'zeko-jobs' ),
					$job_data['title']
				);
				zeko_send_message( $user_id, $employer_id, $initial, 'jobs' );
			}

			/**
			 * Fires after a job application is submitted.
			 *
			 * @param int $application_id The new application ID.
			 * @param int $job_id         The job ID applied to.
			 * @param int $user_id        The applicant user ID.
			 */
			do_action( 'zeko_job_application_submitted', $result, $job_id, $user_id );

			// In-app notification to employer (batched per job — consecutive.
			// unread "New Application" notifications consolidate into one row).
			if ( $job_data ) {
				$zeko_db->batch_application_notification(
					(int) $job_data['employer_id'],
					$job_id,
					$job_data['title']
				);
			}

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Application submitted successfully.', 'zeko-jobs' ),
				)
			);
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Database error. Please try again.', 'zeko-jobs' ) ) );
	}

	/**
	 * Handle bulk apply.
	 */
	public function handle_bulk_apply(): void {
		check_ajax_referer( 'zeko_job_apply', 'zeko_job_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id      = get_current_user_id();
		$job_ids      = isset( $_POST['job_ids'] ) ? array_map( 'intval', (array) $_POST['job_ids'] ) : array();
		$cover_letter = isset( $_POST['cover_letter'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cover_letter'] ) ) : '';

		if ( empty( $job_ids ) || empty( $cover_letter ) || empty( $_FILES['resume'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing required fields.', 'zeko-jobs' ) ) );
		}

		$job_ids = array_filter( array_unique( $job_ids ) );
		if ( count( $job_ids ) > 20 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Maximum 20 jobs per bulk apply.', 'zeko-jobs' ) ) );
		}

		// Rate limit: check daily application count against remaining quota.
		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$max_daily   = (int) apply_filters( 'zeko_jobs_max_daily_applications', 20 );
		$daily_count = $zeko_db->get_user_daily_application_count( $user_id );
		$remaining   = $max_daily - $daily_count;
		if ( $remaining <= 0 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
					/* translators: %d: max applications per day */
						esc_html__( 'You have reached the maximum of %d applications per day.', 'zeko-jobs' ),
						$max_daily
					),
				)
			);
		}
		if ( count( $job_ids ) > $remaining ) {
			$job_ids = array_slice( $job_ids, 0, $remaining );
		}

		// Only ever reach the upload for jobs that actually exist and accept applicants.
		$valid_jobs = $zeko_db->get_valid_job_ids( $job_ids );
		if ( empty( $valid_jobs ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'None of the selected jobs are accepting applications.', 'zeko-jobs' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded_file = $_FILES['resume']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES validated via Zeko_Core_Upload::validate_file()/file-type checks below.
		$allowed_mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		if ( class_exists( 'Zeko_Core_Upload' ) ) {
			$valid = Zeko_Core_Upload::validate_file( $uploaded_file, 'document', 5 * 1024 * 1024 );
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
			}
		} else {
			$file_ext = strtolower( pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) );

			if ( ! isset( $allowed_mimes[ $file_ext ] ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Only PDF and DOC/DOCX files are allowed.', 'zeko-jobs' ) ) );
			}
			if ( $uploaded_file['size'] > 5 * 1024 * 1024 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'File size must be under 5MB.', 'zeko-jobs' ) ) );
			}
		}

		$upload_dir = wp_upload_dir();
		$zeko_dir   = $upload_dir['basedir'] . '/zeko/resumes';
		if ( ! file_exists( $zeko_dir ) ) {
			wp_mkdir_p( $zeko_dir );
		}

		$movefile = wp_handle_upload(
			$uploaded_file,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
				'upload'    => array( 'url' => $upload_dir['baseurl'] . '/zeko/resumes' ),
			)
		);

		if ( ! $movefile || isset( $movefile['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $movefile['error'] ) ? $movefile['error'] : esc_html__( 'Upload failed.', 'zeko-jobs' ) ) );
		}

		$resume_url = $movefile['url'];

		if ( 'pdf' === $file_ext && class_exists( 'Imagick' ) ) {
			$compressed = self::compress_pdf( $movefile['file'] );
			if ( $compressed ) {
				$resume_url = str_replace( basename( $resume_url ), basename( $compressed ), $resume_url );
			}
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();

		$existing_apps = $zeko_db->get_existing_application_job_ids( $user_id, $job_ids );

		$applied = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $valid_jobs as $jid ) {
			if ( in_array( $jid, $existing_apps, true ) ) {
				++$skipped;
				continue;
			}

			$result = $zeko_db->insert_application(
				array(
					'job_id'       => $jid,
					'seeker_id'    => $user_id,
					'resume_url'   => $resume_url,
					'cover_letter' => $cover_letter,
					'status'       => 'awaiting_review',
					'applied_at'   => current_time( 'mysql' ),
				)
			);

			if ( $result ) {
				++$applied;
			} else {
				++$failed;
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: 1: applied count, 2: skipped count, 3: failed count */
					esc_html__( 'Applied to %1$d jobs. %2$d skipped (already applied). %3$d failed.', 'zeko-jobs' ),
					$applied,
					$skipped,
					$failed
				),
				'applied' => $applied,
				'skipped' => $skipped,
				'failed'  => $failed,
			)
		);
	}

	/**
	 * Handle job bookmark.
	 */
	public function handle_job_bookmark(): void {
		check_ajax_referer( 'zeko_job_bookmark', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in to bookmark jobs.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		$job_id  = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job ID.', 'zeko-jobs' ) ) );
		}

		$bookmarks = get_user_meta( $user_id, 'zeko_bookmarked_jobs', true );
		if ( ! is_array( $bookmarks ) ) {
			$bookmarks = array();
		}

		if ( in_array( $job_id, $bookmarks, true ) ) {
			$bookmarks = array_diff( $bookmarks, array( $job_id ) );
			update_user_meta( $user_id, 'zeko_bookmarked_jobs', array_values( $bookmarks ) );
			wp_send_json_success(
				array(
					'bookmarked' => false,
					'message'    => esc_html__( 'Bookmark removed.', 'zeko-jobs' ),
				)
			);
		}

		$bookmarks[] = $job_id;
		update_user_meta( $user_id, 'zeko_bookmarked_jobs', $bookmarks );
		wp_send_json_success(
			array(
				'bookmarked' => true,
				'message'    => esc_html__( 'Job bookmarked successfully!', 'zeko-jobs' ),
			)
		);
	}

	/**
	 * Handle message employer.
	 */
	public function handle_message_employer(): void {
		check_ajax_referer( 'zeko_job_message_employer', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in to message employers.', 'zeko-jobs' ) ) );
		}

		if ( ! Zeko_Jobs_Rate_Limiter::check( 'message' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Too many messages. Please slow down.', 'zeko-jobs' ) ), 429 );
		}

		$sender_id    = get_current_user_id();
		$recipient_id = isset( $_POST['employer_id'] ) ? (int) $_POST['employer_id'] : 0;
		$job_title    = isset( $_POST['job_title'] ) ? sanitize_text_field( wp_unslash( $_POST['job_title'] ) ) : '';

		if ( ! $recipient_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid employer ID.', 'zeko-jobs' ) ) );
		}

		if ( $sender_id === $recipient_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You cannot message yourself.', 'zeko-jobs' ) ) );
		}

		if ( ! function_exists( 'zeko_send_message' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Theme messaging system is currently unavailable.', 'zeko-jobs' ) ) );
		}

		$initial_message = sprintf(
			/* translators: %s: job title */
			__( "Hello, I am interested in your job posting for '%s' and would love to ask a few questions.", 'zeko-jobs' ),
			$job_title
		);

		zeko_send_message( $sender_id, $recipient_id, $initial_message, 'jobs' );

		wp_send_json_success(
			array(
				'redirect_url' => home_url( '/messages/' ),
				'message'      => esc_html__( 'Direct message initiated! Redirecting to messages...', 'zeko-jobs' ),
			)
		);
	}
	/**
	 * Handle update status.
	 */
	public function handle_update_status(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$new_status     = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;
		$job_id         = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

		if ( ! $new_status ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$employer_id = get_current_user_id();

		if ( $application_id ) {
			$app = $zeko_db->get_application( $application_id );
			if ( ! $app ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Application not found.', 'zeko-jobs' ) ) );
			}

			$job = $zeko_db->get_job( (int) $app['job_id'] );
			if ( ! $job || (int) $job['employer_id'] !== $employer_id ) {
				wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to update this application.', 'zeko-jobs' ) ) );
			}

			$allowed_transitions = array(
				'awaiting_review' => array( 'reviewed' ),
				'reviewed'        => array( 'awaiting_review', 'contacting' ),
				'contacting'      => array( 'reviewed', 'interviewing' ),
				'interviewing'    => array( 'contacting', 'offered' ),
				'offered'         => array( 'interviewing', 'hired' ),
				'hired'           => array( 'offered' ),
			);

			$current_status = $app['status'];
			if ( ! isset( $allowed_transitions[ $current_status ] ) || ! in_array( $new_status, $allowed_transitions[ $current_status ], true ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid status transition.', 'zeko-jobs' ) ) );
			}

			$updated = $zeko_db->update_application_status( $application_id, $new_status, $employer_id );
		} elseif ( $job_id ) {
			$updated = $zeko_db->update_job_status( $job_id, $employer_id, $new_status );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		if ( $updated ) {
			/**
	 * Fires after an application status is changed.
	 *
	 * @param int    $application_id The application ID.
	 * @param string $new_status     The new status.
	 * @param int    $employer_id    The employer user ID.
	 */
			do_action( 'zeko_job_application_status_changed', $application_id, $new_status, $employer_id );

			// In-app notification to seeker.
			if ( $application_id && isset( $app ) && $app ) {
				$seeker_id     = (int) $app['seeker_id'];
				$job_title     = isset( $job ) && $job ? $job['title'] : __( 'a job', 'zeko-jobs' );
				$status_labels = array(
					'reviewed'        => __( 'Reviewed', 'zeko-jobs' ),
					'contacting'      => __( 'Contacting', 'zeko-jobs' ),
					'interviewing'    => __( 'Interviewing', 'zeko-jobs' ),
					'offered'         => __( 'Offered', 'zeko-jobs' ),
					'hired'           => __( 'Hired', 'zeko-jobs' ),
					'awaiting_review' => __( 'Awaiting Review', 'zeko-jobs' ),
				);
				$status_label  = $status_labels[ $new_status ] ?? ucfirst( str_replace( '_', ' ', $new_status ) );

				self::add_notification(
					$seeker_id,
					'status_changed',
					__( 'Application Update', 'zeko-jobs' ),
					sprintf(
					/* translators: %1$s: job title, %2$s: new status */
						__( 'Your application for "%1$s" is now: %2$s', 'zeko-jobs' ),
						$job_title,
						$status_label
					),
					'',
					'megaphone',
					array(
						'application_id' => $application_id,
						'status'         => $new_status,
					)
				);
			}

			wp_send_json_success( array( 'message' => esc_html__( 'Status updated.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not update status.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Seeker withdraws an application.
	 */
	public function handle_withdraw_application(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;
		if ( ! $application_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid application.', 'zeko-jobs' ) ) );
		}

		$seeker_id = get_current_user_id();
		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$withdrawn = $zeko_db->withdraw_application( $application_id, $seeker_id );

		if ( $withdrawn ) {
			/**
			 * Fires after an application is withdrawn.
			 *
			 * @param int $application_id The application ID.
			 * @param int $seeker_id      The seeker user ID.
			 */
			do_action( 'zeko_job_application_withdrawn', $application_id, $seeker_id );

			// In-app notification to employer.
			$app = $zeko_db->get_application( $application_id );
			if ( $app ) {
				$job         = $zeko_db->get_job( (int) $app['job_id'] );
				$seeker_name = get_the_author_meta( 'display_name', $seeker_id );
				$job_title   = $job ? $job['title'] : __( 'a job', 'zeko-jobs' );

				self::add_notification(
					$job ? (int) $job['employer_id'] : 0,
					'application_withdrawn',
					__( 'Application Withdrawn', 'zeko-jobs' ),
					sprintf(
						/* translators: %1$s: seeker name, %2$s: job title */
						__( '%1$s withdrew their application for "%2$s"', 'zeko-jobs' ),
						$seeker_name,
						$job_title
					),
					'',
					'exit',
					array( 'application_id' => $application_id )
				);
			}

			wp_send_json_success( array( 'message' => esc_html__( 'Application withdrawn.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not withdraw application.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Employer toggles job featured status.
	 */
	public function handle_toggle_featured(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$employer_id = get_current_user_id();
		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$settings    = $zeko_db::get_billing_settings();

		// Check current featured state to determine if we're boosting or unboosting.
		$job = $zeko_db->get_job( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Job not found.', 'zeko-jobs' ) ) );
		}

		// The job must belong to the current user (or an admin) BEFORE any.
		// boost fee is charged — otherwise a non-owner could trigger a wallet.
		// charge against a job they do not own.
		if ( (int) $job['employer_id'] !== $employer_id && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to feature this job.', 'zeko-jobs' ) ) );
		}
		$is_featured_now = (bool) $job['is_featured'];

		// If boosting (not unboosting) and payments are enabled, charge.
		if ( ! $is_featured_now && $zeko_db::payments_enabled() ) {
			$boost_fee = (float) ( $settings['boost_fee'] ?? 10 );
			if ( $boost_fee > 0 && class_exists( 'Zeko_Pay_SDK' ) ) {
				$sdk    = new Zeko_Pay_SDK();
				$result = $sdk->charge(
					$employer_id,
					$boost_fee,
					'Job boost fee',
					array(
						'source' => 'zeko_jobs',
						'job_id' => $job_id,
						'type'   => 'boost_fee',
					)
				);

				if ( empty( $result['success'] ) ) {
					$message = $result['message'] ?? esc_html__( 'Insufficient funds. Please top up your ZekoPay wallet.', 'zeko-jobs' );
					wp_send_json_error(
						array(
							'featured' => false,
							'message'  => $message,
							'redirect' => home_url( '/wallet/' ),
						)
					);
				}

				$zeko_db->log_billing(
					array(
						'user_id'        => $employer_id,
						'job_id'         => $job_id,
						'type'           => 'boost_fee',
						'amount'         => $boost_fee,
						'zeko_pay_tx_id' => $result['tx_id'] ?? null,
						'status'         => 'completed',
						'description'    => 'Job boost fee',
					)
				);
			}
		}

		$result = $zeko_db->toggle_featured( $job_id, $employer_id );

		if ( null !== $result ) {
			/**
			 * Fires after a job's featured status is toggled.
			 *
			 * @param int  $job_id     The job ID.
			 * @param bool $is_featured Whether the job is now featured.
			 */
			do_action( 'zeko_job_featured_toggled', $job_id, $result );

			wp_send_json_success(
				array(
					'message'  => $result ? esc_html__( 'Job marked as featured.', 'zeko-jobs' ) : esc_html__( 'Featured removed.', 'zeko-jobs' ),
					'featured' => $result,
				)
			);
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not update job.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Employer exports applications for a job to CSV.
	 */
	public function handle_export_applications(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id      = isset( $_GET['job_id'] ) ? (int) $_GET['job_id'] : 0;
		$employer_id = get_current_user_id();

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$owns    = $zeko_db->get_job_by_id_and_employer( $job_id, $employer_id );
		if ( ! $owns ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'zeko-jobs' ) ) );
		}

		$apps = $zeko_db->get_applications_for_job_export( $job_id );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=applications-job-' . $job_id . '.csv' );

		$job_views = (int) ( $owns['views'] ?? 0 );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Job Title', 'Total Views' ) );
		fputcsv( $output, array( $owns['title'], $job_views ) );
		fputcsv( $output, array() );
		fputcsv( $output, array( 'ID', 'Name', 'Username', 'Email', 'Status', 'Applied', 'Cover Letter' ) );
		foreach ( $apps as $app ) {
			fputcsv(
				$output,
				array(
					$app['id'],
					$app['display_name'],
					$app['user_login'],
					$app['user_email'],
					$app['status'],
					$app['applied_at'],
					wp_strip_all_tags( $app['cover_letter'] ),
				)
			);
		}
		fclose( $output );
		exit;
	}

	/**
	 * AJAX: Add a note to an application.
	 */
	public function handle_add_application_note(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;
		$note           = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( ! $application_id || ! $note ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		if ( ! $this->can_access_application( $application_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have access to this application.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$saved   = $zeko_db->add_application_note( $application_id, get_current_user_id(), $note );

		if ( $saved ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Note added.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not add note.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get notes for an application.
	 */
	public function handle_get_application_notes(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_GET['application_id'] ) ? (int) $_GET['application_id'] : 0;
		if ( ! $application_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid application.', 'zeko-jobs' ) ) );
		}

		if ( ! $this->can_access_application( $application_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have access to this application.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$notes   = $zeko_db->get_application_notes( $application_id );

		wp_send_json_success( array( 'notes' => $notes ) );
	}

	/**
	 * AJAX: Get status history for an application.
	 */
	public function handle_get_application_history(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_GET['application_id'] ) ? (int) $_GET['application_id'] : 0;
		if ( ! $application_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid application.', 'zeko-jobs' ) ) );
		}

		if ( ! $this->can_access_application( $application_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have access to this application.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$history = $zeko_db->get_status_history( $application_id );

		wp_send_json_success( array( 'history' => $history ) );
	}

	/**
	 * Compress a PDF file using Imagick.
	 *
	 * @param string $file_path File path.
	 */
	public static function compress_pdf( string $file_path ): ?string {
		if ( ! class_exists( 'Imagick' ) ) {
			return null;
		}

		try {
			$imagick = new Imagick();
			$imagick->setResolution( 150, 150 );
			$imagick->readImage( $file_path );
			$imagick->setImageFormat( 'pdf' );
			$imagick->setImageCompression( Imagick::COMPRESSION_JPEG );
			$imagick->setImageCompressionQuality( 70 );
			$imagick->stripImage();

			$compressed_path = preg_replace( '/\.pdf$/i', '-compressed.pdf', $file_path );
			$imagick->writeImage( $compressed_path );
			$imagick->clear();
			$imagick->destroy();

			return file_exists( $compressed_path ) ? $compressed_path : null;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Geocode a location string to lat/lng using Nominatim (OpenStreetMap).
	 *
	 * @return array{latitude: float, longitude: float}|null
	 * @param string $location Location.
	 */
	public static function geocode_location( string $location ): ?array {
		if ( empty( $location ) ) {
			return null;
		}

		$cache_key = 'zeko_geocode_' . md5( $location );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://nominatim.openstreetmap.org/search',
			array(
				'timeout' => 5,
				'headers' => array( 'User-Agent' => 'ZekoJobs/2.2 (job-board)' ),
				'body'    => array(
					'q'      => $location,
					'format' => 'json',
					'limit'  => 1,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
			return null;
		}

		$coords = array(
			'latitude'  => (float) $body[0]['lat'],
			'longitude' => (float) $body[0]['lon'],
		);

		set_transient( $cache_key, $coords, MONTH_IN_SECONDS );

		return $coords;
	}

	/**
	 * AJAX: Add a reminder for a job.
	 */
	public function handle_add_reminder(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id    = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$remind_at = isset( $_POST['remind_at'] ) ? sanitize_text_field( wp_unslash( $_POST['remind_at'] ) ) : '';

		if ( ! $job_id || empty( $remind_at ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$reminder_id = $zeko_db->add_reminder( get_current_user_id(), $job_id, $remind_at );

		if ( $reminder_id ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Reminder set.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not set reminder.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Delete a reminder.
	 */
	public function handle_delete_reminder(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$reminder_id = isset( $_POST['reminder_id'] ) ? (int) $_POST['reminder_id'] : 0;
		if ( ! $reminder_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid reminder.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$deleted = $zeko_db->delete_reminder( $reminder_id, get_current_user_id() );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Reminder removed.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not remove reminder.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get reminders for current user.
	 */
	public function handle_get_reminders(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$reminders = $zeko_db->get_user_reminders( get_current_user_id() );

		wp_send_json_success( array( 'reminders' => $reminders ) );
	}
	/**
	 * Handle easy apply.
	 */
	public function handle_easy_apply(): void {
		check_ajax_referer( 'zeko_job_easy_apply', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$already = $zeko_db->has_applied( $job_id, get_current_user_id() );
		if ( $already ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You have already applied.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$result  = $zeko_db->insert_application(
			array(
				'job_id'       => $job_id,
				'seeker_id'    => get_current_user_id(),
				'resume_url'   => '',
				'cover_letter' => '',
				'status'       => 'awaiting_review',
				'applied_at'   => current_time( 'mysql' ),
			)
		);

		if ( $result ) {
			$job_data = $zeko_db->get_job( $job_id );
			if ( $job_data ) {
				Zeko_Jobs::get_instance()->log_activity(
					get_current_user_id(),
					'job_applied',
					sprintf( 'Applied for job: %s', $job_data['title'] ),
					$job_id,
					array(
						'job_title' => $job_data['title'],
						'job_slug'  => $job_data['slug'],
					)
				);
			}
			wp_send_json_success( array( 'message' => esc_html__( 'Application submitted successfully.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Failed to submit application.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Not interested feedback.
	 */
	public function handle_not_interested(): void {
		check_ajax_referer( 'zeko_job_not_interested', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$user_id        = get_current_user_id();
		$not_interested = get_user_meta( $user_id, 'zeko_not_interested_jobs', true );
		if ( ! is_array( $not_interested ) ) {
			$not_interested = array();
		}
		$not_interested[] = $job_id;
		update_user_meta( $user_id, 'zeko_not_interested_jobs', array_unique( $not_interested ) );

		wp_send_json_success( array( 'message' => esc_html__( 'Feedback sent. We will show fewer jobs like this.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Report (flag) a job listing for review.
	 */
	public function handle_job_flag(): void {
		check_ajax_referer( 'zeko_job_flag', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in to report a listing.', 'zeko-jobs' ) ) );
		}

		$job_id  = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';

		$allowed_reasons = array( 'inappropriate', 'scam', 'misleading', 'expired', 'other' );
		if ( ! in_array( $reason, $allowed_reasons, true ) ) {
			$reason = 'other';
		}

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid listing.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Listing not found.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		if ( (int) $job['employer_id'] === $user_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You cannot report your own listing.', 'zeko-jobs' ) ) );
		}

		$flag_id = $zeko_db->add_job_flag( $job_id, $user_id, $reason, $details );
		if ( ! $flag_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You have already reported this listing. Our team will review it.', 'zeko-jobs' ) ) );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Thank you. This listing has been reported for review.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Follow / unfollow a company from the company profile page.
	 */
	public function handle_follow_company(): void {
		check_ajax_referer( 'zeko_job_follow_company', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in to follow this company.', 'zeko-jobs' ) ) );
		}

		$employer_id = isset( $_POST['employer_id'] ) ? (int) $_POST['employer_id'] : 0;
		if ( ! $employer_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid company.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id === $employer_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You cannot follow your own company.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$company = $zeko_db->get_company_by_employer( $employer_id );
		if ( ! $company ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Company not found.', 'zeko-jobs' ) ) );
		}

		$action       = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
		$is_following = $zeko_db->is_following_company( $user_id, $employer_id );

		if ( 'unfollow' === $action || ( 'toggle' !== $action && $is_following ) ) {
			$zeko_db->remove_company_follower( $user_id, $employer_id );
			$is_following = false;
		} elseif ( 'follow' === $action || 'toggle' === $action ) {
			$zeko_db->add_company_follower( $user_id, $employer_id );
			$is_following = true;
		}

		wp_send_json_success(
			array(
				'message'        => $is_following ? esc_html__( 'You are now following this company.', 'zeko-jobs' ) : esc_html__( 'You are no longer following this company.', 'zeko-jobs' ),
				'is_following'   => $is_following,
				'follower_count' => $zeko_db->count_company_followers( $employer_id ),
			)
		);
	}

	/**
	 * AJAX: Search opt-in seeker resumes (employers only).
	 */
	public function handle_search_resumes(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		$zeko_db = Zeko_Jobs_DB::get_instance();

		// Employer gate: must have posted a job or manage a company profile.
		$has_jobs    = (bool) $zeko_db->get_employer_all_jobs( $user_id );
		$has_company = (bool) $zeko_db->get_company_by_employer( $user_id );
		if ( ! $has_jobs && ! $has_company ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This feature is available to employers.', 'zeko-jobs' ) ) );
		}

		$args = array(
			'keyword'  => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
			'skills'   => isset( $_POST['skills'] ) ? sanitize_text_field( wp_unslash( $_POST['skills'] ) ) : '',
			'location' => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '',
			'limit'    => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 20,
		);

		$results = $zeko_db->search_resumes( $args );

		$candidates = array_map(
			function ( $row ) use ( $zeko_db ) {
				$resume_url = $row['resume_url'] ?: '';
				if ( ! $resume_url ) {
						$docs = $zeko_db->get_resumes( (int) $row['ID'] );
					if ( ! empty( $docs ) && ! empty( $docs[0]['file_url'] ) ) {
						$resume_url = $docs[0]['file_url'];
					}
				}

				return array(
					'id'          => $row['ID'],
					'name'        => $row['display_name'],
					'location'    => $row['location'],
					'skills'      => $row['skills'],
					'bio'         => wp_trim_words( (string) $row['bio'], 40 ),
					'resume_url'  => $resume_url,
					'avatar'      => get_avatar_url( $row['ID'], array( 'size' => 64 ) ),
					'profile_url' => get_author_posts_url( $row['ID'] ),
				);
			},
			$results
		);

		wp_send_json_success( array( 'candidates' => $candidates ) );
	}

	/**
	 * AJAX: Bulk actions for jobs.
	 */
	public function handle_bulk_action(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_ids     = isset( $_POST['job_ids'] ) ? array_map( 'intval', $_POST['job_ids'] ) : array();
		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';

		if ( empty( $job_ids ) || ! $bulk_action ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$employer_id = get_current_user_id();
		$updated     = 0;

		foreach ( $job_ids as $job_id ) {
			if ( 'publish' === $bulk_action ) {
				$result = $zeko_db->update_job_status( $job_id, $employer_id, 'publish' );
			} elseif ( 'pause' === $bulk_action ) {
				$result = $zeko_db->update_job_status( $job_id, $employer_id, 'paused' );
			} elseif ( 'close' === $bulk_action ) {
				$result = $zeko_db->update_job_status( $job_id, $employer_id, 'closed' );
			} elseif ( 'feature' === $bulk_action ) {
				$result = $zeko_db->toggle_featured( $job_id, $employer_id );
			} elseif ( 'delete' === $bulk_action ) {
				$zeko_db->delete_job( $job_id, $employer_id );
				$result = true;
			} else {
				$result = false;
			}
			if ( $result ) {
				++$updated;
			}
		}

		/* translators: %d: number of updated jobs */
		wp_send_json_success( array( 'message' => sprintf( esc_html__( '%d jobs updated.', 'zeko-jobs' ), $updated ) ) );

		/**
		 * Fires after a bulk action is performed on jobs.
		 *
		 * @param string $bulk_action The action performed (publish, pause, close, feature, delete).
		 * @param array  $job_ids     The job IDs affected.
		 * @param int    $employer_id The employer user ID.
		 */
		do_action( 'zeko_job_bulk_action', $bulk_action, $job_ids, $employer_id );
	}

	/**
	 * AJAX: Duplicate a job listing.
	 */
	public function handle_duplicate_job(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$employer_id = get_current_user_id();

		$original = $zeko_db->get_job_by_id_and_employer( $job_id, $employer_id );

		if ( ! $original ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Job not found.', 'zeko-jobs' ) ) );
		}

		$new_slug = sanitize_title( $original['title'] ) . '-' . substr( md5( uniqid( '', true ) ), 0, 12 );

		$data = array(
			'title'                => $original['title'] . ' (Copy)',
			'slug'                 => $new_slug,
			'description'          => $original['description'],
			'responsibilities'     => $original['responsibilities'] ?? '',
			'requirements'         => $original['requirements'] ?? '',
			'qualifications'       => $original['qualifications'] ?? '',
			'benefits'             => $original['benefits'] ?? '',
			'location'             => $original['location'],
			'type'                 => $original['type'],
			'salary_min'           => $original['salary_min'] ?? 0,
			'salary_max'           => $original['salary_max'] ?? 0,
			'category'             => $original['category'] ?? '',
			'experience_level'     => $original['experience_level'] ?? '',
			'remote_option'        => $original['remote_option'] ?? '',
			'company_name'         => $original['company_name'] ?? '',
			'company_logo_id'      => $original['company_logo_id'] ?? 0,
			'company_industry'     => $original['company_industry'] ?? '',
			'company_size'         => $original['company_size'] ?? '',
			'company_website'      => $original['company_website'] ?? '',
			'company_about'        => $original['company_about'] ?? '',
			'is_easy_apply'        => $original['is_easy_apply'] ?? 0,
			'application_deadline' => $original['application_deadline'] ?? '',
			'employer_id'          => $employer_id,
			'status'               => 'draft',
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		);

		$inserted = $zeko_db->insert_job( $data );
		if ( $inserted ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Job duplicated as draft.', 'zeko-jobs' ),
					'new_id'  => $inserted,
				)
			);
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not duplicate job.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Delete a job listing.
	 */
	public function handle_delete_job(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$zeko_db     = Zeko_Jobs_DB::get_instance();
		$employer_id = get_current_user_id();

		$deleted = $zeko_db->delete_job( $job_id, $employer_id );
		if ( $deleted ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Job deleted.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not delete job.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get full application detail for modal.
	 */
	public function handle_get_application_detail(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_GET['application_id'] ) ? (int) $_GET['application_id'] : 0;
		if ( ! $application_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid application.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$app     = $zeko_db->get_application( $application_id );
		if ( ! $app ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Application not found.', 'zeko-jobs' ) ) );
		}

		$employer_id = get_current_user_id();
		if ( (int) $app['employer_id'] !== $employer_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'zeko-jobs' ) ) );
		}

		$notes         = $zeko_db->get_application_notes( $application_id );
		$history       = $zeko_db->get_status_history( $application_id );
		$rating        = $zeko_db->get_app_rating( $application_id, $employer_id );
		$app['rating'] = $rating ? (int) $rating['rating'] : 0;

		// Get email open status.
		$email_opens = $zeko_db->get_application_email_opens( $application_id );

		// Track application view for seeker notifications.
		$zeko_db->log_application_view( $application_id, $employer_id );

		wp_send_json_success(
			array(
				'application' => $app,
				'notes'       => $notes,
				'history'     => $history,
				'email_opens' => $email_opens,
			)
		);
	}
	/**
	 * Handle save search.
	 */
	public function handle_save_search(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$params = array(
			'name'         => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : 'Saved Search',
			'search_term'  => isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : null,
			'location'     => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : null,
			'job_type'     => isset( $_POST['job_type'] ) ? sanitize_text_field( wp_unslash( $_POST['job_type'] ) ) : null,
			'salary_min'   => isset( $_POST['salary_min'] ) ? (int) $_POST['salary_min'] : null,
			'salary_max'   => isset( $_POST['salary_max'] ) ? (int) $_POST['salary_max'] : null,
			'email_alerts' => isset( $_POST['email_alerts'] ) ? (int) $_POST['email_alerts'] : 0,
		);

		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$search_id = $zeko_db->add_saved_search( get_current_user_id(), $params );

		if ( $search_id ) {
			wp_send_json_success(
				array(
					'message'   => esc_html__( 'Search saved.', 'zeko-jobs' ),
					'search_id' => $search_id,
				)
			);
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not save search.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get saved searches for current user.
	 */
	public function handle_get_saved_searches(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$searches = $zeko_db->get_saved_searches( get_current_user_id() );

		wp_send_json_success( array( 'searches' => $searches ) );
	}

	/**
	 * AJAX: Delete a saved search.
	 */
	public function handle_delete_saved_search(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$search_id = isset( $_POST['search_id'] ) ? (int) $_POST['search_id'] : 0;
		if ( ! $search_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid search.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$deleted = $zeko_db->delete_saved_search( $search_id, get_current_user_id() );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Search deleted.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not delete search.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Upload a document.
	 */
	public function handle_upload_document(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		if ( empty( $_FILES['document'] ) || ! isset( $_FILES['document']['error'] ) || UPLOAD_ERR_OK !== $_FILES['document']['error'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'File upload failed.', 'zeko-jobs' ) ) );
		}

		$file = $_FILES['document']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES validated via Zeko_Core_Upload::validate_file()/file-type checks below.

		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;

		// Ownership: never attach a document to an application the user cannot access.
		if ( $application_id > 0 && ! $this->can_access_application( $application_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'zeko-jobs' ) ) );
		}

		$allowed_mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);

		if ( class_exists( 'Zeko_Core_Upload' ) ) {
			$valid = Zeko_Core_Upload::validate_file( $file, 'documents', 5 * 1024 * 1024 );
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
			}
		} else {
			if ( $file['size'] > 5 * 1024 * 1024 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'File size must be under 5MB.', 'zeko-jobs' ) ) );
			}

			$file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
			if ( ! isset( $allowed_mimes[ $file_ext ] ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG.', 'zeko-jobs' ) ) );
			}

			$mime_check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
			if ( ! $mime_check['ext'] || ! isset( $allowed_mimes[ $mime_check['ext'] ] ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'File content does not match its extension. Allowed: PDF, DOC, DOCX, JPG, PNG.', 'zeko-jobs' ) ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! isset( $mime_check ) ) {
			$mime_check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
		}

		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'zeko/documents/';
		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$this->secure_upload_dir( $target_dir );

		$filename    = wp_unique_filename( $target_dir, sanitize_file_name( $file['name'] ) );
		$target_path = trailingslashit( $target_dir ) . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to move uploaded file.', 'zeko-jobs' ) ) );
		}

		$file_url = trailingslashit( $upload_dir['baseurl'] ) . 'zeko/documents/' . $filename;

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$doc_id  = $zeko_db->add_document(
			get_current_user_id(),
			array(
				'application_id' => $application_id,
				'filename'       => $filename,
				'label'          => $label,
				'file_url'       => $file_url,
				'file_type'      => $mime_check['type'],
				'file_size'      => $file['size'],
			)
		);

		if ( $doc_id ) {
			/**
			 * Fires after a document is uploaded.
			 *
			 * @param int    $doc_id  The document ID.
			 * @param int    $user_id The uploading user ID.
			 * @param string $doc_type The MIME type of the document.
			 */
			do_action( 'zeko_job_document_uploaded', $doc_id, get_current_user_id(), $mime_check['type'] );

			wp_send_json_success(
				array(
					'message'  => esc_html__( 'Document uploaded.', 'zeko-jobs' ),
					'document' => array(
						'id'        => $doc_id,
						'filename'  => $filename,
						'label'     => $label,
						'file_url'  => $file_url,
						'file_type' => $mime_check['type'],
						'file_size' => $file['size'],
					),
				)
			);
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not save document.', 'zeko-jobs' ) ) );
	}

	/**
	 * Harden a custom upload directory against script execution.
	 * Drops a .htaccess that blocks PHP script execution and an index.html
	 * to prevent directory listing, mirroring core's upload protection.
	 *
	 * @param string $dir Absolute path to the upload directory.
	 */
	private function secure_upload_dir( string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# Zeko Jobs: deny script execution in this upload directory.\n";
			$rules .= "<FilesMatch \"\\.(php|php[0-9]|phtml|phar|cgi|pl|py|sh|asp|aspx)$\">\n";
			$rules .= "    Require all denied\n";
			$rules .= "</FilesMatch>\n";
			@file_put_contents( $htaccess, $rules, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '', LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Verify the current user is allowed to access an application.
	 * Allowed parties: the application's seeker, the employer of the linked
	 * job, or a site administrator. Prevents cross-user IDOR reads/writes.
	 *
	 * @return bool True when the current user may access the application.
	 * @param int $application_id Application row ID.
	 */
	private function can_access_application( int $application_id ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$app = Zeko_Jobs_DB::get_instance()->get_application( $application_id );
		if ( ! $app ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( (int) $app['seeker_id'] === $user_id ) {
			return true;
		}

		$job = Zeko_Jobs_DB::get_instance()->get_job( (int) $app['job_id'] );
		return $job && (int) $job['employer_id'] === $user_id;
	}

	/**
	 * AJAX: Get documents for current user.
	 */
	public function handle_get_documents(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_GET['application_id'] ) ? (int) $_GET['application_id'] : 0;
		$zeko_db        = Zeko_Jobs_DB::get_instance();
		$documents      = $zeko_db->get_documents( get_current_user_id(), $application_id );

		wp_send_json_success( array( 'documents' => $documents ) );
	}

	/**
	 * AJAX: Delete a document.
	 */
	public function handle_delete_document(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$document_id = isset( $_POST['document_id'] ) ? (int) $_POST['document_id'] : 0;
		if ( ! $document_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid document.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$deleted = $zeko_db->delete_document( $document_id, get_current_user_id() );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Document deleted.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not delete document.', 'zeko-jobs' ) ) );
	}

	/**
	 * Protected delivery of a candidate document.
	 * Serves the physical file through an authenticated, ownership-checked
	 * controller instead of the raw public upload URL. The database stores a
	 * delivery URL, not the public file path, and the controller streams the
	 * bytes with defense-in-depth response headers (attachment disposition,
	 * nosniff, no-cache, no-store, frame-busting) and exits without ever
	 * exposing directory contents.
	 * Access policy (mirrors can_access_application):
	 * - the candidate who owns the document (document.user_id),
	 * - the employer of the linked application's job,
	 * - anyone with manage_options.
	 *
	 * @return void
	 */
	public function handle_download_document(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in to download documents.', 'zeko-jobs' ), 401 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$document_id = isset( $_GET['document_id'] ) ? (int) $_GET['document_id'] : 0;
		if ( ! $document_id ) {
			wp_die( esc_html__( 'Invalid document.', 'zeko-jobs' ), 400 );
		}

		// Referer + nonce protects against CSRF delivery forging.
		check_admin_referer( 'zeko_job_download_' . $document_id );

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$doc     = $zeko_db->get_document( $document_id );

		if ( ! $doc ) {
			wp_die( esc_html__( 'Document not found.', 'zeko-jobs' ), 404 );
		}

		$user_id = get_current_user_id();

		// Ownership / authorization: the applicant who owns it, the employer.
		// of the linked application, or an administrator.
		$can_view = ( (int) $doc['user_id'] === $user_id )
			|| $this->can_access_application( (int) $doc['application_id'] )
			|| current_user_can( 'manage_options' );

		if ( ! $can_view ) {
			wp_die( esc_html__( 'You do not have permission to download this document.', 'zeko-jobs' ), 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Resolve the physical path; the DB no longer exposes a raw public URL.
		$base_dir  = trailingslashit( $zeko_db->documents_base_dir() );
		$physical  = $base_dir . $doc['filename'];
		$real_dir  = realpath( $base_dir );
		$real_phys = realpath( $physical );

		// Path-confine: the stored filename must stay inside the documents dir.
		if ( false === $real_dir || false === $real_phys || 0 !== strpos( $real_phys, trailingslashit( $real_dir ) ) ) {
			wp_die( esc_html__( 'Invalid document path.', 'zeko-jobs' ), 400 );
		}

		if ( ! is_file( $real_phys ) ) {
			wp_die( esc_html__( 'The document file is missing.', 'zeko-jobs' ), 404 );
		}

		// Validate the extension again before streaming in case the stored row.
		// was tampered with (defense in depth on write was already applied).
		$allowed_mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);
		$ext           = strtolower( pathinfo( $doc['filename'], PATHINFO_EXTENSION ) );
		if ( ! isset( $allowed_mimes[ $ext ] ) ) {
			wp_die( esc_html__( 'Unsupported document type.', 'zeko-jobs' ), 415 );
		}

		$content_type = isset( $doc['file_type'] ) && ! empty( $doc['file_type'] )
			? $doc['file_type']
			: $allowed_mimes[ $ext ];

		$safe_filename = '' !== $doc['label'] ? $doc['label'] : $doc['filename'];
		$safe_filename = sanitize_file_name( $safe_filename );

		$size = (int) $doc['file_size'];
		if ( $size < 1 ) {
			clearstatcache( true, $real_phys );
			$size = (int) filesize( $real_phys );
		}

		// Stream the file with hardened headers. Never echo a public URL.
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Security-Policy: default-src none' );
		header( 'Pragma: no-cache' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . (string) $size );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );

		// Stream without buffering concerns for large files via readfile; this.
		// is a private document endpoint, so streaming the payload directly is.
		// intentional and the file is confined to the hardened documents dir.
		if ( ! headers_sent() ) {
			readfile( $real_phys ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- intentional private streaming
		}
		exit;
	}

	/**
	 * AJAX: Schedule an interview.
	 */
	public function handle_schedule_interview(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;
		$scheduled_at   = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
		$duration       = isset( $_POST['duration'] ) ? (int) $_POST['duration'] : 60;
		$location       = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$meeting_url    = isset( $_POST['meeting_url'] ) ? esc_url_raw( wp_unslash( $_POST['meeting_url'] ) ) : '';
		$type           = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'video';
		$notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$interviewer_id = isset( $_POST['interviewer_id'] ) ? (int) $_POST['interviewer_id'] : 0;

		if ( ! $application_id || ! $scheduled_at ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$app     = $zeko_db->get_application( $application_id );

		if ( ! $app ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Application not found.', 'zeko-jobs' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$job = $zeko_db->get_job( (int) $app['job_id'] );
			if ( ! $job || get_current_user_id() !== (int) $job['employer_id'] ) {
				wp_send_json_error( array( 'message' => esc_html__( 'You are not the employer for this application.', 'zeko-jobs' ) ) );
			}
		}

		$interview_id = $zeko_db->create_interview(
			array(
				'application_id' => $application_id,
				'employer_id'    => get_current_user_id(),
				'interviewer_id' => $interviewer_id ?: null,
				'seeker_id'      => (int) $app['seeker_id'],
				'scheduled_at'   => $scheduled_at,
				'duration'       => $duration,
				'location'       => $location,
				'meeting_url'    => $meeting_url,
				'type'           => $type,
				'notes'          => $notes,
			)
		);

		if ( $interview_id ) {
			/**
			 * Fires after an interview is scheduled.
			 *
			 * @param int $interview_id    The interview ID.
			 * @param int $application_id  The application ID.
			 * @param int $employer_id     The employer user ID.
			 */
			do_action( 'zeko_job_interview_scheduled', $interview_id, $application_id, get_current_user_id() );

			// In-app notification to seeker.
			$seeker_id      = (int) $app['seeker_id'];
			$employer_name  = get_the_author_meta( 'display_name', get_current_user_id() );
			$formatted_date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $scheduled_at ) );

			self::add_notification(
				$seeker_id,
				'interview_scheduled',
				__( 'Interview Scheduled', 'zeko-jobs' ),
				sprintf(
					/* translators: %1$s: employer name, %2$s: formatted date/time */
					__( '%1$s scheduled an interview for %2$s', 'zeko-jobs' ),
					$employer_name,
					$formatted_date
				),
				'',
				'calendar',
				array(
					'interview_id'   => $interview_id,
					'application_id' => $application_id,
					'scheduled_at'   => $scheduled_at,
				)
			);

			wp_send_json_success(
				array(
					'message'      => esc_html__( 'Interview scheduled.', 'zeko-jobs' ),
					'interview_id' => $interview_id,
				)
			);
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not schedule interview.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get interviews for an application.
	 */
	public function handle_get_interviews(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_GET['application_id'] ) ? (int) $_GET['application_id'] : 0;
		if ( ! $application_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid application.', 'zeko-jobs' ) ) );
		}

		if ( ! $this->can_access_application( $application_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have access to this application.', 'zeko-jobs' ) ) );
		}

		$zeko_db    = Zeko_Jobs_DB::get_instance();
		$interviews = $zeko_db->get_interviews( $application_id );

		wp_send_json_success( array( 'interviews' => $interviews ) );
	}

	/**
	 * AJAX: Seeker proposes time slots for an interview.
	 */
	public function handle_propose_times(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$interview_id = isset( $_POST['interview_id'] ) ? (int) $_POST['interview_id'] : 0;
		$times_raw    = isset( $_POST['times'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['times'] ) ) : array();

		if ( ! $interview_id || empty( $times_raw ) || count( $times_raw ) < 2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please provide at least 2 proposed time slots.', 'zeko-jobs' ) ) );
		}

		$zeko_db    = Zeko_Jobs_DB::get_instance();
		$user_id    = get_current_user_id();
		$interviews = $zeko_db->get_interviews( $interview_id );

		if ( empty( $interviews ) || (int) $interviews[0]['seeker_id'] !== $user_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Interview not found or access denied.', 'zeko-jobs' ) ) );
		}

		$saved = $zeko_db->propose_interview_times( $interview_id, $user_id, array_slice( $times_raw, 0, 5 ) );

		if ( $saved ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Time slots proposed.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not propose times.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Employer accepts a proposed time slot.
	 */
	public function handle_accept_time(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$interview_id  = isset( $_POST['interview_id'] ) ? (int) $_POST['interview_id'] : 0;
		$accepted_time = isset( $_POST['accepted_time'] ) ? sanitize_text_field( wp_unslash( $_POST['accepted_time'] ) ) : '';

		if ( ! $interview_id || ! $accepted_time ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$saved   = $zeko_db->accept_interview_time( $interview_id, get_current_user_id(), $accepted_time );

		if ( $saved ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Interview time confirmed.', 'zeko-jobs' ) ) );
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not confirm time.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Import jobs from CSV file.
	 */
	public function handle_import_csv(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() || ( ! current_user_can( 'manage_options' ) && 'employer' !== get_user_meta( get_current_user_id(), 'zeko_job_role', true ) ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'zeko-jobs' ) ) );
		}

		if ( empty( $_FILES['zeko_csv_file'] ) || ! isset( $_FILES['zeko_csv_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['zeko_csv_file']['error'] || empty( $_FILES['zeko_csv_file']['tmp_name'] ) || empty( $_FILES['zeko_csv_file']['name'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please upload a valid CSV file.', 'zeko-jobs' ) ) );
		}

		$tmp = $_FILES['zeko_csv_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-generated temp path; file validated via Zeko_Core_Upload::validate_file() below.
		$ext = strtolower( pathinfo( $_FILES['zeko_csv_file']['name'], PATHINFO_EXTENSION ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File name only used to derive extension; file validated via Zeko_Core_Upload::validate_file() below.

		if ( class_exists( 'Zeko_Core_Upload' ) ) {
			$valid = Zeko_Core_Upload::validate_file( $_FILES['zeko_csv_file'], 'csv' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES validated via Zeko_Core_Upload::validate_file().
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
			}
		} elseif ( 'csv' !== $ext ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only CSV files are allowed.', 'zeko-jobs' ) ) );
		}

		$handle = fopen( $tmp, 'r' );
		if ( ! $handle ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not read the CSV file.', 'zeko-jobs' ) ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle );
			wp_send_json_error( array( 'message' => esc_html__( 'CSV file is empty.', 'zeko-jobs' ) ) );
		}

		$headers = array_map( 'strtolower', array_map( 'trim', $headers ) );
		$map     = array(
			'title'            => array_search( 'title', $headers ) !== false ? array_search( 'title', $headers ) : array_search( 'job_title', $headers ),
			'description'      => array_search( 'description', $headers ) !== false ? array_search( 'description', $headers ) : null,
			'location'         => array_search( 'location', $headers ) !== false ? array_search( 'location', $headers ) : null,
			'type'             => array_search( 'type', $headers ) !== false ? array_search( 'type', $headers ) : array_search( 'job_type', $headers ),
			'salary_min'       => array_search( 'salary_min', $headers ) !== false ? array_search( 'salary_min', $headers ) : null,
			'salary_max'       => array_search( 'salary_max', $headers ) !== false ? array_search( 'salary_max', $headers ) : null,
			'category'         => array_search( 'category', $headers ) !== false ? array_search( 'category', $headers ) : null,
			'experience_level' => array_search( 'experience_level', $headers ) !== false ? array_search( 'experience_level', $headers ) : null,
			'remote_option'    => array_search( 'remote_option', $headers ) !== false ? array_search( 'remote_option', $headers ) : null,
			'company_industry' => array_search( 'industry', $headers ) !== false ? array_search( 'industry', $headers ) : null,
		);

		$user_id  = get_current_user_id();
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$imported = 0;
		$errors   = array();
		$row_num  = 1;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$row_num;
			$title_idx = $map['title'];
			if ( false === $title_idx || empty( $row[ $title_idx ] ) ) {
				/* translators: %d: CSV row number */
				$errors[] = sprintf( __( 'Row %d: missing title.', 'zeko-jobs' ), $row_num );
				continue;
			}

			$data = array(
				'employer_id'      => $user_id,
				'title'            => sanitize_text_field( $row[ $title_idx ] ?? '' ),
				'description'      => isset( $map['description'], $row[ $map['description'] ] ) ? $this->sanitize_rich( $row[ $map['description'] ] ) : '',
				'location'         => isset( $map['location'], $row[ $map['location'] ] ) ? sanitize_text_field( $row[ $map['location'] ] ) : '',
				'type'             => isset( $map['type'], $row[ $map['type'] ] ) ? sanitize_text_field( $row[ $map['type'] ] ) : 'full-time',
				'salary_min'       => isset( $map['salary_min'], $row[ $map['salary_min'] ] ) ? absint( $row[ $map['salary_min'] ] ) : 0,
				'salary_max'       => isset( $map['salary_max'], $row[ $map['salary_max'] ] ) ? absint( $row[ $map['salary_max'] ] ) : 0,
				'category'         => isset( $map['category'], $row[ $map['category'] ] ) ? sanitize_text_field( $row[ $map['category'] ] ) : '',
				'experience_level' => isset( $map['experience_level'], $row[ $map['experience_level'] ] ) ? sanitize_text_field( $row[ $map['experience_level'] ] ) : '',
				'remote_option'    => isset( $map['remote_option'], $row[ $map['remote_option'] ] ) ? sanitize_text_field( $row[ $map['remote_option'] ] ) : '',
				'company_industry' => isset( $map['company_industry'], $row[ $map['company_industry'] ] ) ? sanitize_text_field( $row[ $map['company_industry'] ] ) : '',
				'slug'             => sanitize_title( $row[ $title_idx ] ),
				'status'           => 'publish',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			);

			$job_id = $zeko_db->insert_job( $data );
			if ( $job_id ) {
				++$imported;
			} else {
				/* translators: %d: CSV row number */
				$errors[] = sprintf( __( 'Row %d: failed to insert.', 'zeko-jobs' ), $row_num );
			}
		}
		fclose( $handle );

		wp_send_json_success(
			array(
				/* translators: 1: number of imported jobs. 2: number of errors */
				'message'  => sprintf( __( 'Imported %1$d job(s). %2$d error(s).', 'zeko-jobs' ), $imported, count( $errors ) ),
				'imported' => $imported,
				'errors'   => $errors,
			)
		);
	}

	/**
	 * AJAX: Compare multiple applicants side by side.
	 */
	public function handle_compare_apps(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$seeker_ids_raw = isset( $_POST['seeker_ids'] ) ? array_map( 'absint', (array) $_POST['seeker_ids'] ) : array();
		$seeker_ids     = array_unique( array_filter( $seeker_ids_raw ) );

		if ( count( $seeker_ids ) < 2 || count( $seeker_ids ) > 5 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Select 2–5 applicants to compare.', 'zeko-jobs' ) ) );
		}

		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$results  = array();
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		foreach ( $seeker_ids as $sid ) {
			$ud = get_userdata( $sid );
			if ( ! $ud ) {
				continue;
			}

			if ( ! $is_admin ) {
				global $wpdb;
				$applied_to_mine = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}zeko_job_applications a
						 INNER JOIN {$wpdb->prefix}zeko_jobs j ON a.job_id = j.id
						 WHERE a.seeker_id = %d AND j.employer_id = %d",
						$sid,
						$user_id
					)
				);
				if ( ! $applied_to_mine ) {
					continue;
				}
			}
			$skills = get_user_meta( $sid, 'zeko_skills', true );
			$exp    = get_user_meta( $sid, 'zeko_work_experience', true );
			$edu    = get_user_meta( $sid, 'zeko_education', true );
			$loc    = get_user_meta( $sid, 'zeko_location', true );
			$bio    = get_user_meta( $sid, 'zeko_bio', true );
			$avatar = get_avatar_url( $sid, array( 'size' => 80 ) );
			$open   = (int) get_user_meta( $sid, 'zeko_open_to_work', true );

			if ( ! is_array( $skills ) ) {
				$skills = array_filter( array_map( 'trim', explode( ',', (string) $skills ) ) );
			}
			if ( ! is_array( $exp ) ) {
				$exp = array();
			}
			if ( ! is_array( $edu ) ) {
				$edu = array();
			}

			$total_years = 0;
			foreach ( $exp as $e ) {
				if ( ! empty( $e['years'] ) ) {
					$total_years += (int) $e['years'];
				} elseif ( ! empty( $e['start_date'] ) ) {
					$start = strtotime( $e['start_date'] );
					$end   = ! empty( $e['end_date'] ) ? strtotime( $e['end_date'] ) : time();
					if ( $start && $end && $end > $start ) {
						$total_years += (int) round( ( $end - $start ) / ( 365.25 * 86400 ) );
					}
				}
			}

			$applications = $wpdb ?? null;
			global $wpdb;
			$table_apps  = $wpdb->prefix . 'zeko_job_applications';
			$app_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_apps} WHERE seeker_id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$hired_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_apps} WHERE seeker_id = %d AND status = 'hired'", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$profile     = $zeko_db->calculate_seeker_profile_completeness( $sid );

			$results[] = array(
				'seeker_id'    => $sid,
				'name'         => $ud->display_name,
				'avatar'       => $avatar,
				'location'     => $loc ?: '',
				'bio'          => wp_trim_words( (string) $bio, 30 ),
				'skills'       => array_values( $skills ),
				'experience'   => $exp,
				'total_years'  => $total_years,
				'education'    => $edu,
				'open_to_work' => $open,
				'app_count'    => $app_count,
				'hired_count'  => $hired_count,
				'profile_pct'  => $profile['percent'] ?? 0,
			);
		}

		wp_send_json_success( array( 'applicants' => $results ) );
	}

	/**
	 * AJAX: Save a structured resume.
	 */
	public function handle_save_resume(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		$data    = isset( $_POST['resume'] ) ? json_decode( wp_unslash( $_POST['resume'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON blob parsed into array; validated/sanitized per-field inside save_structured_resume().

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid resume data.', 'zeko-jobs' ) ) );
		}

		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$resume_id = $zeko_db->save_structured_resume( $user_id, $data );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Resume saved.', 'zeko-jobs' ),
				'resume_id' => $resume_id,
			)
		);
	}

	/**
	 * AJAX: Delete a structured resume.
	 */
	public function handle_delete_resume(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$resume_id = isset( $_POST['resume_id'] ) ? sanitize_text_field( wp_unslash( $_POST['resume_id'] ) ) : '';
		if ( ! $resume_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid resume.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$zeko_db->delete_structured_resume( get_current_user_id(), $resume_id );

		wp_send_json_success( array( 'message' => esc_html__( 'Resume deleted.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Set a structured resume as default.
	 */
	public function handle_set_default_resume(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$resume_id = isset( $_POST['resume_id'] ) ? sanitize_text_field( wp_unslash( $_POST['resume_id'] ) ) : '';
		if ( ! $resume_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid resume.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$zeko_db->set_default_structured_resume( get_current_user_id(), $resume_id );

		wp_send_json_success( array( 'message' => esc_html__( 'Default resume updated.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Download ICS calendar invite.
	 */
	public function handle_download_ics(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$interview_id = isset( $_GET['interview_id'] ) ? (int) $_GET['interview_id'] : 0;
		if ( ! $interview_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid interview.', 'zeko-jobs' ) ) );
		}

		$zeko_db   = Zeko_Jobs_DB::get_instance();
		$interview = $zeko_db->get_interview_by_id( $interview_id );

		if ( ! $interview ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Interview not found.', 'zeko-jobs' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$application_id = isset( $interview['application_id'] ) ? (int) $interview['application_id'] : 0;
			if ( ! $application_id || ! $this->can_access_application( $application_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'You do not have access to this interview.', 'zeko-jobs' ) ) );
			}
		}

		$ics = $zeko_db->generate_ics( $interview );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="interview-' . $interview_id . '.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS calendar file download served with text/calendar headers; content is constructed with addcslashes() escaping, not HTML.
		exit;
	}

	/**
	 * AJAX: Mark notification as read.
	 */
	public function handle_mark_notification_read(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$notification_id = isset( $_POST['notification_id'] ) ? absint( $_POST['notification_id'] ) : 0;
		if ( ! $notification_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid notification.', 'zeko-jobs' ) ) );
		}

		$db      = Zeko_Jobs_DB::get_instance();
		$updated = $db->mark_notification_read( $notification_id, get_current_user_id() );

		if ( $updated ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Notification marked as read.', 'zeko-jobs' ) ) );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Notification not found.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * AJAX: Get notifications for current user.
	 */
	public function handle_get_notifications(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$db            = Zeko_Jobs_DB::get_instance();
		$user_id       = get_current_user_id();
		$notifications = $db->get_notifications( $user_id, 50 );
		$unread_count  = $db->count_unread_notifications( $user_id );

		wp_send_json_success(
			array(
				'notifications' => $notifications,
				'unread_count'  => $unread_count,
			)
		);
	}

	/**
	 * AJAX: Mark all notifications as read.
	 */
	public function handle_notif_read_all(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$db = Zeko_Jobs_DB::get_instance();
		$db->mark_all_notifications_read( get_current_user_id() );

		wp_send_json_success( array( 'message' => esc_html__( 'All notifications marked as read.', 'zeko-jobs' ) ) );
	}

	/**
	 * Add an in-app notification for a user.
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param string $type Notification type (application_received, status_changed, etc.).
	 * @param string $title Short title for the notification.
	 * @param string $message Notification body.
	 * @param string $link URL to navigate to when clicked.
	 * @param string $icon Dashicon class name.
	 * @param array  $meta Extra metadata stored as JSON.
	 */
	public static function add_notification( int $user_id, string $type, string $title, string $message, string $link = '', string $icon = 'dashicons-info', array $meta = array() ): void {
		$db = Zeko_Jobs_DB::get_instance();
		$db->insert_notification( $user_id, $type, $title, $message, $link, $icon, $meta );
	}

	/**
	 * Output <link> tag for Google Jobs XML feed discovery.
	 */
	public function output_feed_discovery(): void {
		if ( ! is_page() && ! is_home() ) {
			return;
		}
		$has_shortcode = false;
		$page_id       = get_queried_object_id();
		if ( $page_id ) {
			$has_shortcode = has_shortcode( get_post_field( 'post_content', $page_id ), 'zeko_jobs_archive' );
		}
		if ( ! $has_shortcode && ! is_home() ) {
			return;
		}
		echo '<link rel="alternate" type="application/rss+xml" title="' . esc_attr( get_bloginfo( 'name' ) ) . ' â€” Jobs Feed" href="' . esc_url( home_url( '/jobs/feed/' ) ) . '" />' . "\n";
	}

	/**
	 * AJAX: Submit a review for a job.
	 */
	public function handle_submit_review(): void {
		check_ajax_referer( 'zeko_job_apply', 'zeko_job_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in to leave a review.', 'zeko-jobs' ) ) );
		}

		if ( ! Zeko_Jobs_Rate_Limiter::check( 'review' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Too many reviews today. Please try again later.', 'zeko-jobs' ) ), 429 );
		}

		$job_id      = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$rating      = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
		$review_text = isset( $_POST['review_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_text'] ) ) : '';

		if ( ! $job_id || $rating < 1 || $rating > 5 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid rating.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$result  = $zeko_db->insert_review( $job_id, get_current_user_id(), $rating, $review_text );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not save review.', 'zeko-jobs' ) ) );
		}

		$avg = $zeko_db->get_average_rating( $job_id );
		wp_send_json_success(
			array(
				'message'      => esc_html__( 'Review submitted.', 'zeko-jobs' ),
				'avg_rating'   => round( (float) $avg['avg_rating'], 1 ),
				'review_count' => (int) $avg['review_count'],
			)
		);
	}

	/**
	 * Handle job renewal (extend expiration date).
	 */
	public function handle_renew_job(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$job_id      = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$employer_id = get_current_user_id();
		$days        = isset( $_POST['renew_days'] ) ? absint( $_POST['renew_days'] ) : 30;

		if ( ! $job_id || $days < 1 || $days > 365 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$result  = $zeko_db->renew_job( $job_id, $employer_id, $days );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not renew job.', 'zeko-jobs' ) ) );
		}

		$job = $zeko_db->get_job( $job_id );

		wp_send_json_success(
			array(
				'message'    => esc_html__( 'Job renewed successfully.', 'zeko-jobs' ),
				'expires_at' => $job ? $job['expires_at'] : '',
			)
		);
	}

	/**
	 * Save a cover letter template.
	 */
	public function handle_save_cover_template(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id     = get_current_user_id();
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$content     = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		if ( empty( $name ) || empty( $content ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Name and content are required.', 'zeko-jobs' ) ) );
		}

		$templates = get_user_meta( $user_id, 'zeko_cover_templates', true );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		if ( $template_id && isset( $templates[ $template_id ] ) ) {
			$templates[ $template_id ] = array(
				'name'    => $name,
				'content' => $content,
			);
		} else {
			$new_id = max( array_keys( $templates ) ) + 1;
			if ( empty( $templates ) ) {
				$new_id = 1;
			}
			$templates[ $new_id ] = array(
				'name'    => $name,
				'content' => $content,
			);
		}

		update_user_meta( $user_id, 'zeko_cover_templates', $templates );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Template saved.', 'zeko-jobs' ),
				'templates' => $templates,
			)
		);
	}

	/**
	 * Delete a cover letter template.
	 */
	public function handle_delete_cover_template(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id     = get_current_user_id();
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		if ( ! $template_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid template ID.', 'zeko-jobs' ) ) );
		}

		$templates = get_user_meta( $user_id, 'zeko_cover_templates', true );
		if ( ! is_array( $templates ) || ! isset( $templates[ $template_id ] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Template not found.', 'zeko-jobs' ) ) );
		}

		unset( $templates[ $template_id ] );
		update_user_meta( $user_id, 'zeko_cover_templates', $templates );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Template deleted.', 'zeko-jobs' ),
				'templates' => $templates,
			)
		);
	}

	/**
	 * Get all cover letter templates for the current user.
	 */
	public function handle_get_cover_templates(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$templates = get_user_meta( get_current_user_id(), 'zeko_cover_templates', true );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		wp_send_json_success( array( 'templates' => $templates ) );
	}

	/**
	 * Save company profile.
	 */
	public function handle_save_company(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$name         = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$description  = isset( $_POST['company_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['company_description'] ) ) : '';
		$industry     = isset( $_POST['company_industry'] ) ? sanitize_text_field( wp_unslash( $_POST['company_industry'] ) ) : '';
		$size         = isset( $_POST['company_size'] ) ? sanitize_text_field( wp_unslash( $_POST['company_size'] ) ) : '';
		$website      = isset( $_POST['company_website'] ) ? esc_url_raw( wp_unslash( $_POST['company_website'] ) ) : '';
		$location     = isset( $_POST['company_location'] ) ? sanitize_text_field( wp_unslash( $_POST['company_location'] ) ) : '';
		$founded_year = isset( $_POST['company_founded'] ) ? absint( $_POST['company_founded'] ) : 0;
		$linkedin     = isset( $_POST['company_linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['company_linkedin'] ) ) : '';
		$twitter      = isset( $_POST['company_twitter'] ) ? esc_url_raw( wp_unslash( $_POST['company_twitter'] ) ) : '';
		$cover        = isset( $_POST['company_cover'] ) ? esc_url_raw( wp_unslash( $_POST['company_cover'] ) ) : '';
		$accent       = isset( $_POST['company_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['company_accent'] ) ) : '';
		$video        = isset( $_POST['company_video'] ) ? esc_url_raw( wp_unslash( $_POST['company_video'] ) ) : '';
		$company_id   = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$company_new  = isset( $_POST['company_new'] ) ? 1 : 0;

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Company name is required.', 'zeko-jobs' ) ) );
		}

		$zeko_db    = Zeko_Jobs_DB::get_instance();
		$company_id = $zeko_db->save_company(
			get_current_user_id(),
			array(
				'name'         => $name,
				'description'  => $description,
				'industry'     => $industry,
				'size'         => $size,
				'website'      => $website,
				'location'     => $location,
				'founded_year' => $founded_year > 0 ? $founded_year : null,
				'linkedin'     => $linkedin,
				'twitter'      => $twitter,
				'cover_url'    => $cover,
				'accent_color' => $accent,
				'video_url'    => $video,
			),
			$company_id,
			(bool) $company_new
		);

		if ( ! $company_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Company not found or you do not have permission to edit it.', 'zeko-jobs' ) ) );
		}

		wp_send_json_success(
			array(
				'message'    => esc_html__( 'Company profile saved.', 'zeko-jobs' ),
				'company_id' => $company_id,
			)
		);
	}

	/**
	 * AJAX: Save a company blog post (employer side).
	 */
	public function handle_save_company_post(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id    = get_current_user_id();
		$title      = isset( $_POST['blog_title'] ) ? sanitize_text_field( wp_unslash( $_POST['blog_title'] ) ) : '';
		$content    = isset( $_POST['blog_content'] ) ? $this->sanitize_rich( wp_unslash( $_POST['blog_content'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rich content sanitized by custom sanitize_rich() (wp_kses-based allow-list).
		$post_id    = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : 0;
		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;

		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Post title is required.', 'zeko-jobs' ) ) );
		}
		if ( empty( $content ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Post content is required.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();

		if ( $post_id ) {
			$updated = $zeko_db->update_company_post(
				$post_id,
				$user_id,
				array(
					'title'   => $title,
					'content' => $content,
				)
			);
			if ( ! $updated ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Post not found or you do not have permission to edit it.', 'zeko-jobs' ) ) );
			}
			$saved_id = $post_id;
		} else {
			$company = $zeko_db->get_company( $company_id );
			if ( ! $company || (int) $company['employer_id'] !== $user_id ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid company.', 'zeko-jobs' ) ) );
			}
			$saved_id = $zeko_db->insert_company_post(
				array(
					'company_id'  => $company_id,
					'employer_id' => $user_id,
					'title'       => $title,
					'content'     => $content,
				)
			);
			if ( ! $saved_id ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Failed to save post.', 'zeko-jobs' ) ) );
			}
		}

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Post saved.', 'zeko-jobs' ),
				'blog_id' => $saved_id,
			)
		);
	}

	/**
	 * AJAX: Delete a company blog post (employer side).
	 */
	public function handle_delete_company_post(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$post_id = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid post.', 'zeko-jobs' ) ) );
		}

		$deleted = Zeko_Jobs_DB::get_instance()->delete_company_post( $post_id, get_current_user_id() );
		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Post not found or you do not have permission to delete it.', 'zeko-jobs' ) ) );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Post deleted.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Check for duplicate jobs before creating.
	 */
	public function handle_check_duplicates(): void {
		check_ajax_referer( 'zeko_job_create', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( empty( $title ) ) {
			wp_send_json_success( array( 'duplicates' => array() ) );
			return;
		}

		$zeko_db    = Zeko_Jobs_DB::get_instance();
		$duplicates = $zeko_db->find_duplicate_jobs( get_current_user_id(), $title, $location, $type );

		wp_send_json_success( array( 'duplicates' => $duplicates ) );
	}

	/**
	 * AJAX: One-click apply for returning applicants.
	 */
	public function handle_one_click_apply(): void {
		check_ajax_referer( 'zeko_job_apply', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$seeker_id = get_current_user_id();
		$job_id    = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();

		$job = $zeko_db->get_job_by_id( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Job not found.', 'zeko-jobs' ) ) );
		}

		$existing = $zeko_db->get_application( $job_id, $seeker_id );
		if ( $existing ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You have already applied to this job.', 'zeko-jobs' ) ) );
		}

		$saved_resume = get_user_meta( $seeker_id, 'zeko_saved_resume_url', true );
		if ( empty( $saved_resume ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please save a resume first in your profile before using one-click apply.', 'zeko-jobs' ) ) );
		}

		$last_resume = $saved_resume;
		$documents   = get_user_meta( $seeker_id, 'zeko_documents', true );
		if ( is_array( $documents ) && ! empty( $documents ) ) {
			$last_resume = end( $documents )['url'] ?? $saved_resume;
		}

		$application_id = $zeko_db->create_application( $job_id, $seeker_id, $last_resume, '' );

		if ( ! $application_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not submit application.', 'zeko-jobs' ) ) );
		}

		/**
		 * Fires after a one-click application is submitted.
		 */
		do_action( 'zeko_job_application_submitted', $application_id, $job_id, $seeker_id );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Application submitted!', 'zeko-jobs' ),
			)
		);
	}

	/**
	 * AJAX: Save email template.
	 */
	public function handle_save_email_template(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$key     = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
		$name    = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$subject = isset( $_POST['template_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['template_subject'] ) ) : '';
		$body    = isset( $_POST['template_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template_body'] ) ) : '';

		if ( empty( $key ) || empty( $name ) || empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'All fields are required.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$zeko_db->save_email_template(
			get_current_user_id(),
			$key,
			array(
				'name'    => $name,
				'subject' => $subject,
				'body'    => $body,
			)
		);

		wp_send_json_success( array( 'message' => esc_html__( 'Template saved.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Delete email template.
	 */
	public function handle_delete_email_template(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$key = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid template.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$zeko_db->delete_email_template( get_current_user_id(), $key );

		wp_send_json_success( array( 'message' => esc_html__( 'Template deleted.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Send bulk email to selected applicants.
	 */
	public function handle_bulk_email(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$application_ids = isset( $_POST['application_ids'] ) ? array_map( 'absint', (array) $_POST['application_ids'] ) : array();
		$subject         = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$body            = isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '';

		if ( empty( $application_ids ) || empty( $subject ) || empty( $body ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please select applicants and fill in the email content.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$results = $zeko_db->send_bulk_application_emails( get_current_user_id(), $application_ids, $subject, $body );

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: 1: number sent, 2: number failed */
					esc_html__( '%1$d emails sent, %2$d failed.', 'zeko-jobs' ),
					$results['sent'],
					$results['failed']
				),
				'sent'    => $results['sent'],
				'failed'  => $results['failed'],
			)
		);
	}

	/**
	 * AJAX: Save candidate rating.
	 */
	public function handle_save_rating(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$rating         = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		$note           = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( ! $application_id || $rating < 0 || $rating > 5 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid rating.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$zeko_db->save_app_rating( $application_id, get_current_user_id(), $rating, $note );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Rating saved.', 'zeko-jobs' ),
				'rating'  => $rating,
			)
		);
	}

	/**
	 * AJAX: Save auto-response settings.
	 */
	public function handle_save_auto_response(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$enabled = isset( $_POST['auto_response_enabled'] ) ? 1 : 0;
		$subject = isset( $_POST['auto_response_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['auto_response_subject'] ) ) : '';
		$body    = isset( $_POST['auto_response_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['auto_response_body'] ) ) : '';

		$auto_response = Zeko_Jobs_Auto_Response::get_instance();
		$auto_response->save_settings(
			get_current_user_id(),
			array(
				'enabled' => $enabled,
				'subject' => $subject,
				'body'    => $body,
			)
		);

		wp_send_json_success( array( 'message' => esc_html__( 'Auto-response settings saved.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Send outreach message to a seeker.
	 */
	public function handle_send_outreach(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$seeker_id = isset( $_POST['seeker_id'] ) ? absint( $_POST['seeker_id'] ) : 0;
		$job_id    = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$message   = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $seeker_id || ! $message ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please select a candidate and write a message.', 'zeko-jobs' ) ) );
		}

		$seeker = get_userdata( $seeker_id );
		if ( ! $seeker ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Candidate not found.', 'zeko-jobs' ) ) );
		}

		$employer_id   = get_current_user_id();
		$employer      = get_userdata( $employer_id );
		$employer_name = $employer ? $employer->display_name : '';

		if ( function_exists( 'zeko_send_message' ) ) {
			$formatted = sprintf(
				__( "Hi %1\$s,\n\nAs a representative of %2\$s, I wanted to reach out regarding: %3\$s", 'zeko-jobs' ),
				$seeker->display_name,
				$employer_name,
				$message
			);
			zeko_send_message( $employer_id, $seeker_id, $formatted, 'jobs' );
		} else {
			$job_title = '';
			if ( $job_id ) {
				$zeko_db   = Zeko_Jobs_DB::get_instance();
				$job       = $zeko_db->get_job( $job_id );
				$job_title = $job ? $job['title'] : '';
			}

			$search  = array( '{seeker_name}', '{employer_name}', '{job_title}' );
			$replace = array( $seeker->display_name, $employer_name, $job_title );
			$final   = str_replace( $search, $replace, $message );
			$html    = '<div style="white-space:pre-wrap;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;line-height:1.6;color:#475569;">' . nl2br( esc_html( $final ) ) . '</div>';

			Zeko_Jobs_Email_Renderer::get_instance()->send_styled(
				$seeker->user_email,
				/* translators: %s: employer display name */
				sprintf( __( 'Message from %s', 'zeko-jobs' ), $employer_name ),
				$html
			);
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Message sent to candidate.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Toggle save/bookmark a job.
	 */
	public function handle_toggle_save(): void {
		check_ajax_referer( 'zeko_job_apply', 'zeko_job_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id  = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$user_id = get_current_user_id();

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job.', 'zeko-jobs' ) ) );
		}

		$saved = get_user_meta( $user_id, 'zeko_saved_jobs', true );
		$saved = is_array( $saved ) ? $saved : array();
		$index = array_search( $job_id, $saved, true );

		if ( false !== $index ) {
			unset( $saved[ $index ] );
			$saved   = array_values( $saved );
			$saved_b = false;
		} else {
			$saved[] = $job_id;
			$saved_b = true;
		}

		update_user_meta( $user_id, 'zeko_saved_jobs', $saved );

		wp_send_json_success(
			array(
				'saved'   => $saved_b,
				'message' => $saved_b
					? esc_html__( 'Job saved.', 'zeko-jobs' )
					: esc_html__( 'Job removed from saved.', 'zeko-jobs' ),
			)
		);
	}

	/**
	 * AJAX: Save seeker profile.
	 */
	public function handle_save_seeker_profile(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'zeko-jobs' ) ) );
		}

		$user_id  = get_current_user_id();
		$location = isset( $_POST['seeker_location'] ) ? sanitize_text_field( wp_unslash( $_POST['seeker_location'] ) ) : '';
		$phone    = isset( $_POST['seeker_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['seeker_phone'] ) ) : '';
		$skills   = isset( $_POST['seeker_skills'] ) ? sanitize_text_field( wp_unslash( $_POST['seeker_skills'] ) ) : '';

		update_user_meta( $user_id, 'zeko_location', $location );
		update_user_meta( $user_id, 'zeko_phone', $phone );

		$skills_arr = array_filter( array_map( 'trim', explode( ',', $skills ) ) );
		update_user_meta( $user_id, 'zeko_skills', $skills_arr );

		$experience = array();
		if ( ! empty( $_POST['exp_company'] ) && is_array( $_POST['exp_company'] ) ) {
			$companies = array_map( 'sanitize_text_field', wp_unslash( $_POST['exp_company'] ) );
			$roles     = isset( $_POST['exp_role'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['exp_role'] ) ) : array();
			$dates     = isset( $_POST['exp_dates'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['exp_dates'] ) ) : array();
			$descs     = isset( $_POST['exp_desc'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['exp_desc'] ) ) : array();
			foreach ( $companies as $i => $company ) {
				if ( ! empty( $company ) ) {
					$experience[] = array(
						'company'     => $company,
						'role'        => $roles[ $i ] ?? '',
						'dates'       => $dates[ $i ] ?? '',
						'description' => $descs[ $i ] ?? '',
					);
				}
			}
		}
		update_user_meta( $user_id, 'zeko_work_experience', $experience );

		$education = array();
		if ( ! empty( $_POST['edu_institution'] ) && is_array( $_POST['edu_institution'] ) ) {
			$institutions = array_map( 'sanitize_text_field', wp_unslash( $_POST['edu_institution'] ) );
			$degrees      = isset( $_POST['edu_degree'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['edu_degree'] ) ) : array();
			$edu_dates    = isset( $_POST['edu_dates'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['edu_dates'] ) ) : array();
			foreach ( $institutions as $i => $inst ) {
				if ( ! empty( $inst ) ) {
					$education[] = array(
						'institution' => $inst,
						'degree'      => $degrees[ $i ] ?? '',
						'dates'       => $edu_dates[ $i ] ?? '',
					);
				}
			}
		}
		update_user_meta( $user_id, 'zeko_education', $education );

		// Privacy & Open to Work.
		$profile_visible = isset( $_POST['zeko_profile_visible'] ) ? 1 : 0;
		$open_to_work    = isset( $_POST['zeko_open_to_work'] ) ? 1 : 0;
		update_user_meta( $user_id, 'zeko_profile_visible', $profile_visible );
		update_user_meta( $user_id, 'zeko_open_to_work', $open_to_work );

		wp_send_json_success( array( 'message' => esc_html__( 'Profile saved.', 'zeko-jobs' ) ) );
	}

	/**
	 * AJAX: Get per-job analytics data + time series.
	 */
	public function handle_get_job_analytics(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job ID.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job( $job_id );

		if ( ! $job || get_current_user_id() !== (int) $job['employer_id'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Not authorized.', 'zeko-jobs' ) ) );
		}

		$days        = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$analytics   = $zeko_db->get_job_analytics( $job_id );
		$time_series = $zeko_db->get_job_views_time_series( $job_id, $days );
		$comparison  = $zeko_db->get_analytics_comparison( get_current_user_id(), $days > 14 ? 'month' : 'week' );

		wp_send_json_success(
			array(
				'analytics'   => $analytics,
				'time_series' => $time_series,
				'comparison'  => $comparison,
			)
		);
	}

	/**
	 * AJAX: Export analytics as CSV.
	 */
	public function handle_export_analytics(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid job ID.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job( $job_id );

		if ( ! $job || get_current_user_id() !== (int) $job['employer_id'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Not authorized.', 'zeko-jobs' ) ) );
		}

		$days        = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$analytics   = $zeko_db->get_job_analytics( $job_id );
		$time_series = $zeko_db->get_job_views_time_series( $job_id, $days );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=job-analytics-' . $job_id . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( __( 'Job Title', 'zeko-jobs' ), $analytics['job']['title'] ?? '' ) );
		fputcsv( $output, array( __( 'Total Views', 'zeko-jobs' ), $analytics['total_views'] ?? 0 ) );
		fputcsv( $output, array( __( 'Unique Visitors', 'zeko-jobs' ), $analytics['unique_visitors'] ?? 0 ) );
		fputcsv( $output, array( __( 'Total Applications', 'zeko-jobs' ), $analytics['total_applications'] ?? 0 ) );
		fputcsv( $output, array( __( 'Conversion Rate', 'zeko-jobs' ), ( $analytics['conversion_rate'] ?? 0 ) . '%' ) );
		fputcsv( $output, array() );
		fputcsv( $output, array( __( 'Date', 'zeko-jobs' ), __( 'Views', 'zeko-jobs' ), __( 'Unique Visitors', 'zeko-jobs' ) ) );

		foreach ( $time_series as $row ) {
			fputcsv( $output, array( $row['date'], $row['views'], $row['unique_visitors'] ) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * AJAX: Set a document as the user's default resume.
	 */
	public function handle_set_default_document(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$doc_id = isset( $_POST['document_id'] ) ? (int) $_POST['document_id'] : 0;
		if ( ! $doc_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid document.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$user_id = get_current_user_id();

		// Verify the document belongs to this user.
		$documents = $zeko_db->get_documents( $user_id );
		$found     = false;
		foreach ( $documents as $doc ) {
			if ( (int) $doc['id'] === $doc_id ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Not authorized.', 'zeko-jobs' ) ) );
		}

		$updated = $zeko_db->set_user_default_resume( $user_id, $doc_id );

		if ( $updated ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Default resume updated.', 'zeko-jobs' ),
					'doc_id'  => $doc_id,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not update default resume.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * AJAX: Update a document's label.
	 */
	public function handle_update_document_label(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$doc_id = isset( $_POST['document_id'] ) ? (int) $_POST['document_id'] : 0;
		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( ! $doc_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid document.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$user_id = get_current_user_id();

		// Verify ownership.
		$documents = $zeko_db->get_documents( $user_id );
		$found     = false;
		foreach ( $documents as $doc ) {
			if ( (int) $doc['id'] === $doc_id ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Not authorized.', 'zeko-jobs' ) ) );
		}

		$updated = $zeko_db->update_document_label( $doc_id, $label );
		if ( $updated ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Label updated.', 'zeko-jobs' ),
					'label'   => $label,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not update label.', 'zeko-jobs' ) ) );
		}
	}

	/* ─── Job Alerts ──────────────────────────────────────────── */

	/**
	 * AJAX: Create or update a job alert.
	 */
	public function handle_save_job_alert(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$keyword  = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$freq     = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : 'daily';
		$alert_id = isset( $_POST['alert_id'] ) ? (int) $_POST['alert_id'] : 0;

		if ( empty( $name ) && empty( $keyword ) && empty( $location ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please provide a name, keyword, or location for this alert.', 'zeko-jobs' ) ) );
		}

		if ( ! in_array( $freq, array( 'instant', 'daily', 'weekly' ), true ) ) {
			$freq = 'daily';
		}

		$params = array(
			'name'     => $name ?: sprintf( '%s jobs in %s', $keyword ?: '*', $location ?: '*' ),
			'keyword'  => $keyword,
			'location' => $location,
			'type'     => $type,
			'category' => $category,
		);

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$user_id = get_current_user_id();

		if ( $alert_id > 0 ) {
			$updated = $zeko_db->update_job_alert(
				$alert_id,
				$user_id,
				array(
					'name'          => $params['name'],
					'search_params' => $params,
					'frequency'     => $freq,
				)
			);
			if ( $updated ) {
				wp_send_json_success(
					array(
						'message'  => esc_html__( 'Alert updated.', 'zeko-jobs' ),
						'alert_id' => $alert_id,
					)
				);
			} else {
				wp_send_json_error( array( 'message' => esc_html__( 'Could not update alert.', 'zeko-jobs' ) ) );
			}
		} else {
			$new_id = $zeko_db->create_job_alert(
				$user_id,
				array(
					'name'          => $params['name'],
					'search_params' => $params,
					'frequency'     => $freq,
				)
			);
			if ( $new_id ) {
				wp_send_json_success(
					array(
						'message'  => esc_html__( 'Alert created.', 'zeko-jobs' ),
						'alert_id' => $new_id,
					)
				);
			} else {
				wp_send_json_error( array( 'message' => esc_html__( 'Could not create alert.', 'zeko-jobs' ) ) );
			}
		}
	}

	/**
	 * AJAX: Delete a job alert.
	 */
	public function handle_delete_job_alert(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$alert_id = isset( $_POST['alert_id'] ) ? (int) $_POST['alert_id'] : 0;
		if ( ! $alert_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid alert.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$deleted = $zeko_db->delete_job_alert( $alert_id, get_current_user_id() );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Alert deleted.', 'zeko-jobs' ) ) );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not delete alert.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * AJAX: Toggle a job alert active/inactive.
	 */
	public function handle_toggle_job_alert(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$alert_id = isset( $_POST['alert_id'] ) ? (int) $_POST['alert_id'] : 0;
		if ( ! $alert_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid alert.', 'zeko-jobs' ) ) );
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$alert   = $zeko_db->get_job_alert( $alert_id, get_current_user_id() );
		if ( ! $alert ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Alert not found.', 'zeko-jobs' ) ) );
		}

		$new_state = empty( $alert['is_active'] );
		$updated   = $zeko_db->update_job_alert( $alert_id, get_current_user_id(), array( 'is_active' => $new_state ) );

		if ( $updated ) {
			wp_send_json_success(
				array(
					'message'   => $new_state ? esc_html__( 'Alert activated.', 'zeko-jobs' ) : esc_html__( 'Alert paused.', 'zeko-jobs' ),
					'is_active' => $new_state,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not toggle alert.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * AJAX: Compare 2-3 candidates side-by-side.
	 */
	public function handle_compare_candidates(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$app_ids = isset( $_GET['app_ids'] ) ? array_map( 'absint', (array) $_GET['app_ids'] ) : array();
		$app_ids = array_filter( array_slice( $app_ids, 0, 3 ) );

		if ( count( $app_ids ) < 2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Select at least 2 candidates to compare.', 'zeko-jobs' ) ) );
		}

		$zeko_db    = Zeko_Jobs_DB::get_instance();
		$candidates = array();

		foreach ( $app_ids as $app_id ) {
			$app = $zeko_db->get_application( $app_id );
			if ( ! $app ) {
				continue;
			}
			if ( ! $this->can_access_application( $app_id ) ) {
				continue;
			}

			$rating = $zeko_db->get_app_rating( $app_id, get_current_user_id() );
			$notes  = $zeko_db->get_application_notes( $app_id );

			// Get seeker profile data.
			$seeker_id    = (int) $app['seeker_id'];
			$location     = get_user_meta( $seeker_id, 'zeko_location', true );
			$phone        = get_user_meta( $seeker_id, 'zeko_phone', true );
			$skills       = get_user_meta( $seeker_id, 'zeko_skills', true );
			$experience   = get_user_meta( $seeker_id, 'zeko_work_experience', true );
			$education    = get_user_meta( $seeker_id, 'zeko_education', true );
			$open_to_work = (int) get_user_meta( $seeker_id, 'zeko_open_to_work', true );
			$user_data    = get_userdata( $seeker_id );

			$candidates[] = array(
				'id'           => $app_id,
				'seeker_name'  => $user_data ? $user_data->display_name : ( $app['seeker_login'] ?? '' ),
				'seeker_email' => $user_data ? $user_data->user_email : '',
				'cover_letter' => $app['cover_letter'] ?? '',
				'resume_url'   => $app['resume_url'] ?? '',
				'status'       => $app['status'] ?? '',
				'applied_at'   => $app['applied_at'] ?? '',
				'rating'       => $rating ? (int) $rating['rating'] : 0,
				'notes'        => $notes,
				'location'     => $location,
				'phone'        => $phone,
				'skills'       => is_array( $skills ) ? $skills : array(),
				'experience'   => is_array( $experience ) ? $experience : array(),
				'education'    => is_array( $education ) ? $education : array(),
				'open_to_work' => $open_to_work,
			);
		}

		wp_send_json_success( array( 'candidates' => $candidates ) );
	}

	/**
	 * AJAX: Get interview feedback for a specific interview.
	 */
	public function handle_get_interview_feedback(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$interview_id = isset( $_GET['interview_id'] ) ? (int) $_GET['interview_id'] : 0;
		if ( ! $interview_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid interview.', 'zeko-jobs' ) ) );
		}

		$feedback = get_post_meta( $interview_id, 'zeko_interview_feedback', true );
		wp_send_json_success( array( 'feedback' => $feedback ?: array() ) );
	}

	/**
	 * AJAX: Save interview feedback.
	 */
	public function handle_save_interview_feedback(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$interview_id = isset( $_POST['interview_id'] ) ? (int) $_POST['interview_id'] : 0;
		if ( ! $interview_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid interview.', 'zeko-jobs' ) ) );
		}

		$rating     = isset( $_POST['feedback_rating'] ) ? absint( $_POST['feedback_rating'] ) : 0;
		$recommend  = isset( $_POST['feedback_recommend'] ) ? sanitize_text_field( wp_unslash( $_POST['feedback_recommend'] ) ) : '';
		$notes      = isset( $_POST['feedback_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback_notes'] ) ) : '';
		$strengths  = isset( $_POST['feedback_strengths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback_strengths'] ) ) : '';
		$weaknesses = isset( $_POST['feedback_weaknesses'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback_weaknesses'] ) ) : '';

		$feedback = array(
			'rating'       => min( max( $rating, 0 ), 5 ),
			'recommend'    => $recommend,
			'notes'        => $notes,
			'strengths'    => $strengths,
			'weaknesses'   => $weaknesses,
			'reviewer'     => get_current_user_id(),
			'submitted_at' => current_time( 'mysql' ),
		);

		update_post_meta( $interview_id, 'zeko_interview_feedback', $feedback );

		wp_send_json_success( array( 'message' => esc_html__( 'Feedback saved.', 'zeko-jobs' ) ) );
	}

	/**
	 * Handle save pipeline stages.
	 */
	public function handle_save_pipeline_stages(): void {
		check_ajax_referer( 'zeko_job_create', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
			return;
		}

		$stages_raw = isset( $_POST['stages'] ) ? wp_unslash( $_POST['stages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested array; each slug/label sanitized per-field below.
		if ( ! is_array( $stages_raw ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid stages data.', 'zeko-jobs' ) ) );
			return;
		}

		$stages = array();
		foreach ( $stages_raw as $s ) {
			if ( is_array( $s ) && ! empty( $s['slug'] ) && ! empty( $s['label'] ) ) {
				$stages[] = array(
					'slug'  => sanitize_key( (string) $s['slug'] ),
					'label' => sanitize_text_field( (string) $s['label'] ),
				);
			}
		}

		if ( count( $stages ) < 2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'At least 2 stages are required.', 'zeko-jobs' ) ) );
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$saved   = $zeko_db->save_employer_pipeline_stages( get_current_user_id(), $stages );
		$updated = $zeko_db->get_employer_pipeline_stages( get_current_user_id() );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Pipeline stages saved.', 'zeko-jobs' ),
				'stages'  => $updated,
			)
		);
	}

	/**
	 * Handle get pipeline stages.
	 */
	public function handle_get_pipeline_stages(): void {
		check_ajax_referer( 'zeko_job_create', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$stages  = $zeko_db->get_employer_pipeline_stages( get_current_user_id() );

		wp_send_json_success( array( 'stages' => $stages ) );
	}

	/**
	 * AJAX: Export all user data for GDPR compliance.
	 */
	public function handle_export_data(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		$zeko_db = Zeko_Jobs_DB::get_instance();

		$user = get_userdata( $user_id );

		$data = array(
			'export_date'     => current_time( 'c' ),
			'user_info'       => array(
				'id'           => $user_id,
				'display_name' => $user ? $user->display_name : '',
				'email'        => $user ? $user->user_email : '',
				'registered'   => $user ? $user->user_registered : '',
			),
			'profile'         => array(
				'location'         => get_user_meta( $user_id, 'zeko_location', true ),
				'phone'            => get_user_meta( $user_id, 'zeko_phone', true ),
				'skills'           => get_user_meta( $user_id, 'zeko_skills', true ),
				'work_experience'  => get_user_meta( $user_id, 'zeko_work_experience', true ),
				'education'        => get_user_meta( $user_id, 'zeko_education', true ),
				'saved_resume_url' => get_user_meta( $user_id, 'zeko_saved_resume_url', true ),
				'profile_visible'  => get_user_meta( $user_id, 'zeko_profile_visible', true ),
				'open_to_work'     => get_user_meta( $user_id, 'zeko_open_to_work', true ),
			),
			'applications'    => $zeko_db->get_user_applications( $user_id ),
			'saved_jobs'      => get_user_meta( $user_id, 'zeko_saved_jobs', true ) ?: array(),
			'bookmarked_jobs' => get_user_meta( $user_id, 'zeko_bookmarked_jobs', true ) ?: array(),
			'job_alerts'      => $zeko_db->get_job_alerts( $user_id ),
			'saved_searches'  => $zeko_db->get_saved_searches( $user_id ),
			'documents'       => $zeko_db->get_documents( $user_id ),
			'interviews'      => $zeko_db->get_seeker_interviews( $user_id ),
			'reminders'       => $zeko_db->get_user_reminders( $user_id ),
			'notifications'   => get_user_meta( $user_id, 'zeko_notifications', true ) ?: array(),
			'cover_templates' => get_user_meta( $user_id, 'zeko_cover_templates', true ) ?: array(),
			'activity'        => $zeko_db->get_job_activities_for_feed( $user_id, 100 ),
		);

		/**
		 * Filters the user data exported for GDPR compliance.
		 *
		 * @param array $data    The exported data array.
		 * @param int   $user_id The user ID.
		 */
		$data = apply_filters( 'zeko_jobs_gdpr_export_data', $data, $user_id );

		wp_send_json_success( array( 'data' => $data ) );
	}

	/**
	 * AJAX: Request account data deletion (GDPR right to erasure).
	 */
	public function handle_request_deletion(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();

		$existing = get_user_meta( $user_id, 'zeko_deletion_requested_at', true );
		if ( $existing ) {
			wp_send_json_error( array( 'message' => esc_html__( 'A deletion request is already pending.', 'zeko-jobs' ) ) );
		}

		update_user_meta( $user_id, 'zeko_deletion_requested_at', current_time( 'mysql' ) );

		/**
		 * Fires after a user requests account data deletion.
		 *
		 * @param int $user_id The user ID.
		 */
		do_action( 'zeko_jobs_deletion_requested', $user_id );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Your deletion request has been received. Your data will be permanently removed after a 30-day grace period. You can cancel this request within that time.', 'zeko-jobs' ),
			)
		);
	}

	/**
	 * AJAX: Cancel a pending deletion request.
	 */
	public function handle_cancel_deletion(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id  = get_current_user_id();
		$existing = get_user_meta( $user_id, 'zeko_deletion_requested_at', true );

		if ( ! $existing ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No pending deletion request found.', 'zeko-jobs' ) ) );
		}

		delete_user_meta( $user_id, 'zeko_deletion_requested_at' );

		/**
		 * Fires after a user cancels a pending deletion request.
		 *
		 * @param int $user_id The user ID.
		 */
		do_action( 'zeko_jobs_deletion_cancelled', $user_id );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Your deletion request has been cancelled. Your account and data will remain active.', 'zeko-jobs' ),
			)
		);
	}

	/**
	 * Handle load more.
	 */
	public function handle_load_more(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 2;
		if ( $page < 2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid page.', 'zeko-jobs' ) ) );
		}

		if ( ! Zeko_Jobs_Rate_Limiter::check( 'load_more', 20, MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Too many requests. Please slow down.', 'zeko-jobs' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_jobs';

		$where  = "WHERE status = 'publish'";
		$params = array();
		$join   = '';

		$search_filter     = isset( $_POST['job_search'] ) ? sanitize_text_field( wp_unslash( $_POST['job_search'] ) ) : '';
		$type_filter       = isset( $_POST['job_type'] ) ? sanitize_text_field( wp_unslash( $_POST['job_type'] ) ) : '';
		$location_filter   = isset( $_POST['job_location'] ) ? sanitize_text_field( wp_unslash( $_POST['job_location'] ) ) : '';
		$category_filter   = isset( $_POST['job_category'] ) ? sanitize_text_field( wp_unslash( $_POST['job_category'] ) ) : '';
		$industry_filter   = isset( $_POST['job_industry'] ) ? sanitize_text_field( wp_unslash( $_POST['job_industry'] ) ) : '';
		$company_filter    = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
		$experience_filter = isset( $_POST['experience_level'] ) ? sanitize_text_field( wp_unslash( $_POST['experience_level'] ) ) : '';
		$remote_filter     = isset( $_POST['remote_option'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_option'] ) ) : '';
		$sort_filter       = isset( $_POST['sort'] ) ? sanitize_text_field( wp_unslash( $_POST['sort'] ) ) : '';
		$salary_min        = isset( $_POST['salary_min'] ) ? absint( $_POST['salary_min'] ) : 0;
		$salary_max        = isset( $_POST['salary_max'] ) ? absint( $_POST['salary_max'] ) : 0;
		$date_range        = isset( $_POST['date_range'] ) ? absint( $_POST['date_range'] ) : 0;
		$nearby_lat        = isset( $_POST['nearby_lat'] ) ? (float) $_POST['nearby_lat'] : 0;
		$nearby_lng        = isset( $_POST['nearby_lng'] ) ? (float) $_POST['nearby_lng'] : 0;
		$nearby_radius     = isset( $_POST['nearby_radius'] ) ? absint( $_POST['nearby_radius'] ) : 0;

		if ( ! empty( $type_filter ) ) {
			$where   .= ' AND j.type = %s';
			$params[] = $type_filter;
		}
		if ( ! empty( $search_filter ) ) {
			$like     = '%' . $wpdb->esc_like( $search_filter ) . '%';
			$where   .= ' AND (j.title LIKE %s OR j.description LIKE %s OR j.excerpt LIKE %s OR j.location LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $location_filter ) ) {
			$loc_like = '%' . $wpdb->esc_like( $location_filter ) . '%';
			$where   .= ' AND j.location LIKE %s';
			$params[] = $loc_like;
		}
		if ( ! empty( $category_filter ) ) {
			$where   .= ' AND j.category = %s';
			$params[] = $category_filter;
		}
		if ( ! empty( $industry_filter ) ) {
			$where   .= ' AND j.company_industry = %s';
			$params[] = $industry_filter;
		}
		if ( ! empty( $company_filter ) ) {
			$company_like = '%' . $wpdb->esc_like( $company_filter ) . '%';
			$where       .= ' AND (u.display_name LIKE %s)';
			$params[]     = $company_like;
			if ( false === strpos( $join, 'LEFT JOIN' ) ) {
				$join .= " LEFT JOIN {$wpdb->users} u ON u.ID = j.employer_id";
			}
		}
		if ( $salary_min > 0 ) {
			$where   .= ' AND j.salary_max >= %d';
			$params[] = $salary_min;
		}
		if ( $salary_max > 0 ) {
			$where   .= ' AND j.salary_min <= %d';
			$params[] = $salary_max;
		}
		if ( ! empty( $experience_filter ) ) {
			$where   .= ' AND j.experience_level = %s';
			$params[] = $experience_filter;
		}
		if ( ! empty( $remote_filter ) ) {
			$where   .= ' AND j.remote_option = %s';
			$params[] = $remote_filter;
		}
		if ( $date_range > 0 ) {
			$where   .= ' AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
			$params[] = $date_range;
		}

		$distance_select = '';
		$haversine_where = '';
		if ( 0.0 !== $nearby_lat && 0.0 !== $nearby_lng && $nearby_radius > 0 ) {
			// Haversine formula: distance in miles.
			$distance_select = ', (3959 * ACOS( COS(RADIANS(%f)) * COS(RADIANS(j.latitude)) * COS(RADIANS(j.longitude) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(j.latitude)) )) AS distance';
			$haversine_where = ' AND j.latitude IS NOT NULL AND j.longitude IS NOT NULL AND (3959 * ACOS( COS(RADIANS(%f)) * COS(RADIANS(j.latitude)) * COS(RADIANS(j.longitude) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(j.latitude)) )) <= %d';
			$params          = array_merge( array( $nearby_lat, $nearby_lng, $nearby_lat ), $params, array( $nearby_lat, $nearby_lng, $nearby_lat, $nearby_radius ) );
		}
		if ( false === strpos( $join, 'LEFT JOIN ' . $wpdb->users ) ) {
			$join .= " LEFT JOIN {$wpdb->users} u ON u.ID = j.employer_id";
		}

		if ( ! empty( $haversine_where ) ) {
			$where .= $haversine_where;
		}

		$per_page = 10;
		$offset   = ( $page - 1 ) * $per_page;

		$order = 'j.is_featured DESC, j.created_at DESC';
		if ( ! empty( $search_filter ) ) {
			$order = 'j.is_featured DESC, j.created_at DESC';
		}
		switch ( $sort_filter ) {
			case 'date':
				$order = 'j.created_at DESC';
				break;
			case 'salary_high':
				$order = 'j.salary_max DESC';
				break;
			case 'salary_low':
				$order = 'j.salary_min ASC';
				break;
			case 'company_az':
				$order = 'u.display_name ASC';
				break;
			case 'distance':
				if ( 0.0 !== $nearby_lat && 0.0 !== $nearby_lng ) {
					$order = 'distance ASC';
				}
				break;
		}

		$table_apps = $wpdb->prefix . 'zeko_job_applications';
		$sql        = "SELECT j.*{$distance_select}, (SELECT COUNT(*) FROM {$table_apps} a WHERE a.job_id = j.id) AS application_count FROM {$table_name} j {$join} {$where} ORDER BY {$order} LIMIT %d OFFSET %d";

		$count_sql = "SELECT COUNT(*) FROM {$table_name} {$where}";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$total = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$total_pages = (int) ceil( $total / $per_page );

		$all_params = array_merge( $params, array( $per_page, $offset ) );
		$jobs       = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( empty( $jobs ) ) {
			wp_send_json_success(
				array(
					'html'     => '',
					'has_more' => false,
					'total'    => $total,
				)
			);
		}

		$zeko_db         = Zeko_Jobs_DB::get_instance();
		$current_user_id = get_current_user_id();
		$is_seeker       = $current_user_id > 0;
		$bookmarked_ids  = array();
		$applied_ids     = array();
		if ( $is_seeker ) {
			$raw            = get_user_meta( $current_user_id, 'zeko_bookmarked_jobs', true );
			$bookmarked_ids = is_array( $raw ) ? array_map( 'intval', $raw ) : array();
			$applied_ids    = $zeko_db->get_applied_job_ids( $current_user_id );
		}

		$match_scores  = array();
		$skills_counts = array();
		if ( $is_seeker ) {
			$match_scores  = $zeko_db->get_bulk_match_scores( $current_user_id, array_column( $jobs, 'id' ) );
			$skills_counts = $zeko_db->get_bulk_skills_counts( $current_user_id, array_column( $jobs, 'id' ) );
		}

		// Hoist per-user queries outside the loop to avoid N+1.
		$seeker_has_resume = false;
		if ( $is_seeker ) {
			$seeker_has_resume_raw = ! empty( get_user_meta( $current_user_id, 'zeko_saved_resume_url', true ) );
			$seeker_documents      = get_user_meta( $current_user_id, 'zeko_documents', true );
			$seeker_documents      = is_array( $seeker_documents ) ? $seeker_documents : array();
			$seeker_has_resume     = $seeker_has_resume_raw || ! empty( $seeker_documents );
		}

		$html = '';
		foreach ( $jobs as $job ) {
			$employer_id  = (int) $job['employer_id'];
			$employer     = $employer_id > 0 ? get_userdata( $employer_id ) : false;
			$company_name = $employer ? $employer->display_name : __( 'N/A', 'zeko-jobs' );
			$type_icons   = array(
				'full-time' => 'dashicons-portfolio',
				'part-time' => 'dashicons-clock',
				'contract'  => 'dashicons-clipboard',
				'freelance' => 'dashicons-randomize',
			);
			$type_icon    = isset( $type_icons[ $job['type'] ] ) ? $type_icons[ $job['type'] ] : 'dashicons-tag';
			$logo_id      = ! empty( $job['company_logo_id'] ) ? (int) $job['company_logo_id'] : 0;
			$is_new       = strtotime( $job['created_at'] ) > strtotime( '-3 days' );
			$remote       = ! empty( $job['remote_option'] ) ? $job['remote_option'] : '';
			$is_easy      = ! empty( $job['is_easy_apply'] );
			$app_count    = isset( $job['application_count'] ) ? (int) $job['application_count'] : 0;

			ob_start();
			?>
			<article class="zeko-job-card<?php echo ! empty( $job['is_featured'] ) ? ' is-featured' : ''; ?>" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
				<div class="zeko-card-top">
				<?php if ( $is_seeker && $employer_id !== $current_user_id ) : ?>
						<label class="zeko-bulk-checkbox" title="<?php esc_attr_e( 'Select for bulk apply', 'zeko-jobs' ); ?>">
							<input type="checkbox" class="zeko-bulk-select" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
							<span class="zeko-bulk-checkmark"></span>
						</label>
					<?php endif; ?>
					<div class="zeko-card-logo-col">
					<?php if ( $logo_id ) : ?>
							<div class="zeko-card-logo">
							<?php
							echo wp_get_attachment_image(
								$logo_id,
								'thumbnail',
								false,
								array(
									'alt'     => esc_attr( $company_name ),
									'loading' => 'lazy',
								)
							);
							?>
														</div>
						<?php else : ?>
							<div class="zeko-card-logo zeko-card-logo-placeholder"><span><?php echo esc_html( mb_substr( $company_name, 0, 2 ) ); ?></span></div>
						<?php endif; ?>
					</div>
					<div class="zeko-card-info">
						<div class="zeko-card-badges">
							<?php
							if ( ! empty( $job['is_featured'] ) ) :
								?>
								<span class="zeko-badge zeko-badge-featured"><?php esc_html_e( 'Featured', 'zeko-jobs' ); ?></span><?php endif; ?>
								<?php
								if ( $is_new ) :
									?>
								<span class="zeko-badge zeko-badge-new"><?php esc_html_e( 'New', 'zeko-jobs' ); ?></span><?php endif; ?>
								<?php
								$match_score = $match_scores[ (int) $job['id'] ] ?? null;
								if ( null !== $match_score && $match_score > 0 ) :
									$match_class = $match_score >= 80 ? 'match-high' : ( $match_score >= 50 ? 'match-medium' : 'match-low' );
									?>
								<span class="zeko-badge zeko-badge-match <?php echo esc_attr( $match_class ); ?>"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span> <?php echo esc_html( $match_score ); ?>% <?php esc_html_e( 'match', 'zeko-jobs' ); ?></span>
								<?php endif; ?>
								<?php
								if ( $remote ) :
									?>
								<span class="zeko-badge zeko-badge-remote"><span class="dashicons dashicons-admin-home" aria-hidden="true"></span> <?php echo esc_html( ucfirst( $remote ) ); ?></span><?php endif; ?>
								<?php
								if ( $is_easy ) :
									?>
								<span class="zeko-badge zeko-badge-easy"><span class="dashicons dashicons-rocket" aria-hidden="true"></span> <?php esc_html_e( 'Easy Apply', 'zeko-jobs' ); ?></span><?php endif; ?>
						</div>
						<h3 class="zeko-card-title"><a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] ) ); ?>"><?php echo esc_html( $job['title'] ); ?></a></h3>
						<div class="zeko-card-company">
							<span class="zeko-card-company-name"><?php echo esc_html( $company_name ); ?></span>
								<?php if ( $zeko_db->is_employer_verified( $employer_id ) ) : ?>
								<span class="zeko-verified-badge" title="<?php esc_attr_e( 'Verified Employer', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span>
							<?php endif; ?>
						</div>
					</div>
						<?php if ( $is_seeker ) : ?>
							<?php $is_bookmarked = in_array( (int) $job['id'], $bookmarked_ids, true ); ?>
						<button type="button" class="zeko-bookmark-icon <?php echo $is_bookmarked ? 'is-bookmarked' : ''; ?>" data-job-id="<?php echo esc_attr( $job['id'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_bookmark' ) ); ?>" aria-label="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'zeko-jobs' ) : esc_attr__( 'Bookmark job', 'zeko-jobs' ); ?>">
							<span class="dashicons <?php echo $is_bookmarked ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>" aria-hidden="true"></span>
						</button>
					<?php endif; ?>
				</div>
				<div class="zeko-card-meta">
					<span class="zeko-card-meta-item"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $job['location'] ); ?></span>
					<span class="zeko-card-meta-item"><span class="dashicons <?php echo esc_attr( $type_icon ); ?>" aria-hidden="true"></span> <?php echo esc_html( ucfirst( str_replace( '-', ' ', $job['type'] ) ) ); ?></span>
						<?php if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) : ?>
						<span class="zeko-card-meta-item zeko-card-salary"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span> $<?php echo esc_html( number_format( (float) $job['salary_min'] ) ); ?> &ndash; $<?php echo esc_html( number_format( (float) $job['salary_max'] ) ); ?></span>
					<?php endif; ?>
					<span class="zeko-card-meta-item"><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php /* translators: %d: number of applicants */ echo esc_html( sprintf( _n( '%d applicant', '%d applicants', $app_count, 'zeko-jobs' ), $app_count ) ); ?></span>
				</div>
				<div class="zeko-card-badges-bottom">
						<?php if ( $app_count > 0 && $is_seeker && $employer_id !== $current_user_id ) : ?>
							<?php
							if ( $app_count <= 5 ) {
								$cc = 'zeko-comp-low';
								$cl = __( 'Low competition', 'zeko-jobs' ); } elseif ( $app_count <= 15 ) {
								$cc = 'zeko-comp-medium';
								$cl = __( 'Medium competition', 'zeko-jobs' ); } else {
									$cc = 'zeko-comp-high';
									$cl = __( 'High competition', 'zeko-jobs' ); }
								?>
						<span class="zeko-competition-badge <?php echo esc_attr( $cc ); ?>"><?php echo esc_html( $cl ); ?></span>
					<?php endif; ?>
						<?php
						if ( $is_seeker && $employer_id !== $current_user_id ) :
							$m_score = $match_scores[ (int) $job['id'] ] ?? 0;
							$m_class = $m_score >= 75 ? 'zeko-match-high' : ( $m_score >= 50 ? 'zeko-match-mid' : 'zeko-match-low' );
							?>
						<span class="zeko-match-badge <?php echo esc_attr( $m_class ); ?>" title="<?php /* translators: %d: profile match percentage */ echo esc_attr( sprintf( __( '%d%% profile match', 'zeko-jobs' ), $m_score ) ); ?>">
							<span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php echo esc_html( sprintf( '%d%%', $m_score ) ); ?>
						</span>
						<?php endif; ?>
						<?php
						$skills_job = $skills_counts[ (int) $job['id'] ] ?? null;
						if ( $is_seeker && $skills_job && $skills_job['total'] > 0 ) :
							$skills_ok = $skills_job['total'] === $skills_job['matched'];
							?>
						<span class="zeko-skills-count-badge <?php echo $skills_ok ? 'zeko-skills-ok' : ''; ?>" title="<?php /* translators: 1: number of matched skills. 2: total required skills */ echo esc_attr( sprintf( __( 'You have %1$d of %2$d required skills', 'zeko-jobs' ), $skills_job['matched'], $skills_job['total'] ) ); ?>">
							<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
							<?php /* translators: 1: number of matched skills. 2: total required skills */ echo esc_html( sprintf( __( '%1$d/%2$d skills', 'zeko-jobs' ), $skills_job['matched'], $skills_job['total'] ) ); ?>
						</span>
						<?php endif; ?>
				</div>
					<?php
					if ( $is_seeker && $employer_id !== $current_user_id ) :
						$has_resume  = $seeker_has_resume;
						$has_applied = in_array( (int) $job['id'], $applied_ids, true );
						?>
					<div class="zeko-card-actions">
						<?php if ( $has_applied ) : ?>
							<span class="zeko-card-applied-label"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Applied', 'zeko-jobs' ); ?></span>
						<?php else : ?>
							<?php if ( $is_easy && $has_resume ) : ?>
								<button type="button" class="button zeko-one-click-apply-btn" data-job-id="<?php echo esc_attr( $job['id'] ); ?>"><span class="dashicons dashicons-rocket" aria-hidden="true"></span> <?php esc_html_e( 'Quick Apply', 'zeko-jobs' ); ?></button>
							<?php endif; ?>
							<button type="button" class="button button-primary zeko-job-apply-btn" data-job-id="<?php echo esc_attr( $job['id'] ); ?>"><?php esc_html_e( 'Apply', 'zeko-jobs' ); ?></button>
						<?php endif; ?>
					</div>
					<?php endif; ?>
			</article>
				<?php
				$html .= ob_get_clean();
		}

		wp_send_json_success(
			array(
				'html'     => $html,
				'has_more' => $page < $total_pages,
				'total'    => $total,
			)
		);
	}

	// ── ZekoPay billing AJAX handlers ──────────────────────────────.

	/**
	 * Helper: maybe charge posting fee. Returns true/WP_Error.
	 *
	 * @return true|\WP_Error
	 * @param int $user_id Employer user ID.
	 */
	public static function maybe_charge_posting_fee( int $user_id ) {
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$settings = $zeko_db::get_billing_settings();

		if ( ! $zeko_db::payments_enabled() ) {
			return true;
		}

		$payment_type = $zeko_db->get_posting_payment_type( $user_id );

		if ( 'free' === $payment_type || 'pack' === $payment_type ) {
			return true;
		}

		if ( ! class_exists( 'Zeko_Pay_SDK' ) ) {
			return new \WP_Error( 'pay_unavailable', esc_html__( 'ZekoPay is not available.', 'zeko-jobs' ) );
		}

		$posting_fee = (float) ( $settings['posting_fee'] ?? 25 );
		if ( $posting_fee <= 0 ) {
			return true;
		}

		$sdk    = new Zeko_Pay_SDK();
		$result = $sdk->charge(
			$user_id,
			$posting_fee,
			'Job posting fee',
			array(
				'source' => 'zeko_jobs',
				'type'   => 'posting_fee',
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$zeko_db->log_billing(
				array(
					'user_id'        => $user_id,
					'type'           => 'posting_fee',
					'amount'         => $posting_fee,
					'zeko_pay_tx_id' => $result['tx_id'] ?? null,
					'status'         => 'completed',
					'description'    => 'Job posting fee',
				)
			);
			return true;
		}

		$message = $result['message'] ?? esc_html__( 'Insufficient funds. Please top up your ZekoPay wallet.', 'zeko-jobs' );
		return new \WP_Error( 'insufficient_funds', $message, array( 'redirect' => home_url( '/wallet/' ) ) );
	}

	/**
	 * AJAX: Check employer's ZekoPay wallet balance + posting cost.
	 */
	public function handle_check_balance(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id  = get_current_user_id();
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$settings = $zeko_db::get_billing_settings();

		$balance = '0.00';
		if ( class_exists( 'Zeko_Pay_SDK' ) ) {
			$sdk     = new Zeko_Pay_SDK();
			$balance = $sdk->get_balance( $user_id );
		}

		$posting_fee  = (float) ( $settings['posting_fee'] ?? 25 );
		$boost_fee    = (float) ( $settings['boost_fee'] ?? 10 );
		$payment_type = $zeko_db->get_posting_payment_type( $user_id );

		wp_send_json_success(
			array(
				'balance'      => $balance,
				'posting_fee'  => $posting_fee,
				'boost_fee'    => $boost_fee,
				'payment_type' => $payment_type,
				'currency'     => 'USD',
			)
		);
	}

	/**
	 * AJAX: Purchase a listing pack.
	 */
	public function handle_buy_listing_pack(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id  = get_current_user_id();
		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$settings = $zeko_db::get_billing_settings();

		if ( ! class_exists( 'Zeko_Pay_SDK' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'ZekoPay is not available.', 'zeko-jobs' ) ) );
		}

		$pack_price = (float) ( $settings['listing_pack_price'] ?? 99 );
		$pack_count = (int) ( $settings['listing_pack_count'] ?? 10 );

		if ( $pack_price <= 0 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Listing pack not configured.', 'zeko-jobs' ) ) );
		}

		$sdk    = new Zeko_Pay_SDK();
		$result = $sdk->charge(
			$user_id,
			$pack_price,
			sprintf( 'Listing pack (%d jobs)', $pack_count ),
			array(
				'source'     => 'zeko_jobs',
				'type'       => 'listing_pack',
				'pack_count' => $pack_count,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$zeko_db->log_billing(
				array(
					'user_id'        => $user_id,
					'type'           => 'listing_pack',
					'amount'         => $pack_price,
					'zeko_pay_tx_id' => $result['tx_id'] ?? null,
					'status'         => 'completed',
					'description'    => sprintf( 'Listing pack (%d jobs)', $pack_count ),
				)
			);

			wp_send_json_success(
				array(
					'message'    => sprintf(
					/* translators: 1: number of jobs, 2: amount paid */
						__( 'Listing pack purchased: %1$d job postings for %2$s.', 'zeko-jobs' ),
						$pack_count,
						'$' . number_format( $pack_price, 2 )
					),
					'pack_count' => $pack_count,
				)
			);
			return;
		}

		$message = $result['message'] ?? esc_html__( 'Insufficient funds. Please top up your ZekoPay wallet.', 'zeko-jobs' );
		wp_send_json_error(
			array(
				'message'  => $message,
				'redirect' => home_url( '/wallet/' ),
			)
		);
	}

	/**
	 * AJAX: Get billing history for employer.
	 */
	public function handle_billing_history(): void {
		check_ajax_referer( 'zeko_job_dashboard', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please log in.', 'zeko-jobs' ) ) );
		}

		$user_id = get_current_user_id();
		$zeko_db = Zeko_Jobs_DB::get_instance();
		$history = $zeko_db->get_billing_history( $user_id, 50 );

		wp_send_json_success( array( 'history' => $history ) );
	}
}
