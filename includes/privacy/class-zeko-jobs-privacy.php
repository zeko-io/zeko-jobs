<?php
/**
 * Zeko Jobs — WordPress personal-data exporter and eraser for the account
 * domain (complements the existing Candidate Documents privacy group that is
 * registered inline in zeko-jobs.php).
 *
 * Covers job applications (with their notes and status history), interviews,
 * saved searches, job alerts, reminders, job reviews, employer application
 * ratings, notifications, message templates, billing entries, company follows,
 * ascribed view log rows and ascribed email-open rows. Public community
 * content is kept but de-anonymized:
 *
 *   jobs.employer_id, companies.employer_id, reviews.user_id → 0
 *   interviews.interviewer_id, flags.resolved_by             → 0
 *
 * Age-based retention for views_log / email_opens / notifications / flags is
 * already owned by Zeko_Jobs_DB::run_cleanup() (90/180/90/90 days
 * respectively) plus the document retention cron in zeko-jobs.php — no
 * duplicate retention registry entries are introduced here.
 *
 * Jobs DB exposes no table getters, so all tables are addressed by full
 * prefixed name behind the established SHOW-TABLES guard.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the exporter and eraser callbacks.
 */
function zeko_jobs_account_privacy_register(): void {
	add_filter( 'wp_privacy_personal_data_exporters', 'zeko_jobs_account_privacy_register_exporter' );
	add_filter( 'wp_privacy_personal_data_erasers', 'zeko_jobs_account_privacy_register_eraser' );
}
add_action( 'init', 'zeko_jobs_account_privacy_register', 11 );

/**
 * Register the personal-data exporter (distinct from the Candidate Documents
 * exporter keyed 'zeko-job-documents').
 *
 * @param array $exporters Exporters.
 */
function zeko_jobs_account_privacy_register_exporter( array $exporters ): array {
	$exporters['zeko-job-account'] = array(
		'exporter_friendly_name' => __( 'Zeko Jobs Account data', 'zeko-jobs' ),
		'callback'               => 'zeko_jobs_account_privacy_export',
	);
	return $exporters;
}

/**
 * Register the personal-data eraser.
 *
 * @param array $erasers Erasers.
 */
function zeko_jobs_account_privacy_register_eraser( array $erasers ): array {
	$erasers['zeko-job-account'] = array(
		'eraser_friendly_name' => __( 'Zeko Jobs Account data', 'zeko-jobs' ),
		'callback'             => 'zeko_jobs_account_privacy_erase',
	);
	return $erasers;
}

/**
 * Whether a jobs table exists (guards every touch of a table).
 *
 * @param string $table Table.
 */
function zeko_jobs_account_privacy_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Export a user's Zeko Jobs account data, 20 rows per table per page.
 *
 * @return array{data: array, done: bool}
 * @param string $email_address User who requested the export.
 * @param int    $page Export page (batching).
 */
