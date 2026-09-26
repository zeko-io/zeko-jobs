<?php
/**
 * Uninstall script for Zeko Jobs.
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Cleans up database tables, user meta, plugin options, and scheduled cron events.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix;

// Drop all custom tables.
$tables = array(
	// Core tables.
	$prefix . 'zeko_jobs',
	$prefix . 'zeko_job_applications',
	$prefix . 'zeko_job_reminders',
	// Phase 1.4 tables.
	$prefix . 'zeko_job_application_notes',
	$prefix . 'zeko_job_application_history',
	// Phase 1.8+ tables.
	$prefix . 'zeko_job_searches',
	// Phase 2.0+ tables.
	$prefix . 'zeko_job_documents',
	$prefix . 'zeko_job_interviews',
	// Phase 2.2+ tables.
	$prefix . 'zeko_job_reviews',
	$prefix . 'zeko_companies',
	// Phase 2.5+ tables.
	$prefix . 'zeko_app_ratings',
	// Phase 2.6+ tables.
	$prefix . 'zeko_job_views_log',
	// Phase 2.7+ tables.
	$prefix . 'zeko_job_alerts',
	$prefix . 'zeko_job_email_opens',
	// Phase 3.5+ tables.
	$prefix . 'zeko_job_flags',
	// Phase 3.6+ tables.
	$prefix . 'zeko_company_follows',
	// Phase 3.7+ tables.
	$prefix . 'zeko_company_posts',
	$prefix . 'zeko_message_templates',
	// Phase 2.9+ tables.
	$prefix . 'zeko_job_notifications',
	// Monetization billing ledger.
	$prefix . 'zeko_job_billing',
);

// Phase 3.0+ messaging tables. These are SHARED with Zeko Core: when Core is.
// active it is the canonical owner of messages/conversations/message_meta (see.
// class-zeko-app-schema.php) and jobs only mirrors them as a fallback. Never.
// drop them from a child module while Core is present.
if ( ! class_exists( 'Zeko_Core_DB' ) ) {
	$tables = array_merge(
		$tables,
		array(
			$prefix . 'zeko_messages',
			$prefix . 'zeko_conversations',
			$prefix . 'zeko_message_meta',
		)
	);
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Delete plugin user meta (jobs-owned keys only — never a bare `zeko_%`.
// wildcard, which would wipe other ecosystem modules' meta).
$profile_keys = array(
	'zeko_skills',
	'zeko_bio',
	'zeko_location',
	'zeko_phone',
	'zeko_education',
	'zeko_work_experience',
	'zeko_resume',
	'zeko_profile_visible',
	'zeko_default_resume_id',
	'zeko_structured_resumes',
	'zeko_documents',
	'zeko_saved_jobs',
	'zeko_bookmarked_jobs',
	'zeko_viewed_jobs',
	'zeko_not_interested_jobs',
	'zeko_open_to_work',
	'zeko_employer_status',
	'zeko_verified_employer',
	'zeko_company_slug',
	'zeko_company_logo',
	'zeko_company_logo_id',
	'zeko_map_data',
	'zeko_email_templates',
	'zeko_cover_templates',
	'zeko_csv_file',
	'zeko_email_track',
	'zeko_jobs_email_prefs',
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
$placeholders = implode( ',', array_fill( 0, count( $profile_keys ), '%s' ) );
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		"DELETE FROM {$wpdb->usermeta}
		 WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key IN ({$placeholders})",
		array_merge(
			array( 'zeko_job%', 'zeko_jobs%', 'zeko_linkedin%' ),
			$profile_keys
		)
	)
);
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

// Delete plugin options.
$options = array(
	'zeko_jobs_db_version',
	'zeko_jobs_settings',
	'zeko_jobs_send_status_emails',
	'zeko_messages_page_id',
	'zeko_linkedin_client_id',
	'zeko_linkedin_client_secret',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear all scheduled cron events.
$cron_hooks = array(
	'zeko_jobs_daily_digest',
	'zeko_jobs_check_expirations',
	'zeko_jobs_expiry_reminders',
	'zeko_jobs_interview_reminders',
	'zeko_jobs_daily_alerts',
	'zeko_jobs_weekly_alerts',
	'zeko_jobs_db_cleanup',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
