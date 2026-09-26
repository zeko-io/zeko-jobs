<?php
/**
 * Database class for Zeko Jobs.
 *
 * Handles table creation, schema migrations, and query helpers.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_DB. */
final class Zeko_Jobs_DB {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;
	/**
	 * Db version.
	 *
	 * @var mixed Db version.
	 */
	private $db_version       = '3.9.0';
	private const CACHE_GROUP = 'zeko_jobs';
	private const CACHE_TTL   = 300; // 5 minutes.

	/**
	 * Sanitize rich-text (Quill) content with the narrow ecosystem
	 * allow-list; falls back to wp_kses_post() when zeko-core is absent.
	 *
	 * @param mixed $html Html.
	 */
	private static function sanitize_rich( $html ): string {
		if ( class_exists( 'Zeko_Core_Sanitize' ) ) {
			return Zeko_Core_Sanitize::rich_text( (string) $html );
		}
		return wp_kses_post( (string) $html );
	}

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Jobs_DB {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Run on plugin activation / admin_init to keep schema current.
	 */
	public function maybe_upgrade(): void {
		$installed = get_option( 'zeko_jobs_db_version', '0.0.0' );
		if ( version_compare( $installed, $this->db_version, '<' ) ) {
			$this->create_tables();
			$this->run_migrations( $installed );
			update_option( 'zeko_jobs_db_version', $this->db_version );
		}
		add_action( 'save_post', array( $this, 'invalidate_job_cache' ) );
	}

	/**
	 * Build a cache key from query parameters.
	 *
	 * @param string $prefix Prefix.
	 * @param array  $params Params.
	 */
	private function cache_key( string $prefix, array $params ): string {
		return $prefix . '_' . md5( wp_json_encode( $params ) );
	}

	/**
	 * Invalidate cached job queries when a job post is saved.
	 */
	public function invalidate_job_cache(): void {
		global $post;
		if ( ! $post || 'zeko_job' !== get_post_type( $post ) ) {
			return;
		}

		$job_id = $post->ID;

		// Invalidate specific job cache.
		wp_cache_delete( 'job_' . $job_id, self::CACHE_GROUP );

		// Invalidate slug cache.
		if ( ! empty( $post->post_name ) ) {
			wp_cache_delete( 'job_slug_' . md5( $post->post_name ), self::CACHE_GROUP );
		}

		// Invalidate aggregate caches that depend on job data.
		wp_cache_delete( 'distinct_locations', self::CACHE_GROUP );
		wp_cache_delete( 'distinct_industries', self::CACHE_GROUP );
		wp_cache_delete( 'distinct_companies', self::CACHE_GROUP );

		// Flush paginated/filtered query caches.
		wp_cache_flush_group( self::CACHE_GROUP );
	}

	/**
	 * Create base tables (idempotent via dbDelta).
	 */
	public function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_jobs = $wpdb->prefix . 'zeko_jobs';
		$sql_jobs   = "CREATE TABLE {$table_jobs} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`employer_id` bigint(20) UNSIGNED NOT NULL,
			`title` varchar(255) NOT NULL,
			`slug` varchar(255) NOT NULL,
			`description` longtext NOT NULL,
			`location` varchar(255) NOT NULL,
			`type` varchar(50) NOT NULL,
			`salary_min` decimal(10,2) DEFAULT '0.00',
			`salary_max` decimal(10,2) DEFAULT '0.00',
			`is_featured` tinyint(1) NOT NULL DEFAULT 0,
			`views` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			`status` varchar(20) NOT NULL,
			`created_at` datetime NOT NULL,
			`updated_at` datetime DEFAULT NULL,
			`expires_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `slug` (`slug`),
			KEY `employer_id` (`employer_id`),
			KEY `status` (`status`),
			KEY `is_featured` (`is_featured`),
			KEY `employer_status` (`employer_id`, `status`),
			KEY `status_created` (`status`, `created_at`),
			KEY `expires_at` (`expires_at`)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_jobs );

		$table_apps = $wpdb->prefix . 'zeko_job_applications';
		$sql_apps   = "CREATE TABLE {$table_apps} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` bigint(20) UNSIGNED NOT NULL,
			`seeker_id` bigint(20) UNSIGNED NOT NULL,
			`resume_url` text NOT NULL,
			`cover_letter` longtext NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'pending',
			`applied_at` datetime NOT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `job_seeker` (`job_id`, `seeker_id`),
			KEY `job_id` (`job_id`),
			KEY `seeker_id` (`seeker_id`),
			KEY `status` (`status`),
			KEY `seeker_status` (`seeker_id`, `status`),
			KEY `job_status` (`job_id`, `status`)
		) {$charset_collate};";

		dbDelta( $sql_apps );