function zeko_jobs_account_privacy_export( string $email_address, int $page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	global $wpdb;

	$user_id  = (int) $user->ID;
	$per_page = 20;
	$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
	$data     = array();
	$done     = true;

	$tables = array(
		'applications' => $wpdb->prefix . 'zeko_job_applications',
		'jobs'         => $wpdb->prefix . 'zeko_jobs',
		'notes'        => $wpdb->prefix . 'zeko_job_application_notes',
		'history'      => $wpdb->prefix . 'zeko_job_application_history',
		'interviews'   => $wpdb->prefix . 'zeko_job_interviews',
		'reviews'      => $wpdb->prefix . 'zeko_job_reviews',
		'ratings'      => $wpdb->prefix . 'zeko_app_ratings',
		'notifs'       => $wpdb->prefix . 'zeko_job_notifications',
		'reminders'    => $wpdb->prefix . 'zeko_job_reminders',
		'searches'     => $wpdb->prefix . 'zeko_job_searches',
		'alerts'       => $wpdb->prefix . 'zeko_job_alerts',
		'templates'    => $wpdb->prefix . 'zeko_message_templates',
		'billing'      => $wpdb->prefix . 'zeko_job_billing',
		'follows'      => $wpdb->prefix . 'zeko_company_follows',
		'views'        => $wpdb->prefix . 'zeko_job_views_log',
	);

	if ( ! zeko_jobs_account_privacy_table_exists( $tables['applications'] ) ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	// Applications (seekership is the user) with their job title.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$apps = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.id, a.job_id, a.status, a.applied_at, a.updated_at, a.viewed_at, j.title AS job_title FROM {$tables['applications']} a LEFT JOIN {$tables['jobs']} j ON j.id = a.job_id WHERE a.seeker_id = %d ORDER BY a.id ASC LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	foreach ( $apps as $app ) {
		$app_data = array(
			array(
				'name'  => __( 'Job', 'zeko-jobs' ),
				'value' => (string) $app['job_title'],
			),
			array(
				'name'  => __( 'Status', 'zeko-jobs' ),
				'value' => (string) $app['status'],
			),
			array(
				'name'  => __( 'Applied at', 'zeko-jobs' ),
				'value' => (string) $app['applied_at'],
			),
			array(
				'name'  => __( 'Updated at', 'zeko-jobs' ),
				'value' => (string) $app['updated_at'],
			),
			array(
				'name'  => __( 'Viewed at', 'zeko-jobs' ),
				'value' => (string) $app['viewed_at'],
			),
		);

		// Interview invite tied to this application.
		if ( zeko_jobs_account_privacy_table_exists( $tables['interviews'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$interview = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT scheduled_at, duration, location, type, status, notes FROM {$tables['interviews']} WHERE application_id = %d LIMIT 1",
					(int) $app['id']
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( $interview ) {
				$app_data[] = array(
					'name'  => __( 'Interview', 'zeko-jobs' ),
					'value' => sprintf( '%s at %s (%s %s min) — %s', $interview['type'], $interview['scheduled_at'], $interview['location'], $interview['duration'], $interview['status'] ),
				);
				if ( ! empty( $interview['notes'] ) ) {
					$app_data[] = array(
						'name'  => __( 'Interview notes', 'zeko-jobs' ),
						'value' => (string) $interview['notes'],
					);
				}
			}
		}

		// Employer notes and status history per application.
		if ( zeko_jobs_account_privacy_table_exists( $tables['notes'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$notes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT note, created_at FROM {$tables['notes']} WHERE application_id = %d ORDER BY created_at ASC, id ASC",
					(int) $app['id']
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			foreach ( (array) $notes as $note ) {
				$app_data[] = array(
					'name'  => __( 'Employer note', 'zeko-jobs' ),
					'value' => sprintf( '%s (%s)', $note->note, $note->created_at ),
				);
			}
		}
		if ( zeko_jobs_account_privacy_table_exists( $tables['history'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$history = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT from_status, to_status, created_at FROM {$tables['history']} WHERE application_id = %d ORDER BY created_at ASC, id ASC",
					(int) $app['id']
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			foreach ( (array) $history as $entry ) {
				$app_data[] = array(
					'name'  => __( 'Status change', 'zeko-jobs' ),
					'value' => sprintf( '%s → %s (%s)', $entry->from_status, $entry->to_status, $entry->created_at ),
				);
			}
		}

		// Employer rating for this application.
		if ( zeko_jobs_account_privacy_table_exists( $tables['ratings'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$rating = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT rating, note, updated_at FROM {$tables['ratings']} WHERE application_id = %d LIMIT 1",
					(int) $app['id']
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( $rating ) {
				$app_data[] = array(
					'name'  => __( 'Employer rating', 'zeko-jobs' ),
					'value' => sprintf( '%s/5 — %s', $rating['rating'], $rating['note'] ),
				);
			}
		}

		$data[] = array(
			'group_id'    => 'zeko-job-account',
			'group_label' => __( 'Zeko Jobs Account', 'zeko-jobs' ),
			'item_id'     => 'zeko-job-application-' . (int) $app['id'],
			'data'        => $app_data,
		);
	}
	if ( count( $apps ) >= $per_page ) {
		$done = false;
	}

	// Paged standalone profiles: searches, alerts, reminders, reviews posted,
	// ratings given, notifications, templates, billing, follows, views.
	$specs = array(
		array(
			'table' => $tables['searches'],
			'where' => 'user_id = %d',
			'label' => __( 'Saved search', 'zeko-jobs' ),
			'cols'  => array( 'name', 'search_term', 'location', 'job_type', 'last_sent', 'created_at' ),
		),
		array(
			'table' => $tables['alerts'],
			'where' => 'user_id = %d',
			'label' => __( 'Job alert', 'zeko-jobs' ),
			'cols'  => array( 'name', 'frequency', 'last_sent', 'is_active', 'created_at' ),
		),
		array(
			'table' => $tables['reminders'],
			'where' => 'user_id = %d',
			'label' => __( 'Application reminder', 'zeko-jobs' ),
			'cols'  => array( 'job_id', 'remind_at', 'created_at' ),
		),
		array(
			'table' => $tables['reviews'],
			'where' => 'user_id = %d',
			'label' => __( 'Job review', 'zeko-jobs' ),
			'cols'  => array( 'job_id', 'rating', 'review_text', 'created_at' ),
		),
		array(
			'table' => $tables['ratings'],
			'where' => 'employer_id = %d',
			'label' => __( 'Application rating given', 'zeko-jobs' ),
			'cols'  => array( 'application_id', 'rating', 'note', 'created_at' ),
		),
		array(
			'table' => $tables['notifs'],
			'where' => 'user_id = %d',
			'label' => __( 'Notification', 'zeko-jobs' ),
			'cols'  => array( 'type', 'title', 'message', 'is_read', 'created_at' ),
		),
		array(
			'table' => $tables['templates'],
			'where' => 'user_id = %d',
			'label' => __( 'Message template', 'zeko-jobs' ),
			'cols'  => array( 'title', 'content', 'created_at', 'updated_at' ),
		),
		array(
			'table' => $tables['billing'],
			'where' => 'user_id = %d',
			'label' => __( 'Billing entry', 'zeko-jobs' ),
			'cols'  => array( 'job_id', 'type', 'amount', 'currency', 'status', 'description', 'created_at' ),
		),
		array(
			'table' => $tables['follows'],
			'where' => 'user_id = %d',
			'label' => __( 'Company follow', 'zeko-jobs' ),
			'cols'  => array( 'employer_id', 'created_at' ),
		),
		array(
			'table' => $tables['views'],
			'where' => 'user_id = %d',
			'label' => __( 'Job view', 'zeko-jobs' ),
			'cols'  => array( 'job_id', 'viewed_at' ),
		),
	);

	foreach ( $specs as $spec ) {
		if ( ! zeko_jobs_account_privacy_table_exists( $spec['table'] ) ) {
			continue;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$spec['table']} WHERE {$spec['where']} ORDER BY id ASC LIMIT %d OFFSET %d",
				array_merge( array( $user_id ), array( $per_page, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$row_data = array();
			foreach ( $spec['cols'] as $col ) {
				if ( isset( $row->$col ) ) {
					$row_data[] = array(
						'name'  => $col,
						'value' => (string) $row->$col,
					);
				}
			}
			$data[] = array(
				'group_id'    => 'zeko-job-account',
				'group_label' => __( 'Zeko Jobs Account', 'zeko-jobs' ),
				'item_id'     => 'zeko-job-' . $spec['table'] . '-' . (int) $row->id,
				'data'        => $row_data,
			);
		}
		if ( count( $rows ) >= $per_page ) {
			$done = false;
		}
	}

	return array(
		'data' => $data,
		'done' => $done,
	);
}

/**
 * Erase a user's Zeko Jobs account data.
 * Applications (and their notes/history/ratings) are deleted; interviews the
 * user is party to are deleted; community content (job listings, company
 * profiles, job reviews, interviewer attribution, flag resolution) is
 * de-anonymized to 0; everything else is drained per-user. Called repeatedly
 * until done is true.
 *
 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
 * @param string $email_address User who requested erasure.
 * @param int    $_page page.
 */
function zeko_jobs_account_privacy_erase( string $email_address, int $_page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	global $wpdb;

	$user_id = (int) $user->ID;
	$removed = 0;
	$paged   = 20;

	$tables = array(
		'applications' => $wpdb->prefix . 'zeko_job_applications',
		'jobs'         => $wpdb->prefix . 'zeko_jobs',
		'notes'        => $wpdb->prefix . 'zeko_job_application_notes',
		'history'      => $wpdb->prefix . 'zeko_job_application_history',
		'interviews'   => $wpdb->prefix . 'zeko_job_interviews',
		'reviews'      => $wpdb->prefix . 'zeko_job_reviews',
		'companies'    => $wpdb->prefix . 'zeko_companies',
		'ratings'      => $wpdb->prefix . 'zeko_app_ratings',
		'notifs'       => $wpdb->prefix . 'zeko_job_notifications',
		'reminders'    => $wpdb->prefix . 'zeko_job_reminders',
		'searches'     => $wpdb->prefix . 'zeko_job_searches',
		'alerts'       => $wpdb->prefix . 'zeko_job_alerts',
		'templates'    => $wpdb->prefix . 'zeko_message_templates',
		'billing'      => $wpdb->prefix . 'zeko_job_billing',
		'follows'      => $wpdb->prefix . 'zeko_company_follows',
		'views'        => $wpdb->prefix . 'zeko_job_views_log',
		'opens'        => $wpdb->prefix . 'zeko_job_email_opens',
		'flags'        => $wpdb->prefix . 'zeko_job_flags',
	);

	// Public community content — keep, but detach the user.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	if ( zeko_jobs_account_privacy_table_exists( $tables['jobs'] ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['jobs']} SET employer_id = 0 WHERE employer_id = %d",
				$user_id
			)
		);
	}
	if ( zeko_jobs_account_privacy_table_exists( $tables['companies'] ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['companies']} SET employer_id = 0 WHERE employer_id = %d",
				$user_id
			)
		);
	}
	if ( zeko_jobs_account_privacy_table_exists( $tables['reviews'] ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['reviews']} SET user_id = 0 WHERE user_id = %d",
				$user_id
			)
		);
	}
	if ( zeko_jobs_account_privacy_table_exists( $tables['interviews'] ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['interviews']} SET interviewer_id = 0 WHERE interviewer_id = %d",
				$user_id
			)
		);
	}
	if ( zeko_jobs_account_privacy_table_exists( $tables['flags'] ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['flags']} SET resolved_by = 0 WHERE resolved_by = %d",
				$user_id
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	// Resolve one batch of the user's application ids per pass.
	$app_ids = array();
	if ( zeko_jobs_account_privacy_table_exists( $tables['applications'] ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$tables['applications']} WHERE seeker_id = %d ORDER BY id ASC LIMIT %d",
					$user_id,
					$paged
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	if ( $app_ids ) {
		$placeholders = implode( ', ', array_fill( 0, count( $app_ids ), '%d' ) );
		$args         = $app_ids;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( zeko_jobs_account_privacy_table_exists( $tables['notes'] ) ) {
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['notes']} WHERE application_id IN ({$placeholders}) OR user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					array_merge( $args, array( $user_id ) )
				)
			);
		}
		if ( zeko_jobs_account_privacy_table_exists( $tables['history'] ) ) {
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['history']} WHERE application_id IN ({$placeholders}) OR user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					array_merge( $args, array( $user_id ) )
				)
			);
		}
		if ( zeko_jobs_account_privacy_table_exists( $tables['ratings'] ) ) {
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['ratings']} WHERE application_id IN ({$placeholders}) OR employer_id = %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					array_merge( $args, array( $user_id ) )
				)
			);
		}
		if ( zeko_jobs_account_privacy_table_exists( $tables['opens'] ) ) {
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['opens']} WHERE application_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$args
				)
			);
		}
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['applications']} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Interviews are two-sided records between seeker and employer.
	if ( zeko_jobs_account_privacy_table_exists( $tables['interviews'] ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['interviews']} WHERE seeker_id = %d OR employer_id = %d LIMIT %d",
				$user_id,
				$user_id,
				$paged
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Per-user rows.
	$per_user = array(
		'notifs'    => $tables['notifs'],
		'reminders' => $tables['reminders'],
		'searches'  => $tables['searches'],
		'alerts'    => $tables['alerts'],
		'templates' => $tables['templates'],
		'billing'   => $tables['billing'],
		'follows'   => $tables['follows'],
		'views'     => $tables['views'],
		'flags'     => $tables['flags'],
	);
	foreach ( $per_user as $table ) {
		if ( ! zeko_jobs_account_privacy_table_exists( $table ) ) {
			continue;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d LIMIT %d",
				$user_id,
				$paged
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Ascribed email opens (rows only attributable via recipient_id).
	if ( zeko_jobs_account_privacy_table_exists( $tables['opens'] ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['opens']} WHERE recipient_id = %d LIMIT %d",
				$user_id,
				$paged
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Jobs-specific user meta (saved jobs, bookmarks, profile fields, prefs).
	$meta_exact        = array(
		'zeko_saved_jobs',
		'zeko_bookmarked_jobs',
		'zeko_phone',
		'zeko_skills',
		'zeko_location',
		'zeko_work_experience',
		'zeko_education',
		'zeko_saved_resume_url',
		'zeko_structured_resumes',
		'zeko_notifications',
	);
	$meta_placeholders = array_fill( 0, count( $meta_exact ), '%s' );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$removed += (int) $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND ( meta_key IN (' . implode( ', ', $meta_placeholders ) . ') OR meta_key LIKE %s )',
			array_merge( array( $user_id ), $meta_exact, array( 'zeko_job_%' ) )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	// Done gate: any rows left attributed to this user?
	$remaining = 0;

	if ( zeko_jobs_account_privacy_table_exists( $tables['applications'] ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['applications']} WHERE seeker_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}
	if ( zeko_jobs_account_privacy_table_exists( $tables['interviews'] ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['interviews']} WHERE seeker_id = %d OR employer_id = %d",
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}
	foreach ( $per_user as $table ) {
		if ( ! zeko_jobs_account_privacy_table_exists( $table ) ) {
			continue;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}
	if ( zeko_jobs_account_privacy_table_exists( $tables['opens'] ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['opens']} WHERE recipient_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$messages = array();
	if ( $removed > 0 ) {
		$messages[] = __( 'Your Zeko Jobs applications, interviews, saved searches, alerts, reminders, notifications, message templates, billing history and company follows were removed. Job listings, company profiles, reviews and flag resolutions you contributed were kept but detached from your account.', 'zeko-jobs' );
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => 0,
		'messages'       => $messages,
		'done'           => 0 === $remaining,
	);
}
