<?php
/**
 * Migrate Jobs — Import data from WP Job Manager into zeko-jobs.
 *
 * @package Zeko_Jobs
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Zeko_Migrate_WPJM
 *
 * Imports companies, jobs, and applications from WP Job Manager into
 * the zeko-jobs custom-table ecosystem.
 */
class Zeko_Migrate_WPJM extends Zeko_Migrator_Base {

	/**
	 * Company map.
	 *
	 * @var array Company map.
	 */
	private array $company_map = array();

	/**
	 * Job map.
	 *
	 * @var array Job map.
	 */
	private array $job_map = array();

	/**
	 * Default migration options.
	 */
	protected function get_defaults(): array {
		return array(
			'migrate_companies'    => true,
			'migrate_jobs'         => true,
			'migrate_applications' => true,
			'delete_source'        => false,
		);
	}

	/**
	 * Check whether WP Job Manager is active and the job_listing CPT exists.
	 */
	public function is_available(): bool {
		return post_type_exists( 'job_listing' ) && class_exists( 'WP_Job_Manager' );
	}

	/**
	 * Human-readable label.
	 */
	public function get_label(): string {
		return __( 'WP Job Manager', 'zeko-jobs' );
	}

	/**
	 * Get item counts for preview display.
	 *
	 * @return array{jobs: int, companies: int, applications: int}
	 */
	public function get_item_counts(): array {
		global $wpdb;

		$jobs_count         = 0;
		$company_count      = 0;
		$applications_count = 0;

		if ( post_type_exists( 'job_listing' ) ) {
			$count_posts = wp_count_posts( 'job_listing' );
			$jobs_count  = (int) ( $count_posts->publish ?? 0 );
		}

		$company_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.meta_value)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND pm.meta_key = '_company_name'
				AND pm.meta_value != ''",
				'job_listing'
			)
		);

		if ( post_type_exists( 'job_application' ) ) {
			$count_apps         = wp_count_posts( 'job_application' );
			$applications_count = (int) ( $count_apps->publish ?? 0 ) + (int) ( $count_apps->pending ?? 0 );
		}

		return array(
			'jobs'         => $jobs_count,
			'companies'    => $company_count,
			'applications' => $applications_count,
		);
	}

	/**
	 * Return preview data for the admin UI.
	 *
	 * @return array List of item arrays with title, company, location, status, has_applications.
	 * @param int $limit Max items to preview.
	 */
	public function preview( int $limit = 20 ): array {
		$items = array();

		$posts = get_posts(
			array(
				'post_type'      => 'job_listing',
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$company_name = get_post_meta( $post->ID, '_company_name', true );
			$location     = get_post_meta( $post->ID, '_job_location', true );
			$status       = ( 'publish' === $post->post_status ) ? 'active' : 'closed';

			$has_apps = false;
			if ( post_type_exists( 'job_application' ) ) {
				$has_apps = $this->count_applications_for_job( $post->ID ) > 0;
			}

			$items[] = array(
				'title'            => $post->post_title,
				'company'          => $company_name ?: '—',
				'location'         => $location ?: '—',
				'status'           => $status,
				'has_applications' => $has_apps,
			);
		}

		return $items;
	}

	/**
	 * Run the full migration.
	 *
	 * @return array{imported: int, skipped: int, errors: int}
	 */
	public function run(): array {
		$this->set_options( $this->options );

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		if ( $this->options['migrate_companies'] ) {
			$this->migrate_companies();
		}

		if ( $this->options['migrate_jobs'] ) {
			$result    = $this->migrate_jobs();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		if ( $this->options['migrate_applications'] ) {
			$app_result = $this->migrate_applications();
			$imported  += $app_result['imported'];
			$skipped   += $app_result['skipped'];
			$errors    += $app_result['errors'];
		}

		if ( $this->options['delete_source'] ) {
			$this->delete_source_posts();
		}

		/**
		 * Action: After jobs migration completes.
		 *
		 * @param string $source   Source plugin key.
		 * @param int    $imported Number of items imported.
		 * @param int    $skipped  Number of items skipped.
		 * @param array  $options  Migration options used.
		 */
		do_action( 'zbp_migration_complete', 'wp_job_manager', $imported, $skipped, $this->options );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/*
	=========================================================================
	 * Companies
	 * ======================================================================
	 */

	/**
	 * Migrate unique companies from job meta into zeko_companies.
	 */
	private function migrate_companies(): void {
		global $wpdb;

		$employer_id = $this->resolve_default_employer();

		$company_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND pm.meta_key = '_company_name'
				AND pm.meta_value != ''",
				'job_listing'
			)
		);

		if ( empty( $company_names ) ) {
			return;
		}

		$db = \Zeko_Jobs_DB::get_instance();

		foreach ( $company_names as $company_name ) {
			$company_name = trim( $company_name );

			// Find first job with this company to pull logo and other meta.
			$sample_job_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'job_listing' AND p.post_status = 'publish'
					AND pm.meta_key = '_company_name' AND pm.meta_value = %s
					ORDER BY p.ID ASC LIMIT 1",
					$company_name
				)
			);

			$about    = $sample_job_id ? (string) get_post_meta( $sample_job_id, '_company_about', true ) : '';
			$website  = $sample_job_id ? (string) get_post_meta( $sample_job_id, '_company_website', true ) : '';
			$location = $sample_job_id ? (string) get_post_meta( $sample_job_id, '_job_location', true ) : '';
			$logo_url = '';

			if ( $sample_job_id ) {
				$thumb_id = (int) get_post_thumbnail_id( $sample_job_id );
				if ( $thumb_id ) {
					$logo_url = (string) wp_get_attachment_image_url( $thumb_id, 'medium' );
				}
			}

			$company_data = array(
				'name'        => $company_name,
				'slug'        => sanitize_title( $company_name ),
				'description' => $about,
				'website'     => $website,
				'logo_url'    => $logo_url,
				'industry'    => '',
				'location'    => $location,
			);

			$company_id = $db->save_company( $employer_id, $company_data );

			if ( $company_id ) {
				$this->company_map[ $company_name ] = $company_id;
				$this->log( 'imported', $company_name, 'Company created (ID: ' . $company_id . ')' );
			} else {
				$this->log( 'error', $company_name, 'Failed to save company' );
			}
		}
	}

	/*
	=========================================================================
	 * Jobs
	 * ======================================================================
	 */

	/**
	 * Migrate published job_listing posts into zeko_jobs.
	 */
	private function migrate_jobs(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;
		$batch    = 50;

		$employer_id = $this->resolve_default_employer();
		$db          = \Zeko_Jobs_DB::get_instance();

		do {
			$posts = get_posts(
				array(
					'post_type'      => 'job_listing',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'post_status'    => 'publish',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				// Duplicate check by title.
				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}zeko_jobs WHERE title = %s LIMIT 1",
						$post->post_title
					)
				);

				if ( $exists ) {
					++$skipped;
					$this->log( 'skipped', $post->post_title, 'Job already exists' );
					continue;
				}

				try {
					$job_id = $this->import_single_job( $post, $employer_id, $db );

					if ( $job_id ) {
						++$imported;
						$this->job_map[ $post->ID ] = $job_id;
						$this->log( 'imported', $post->post_title, 'Job created (ID: ' . $job_id . ')' );
						do_action( 'zbp_migration_item_complete', 'jobs', $post->ID, $job_id );
					} else {
						++$errors;
						$this->log( 'error', $post->post_title, 'Failed to insert job' );
					}
				} catch ( \Exception $e ) {
					++$errors;
					$this->log( 'error', $post->post_title, $e->getMessage() );
				}
			}

			$offset += $batch;

			$posts_count = count( $posts );
		} while ( $posts_count === $batch );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single WPJM job post into zeko_jobs.
	 *
	 * @return int Job ID on success, 0 on failure.
	 * @param \WP_Post      $post Source job_listing post.
	 * @param int           $employer_id Resolved employer user ID.
	 * @param \Zeko_Jobs_DB $db DB instance.
	 */
	private function import_single_job( \WP_Post $post, int $employer_id, \Zeko_Jobs_DB $db ): int {
		$pid = $post->ID;

		$company_name    = (string) get_post_meta( $pid, '_company_name', true );
		$location        = (string) get_post_meta( $pid, '_job_location', true );
		$company_website = (string) get_post_meta( $pid, '_company_website', true );
		$featured        = ( 'featured' === (string) get_post_meta( $pid, '_featured', true ) ) ? 1 : 0;
		$remote          = ( 'yes' === strtolower( (string) get_post_meta( $pid, '_remote_position', true ) ) ) ? 1 : 0;
		$expires_raw     = (string) get_post_meta( $pid, '_job_expires', true );

		// Job type mapping: full_time → full-time, etc.
		$type = $this->map_job_type( $pid );

		// Category mapping.
		$category = $this->map_job_category( $pid );

		// Salary parsing.
		$salary_raw = (string) get_post_meta( $pid, '_job_salary', true );
		$this->parse_salary( $salary_raw, $salary_min, $salary_max );

		// Employer ID resolution from application email or post author.
		$employer_id = $this->resolve_employer_id( $pid, $employer_id );

		// Slug handling (ensure uniqueness).
		$slug = $this->unique_slug( $post->post_name, 'zeko_jobs' );

		// Status mapping.
		$status = ( 'publish' === $post->post_status ) ? 'active' : 'closed';

		// Expiry date.
		$expires_at = ! empty( $expires_raw ) ? gmdate( 'Y-m-d H:i:s', strtotime( $expires_raw ) ) : gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

		$job_data = array(
			'employer_id'          => $employer_id,
			'title'                => $post->post_title,
			'slug'                 => $slug,
			'description'          => $post->post_content,
			'location'             => $location,
			'type'                 => $type,
			'category'             => $category,
			'company_name'         => $company_name,
			'company_website'      => $company_website,
			'salary_min'           => $salary_min,
			'salary_max'           => $salary_max,
			'is_featured'          => $featured,
			'status'               => $status,
			'created_at'           => $post->post_date,
			'updated_at'           => $post->post_modified,
			'expires_at'           => $expires_at,
			'latitude'             => 0,
			'longitude'            => 0,
			'remote_option'        => $remote ? 'yes' : '',
			'application_deadline' => $expires_at,
			'company_id'           => $this->company_map[ $company_name ] ?? null,
			'required_skills'      => '',
			'payment_status'       => 'completed',
			'requirements'         => '',
			'benefits'             => '',
			'responsibilities'     => '',
			'qualifications'       => '',
			'experience_level'     => '',
			'is_easy_apply'        => 0,
			'company_logo_id'      => 0,
			'company_size'         => '',
			'company_industry'     => '',
			'company_about'        => '',
		);

		return $db->insert_job( $job_data );
	}

	/*
	=========================================================================
	 * Applications
	 * ======================================================================
	 */

	/**
	 * Migrate job_application posts into zeko_job_applications.
	 */
	private function migrate_applications(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;
		$batch    = 50;

		if ( ! post_type_exists( 'job_application' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		$db = \Zeko_Jobs_DB::get_instance();

		do {
			$posts = get_posts(
				array(
					'post_type'      => 'job_application',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$seeker_id = $this->resolve_seeker_id( $post );
				if ( ! $seeker_id ) {
					++$skipped;
					$this->log( 'skipped', 'Application #' . $post->ID, 'No valid seeker user found' );
					continue;
				}

				$job_id = $this->resolve_application_job_id( $post );
				if ( ! $job_id ) {
					++$skipped;
					$this->log( 'skipped', 'Application #' . $post->ID, 'No matching zeko job found' );
					continue;
				}

				$resume_url = $this->get_user_resume_url( $seeker_id );

				$app_status = $this->map_application_status( $post->post_status );

				$app_data = array(
					'job_id'       => $job_id,
					'seeker_id'    => $seeker_id,
					'resume_url'   => $resume_url,
					'cover_letter' => $post->post_content,
					'status'       => $app_status,
					'applied_at'   => $post->post_date,
					'updated_at'   => $post->post_modified,
					'viewed_at'    => null,
				);

				$result = $db->insert_application( $app_data );

				if ( $result ) {
					++$imported;
					$this->log( 'imported', 'Application #' . $post->ID, 'Application imported for job ID: ' . $job_id );
					do_action( 'zbp_migration_item_complete', 'jobs', $post->ID, $job_id );
				} else {
					++$errors;
					$this->log( 'error', 'Application #' . $post->ID, 'Failed to insert application' );
				}
			}

			$offset += $batch;

			$posts_count = count( $posts );
		} while ( $posts_count === $batch );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/*
	=========================================================================
	 * Mapping helpers
	 * ======================================================================
	 */

	/**
	 * Map WPJM job type taxonomy term to zeko-jobs format.
	 *
	 * @return string Normalized type string.
	 * @param int $post_id WP post ID.
	 */
	private function map_job_type( int $post_id ): string {
		$terms = wp_get_post_terms( $post_id, 'job_listing_type', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$slug = strtolower( trim( $terms[0] ) );

		$type_map = array(
			'full-time'  => 'full-time',
			'full_time'  => 'full-time',
			'part-time'  => 'part-time',
			'part_time'  => 'part-time',
			'contract'   => 'contract',
			'temporary'  => 'temporary',
			'internship' => 'internship',
			'freelance'  => 'freelance',
		);

		return $type_map[ $slug ] ?? $slug;
	}

	/**
	 * Map WPJM job category taxonomy term.
	 *
	 * @return string Category name or empty.
	 * @param int $post_id WP post ID.
	 */
	private function map_job_category( int $post_id ): string {
		$terms = wp_get_post_terms( $post_id, 'job_listing_category', array( 'fields' => 'names' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return $terms[0];
	}

	/**
	 * Parse a salary string into min/max values.
	 * Attempts to handle common formats like "$50,000 - $70,000", "50000-70000",
	 * "$50k-$70k", or plain numeric values.
	 *
	 * @param string $raw Raw salary string.
	 * @param float  $salary_min Salary min.
	 * @param float  $salary_max Salary max.
	 */
	private function parse_salary( string $raw, float &$salary_min, float &$salary_max ): void {
		$salary_min = 0;
		$salary_max = 0;

		$raw = trim( $raw );
		if ( empty( $raw ) ) {
			return;
		}

		// Normalize: remove currency symbols, commas, whitespace variants.
		$clean = preg_replace( '/[\$£€¥,\s]/', '', $raw );

		// Handle "k" suffix (e.g. 50k-70k).
		$has_k = stripos( $clean, 'k' ) !== false;
		$clean = str_ireplace( 'k', '', $clean );

		// Split on dash, en-dash, hyphen.
		$parts = preg_split( '/\s*[-–—]\s*/', $clean, 2 );

		if ( 2 === count( $parts ) && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
			$salary_min = (float) $parts[0];
			$salary_max = (float) $parts[1];
		} elseif ( 1 === count( $parts ) && is_numeric( $parts[0] ) ) {
			$salary_min = (float) $parts[0];
			$salary_max = (float) $parts[0];
		} else {
			return;
		}

		if ( $has_k ) {
			$salary_min *= 1000;
			$salary_max *= 1000;
		}
	}

	/**
	 * Resolve the employer_id for a job.
	 * Checks `_application` meta for an email address and resolves to a WP user.
	 * Falls back to the job post_author, then to the default employer.
	 *
	 * @return int Resolved employer user ID.
	 * @param int $post_id Source job post ID.
	 * @param int $default_id Default employer ID.
	 */
	private function resolve_employer_id( int $post_id, int $default_id ): int {
		$email = (string) get_post_meta( $post_id, '_application', true );

		if ( ! empty( $email ) && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				return $user->ID;
			}
		}

		$post = get_post( $post_id );
		if ( $post && $post->post_author ) {
			$author = get_userdata( (int) $post->post_author );
			if ( $author ) {
				return $author->ID;
			}
		}

		return $default_id;
	}

	/**
	 * Resolve the seeker user ID from an application post.
	 *
	 * @return int User ID or 0.
	 * @param \WP_Post $post Application post.
	 */
	private function resolve_seeker_id( \WP_Post $post ): int {
		if ( $post->post_author ) {
			$user = get_userdata( (int) $post->post_author );
			if ( $user ) {
				return $user->ID;
			}
		}

		return 0;
	}

	/**
	 * Resolve the zeko job ID for a job_application post.
	 * Checks `_job_id` meta first, then the post_parent relationship,
	 * then searches the id_map.
	 *
	 * @return int Zeko job ID or 0.
	 * @param \WP_Post $post Application post.
	 */
	private function resolve_application_job_id( \WP_Post $post ): int {
		global $wpdb;

		// Method 1: _job_id meta (WPJM stores the source job_listing ID).
		$source_job_id = (int) get_post_meta( $post->ID, '_job_id', true );

		if ( $source_job_id && isset( $this->job_map[ $source_job_id ] ) ) {
			return $this->job_map[ $source_job_id ];
		}

		// Method 2: post_parent (WPJM sometimes links via parent).
		if ( $post->post_parent && isset( $this->job_map[ $post->post_parent ] ) ) {
			return $this->job_map[ $post->post_parent ];
		}

		// Method 3: Try to find by matching the source job listing's title.
		if ( $source_job_id ) {
			$source_post = get_post( $source_job_id );
			if ( $source_post ) {
				$zeko_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}zeko_jobs WHERE title = %s LIMIT 1",
						$source_post->post_title
					)
				);
				if ( $zeko_id ) {
					$this->job_map[ $source_job_id ] = $zeko_id;
					return $zeko_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Get a user's resume URL from zeko user meta.
	 *
	 * @return string Resume URL or empty.
	 * @param int $user_id User ID.
	 */
	private function get_user_resume_url( int $user_id ): string {
		$url = (string) get_user_meta( $user_id, 'zeko_resume', true );

		if ( ! empty( $url ) ) {
			return $url;
		}

		// Fallback: WPJM resume meta.
		$url = (string) get_user_meta( $user_id, '_resume', true );

		return $url;
	}

	/**
	 * Map WPJM application status to zeko-jobs status.
	 *
	 * @return string Zeko application status.
	 * @param string $wp_status WP post_status.
	 */
	private function map_application_status( string $wp_status ): string {
		$map = array(
			'pending' => 'pending',
			'publish' => 'shortlisted',
			'private' => 'shortlisted',
			'draft'   => 'pending',
		);

		return $map[ $wp_status ] ?? 'pending';
	}

	/**
	 * Count applications for a given WPJM job post.
	 *
	 * @return int Application count.
	 * @param int $job_post_id Source job_listing post ID.
	 */
	private function count_applications_for_job( int $job_post_id ): int {
		global $wpdb;

		if ( ! post_type_exists( 'job_application' ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'job_application'
				AND ( post_parent = %d OR ID IN (
					SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_key = '_job_id' AND meta_value = %d
				) )",
				$job_post_id,
				$job_post_id
			)
		);
	}

	/**
	 * Generate a unique slug for zeko_jobs.
	 *
	 * @return string Unique slug.
	 * @param string $slug Proposed slug.
	 * @param string $table Table name (without prefix).
	 */
	private function unique_slug( string $slug, string $table ): string {
		global $wpdb;

		$original = $slug;
		$counter  = 1;

		$table_name = $wpdb->prefix . $table;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		while ( true ) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE slug = %s",
					$slug
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( ! $exists ) {
				return $slug;
			}

			++$counter;
			$slug = $original . '-' . $counter;
		}
	}

	/**
	 * Resolve a default employer ID for company creation.
	 * Uses the first user with 'employer' role or falls back to admin.
	 *
	 * @return int User ID.
	 */
	private function resolve_default_employer(): int {
		$employers = get_users(
			array(
				'role'   => 'employer',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $employers ) ) {
			return (int) $employers[0];
		}

		// Fallback to first admin.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}

		return 1;
	}

	/**
	 * Delete source WPJM posts after migration.
	 */
	private function delete_source_posts(): void {
		$types = array( 'job_listing' );

		if ( post_type_exists( 'job_application' ) ) {
			$types[] = 'job_application';
		}

		foreach ( $types as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				)
			);

			foreach ( $posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
	}
}