		$table_reminders = $wpdb->prefix . 'zeko_job_reminders';
		$sql_reminders   = "CREATE TABLE {$table_reminders} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) UNSIGNED NOT NULL,
			`job_id` bigint(20) UNSIGNED NOT NULL,
			`remind_at` datetime NOT NULL,
			`sent` tinyint(1) NOT NULL DEFAULT 0,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `user_id` (`user_id`),
			KEY `job_id` (`job_id`),
			KEY `remind_at` (`remind_at`)
		) {$charset_collate};";

		dbDelta( $sql_reminders );

		dbDelta( $this->flags_table_sql() );
	}

	/**
	 * Incremental column additions for sites upgrading from older versions.
	 *
	 * @param string $from_version From version.
	 */
	private function run_migrations( string $from_version ): void {
		global $wpdb;
		$table_jobs      = $wpdb->prefix . 'zeko_jobs';
		$table_apps      = $wpdb->prefix . 'zeko_job_applications';
		$charset_collate = $wpdb->get_charset_collate();

		// Pre-1.1.0: add is_featured + updated_at to jobs, updated_at to applications.
		if ( version_compare( $from_version, '1.1.0', '<' ) ) {
			$this->column_exists_add( $table_jobs, 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `salary_max`' );
			$this->column_exists_add( $table_jobs, 'updated_at', 'DATETIME DEFAULT NULL AFTER `created_at`' );
			$this->column_exists_add( $table_apps, 'updated_at', 'DATETIME DEFAULT NULL AFTER `applied_at`' );
		}

		// Pre-1.2.0: add unique constraint on (job_id, seeker_id) to prevent duplicate applications.
		if ( version_compare( $from_version, '1.2.0', '<' ) ) {
			$index_name = 'job_seeker';
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SHOW INDEX FROM {$table_apps} WHERE Key_name = %s", $index_name )
			);
			if ( ! $exists ) {
				$wpdb->query( "DELETE t1 FROM {$table_apps} t1 INNER JOIN {$table_apps} t2 WHERE t1.id > t2.id AND t1.job_id = t2.job_id AND t1.seeker_id = t2.seeker_id" );
				$wpdb->query( "ALTER TABLE {$table_apps} ADD UNIQUE KEY `job_seeker` (`job_id`, `seeker_id`)" );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}
		}

		// Pre-1.3.0: add views column to jobs table.
		if ( version_compare( $from_version, '1.3.0', '<' ) ) {
			$this->column_exists_add( $table_jobs, 'views', 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_featured`' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Pre-1.4.0: convert legacy `pending` to `awaiting_review`, add notes/history tables.
		if ( version_compare( $from_version, '1.4.0', '<' ) ) {
			$wpdb->query( "UPDATE {$table_apps} SET status = 'awaiting_review' WHERE status = 'pending'" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			$notes_table = $wpdb->prefix . 'zeko_job_application_notes';
			$sql_notes   = "CREATE TABLE {$notes_table} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`application_id` bigint(20) UNSIGNED NOT NULL,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`note` longtext NOT NULL,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `application_id` (`application_id`),
				KEY `user_id` (`user_id`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_notes );

			$history_table = $wpdb->prefix . 'zeko_job_application_history';
			$sql_history   = "CREATE TABLE {$history_table} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`application_id` bigint(20) UNSIGNED NOT NULL,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`from_status` varchar(20) DEFAULT NULL,
				`to_status` varchar(20) NOT NULL,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `application_id` (`application_id`),
				KEY `user_id` (`user_id`)
			) {$charset_collate};";
			dbDelta( $sql_history );
		}

		// Pre-1.5.0: add composite index on jobs (employer_id, status).
		if ( version_compare( $from_version, '1.5.0', '<' ) ) {
			$index_name = $wpdb->prefix . 'zeko_jobs_employer_status';
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SHOW INDEX FROM {$table_jobs} WHERE Key_name = %s", $index_name )
			);
			if ( ! $exists ) {
				$wpdb->query( "ALTER TABLE {$table_jobs} ADD KEY `{$index_name}` (`employer_id`, `status`)" );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}
		}

		// Pre-1.6.0: add application reminders table.
		if ( version_compare( $from_version, '1.6.0', '<' ) ) {
			$table_reminders = $wpdb->prefix . 'zeko_job_reminders';
			$sql_reminders   = "CREATE TABLE {$table_reminders} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`job_id` bigint(20) UNSIGNED NOT NULL,
				`remind_at` datetime NOT NULL,
				`sent` tinyint(1) NOT NULL DEFAULT 0,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `job_id` (`job_id`),
				KEY `remind_at` (`remind_at`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_reminders );
		}

		// Pre-1.7.0: add Indeed-level job detail fields.
		if ( version_compare( $from_version, '1.7.0', '<' ) ) {
			$columns = array(
				'requirements'         => 'LONGTEXT DEFAULT NULL AFTER `description`',
				'benefits'             => 'LONGTEXT DEFAULT NULL AFTER `requirements`',
				'responsibilities'     => 'LONGTEXT DEFAULT NULL AFTER `benefits`',
				'qualifications'       => 'LONGTEXT DEFAULT NULL AFTER `responsibilities`',
				'experience_level'     => 'VARCHAR(50) DEFAULT NULL AFTER `qualifications`',
				'remote_option'        => 'VARCHAR(50) DEFAULT NULL AFTER `experience_level`',
				'application_deadline' => 'DATETIME DEFAULT NULL AFTER `remote_option`',
				'is_easy_apply'        => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `application_deadline`',
				'company_logo_id'      => 'BIGINT(20) UNSIGNED DEFAULT NULL AFTER `is_easy_apply`',
				'company_size'         => 'VARCHAR(100) DEFAULT NULL AFTER `company_logo_id`',
				'company_industry'     => 'VARCHAR(255) DEFAULT NULL AFTER `company_size`',
				'company_about'        => 'LONGTEXT DEFAULT NULL AFTER `company_industry`',
				'company_website'      => 'VARCHAR(255) DEFAULT NULL AFTER `company_about`',
			);

			foreach ( $columns as $column => $definition ) {
				$this->column_exists_add( $table_jobs, $column, $definition );
			}
		}

		// Pre-1.8.0: add saved searches table.
		if ( version_compare( $from_version, '1.8.0', '<' ) ) {
			$table_searches = $wpdb->prefix . 'zeko_job_searches';
			$sql_searches   = "CREATE TABLE {$table_searches} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`name` varchar(255) NOT NULL,
				`search_term` varchar(255) DEFAULT NULL,
				`location` varchar(255) DEFAULT NULL,
				`job_type` varchar(50) DEFAULT NULL,
				`salary_min` int(11) DEFAULT NULL,
				`salary_max` int(11) DEFAULT NULL,
				`email_alerts` tinyint(1) NOT NULL DEFAULT 0,
				`last_sent` datetime DEFAULT NULL,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `email_alerts` (`email_alerts`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_searches );
		}

		// Pre-1.9.0: add job category column.
		if ( version_compare( $from_version, '1.9.0', '<' ) ) {
			$this->column_exists_add( $table_jobs, 'category', 'VARCHAR(100) DEFAULT NULL AFTER `type`' );
		}

		// Pre-2.0.0: add candidate documents table.
		if ( version_compare( $from_version, '2.0.0', '<' ) ) {
			$table_docs = $wpdb->prefix . 'zeko_job_documents';
			$sql_docs   = "CREATE TABLE {$table_docs} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`application_id` bigint(20) UNSIGNED DEFAULT NULL,
				`filename` varchar(255) NOT NULL,
				`file_url` text NOT NULL,
				`file_type` varchar(100) DEFAULT NULL,
				`file_size` bigint(20) UNSIGNED DEFAULT NULL,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `application_id` (`application_id`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_docs );
		}

		// Pre-2.1.0: add interviews table.
		if ( version_compare( $from_version, '2.1.0', '<' ) ) {
			$table_interviews = $wpdb->prefix . 'zeko_job_interviews';
			$sql_interviews   = "CREATE TABLE {$table_interviews} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`application_id` bigint(20) UNSIGNED NOT NULL,
				`employer_id` bigint(20) UNSIGNED NOT NULL,
				`seeker_id` bigint(20) UNSIGNED NOT NULL,
				`scheduled_at` datetime NOT NULL,
				`duration` int(11) NOT NULL DEFAULT 60,
				`location` varchar(255) DEFAULT NULL,
				`type` varchar(50) NOT NULL DEFAULT 'video',
				`notes` text DEFAULT NULL,
				`status` varchar(20) NOT NULL DEFAULT 'scheduled',
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `application_id` (`application_id`),
				KEY `employer_id` (`employer_id`),
				KEY `seeker_id` (`seeker_id`),
				KEY `status_scheduled` (`status`, `scheduled_at`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_interviews );
		}

		// Pre-2.2.0: add lat/lng for map view, company_name, category, industry, application_deadline.
		if ( version_compare( $from_version, '2.2.0', '<' ) ) {
			$this->column_exists_add( $table_jobs, 'latitude', 'DECIMAL(10,7) DEFAULT NULL AFTER `location`' );
			$this->column_exists_add( $table_jobs, 'longitude', 'DECIMAL(10,7) DEFAULT NULL AFTER `latitude`' );
			$this->column_exists_add( $table_jobs, 'company_name', 'VARCHAR(255) DEFAULT NULL AFTER `employer_id`' );
			$this->column_exists_add( $table_jobs, 'category', 'VARCHAR(100) DEFAULT NULL AFTER `type`' );
			$this->column_exists_add( $table_jobs, 'company_industry', 'VARCHAR(100) DEFAULT NULL AFTER `category`' );
			$this->column_exists_add( $table_jobs, 'application_deadline', 'DATETIME DEFAULT NULL AFTER `expires_at`' );

			$reviews_table = $wpdb->prefix . 'zeko_job_reviews';
			$sql_reviews   = "CREATE TABLE {$reviews_table} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`job_id` bigint(20) UNSIGNED NOT NULL,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`rating` tinyint(1) UNSIGNED NOT NULL,
				`review_text` text DEFAULT NULL,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `job_id` (`job_id`),
				KEY `user_id` (`user_id`),
				UNIQUE KEY `job_user` (`job_id`, `user_id`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_reviews );
		}

		// Pre-2.3.0: add performance indexes on applications table.
		if ( version_compare( $from_version, '2.3.0', '<' ) ) {
			$this->add_index_if_missing( $table_apps, 'seeker_status', '`seeker_id`, `status`' );
			$this->add_index_if_missing( $table_apps, 'job_status', '`job_id`, `status`' );
		}

		// Pre-2.4.0: add companies table.
		if ( version_compare( $from_version, '2.4.0', '<' ) ) {
			$table_companies = $wpdb->prefix . 'zeko_companies';
			$sql_companies   = "CREATE TABLE {$table_companies} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`employer_id` bigint(20) UNSIGNED NOT NULL,
				`name` varchar(255) NOT NULL,
				`slug` varchar(255) NOT NULL,
				`logo_url` text DEFAULT NULL,
				`cover_url` text DEFAULT NULL,
				`description` longtext DEFAULT NULL,
				`industry` varchar(255) DEFAULT NULL,
				`size` varchar(100) DEFAULT NULL,
				`founded_year` year DEFAULT NULL,
				`website` varchar(255) DEFAULT NULL,
				`location` varchar(255) DEFAULT NULL,
				`linkedin` varchar(255) DEFAULT NULL,
				`twitter` varchar(255) DEFAULT NULL,
				`is_verified` tinyint(1) NOT NULL DEFAULT 0,
				`is_featured` tinyint(1) NOT NULL DEFAULT 0,
				`created_at` datetime NOT NULL,
				`updated_at` datetime DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `slug` (`slug`),
				KEY `employer_id` (`employer_id`),
				KEY `industry` (`industry`),
				KEY `is_featured` (`is_featured`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_companies );
		}

		// Pre-2.5.0: add application ratings/notes table.
		if ( version_compare( $from_version, '2.5.0', '<' ) ) {
			$table_ratings = $wpdb->prefix . 'zeko_app_ratings';
			$sql_ratings   = "CREATE TABLE {$table_ratings} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`application_id` bigint(20) UNSIGNED NOT NULL,
				`employer_id` bigint(20) UNSIGNED NOT NULL,
				`rating` tinyint(1) NOT NULL DEFAULT 0,
				`note` longtext DEFAULT NULL,
				`created_at` datetime NOT NULL,
				`updated_at` datetime DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `app_employer` (`application_id`, `employer_id`),
				KEY `employer_id` (`employer_id`),
				KEY `rating` (`rating`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_ratings );
		}

		// Pre-2.6.0: add job views log table for per-job analytics + time series.
		if ( version_compare( $from_version, '2.6.0', '<' ) ) {
			$table_views_log = $wpdb->prefix . 'zeko_job_views_log';
			$sql_views_log   = "CREATE TABLE {$table_views_log} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`job_id` bigint(20) UNSIGNED NOT NULL,
				`user_id` bigint(20) UNSIGNED DEFAULT NULL,
				`visitor_ip` varchar(45) DEFAULT NULL,
				`user_agent` varchar(512) DEFAULT NULL,
				`referer` varchar(512) DEFAULT NULL,
				`viewed_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `job_id` (`job_id`),
				KEY `viewed_at` (`viewed_at`),
				KEY `job_viewed` (`job_id`, `viewed_at`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_views_log );

			// Add label column to documents table for resume naming.
			$this->column_exists_add( $wpdb->prefix . 'zeko_job_documents', 'label', 'VARCHAR(255) DEFAULT NULL AFTER `filename`' );
		}

		// Pre-2.7.0: add job alerts table.
		if ( version_compare( $from_version, '2.7.0', '<' ) ) {
			$table_alerts = $wpdb->prefix . 'zeko_job_alerts';
			$sql_alerts   = "CREATE TABLE {$table_alerts} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`name` varchar(255) NOT NULL DEFAULT '',
				`search_params` longtext DEFAULT NULL,
				`frequency` varchar(20) NOT NULL DEFAULT 'daily',
				`last_sent` datetime DEFAULT NULL,
				`is_active` tinyint(1) NOT NULL DEFAULT 1,
				`created_at` datetime NOT NULL,
				`updated_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `is_active_frequency` (`is_active`, `frequency`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_alerts );

			// Add viewed_at column to applications table.
			$this->column_exists_add( $wpdb->prefix . 'zeko_job_applications', 'viewed_at', 'datetime DEFAULT NULL AFTER `updated_at`' );
		}

		// Pre-2.7.1: add interviewer_id to interviews table + email opens table.
		if ( version_compare( $from_version, '2.7.1', '<' ) ) {
			$this->column_exists_add( $wpdb->prefix . 'zeko_job_interviews', 'interviewer_id', 'bigint(20) UNSIGNED DEFAULT NULL AFTER `employer_id`' );

			$table_email_opens = $wpdb->prefix . 'zeko_job_email_opens';
			$sql_email_opens   = "CREATE TABLE {$table_email_opens} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`tracking_id` varchar(64) NOT NULL,
				`application_id` bigint(20) UNSIGNED DEFAULT NULL,
				`recipient_id` bigint(20) UNSIGNED DEFAULT NULL,
				`email_type` varchar(50) DEFAULT NULL,
				`opened_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `tracking_id` (`tracking_id`),
				KEY `application_id` (`application_id`),
				KEY `recipient_id` (`recipient_id`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_email_opens );
		}

		// Pre-2.8.0: add rejection_reason and verified_employer columns for job approval workflow.
		if ( version_compare( $from_version, '2.8.0', '<' ) ) {
			$this->column_exists_add( $table_jobs, 'rejection_reason', 'TEXT DEFAULT NULL AFTER `status`' );
			$this->column_exists_add( $table_jobs, 'verified_employer', 'TINYINT(1) DEFAULT 0 AFTER `rejection_reason`' );
		}

		// Pre-2.9.0: notifications table — migrate from user_meta to proper table.
		if ( version_compare( $from_version, '2.9.0', '<' ) ) {
			$table_notifs = $wpdb->prefix . 'zeko_job_notifications';
			$sql_notifs   = "CREATE TABLE {$table_notifs} (
				`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) UNSIGNED NOT NULL,
				`type` varchar(50) NOT NULL DEFAULT 'general',
				`title` varchar(255) NOT NULL,
				`message` text NOT NULL,
				`link` varchar(500) DEFAULT NULL,
				`icon` varchar(50) DEFAULT 'dashicons-info',
				`meta` longtext DEFAULT NULL,
				`is_read` tinyint(1) NOT NULL DEFAULT 0,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `user_read` (`user_id`, `is_read`),
				KEY `type` (`type`),
				KEY `created_at` (`created_at`)
			) {$charset_collate};";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_notifs );

			// Migrate existing user_meta notifications to the new table.
			$this->migrate_notifications_from_meta();
		}

		// Pre-3.0.0: messaging tables.
		// Canonical owner is zeko-core ("messaging" module, class-zeko-app-schema.php).
		// when present; this block is the standalone-fallback so the schema is.
		// created exactly once and can no longer drift.
		if ( version_compare( $from_version, '3.0.0', '<' ) && ! class_exists( 'Zeko_Core_DB' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$table_msgs = $wpdb->prefix . 'zeko_messages';
			$sql_msgs   = "CREATE TABLE {$table_msgs} (
				`message_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`conversation_id` bigint(20) UNSIGNED NOT NULL,
				`sender_id` bigint(20) UNSIGNED NOT NULL,
				`recipient_id` bigint(20) UNSIGNED NOT NULL,
				`message_content` longtext NOT NULL,
				`message_status` varchar(20) DEFAULT 'sent',
				`message_date` datetime NOT NULL,
				`is_read` tinyint(1) DEFAULT 0,
				PRIMARY KEY (`message_id`),
				KEY `conversation_id` (`conversation_id`),
				KEY `sender_id` (`sender_id`),
				KEY `recipient_id` (`recipient_id`),
				KEY `conv_read` (`conversation_id`, `is_read`)
			) {$charset_collate};";
			dbDelta( $sql_msgs );

			$table_conv = $wpdb->prefix . 'zeko_conversations';
			$sql_conv   = "CREATE TABLE {$table_conv} (
				`conversation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user1_id` bigint(20) UNSIGNED NOT NULL,
				`user2_id` bigint(20) UNSIGNED NOT NULL,
				`last_message_id` bigint(20) UNSIGNED DEFAULT NULL,
				`last_message_date` datetime DEFAULT NULL,
				`unread_count_user1` int(11) DEFAULT 0,
				`unread_count_user2` int(11) DEFAULT 0,
				`status` varchar(20) DEFAULT 'active',
				PRIMARY KEY (`conversation_id`),
				KEY `user1_id` (`user1_id`),
				KEY `user2_id` (`user2_id`),
				KEY `active_users` (`status`, `user1_id`, `user2_id`)
			) {$charset_collate};";
			dbDelta( $sql_conv );

			$table_meta = $wpdb->prefix . 'zeko_message_meta';
			$sql_meta   = "CREATE TABLE {$table_meta} (
				`meta_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`message_id` bigint(20) UNSIGNED NOT NULL,
				`meta_key` varchar(255) DEFAULT NULL,
				`meta_value` longtext DEFAULT NULL,
				PRIMARY KEY (`meta_id`),
				KEY `message_id` (`message_id`),
				KEY `meta_key` (`meta_key`)
			) {$charset_collate};";
			dbDelta( $sql_meta );
		}

		// Billing table — tracks ZekoPay charges made from the Jobs module.
		$table_billing = $wpdb->prefix . 'zeko_job_billing';
		$sql_billing   = "CREATE TABLE {$table_billing} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) UNSIGNED NOT NULL,
			`job_id` bigint(20) UNSIGNED DEFAULT NULL,
			`type` varchar(50) NOT NULL COMMENT 'posting_fee, boost_fee, listing_pack',
			`amount` decimal(10,2) NOT NULL DEFAULT '0.00',
			`currency` varchar(10) DEFAULT 'USD',
			`zeko_pay_tx_id` bigint(20) UNSIGNED DEFAULT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'completed' COMMENT 'completed, failed, refunded',
			`description` varchar(255) DEFAULT NULL,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `user_id` (`user_id`),
			KEY `job_id` (`job_id`),
			KEY `type_status` (`type`, `status`),
			KEY `created_at` (`created_at`)
		) {$charset_collate};";
		dbDelta( $sql_billing );

		// Pre-3.3.0: add billing table.
		// (handled above via dbDelta — idempotent).

		// Pre-3.1.0: add proposed_times + accepted_time to interviews table.
		if ( version_compare( $from_version, '3.1.0', '<' ) ) {
			$table_interviews = $wpdb->prefix . 'zeko_job_interviews';
			$this->column_exists_add( $table_interviews, 'proposed_times', 'TEXT DEFAULT NULL AFTER `notes`' );
			$this->column_exists_add( $table_interviews, 'accepted_time', 'DATETIME DEFAULT NULL AFTER `proposed_times`' );
		}

		// Pre-3.2.0: add meeting_url to interviews table.
		if ( version_compare( $from_version, '3.2.0', '<' ) ) {
			$table_interviews = $wpdb->prefix . 'zeko_job_interviews';
			$this->column_exists_add( $table_interviews, 'meeting_url', 'VARCHAR(500) DEFAULT NULL AFTER `location`' );
		}

		// Pre-3.4.0: featured companies (premium employer highlights on homepage).
		if ( version_compare( $from_version, '3.4.0', '<' ) ) {
			$this->column_exists_add( $wpdb->prefix . 'zeko_companies', 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_verified`' );
		}

		// Pre-3.5.0: job flags table (content moderation queue).
		// (created idempotently via create_tables()/dbDelta above).
		if ( version_compare( $from_version, '3.5.0', '<' ) ) {
			$table_flags = $wpdb->prefix . 'zeko_job_flags';
			$exists      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_flags ) );
			if ( ! $exists ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $this->flags_table_sql() );
			}
		}

		// Pre-3.6.0: company page customization (accent color, video embed).
		// + company follows table (seekers follow companies for new-job alerts).
		if ( version_compare( $from_version, '3.6.0', '<' ) ) {
			$table_companies = $wpdb->prefix . 'zeko_companies';
			$this->column_exists_add( $table_companies, 'accent_color', 'VARCHAR(20) DEFAULT NULL AFTER `is_featured`' );
			$this->column_exists_add( $table_companies, 'video_url', 'VARCHAR(500) DEFAULT NULL AFTER `accent_color`' );

			$table_follows = $wpdb->prefix . 'zeko_company_follows';
			$exists        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_follows ) );
			if ( ! $exists ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $this->company_follows_table_sql() );
			}
		}

		// Pre-3.7.0: multi-company + required skills + payment status on jobs,.
		// employer blog table, and message templates table.
		if ( version_compare( $from_version, '3.7.0', '<' ) ) {
			$table_jobs = $wpdb->prefix . 'zeko_jobs';
			$this->column_exists_add( $table_jobs, 'company_id', 'BIGINT(20) UNSIGNED DEFAULT NULL AFTER `employer_id`' );
			$this->column_exists_add( $table_jobs, 'required_skills', 'TEXT DEFAULT NULL AFTER `is_easy_apply`' );
			$this->column_exists_add( $table_jobs, 'payment_status', "VARCHAR(20) NOT NULL DEFAULT 'free' AFTER `required_skills`" );

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $this->company_posts_table_sql() );
			dbDelta( $this->message_templates_table_sql() );
		}

		// Pre-3.8.0: normalize messaging tables (UNSIGNED + composite indexes).
		if ( version_compare( $from_version, '3.8.0', '<' ) ) {
			$this->normalize_messaging_schema();
		}

		// Pre-3.9.0: hot-path indexes for jobs/applications/interviews.
		if ( version_compare( $from_version, '3.9.0', '<' ) ) {
			$this->add_index_if_missing( $table_jobs, 'category', '`category`' );
			$this->add_index_if_missing( $table_jobs, 'status_created', '`status`, `created_at`' );
			$this->add_index_if_missing( $table_jobs, 'expires_at', '`expires_at`' );
			$this->add_index_if_missing( $table_apps, 'seeker_status', '`seeker_id`, `status`' );
			$this->add_index_if_missing( $table_apps, 'job_status', '`job_id`, `status`' );
			$this->add_index_if_missing( $wpdb->prefix . 'zeko_job_interviews', 'status_scheduled', '`status`, `scheduled_at`' );
		}
	}

	/**
	 * Build the employer blog (company posts) table DDL.
	 */
	private function company_posts_table_sql(): string {
		global $wpdb;
		$table           = $wpdb->prefix . 'zeko_company_posts';
		$charset_collate = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`company_id` bigint(20) UNSIGNED NOT NULL,
			`employer_id` bigint(20) UNSIGNED NOT NULL,
			`title` varchar(255) NOT NULL,
			`slug` varchar(255) NOT NULL,
			`content` longtext NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'publish',
			`created_at` datetime NOT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `slug` (`slug`),
			KEY `company_id` (`company_id`),
			KEY `employer_id` (`employer_id`),
			KEY `status` (`status`)
		) {$charset_collate};";
	}

	/**
	 * Build the message templates table DDL.
	 */
	private function message_templates_table_sql(): string {
		global $wpdb;
		$table           = $wpdb->prefix . 'zeko_message_templates';
		$charset_collate = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) UNSIGNED NOT NULL,
			`title` varchar(255) NOT NULL,
			`content` longtext NOT NULL,
			`created_at` datetime NOT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `user_id` (`user_id`)
		) {$charset_collate};";
	}

	/**
	 * Build the company follows table DDL (shared by create_tables and migrations).
	 */
	private function company_follows_table_sql(): string {
		global $wpdb;
		$table           = $wpdb->prefix . 'zeko_company_follows';
		$charset_collate = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) UNSIGNED NOT NULL,
			`employer_id` bigint(20) UNSIGNED NOT NULL,
			`created_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `user_company` (`user_id`, `employer_id`),
			KEY `employer_id` (`employer_id`)
		) {$charset_collate};";
	}

	/**
	 * Build the job flags table DDL (shared by create_tables and migrations).
	 */
	private function flags_table_sql(): string {
		global $wpdb;
		$table           = $wpdb->prefix . 'zeko_job_flags';
		$charset_collate = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` bigint(20) UNSIGNED NOT NULL,
			`user_id` bigint(20) UNSIGNED NOT NULL,
			`reason` varchar(100) NOT NULL,
			`details` text DEFAULT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, resolved, dismissed',
			`created_at` datetime NOT NULL,
			`resolved_by` bigint(20) UNSIGNED DEFAULT NULL,
			`resolved_at` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `job_id` (`job_id`),
			KEY `user_id` (`user_id`),
			KEY `status` (`status`),
			KEY `job_status` (`job_id`, `status`)
		) {$charset_collate};";
	}

	/**
	 * Add a column if it doesn't already exist.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param string $definition Definition.
	 */
	private function column_exists_add( string $table, string $column, string $definition ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$check = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		if ( ! $check ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Add an index if it doesn't already exist.
	 *
	 * @param string $table Table.
	 * @param string $index_name Index name.
	 * @param string $columns Columns.
	 */
	private function add_index_if_missing( string $table, string $index_name, string $columns ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name )
		);
		if ( ! $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY `{$index_name}` ({$columns})" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Normalize messaging tables: ensure UNSIGNED PKs/FKs and composite indexes.
	 * Sites where the theme created the tables before jobs was activated may have
	 * non-UNSIGNED bigint columns and missing composite indexes. This migration
	 * brings them up to the canonical schema.
	 */
	private function normalize_messaging_schema(): void {
		global $wpdb;

		$messages      = $wpdb->prefix . 'zeko_messages';
		$conversations = $wpdb->prefix . 'zeko_conversations';
		$meta          = $wpdb->prefix . 'zeko_message_meta';

		// Helper: check if a column is UNSIGNED.
		$is_unsigned = function ( string $table, string $column ): bool {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} WHERE Field = %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( ! $row ) {
				return false;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Type is the native column alias of SHOW COLUMNS results and cannot be renamed.
			return false !== strpos( $row->Type, 'unsigned' );
		};

		// Helper: modify a column to UNSIGNED if it isn't already.
		$make_unsigned = function ( string $table, string $column, string $type ) use ( $is_unsigned, $wpdb ): void {
			if ( $is_unsigned( $table, $column ) ) {
				return;
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN `{$column}` {$type} UNSIGNED NOT NULL" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		};

		// zeko_messages: convert bigint ID/FK columns to UNSIGNED.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $messages ) ) === $messages ) {
			$make_unsigned( $messages, 'message_id', 'bigint(20)' );
			$make_unsigned( $messages, 'conversation_id', 'bigint(20)' );
			$make_unsigned( $messages, 'sender_id', 'bigint(20)' );
			$make_unsigned( $messages, 'recipient_id', 'bigint(20)' );
			$this->add_index_if_missing( $messages, 'conv_read', '`conversation_id`, `is_read`' );
		}

		// zeko_conversations: convert bigint ID/FK columns to UNSIGNED.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conversations ) ) === $conversations ) {
			$make_unsigned( $conversations, 'conversation_id', 'bigint(20)' );
			$make_unsigned( $conversations, 'user1_id', 'bigint(20)' );
			$make_unsigned( $conversations, 'user2_id', 'bigint(20)' );
			$make_unsigned( $conversations, 'last_message_id', 'bigint(20)' );
			$this->add_index_if_missing( $conversations, 'active_users', '`status`, `user1_id`, `user2_id`' );
		}

		// zeko_message_meta: convert meta_id to UNSIGNED.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta ) ) === $meta ) {
			$make_unsigned( $meta, 'meta_id', 'bigint(20)' );
			$make_unsigned( $meta, 'message_id', 'bigint(20)' );
		}
	}

	/**
	 * Get job count.
	 */
	public function get_job_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'publish'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $count ? intval( $count ) : 0;
	}

	/**
	 * Get recent jobs.
	 *
	 * @param int $limit Limit.
	 */
	public function get_recent_jobs( int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT title, location FROM {$table} WHERE status = 'publish' ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Toggle featured status for a job.
	 *
	 * @param int $job_id Job id.
	 * @param int $employer_id Employer id.
	 */
	public function toggle_featured( int $job_id, int $employer_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$current = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT is_featured FROM {$table} WHERE id = %d AND employer_id = %d", $job_id, $employer_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( is_null( $current ) ) {
			return false;
		}
		$new_val = $current ? 0 : 1;
		$wpdb->update( $table, array( 'is_featured' => $new_val ), array( 'id' => $job_id ), array( '%d' ), array( '%d' ) );
		return (bool) $new_val;
	}

	/**
	 * Update job status.
	 *
	 * @param int    $job_id Job id.
	 * @param int    $employer_id Employer id.
	 * @param string $new_status New status.
	 */
	public function update_job_status( int $job_id, int $employer_id, string $new_status ): bool {
		global $wpdb;
		$table   = $wpdb->prefix . 'zeko_jobs';
		$allowed = array( 'draft', 'publish', 'paused', 'closed', 'expired', 'pending' );
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return false;
		}

		$result = $wpdb->update(
			$table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'          => $job_id,
				'employer_id' => $employer_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Get all jobs with status='pending' for admin review.
	 */
	public function get_pending_jobs(): array {
		global $wpdb;
		$table       = $wpdb->prefix . 'zeko_jobs';
		$users_table = $wpdb->users;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			"SELECT j.*, u.display_name AS employer_name
			 FROM {$table} j
			 LEFT JOIN {$users_table} u ON u.ID = j.employer_id
			 WHERE j.status = 'pending'
			 ORDER BY j.created_at DESC",
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Approve a pending job (set status to 'publish').
	 *
	 * @param int $job_id Job id.
	 */
	public function approve_job( int $job_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_jobs';
		$result = $wpdb->update(
			$table,
			array(
				'status'     => 'publish',
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'     => $job_id,
				'status' => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		return (bool) $result;
	}

	/**
	 * Reject a pending job (set status to 'draft' and store rejection reason).
	 *
	 * @param int    $job_id Job id.
	 * @param string $reason Reason.
	 */
	public function reject_job( int $job_id, string $reason = '' ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_jobs';
		$result = $wpdb->update(
			$table,
			array(
				'status'           => 'draft',
				'rejection_reason' => sanitize_textarea_field( $reason ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array(
				'id'     => $job_id,
				'status' => 'pending',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return (bool) $result;
	}

	/**
	 * Bulk update job statuses.
	 *
	 * @param array  $job_ids Job ids.
	 * @param string $status Status.
	 */
	public function bulk_update_job_status( array $job_ids, string $status ): int {
		global $wpdb;
		$table   = $wpdb->prefix . 'zeko_jobs';
		$count   = 0;
		$allowed = array( 'publish', 'draft', 'pending', 'paused', 'closed', 'expired' );

		if ( ! in_array( $status, $allowed, true ) || empty( $job_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$result = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
				array_merge( array( $status, current_time( 'mysql' ) ), $job_ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return $result ? (int) $result : 0;
	}

	/**
	 * Renew a job by extending its expiration date.
	 *
	 * @param int $job_id Job id.
	 * @param int $employer_id Employer id.
	 * @param int $days Days.
	 */
	public function renew_job( int $job_id, int $employer_id, int $days = 30 ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, expires_at FROM {$table} WHERE id = %d AND employer_id = %d",
				$job_id,
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( ! $job ) {
			return false;
		}

		$base_date = ( $job->expires_at && strtotime( $job->expires_at ) > time() )
			? $job->expires_at
			: current_time( 'mysql' );

		$new_expires = gmdate( 'Y-m-d H:i:s', strtotime( $base_date . " +{$days} days" ) );
		$new_status  = ( 'expired' === $job->status ) ? 'publish' : $job->status;

		$update_data = array(
			'expires_at' => $new_expires,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( 'expired' === $job->status ) {
			$update_data['status'] = $new_status;
		}

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return (bool) $result;
	}

	/**
	 * Get the number of applications a user has submitted today.
	 *
	 * @param int $user_id User id.
	 */
	public function get_user_daily_application_count( int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		$today = current_time( 'Y-m-d' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE seeker_id = %d AND applied_at >= %s",
				$user_id,
				$today
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Calculate job completeness score as a percentage.
	 *
	 * @param array $job Job.
	 */
	public function calculate_completeness( array $job ): int {
		$fields = array(
			'title',
			'description',
			'location',
			'type',
			'category',
			'experience_level',
			'remote_option',
			'salary_min',
			'salary_max',
			'requirements',
			'benefits',
			'responsibilities',
			'qualifications',
			'company_industry',
			'company_size',
			'company_about',
			'application_deadline',
		);

		$filled = 0;
		$total  = count( $fields );

		foreach ( $fields as $field ) {
			if ( isset( $job[ $field ] ) && '' !== $job[ $field ] && null !== $job[ $field ] ) {
				if ( in_array( $field, array( 'salary_min', 'salary_max' ), true ) ) {
					if ( (float) $job[ $field ] > 0 ) {
						++$filled;
					}
				} else {
					++$filled;
				}
			}
		}

		return (int) round( ( $filled / $total ) * 100 );
	}

	/**
	 * Check if an employer is verified (has confirmed email and company profile).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function is_employer_verified( int $employer_id ): bool {
		$cache_key = 'emp_verified_' . $employer_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$user = get_userdata( $employer_id );
		if ( ! $user ) {
			wp_cache_set( $cache_key, false, self::CACHE_GROUP, self::CACHE_TTL );
			return false;
		}

		$company = $this->get_company_by_employer( $employer_id );
		if ( $company && (bool) $company['is_verified'] ) {
			wp_cache_set( $cache_key, true, self::CACHE_GROUP, self::CACHE_TTL );
			return true;
		}

		$email_verified = (bool) get_user_meta( $employer_id, 'email_verified', true );
		$has_company    = ! empty( $company );
		$result         = $email_verified && $has_company;

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Calculate seeker profile completeness (0-100).
	 *
	 * @param int $user_id User id.
	 */
	public function calculate_seeker_profile_completeness( int $user_id ): array {
		$user   = get_userdata( $user_id );
		$fields = array();

		$fields['name']       = array(
			'label'   => __( 'Full Name', 'zeko-jobs' ),
			'filled'  => ! empty( $user->display_name ),
			'suggest' => __( 'Add your display name', 'zeko-jobs' ),
		);
		$fields['email']      = array(
			'label'   => __( 'Email', 'zeko-jobs' ),
			'filled'  => ! empty( $user->user_email ),
			'suggest' => __( 'Add your email address', 'zeko-jobs' ),
		);
		$fields['location']   = array(
			'label'   => __( 'Location', 'zeko-jobs' ),
			'filled'  => ! empty( get_user_meta( $user_id, 'zeko_location', true ) ),
			'suggest' => __( 'Add your city or location', 'zeko-jobs' ),
		);
		$fields['phone']      = array(
			'label'   => __( 'Phone', 'zeko-jobs' ),
			'filled'  => ! empty( get_user_meta( $user_id, 'zeko_phone', true ) ),
			'suggest' => __( 'Add your phone number', 'zeko-jobs' ),
		);
		$fields['resume']     = array(
			'label'   => __( 'Resume', 'zeko-jobs' ),
			'filled'  => ! empty( get_user_meta( $user_id, 'zeko_saved_resume_url', true ) ),
			'suggest' => __( 'Upload your resume', 'zeko-jobs' ),
		);
		$fields['experience'] = array(
			'label'   => __( 'Work Experience', 'zeko-jobs' ),
			'filled'  => ! empty( get_user_meta( $user_id, 'zeko_work_experience', true ) ),
			'suggest' => __( 'Add your work experience', 'zeko-jobs' ),
		);
		$fields['education']  = array(
			'label'   => __( 'Education', 'zeko-jobs' ),
			'filled'  => ! empty( get_user_meta( $user_id, 'zeko_education', true ) ),
			'suggest' => __( 'Add your education', 'zeko-jobs' ),
		);
		$fields['skills']     = array(
			'label'   => __( 'Skills', 'zeko-jobs' ),
			'filled'  => ! empty( get_user_meta( $user_id, 'zeko_skills', true ) ),
			'suggest' => __( 'Add your skills', 'zeko-jobs' ),
		);

		$total   = count( $fields );
		$filled  = 0;
		$suggest = array();
		foreach ( $fields as $f ) {
			if ( $f['filled'] ) {
				++$filled;
			} else {
				$suggest[] = $f['suggest'];
			}
		}

		return array(
			'percent' => $total > 0 ? (int) round( ( $filled / $total ) * 100 ) : 0,
			'fields'  => $fields,
			'suggest' => $suggest,
			'filled'  => $filled,
			'total'   => $total,
		);
	}

	/**
	 * Update application status.
	 *
	 * @param int    $application_id Application id.
	 * @param string $new_status New status.
	 * @param int    $employer_id Employer id.
	 */
	public function update_application_status( int $application_id, string $new_status, int $employer_id ): bool {
		global $wpdb;
		$table_apps = $wpdb->prefix . 'zeko_job_applications';
		$table_jobs = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT app.id, app.status FROM {$table_apps} app
				 JOIN {$table_jobs} job ON job.id = app.job_id
				 WHERE app.id = %d AND job.employer_id = %d",
				$application_id,
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! $app ) {
			return false;
		}
		$allowed = array( 'awaiting_review', 'reviewed', 'contacting', 'interviewing', 'offered', 'hired', 'archived', 'rejected', 'withdrawn' );
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return false;
		}
		$result = $wpdb->update(
			$table_apps,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $application_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( $result && $app->status !== $new_status ) {
			$this->log_status_history( $application_id, $employer_id, $app->status, $new_status );
			// Email notifications are now handled by Zeko_Jobs_Email via the.
			// 'zeko_job_application_status_changed' action hook.
		}
		return (bool) $result;
	}

	/**
	 * Send email notification on status change if enabled.
	 *
	 * @param int    $application_id Application id.
	 * @param string $from_status From status.
	 * @param string $to_status To status.
	 */
	private function maybe_send_status_email( int $application_id, string $from_status, string $to_status ): void {
		$send_emails = get_option( 'zeko_jobs_send_status_emails', 0 );
		if ( ! $send_emails ) {
			return;
		}

		$app = $this->get_application( $application_id );
		if ( ! $app ) {
			return;
		}

		$seeker = get_userdata( (int) $app['seeker_id'] );
		if ( ! $seeker || empty( $seeker->user_email ) ) {
			return;
		}

		if ( 'demo.com' === strtolower( (string) wp_parse_url( $seeker->user_email, PHP_URL_HOST ) ) || get_user_meta( (int) $seeker->ID, 'zeko_demo_user', true ) ) {
			return;
		}

		$job = get_post( (int) $app['job_id'] );
		if ( ! $job ) {
			return;
		}

		$subject = sprintf( 'Application status updated for "%s"', $job->post_title );
		$message = sprintf(
			"Hi %s,\n\nYour application for \"%s\" has been updated to: %s.\n\nPrevious status: %s\n\nBest regards,\n%s",
			$seeker->display_name,
			$job->post_title,
			ucfirst( $to_status ),
			ucfirst( $from_status ),
			get_bloginfo( 'name' )
		);

		wp_mail( $seeker->user_email, $subject, $message );
	}

	/**
	 * Get a single application row.
	 *
	 * @param int $application_id Application id.
	 */
	public function get_application( int $application_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $application_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $app ?: null;
	}

	/**
	 * Withdraw an application (seeker action).
	 *
	 * @param int $application_id Application id.
	 * @param int $seeker_id Seeker id.
	 */
	public function withdraw_application( int $application_id, int $seeker_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND seeker_id = %d", $application_id, $seeker_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! $app ) {
			return false;
		}
		$wpdb->update(
			$table,
			array(
				'status'     => 'withdrawn',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $application_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Check if a seeker has already applied to a job.
	 *
	 * @param int $job_id Job id.
	 * @param int $seeker_id Seeker id.
	 */
	public function has_applied( int $job_id, int $seeker_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE job_id = %d AND seeker_id = %d AND status != 'withdrawn'",
				$job_id,
				$seeker_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $count ? (int) $count > 0 : false;
	}

	/**
	 * Log a status transition to the history table.
	 *
	 * @param int     $application_id Application id.
	 * @param int     $user_id User id.
	 * @param ?string $from_status From status.
	 * @param string  $to_status To status.
	 */
	public function log_status_history( int $application_id, int $user_id, ?string $from_status, string $to_status ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_application_history';
		$wpdb->insert(
			$table,
			array(
				'application_id' => $application_id,
				'user_id'        => $user_id,
				'from_status'    => $from_status,
				'to_status'      => $to_status,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get status history for an application.
	 *
	 * @param int $application_id Application id.
	 */
	public function get_status_history( int $application_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_application_history';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.*, u.display_name AS user_name
				 FROM {$table} h
				 LEFT JOIN {$wpdb->users} u ON u.ID = h.user_id
				 WHERE h.application_id = %d
				 ORDER BY h.created_at ASC",
				$application_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Log that an employer viewed an application.
	 *
	 * @param int $application_id Application id.
	 * @param int $employer_id Employer id.
	 */
	public function log_application_view( int $application_id, int $employer_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';

		// Only log once per employer per application per day.
		$last_view = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$wpdb->prefix}zeko_job_application_notes WHERE application_id = %d AND user_id = %d AND note = '__view__'",
				$application_id,
				$employer_id
			)
		);

		$today = current_time( 'Y-m-d' );
		if ( $last_view && gmdate( 'Y-m-d', strtotime( $last_view ) ) === $today ) {
			return;
		}

		// Insert a sentinel note.
		$notes_table = $wpdb->prefix . 'zeko_job_application_notes';
		$wpdb->insert(
			$notes_table,
			array(
				'application_id' => $application_id,
				'user_id'        => $employer_id,
				'note'           => '__view__',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		// Update the viewed_at timestamp on the application.
		$wpdb->update(
			$table,
			array( 'viewed_at' => current_time( 'mysql' ) ),
			array( 'id' => $application_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get application response stats for a seeker.
	 * Returns: total, responded (non-pending), response_rate (percent)
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function get_seeker_response_stats( int $seeker_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE seeker_id = %d", $seeker_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$responded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE seeker_id = %d AND status NOT IN ('pending', 'awaiting_review')",
				$seeker_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$rate = $total > 0 ? round( ( $responded / $total ) * 100 ) : 0;

		return array(
			'total'         => $total,
			'responded'     => $responded,
			'response_rate' => $rate,
		);
	}

	/**
	 * Add a note to an application.
	 *
	 * @param int    $application_id Application id.
	 * @param int    $user_id User id.
	 * @param string $note Note.
	 */
	public function add_application_note( int $application_id, int $user_id, string $note ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_application_notes';
		$result = $wpdb->insert(
			$table,
			array(
				'application_id' => $application_id,
				'user_id'        => $user_id,
				'note'           => sanitize_textarea_field( $note ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return (bool) $result;
	}

	/**
	 * Get notes for an application.
	 *
	 * @param int $application_id Application id.
	 */
	public function get_application_notes( int $application_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_application_notes';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.display_name AS user_name
				 FROM {$table} n
				 LEFT JOIN {$wpdb->users} u ON u.ID = n.user_id
				 WHERE n.application_id = %d
				 ORDER BY n.created_at ASC",
				$application_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Add a reminder for a job application.
	 *
	 * @param int    $user_id User id.
	 * @param int    $job_id Job id.
	 * @param string $remind_at Remind at.
	 */
	public function add_reminder( int $user_id, int $job_id, string $remind_at ): int|false {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_reminders';
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'job_id'     => $job_id,
				'remind_at'  => sanitize_text_field( $remind_at ),
				'sent'       => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get reminders for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function get_user_reminders( int $user_id ): array {
		global $wpdb;
		$table      = $wpdb->prefix . 'zeko_job_reminders';
		$jobs_table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, j.title, j.slug, j.status as job_status
				 FROM {$table} r
				 JOIN {$jobs_table} j ON j.id = r.job_id
				 WHERE r.user_id = %d
				 ORDER BY r.remind_at ASC",
				$user_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete a reminder.
	 *
	 * @param int $reminder_id Reminder id.
	 * @param int $user_id User id.
	 */
	public function delete_reminder( int $reminder_id, int $user_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_reminders';
		$result = $wpdb->delete(
			$table,
			array(
				'id'      => $reminder_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Add a saved search.
	 *
	 * @param int   $user_id User id.
	 * @param array $params Params.
	 */
	public function add_saved_search( int $user_id, array $params ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_searches';
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'      => $user_id,
				'name'         => isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : 'Saved Search',
				'search_term'  => isset( $params['search_term'] ) ? sanitize_text_field( $params['search_term'] ) : null,
				'location'     => isset( $params['location'] ) ? sanitize_text_field( $params['location'] ) : null,
				'job_type'     => isset( $params['job_type'] ) ? sanitize_text_field( $params['job_type'] ) : null,
				'salary_min'   => isset( $params['salary_min'] ) ? (int) $params['salary_min'] : null,
				'salary_max'   => isset( $params['salary_max'] ) ? (int) $params['salary_max'] : null,
				'email_alerts' => isset( $params['email_alerts'] ) ? (int) $params['email_alerts'] : 0,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get saved searches for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function get_saved_searches( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_searches';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete a saved search.
	 *
	 * @param int $search_id Search id.
	 * @param int $user_id User id.
	 */
	public function delete_saved_search( int $search_id, int $user_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_searches';
		$result = $wpdb->delete(
			$table,
			array(
				'id'      => $search_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Get saved searches with email alerts enabled.
	 */
	public function get_saved_searches_with_alerts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_searches';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE email_alerts = 1 AND alert_frequency IS NOT NULL AND alert_frequency != ''",
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get all upcoming interviews for a seeker.
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function get_seeker_interviews( int $seeker_id ): array {
		global $wpdb;
		$table      = $wpdb->prefix . 'zeko_job_interviews';
		$apps_table = $wpdb->prefix . 'zeko_job_applications';
		$jobs_table = $wpdb->prefix . 'zeko_jobs';

		$sql = "SELECT i.*, j.title AS job_title, j.slug AS job_slug,
				employer.display_name AS employer_name,
				interviewer.display_name AS interviewer_name
			FROM {$table} i
			INNER JOIN {$apps_table} a ON a.id = i.application_id
			INNER JOIN {$jobs_table} j ON j.id = a.job_id
			LEFT JOIN {$wpdb->users} employer ON employer.ID = i.employer_id
			LEFT JOIN {$wpdb->users} interviewer ON interviewer.ID = i.interviewer_id
			WHERE i.seeker_id = %d AND i.status = 'scheduled'
			ORDER BY i.scheduled_at ASC";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare( $sql, $seeker_id ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Add a candidate document.
	 *
	 * @param int   $user_id User id.
	 * @param array $params Params.
	 */
	public function add_document( int $user_id, array $params ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_documents';
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'        => $user_id,
				'application_id' => isset( $params['application_id'] ) ? (int) $params['application_id'] : null,
				'filename'       => sanitize_text_field( $params['filename'] ),
				'label'          => isset( $params['label'] ) ? sanitize_text_field( $params['label'] ) : null,
				'file_url'       => esc_url_raw( $params['file_url'] ),
				'file_type'      => sanitize_text_field( $params['file_type'] ),
				'file_size'      => isset( $params['file_size'] ) ? (int) $params['file_size'] : null,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get documents for a user or application.
	 *
	 * @param int $user_id User id.
	 * @param int $application_id Application id.
	 */
	public function get_documents( int $user_id, int $application_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_documents';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$query = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d",
			$user_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $application_id > 0 ) {
			$query .= $wpdb->prepare( ' AND application_id = %d', $application_id );
		}
		$query .= ' ORDER BY created_at DESC';
		return $wpdb->get_results( $query, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get only resume-type documents for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function get_resumes( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_documents';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND (file_type LIKE %s OR file_type LIKE %s OR file_type LIKE %s) AND application_id IS NULL ORDER BY created_at DESC",
				$user_id,
				'%pdf%',
				'%word%',
				'%doc%'
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Set a document as the user's default resume (stored in user meta).
	 *
	 * @param int $user_id User id.
	 * @param int $doc_id Doc id.
	 */
	public function set_user_default_resume( int $user_id, int $doc_id ): bool {
		return (bool) update_user_meta( $user_id, 'zeko_default_resume_id', $doc_id );
	}

	/**
	 * Get the user's default resume document ID.
	 *
	 * @param int $user_id User id.
	 */
	public function get_user_default_resume_id( int $user_id ): int {
		return (int) get_user_meta( $user_id, 'zeko_default_resume_id', true );
	}

	/**
	 * Update the label on a document.
	 *
	 * @param int    $doc_id Doc id.
	 * @param string $label Label.
	 */
	public function update_document_label( int $doc_id, string $label ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_documents';
		$result = $wpdb->update(
			$table,
			array( 'label' => sanitize_text_field( $label ) ),
			array( 'id' => $doc_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/* ─── Job Alerts ───────────────────────────────────────────── */

	/**
	 * Create a new job alert.
	 *
	 * @param int   $user_id User id.
	 * @param array $params Params.
	 */
	public function create_job_alert( int $user_id, array $params ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_alerts';
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'       => $user_id,
				'name'          => sanitize_text_field( $params['name'] ?? '' ),
				'search_params' => wp_json_encode( $params['search_params'] ?? array() ),
				'frequency'     => sanitize_text_field( $params['frequency'] ?? 'daily' ),
				'is_active'     => 1,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get all alerts for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function get_job_alerts( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_alerts';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( $results as &$row ) {
			$row['search_params'] = json_decode( $row['search_params'], true ) ?: array();
		}
		return $results;
	}

	/**
	 * Get a single alert by ID (with ownership check).
	 *
	 * @param int $alert_id Alert id.
	 * @param int $user_id User id.
	 */
	public function get_job_alert( int $alert_id, int $user_id = 0 ): ?array {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_alerts';
		$where  = array( 'id' => $alert_id );
		$format = array( '%d' );
		if ( $user_id > 0 ) {
			$where['user_id'] = $user_id;
			$format[]         = '%d';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d" . ( $user_id ? ' AND user_id = %d' : '' ), ...array_values( $where ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		if ( ! $row ) {
			return null;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$row['search_params'] = json_decode( $row['search_params'], true ) ?: array();
		return $row;
	}

	/**
	 * Update a job alert.
	 *
	 * @param int   $alert_id Alert id.
	 * @param int   $user_id User id.
	 * @param array $params Params.
	 */
	public function update_job_alert( int $alert_id, int $user_id, array $params ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_alerts';
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$format = array( '%s' );

		if ( isset( $params['name'] ) ) {
			$update['name'] = sanitize_text_field( $params['name'] );
			$format[]       = '%s';
		}
		if ( isset( $params['search_params'] ) ) {
			$update['search_params'] = wp_json_encode( $params['search_params'] );
			$format[]                = '%s';
		}
		if ( isset( $params['frequency'] ) ) {
			$frequency = sanitize_text_field( $params['frequency'] );
			if ( in_array( $frequency, array( 'instant', 'daily', 'weekly' ), true ) ) {
				$update['frequency'] = $frequency;
				$format[]            = '%s';
			}
		}
		if ( isset( $params['is_active'] ) ) {
			$update['is_active'] = $params['is_active'] ? 1 : 0;
			$format[]            = '%d';
		}

		$result = $wpdb->update(
			$table,
			$update,
			array(
				'id'      => $alert_id,
				'user_id' => $user_id,
			),
			$format,
			array( '%d', '%d' )
		);
		return false !== $result;
	}

	/**
	 * Delete a job alert.
	 *
	 * @param int $alert_id Alert id.
	 * @param int $user_id User id.
	 */
	public function delete_job_alert( int $alert_id, int $user_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_alerts';
		$result = $wpdb->delete(
			$table,
			array(
				'id'      => $alert_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
		return false !== $result && $result > 0;
	}

	/**
	 * Get all active alerts by frequency (for cron processing).
	 *
	 * @param string $frequency Frequency.
	 */
	public function get_active_alerts_by_frequency( string $frequency ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_alerts';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_active = 1 AND frequency = %s ORDER BY id ASC",
				$frequency
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( $results as &$row ) {
			$row['search_params'] = json_decode( $row['search_params'], true ) ?: array();
		}
		return $results;
	}

	/**
	 * Update last_sent for an alert.
	 *
	 * @param int $alert_id Alert id.
	 */
	public function mark_alert_sent( int $alert_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_alerts';
		$result = $wpdb->update(
			$table,
			array(
				'last_sent'  => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $alert_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Find jobs matching alert search params (simplified keyword/location/type match).
	 *
	 * @param array $params Params.
	 * @param int   $limit Limit.
	 */
	public function get_jobs_matching_alert( array $params, int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';

		$where  = "WHERE status = 'publish'";
		$values = array();

		if ( ! empty( $params['keyword'] ) ) {
			$keyword  = '%' . $wpdb->esc_like( sanitize_text_field( $params['keyword'] ) ) . '%';
			$where   .= ' AND (title LIKE %s OR description LIKE %s OR excerpt LIKE %s)';
			$values[] = $keyword;
			$values[] = $keyword;
			$values[] = $keyword;
		}
		if ( ! empty( $params['location'] ) ) {
			$location = '%' . $wpdb->esc_like( sanitize_text_field( $params['location'] ) ) . '%';
			$where   .= ' AND (location LIKE %s OR location_type LIKE %s)';
			$values[] = $location;
			$values[] = $location;
		}
		if ( ! empty( $params['type'] ) ) {
			$where   .= ' AND type = %s';
			$values[] = sanitize_text_field( $params['type'] );
		}
		if ( ! empty( $params['category'] ) ) {
			$where   .= ' AND category = %s';
			$values[] = sanitize_text_field( $params['category'] );
		}
		if ( ! empty( $params['salary_min'] ) ) {
			$where   .= ' AND salary_min >= %d';
			$values[] = (int) $params['salary_min'];
		}
		if ( ! empty( $params['salary_max'] ) ) {
			$where   .= ' AND salary_max <= %d';
			$values[] = (int) $params['salary_max'];
		}

		$query    = "SELECT id, title, location, salary_min, salary_max, type, is_featured, created_at FROM {$table} {$where} ORDER BY is_featured DESC, created_at DESC LIMIT %d";
		$values[] = $limit;

		return $wpdb->get_results( $wpdb->prepare( $query, ...$values ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get recommended jobs for a user based on profile signals and activity.
	 * Matches by: saved search terms, dominant category/type/location from
	 * application history, and saved search preferences. Falls back to
	 * newest published jobs when no signals exist.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public function get_recommended_jobs( int $user_id, int $limit = 8 ): array {
		global $wpdb;
		$table_jobs     = $wpdb->prefix . 'zeko_jobs';
		$table_searches = $wpdb->prefix . 'zeko_job_searches';
		$table_apps     = $wpdb->prefix . 'zeko_job_applications';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$applied_job_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT job_id FROM {$table_apps} WHERE seeker_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$search_terms = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT search_term FROM {$table_searches} WHERE user_id = %d AND search_term != ''",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$preferred_types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT j.type FROM {$table_apps} a INNER JOIN {$table_jobs} j ON a.job_id = j.id WHERE a.seeker_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$preferred_categories = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT j.category FROM {$table_apps} a INNER JOIN {$table_jobs} j ON a.job_id = j.id WHERE a.seeker_id = %d AND j.category != ''",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$preferred_locations = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT j.location FROM {$table_apps} a INNER JOIN {$table_jobs} j ON a.job_id = j.id WHERE a.seeker_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$search_locations = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT location FROM {$table_searches} WHERE user_id = %d AND location != ''",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$preferred_locations = array_unique( array_merge( $preferred_locations, $search_locations ) );

		$where   = array( "status = 'publish'" );
		$params  = array();
		$signals = 0;

		if ( ! empty( $search_terms ) ) {
			$like_clauses = array();
			foreach ( $search_terms as $term ) {
				$like_clauses[] = '(title LIKE %s OR description LIKE %s)';
				$params[]       = '%' . $wpdb->esc_like( $term ) . '%';
				$params[]       = '%' . $wpdb->esc_like( $term ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $like_clauses ) . ')';
			++$signals;
		}

		if ( ! empty( $preferred_categories ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $preferred_categories ), '%s' ) );
			$where[]      = "category IN ({$placeholders})";
			$params       = array_merge( $params, $preferred_categories );
			++$signals;
		}

		if ( ! empty( $preferred_types ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $preferred_types ), '%s' ) );
			$where[]      = "type IN ({$placeholders})";
			$params       = array_merge( $params, $preferred_types );
			++$signals;
		}

		if ( ! empty( $preferred_locations ) ) {
			$loc_clauses = array();
			foreach ( $preferred_locations as $loc ) {
				$loc_clauses[] = 'location LIKE %s';
				$params[]      = '%' . $wpdb->esc_like( $loc ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $loc_clauses ) . ')';
			++$signals;
		}

		if ( ! empty( $applied_job_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $applied_job_ids ), '%d' ) );
			$where[]      = "id NOT IN ({$placeholders})";
			$params       = array_merge( $params, $applied_job_ids );
		}

		if ( 0 === $signals ) {
			$where[] = '1 = 1';
		}

		$where_clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, slug, location, type, category, salary_min, salary_max, is_featured, description, experience_level, remote_option FROM {$table_jobs} WHERE {$where_clause} ORDER BY is_featured DESC, created_at DESC LIMIT %d",
				array_merge( $params, array( $limit * 2 ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( ! empty( $jobs ) ) {
			foreach ( $jobs as &$job ) {
				$match                  = $this->calculate_profile_match( $user_id, $job );
				$job['match_score']     = $match['score'];
				$job['match_breakdown'] = $match['breakdown'];
			}
			unset( $job );
			usort( $jobs, fn( $a, $b ) => ( $b['match_score'] ?? 0 ) <=> ( $a['match_score'] ?? 0 ) );
			$jobs = array_slice( $jobs, 0, $limit );
		}

		return $jobs;
	}

	/**
	 * Get jobs similar to a specific job by matching category, type, and keyword overlap.
	 *
	 * @param int $job_id Job id.
	 * @param int $limit Limit.
	 */
	public function get_similar_jobs( int $job_id, int $limit = 4 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';

		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return array();
		}

		$where  = array( "status = 'publish'", 'id != %d' );
		$params = array( $job_id );

		// Match by category (highest weight).
		if ( ! empty( $job['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = $job['category'];
		}

		// Match by type.
		if ( ! empty( $job['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $job['type'];
		}

		// Keyword overlap from title words.
		$title_words = array_filter(
			explode( ' ', $job['title'] ),
			function ( $w ) {
				return mb_strlen( $w ) > 3;
			}
		);
		if ( ! empty( $title_words ) ) {
			$like_clauses = array();
			foreach ( $title_words as $word ) {
				$like_clauses[] = 'title LIKE %s';
				$params[]       = '%' . $wpdb->esc_like( $word ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $like_clauses ) . ')';
		}

		// Prefer similar salary range (±30%).
		$min_salary = ! empty( $job['salary_min'] ) ? (float) $job['salary_min'] * 0.7 : 0;
		$max_salary = ! empty( $job['salary_max'] ) ? (float) $job['salary_max'] * 1.3 : 0;
		if ( $min_salary > 0 || $max_salary > 0 ) {
			$salary_clauses = array();
			if ( $min_salary > 0 ) {
				$salary_clauses[] = 'salary_max >= %d';
				$params[]         = (int) $min_salary;
			}
			if ( $max_salary > 0 ) {
				$salary_clauses[] = 'salary_min <= %d';
				$params[]         = (int) $max_salary;
			}
			if ( ! empty( $salary_clauses ) ) {
				$where[] = '(' . implode( ' AND ', $salary_clauses ) . ')';
			}
		}

		$where_clause = implode( ' AND ', $where );
		$params[]     = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, employer_id, title, slug, location, type, category, salary_min, salary_max, company_name FROM {$table} WHERE {$where_clause} ORDER BY is_featured DESC, created_at DESC LIMIT %d",
				$params
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get jobs similar to a given job based on category, type, and location overlap.
	 */
	/**
	 * Get salary insights for a given category and/or location.
	 * Returns average, min, max, and sample count for the category, the location,
	 * and overall — so the single job page can show how a listing compares.
	 *
	 * @param string $category Category.
	 * @param string $location Location.
	 */
	public function get_salary_insights( string $category = '', string $location = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';

		$empty = array(
			'category' => null,
			'location' => null,
			'overall'  => null,
		);

		$has_salary = 'salary_min > 0 OR salary_max > 0';

		$avg_sql = "SELECT
			AVG(CASE WHEN salary_min > 0 AND salary_max > 0 THEN (salary_min + salary_max) / 2 WHEN salary_min > 0 THEN salary_min ELSE salary_max END) AS avg_salary,
			MIN(CASE WHEN salary_min > 0 THEN salary_min ELSE salary_max END) AS min_salary,
			MAX(CASE WHEN salary_max > 0 THEN salary_max ELSE salary_min END) AS max_salary,
			COUNT(*) AS sample_count
			FROM {$table} WHERE status = 'publish' AND ({$has_salary})";

		$results = array();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $category ) {
			$results['category'] = $wpdb->get_row(
				$wpdb->prepare( "{$avg_sql} AND category = %s", $category ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $location ) {
			$results['location'] = $wpdb->get_row(
				$wpdb->prepare( "{$avg_sql} AND location LIKE %s", '%' . $wpdb->esc_like( $location ) . '%' ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$results['overall'] = $wpdb->get_row( $avg_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return $results;
	}

	/**
	 * Insert a review for a job.
	 *
	 * @param int    $job_id Job id.
	 * @param int    $user_id User id.
	 * @param int    $rating Rating.
	 * @param string $review_text Review text.
	 */
	public function insert_review( int $job_id, int $user_id, int $rating, string $review_text = '' ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_reviews';
		$rating = max( 1, min( 5, $rating ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE job_id = %d AND user_id = %d", $job_id, $user_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $existing ) {
			return (bool) $wpdb->update(
				$table,
				array(
					'rating'      => $rating,
					'review_text' => $review_text,
				),
				array( 'id' => (int) $existing ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		return (bool) $wpdb->insert(
			$table,
			array(
				'job_id'      => $job_id,
				'user_id'     => $user_id,
				'rating'      => $rating,
				'review_text' => $review_text,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get all reviews for a job.
	 *
	 * @param int $job_id Job id.
	 */
	public function get_reviews( int $job_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_reviews';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, u.display_name AS reviewer_name
				 FROM {$table} r
				 LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
				 WHERE r.job_id = %d
				 ORDER BY r.created_at DESC",
				$job_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get average rating for a job.
	 *
	 * @param int $job_id Job id.
	 */
	public function get_average_rating( int $job_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_reviews';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count FROM {$table} WHERE job_id = %d",
				$job_id
			),
			ARRAY_A
		) ?: array(
			'avg_rating'   => 0,
			'review_count' => 0,
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get average rating across all jobs for a company (by employer_id).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_company_average_rating( int $employer_id ): array {
		global $wpdb;
		$reviews_table = $wpdb->prefix . 'zeko_job_reviews';
		$jobs_table    = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT AVG(r.rating) AS avg_rating, COUNT(*) AS review_count
				 FROM {$reviews_table} r
				 INNER JOIN {$jobs_table} j ON r.job_id = j.id
				 WHERE j.employer_id = %d",
				$employer_id
			),
			ARRAY_A
		) ?: array(
			'avg_rating'   => 0,
			'review_count' => 0,
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get all reviews for a company's jobs (by employer_id).
	 *
	 * @param int $employer_id Employer id.
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 */
	public function get_company_reviews( int $employer_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$reviews_table = $wpdb->prefix . 'zeko_job_reviews';
		$jobs_table    = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, u.display_name AS reviewer_name, j.title AS job_title, j.slug AS job_slug
				 FROM {$reviews_table} r
				 INNER JOIN {$jobs_table} j ON r.job_id = j.id
				 LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
				 WHERE j.employer_id = %d
				 ORDER BY r.created_at DESC
				 LIMIT %d OFFSET %d",
				$employer_id,
				$limit,
				$offset
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get a single document row by ID (ownership-neutral; the caller applies
	 * the access/authorization policy). Used by the private delivery
	 * controller, physical-file deletion, retention, and privacy flows.
	 *
	 * @return array|null Document row or null when not found.
	 * @param int $document_id Document row ID.
	 */
	public function get_document( int $document_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_documents';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $document_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	/**
	 * Purge candidate documents for applications in terminal states older
	 * than a retention window, removing physical files too.
	 * Retention applies to applications that are rejected, withdrawn,
	 * cancelled or expired — documents on those rows are private stale data.
	 *
	 * @return int Number of documents removed (rows + physical files).
	 * @param int   $older_than_days Minimum age in days before a document is purged.
	 * @param array $statuses Terminal application statuses to consider.
	 */
	public function purge_expired_documents( int $older_than_days, array $statuses = array() ): int {
		global $wpdb;
		if ( $older_than_days < 1 ) {
			return 0;
		}

		$documents_table = $wpdb->prefix . 'zeko_job_documents';
		$apps_table      = $wpdb->prefix . 'zeko_job_applications';
		$default_status  = array( 'rejected', 'withdrawn', 'cancelled', 'expired' );
		$statuses        = array_values( array_intersect( $default_status, $statuses ) );
		$statuses        = $statuses ?: $default_status;

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - $older_than_days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$sql = $wpdb->prepare(
			"SELECT d.id, d.filename FROM {$documents_table} d
			INNER JOIN {$apps_table} a ON a.id = d.application_id
			WHERE a.status IN ({$placeholders})
			AND d.created_at < %s",
			array_merge( $statuses, array( $cutoff ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$removed = 0;
		foreach ( $rows as $row ) {
			if ( $this->delete_physical_document_file( (string) $row['filename'] ) && $wpdb->delete( $documents_table, array( 'id' => (int) $row['id'] ), array( '%d' ) ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Remove the physical file for a candidate document.
	 * Only operates on files inside the hardened documents directory.
	 *
	 * @return bool True when the physical file no longer exists (deleted or absent).
	 * @param string $filename Stored document filename.
	 */
	public function delete_physical_document_file( string $filename ): bool {
		$target_dir = $this->documents_base_dir();
		$candidate  = trailingslashit( $target_dir ) . $filename;

		// Path-confine: the stored filename must stay inside the documents dir.
		$real_dir  = realpath( $target_dir );
		$real_cand = realpath( $candidate );
		if ( false === $real_dir || false === $real_cand || 0 !== strpos( $real_cand, trailingslashit( $real_dir ) ) ) {
			return false;
		}

		if ( is_file( $real_cand ) && is_writable( $real_cand ) ) {
			return @unlink( $real_cand ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return ! file_exists( $real_cand );
	}

	/**
	 * Base directory that stores candidate documents.
	 */
	public function documents_base_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'zeko/documents/';
	}

	/**
	 * Delete a document (row + physical file).
	 * Removes the DB record and unlinks the physical file so private
	 * resume/document data never survives as an orphan on disk.
	 *
	 * @param int $document_id Document id.
	 * @param int $user_id User id.
	 */
	public function delete_document( int $document_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_documents';
		$doc   = $this->get_document( $document_id );

		if ( ! $doc ) {
			return false;
		}

		// Ownership: only the owner may delete (matches existing scoping).
		if ( (int) $doc['user_id'] !== $user_id ) {
			return false;
		}

		// Confine + unlink the physical file (no-op when already absent).
		$base_dir  = $this->documents_base_dir();
		$physical  = trailingslashit( $base_dir ) . $doc['filename'];
		$real_dir  = realpath( $base_dir );
		$real_phys = realpath( $physical );

		if ( false === $real_dir || false === $real_phys || 0 !== strpos( $real_phys, trailingslashit( $real_dir ) ) ) {
			return false;
		}

		if ( is_file( $real_phys ) && is_writable( $real_phys ) ) {
			@unlink( $real_phys ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( file_exists( $real_phys ) ) {
			return false;
		}

		$result = $wpdb->delete(
			$table,
			array(
				'id'      => $document_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Update saved search last_sent timestamp.
	 *
	 * @param int $search_id Search id.
	 */
	public function update_saved_search_sent( int $search_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_searches';
		$result = $wpdb->update(
			$table,
			array( 'last_sent' => current_time( 'mysql' ) ),
			array( 'id' => $search_id ),
			array( '%s' ),
			array( '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Create an interview.
	 *
	 * @param array $params Params.
	 */
	public function create_interview( array $params ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_interviews';
		$result = $wpdb->insert(
			$table,
			array(
				'application_id' => isset( $params['application_id'] ) ? (int) $params['application_id'] : 0,
				'employer_id'    => isset( $params['employer_id'] ) ? (int) $params['employer_id'] : 0,
				'interviewer_id' => ! empty( $params['interviewer_id'] ) ? (int) $params['interviewer_id'] : null,
				'seeker_id'      => isset( $params['seeker_id'] ) ? (int) $params['seeker_id'] : 0,
				'scheduled_at'   => sanitize_text_field( $params['scheduled_at'] ),
				'duration'       => isset( $params['duration'] ) ? (int) $params['duration'] : 60,
				'location'       => isset( $params['location'] ) ? sanitize_text_field( $params['location'] ) : null,
				'meeting_url'    => isset( $params['meeting_url'] ) ? esc_url_raw( $params['meeting_url'] ) : null,
				'type'           => isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : 'video',
				'notes'          => isset( $params['notes'] ) ? sanitize_textarea_field( $params['notes'] ) : null,
				'status'         => 'scheduled',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get interviews for an application.
	 *
	 * @param int $application_id Application id.
	 */
	public function get_interviews( int $application_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_interviews';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE application_id = %d ORDER BY scheduled_at DESC",
				$application_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Update interview status.
	 * When an interview is marked completed, applications still sitting in the
	 * "interviewing" stage are automatically advanced to the configured next
	 * stage (default "offered") — pipeline automation.
	 *
	 * @return bool
	 * @param int    $interview_id Interview ID.
	 * @param string $status New status.
	 */
	public function update_interview_status( int $interview_id, string $status ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_interviews';
		$result = $wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'id' => $interview_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $result && 'completed' === $status ) {
			$this->auto_advance_after_interview( $interview_id );
		}

		return (bool) $result;
	}

	/**
	 * Auto-advance an application after its interview is completed.
	 * The rule map is filterable via `zeko_jobs_auto_advance_rules`
	 * (default: interviewing → offered). Only forward moves to a later stage
	 * are applied; terminal/withdrawn applications are never touched.
	 *
	 * @param int $interview_id Interview ID.
	 */
	private function auto_advance_after_interview( int $interview_id ): void {
		global $wpdb;
		$interview = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.application_id FROM {$wpdb->prefix}zeko_job_interviews i WHERE i.id = %d",
				$interview_id
			)
		);
		if ( ! $interview || empty( $interview->application_id ) ) {
			return;
		}

		$app = $this->get_application( (int) $interview->application_id );
		if ( ! $app ) {
			return;
		}

		$rules = apply_filters( 'zeko_jobs_auto_advance_rules', array( 'interviewing' => 'offered' ) );
		if ( empty( $rules[ $app['status'] ] ) ) {
			return;
		}

		$target = sanitize_text_field( $rules[ $app['status'] ] );
		$order  = array(
			'awaiting_review' => 1,
			'reviewed'        => 2,
			'contacting'      => 3,
			'interviewing'    => 4,
			'offered'         => 5,
			'hired'           => 6,
		);
		if ( ! isset( $order[ $target ] ) || $order[ $target ] <= $order[ $app['status'] ] ) {
			return;
		}

		$job = $wpdb->get_var(
			$wpdb->prepare( "SELECT employer_id FROM {$wpdb->prefix}zeko_jobs WHERE id = %d", (int) $app['job_id'] )
		);
		if ( $job ) {
			$this->update_application_status( (int) $app['id'], $target, (int) $job );
		}
	}

	/**
	 * Propose time slots for an interview (seeker side).
	 *
	 * @param int   $interview_id Interview id.
	 * @param int   $seeker_id Seeker id.
	 * @param array $times Times.
	 */
	public function propose_interview_times( int $interview_id, int $seeker_id, array $times ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_interviews';
		$clean  = array_map( 'sanitize_text_field', $times );
		$result = $wpdb->update(
			$table,
			array( 'proposed_times' => wp_json_encode( $clean ) ),
			array(
				'id'        => $interview_id,
				'seeker_id' => $seeker_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Accept a proposed time slot (employer side).
	 *
	 * @param int    $interview_id Interview id.
	 * @param int    $employer_id Employer id.
	 * @param string $accepted_time Accepted time.
	 */
	public function accept_interview_time( int $interview_id, int $employer_id, string $accepted_time ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_interviews';
		$result = $wpdb->update(
			$table,
			array(
				'accepted_time' => sanitize_text_field( $accepted_time ),
				'scheduled_at'  => sanitize_text_field( $accepted_time ),
				'status'        => 'confirmed',
			),
			array(
				'id'          => $interview_id,
				'employer_id' => $employer_id,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Generate ICS calendar invite for an interview.
	 *
	 * @param array $interview Interview.
	 */
	public function generate_ics( array $interview ): string {
		$dtstart = gmdate( 'Ymd\THis\Z', strtotime( $interview['scheduled_at'] ) );
		$dtend   = gmdate( 'Ymd\THis\Z', strtotime( $interview['scheduled_at'] . ' + ' . $interview['duration'] . ' minutes' ) );
		$uid     = uniqid() . '@zeko-jobs';

		$ics = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Zeko Jobs//EN\nBEGIN:VEVENT\nUID:{$uid}\nDTSTART:{$dtstart}\nDTEND:{$dtend}\nSUMMARY:Job Interview\n";
		if ( ! empty( $interview['location'] ) ) {
			$ics .= 'LOCATION:' . addcslashes( $interview['location'], ",;\n" ) . "\n";
		}
		if ( ! empty( $interview['notes'] ) ) {
			$ics .= 'DESCRIPTION:' . addcslashes( $interview['notes'], ",;\n" ) . "\n";
		}
		$ics .= "END:VEVENT\nEND:VCALENDAR";

		return $ics;
	}

	/**
	 * Get a single job by ID.
	 *
	 * @param int $job_id Job id.
	 */
	public function get_job( int $job_id ): ?array {
		$cache_key = 'job_' . $job_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		wp_cache_set( $cache_key, $job ?: false, self::CACHE_GROUP, self::CACHE_TTL );

		return $job ?: null;
	}

	/**
	 * Get a single job by ID with employer ownership check.
	 *
	 * @param int $job_id Job id.
	 * @param int $employer_id Employer id.
	 */
	public function get_job_by_id_and_employer( int $job_id, int $employer_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND employer_id = %d", $job_id, $employer_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $job ?: null;
	}

	/**
	 * Get a published job by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function get_job_by_slug( string $slug ): ?array {
		$cache_key = 'job_slug_' . md5( $slug );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s AND status = 'publish'", $slug ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		wp_cache_set( $cache_key, $job ?: false, self::CACHE_GROUP, self::CACHE_TTL );

		return $job ?: null;
	}

	/**
	 * Insert a new job and return the insert ID.
	 *
	 * @param array $data Data.
	 */
	public function insert_job( array $data ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_jobs';
		$result = $wpdb->insert( $table, $data );
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a job by ID.
	 *
	 * @param int   $job_id Job id.
	 * @param array $data Data.
	 */
	public function update_job( int $job_id, array $data ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_jobs';
		$result = $wpdb->update( $table, $data, array( 'id' => $job_id ) );
		return false !== $result;
	}

	/**
	 * Delete a job by ID with employer ownership check.
	 *
	 * @param int $job_id Job id.
	 * @param int $employer_id Employer id.
	 */
	public function delete_job( int $job_id, int $employer_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_jobs';
		$result = $wpdb->delete(
			$table,
			array(
				'id'          => $job_id,
				'employer_id' => $employer_id,
			),
			array( '%d', '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Insert a job application.
	 *
	 * @param array $data Data.
	 */
	public function insert_application( array $data ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_applications';
		$result = $wpdb->insert( $table, $data );
		return (bool) $result;
	}

	/**
	 * Filter job IDs to only those that are published.
	 *
	 * @param array $job_ids Job ids.
	 */
	public function get_valid_job_ids( array $job_ids ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		if ( empty( $job_ids ) ) {
			return array();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE id IN (" . implode( ',', array_fill( 0, count( $job_ids ), '%d' ) ) . ") AND status = 'publish'",
				$job_ids
			)
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get existing application job IDs for a seeker from a list of job IDs.
	 *
	 * @param int   $seeker_id Seeker id.
	 * @param array $job_ids Job ids.
	 */
	public function get_existing_application_job_ids( int $seeker_id, array $job_ids ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		if ( empty( $job_ids ) ) {
			return array();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT job_id FROM {$table} WHERE seeker_id = %d AND job_id IN (" . implode( ',', array_fill( 0, count( $job_ids ), '%d' ) ) . ')',
				array_merge( array( $seeker_id ), $job_ids )
			)
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get applications for a job with user data (for CSV export).
	 *
	 * @param int $job_id Job id.
	 */
	public function get_applications_for_job_export( int $job_id ): array {
		global $wpdb;
		$table_apps = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT app.id, app.seeker_id, app.status, app.applied_at, app.cover_letter,
				        seeker.display_name, seeker.user_login, seeker.user_email
				 FROM {$table_apps} app
				 LEFT JOIN {$wpdb->users} seeker ON seeker.ID = app.seeker_id
				 WHERE app.job_id = %d
				 ORDER BY app.applied_at DESC",
				$job_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get a single interview by ID.
	 *
	 * @param int $interview_id Interview id.
	 */
	public function get_interview_by_id( int $interview_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_interviews';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$interview = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $interview_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $interview ?: null;
	}

	/**
	 * Get all upcoming interviews for an employer.
	 *
	 * @param int    $employer_id Employer id.
	 * @param string $month Month.
	 */
	public function get_employer_interviews( int $employer_id, string $month = '' ): array {
		global $wpdb;
		$table      = $wpdb->prefix . 'zeko_job_interviews';
		$apps_table = $wpdb->prefix . 'zeko_job_applications';
		$jobs_table = $wpdb->prefix . 'zeko_jobs';

		$where  = "i.employer_id = %d AND i.status = 'scheduled'";
		$params = array( $employer_id );

		if ( ! empty( $month ) ) {
			$where   .= ' AND DATE_FORMAT(i.scheduled_at, "%%Y-%%m") = %s';
			$params[] = $month;
		}

		$sql = "SELECT i.*, j.title AS job_title, j.slug AS job_slug,
				seeker.display_name AS seeker_name, seeker.user_login AS seeker_login,
				interviewer.display_name AS interviewer_name
			FROM {$table} i
			INNER JOIN {$apps_table} a ON a.id = i.application_id
			INNER JOIN {$jobs_table} j ON j.id = a.job_id
			LEFT JOIN {$wpdb->users} seeker ON seeker.ID = i.seeker_id
			LEFT JOIN {$wpdb->users} interviewer ON interviewer.ID = i.interviewer_id
			WHERE {$where}
			ORDER BY i.scheduled_at ASC";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get employer's team members (users with manage_options or edit_posts capability).
	 *
	 * @return array Array of [ ['id' => int, 'name' => string], ... ].
	 * @param int $employer_id Employer user ID.
	 */
	public function get_employer_team_members( int $employer_id ): array {
		unset( $employer_id );
		$args    = array(
			'role'    => array( 'administrator', 'editor', 'author', 'contributor' ),
			'fields'  => array( 'ID', 'display_name' ),
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 50,
		);
		$users   = get_users( $args );
		$members = array();
		foreach ( $users as $u ) {
			$members[] = array(
				'id'   => (int) $u->ID,
				'name' => $u->display_name,
			);
		}
		return $members;
	}

	/**
	 * Get featured published jobs.
	 *
	 * @param int $limit Limit.
	 */
	public function get_featured_jobs( int $limit = 6 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, slug, location, type, salary_min, salary_max, created_at
				 FROM {$table}
				 WHERE status = 'publish' AND is_featured = 1
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Increment the view count for a job.
	 *
	 * @param int $job_id Job id.
	 */
	public function increment_job_views( int $job_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET views = views + 1 WHERE id = %d",
				$job_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// Log the view for time-series analytics.
		$log_table  = $wpdb->prefix . 'zeko_job_views_log';
		$user_id    = get_current_user_id() ?: null;
		$visitor_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$referer    = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$wpdb->insert(
			$log_table,
			array(
				'job_id'     => $job_id,
				'user_id'    => $user_id,
				'visitor_ip' => substr( $visitor_ip, 0, 45 ),
				'user_agent' => substr( $user_agent, 0, 512 ),
				'referer'    => substr( $referer, 0, 512 ),
				'viewed_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get application rating by employer.
	 *
	 * @param int $application_id Application id.
	 * @param int $employer_id Employer id.
	 */
	public function get_app_rating( int $application_id, int $employer_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_app_ratings';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d AND employer_id = %d", $application_id, $employer_id ),
			ARRAY_A
		) ?: null;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Save or update application rating.
	 *
	 * @param int    $application_id Application id.
	 * @param int    $employer_id Employer id.
	 * @param int    $rating Rating.
	 * @param string $note Note.
	 */
	public function save_app_rating( int $application_id, int $employer_id, int $rating, string $note = '' ): int {
		global $wpdb;
		$table    = $wpdb->prefix . 'zeko_app_ratings';
		$existing = $this->get_app_rating( $application_id, $employer_id );
		$data     = array(
			'application_id' => $application_id,
			'employer_id'    => $employer_id,
			'rating'         => max( 0, min( 5, $rating ) ),
			'note'           => $note,
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing['id'] ) );
			return (int) $existing['id'];
		}
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get all ratings for an employer's applications.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_employer_ratings( int $employer_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_app_ratings';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE employer_id = %d", $employer_id ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get average rating for an application.
	 *
	 * @param int $application_id Application id.
	 */
	public function get_app_avg_rating( int $application_id ): ?float {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_app_ratings';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$avg = $wpdb->get_var(
			$wpdb->prepare( "SELECT AVG(rating) FROM {$table} WHERE application_id = %d", $application_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $avg ? round( (float) $avg, 1 ) : null;
	}

	/**
	 * All published jobs for sitemap.
	 */
	public function get_all_published_jobs_for_sitemap(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			"SELECT slug, updated_at FROM {$table} WHERE status = 'publish' ORDER BY updated_at DESC",
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get jobs with optional status and limit filters.
	 *
	 * @param array $args Args.
	 */
	public function get_jobs( array $args = array() ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';

		$status = isset( $args['status'] ) ? $args['status'] : 'publish';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 20;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
				$status,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get a company profile by employer ID.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_company_by_employer( int $employer_id ): ?array {
		$cache_key = 'company_' . $employer_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE employer_id = %d", $employer_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		wp_cache_set( $cache_key, $result ?: false, self::CACHE_GROUP, self::CACHE_TTL );

		return $result ?: null;
	}

	/**
	 * Get a company profile by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function get_company_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Insert or update a company profile.
	 * When $company_id is provided the profile must already belong to the
	 * employer (ownership enforced); otherwise the single/default company is
	 * updated, or — when $force_create is true — a brand new company is
	 * created (multi-company support).
	 *
	 * @return int New/updated company ID (0 on ownership failure).
	 * @param int   $employer_id Employer user ID.
	 * @param array $data Company fields.
	 * @param int   $company_id Optional existing company ID to update.
	 * @param bool  $force_create Force creation of a new company row.
	 */
	public function save_company( int $employer_id, array $data, int $company_id = 0, bool $force_create = false ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';

		$data['employer_id'] = $employer_id;
		$data['updated_at']  = current_time( 'mysql' );

		if ( empty( $data['slug'] ) ) {
			$data['slug'] = sanitize_title( ( $data['name'] ?? '' ) . '-' . uniqid() );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $company_id ) {
			$owned = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND employer_id = %d", $company_id, $employer_id )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( ! $owned ) {
				return 0;
			}
			$wpdb->update( $table, $data, array( 'id' => $company_id ) );
			return (int) $company_id;
		}

		$existing = $this->get_company_by_employer( $employer_id );
		if ( $existing && ! $force_create ) {
			$wpdb->update( $table, $data, array( 'id' => $existing['id'] ) );
			return (int) $existing['id'];
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get all companies belonging to an employer (multi-company support).
	 *
	 * @return array List of company rows.
	 * @param int $employer_id Employer user ID.
	 */
	public function get_companies_by_employer( int $employer_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE employer_id = %d ORDER BY name ASC", $employer_id ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get published jobs shown on a company profile page.
	 * Prefers jobs explicitly assigned to the company; falls back to all of the
	 * employer's jobs for legacy data created before the company_id column.
	 *
	 * @return array
	 * @param int $company_id Company ID.
	 * @param int $limit Max jobs.
	 */
	public function get_jobs_by_company( int $company_id, int $limit = 50 ): array {
		global $wpdb;
		$table_jobs = $wpdb->prefix . 'zeko_jobs';
		$table_co   = $wpdb->prefix . 'zeko_companies';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.* FROM {$table_jobs} j
				LEFT JOIN {$table_co} c ON c.id = %d
				WHERE j.status = 'publish'
				  AND j.employer_id = c.employer_id
				  AND ( j.company_id = %d OR j.company_id IS NULL )
				ORDER BY j.created_at DESC
				LIMIT %d",
				$company_id,
				$company_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/*
	=========================================================================
	 * Employer blog (company posts)
	 * ======================================================================
	 */

	/**
	 * Insert a company blog post.
	 *
	 * @param array $data Data.
	 */
	public function insert_company_post( array $data ): int {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'zeko_company_posts',
			array(
				'company_id'  => (int) $data['company_id'],
				'employer_id' => (int) $data['employer_id'],
				'title'       => sanitize_text_field( $data['title'] ),
				'slug'        => sanitize_title( ( $data['slug'] ?? '' ) ?: ( $data['title'] . '-' . uniqid() ) ),
				'content'     => self::sanitize_rich( $data['content'] ),
				'status'      => sanitize_text_field( $data['status'] ?? 'publish' ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a company blog post (ownership enforced).
	 *
	 * @param int   $post_id Post id.
	 * @param int   $employer_id Employer id.
	 * @param array $data Data.
	 */
	public function update_company_post( int $post_id, int $employer_id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_company_posts';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND employer_id = %d", $post_id, $employer_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! $row ) {
			return false;
		}

		$clean = array(
			'title'      => sanitize_text_field( $data['title'] ),
			'content'    => self::sanitize_rich( $data['content'] ),
			'status'     => sanitize_text_field( $data['status'] ?? 'publish' ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( ! empty( $data['slug'] ) ) {
			$clean['slug'] = sanitize_title( $data['slug'] );
		}

		return false !== $wpdb->update( $table, $clean, array( 'id' => $post_id ), null, array( '%d' ) );
	}

	/**
	 * Delete a company blog post (ownership enforced).
	 *
	 * @param int $post_id Post id.
	 * @param int $employer_id Employer id.
	 */
	public function delete_company_post( int $post_id, int $employer_id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'zeko_company_posts',
			array(
				'id'          => $post_id,
				'employer_id' => $employer_id,
			),
			array( '%d', '%d' )
		);
		return (bool) $deleted;
	}

	/**
	 * Get published blog posts for a company (public).
	 *
	 * @param int $company_id Company id.
	 * @param int $limit Limit.
	 */
	public function get_company_posts( int $company_id, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_company_posts';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE company_id = %d AND status = 'publish' ORDER BY created_at DESC LIMIT %d",
				$company_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get a company post by ID (used by the dashboard).
	 *
	 * @param int $post_id Post id.
	 * @param int $employer_id Employer id.
	 */
	public function get_company_post( int $post_id, int $employer_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_company_posts';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND employer_id = %d", $post_id, $employer_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	/**
	 * List all blog posts an employer manages (dashboard).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_employer_company_posts( int $employer_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_company_posts';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.name AS company_name
				FROM {$table} p
				LEFT JOIN {$wpdb->prefix}zeko_companies c ON c.id = p.company_id
				WHERE p.employer_id = %d
				ORDER BY p.created_at DESC",
				$employer_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/*
	=========================================================================
	 * Message templates
	 * ======================================================================
	 */

	/**
	 * Insert a pre-written message template for an employer.
	 *
	 * @param int    $user_id User id.
	 * @param string $title Title.
	 * @param string $content Content.
	 */
	public function insert_message_template( int $user_id, string $title, string $content ): int {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'zeko_message_templates',
			array(
				'user_id'    => $user_id,
				'title'      => sanitize_text_field( $title ),
				'content'    => sanitize_textarea_field( $content ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a message template (ownership enforced).
	 *
	 * @param int    $template_id Template id.
	 * @param int    $user_id User id.
	 * @param string $title Title.
	 * @param string $content Content.
	 */
	public function update_message_template( int $template_id, int $user_id, string $title, string $content ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_message_templates';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND user_id = %d", $template_id, $user_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! $row ) {
			return false;
		}
		return false !== $wpdb->update(
			$table,
			array(
				'title'      => sanitize_text_field( $title ),
				'content'    => sanitize_textarea_field( $content ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $template_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a message template (ownership enforced).
	 *
	 * @param int $template_id Template id.
	 * @param int $user_id User id.
	 */
	public function delete_message_template( int $template_id, int $user_id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'zeko_message_templates',
			array(
				'id'      => $template_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
		return (bool) $deleted;
	}

	/**
	 * Get a user's message templates.
	 *
	 * @param int $user_id User id.
	 */
	public function get_message_templates( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_message_templates';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY title ASC",
				$user_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Search messages across every conversation the user participates in.
	 *
	 * @return array Rows with conversation_id, other user info, message snippet.
	 * @param int    $user_id User ID.
	 * @param string $term Search term.
	 * @param int    $limit Max results.
	 */
	public function search_messages( int $user_id, string $term, int $limit = 20 ): array {
		global $wpdb;
		$term = trim( $term );
		if ( '' === $term ) {
			return array();
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$msg  = $wpdb->prefix . 'zeko_messages';
		$conv = $wpdb->prefix . 'zeko_conversations';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.message_id, m.conversation_id, m.sender_id, m.recipient_id,
				        m.message_content, m.message_date,
				        other.display_name AS other_name
				FROM {$msg} m
				INNER JOIN {$conv} c
					ON c.conversation_id = m.conversation_id
					AND c.status = 'active'
					AND ( c.user1_id = %d OR c.user2_id = %d )
				INNER JOIN {$wpdb->users} other
					ON other.ID = CASE WHEN %d = c.user1_id THEN c.user2_id ELSE c.user1_id END
				WHERE m.message_content LIKE %s
				ORDER BY m.message_date DESC
				LIMIT %d",
				$user_id,
				$user_id,
				$user_id,
				$like,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/*
	=========================================================================
	 * Zeko-Learn integration (fail-soft when the Learn plugin is inactive)
	 * ======================================================================
	 */

	/**
	 * Whether the Zeko-Learn plugin is active and usable.
	 */
	private function learn_active(): bool {
		return function_exists( 'zeko_learn' )
			&& class_exists( 'Zeko_Learn_DB' )
			&& is_callable( array( zeko_learn(), 'get_db' ) );
	}

	/**
	 * Aggregate a user's skills from their completed Zeko-Learn courses.
	 * Skills are tagged at course level, so learner skills are the union of
	 * course skills across completed courses, merged with the user's own
	 * self-reported skills (usermeta zeko_skills).
	 *
	 * @return array List of skill names.
	 * @param int $user_id User ID.
	 */
	public function get_user_learn_skills( int $user_id ): array {
		$skills = get_user_meta( $user_id, 'zeko_skills', true );
		if ( ! is_array( $skills ) ) {
			$skills = array_filter( array_map( 'trim', explode( ',', (string) $skills ) ) );
		}
		$skills = array_values( array_filter( array_map( 'sanitize_text_field', (array) $skills ) ) );

		if ( ! $this->learn_active() ) {
			return $skills;
		}

		try {
			$learn_db  = zeko_learn()->get_db();
			$completed = $learn_db->get_user_enrolled_courses( $user_id, array( 'status' => 'completed' ) );
			foreach ( (array) $completed as $course ) {
				$course_id = (int) ( $course['course_id'] ?? $course['id'] ?? 0 );
				if ( ! $course_id ) {
					continue;
				}
				foreach ( (array) $learn_db->get_course_skills( $course_id ) as $skill ) {
					$name = sanitize_text_field( $skill['name'] ?? '' );
					if ( $name ) {
						$skills[] = $name;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return $skills; // phpcs:ignore Squiz.PHP.NonExecutableCode -- fail soft.
		}

		return array_values( array_unique( array_filter( $skills ) ) );
	}

	/**
	 * Get a user's Zeko-Learn certificates.
	 *
	 * @return array
	 * @param int $user_id User ID.
	 * @param int $limit Max certificates.
	 */
	public function get_user_learn_certificates( int $user_id, int $limit = 10 ): array {
		if ( ! $this->learn_active() ) {
			return array();
		}
		try {
			$learn_db = zeko_learn()->get_db();
			return (array) $learn_db->get_user_certificates( $user_id, $limit );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Parse a job's structured required skills (comma/array column).
	 *
	 * @param array $job Job.
	 */
	public function get_job_required_skills( array $job ): array {
		$raw = $job['required_skills'] ?? '';
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		}
		return array_values( array_filter( array_unique( $parts ) ) );
	}

	/**
	 * Match a user's skills against a job's required skills.
	 *
	 * @return array{total:int, matched:array, missing:array}
	 * @param int   $user_id User id.
	 * @param array $job Job.
	 */
	public function get_skills_match( int $user_id, array $job ): array {
		$required = $this->get_job_required_skills( $job );
		if ( empty( $required ) ) {
			return array(
				'total'   => 0,
				'matched' => array(),
				'missing' => array(),
			);
		}

		$user_skills = array_map( 'strtolower', $this->get_user_learn_skills( $user_id ) );
		$required_l  = array_map( 'strtolower', $required );

		$matched = array();
		$missing = array();
		foreach ( $required as $i => $skill ) {
			if ( in_array( $required_l[ $i ], $user_skills, true ) ) {
				$matched[] = $skill;
			} else {
				$missing[] = $skill;
			}
		}

		return array(
			'total'   => count( $required ),
			'matched' => $matched,
			'missing' => $missing,
		);
	}

	/**
	 * Recommend Zeko-Learn courses that teach the skills a user is missing for
	 * a given job ("take this course to qualify for more jobs").
	 *
	 * @return array Each item: course row + the skills it covers that the user lacks.
	 * @param int   $user_id User ID.
	 * @param array $job Job row.
	 * @param int   $limit Max course recommendations.
	 */
	public function recommend_courses_for_job( int $user_id, array $job, int $limit = 3 ): array {
		if ( ! $this->learn_active() ) {
			return array();
		}

		$match   = $this->get_skills_match( $user_id, $job );
		$missing = array_map( 'strtolower', $match['missing'] );
		if ( empty( $missing ) ) {
			return array();
		}

		$recommended = array();
		try {
			$learn_db = zeko_learn()->get_db();
			$courses  = $learn_db->get_courses(
				array(
					'status' => 'published',
					'limit'  => 100,
				)
			);
			foreach ( (array) $courses as $course ) {
				$course_id = (int) ( $course['id'] ?? 0 );
				if ( ! $course_id ) {
					continue;
				}
				$covered = array();
				foreach ( (array) $learn_db->get_course_skills( $course_id ) as $skill ) {
					$slug = strtolower( sanitize_text_field( $skill['name'] ?? '' ) );
					if ( $slug && in_array( $slug, $missing, true ) ) {
						$covered[] = $skill['name'];
					}
				}
				if ( ! empty( $covered ) ) {
					$recommended[] = array(
						'course' => $course,
						'covers' => $covered,
					);
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		usort(
			$recommended,
			static function ( $a, $b ) {
				return count( $b['covers'] ) <=> count( $a['covers'] );
			}
		);

		return array_slice( $recommended, 0, $limit );
	}

	/**
	 * Bulk required-skill counts for a set of jobs against one user.
	 * Used by job cards to render "You have X of Y required skills".
	 *
	 * @return array<int, array{total:int, matched:int}>
	 * @param int   $user_id User id.
	 * @param array $job_ids Job ids.
	 */
	public function get_bulk_skills_counts( int $user_id, array $job_ids ): array {
		if ( empty( $job_ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'zeko_jobs';
		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, required_skills FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$job_ids
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$user_skills = array_map( 'strtolower', $this->get_user_learn_skills( $user_id ) );
		$counts      = array();
		foreach ( $rows as $job ) {
			$required = $this->get_job_required_skills( $job );
			if ( empty( $required ) ) {
				$counts[ (int) $job['id'] ] = array(
					'total'   => 0,
					'matched' => 0,
				);
				continue;
			}
			$matched = 0;
			foreach ( $required as $skill ) {
				if ( in_array( strtolower( $skill ), $user_skills, true ) ) {
					++$matched;
				}
			}
			$counts[ (int) $job['id'] ] = array(
				'total'   => count( $required ),
				'matched' => $matched,
			);
		}
		return $counts;
	}

	/*
	=========================================================================
	 * Zeko-QA integration (fail-soft when the QA plugin is inactive)
	 * ======================================================================
	 */

	/**
	 * Whether the Zeko-QA tables are available.
	 */
	private function qa_tables_exist(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_questions';
		$check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return (bool) $check;
	}

	/**
	 * Fetch Q&A discussions relevant to a job, matched by keyword.
	 *
	 * @return array Rows with question_id, title, slug, answer_count, author name.
	 * @param array $job Job row.
	 * @param int   $limit Max questions.
	 */
	public function get_related_qa_questions( array $job, int $limit = 4 ): array {
		if ( ! $this->qa_tables_exist() ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_questions';
		$users = $wpdb->users;

		$terms = array_filter(
			array_map(
				'trim',
				array_merge(
					array( $job['title'] ?? '' ),
					$this->get_job_required_skills( $job )
				)
			)
		);
		if ( empty( $terms ) ) {
			return array();
		}

		$where  = array( 'q.status = %s' );
		$params = array( 'publish' );
		foreach ( array_slice( array_unique( $terms ), 0, 5 ) as $term ) {
			$where[]  = 'q.title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $term ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id AS question_id, q.title, q.slug, q.created_at,
				        ( SELECT COUNT(*) FROM {$wpdb->prefix}zeko_answers a WHERE a.question_id = q.id ) AS answer_count,
				        u.display_name AS author_name
				FROM {$table} q
				LEFT JOIN {$users} u ON u.ID = q.user_id
				WHERE " . implode( ' OR ', $where ) . '
				ORDER BY answer_count DESC, q.created_at DESC
				LIMIT %d',
				array_merge( $params, array( $limit ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Fetch "industry insight" questions — the most active Q&A around a job's
	 * category/industry keywords (e.g. "How to negotiate salary?").
	 *
	 * @return array
	 * @param array $job Job row.
	 * @param int   $limit Max questions.
	 */
	public function get_industry_insights( array $job, int $limit = 3 ): array {
		if ( ! $this->qa_tables_exist() ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_questions';

		$terms = array_filter(
			array_map(
				'trim',
				array(
					$job['category'] ?? '',
					$job['company_industry'] ?? '',
					$job['experience_level'] ?? '',
				)
			)
		);
		if ( empty( $terms ) ) {
			return array();
		}

		$where  = array( 'status = %s' );
		$params = array( 'publish' );
		foreach ( array_slice( array_unique( $terms ), 0, 5 ) as $term ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $term ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id AS question_id, q.title, q.slug,
				        ( SELECT COUNT(*) FROM {$wpdb->prefix}zeko_answers a WHERE a.question_id = q.id ) AS answer_count
				FROM {$table} q
				WHERE " . implode( ' OR ', $where ) . '
				ORDER BY answer_count DESC, q.created_at DESC
				LIMIT %d',
				array_merge( $params, array( $limit ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get company by ID.
	 *
	 * @param int $company_id Company id.
	 */
	public function get_company( int $company_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $company_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * List all companies (for directory).
	 *
	 * @param array $args Args.
	 */
	public function get_companies( array $args = array() ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_companies';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['industry'] ) ) {
			$where   .= ' AND industry = %s';
			$params[] = $args['industry'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (name LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql      = "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * List featured (premium) companies for the homepage section.
	 * Each row is enriched with the company's open (publish) job count so the
	 * front end can show hiring activity.
	 *
	 * @return array
	 * @param int $limit Max featured companies to return.
	 */
	public function get_featured_companies( int $limit = 6 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';
		$jobs  = $wpdb->prefix . 'zeko_jobs';
		$limit = max( 1, min( 20, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, COALESCE(jobs.open_jobs, 0) AS open_jobs
				FROM {$table} c
				LEFT JOIN (
					SELECT employer_id, COUNT(*) AS open_jobs
					FROM {$jobs}
					WHERE status = 'publish'
					GROUP BY employer_id
				) jobs ON jobs.employer_id = c.employer_id
				WHERE c.is_featured = 1
				ORDER BY c.name ASC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Set a company's featured flag (admin premium-highlight toggle).
	 *
	 * @param int  $company_id Company id.
	 * @param bool $featured Featured.
	 */
	public function set_company_featured( int $company_id, bool $featured ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_companies';

		$updated = $wpdb->update(
			$table,
			array( 'is_featured' => $featured ? 1 : 0 ),
			array( 'id' => $company_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Check whether a user follows a company (by employer ID).
	 *
	 * @param int $user_id User id.
	 * @param int $employer_id Employer id.
	 */
	public function is_following_company( int $user_id, int $employer_id ): bool {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zeko_company_follows WHERE user_id = %d AND employer_id = %d",
				$user_id,
				$employer_id
			)
		);
		return (int) $count > 0;
	}

	/**
	 * Follow a company (idempotent — duplicate follow returns false).
	 *
	 * @param int $user_id User id.
	 * @param int $employer_id Employer id.
	 */
	public function add_company_follower( int $user_id, int $employer_id ): bool {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}zeko_company_follows (user_id, employer_id, created_at) VALUES (%d, %d, %s)",
				$user_id,
				$employer_id,
				current_time( 'mysql' )
			)
		);
		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Unfollow a company.
	 *
	 * @param int $user_id User id.
	 * @param int $employer_id Employer id.
	 */
	public function remove_company_follower( int $user_id, int $employer_id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'zeko_company_follows',
			array(
				'user_id'     => $user_id,
				'employer_id' => $employer_id,
			),
			array( '%d', '%d' )
		);
		return false !== $deleted;
	}

	/**
	 * Count followers for a company.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function count_company_followers( int $employer_id ): int {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zeko_company_follows WHERE employer_id = %d",
				$employer_id
			)
		);
		return (int) $count;
	}

	/**
	 * Get all user IDs following a company (for new-job notifications).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_company_follower_ids( int $employer_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}zeko_company_follows WHERE employer_id = %d",
				$employer_id
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Notify all followers of a company that a new job was posted.
	 *
	 * @return int Number of notifications created.
	 * @param int    $employer_id Employer id.
	 * @param int    $job_id Job id.
	 * @param string $job_title Job title.
	 * @param string $company_name Company name.
	 */
	public function notify_company_followers( int $employer_id, int $job_id, string $job_title, string $company_name ): int {
		$follower_ids = $this->get_company_follower_ids( $employer_id );
		if ( empty( $follower_ids ) ) {
			return 0;
		}

		$sent    = 0;
		$job     = $this->get_job( $job_id );
		$job_url = $job ? home_url( '/jobs/' . $job['slug'] ) : '';
		foreach ( $follower_ids as $follower_id ) {
			$this->insert_notification(
				$follower_id,
				'company_new_job',
				/* translators: %s: company name */
				sprintf( __( 'New job at %s', 'zeko-jobs' ), $company_name ),
				/* translators: 1: job title, 2: company name */
				sprintf( __( '%1$s posted "%2$s" — check it out!', 'zeko-jobs' ), $company_name, $job_title ),
				$job_url,
				'dashicons-building'
			);
			++$sent;
		}

		return $sent;
	}

	/**
	 * Search seeker resumes (opt-in only).
	 * Only returns users who have explicitly enabled profile visibility
	 * (`zeko_profile_visible = 1`) and are open to work (`zeko_open_to_work = 1`).
	 *
	 * @param array $args Optional. Supports `keyword`, `skills`, `location`, `limit`.
	 */
	public function search_resumes( array $args = array() ): array {
		global $wpdb;

		$limit    = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
		$keyword  = isset( $args['keyword'] ) ? sanitize_text_field( $args['keyword'] ) : '';
		$skills   = isset( $args['skills'] ) ? sanitize_text_field( $args['skills'] ) : '';
		$location = isset( $args['location'] ) ? sanitize_text_field( $args['location'] ) : '';

		$where  = '1=1';
		$params = array();

		if ( '' !== $keyword ) {
			$like     = '%' . $wpdb->esc_like( $keyword ) . '%';
			$where   .= ' AND (u.display_name LIKE %s OR um_bio.meta_value LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $skills ) {
			$like     = '%' . $wpdb->esc_like( $skills ) . '%';
			$where   .= ' AND um_skills.meta_value LIKE %s';
			$params[] = $like;
		}
		if ( '' !== $location ) {
			$like     = '%' . $wpdb->esc_like( $location ) . '%';
			$where   .= ' AND um_loc.meta_value LIKE %s';
			$params[] = $like;
		}

		$sql = "SELECT u.ID, u.display_name,
				um_skills.meta_value AS skills,
				um_loc.meta_value AS location,
				um_bio.meta_value AS bio,
				um_resume.meta_value AS resume_url,
				um_default.meta_value AS default_resume_id
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} um_vis ON um_vis.user_id = u.ID AND um_vis.meta_key = 'zeko_profile_visible' AND um_vis.meta_value = '1'
			INNER JOIN {$wpdb->usermeta} um_otw ON um_otw.user_id = u.ID AND um_otw.meta_key = 'zeko_open_to_work' AND um_otw.meta_value = '1'
			LEFT JOIN {$wpdb->usermeta} um_skills ON um_skills.user_id = u.ID AND um_skills.meta_key = 'zeko_skills'
			LEFT JOIN {$wpdb->usermeta} um_loc ON um_loc.user_id = u.ID AND um_loc.meta_key = 'zeko_location'
			LEFT JOIN {$wpdb->usermeta} um_bio ON um_bio.user_id = u.ID AND um_bio.meta_key = 'zeko_bio'
			LEFT JOIN {$wpdb->usermeta} um_resume ON um_resume.user_id = u.ID AND um_resume.meta_key = 'zeko_resume'
			LEFT JOIN {$wpdb->usermeta} um_default ON um_default.user_id = u.ID AND um_default.meta_key = 'zeko_default_resume_id'
			WHERE {$where}
			ORDER BY u.display_name ASC
			LIMIT %d";

		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return array_map(
			function ( $row ) {
				$row['ID']     = (int) $row['ID'];
				$row['skills'] = maybe_unserialize( $row['skills'] );
				if ( is_array( $row['skills'] ) ) {
						$row['skills'] = array_values( array_filter( array_map( 'trim', $row['skills'] ) ) );
				} else {
					$row['skills'] = $row['skills'] ? array_map( 'trim', explode( ',', (string) $row['skills'] ) ) : array();
				}
				return $row;
			},
			$rows
		);
	}

	/**
	 * Get employer average response time in hours (first status change after application).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_employer_response_time( int $employer_id ): ?float {
		$cache_key = 'resp_time_' . $employer_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		global $wpdb;
		$jobs_table = $wpdb->prefix . 'zeko_jobs';
		$apps_table = $wpdb->prefix . 'zeko_job_applications';
		$hist_table = $wpdb->prefix . 'zeko_job_application_history';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(HOUR, a.applied_at, h.created_at))
				FROM {$hist_table} h
				INNER JOIN {$apps_table} a ON a.id = h.application_id
				INNER JOIN {$jobs_table} j ON j.id = a.job_id
				WHERE j.employer_id = %d
				AND h.id = (
					SELECT h2.id FROM {$hist_table} h2
					WHERE h2.application_id = h.application_id
					ORDER BY h2.created_at ASC LIMIT 1
				)",
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$value = $result ? (float) $result : null;
		wp_cache_set( $cache_key, $value ?? false, self::CACHE_GROUP, self::CACHE_TTL );

		return $value;
	}

	/**
	 * Get employer response time as human-readable string.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_employer_response_time_label( int $employer_id ): string {
		$hours = $this->get_employer_response_time( $employer_id );
		if ( null === $hours || $hours <= 0 ) {
			return __( 'New employer', 'zeko-jobs' );
		}
		if ( $hours < 1 ) {
			return __( 'Responds within an hour', 'zeko-jobs' );
		}
		if ( $hours < 24 ) {
			return sprintf(
				/* translators: %d: number of hours */
				_n( 'Responds within %d hour', 'Responds within %d hours', (int) round( $hours ), 'zeko-jobs' ),
				round( $hours )
			);
		}
		$days = round( $hours / 24 );
		return sprintf(
			/* translators: %d: number of days */
			_n( 'Responds within %d day', 'Responds within %d days', $days, 'zeko-jobs' ),
			$days
		);
	}

	/**
	 * Get analytics summary for an employer's jobs.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_employer_analytics( int $employer_id ): array {
		global $wpdb;
		$jobs_table = $wpdb->prefix . 'zeko_jobs';
		$apps_table = $wpdb->prefix . 'zeko_job_applications';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_jobs,
					COALESCE(SUM(j.views), 0) AS total_views,
					COALESCE(AVG(j.views), 0) AS avg_views
				FROM {$jobs_table} j
				WHERE j.employer_id = %d AND j.status IN ('publish', 'paused', 'closed')",
				$employer_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_applications,
					COALESCE(SUM(CASE WHEN app.status = 'hired' THEN 1 ELSE 0 END), 0) AS hired_count,
					COALESCE(SUM(CASE WHEN app.status = 'interviewing' THEN 1 ELSE 0 END), 0) AS interviewing_count
				FROM {$apps_table} app
				INNER JOIN {$jobs_table} j ON j.id = app.job_id
				WHERE j.employer_id = %d",
				$employer_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$top_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.id, j.title, j.slug, j.views,
					(SELECT COUNT(*) FROM {$apps_table} a WHERE a.job_id = j.id) AS app_count
				FROM {$jobs_table} j
				WHERE j.employer_id = %d AND j.status IN ('publish', 'paused', 'closed')
				ORDER BY j.views DESC
				LIMIT 5",
				$employer_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$time_to_fill = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(DATEDIFF(app.updated_at, j.created_at))
				FROM {$apps_table} app
				INNER JOIN {$jobs_table} j ON j.id = app.job_id
				WHERE j.employer_id = %d AND app.status = 'hired' AND app.updated_at IS NOT NULL",
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$total_views = (int) ( $stats['total_views'] ?? 0 );
		$total_apps  = (int) ( $app_stats['total_applications'] ?? 0 );
		$conversion  = $total_views > 0 ? round( ( $total_apps / $total_views ) * 100, 1 ) : 0;

		return array(
			'total_jobs'         => (int) ( $stats['total_jobs'] ?? 0 ),
			'total_views'        => $total_views,
			'avg_views'          => (int) round( (float) ( $stats['avg_views'] ?? 0 ) ),
			'total_applications' => $total_apps,
			'conversion_rate'    => $conversion,
			'hired_count'        => (int) ( $app_stats['hired_count'] ?? 0 ),
			'interviewing_count' => (int) ( $app_stats['interviewing_count'] ?? 0 ),
			'time_to_fill'       => null !== $time_to_fill ? (int) round( (float) $time_to_fill ) : null,
			'top_jobs'           => $top_jobs,
		);
	}

	/**
	 * Get per-job analytics: views, unique visitors, applications, conversion, time series.
	 *
	 * @param int $job_id Job id.
	 */
	public function get_job_analytics( int $job_id ): array {
		global $wpdb;
		$jobs_table = $wpdb->prefix . 'zeko_jobs';
		$apps_table = $wpdb->prefix . 'zeko_job_applications';
		$views_log  = $wpdb->prefix . 'zeko_job_views_log';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, title, slug, views, salary_min, salary_max, created_at FROM {$jobs_table} WHERE id = %d", $job_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( ! $job ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$unique_visitors = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT visitor_ip) FROM {$views_log} WHERE job_id = %d AND visitor_ip IS NOT NULL AND visitor_ip != ''", $job_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) AS applied,
					SUM(CASE WHEN status = 'reviewed' OR status = 'awaiting_review' THEN 1 ELSE 0 END) AS reviewed,
					SUM(CASE WHEN status = 'interviewing' THEN 1 ELSE 0 END) AS interviewing,
					SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) AS hired,
					SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
				FROM {$apps_table} WHERE job_id = %d",
				$job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$total_views     = (int) $job['views'];
		$total_apps      = (int) ( $app_stats['total'] ?? 0 );
		$conversion_rate = $total_views > 0 ? round( ( $total_apps / $total_views ) * 100, 1 ) : 0;

		return array(
			'job'                => $job,
			'total_views'        => $total_views,
			'unique_visitors'    => $unique_visitors,
			'total_applications' => $total_apps,
			'conversion_rate'    => $conversion_rate,
			'status_breakdown'   => array(
				'applied'      => (int) ( $app_stats['applied'] ?? 0 ),
				'reviewed'     => (int) ( $app_stats['reviewed'] ?? 0 ),
				'interviewing' => (int) ( $app_stats['interviewing'] ?? 0 ),
				'hired'        => (int) ( $app_stats['hired'] ?? 0 ),
				'rejected'     => (int) ( $app_stats['rejected'] ?? 0 ),
			),
			'created_at'         => $job['created_at'],
		);
	}

	/**
	 * Get daily views time series for a job.
	 *
	 * @param int $job_id Job id.
	 * @param int $days Days.
	 */
	public function get_job_views_time_series( int $job_id, int $days = 30 ): array {
		global $wpdb;
		$views_log = $wpdb->prefix . 'zeko_job_views_log';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(viewed_at) AS date, COUNT(*) AS views, COUNT(DISTINCT visitor_ip) AS unique_visitors
				FROM {$views_log}
				WHERE job_id = %d AND viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY DATE(viewed_at)
				ORDER BY date ASC",
				$job_id,
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get comparison analytics: this period vs previous period.
	 *
	 * @param int    $employer_id Employer id.
	 * @param string $period Period.
	 */
	public function get_analytics_comparison( int $employer_id, string $period = 'week' ): array {
		global $wpdb;
		$jobs_table = $wpdb->prefix . 'zeko_jobs';
		$apps_table = $wpdb->prefix . 'zeko_job_applications';
		$views_log  = $wpdb->prefix . 'zeko_job_views_log';

		$interval = 'month' === $period ? 30 : 7;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT v.job_id) AS jobs_viewed,
					COUNT(*) AS total_views,
					COUNT(DISTINCT v.visitor_ip) AS unique_visitors
				FROM {$views_log} v
				INNER JOIN {$jobs_table} j ON j.id = v.job_id
				WHERE j.employer_id = %d AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$employer_id,
				$interval
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$previous = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT v.job_id) AS jobs_viewed,
					COUNT(*) AS total_views,
					COUNT(DISTINCT v.visitor_ip) AS unique_visitors
				FROM {$views_log} v
				INNER JOIN {$jobs_table} j ON j.id = v.job_id
				WHERE j.employer_id = %d AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND v.viewed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$employer_id,
				$interval * 2,
				$interval
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app_current = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$apps_table} a
				INNER JOIN {$jobs_table} j ON j.id = a.job_id
				WHERE j.employer_id = %d AND a.applied_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$employer_id,
				$interval
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$app_previous = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$apps_table} a
				INNER JOIN {$jobs_table} j ON j.id = a.job_id
				WHERE j.employer_id = %d AND a.applied_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND a.applied_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$employer_id,
				$interval * 2,
				$interval
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return array(
			'period'   => $period,
			'current'  => array(
				'views'           => (int) ( $current['total_views'] ?? 0 ),
				'unique_visitors' => (int) ( $current['unique_visitors'] ?? 0 ),
				'jobs_viewed'     => (int) ( $current['jobs_viewed'] ?? 0 ),
				'applications'    => $app_current,
			),
			'previous' => array(
				'views'           => (int) ( $previous['total_views'] ?? 0 ),
				'unique_visitors' => (int) ( $previous['unique_visitors'] ?? 0 ),
				'jobs_viewed'     => (int) ( $previous['jobs_viewed'] ?? 0 ),
				'applications'    => $app_previous,
			),
		);
	}

	/**
	 * Find potential duplicate jobs by same employer.
	 *
	 * @param int    $employer_id Employer id.
	 * @param string $title Title.
	 * @param string $location Location.
	 * @param string $type Type.
	 */
	public function find_duplicate_jobs( int $employer_id, string $title, string $location, string $type ): array {
		global $wpdb;
		$table      = $wpdb->prefix . 'zeko_jobs';
		$like_title = '%' . $wpdb->esc_like( $title ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, location, type, created_at
				FROM {$table}
				WHERE employer_id = %d
				AND id != %d
				AND status IN ('publish', 'draft', 'paused')
				AND (title LIKE %s OR (location = %s AND type = %s))
				ORDER BY created_at DESC
				LIMIT 5",
				$employer_id,
				0,
				$like_title,
				$location,
				$type
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Check if user has previously applied to jobs by this employer.
	 *
	 * @param int $seeker_id Seeker id.
	 * @param int $employer_id Employer id.
	 */
	public function has_applied_to_employer( int $seeker_id, int $employer_id ): bool {
		global $wpdb;
		$apps_table = $wpdb->prefix . 'zeko_job_applications';
		$jobs_table = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$apps_table} a
				INNER JOIN {$jobs_table} j ON j.id = a.job_id
				WHERE a.seeker_id = %d AND j.employer_id = %d",
				$seeker_id,
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return (int) $count > 0;
	}

	/**
	 * Get employer email templates from user meta.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_email_templates( int $employer_id ): array {
		$templates = get_user_meta( $employer_id, 'zeko_email_templates', true );
		if ( ! is_array( $templates ) ) {
			return $this->get_default_email_templates();
		}
		return $templates;
	}

	/**
	 * Save employer email template.
	 *
	 * @param int    $employer_id Employer id.
	 * @param string $key Key.
	 * @param array  $template Template.
	 */
	public function save_email_template( int $employer_id, string $key, array $template ): void {
		$templates         = $this->get_email_templates( $employer_id );
		$templates[ $key ] = $template;
		update_user_meta( $employer_id, 'zeko_email_templates', $templates );
	}

	/**
	 * Delete employer email template.
	 *
	 * @param int    $employer_id Employer id.
	 * @param string $key Key.
	 */
	public function delete_email_template( int $employer_id, string $key ): void {
		$templates = $this->get_email_templates( $employer_id );
		unset( $templates[ $key ] );
		update_user_meta( $employer_id, 'zeko_email_templates', $templates );
	}

	/**
	 * Get default email templates.
	 */
	private function get_default_email_templates(): array {
		return array(
			'thank_you'          => array(
				'name'    => __( 'Thank You for Applying', 'zeko-jobs' ),
				'subject' => __( 'Thank you for your application', 'zeko-jobs' ),
				'body'    => __( "Hi {seeker_name},\n\nThank you for applying for the {job_title} position. We have received your application and will review it shortly.\n\nBest regards,\n{employer_name}", 'zeko-jobs' ),
			),
			'schedule_interview' => array(
				'name'    => __( 'Schedule Interview', 'zeko-jobs' ),
				'subject' => __( 'Interview invitation for {job_title}', 'zeko-jobs' ),
				'body'    => __( "Hi {seeker_name},\n\nWe were impressed by your application for {job_title} and would like to invite you for an interview.\n\nPlease let us know your availability for next week.\n\nBest regards,\n{employer_name}", 'zeko-jobs' ),
			),
			'rejection'          => array(
				'name'    => __( 'Rejection', 'zeko-jobs' ),
				'subject' => __( 'Update on your application for {job_title}', 'zeko-jobs' ),
				'body'    => __( "Hi {seeker_name},\n\nThank you for your interest in the {job_title} position. After careful consideration, we have decided to move forward with other candidates.\n\nWe wish you the best in your job search.\n\nBest regards,\n{employer_name}", 'zeko-jobs' ),
			),
			'offer'              => array(
				'name'    => __( 'Job Offer', 'zeko-jobs' ),
				'subject' => __( 'Job offer for {job_title}', 'zeko-jobs' ),
				'body'    => __( "Hi {seeker_name},\n\nWe are pleased to offer you the {job_title} position. We believe your skills and experience would be a great addition to our team.\n\nPlease find the offer details attached. We look forward to hearing from you.\n\nBest regards,\n{employer_name}", 'zeko-jobs' ),
			),
		);
	}

	/**
	 * Send bulk emails to applicants.
	 *
	 * @param int    $employer_id Employer id.
	 * @param array  $application_ids Application ids.
	 * @param string $subject Subject.
	 * @param string $body Body.
	 */
	public function send_bulk_application_emails( int $employer_id, array $application_ids, string $subject, string $body ): array {
		$results       = array(
			'sent'   => 0,
			'failed' => 0,
		);
		$employer      = get_userdata( $employer_id );
		$employer_name = $employer ? $employer->display_name : '';
		$applications  = $this->get_applications_by_ids( $application_ids );
		$renderer      = Zeko_Jobs_Email_Renderer::get_instance();

		foreach ( $applications as $app ) {
			if ( (int) $app['employer_id'] !== $employer_id ) {
				++$results['failed'];
				continue;
			}

			$seeker = get_userdata( (int) $app['seeker_id'] );
			if ( ! $seeker ) {
				++$results['failed'];
				continue;
			}

			$search  = array( '{seeker_name}', '{job_title}', '{employer_name}', '{application_date}' );
			$replace = array(
				$seeker->display_name,
				$app['job_title'] ?? '',
				$employer_name,
				date_i18n( get_option( 'date_format' ), strtotime( $app['applied_at'] ) ),
			);

			$final_subject = str_replace( $search, $replace, $subject );
			$final_body    = str_replace( $search, $replace, $body );
			$html          = '<div style="white-space:pre-wrap;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;line-height:1.6;color:#475569;">' . nl2br( esc_html( $final_body ) ) . '</div>';

			$sent = $renderer->send_styled( $seeker->user_email, $final_subject, $html );

			if ( $sent ) {
				++$results['sent'];
			} else {
				++$results['failed'];
			}
		}

		return $results;
	}

	/**
	 * Get applications by IDs with job data.
	 *
	 * @param array $ids Ids.
	 */
	private function get_applications_by_ids( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$apps_table   = $wpdb->prefix . 'zeko_job_applications';
		$jobs_table   = $wpdb->prefix . 'zeko_jobs';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, j.title AS job_title, j.employer_id
				FROM {$apps_table} a
				INNER JOIN {$jobs_table} j ON j.id = a.job_id
				WHERE a.id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				...$ids
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get employer's custom pipeline stages (merges with defaults).
	 *
	 * @return array Associative array of slug => label.
	 * @param int $employer_id Employer user ID.
	 */
	public function get_employer_pipeline_stages( int $employer_id ): array {
		$defaults = array(
			'awaiting_review' => __( 'Awaiting Review', 'zeko-jobs' ),
			'reviewed'        => __( 'Reviewed', 'zeko-jobs' ),
			'contacting'      => __( 'Contacting', 'zeko-jobs' ),
			'interviewing'    => __( 'Interviewing', 'zeko-jobs' ),
			'offered'         => __( 'Offered', 'zeko-jobs' ),
			'hired'           => __( 'Hired', 'zeko-jobs' ),
		);

		$custom = get_user_meta( $employer_id, 'zeko_custom_pipeline_stages', true );
		if ( ! is_array( $custom ) || empty( $custom ) ) {
			return $defaults;
		}

		// Custom stages replace defaults if provided.
		$merged = array();
		foreach ( $custom as $stage ) {
			if ( empty( $stage['slug'] ) || empty( $stage['label'] ) ) {
				continue;
			}
			$merged[ sanitize_title( $stage['slug'] ) ] = sanitize_text_field( $stage['label'] );
		}

		return ! empty( $merged ) ? $merged : $defaults;
	}

	/**
	 * Save employer's custom pipeline stages.
	 *
	 * @return bool
	 * @param int   $employer_id Employer user ID.
	 * @param array $stages Array of [ ['slug' => '...', 'label' => '...'], ... ].
	 */
	public function save_employer_pipeline_stages( int $employer_id, array $stages ): bool {
		$clean = array();
		foreach ( $stages as $stage ) {
			if ( empty( $stage['slug'] ) || empty( $stage['label'] ) ) {
				continue;
			}
			$clean[] = array(
				'slug'  => sanitize_title( $stage['slug'] ),
				'label' => sanitize_text_field( $stage['label'] ),
			);
		}
		return (bool) update_user_meta( $employer_id, 'zeko_custom_pipeline_stages', $clean );
	}

	/**
	 * Get default pipeline colors.
	 *
	 * @return array
	 */
	public function get_pipeline_colors(): array {
		return array(
			'awaiting_review' => '#6366f1',
			'reviewed'        => '#8b5cf6',
			'contacting'      => '#3b82f6',
			'interviewing'    => '#f59e0b',
			'offered'         => '#10b981',
			'hired'           => '#22c55e',
		);
	}

	/**
	 * Calculate profile match percentage between a seeker and a job.
	 *
	 * @return array { score: int (0-100), breakdown: array }
	 * @param int   $user_id Seeker user ID.
	 * @param array $job_data Job data row.
	 */
	public function calculate_profile_match( int $user_id, array $job_data ): array {
		$breakdown = array();
		$score     = 0;

		$seeker_skills   = get_user_meta( $user_id, 'zeko_skills', true );
		$seeker_exp      = get_user_meta( $user_id, 'zeko_work_experience', true );
		$seeker_location = get_user_meta( $user_id, 'zeko_location', true );
		$seeker_edu      = get_user_meta( $user_id, 'zeko_education', true );

		if ( ! is_array( $seeker_skills ) ) {
			$seeker_skills = array_filter( array_map( 'trim', explode( ',', (string) $seeker_skills ) ) );
		}
		if ( ! is_array( $seeker_exp ) ) {
			$seeker_exp = array();
		}
		if ( ! is_array( $seeker_edu ) ) {
			$seeker_edu = array();
		}

		// 1. Skills match (40%) — match seeker skills against job keywords.
		$job_text    = strtolower(
			implode(
				' ',
				array_filter(
					array(
						$job_data['title'] ?? '',
						$job_data['requirements'] ?? '',
						$job_data['qualifications'] ?? '',
						$job_data['description'] ?? '',
					)
				)
			)
		);
		$seeker_text = strtolower( implode( ' ', $seeker_skills ) );

		$matched_skills = array();
		$total_skills   = count( $seeker_skills );
		foreach ( $seeker_skills as $skill ) {
			$sanitized = strtolower( trim( $skill ) );
			if ( strlen( $sanitized ) < 2 ) {
				continue;
			}
			// Match if skill appears in job text (word boundary or substring).
			if ( preg_match( '/\b' . preg_quote( $sanitized, '/' ) . '\b/i', $job_text )
				|| stripos( $job_text, $sanitized ) !== false ) {
				$matched_skills[] = $skill;
			}
		}

		$skills_pct          = $total_skills > 0 ? (int) round( ( count( $matched_skills ) / $total_skills ) * 100 ) : 0;
		$score              += (int) round( $skills_pct * 0.4 );
		$breakdown['skills'] = array(
			'pct'     => $skills_pct,
			'matched' => $matched_skills,
			'total'   => $total_skills,
		);

		// 2. Experience level match (20%).
		$job_exp     = strtolower( $job_data['experience_level'] ?? '' );
		$total_years = 0;
		foreach ( $seeker_exp as $exp ) {
			if ( ! empty( $exp['years'] ) ) {
				$total_years += (int) $exp['years'];
			} elseif ( ! empty( $exp['start_date'] ) ) {
				$start = strtotime( $exp['start_date'] );
				$end   = ! empty( $exp['end_date'] ) ? strtotime( $exp['end_date'] ) : time();
				if ( $start && $end && $end > $start ) {
					$total_years += (int) round( ( $end - $start ) / ( 365.25 * 86400 ) );
				}
			}
		}

		$exp_levels = array(
			'entry'     => array( 0, 2 ),
			'mid'       => array( 2, 5 ),
			'senior'    => array( 5, 10 ),
			'lead'      => array( 8, 15 ),
			'executive' => array( 10, 99 ),
		);
		$exp_pct    = 50; // default.
		if ( $job_exp && isset( $exp_levels[ $job_exp ] ) ) {
			[ $min, $max ] = $exp_levels[ $job_exp ];
			if ( $total_years >= $min && $total_years <= $max ) {
				$exp_pct = 100;
			} elseif ( $total_years < $min ) {
				$exp_pct = max( 0, (int) round( ( $total_years / max( $min, 1 ) ) * 100 ) );
			} else {
				$exp_pct = max( 0, 100 - (int) round( ( ( $total_years - $max ) / max( $max, 1 ) ) * 50 ) );
			}
		}
		$score                  += (int) round( $exp_pct * 0.2 );
		$breakdown['experience'] = array(
			'pct'   => $exp_pct,
			'years' => $total_years,
		);

		// 3. Employment type match (15%).
		$job_type = strtolower( $job_data['type'] ?? '' );
		$type_pct = 100;
		if ( $job_type ) {
			// No explicit seeker type preference — default full match.
			$type_pct = 100;
		}
		$score            += (int) round( $type_pct * 0.15 );
		$breakdown['type'] = array( 'pct' => $type_pct );

		// 4. Location match (15%).
		$job_location = strtolower( $job_data['location'] ?? '' );
		$loc_pct      = 50;
		if ( ! empty( $seeker_location ) && $job_location ) {
			$seeker_loc = strtolower( $seeker_location );
			// Check same city.
			if ( $seeker_loc === $job_location ) {
				$loc_pct = 100;
			} elseif ( strpos( $job_location, $seeker_loc ) !== false || strpos( $seeker_loc, $job_location ) !== false ) {
				$loc_pct = 80;
			} else {
				$parts_job    = array_map( 'trim', explode( ',', $job_location ) );
				$parts_seeker = array_map( 'trim', explode( ',', $seeker_loc ) );
				if ( count( array_intersect( $parts_job, $parts_seeker ) ) > 0 ) {
					$loc_pct = 80;
				}
			}
		}
		$remote_opt = strtolower( $job_data['remote_option'] ?? '' );
		if ( 'remote' === $remote_opt ) {
			$loc_pct = max( $loc_pct, 100 );
		}
		$score                += (int) round( $loc_pct * 0.15 );
		$breakdown['location'] = array( 'pct' => $loc_pct );

		// 5. Education match (10%).
		$edu_pct = 50;
		if ( ! empty( $seeker_edu ) ) {
			$edu_pct      = 80;
			$job_edu_text = strtolower(
				implode(
					' ',
					array_filter(
						array(
							$job_data['requirements'] ?? '',
							$job_data['qualifications'] ?? '',
						)
					)
				)
			);
			$has_relevant = false;
			foreach ( $seeker_edu as $edu ) {
				$degree = strtolower( $edu['degree'] ?? '' );
				$field  = strtolower( $edu['field'] ?? '' );
				if ( $degree && strpos( $job_edu_text, $degree ) !== false ) {
					$has_relevant = true;
				}
				if ( $field && strpos( $job_edu_text, $field ) !== false ) {
					$has_relevant = true;
				}
			}
			if ( $has_relevant ) {
				$edu_pct = 100;
			}
		}
		$score                 += (int) round( $edu_pct * 0.1 );
		$breakdown['education'] = array( 'pct' => $edu_pct );

		$score = min( 100, max( 0, $score ) );

		return array(
			'score'     => $score,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Get match scores for a list of jobs against a seeker's profile.
	 *
	 * @return array<int, int> Map of job_id => match score (0–100).
	 * @param int   $user_id Seeker user ID.
	 * @param array $job_ids Array of job IDs to score.
	 */
	public function get_bulk_match_scores( int $user_id, array $job_ids ): array {
		if ( empty( $job_ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'zeko_jobs';
		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$job_ids
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$scores = array();
		foreach ( $rows as $job ) {
			$match                      = $this->calculate_profile_match( $user_id, $job );
			$scores[ (int) $job['id'] ] = $match['score'];
		}
		return $scores;
	}

	/**
	 * Log an email open event.
	 *
	 * @return bool
	 * @param string $tracking_id Unique tracking identifier.
	 * @param ?int   $application_id Application ID (if applicable).
	 * @param ?int   $recipient_id Recipient user ID.
	 * @param string $email_type Email type label.
	 */
	public function log_email_open( string $tracking_id, ?int $application_id, ?int $recipient_id, string $email_type ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_email_opens';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Only record first open per tracking_id.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_id = %s", $tracking_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $exists ) {
			return false;
		}
		$result = $wpdb->insert(
			$table,
			array(
				'tracking_id'    => sanitize_text_field( $tracking_id ),
				'application_id' => $application_id,
				'recipient_id'   => $recipient_id,
				'email_type'     => sanitize_text_field( $email_type ),
				'opened_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
		return (bool) $result;
	}

	/**
	 * Check if an email with the given tracking_id has been opened.
	 *
	 * @return bool
	 * @param string $tracking_id Tracking identifier.
	 */
	public function is_email_opened( string $tracking_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_email_opens';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_id = %s", $tracking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get email open status for an application.
	 *
	 * @return array Keyed by email_type => ['opened' => bool, 'opened_at' => string|null].
	 * @param int $application_id Application ID.
	 */
	public function get_application_email_opens( int $application_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_email_opens';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT email_type, opened_at FROM {$table} WHERE application_id = %d ORDER BY opened_at ASC",
				$application_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$opens = array();
		foreach ( $results as $row ) {
			$opens[ $row['email_type'] ] = array(
				'opened'    => true,
				'opened_at' => $row['opened_at'],
			);
		}
		return $opens;
	}

	/**
	 * Analyze application timing patterns to suggest the best time to apply.
	 *
	 * @return array { day: string, hour: string, message: string }
	 */
	public function get_best_time_to_apply(): array {
		global $wpdb;
		$apps_table = $wpdb->prefix . 'zeko_job_applications';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = $wpdb->get_results(
			"SELECT DAYOFWEEK(applied_at) AS dow, HOUR(applied_at) AS hr, COUNT(*) AS cnt
			FROM {$apps_table}
			WHERE status NOT IN ('pending', 'awaiting_review', 'rejected', 'archived')
			AND applied_at > DATE_SUB(NOW(), INTERVAL 6 MONTH)
			GROUP BY dow, hr
			ORDER BY cnt DESC
			LIMIT 1",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( empty( $results ) ) {
			return array(
				'day'     => '',
				'hour'    => '',
				'message' => '',
			);
		}

		$day_names = array(
			1 => 'Sunday',
			2 => 'Monday',
			3 => 'Tuesday',
			4 => 'Wednesday',
			5 => 'Thursday',
			6 => 'Friday',
			7 => 'Saturday',
		);
		$dow       = (int) $results[0]['dow'];
		$hr        = (int) $results[0]['hr'];
		$day_name  = $day_names[ $dow ] ?? '';
		$hour_str  = sprintf( '%d:00 %s', $hr > 12 ? $hr - 12 : ( 0 === $hr ? 12 : $hr ), $hr >= 12 ? 'PM' : 'AM' );

		return array(
			'day'     => $day_name,
			'hour'    => $hour_str,
			'message' => sprintf(
				/* translators: 1: day of week, 2: hour */
				__( 'Based on data, apply on %1$ss around %2$s for the best response rate.', 'zeko-jobs' ),
				$day_name,
				$hour_str
			),
		);
	}

	// ---- Methods added for Phase 1.1 raw-$wpdb refactor ----.

	/**
	 * Get a published job by ID (status = 'publish').
	 *
	 * @param int $job_id Job id.
	 */
	public function get_published_job( int $job_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'publish'", $job_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $job ?: null;
	}

	/**
	 * Count applications for a given job.
	 *
	 * @param int $job_id Job id.
	 */
	public function count_applications( int $job_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE job_id = %d", $job_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get published jobs belonging to an employer.
	 *
	 * @param int $employer_id Employer id.
	 * @param int $limit Limit.
	 */
	public function get_jobs_by_employer( int $employer_id, int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE employer_id = %d AND status = 'publish' ORDER BY created_at DESC LIMIT %d",
				$employer_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count published jobs belonging to an employer.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function count_jobs_by_employer( int $employer_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE employer_id = %d AND status = 'publish'",
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count jobs posted by an employer (any status).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function count_posted_jobs( int $employer_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE employer_id = %d", $employer_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count applications submitted by a seeker (any status).
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function count_applications_by_seeker( int $seeker_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE seeker_id = %d", $seeker_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Record a content flag for a job listing.
	 *
	 * @return int New flag ID, or 0 when a pending flag from the same user already exists.
	 * @param int    $job_id Job id.
	 * @param int    $user_id User id.
	 * @param string $reason Reason.
	 * @param string $details Details.
	 */
	public function add_job_flag( int $job_id, int $user_id, string $reason, string $details = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_flags';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE job_id = %d AND user_id = %d AND status = 'pending' LIMIT 1",
				$job_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $existing ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'job_id'     => $job_id,
				'user_id'    => $user_id,
				'reason'     => sanitize_text_field( $reason ),
				'details'    => sanitize_textarea_field( $details ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get flags for the moderation queue, optionally filtered by status.
	 * Joins the jobs table to expose the job slug/title.
	 *
	 * @param string $status Status.
	 * @param int    $limit Limit.
	 */
	public function get_job_flags( string $status = 'pending', int $limit = 200 ): array {
		global $wpdb;
		$table_flags = $wpdb->prefix . 'zeko_job_flags';
		$table_jobs  = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.job_id, f.user_id, f.reason, f.details, f.status, f.created_at, f.resolved_by, f.resolved_at,
				        j.title AS job_title, j.slug AS job_slug
				 FROM {$table_flags} f
				 LEFT JOIN {$table_jobs} j ON j.id = f.job_id
				 WHERE f.status = %s
				 ORDER BY f.created_at DESC
				 LIMIT %d",
				$status,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count flags per job for jobs in the given ID set (map: job_id => count).
	 *
	 * @param array $job_ids Job ids.
	 */
	public function get_job_flag_counts( array $job_ids = array() ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_flags';

		$where  = "status = 'pending'";
		$params = array();
		if ( ! empty( $job_ids ) ) {
			$job_ids      = array_map( 'absint', $job_ids );
			$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
			$where       .= " AND job_id IN ({$placeholders})";
			$params       = $job_ids;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, COUNT(*) AS c FROM {$table} WHERE {$where} GROUP BY job_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$counts = array();
		foreach ( $rows ?: array() as $row ) {
			$counts[ (int) $row['job_id'] ] = (int) $row['c'];
		}
		return $counts;
	}

	/**
	 * Count flags by status.
	 *
	 * @param string $status Status.
	 */
	public function count_job_flags_by_status( string $status = 'pending' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_flags';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Transition a flag to a new status (resolved/dismissed) with an admin actor.
	 *
	 * @param int    $flag_id Flag id.
	 * @param string $status Status.
	 * @param int    $resolved_by Resolved by.
	 */
	public function update_job_flag_status( int $flag_id, string $status, int $resolved_by = 0 ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_flags';

		$data    = array( 'status' => $status );
		$formats = array( '%s' );
		if ( in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			$data['resolved_by'] = $resolved_by ?: null;
			$data['resolved_at'] = current_time( 'mysql' );
			$formats[]           = '%d';
			$formats[]           = '%s';
		}

		return false !== $wpdb->update(
			$table,
			$data,
			array( 'id' => $flag_id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Get recently-published jobs matching dynamic search filters (used by digest).
	 * Supported keys in $filters: search_term, location, job_type, salary_min, salary_max.
	 *
	 * @param array $filters Filters.
	 * @param int   $days Days.
	 * @param int   $limit Limit.
	 */
	public function get_jobs_matching_search( array $filters, int $days = 1, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';

		$where  = array( "status = 'publish'", "created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" );
		$params = array();

		if ( ! empty( $filters['search_term'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search_term'] ) . '%';
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $filters['location'] ) ) {
			$loc_like = '%' . $wpdb->esc_like( $filters['location'] ) . '%';
			$where[]  = 'location LIKE %s';
			$params[] = $loc_like;
		}

		if ( ! empty( $filters['job_type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $filters['job_type'];
		}

		if ( ! empty( $filters['salary_min'] ) ) {
			$where[]  = 'salary_max >= %d';
			$params[] = (int) $filters['salary_min'];
		}

		if ( ! empty( $filters['salary_max'] ) ) {
			$where[]  = 'salary_min <= %d';
			$params[] = (int) $filters['salary_max'];
		}

		$where_clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT title, slug, location, type, is_featured FROM {$table} WHERE {$where_clause} ORDER BY is_featured DESC, created_at DESC LIMIT %d",
				array_merge( $params, array( $limit ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get published jobs that have passed their expires_at date.
	 */
	public function get_expired_jobs(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, employer_id, title, slug FROM {$table} WHERE status = 'publish' AND expires_at IS NOT NULL AND expires_at <= %s",
				current_time( 'mysql' )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Expire a single job by ID (sets status to 'expired').
	 *
	 * @param int $job_id Job id.
	 */
	public function expire_job( int $job_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_jobs';
		$result = $wpdb->update(
			$table,
			array(
				'status'     => 'expired',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return (bool) $result;
	}

	/**
	 * Get published jobs expiring within the given window from now.
	 *
	 * @param string $from From.
	 * @param string $to To.
	 */
	public function get_jobs_expiring_soon( string $from, string $to ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, employer_id, title, slug, expires_at FROM {$table} WHERE status = 'publish' AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s",
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get upcoming interviews with joined details (job title, seeker/employer info).
	 */
	public function get_upcoming_interviews(): array {
		global $wpdb;
		$interviews   = $wpdb->prefix . 'zeko_job_interviews';
		$applications = $wpdb->prefix . 'zeko_job_applications';
		$jobs         = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			"SELECT i.*, j.title AS job_title, j.slug AS job_slug,
				seeker.display_name AS seeker_name, seeker.user_email AS seeker_email,
				employer.display_name AS employer_name, employer.user_email AS employer_email
			FROM {$interviews} i
			INNER JOIN {$applications} a ON a.id = i.application_id
			INNER JOIN {$jobs} j ON j.id = a.job_id
			LEFT JOIN {$wpdb->users} seeker ON seeker.ID = i.seeker_id
			LEFT JOIN {$wpdb->users} employer ON employer.ID = i.employer_id
			WHERE i.status = 'scheduled'
			AND i.scheduled_at > NOW()
			AND i.scheduled_at <= DATE_ADD(NOW(), INTERVAL 25 HOUR)",
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count reminders of a given type already sent for an interview.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $note_pattern Note pattern.
	 */
	public function count_reminder_sent( int $user_id, string $type, string $note_pattern ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_reminders';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND reminder_type = 'interview_%s' AND note LIKE %s",
				$user_id,
				$type,
				$note_pattern
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Insert a reminder record.
	 *
	 * @param array $data Data.
	 */
	public function insert_reminder( array $data ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'zeko_job_reminders';
		$result = $wpdb->insert( $table, $data );
		return (bool) $result;
	}

	/**
	 * Log a user activity event (used by the main plugin class).
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 * @param string $message Message.
	 * @param int    $item_id Item id.
	 * @param array  $meta Meta.
	 */
	public function log_activity( int $user_id, string $action, string $message, int $item_id = 0, array $meta = array() ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_user_activity';

		$wpdb->insert(
			$table,
			array(
				'user_id'          => $user_id,
				'activity_type'    => sanitize_text_field( $action ),
				'activity_module'  => 'zeko-jobs',
				'activity_item_id' => $item_id,
				'activity_content' => sanitize_textarea_field( $message ),
				'activity_meta'    => maybe_serialize( $meta ),
				'activity_date'    => current_time( 'mysql' ),
				'activity_ip'      => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
				'activity_status'  => 'published',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Count applications for all jobs belonging to an employer.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function count_applications_for_employer( int $employer_id ): int {
		global $wpdb;
		$jobs = $wpdb->prefix . 'zeko_jobs';
		$apps = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$apps} app
				 JOIN {$jobs} j ON j.id = app.job_id
				 WHERE j.employer_id = %d",
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get recent applications for an employer's jobs.
	 *
	 * @param int $employer_id Employer id.
	 * @param int $limit Limit.
	 */
	public function get_recent_applications_for_employer( int $employer_id, int $limit = 5 ): array {
		global $wpdb;
		$jobs = $wpdb->prefix . 'zeko_jobs';
		$apps = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT app.id, app.status, app.applied_at, j.title AS job_title, u.display_name AS applicant_name
				 FROM {$apps} app
				 JOIN {$jobs} j ON j.id = app.job_id
				 LEFT JOIN {$wpdb->users} u ON u.ID = app.seeker_id
				 WHERE j.employer_id = %d
				 ORDER BY app.applied_at DESC
				 LIMIT %d",
				$employer_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count applications for a seeker.
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function count_applications_for_seeker( int $seeker_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE seeker_id = %d AND status != 'withdrawn'",
				$seeker_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count upcoming interviews for a seeker.
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function count_upcoming_interviews_for_seeker( int $seeker_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_interviews';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE seeker_id = %d AND status = 'scheduled' AND scheduled_at > NOW()",
				$seeker_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get recent applications for a seeker.
	 *
	 * @param int $seeker_id Seeker id.
	 * @param int $limit Limit.
	 */
	public function get_recent_applications_for_seeker( int $seeker_id, int $limit = 5 ): array {
		global $wpdb;
		$apps = $wpdb->prefix . 'zeko_job_applications';
		$jobs = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT app.id, app.status, app.applied_at, j.title AS job_title, j.slug AS job_slug
				 FROM {$apps} app
				 JOIN {$jobs} j ON j.id = app.job_id
				 WHERE app.seeker_id = %d AND app.status != 'withdrawn'
				 ORDER BY app.applied_at DESC
				 LIMIT %d",
				$seeker_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get jobs by an array of IDs (for bookmarks, etc.).
	 *
	 * @param array $ids Ids.
	 */
	public function get_jobs_by_ids( array $ids ): array {
		global $wpdb;
		$table        = $wpdb->prefix . 'zeko_jobs';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, slug, location, type, salary_min, salary_max, created_at, status, is_featured
				 FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				...$ids
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ---- Helper: build WHERE clause for dynamic job filters ----.

	/**
	 * Build a WHERE clause + params array from a filter set.
	 * Supported keys: search_term, location, job_type.
	 * Always restricts to status = 'publish'.
	 *
	 * @param array $filters Filters.
	 */
	private function build_job_filters_where( array $filters ): array {
		global $wpdb;
		$where  = array( "status = 'publish'" );
		$params = array();

		if ( ! empty( $filters['search_term'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search_term'] ) . '%';
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $filters['location'] ) ) {
			$loc_like = '%' . $wpdb->esc_like( $filters['location'] ) . '%';
			$where[]  = 'location LIKE %s';
			$params[] = $loc_like;
		}

		if ( ! empty( $filters['job_type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $filters['job_type'];
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Count jobs matching a filter set.
	 *
	 * @param array $filters Filters.
	 */
	public function count_jobs_filtered( array $filters ): int {
		$key    = $this->cache_key( 'count', $filters );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table                     = $wpdb->prefix . 'zeko_jobs';
		[ $where_clause, $params ] = $this->build_job_filters_where( $filters );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", ...$params ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		wp_cache_set( $key, $count, self::CACHE_GROUP, self::CACHE_TTL );
		return $count;
	}

	/**
	 * Get a paginated, filtered list of published jobs (REST API helper).
	 *
	 * @param array $filters Filters.
	 * @param int   $per_page Per page.
	 * @param int   $offset Offset.
	 */
	public function get_jobs_paginated( array $filters, int $per_page = 20, int $offset = 0 ): array {
		$key    = $this->cache_key( 'paginated', array_merge( $filters, compact( 'per_page', 'offset' ) ) );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table                     = $wpdb->prefix . 'zeko_jobs';
		[ $where_clause, $params ] = $this->build_job_filters_where( $filters );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				"SELECT id, title, slug, location, type, salary_min, salary_max, created_at, views
				 FROM {$table}
				 WHERE {$where_clause}
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		wp_cache_set( $key, $results, self::CACHE_GROUP, self::CACHE_TTL );
		return $results;
	}

	/**
	 * Get all distinct non-empty locations (for autocomplete datalists).
	 */
	public function get_distinct_locations(): array {
		$cache_key = 'distinct_locations';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = $wpdb->get_col(
			"SELECT DISTINCT location FROM {$table} WHERE status = 'publish' AND location != '' ORDER BY location ASC"
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Get all job IDs a seeker has applied to.
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function get_applied_job_ids( int $seeker_id ): array {
		$cache_key = 'applied_ids_' . $seeker_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_applications';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$results = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare( "SELECT job_id FROM {$table} WHERE seeker_id = %d", $seeker_id )
			) ?: array()
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Get all applications for a seeker with joined job info.
	 *
	 * @param int $seeker_id Seeker id.
	 */
	public function get_user_applications( int $seeker_id ): array {
		global $wpdb;
		$apps = $wpdb->prefix . 'zeko_job_applications';
		$jobs = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT app.id, app.job_id, app.status, app.applied_at, app.updated_at, app.viewed_at,
						job.title, job.slug, job.employer_id, job.is_featured
				 FROM {$apps} app
				 JOIN {$jobs} job ON job.id = app.job_id
				 WHERE app.seeker_id = %d
				 ORDER BY app.applied_at DESC",
				$seeker_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get all jobs posted by an employer (including non-published).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_employer_all_jobs( int $employer_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, slug, status, created_at, is_featured, company_id, payment_status
				 FROM {$table}
				 WHERE employer_id = %d
				 ORDER BY created_at DESC",
				$employer_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count total applications across an array of job IDs.
	 *
	 * @param array $job_ids Job ids.
	 */
	public function count_applications_for_jobs( array $job_ids ): int {
		if ( empty( $job_ids ) ) {
			return 0;
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'zeko_job_applications';
		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE job_id IN ({$placeholders})", ...$job_ids ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get job-related activities for the global feed (module = 'zeko-jobs').
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public function get_job_activities_for_feed( int $user_id, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_user_activity';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT activity_type, activity_content, activity_date, activity_item_id
				 FROM {$table}
				 WHERE user_id = %d AND activity_module = 'zeko-jobs'
				 ORDER BY activity_date DESC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Export all user data from plugin tables for GDPR compliance.
	 *
	 * @return array Nested array of all user data.
	 * @param int $user_id The user ID to export data for.
	 */
	public function export_user_data( int $user_id ): array {
		global $wpdb;

		$applications = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_applications WHERE seeker_id = %d ORDER BY applied_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$saved_searches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_searches WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$documents = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_documents WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$alerts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_alerts WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$interviews = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_interviews WHERE seeker_id = %d ORDER BY scheduled_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$reminders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_reminders WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$reviews = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_reviews WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		$activity = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_user_activity WHERE user_id = %d ORDER BY activity_date DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		return array(
			'applications'   => $applications,
			'saved_searches' => $saved_searches,
			'documents'      => $documents,
			'job_alerts'     => $alerts,
			'interviews'     => $interviews,
			'reminders'      => $reminders,
			'reviews'        => $reviews,
			'activity'       => $activity,
			'user_meta'      => array(
				'zeko_location'         => get_user_meta( $user_id, 'zeko_location', true ),
				'zeko_phone'            => get_user_meta( $user_id, 'zeko_phone', true ),
				'zeko_skills'           => get_user_meta( $user_id, 'zeko_skills', true ),
				'zeko_work_experience'  => get_user_meta( $user_id, 'zeko_work_experience', true ),
				'zeko_education'        => get_user_meta( $user_id, 'zeko_education', true ),
				'zeko_saved_resume_url' => get_user_meta( $user_id, 'zeko_saved_resume_url', true ),
				'zeko_saved_jobs'       => get_user_meta( $user_id, 'zeko_saved_jobs', true ),
				'zeko_bookmarked_jobs'  => get_user_meta( $user_id, 'zeko_bookmarked_jobs', true ),
				'zeko_notifications'    => $this->get_notifications( $user_id, 1000 ),
			),
		);
	}

	/**
	 * Get all applications for an array of job IDs, with joined job + seeker info.
	 *
	 * @param array $job_ids Job ids.
	 * @param int   $limit Limit.
	 */
	public function get_applications_for_jobs( array $job_ids, int $limit = 20 ): array {
		if ( empty( $job_ids ) ) {
			return array();
		}
		global $wpdb;
		$apps         = $wpdb->prefix . 'zeko_job_applications';
		$jobs         = $wpdb->prefix . 'zeko_jobs';
		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT app.id, app.job_id, app.status, app.applied_at, app.updated_at,
						job.title AS job_title, job.slug AS job_slug,
						seeker.ID AS seeker_id, seeker.user_login AS seeker_login, seeker.display_name AS seeker_name
				 FROM {$apps} app
				 JOIN {$jobs} job ON job.id = app.job_id
				 LEFT JOIN {$wpdb->users} seeker ON seeker.ID = app.seeker_id
				 WHERE app.job_id IN ({$placeholders})
				 ORDER BY app.applied_at DESC
				 LIMIT %d",
				array_merge( $job_ids, array( $limit ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/*
	================================================================
		NOTIFICATIONS
		================================================================
		*/

	/**
	 * Migrate existing user_meta notifications to the new table.
	 */
	private function migrate_notifications_from_meta(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_notifications';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$users = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'zeko_notifications'"
		);

		foreach ( $users as $user_id ) {
			$old = get_user_meta( $user_id, 'zeko_notifications', true );
			if ( ! is_array( $old ) || empty( $old ) ) {
				continue;
			}

			foreach ( $old as $note ) {
				$wpdb->insert(
					$table,
					array(
						'user_id'    => $user_id,
						'type'       => $note['meta']['type'] ?? 'general',
						'title'      => $note['meta']['title'] ?? '',
						'message'    => $note['message'] ?? '',
						'link'       => $note['meta']['link'] ?? null,
						'icon'       => $note['meta']['icon'] ?? 'dashicons-info',
						'meta'       => isset( $note['meta'] ) ? wp_json_encode( $note['meta'] ) : null,
						'is_read'    => ! empty( $note['read'] ) ? 1 : 0,
						'created_at' => $note['created'] ?? current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
				);
			}

			delete_user_meta( $user_id, 'zeko_notifications' );
		}
	}

	/**
	 * Create a notification record.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $title Title.
	 * @param string $message Message.
	 * @param string $link Link.
	 * @param string $icon Icon.
	 * @param array  $meta Meta.
	 */
	public function insert_notification( int $user_id, string $type, string $title, string $message, string $link = '', string $icon = 'dashicons-info', array $meta = array() ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'zeko_job_notifications',
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'title'      => $title,
				'message'    => $message,
				'link'       => $link,
				'icon'       => $icon,
				'meta'       => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
				'is_read'    => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get notifications for a user.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 */
	public function get_notifications( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zeko_job_notifications
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $results ) ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				$row['is_read'] = (int) $row['is_read'];
				$row['meta']    = $row['meta'] ? json_decode( $row['meta'], true ) : array();
				return $row;
			},
			$results
		);
	}

	/**
	 * Count unread notifications for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function count_unread_notifications( int $user_id ): int {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zeko_job_notifications
				 WHERE user_id = %d AND is_read = 0",
				$user_id
			)
		);

		return (int) $count;
	}

	/**
	 * Mark a single notification as read.
	 *
	 * @param int $notification_id Notification id.
	 * @param int $user_id User id.
	 */
	public function mark_notification_read( int $notification_id, int $user_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'zeko_job_notifications',
			array( 'is_read' => 1 ),
			array(
				'id'      => $notification_id,
				'user_id' => $user_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Mark all notifications as read for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function mark_all_notifications_read( int $user_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'zeko_job_notifications',
			array( 'is_read' => 1 ),
			array(
				'user_id' => $user_id,
				'is_read' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Delete old notifications (cleanup cron).
	 *
	 * @param int $days Days.
	 */
	public function cleanup_old_notifications( int $days = 90 ): int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}zeko_job_notifications
				 WHERE created_at < %s",
				wp_date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Batch application notifications.
	 * Instead of creating a separate "New Application" notification per applicant,
	 * consecutive unread application notifications for the same job are consolidated
	 * into a single row whose message shows the running applicant count.
	 *
	 * @return int New or consolidated notification ID.
	 * @param int    $employer_id Employer receiving the notification.
	 * @param int    $job_id Job the application is for.
	 * @param string $job_title Job title for the notification message.
	 */
	public function batch_application_notification( int $employer_id, int $job_id, string $job_title ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_notifications';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, meta FROM {$table}
				 WHERE user_id = %d AND type = %s AND is_read = 0 AND meta LIKE %s
				 ORDER BY id DESC LIMIT 1",
				$employer_id,
				'application_received',
				'%' . $wpdb->esc_like( '"job_id":' . (int) $job_id ) . '%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $existing ) {
			$meta                      = json_decode( (string) $existing['meta'], true ) ?: array();
			$count                     = ( isset( $meta['application_count'] ) ? (int) $meta['application_count'] : 1 ) + 1;
			$meta['application_count'] = $count;

			$message = sprintf(
				/* translators: 1: application count, 2: job title */
				_n(
					'%1$d new application for "%2$s"',
					'%1$d new applications for "%2$s"',
					$count,
					'zeko-jobs'
				),
				$count,
				$job_title
			);

			$wpdb->update(
				$table,
				array(
					'message'    => $message,
					'meta'       => wp_json_encode( $meta ),
					'created_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$meta = array(
			'job_id'            => $job_id,
			'application_count' => 1,
		);
		return $this->insert_notification(
			$employer_id,
			'application_received',
			__( 'New Application', 'zeko-jobs' ),
			/* translators: %s: job title */
			sprintf( __( '1 new application for "%s"', 'zeko-jobs' ), $job_title ),
			admin_url( 'admin.php?page=zeko-jobs-dashboard&tab=applications&job_id=' . $job_id ),
			'dashicons-admin-users',
			$meta
		);
	}

	/**
	 * Daily DB cleanup: purge old logs, resolved flags, and stale notification rows.
	 *
	 * @return array Counts per cleanup bucket.
	 */
	public function run_cleanup(): array {
		global $wpdb;

		$deleted = array(
			'notifications' => $this->cleanup_old_notifications( 90 ),
			'email_opens'   => 0,
			'views_log'     => 0,
			'flags'         => 0,
		);

		$cutoff_90 = wp_date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

		$deleted['email_opens'] = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}zeko_job_email_opens WHERE opened_at < %s",
				$cutoff_90
			)
		);

		$deleted['views_log'] = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}zeko_job_views_log WHERE viewed_at < %s",
				wp_date( 'Y-m-d H:i:s', strtotime( '-180 days' ) )
			)
		);

		$deleted['flags'] = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}zeko_job_flags
				 WHERE status <> 'pending' AND resolved_at < %s",
				$cutoff_90
			)
		);

		/**
		 * Fires after the daily Zeko Jobs DB cleanup runs.
		 *
		 * @param array $deleted Per-bucket deleted-row counts.
		 */
		do_action( 'zeko_jobs_db_cleanup_done', $deleted );

		return $deleted;
	}

	/*
	=============================================
		Resume Builder (Structured Resumes)
		=============================================
		*/

	/**
	 * Get all structured resumes for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function get_structured_resumes( int $user_id ): array {
		$resumes = get_user_meta( $user_id, 'zeko_structured_resumes', true );
		if ( ! is_array( $resumes ) ) {
			return array();
		}
		return $resumes;
	}

	/**
	 * Get a single structured resume by ID.
	 *
	 * @param int    $user_id User id.
	 * @param string $resume_id Resume id.
	 */
	public function get_structured_resume( int $user_id, string $resume_id ): ?array {
		$resumes = $this->get_structured_resumes( $user_id );
		foreach ( $resumes as $r ) {
			if ( ( $r['id'] ?? '' ) === $resume_id ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Save (create or update) a structured resume.
	 *
	 * @return string Resume ID.
	 * @param int   $user_id User id.
	 * @param array $data Data.
	 */
	public function save_structured_resume( int $user_id, array $data ): string {
		$resumes   = $this->get_structured_resumes( $user_id );
		$resume_id = $data['id'] ?? wp_generate_uuid4();

		$existing_idx = null;
		foreach ( $resumes as $i => $r ) {
			if ( ( $r['id'] ?? '' ) === $resume_id ) {
				$existing_idx = $i;
				break;
			}
		}

		$resume = array(
			'id'         => $resume_id,
			'title'      => sanitize_text_field( $data['title'] ?? __( 'My Resume', 'zeko-jobs' ) ),
			'template'   => sanitize_text_field( $data['template'] ?? 'modern' ),
			'sections'   => array(
				'personal'       => array(
					'full_name' => sanitize_text_field( $data['sections']['personal']['full_name'] ?? '' ),
					'email'     => sanitize_email( $data['sections']['personal']['email'] ?? '' ),
					'phone'     => sanitize_text_field( $data['sections']['personal']['phone'] ?? '' ),
					'location'  => sanitize_text_field( $data['sections']['personal']['location'] ?? '' ),
					'website'   => esc_url_raw( $data['sections']['personal']['website'] ?? '' ),
					'linkedin'  => esc_url_raw( $data['sections']['personal']['linkedin'] ?? '' ),
					'summary'   => sanitize_textarea_field( $data['sections']['personal']['summary'] ?? '' ),
				),
				'experience'     => $this->sanitize_resume_list( $data['sections']['experience'] ?? array(), array( 'company', 'title', 'location', 'start_date', 'end_date', 'description', 'current' ) ),
				'education'      => $this->sanitize_resume_list( $data['sections']['education'] ?? array(), array( 'school', 'degree', 'field', 'start_date', 'end_date', 'description', 'current' ) ),
				'skills'         => array_values( array_filter( array_map( 'sanitize_text_field', $data['sections']['skills'] ?? array() ) ) ),
				'certifications' => $this->sanitize_resume_list( $data['sections']['certifications'] ?? array(), array( 'name', 'issuer', 'date', 'url' ) ),
				'projects'       => $this->sanitize_resume_list( $data['sections']['projects'] ?? array(), array( 'name', 'url', 'description', 'start_date', 'end_date' ) ),
			),
			'is_default' => (bool) ( $data['is_default'] ?? false ),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( null !== $existing_idx ) {
			$resumes[ $existing_idx ] = $resume;
		} else {
			$resumes[] = $resume;
		}

		// If setting as default, unset default on others.
		if ( $resume['is_default'] ) {
			foreach ( $resumes as &$r ) {
				if ( ( $r['id'] ?? '' ) !== $resume_id ) {
					$r['is_default'] = false;
				}
			}
			unset( $r );
		}

		update_user_meta( $user_id, 'zeko_structured_resumes', $resumes );
		return $resume_id;
	}

	/**
	 * Delete a structured resume.
	 *
	 * @param int    $user_id User id.
	 * @param string $resume_id Resume id.
	 */
	public function delete_structured_resume( int $user_id, string $resume_id ): bool {
		$resumes = $this->get_structured_resumes( $user_id );
		$resumes = array_values( array_filter( $resumes, fn( $r ) => ( $r['id'] ?? '' ) !== $resume_id ) );
		return update_user_meta( $user_id, 'zeko_structured_resumes', $resumes );
	}

	/**
	 * Set a structured resume as default.
	 *
	 * @param int    $user_id User id.
	 * @param string $resume_id Resume id.
	 */
	public function set_default_structured_resume( int $user_id, string $resume_id ): bool {
		$resumes = $this->get_structured_resumes( $user_id );
		foreach ( $resumes as &$r ) {
			$r['is_default'] = ( ( $r['id'] ?? '' ) === $resume_id );
		}
		unset( $r );
		return update_user_meta( $user_id, 'zeko_structured_resumes', $resumes );
	}

	/**
	 * Sanitize an array of resume list items (experience, education, etc).
	 *
	 * @param array $items Items.
	 * @param array $fields Fields.
	 */
	private function sanitize_resume_list( array $items, array $fields ): array {
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$entry = array();
			foreach ( $fields as $field ) {
				$value = $item[ $field ] ?? '';
				if ( in_array( $field, array( 'url' ), true ) ) {
					$entry[ $field ] = esc_url_raw( $value );
				} elseif ( in_array( $field, array( 'description' ), true ) ) {
					$entry[ $field ] = sanitize_textarea_field( $value );
				} elseif ( in_array( $field, array( 'current' ), true ) ) {
					$entry[ $field ] = (bool) $value;
				} else {
					$entry[ $field ] = sanitize_text_field( $value );
				}
			}
			$clean[] = $entry;
		}
		return $clean;
	}

	// ── ZekoPay billing methods ──────────────────────────────────────.

	/**
	 * Get billing settings with defaults.
	 */
	public static function get_billing_settings(): array {
		$defaults = array(
			'posting_fee'        => 25.00,
			'boost_fee'          => 10.00,
			'free_jobs'          => 0,
			'enable_payments'    => 0,
			'listing_pack_price' => 99.00,
			'listing_pack_count' => 10,
		);
		$options  = get_option( 'zeko_jobs_settings', array() );
		return array_merge( $defaults, $options );
	}

	/**
	 * Check if ZekoPay payments are enabled.
	 */
	public static function payments_enabled(): bool {
		$settings = self::get_billing_settings();
		return (int) ( $settings['enable_payments'] ?? 0 ) === 1
			&& class_exists( 'Zeko_Pay_SDK' );
	}

	/**
	 * Count how many jobs this employer has posted (all time).
	 *
	 * @param int $employer_id Employer id.
	 */
	public function count_employer_jobs( int $employer_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE employer_id = %d", $employer_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count active (published) job posts for an employer. The free plan caps
	 * this; the `zeko_jobs_limit_active_posts` filter raises the cap for
	 * licensed sites.
	 *
	 * @param int $employer_id Employer user ID.
	 */
	public function count_published_jobs( int $employer_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_jobs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE employer_id = %d AND status = 'publish'", $employer_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Log a billing transaction.
	 *
	 * @param array $data Data.
	 */
	public function log_billing( array $data ): int {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'zeko_job_billing',
			array(
				'user_id'        => (int) $data['user_id'],
				'job_id'         => isset( $data['job_id'] ) ? (int) $data['job_id'] : null,
				'type'           => sanitize_text_field( $data['type'] ),
				'amount'         => (float) ( $data['amount'] ?? 0 ),
				'currency'       => sanitize_text_field( $data['currency'] ?? 'USD' ),
				'zeko_pay_tx_id' => isset( $data['zeko_pay_tx_id'] ) ? (int) $data['zeko_pay_tx_id'] : null,
				'status'         => sanitize_text_field( $data['status'] ?? 'completed' ),
				'description'    => sanitize_text_field( $data['description'] ?? '' ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%f', '%s', '%d', '%s', '%s', '%s' )
		);
		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get billing history for an employer.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 */
	public function get_billing_history( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_billing';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, j.title AS job_title
				FROM {$table} b
				LEFT JOIN {$wpdb->prefix}zeko_jobs j ON j.id = b.job_id
				WHERE b.user_id = %d
				ORDER BY b.created_at DESC
				LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get the number of free job postings remaining for an employer.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_free_postings_remaining( int $employer_id ): int {
		$settings  = self::get_billing_settings();
		$free_jobs = (int) ( $settings['free_jobs'] ?? 0 );
		if ( $free_jobs <= 0 ) {
			return 0;
		}
		$used = $this->count_employer_jobs( $employer_id );
		return max( 0, $free_jobs - $used );
	}

	/**
	 * Check if an employer has active listing pack credits.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function has_listing_pack_credits( int $employer_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_billing';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND type = 'listing_pack' AND status = 'completed' AND amount > 0",
				$employer_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $count > 0;
	}

	/**
	 * Deduct one credit from a listing pack.
	 * Returns true if a credit was available, false otherwise.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function use_listing_pack_credit( int $employer_id ): bool {
		if ( ! $this->has_listing_pack_credits( $employer_id ) ) {
			return false;
		}
		// A listing pack entry with positive amount acts as a credit counter.
		// We mark it used by reducing amount by 1 (conceptually).
		// In practice, we count completed pack entries and compare with used postings.
		$settings      = self::get_billing_settings();
		$pack_count    = (int) ( $settings['listing_pack_count'] ?? 5 );
		$employer_jobs = $this->count_employer_jobs( $employer_id );
		$paid_jobs     = $employer_jobs - ( (int) ( $settings['free_jobs'] ?? 0 ) );
		return $paid_jobs < $pack_count;
	}

	/**
	 * Check if employer needs to pay for posting.
	 * Returns: 'free' | 'pack' | 'pay'
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_posting_payment_type( int $employer_id ): string {
		$settings = self::get_billing_settings();
		if ( ! self::payments_enabled() ) {
			return 'free';
		}
		$free_remaining = $this->get_free_postings_remaining( $employer_id );
		if ( $free_remaining > 0 ) {
			return 'free';
		}
		if ( $this->use_listing_pack_credit( $employer_id ) ) {
			return 'pack';
		}
		return 'pay';
	}
}
