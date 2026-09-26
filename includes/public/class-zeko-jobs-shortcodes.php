<?php
/**
 * Shortcodes for Zeko Jobs.
 *
 * Responsibilities:
 * - [zeko_jobs_archive] - Render jobs archive with filters, map, pagination
 * - [zeko_jobs_featured] - Render featured jobs grid
 * - [zeko_jobs_post_form] - Render job post/edit form
 * - [zeko_jobs_dashboard] - Render employer/seeker dashboard
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Shortcodes. */
final class Zeko_Jobs_Shortcodes {

	/**
	 * Construct.
	 */
	public function __construct() {
		add_shortcode( 'zeko_jobs_archive', array( $this, 'jobs_archive_shortcode' ) );
		add_shortcode( 'zeko_jobs_post_form', array( $this, 'job_post_form_shortcode' ) );
		add_shortcode( 'zeko_jobs_dashboard', array( $this, 'job_dashboard_shortcode' ) );
		add_shortcode( 'zeko_jobs_featured', array( $this, 'featured_jobs_shortcode' ) );
		add_shortcode( 'zeko_jobs_companies', array( $this, 'featured_companies_shortcode' ) );
	}

	/**
	 * Jobs archive shortcode.
	 *
	 * @param array $atts Atts.
	 */
	public function jobs_archive_shortcode( array $atts = array() ): string {
		unset( $atts );

		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_jobs';

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Public read-only job search/filter form; these GET params only control listing filters and never trigger state changes, so nonce verification is not applicable.
		$type_filter       = isset( $_GET['job_type'] ) ? sanitize_text_field( wp_unslash( $_GET['job_type'] ) ) : '';
		$search_filter     = isset( $_GET['job_search'] ) ? sanitize_text_field( wp_unslash( $_GET['job_search'] ) ) : '';
		$location_filter   = isset( $_GET['job_location'] ) ? sanitize_text_field( wp_unslash( $_GET['job_location'] ) ) : '';
		$category_filter   = isset( $_GET['job_category'] ) ? sanitize_text_field( wp_unslash( $_GET['job_category'] ) ) : '';
		$industry_filter   = isset( $_GET['job_industry'] ) ? sanitize_text_field( wp_unslash( $_GET['job_industry'] ) ) : '';
		$company_filter    = isset( $_GET['company'] ) ? sanitize_text_field( wp_unslash( $_GET['company'] ) ) : '';
		$salary_min_filter = isset( $_GET['salary_min'] ) ? absint( $_GET['salary_min'] ) : 0;
		$salary_max_filter = isset( $_GET['salary_max'] ) ? absint( $_GET['salary_max'] ) : 0;
		$experience_filter = isset( $_GET['experience_level'] ) ? sanitize_text_field( wp_unslash( $_GET['experience_level'] ) ) : '';
		$remote_filter     = isset( $_GET['remote_option'] ) ? sanitize_text_field( wp_unslash( $_GET['remote_option'] ) ) : '';
		$date_range_filter = isset( $_GET['date_range'] ) ? absint( $_GET['date_range'] ) : 0;
		$sort_filter       = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : '';
		$nearby_lat        = isset( $_GET['nearby_lat'] ) ? (float) $_GET['nearby_lat'] : 0;
		$nearby_lng        = isset( $_GET['nearby_lng'] ) ? (float) $_GET['nearby_lng'] : 0;
		$nearby_radius     = isset( $_GET['nearby_radius'] ) ? absint( $_GET['nearby_radius'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$where  = "WHERE status = 'publish'";
		$params = array();
		$join   = '';

		if ( ! empty( $type_filter ) ) {
			$where   .= ' AND j.type = %s';
			$params[] = $type_filter;
		}

		if ( ! empty( $search_filter ) ) {
			$like     = '%' . $wpdb->esc_like( $search_filter ) . '%';
			$where   .= ' AND (j.title LIKE %s OR j.description LIKE %s OR j.excerpt LIKE %s OR j.location LIKE %s OR j.company_industry LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
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
			$where       .= ' AND (u.display_name LIKE %s OR c.name LIKE %s)';
			$params[]     = $company_like;
			$params[]     = $company_like;
			if ( false === strpos( $join, 'LEFT JOIN' ) ) {
				$join .= " LEFT JOIN {$wpdb->users} u ON u.ID = j.employer_id";
			}
			if ( false === strpos( $join, 'zeko_companies' ) ) {
				$join .= " LEFT JOIN {$wpdb->prefix}zeko_companies c ON c.employer_id = j.employer_id";
			}
		}

		if ( $salary_min_filter > 0 ) {
			$where   .= ' AND j.salary_max >= %d';
			$params[] = $salary_min_filter;
		}

		if ( $salary_max_filter > 0 ) {
			$where   .= ' AND j.salary_min <= %d';
			$params[] = $salary_max_filter;
		}

		if ( ! empty( $experience_filter ) ) {
			$where   .= ' AND j.experience_level = %s';
			$params[] = $experience_filter;
		}

		if ( ! empty( $remote_filter ) ) {
			$where   .= ' AND j.remote_option = %s';
			$params[] = $remote_filter;
		}

		if ( $date_range_filter > 0 ) {
			$where   .= ' AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
			$params[] = $date_range_filter;
		}

		// Haversine "Near Me" support for initial page load.
		$distance_select = '';
		if ( 0.0 !== $nearby_lat && 0.0 !== $nearby_lng && $nearby_radius > 0 ) {
			$distance_select = ', (3959 * ACOS( COS(RADIANS(%f)) * COS(RADIANS(j.latitude)) * COS(RADIANS(j.longitude) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(j.latitude)) )) AS distance';
			$where          .= ' AND j.latitude IS NOT NULL AND j.longitude IS NOT NULL AND (3959 * ACOS( COS(RADIANS(%f)) * COS(RADIANS(j.latitude)) * COS(RADIANS(j.longitude) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(j.latitude)) )) <= %d';
			$params          = array_merge( array( $nearby_lat, $nearby_lng, $nearby_lat ), $params, array( $nearby_lat, $nearby_lng, $nearby_lat, $nearby_radius ) );
		}

		// Always join users for company name display.
		if ( false === strpos( $join, 'LEFT JOIN ' . $wpdb->users ) ) {
			$join .= " LEFT JOIN {$wpdb->users} u ON u.ID = j.employer_id";
		}

		$per_page     = 10;
		$current_page = max( 1, (int) get_query_var( 'paged', 1 ) );
		$offset       = ( $current_page - 1 ) * $per_page;

		// Build ORDER BY with relevance scoring for keyword search.
		if ( ! empty( $search_filter ) ) {
			$like_relevance   = '%' . $wpdb->esc_like( $search_filter ) . '%';
			$like_starts      = $wpdb->esc_like( $search_filter ) . '%';
			$order_case       = 'CASE'
				. ' WHEN j.title LIKE %s THEN 100'
				. ' WHEN j.title LIKE %s THEN 80'
				. ' WHEN j.description LIKE %s THEN 60'
				. ' WHEN j.excerpt LIKE %s THEN 40'
				. ' WHEN j.location LIKE %s THEN 30'
				. ' WHEN j.company_industry LIKE %s THEN 20'
				. ' WHEN u.display_name LIKE %s THEN 10'
				. ' ELSE 0 END';
			$relevance_params = array(
				$like_starts,  // title starts with.
				$like_relevance, // title contains.
				$like_relevance, // description.
				$like_relevance, // excerpt.
				$like_relevance, // location.
				$like_relevance, // industry.
				$like_relevance, // company name.
			);

			$sort_order = 'DESC';
			switch ( $sort_filter ) {
				case 'date':
					$order = 'j.created_at DESC';
					break;
				case 'salary_high':
					$order = 'j.salary_max DESC, j.salary_min DESC';
					break;
				case 'salary_low':
					$order = 'j.salary_min ASC, j.salary_max ASC';
					break;
				case 'company_az':
					$order = 'u.display_name ASC';
					break;
				case 'distance':
					$order = 0.0 !== $nearby_lat && 0.0 !== $nearby_lng ? 'distance ASC' : 'j.is_featured DESC, j.created_at DESC';
					break;
				case 'relevance':
				default:
					$order = '(' . $order_case . ') DESC, j.is_featured DESC, j.created_at DESC';
					break;
			}
		} else {
			$relevance_params = array();
			switch ( $sort_filter ) {
				case 'date':
					$order = 'j.created_at DESC';
					break;
				case 'salary_high':
					$order = 'j.salary_max DESC, j.salary_min DESC';
					break;
				case 'salary_low':
					$order = 'j.salary_min ASC, j.salary_max ASC';
					break;
				case 'company_az':
					$order = 'u.display_name ASC';
					break;
				case 'distance':
					$order = 0.0 !== $nearby_lat && 0.0 !== $nearby_lng ? 'distance ASC' : 'j.is_featured DESC, j.created_at DESC';
					break;
				default:
					$order = 'j.is_featured DESC, j.created_at DESC';
					break;
			}
		}

		/**
		 * Filter the job search SQL query components.
		 *
		 * @param array $query_args {
		 *     @type string $where   The SQL WHERE clause.
		 *     @type array  $params  The prepared statement parameters.
		 *     @type string $order   The SQL ORDER BY clause.
		 *     @type string $join    The SQL JOIN clause.
		 * }
		 * @param array $search_params Raw filter values from $_GET.
		 */
		$query_args = apply_filters(
			'zeko_job_search_args',
			array(
				'where'  => $where,
				'params' => $params,
				'order'  => $order,
				'join'   => $join,
			),
			array(
				'type'             => $type_filter,
				'search'           => $search_filter,
				'location'         => $location_filter,
				'category'         => $category_filter,
				'industry'         => $industry_filter,
				'company'          => $company_filter,
				'salary_min'       => $salary_min_filter,
				'salary_max'       => $salary_max_filter,
				'experience_level' => $experience_filter,
				'remote_option'    => $remote_filter,
				'date_range'       => $date_range_filter,
				'sort'             => $sort_filter,
			)
		);

		$where  = $query_args['where'];
		$params = $query_args['params'];
		$order  = $query_args['order'];
		$join   = isset( $query_args['join'] ) ? $query_args['join'] : '';

		$table_apps = $wpdb->prefix . 'zeko_job_applications';
		$sql        = "SELECT j.*{$distance_select}, (SELECT COUNT(*) FROM {$table_apps} a WHERE a.job_id = j.id) AS application_count FROM {$table_name} j {$join} {$where} ORDER BY {$order} LIMIT %d OFFSET %d";

		// Relevance params go after WHERE params (ORDER BY CASE placeholders follow WHERE placeholders).
		$all_params = array_merge( $params, $relevance_params, array( $per_page, $offset ) );

		$jobs = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// Count for pagination.
		$count_sql  = "SELECT COUNT(*) FROM {$table_name} {$where}";
		$total_jobs = 0;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( empty( $params ) ) {
			$total_jobs = (int) $wpdb->get_var( $count_sql );
		} else {
			// $wpdb->prepare requires placeholders count match; our $where already includes placeholders.
			$total_jobs = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$total_pages = ( $per_page > 0 ) ? (int) ceil( $total_jobs / $per_page ) : 1;

		$zeko_db       = Zeko_Jobs_DB::get_instance();
		$all_locations = $zeko_db->get_distinct_locations();

		ob_start();
		?>
		<div class="zeko-jobs-archive">
			<a href="#zeko-job-results" class="zeko-skip-link"><?php esc_html_e( 'Skip to job listings', 'zeko-jobs' ); ?></a>
			<div class="zeko-live-region" aria-live="polite" aria-atomic="true" id="zeko-aria-live"></div>
			<?php
			$bookmarked_ids = array();
			$applied_ids    = array();
			if ( is_user_logged_in() ) :
				$current_user_id    = get_current_user_id();
				$bookmarked_ids_raw = get_user_meta( $current_user_id, 'zeko_bookmarked_jobs', true );
				if ( is_array( $bookmarked_ids_raw ) ) {
					$bookmarked_ids = array_map( 'intval', $bookmarked_ids_raw );
				}
				$applied_ids = $zeko_db->get_applied_job_ids( $current_user_id );
				endif;
			?>

			<h2 class="archive-title"><?php esc_html_e( 'Job Listings', 'zeko-jobs' ); ?></h2>

			<p class="zeko-jobs-results-count">
				<?php
				printf(
					/* translators: 1: number of jobs found, 2: search or type filter label */
					esc_html( _n( '%1$d job found', '%1$d jobs found', $total_jobs, 'zeko-jobs' ) ),
					(int) $total_jobs
				);
				if ( ! empty( $search_filter ) || ! empty( $type_filter ) || ! empty( $location_filter ) ) {
					$labels = array();
					if ( ! empty( $search_filter ) ) {
						/* translators: %s: search keyword */
						$labels[] = sprintf( esc_html__( 'matching "%s"', 'zeko-jobs' ), esc_html( $search_filter ) );
					}
					if ( ! empty( $location_filter ) ) {
						/* translators: %s: location name */
						$labels[] = sprintf( esc_html__( 'in %s', 'zeko-jobs' ), esc_html( $location_filter ) );
					}
					if ( ! empty( $type_filter ) ) {
						/* translators: %s: job type */
						$labels[] = sprintf( esc_html__( 'type: %s', 'zeko-jobs' ), esc_html( ucfirst( $type_filter ) ) );
					}
					if ( ! empty( $category_filter ) ) {
						/* translators: %s: job category */
						$labels[] = sprintf( esc_html__( 'category: %s', 'zeko-jobs' ), esc_html( ucfirst( $category_filter ) ) );
					}
					if ( ! empty( $industry_filter ) ) {
						/* translators: %s: industry name */
						$labels[] = sprintf( esc_html__( 'industry: %s', 'zeko-jobs' ), esc_html( $industry_filter ) );
					}
					if ( ! empty( $company_filter ) ) {
						/* translators: %s: company name */
						$labels[] = sprintf( esc_html__( 'company: %s', 'zeko-jobs' ), esc_html( $company_filter ) );
					}
					if ( ! empty( $experience_filter ) ) {
						/* translators: %s: experience level */
						$labels[] = sprintf( esc_html__( 'level: %s', 'zeko-jobs' ), esc_html( ucfirst( $experience_filter ) ) );
					}
					if ( ! empty( $remote_filter ) ) {
						/* translators: %s: remote work option label */
						$labels[] = esc_html( ucfirst( $remote_filter ) );
					}
					if ( $date_range_filter > 0 ) {
						/* translators: %d: number of days */
						$labels[] = sprintf( esc_html__( 'last %d days', 'zeko-jobs' ), $date_range_filter );
					}
					if ( $salary_min_filter > 0 || $salary_max_filter > 0 ) {
						$sal_label = '$' . number_format( $salary_min_filter ) . ' – $' . ( $salary_max_filter > 0 ? number_format( $salary_max_filter ) : '200,000+' );
						/* translators: %s: salary range */
						$labels[] = sprintf( esc_html__( 'salary: %s', 'zeko-jobs' ), esc_html( $sal_label ) );
					}
					echo ' ' . esc_html( implode( ' ', $labels ) );
				}
				?>
			</p>

				<?php if ( is_user_logged_in() ) : ?>
					<?php
					$current_user_id  = get_current_user_id();
					$zeko_db          = Zeko_Jobs_DB::get_instance();
					$recommended_jobs = $zeko_db->get_recommended_jobs( $current_user_id );
					if ( ! empty( $recommended_jobs ) ) :
						?>
					<div class="zeko-recommended-jobs">
						<h3 class="zeko-recommended-title"><?php esc_html_e( 'Recommended for You', 'zeko-jobs' ); ?></h3>
						<div class="zeko-jobs-grid">
							<?php
							foreach ( $recommended_jobs as $rec_job ) :
								$rec_salary = '';
								if ( ! empty( $rec_job['salary_min'] ) || ! empty( $rec_job['salary_max'] ) ) {
									$rec_salary = '$' . number_format_i18n( (int) $rec_job['salary_min'] ) . ' - $' . number_format_i18n( (int) $rec_job['salary_max'] );
								}
								?>
								<div class="zeko-job-card">
									<div class="zeko-job-card-header">
										<h4><a href="<?php echo esc_url( home_url( '/jobs/' . $rec_job['slug'] ) ); ?>"><?php echo esc_html( $rec_job['title'] ); ?></a></h4>
										<?php if ( ! empty( $rec_job['is_featured'] ) ) : ?>
											<span class="zeko-featured-badge" title="<?php esc_attr_e( 'Featured', 'zeko-jobs' ); ?>">&#9733;</span>
										<?php endif; ?>
									</div>
									<div class="zeko-job-card-meta">
										<span><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $rec_job['location'] ); ?></span>
										<span><span class="dashicons dashicons-portfolio" aria-hidden="true"></span> <?php echo esc_html( ucfirst( str_replace( '-', ' ', $rec_job['type'] ) ) ); ?></span>
										<?php if ( ! empty( $rec_job['category'] ) ) : ?>
											<span><span class="dashicons dashicons-category" aria-hidden="true"></span> <?php echo esc_html( ucfirst( $rec_job['category'] ) ); ?></span>
										<?php endif; ?>
										<?php if ( $rec_salary ) : ?>
											<span><span class="dashicons dashicons-money" aria-hidden="true"></span> <?php echo esc_html( $rec_salary ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
			<?php endif; ?>

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			<form class="zeko-jobs-filter" method="get" role="search" aria-label="<?php esc_attr_e( 'Filter jobs', 'zeko-jobs' ); ?>">
				<div class="filter-group">
					<input type="text" name="job_search" placeholder="<?php esc_attr_e( 'Search jobs...', 'zeko-jobs' ); ?>" value="<?php echo esc_attr( $search_filter ); ?>">
				</div>
				<div class="filter-group">
					<input type="text" name="job_location" list="zeko-location-list" placeholder="<?php esc_attr_e( 'Location...', 'zeko-jobs' ); ?>" value="<?php echo esc_attr( $location_filter ); ?>" autocomplete="off">
					<datalist id="zeko-location-list">
							<?php foreach ( $all_locations as $loc ) : ?>
							<option value="<?php echo esc_attr( $loc ); ?>">
						<?php endforeach; ?>
					</datalist>
				</div>
			<div class="filter-group">
				<select name="job_type">
					<option value=""><?php esc_html_e( 'All Types', 'zeko-jobs' ); ?></option>
					<option value="full-time" <?php selected( $type_filter, 'full-time' ); ?>><?php esc_html_e( 'Full Time', 'zeko-jobs' ); ?></option>
					<option value="part-time" <?php selected( $type_filter, 'part-time' ); ?>><?php esc_html_e( 'Part Time', 'zeko-jobs' ); ?></option>
					<option value="contract" <?php selected( $type_filter, 'contract' ); ?>><?php esc_html_e( 'Contract', 'zeko-jobs' ); ?></option>
					<option value="freelance" <?php selected( $type_filter, 'freelance' ); ?>><?php esc_html_e( 'Freelance', 'zeko-jobs' ); ?></option>
				</select>
			</div>
			<div class="filter-group">
				<select name="job_category">
					<option value=""><?php esc_html_e( 'All Categories', 'zeko-jobs' ); ?></option>
					<option value="technology" <?php selected( $category_filter ?? '', 'technology' ); ?>><?php esc_html_e( 'Technology', 'zeko-jobs' ); ?></option>
					<option value="marketing" <?php selected( $category_filter ?? '', 'marketing' ); ?>><?php esc_html_e( 'Marketing', 'zeko-jobs' ); ?></option>
					<option value="design" <?php selected( $category_filter ?? '', 'design' ); ?>><?php esc_html_e( 'Design', 'zeko-jobs' ); ?></option>
					<option value="sales" <?php selected( $category_filter ?? '', 'sales' ); ?>><?php esc_html_e( 'Sales', 'zeko-jobs' ); ?></option>
					<option value="finance" <?php selected( $category_filter ?? '', 'finance' ); ?>><?php esc_html_e( 'Finance', 'zeko-jobs' ); ?></option>
					<option value="healthcare" <?php selected( $category_filter ?? '', 'healthcare' ); ?>><?php esc_html_e( 'Healthcare', 'zeko-jobs' ); ?></option>
					<option value="education" <?php selected( $category_filter ?? '', 'education' ); ?>><?php esc_html_e( 'Education', 'zeko-jobs' ); ?></option>
					<option value="engineering" <?php selected( $category_filter ?? '', 'engineering' ); ?>><?php esc_html_e( 'Engineering', 'zeko-jobs' ); ?></option>
				</select>
			</div>
			<div class="filter-group">
				<select name="job_industry">
					<option value=""><?php esc_html_e( 'All Industries', 'zeko-jobs' ); ?></option>
						<?php
						$industries = $wpdb->get_col( "SELECT DISTINCT company_industry FROM {$table_name} WHERE company_industry != '' ORDER BY company_industry ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						foreach ( $industries as $ind ) :
							?>
						<option value="<?php echo esc_attr( $ind ); ?>" <?php selected( $industry_filter ?? '', $ind ); ?>><?php echo esc_html( $ind ); ?></option>
						<?php endforeach; ?>
				</select>
			</div>
			<div class="filter-group">
				<select name="experience_level">
					<option value=""><?php esc_html_e( 'All Levels', 'zeko-jobs' ); ?></option>
					<option value="entry" <?php selected( $experience_filter, 'entry' ); ?>><?php esc_html_e( 'Entry Level', 'zeko-jobs' ); ?></option>
					<option value="mid" <?php selected( $experience_filter, 'mid' ); ?>><?php esc_html_e( 'Mid Level', 'zeko-jobs' ); ?></option>
					<option value="senior" <?php selected( $experience_filter, 'senior' ); ?>><?php esc_html_e( 'Senior', 'zeko-jobs' ); ?></option>
					<option value="lead" <?php selected( $experience_filter, 'lead' ); ?>><?php esc_html_e( 'Lead', 'zeko-jobs' ); ?></option>
					<option value="executive" <?php selected( $experience_filter, 'executive' ); ?>><?php esc_html_e( 'Executive', 'zeko-jobs' ); ?></option>
				</select>
			</div>
			<div class="filter-group">
				<select name="remote_option">
					<option value=""><?php esc_html_e( 'Work Style', 'zeko-jobs' ); ?></option>
					<option value="remote" <?php selected( $remote_filter, 'remote' ); ?>><?php esc_html_e( 'Remote', 'zeko-jobs' ); ?></option>
					<option value="hybrid" <?php selected( $remote_filter, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid', 'zeko-jobs' ); ?></option>
					<option value="onsite" <?php selected( $remote_filter, 'onsite' ); ?>><?php esc_html_e( 'On-site', 'zeko-jobs' ); ?></option>
				</select>
			</div>
			<div class="filter-group">
				<select name="date_range">
					<option value=""><?php esc_html_e( 'Any Time', 'zeko-jobs' ); ?></option>
					<option value="1" <?php selected( $date_range_filter, 1 ); ?>><?php esc_html_e( 'Last 24 Hours', 'zeko-jobs' ); ?></option>
					<option value="7" <?php selected( $date_range_filter, 7 ); ?>><?php esc_html_e( 'Last 7 Days', 'zeko-jobs' ); ?></option>
					<option value="30" <?php selected( $date_range_filter, 30 ); ?>><?php esc_html_e( 'Last 30 Days', 'zeko-jobs' ); ?></option>
					<option value="90" <?php selected( $date_range_filter, 90 ); ?>><?php esc_html_e( 'Last 90 Days', 'zeko-jobs' ); ?></option>
				</select>
			</div>
		<div class="filter-group zeko-salary-filter">
					<label class="zeko-salary-label"><?php esc_html_e( 'Salary Range', 'zeko-jobs' ); ?></label>
					<div class="zeko-range-slider" data-min="0" data-max="200000" data-step="5000">
						<input type="hidden" name="salary_min" value="<?php echo esc_attr( $salary_min_filter ); ?>" class="zeko-range-min">
						<input type="hidden" name="salary_max" value="<?php echo esc_attr( $salary_max_filter ); ?>" class="zeko-range-max">
						<div class="zeko-range-track">
							<div class="zeko-range-fill"></div>
						</div>
						<div class="zeko-range-handle zeko-range-handle-min" tabindex="0" role="slider" aria-label="<?php esc_attr_e( 'Minimum salary', 'zeko-jobs' ); ?>" aria-valuemin="0" aria-valuemax="200000" aria-valuenow="<?php echo esc_attr( $salary_min_filter ); ?>"></div>
						<div class="zeko-range-handle zeko-range-handle-max" tabindex="0" role="slider" aria-label="<?php esc_attr_e( 'Maximum salary', 'zeko-jobs' ); ?>" aria-valuemin="0" aria-valuemax="200000" aria-valuenow="<?php echo esc_attr( $salary_max_filter ); ?>"></div>
					</div>
					<div class="zeko-range-values">
						<span class="zeko-range-val-min">$<?php echo esc_html( number_format( $salary_min_filter ) ); ?></span>
						<span class="zeko-range-sep">&ndash;</span>
						<span class="zeko-range-val-max">$<?php echo esc_html( $salary_max_filter > 0 ? number_format( $salary_max_filter ) : '200,000+' ); ?></span>
					</div>
				</div>
			<div class="filter-group">
					<?php
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
					$company_names = $wpdb->get_col(
						"SELECT DISTINCT u.display_name FROM {$table_name} j INNER JOIN {$wpdb->users} u ON u.ID = j.employer_id WHERE j.status = 'publish' AND u.display_name != '' ORDER BY u.display_name ASC"
					); // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
					?>
				<select name="company">
					<option value=""><?php esc_html_e( 'All Companies', 'zeko-jobs' ); ?></option>
					<?php foreach ( $company_names as $cname ) : ?>
						<option value="<?php echo esc_attr( $cname ); ?>" <?php selected( $company_filter, $cname ); ?>><?php echo esc_html( $cname ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
				<div class="filter-group zeko-near-me-group">
				<?php $nearby_active = ( 0.0 !== $nearby_lat && 0.0 !== $nearby_lng && $nearby_radius > 0 ); ?>
				<button type="button" class="zeko-near-me-btn<?php echo $nearby_active ? ' active' : ''; ?>" aria-label="<?php esc_attr_e( 'Find jobs near me', 'zeko-jobs' ); ?>" aria-pressed="<?php echo $nearby_active ? 'true' : 'false'; ?>">
					<span class="dashicons dashicons-location" aria-hidden="true"></span> <?php esc_html_e( 'Near Me', 'zeko-jobs' ); ?>
				</button>
				<select name="nearby_radius" class="zeko-nearby-radius"<?php echo $nearby_active ? '' : ' style="display:none;"'; ?>>
					<option value="5" <?php selected( $nearby_radius, 5 ); ?>><?php esc_html_e( '5 mi', 'zeko-jobs' ); ?></option>
					<option value="10" <?php selected( $nearby_radius, 10 ); ?>><?php esc_html_e( '10 mi', 'zeko-jobs' ); ?></option>
					<option value="25" <?php selected( $nearby_radius, 25 ); ?>><?php esc_html_e( '25 mi', 'zeko-jobs' ); ?></option>
					<option value="50" <?php selected( $nearby_radius, 50 ); ?>><?php esc_html_e( '50 mi', 'zeko-jobs' ); ?></option>
					<option value="100" <?php selected( $nearby_radius, 100 ); ?>><?php esc_html_e( '100 mi', 'zeko-jobs' ); ?></option>
				</select>
				<input type="hidden" name="nearby_lat" class="zeko-nearby-lat" value="<?php echo esc_attr( $nearby_lat ?: '' ); ?>">
				<input type="hidden" name="nearby_lng" class="zeko-nearby-lng" value="<?php echo esc_attr( $nearby_lng ?: '' ); ?>">
			</div>
			<div class="filter-group">
				<select name="sort">
					<option value=""><?php esc_html_e( 'Sort by', 'zeko-jobs' ); ?></option>
					<option value="relevance" <?php selected( $sort_filter, 'relevance' ); ?>><?php esc_html_e( 'Relevance', 'zeko-jobs' ); ?></option>
					<option value="date" <?php selected( $sort_filter, 'date' ); ?>><?php esc_html_e( 'Newest First', 'zeko-jobs' ); ?></option>
					<option value="salary_high" <?php selected( $sort_filter, 'salary_high' ); ?>><?php esc_html_e( 'Salary: High to Low', 'zeko-jobs' ); ?></option>
					<option value="salary_low" <?php selected( $sort_filter, 'salary_low' ); ?>><?php esc_html_e( 'Salary: Low to High', 'zeko-jobs' ); ?></option>
					<option value="company_az" <?php selected( $sort_filter, 'company_az' ); ?>><?php esc_html_e( 'Company A-Z', 'zeko-jobs' ); ?></option>
					<option value="distance" <?php selected( $sort_filter, 'distance' ); ?>><?php esc_html_e( 'Distance (Nearest)', 'zeko-jobs' ); ?></option>
				</select>
			</div>
			<div class="filter-group">
				<button type="submit" class="button"><?php esc_html_e( 'Filter Jobs', 'zeko-jobs' ); ?></button>
				<a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="button button-secondary zeko-clear-filters"><?php esc_html_e( 'Clear Filters', 'zeko-jobs' ); ?></a>
				<?php if ( is_user_logged_in() ) : ?>
					<button type="button" class="button zeko-save-search-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_dashboard' ) ); ?>">
						<?php esc_html_e( 'Save This Search', 'zeko-jobs' ); ?>
					</button>
				<?php endif; ?>
			</div>
			</form>

			<div class="zeko-jobs-loading" aria-hidden="true">
				<div class="zeko-skeleton-card">
					<div class="zeko-skeleton-line title"></div>
					<div class="zeko-skeleton-line short"></div>
					<div class="zeko-skeleton-line"></div>
					<div class="zeko-skeleton-line text"></div>
				</div>
			</div>

		<div class="zeko-toolbar">
			<div class="zeko-view-toggle">
				<button type="button" class="zeko-view-btn zeko-view-grid active" data-view="grid" aria-label="<?php esc_attr_e( 'Grid view', 'zeko-jobs' ); ?>">
					<span class="dashicons dashicons-grid-view" aria-hidden="true"></span>
				</button>
				<button type="button" class="zeko-view-btn zeko-view-list" data-view="list" aria-label="<?php esc_attr_e( 'List view', 'zeko-jobs' ); ?>">
					<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
				</button>
				<button type="button" class="zeko-view-btn zeko-view-map" data-view="map" aria-label="<?php esc_attr_e( 'Map view', 'zeko-jobs' ); ?>">
					<span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
				</button>
			</div>
				<?php if ( $total_pages > 1 ) : ?>
			<div class="zeko-page-mode-toggle">
				<button type="button" class="zeko-page-mode-btn active" data-mode="load-more" aria-label="<?php esc_attr_e( 'Load more mode', 'zeko-jobs' ); ?>">
					<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span> <?php esc_html_e( 'Load More', 'zeko-jobs' ); ?>
				</button>
				<button type="button" class="zeko-page-mode-btn" data-mode="paginated" aria-label="<?php esc_attr_e( 'Paginated mode', 'zeko-jobs' ); ?>">
					<span class="dashicons dashicons-controls-forward" aria-hidden="true"></span> <?php esc_html_e( 'Pages', 'zeko-jobs' ); ?>
				</button>
			</div>
			<?php endif; ?>
		</div>

			<div class="zeko-map-container" style="display:none;height:500px;border-radius:var(--radius-lg, 12px);margin-bottom:24px;"></div>

				<?php
				$map_jobs = array();
				foreach ( $jobs as $mj ) {
					if ( ! empty( $mj['latitude'] ) && ! empty( $mj['longitude'] ) ) {
						$map_jobs[] = array(
							'id'       => (int) $mj['id'],
							'title'    => $mj['title'],
							'slug'     => $mj['slug'],
							'location' => $mj['location'],
							'type'     => $mj['type'],
							'lat'      => (float) $mj['latitude'],
							'lng'      => (float) $mj['longitude'],
							'salary'   => ( ! empty( $mj['salary_min'] ) || ! empty( $mj['salary_max'] ) )
															? '$' . number_format( $mj['salary_min'] ) . ' – $' . number_format( (int) $mj['salary_max'] )
															: '',
						);
					}
				}
				?>
			<script>
			var zekoJobMapData = <?php echo wp_json_encode( $map_jobs ); ?>;
			</script>

		<div class="zeko-jobs-grid" id="zeko-job-results" data-page="1" data-total-pages="<?php echo esc_attr( $total_pages ); ?>" role="region" aria-label="<?php esc_attr_e( 'Job listings', 'zeko-jobs' ); ?>">
			<?php if ( ! empty( $jobs ) ) : ?>
				<?php
					$current_user_id = is_user_logged_in() ? get_current_user_id() : 0;
					$is_seeker       = $current_user_id > 0;

					$match_scores  = array();
					$skills_counts = array();
				if ( $is_seeker ) {
					$job_ids_for_match = array_column( $jobs, 'id' );
					$match_scores      = $zeko_db->get_bulk_match_scores( $current_user_id, $job_ids_for_match );
					$skills_counts     = $zeko_db->get_bulk_skills_counts( $current_user_id, $job_ids_for_match );
				}

				// Hoist per-user queries outside the loop to avoid N+1.
				$seeker_has_resume    = false;
				$seeker_documents     = array();
				$seeker_cover_tpls    = array();
				$seeker_resumes       = array();
				$seeker_default_doc   = 0;
				$seeker_resume_url    = '';
				$seeker_has_resumes   = false;
				$seeker_active_resume = null;

				if ( $is_seeker ) {
					$seeker_has_resume = ! empty( get_user_meta( $current_user_id, 'zeko_saved_resume_url', true ) );
					$seeker_documents  = get_user_meta( $current_user_id, 'zeko_documents', true );
					if ( ! is_array( $seeker_documents ) ) {
						$seeker_documents = array();
					}
					$seeker_has_resume = $seeker_has_resume || ! empty( $seeker_documents );

					$seeker_cover_tpls  = get_user_meta( $current_user_id, 'zeko_cover_templates', true );
					$seeker_resumes     = $zeko_db->get_resumes( $current_user_id );
					$seeker_default_doc = $zeko_db->get_user_default_resume_id( $current_user_id );
					$seeker_resume_url  = get_user_meta( $current_user_id, 'zeko_saved_resume_url', true );
					$seeker_has_resumes = ! empty( $seeker_resumes );

					if ( $seeker_has_resumes ) {
						foreach ( $seeker_resumes as $sr ) {
							if ( (int) $sr['id'] === $seeker_default_doc ) {
								$seeker_active_resume = $sr;
								break;
							}
						}
						if ( ! $seeker_active_resume ) {
							$seeker_active_resume = reset( $seeker_resumes );
						}
						$seeker_resume_url = $seeker_active_resume['file_url'];
					}
				}

				foreach ( $jobs as $job ) :
					$employer_id  = (int) $job['employer_id'];
					$employer     = $employer_id > 0 ? get_userdata( $employer_id ) : false;
					$company_name = $employer ? $employer->display_name : __( 'N/A', 'zeko-jobs' );

					$type_icons = array(
						'full-time' => 'dashicons-portfolio',
						'part-time' => 'dashicons-clock',
						'contract'  => 'dashicons-clipboard',
						'freelance' => 'dashicons-randomize',
					);
					$type_icon  = isset( $type_icons[ $job['type'] ] ) ? $type_icons[ $job['type'] ] : 'dashicons-tag';

					$logo_id = ! empty( $job['company_logo_id'] ) ? (int) $job['company_logo_id'] : 0;
					$is_new  = strtotime( $job['created_at'] ) > strtotime( '-3 days' );
					$remote  = ! empty( $job['remote_option'] ) ? $job['remote_option'] : '';
					$is_easy = ! empty( $job['is_easy_apply'] );

					ob_start();
					?>
			<article class="zeko-job-card<?php echo ! empty( $job['is_featured'] ) ? ' is-featured' : ''; ?>" data-job-id="<?php echo esc_attr( $job['id'] ); ?>" aria-label="<?php /* translators: 1: job title. 2: company name */ echo esc_attr( sprintf( __( '%1$s at %2$s', 'zeko-jobs' ), $job['title'], $company_name ) ); ?>">
					<div class="zeko-card-top">
					<?php if ( is_user_logged_in() && $employer_id !== $current_user_id ) : ?>
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
								<div class="zeko-card-logo zeko-card-logo-placeholder">
									<span><?php echo esc_html( mb_substr( $company_name, 0, 2 ) ); ?></span>
								</div>
							<?php endif; ?>
						</div>
						<div class="zeko-card-info">
							<div class="zeko-card-badges">
							<?php if ( ! empty( $job['is_featured'] ) ) : ?>
									<span class="zeko-badge zeko-badge-featured"><?php esc_html_e( 'Featured', 'zeko-jobs' ); ?></span>
								<?php endif; ?>
							<?php if ( $is_new ) : ?>
									<span class="zeko-badge zeko-badge-new"><?php esc_html_e( 'New', 'zeko-jobs' ); ?></span>
								<?php endif; ?>
							<?php
							$job_id_int  = (int) $job['id'];
							$match_score = $match_scores[ $job_id_int ] ?? null;
							if ( null !== $match_score && $match_score > 0 ) :
								$match_class = $match_score >= 80 ? 'match-high' : ( $match_score >= 50 ? 'match-medium' : 'match-low' );
								?>
									<span class="zeko-badge zeko-badge-match <?php echo esc_attr( $match_class ); ?>" title="<?php /* translators: %d: match percentage */ echo esc_attr( sprintf( __( '%d%% match with your profile', 'zeko-jobs' ), $match_score ) ); ?>">
										<span class="dashicons dashicons-star-filled" aria-hidden="true"></span> <?php echo esc_html( $match_score ); ?>% <?php esc_html_e( 'match', 'zeko-jobs' ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $remote ) : ?>
									<span class="zeko-badge zeko-badge-remote">
										<span class="dashicons dashicons-admin-home" aria-hidden="true"></span>
										<?php echo esc_html( ucfirst( $remote ) ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $is_easy ) : ?>
									<span class="zeko-badge zeko-badge-easy">
										<span class="dashicons dashicons-rocket" aria-hidden="true"></span>
										<?php esc_html_e( 'Easy Apply', 'zeko-jobs' ); ?>
									</span>
								<?php endif; ?>
							</div>
							<h3 class="zeko-card-title">
								<a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] ) ); ?>"><?php echo esc_html( $job['title'] ); ?></a>
							</h3>
							<div class="zeko-card-company">
								<span class="zeko-card-company-name"><?php echo esc_html( $company_name ); ?></span>
								<?php if ( $zeko_db->is_employer_verified( $employer_id ) ) : ?>
									<span class="zeko-verified-badge" title="<?php esc_attr_e( 'Verified Employer', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span>
								<?php endif; ?>
								<?php
								$response_label = $zeko_db->get_employer_response_time_label( $employer_id );
								if ( $response_label ) :
									?>
									<span class="zeko-response-time" title="<?php echo esc_attr( $response_label ); ?>"><span class="dashicons dashicons-clock" aria-hidden="true"></span> <?php echo esc_html( $response_label ); ?></span>
								<?php endif; ?>
							</div>
						</div>
								<?php if ( is_user_logged_in() ) : ?>
									<?php $is_bookmarked = in_array( (int) $job['id'], $bookmarked_ids, true ); ?>
							<button
								type="button"
								class="zeko-bookmark-icon <?php echo $is_bookmarked ? 'is-bookmarked' : ''; ?>"
								data-job-id="<?php echo esc_attr( $job['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_bookmark' ) ); ?>"
								aria-label="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'zeko-jobs' ) : esc_attr__( 'Bookmark job', 'zeko-jobs' ); ?>"
							>
								<span class="dashicons <?php echo $is_bookmarked ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>" aria-hidden="true"></span>
							</button>
						<?php endif; ?>
					</div>

					<div class="zeko-card-meta">
						<span class="zeko-card-meta-item"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $job['location'] ); ?>
						<?php
						if ( $nearby_active && ! empty( $job['distance'] ) ) :
							?>
		<span class="zeko-card-distance">(<?php echo esc_html( number_format( (float) $job['distance'], 1 ) ); ?> mi)</span><?php endif; ?></span>
						<span class="zeko-card-meta-item"><span class="dashicons <?php echo esc_attr( $type_icon ); ?>" aria-hidden="true"></span> <?php echo esc_html( ucfirst( str_replace( '-', ' ', $job['type'] ) ) ); ?></span>
						<?php if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) : ?>
							<span class="zeko-card-meta-item zeko-card-salary">
								<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
								$<?php echo esc_html( number_format( (float) $job['salary_min'] ) ); ?> &ndash; $<?php echo esc_html( number_format( (float) $job['salary_max'] ) ); ?>
							</span>
						<?php endif; ?>
						<?php $app_count = isset( $job['application_count'] ) ? (int) $job['application_count'] : 0; ?>
						<span class="zeko-card-meta-item"><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php /* translators: %d: number of applicants */ echo esc_html( sprintf( _n( '%d applicant', '%d applicants', $app_count, 'zeko-jobs' ), $app_count ) ); ?></span>
					</div>

					<div class="zeko-card-badges-bottom">
						<?php if ( $app_count > 0 && $is_seeker && $employer_id !== $current_user_id ) : ?>
							<?php
							if ( $app_count <= 5 ) {
								$comp_class = 'zeko-comp-low';
								$comp_label = __( 'Low competition', 'zeko-jobs' );
							} elseif ( $app_count <= 15 ) {
								$comp_class = 'zeko-comp-medium';
								$comp_label = __( 'Medium competition', 'zeko-jobs' );
							} else {
								$comp_class = 'zeko-comp-high';
								$comp_label = __( 'High competition', 'zeko-jobs' );
							}
							?>
							<span class="zeko-competition-badge <?php echo esc_attr( $comp_class ); ?>"><?php echo esc_html( $comp_label ); ?></span>
						<?php endif; ?>
								<?php
								if ( $is_seeker && $employer_id !== $current_user_id ) :
									$m_score = $match_scores[ (int) $job['id'] ] ?? 0;
									if ( $m_score >= 75 ) {
										$m_class = 'zeko-match-high';
									} elseif ( $m_score >= 50 ) {
										$m_class = 'zeko-match-mid';
									} else {
										$m_class = 'zeko-match-low';
									}
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

							<?php if ( $is_seeker && $employer_id !== $current_user_id ) : ?>
								<?php
								$seeker_id   = $current_user_id;
								$has_resume  = $seeker_has_resume;
								$has_applied = in_array( (int) $job['id'], $applied_ids ?? array(), true );
								?>
						<div class="zeko-card-actions">
								<?php if ( $has_applied ) : ?>
								<span class="zeko-card-applied-label"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Applied', 'zeko-jobs' ); ?></span>
							<?php else : ?>
								<?php if ( $is_easy && $has_resume ) : ?>
									<button type="button" class="button zeko-one-click-apply-btn" data-job-id="<?php echo esc_attr( $job['id'] ); ?>" title="<?php esc_attr_e( 'Apply instantly with saved resume', 'zeko-jobs' ); ?>">
										<span class="dashicons dashicons-rocket" aria-hidden="true"></span> <?php esc_html_e( 'Quick Apply', 'zeko-jobs' ); ?>
									</button>
								<?php endif; ?>
								<button type="button" class="button button-primary zeko-job-apply-btn" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
									<?php esc_html_e( 'Apply', 'zeko-jobs' ); ?>
								</button>
							<?php endif; ?>
						</div>

					<div id="zeko-job-apply-form-wrapper-<?php echo esc_attr( $job['id'] ); ?>" style="display:none;" class="zeko-job-apply-form-wrapper">
						<form class="zeko-job-apply-form zeko-form" method="post" enctype="multipart/form-data">
							<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>">
							<input type="hidden" name="zeko_job_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zeko_job_apply' ) ); ?>">
								<?php
								$cover_templates = $seeker_cover_tpls;
								if ( is_array( $cover_templates ) && ! empty( $cover_templates ) ) :
									?>
								<div class="zeko-field">
									<label for="zeko-template-select-<?php echo esc_attr( $job['id'] ); ?>"><?php esc_html_e( 'Cover Letter Template', 'zeko-jobs' ); ?></label>
									<select id="zeko-template-select-<?php echo esc_attr( $job['id'] ); ?>" class="zeko-template-select" data-target="zeko-cover-letter-<?php echo esc_attr( $job['id'] ); ?>">
										<option value=""><?php esc_html_e( '-- Write your own --', 'zeko-jobs' ); ?></option>
										<?php foreach ( $cover_templates as $tid => $tpl ) : ?>
											<option value="<?php echo esc_attr( $tid ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<?php endif; ?>
								<div class="zeko-field">
									<label for="zeko-cover-letter-<?php echo esc_attr( $job['id'] ); ?>"><?php esc_html_e( 'Cover Letter', 'zeko-jobs' ); ?></label>
									<textarea id="zeko-cover-letter-<?php echo esc_attr( $job['id'] ); ?>" name="cover_letter" rows="5" required></textarea>
									<?php if ( class_exists( 'Zeko_AI_Writer_UI' ) ) : ?>
										<?php
										echo wp_kses_post(
											(string) Zeko_AI_Writer_UI::button(
												array(
													'preset' => 'cover_letter',
													'target' => '#zeko-cover-letter-' . esc_attr( $job['id'] ),
												)
											)
										);
										?>
									<?php endif; ?>
								</div>
								<?php
								$documents        = $seeker_documents;
								$resumes          = $seeker_resumes;
								$default_doc_id   = $seeker_default_doc;
								$saved_resume_url = $seeker_resume_url;
								$has_resumes      = $seeker_has_resumes;

								if ( $has_resumes ) {
									$active_resume    = $seeker_active_resume;
									$saved_resume_url = $active_resume['file_url'];
								}
								$has_saved_resume = ! empty( $saved_resume_url );
								?>
								<?php if ( $has_resumes ) : ?>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Resume', 'zeko-jobs' ); ?></label>
								<div class="zeko-resume-choice">
									<label class="zeko-radio-label">
										<input type="radio" name="resume_mode" value="saved" class="zeko-resume-mode" checked>
										<?php esc_html_e( 'Use saved resume', 'zeko-jobs' ); ?>
									</label>
									<?php if ( count( $resumes ) > 1 ) : ?>
									<select name="saved_resume_id" class="zeko-resume-select" style="margin-left:8px;">
										<?php foreach ( $resumes as $r ) : ?>
											<option value="<?php echo esc_attr( $r['id'] ); ?>" data-url="<?php echo esc_url( $r['file_url'] ); ?>"<?php echo (int) $r['id'] === $default_doc_id ? ' selected' : ''; ?>>
												<?php echo esc_html( ! empty( $r['label'] ) ? $r['label'] : $r['filename'] ); ?>
												<?php
												if ( (int) $r['id'] === $default_doc_id ) :
													?>
													(<?php esc_html_e( 'Default', 'zeko-jobs' ); ?>)<?php endif; ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php else : ?>
										<span class="zeko-saved-resume-name"><?php echo esc_html( ! empty( $active_resume['label'] ) ? $active_resume['label'] : $active_resume['filename'] ); ?></span>
									<?php endif; ?>
									<label class="zeko-radio-label">
										<input type="radio" name="resume_mode" value="new" class="zeko-resume-mode">
										<?php esc_html_e( 'Upload new resume', 'zeko-jobs' ); ?>
									</label>
								</div>
								<input type="file" id="zeko-resume-<?php echo esc_attr( $job['id'] ); ?>" name="resume" accept="application/pdf,.pdf,.doc,.docx" class="zeko-resume-file-input" style="display:none;">
								<input type="hidden" name="saved_resume_url" value="<?php echo esc_url( $saved_resume_url ); ?>">
							</div>
							<?php elseif ( $has_saved_resume ) : ?>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Resume', 'zeko-jobs' ); ?></label>
								<div class="zeko-resume-choice">
									<label class="zeko-radio-label">
										<input type="radio" name="resume_mode" value="saved" class="zeko-resume-mode" checked>
										<?php esc_html_e( 'Use saved resume', 'zeko-jobs' ); ?>
										<span class="zeko-saved-resume-name"><?php echo esc_html( basename( $saved_resume_url ) ); ?></span>
									</label>
									<label class="zeko-radio-label">
										<input type="radio" name="resume_mode" value="new" class="zeko-resume-mode">
										<?php esc_html_e( 'Upload new resume', 'zeko-jobs' ); ?>
									</label>
								</div>
								<input type="file" id="zeko-resume-<?php echo esc_attr( $job['id'] ); ?>" name="resume" accept="application/pdf,.pdf,.doc,.docx" class="zeko-resume-file-input" style="display:none;">
								<input type="hidden" name="saved_resume_url" value="<?php echo esc_url( $saved_resume_url ); ?>">
							</div>
							<?php else : ?>
							<div class="zeko-field">
								<label for="zeko-resume-<?php echo esc_attr( $job['id'] ); ?>"><?php esc_html_e( 'Resume (PDF/DOC)', 'zeko-jobs' ); ?></label>
								<input type="file" id="zeko-resume-<?php echo esc_attr( $job['id'] ); ?>" name="resume" accept="application/pdf,.pdf,.doc,.docx" required>
							</div>
							<?php endif; ?>
								<div class="zeko-field zeko-submit">
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Application', 'zeko-jobs' ); ?></button>
								</div>
								<div class="zeko-application-message zeko-inline-message"></div>
							</form>
						</div>
				<?php endif; ?>
				</article>
					<?php
					$card_html = ob_get_clean();
					echo apply_filters( 'zeko_job_archive_card', $card_html, $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw HTML card built with per-field escaping above; contains <form>/<input> markup so wp_kses_post() would strip required form elements. Filter lets integrators modify markup.
			endforeach;
				?>
							<?php else : ?>
										<p class="no-jobs-found"><?php esc_html_e( 'No jobs found matching your criteria.', 'zeko-jobs' ); ?></p>
										<?php endif; ?>

		</div>

			<?php if ( $total_pages > 1 ) : ?>
			<div class="zeko-load-more-wrap">
				<button type="button" class="button zeko-load-more-btn"><?php esc_html_e( 'Load More Jobs', 'zeko-jobs' ); ?></button>
				<span class="zeko-load-more-spinner" style="display:none;"><span class="dashicons dashicons-update" aria-hidden="true"></span></span>
				<p class="zeko-load-more-count">
				<?php
					printf(
						/* translators: 1: number of jobs shown. 2: total number of jobs */
						esc_html__( 'Showing %1$d of %2$d jobs', 'zeko-jobs' ),
						(int) count( $jobs ),
						(int) $total_jobs
					);
				?>
				</p>
			</div>

			<div class="zeko-pagination zeko-pagination-links">
				<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $current_page,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
				?>
			</div>
		<?php endif; ?>

				<?php if ( is_user_logged_in() ) : ?>
				<div class="zeko-bulk-bar" style="display:none;" role="status" aria-live="polite">
					<span class="zeko-bulk-count">0 <?php esc_html_e( 'jobs selected', 'zeko-jobs' ); ?></span>
					<button type="button" class="button button-primary zeko-bulk-apply-btn"><?php esc_html_e( 'Apply to Selected', 'zeko-jobs' ); ?></button>
					<button type="button" class="button zeko-bulk-clear-btn"><?php esc_html_e( 'Clear', 'zeko-jobs' ); ?></button>
				</div>

				<div id="zeko-bulk-apply-modal" class="zeko-modal" style="display:none;" role="dialog" aria-label="<?php esc_attr_e( 'Bulk Apply', 'zeko-jobs' ); ?>">
					<div class="zeko-modal-overlay"></div>
					<div class="zeko-modal-dialog">
						<div class="zeko-modal-header">
							<h3><?php esc_html_e( 'Apply to Selected Jobs', 'zeko-jobs' ); ?></h3>
							<button type="button" class="zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
						</div>
						<div class="zeko-modal-body">
							<p class="zeko-bulk-apply-info"></p>
							<form id="zeko-bulk-apply-form" class="zeko-form" enctype="multipart/form-data">
								<div class="zeko-field">
									<label for="zeko-bulk-cover-letter"><?php esc_html_e( 'Cover Letter', 'zeko-jobs' ); ?></label>
									<textarea id="zeko-bulk-cover-letter" name="cover_letter" rows="5" required placeholder="<?php esc_attr_e( 'Tell employers why you are a great fit...', 'zeko-jobs' ); ?>"></textarea>
								</div>
								<div class="zeko-field">
									<label for="zeko-bulk-resume"><?php esc_html_e( 'Resume (PDF or DOC/DOCX)', 'zeko-jobs' ); ?></label>
									<div class="zeko-file-upload" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload resume file', 'zeko-jobs' ); ?>">
										<span class="dashicons dashicons-upload" aria-hidden="true"></span>
										<span class="zeko-file-upload-text"><?php esc_html_e( 'Click to upload or drag & drop', 'zeko-jobs' ); ?></span>
										<span class="zeko-file-upload-hint"><?php esc_html_e( 'PDF, DOC, or DOCX â€” max 5MB', 'zeko-jobs' ); ?></span>
										<input type="file" id="zeko-bulk-resume" name="resume" accept=".pdf,.doc,.docx,application/pdf,.docx" required class="zeko-file-input">
									</div>
									<div class="zeko-file-preview" style="display:none;">
										<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
										<span class="zeko-file-name"></span>
										<span class="zeko-file-size"></span>
										<button type="button" class="zeko-file-remove" aria-label="<?php esc_attr_e( 'Remove file', 'zeko-jobs' ); ?>">&times;</button>
									</div>
								</div>
								<div class="zeko-field zeko-submit">
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Submit All Applications', 'zeko-jobs' ); ?></button>
								</div>
								<div class="zeko-bulk-apply-progress" style="display:none;">
									<div class="zeko-progress-bar"><div class="zeko-progress-fill"></div></div>
									<span class="zeko-progress-text">0 / 0</span>
								</div>
								<div class="zeko-bulk-apply-results" style="display:none;"></div>
							</form>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<nav class="zeko-mobile-nav" aria-label="<?php esc_attr_e( 'Job navigation', 'zeko-jobs' ); ?>">
			<a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="zeko-mobile-nav-item active">
				<span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Jobs', 'zeko-jobs' ); ?></span>
			</a>
				<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( home_url( '/jobs/?dashboard=seeker' ) ); ?>" class="zeko-mobile-nav-item">
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></span>
				</a>
				<a href="<?php echo esc_url( home_url( '/messages/' ) ); ?>" class="zeko-mobile-nav-item">
					<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Messages', 'zeko-jobs' ); ?></span>
				</a>
				<a href="<?php echo esc_url( get_author_posts_url( get_current_user_id() ) ); ?>" class="zeko-mobile-nav-item">
					<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Profile', 'zeko-jobs' ); ?></span>
				</a>
			<?php endif; ?>
		</nav>
			<?php
			return ob_get_clean();
	}

	/**
	 * [zeko_jobs_featured limit="6"]
	 *
	 * @param array $atts Atts.
	 */
	public function featured_jobs_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit' => 6,
			),
			$atts,
			'zeko_jobs_featured'
		);

		$limit = max( 1, min( 20, (int) $atts['limit'] ) );

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$jobs    = $zeko_db->get_featured_jobs( $limit );

		if ( empty( $jobs ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="zeko-featured-jobs">
			<?php
			foreach ( $jobs as $job ) :
				$salary = '';
				if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
					$salary = '$' . number_format_i18n( (int) $job['salary_min'] ) . ' - $' . number_format_i18n( (int) $job['salary_max'] );
				}
				?>
				<div class="zeko-featured-job-card">
					<h4>
						<a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] ) ); ?>"><?php echo esc_html( $job['title'] ); ?></a>
						<?php if ( strtotime( $job['created_at'] ) > strtotime( '-3 days' ) ) : ?>
							<span class="zeko-new-badge"><?php esc_html_e( 'New', 'zeko-jobs' ); ?></span>
						<?php endif; ?>
					</h4>
					<div class="zeko-featured-job-meta">
						<span><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $job['location'] ); ?></span>
						<span><span class="dashicons dashicons-portfolio" aria-hidden="true"></span> <?php echo esc_html( ucfirst( str_replace( '-', ' ', $job['type'] ) ) ); ?></span>
						<?php if ( $salary ) : ?>
							<span><span class="dashicons dashicons-money" aria-hidden="true"></span> <?php echo esc_html( $salary ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a strip of featured (premium) companies for the jobs homepage.
	 *
	 * @param array $atts Shortcode attributes (limit).
	 */
	public function featured_companies_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit' => 6,
			),
			$atts,
			'zeko_jobs_companies'
		);

		$limit = max( 1, min( 20, (int) $atts['limit'] ) );

		$companies = Zeko_Jobs_DB::get_instance()->get_featured_companies( $limit );

		if ( empty( $companies ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="zeko-featured-companies">
			<?php foreach ( $companies as $company ) : ?>
				<a class="zeko-featured-company-card" href="<?php echo esc_url( home_url( '/companies/' . $company['slug'] ) ); ?>">
					<?php if ( ! empty( $company['logo_url'] ) ) : ?>
						<span class="zeko-featured-company-logo">
							<img src="<?php echo esc_url( $company['logo_url'] ); ?>" alt="<?php echo esc_attr( $company['name'] ); ?>" loading="lazy">
						</span>
					<?php endif; ?>
					<span class="zeko-featured-company-body">
						<span class="zeko-featured-company-name"><?php echo esc_html( $company['name'] ); ?></span>
						<span class="zeko-featured-company-meta">
							<?php if ( ! empty( $company['industry'] ) ) : ?>
								<span><?php echo esc_html( $company['industry'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $company['location'] ) ) : ?>
								<span><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $company['location'] ); ?></span>
							<?php endif; ?>
						</span>
						<span class="zeko-featured-company-jobs">
							<?php
							/* translators: %d: number of open jobs */
							printf( esc_html( _n( '%d open job', '%d open jobs', (int) $company['open_jobs'], 'zeko-jobs' ) ), (int) $company['open_jobs'] );
							?>
						</span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Job post form shortcode.
	 */
	public function job_post_form_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="zeko-jobs-not-logged-in">' . esc_html__( 'Please log in to post a job.', 'zeko-jobs' ) . '</p>';
		}

		$user_id  = get_current_user_id();
		$edit_job = null;
		$edit_id  = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit intent; ownership is re-verified server-side by get_job_by_id_and_employer() below and the form submit carries its own zeko_job_create nonce.

		$zeko_db = Zeko_Jobs_DB::get_instance();
		if ( $edit_id ) {
			$edit_job = $zeko_db->get_job_by_id_and_employer( $edit_id, $user_id );
		}

		$is_editing = ! empty( $edit_job );

		ob_start();
		?>
			<div class="zeko-jobs-post-form" data-user-id="<?php echo esc_attr( $user_id ); ?>">
			<h2><?php echo $is_editing ? esc_html__( 'Edit Job', 'zeko-jobs' ) : esc_html__( 'Post a New Job', 'zeko-jobs' ); ?></h2>
			<?php if ( $is_editing ) : ?>
				<p class="zeko-edit-notice"><?php esc_html_e( 'Editing:', 'zeko-jobs' ); ?> <strong><?php echo esc_html( $edit_job['title'] ); ?></strong> â€” <a href="<?php echo esc_url( home_url( '/job-dashboard/' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'zeko-jobs' ); ?></a></p>
			<?php endif; ?>
			<form id="zeko-job-create-form" class="zeko-form" method="post">
				<?php wp_nonce_field( 'zeko_job_create', 'zeko_job_create_nonce' ); ?>
				<?php if ( $is_editing ) : ?>
					<input type="hidden" name="edit_job_id" value="<?php echo esc_attr( $edit_id ); ?>">
				<?php endif; ?>
				<?php
				if ( ! $is_editing && Zeko_Jobs_DB::payments_enabled() ) :
					$billing      = Zeko_Jobs_DB::get_billing_settings();
					$payment_type = Zeko_Jobs_DB::get_instance()->get_posting_payment_type( $user_id );
					if ( 'pay' === $payment_type ) :
						$fee = (float) ( $billing['posting_fee'] ?? 25 );
						?>
						<div class="zeko-notice zeko-notice-warning" style="padding:12px 16px;border-radius:6px;background:#fef3cd;border:1px solid #fbbf24;margin-bottom:16px;">
							<span class="dashicons dashicons-warning" style="color:#b45309;" aria-hidden="true"></span>
							<?php
							printf(
								/* translators: %s: posting fee */
								esc_html__( 'A posting fee of %s will be deducted from your ZekoPay wallet when you submit this job.', 'zeko-jobs' ),
								'$' . number_format( $fee, 2 )
							);
							?>
							<a href="<?php echo esc_url( home_url( '/wallet/' ) ); ?>" target="_blank" rel="noopener" style="margin-left:8px;font-weight:600;"><?php esc_html_e( 'Top up wallet', 'zeko-jobs' ); ?></a>
						</div>
					<?php elseif ( 'pack' === $payment_type ) : ?>
						<div class="zeko-notice zeko-notice-info" style="padding:12px 16px;border-radius:6px;background:#dbeafe;border:1px solid #60a5fa;margin-bottom:16px;">
							<span class="dashicons dashicons-info" style="color:#2563eb;" aria-hidden="true"></span>
							<?php esc_html_e( 'Using a listing pack credit for this posting.', 'zeko-jobs' ); ?>
						</div>
						<?php
					elseif ( 'free' === $payment_type ) :
						$remaining = Zeko_Jobs_DB::get_instance()->get_free_postings_remaining( $user_id );
						?>
						<div class="zeko-notice zeko-notice-success" style="padding:12px 16px;border-radius:6px;background:#d1fae5;border:1px solid #34d399;margin-bottom:16px;">
							<span class="dashicons dashicons-yes-alt" style="color:#065f46;" aria-hidden="true"></span>
							<?php
							printf(
								/* translators: %d: number of free postings remaining */
								esc_html__( 'Free posting (%d remaining).', 'zeko-jobs' ),
								absint( $remaining )
							);
							?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
				<div class="zeko-grid">
					<div class="zeko-field">
						<label for="zeko-job-title"><?php esc_html_e( 'Job Title', 'zeko-jobs' ); ?> *</label>
						<input type="text" id="zeko-job-title" name="title" required maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Senior React Developer', 'zeko-jobs' ); ?>" value="<?php echo $is_editing ? esc_attr( $edit_job['title'] ) : ''; ?>">
					</div>

					<div class="zeko-field">
						<label for="zeko-job-location"><?php esc_html_e( 'Location', 'zeko-jobs' ); ?> *</label>
						<input type="text" id="zeko-job-location" name="location" required maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Remote, New York, London', 'zeko-jobs' ); ?>" value="<?php echo $is_editing ? esc_attr( $edit_job['location'] ) : ''; ?>">
					</div>

					<div class="zeko-field">
						<label for="zeko-job-type"><?php esc_html_e( 'Employment Type', 'zeko-jobs' ); ?> *</label>
						<select id="zeko-job-type" name="type" required>
							<option value="full-time" <?php echo ( $is_editing && 'full-time' === $edit_job['type'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Full Time', 'zeko-jobs' ); ?></option>
							<option value="part-time" <?php echo ( $is_editing && 'part-time' === $edit_job['type'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Part Time', 'zeko-jobs' ); ?></option>
							<option value="contract" <?php echo ( $is_editing && 'contract' === $edit_job['type'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Contract', 'zeko-jobs' ); ?></option>
							<option value="freelance" <?php echo ( $is_editing && 'freelance' === $edit_job['type'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Freelance', 'zeko-jobs' ); ?></option>
						</select>
					</div>

					<div class="zeko-field">
						<label for="zeko-job-category"><?php esc_html_e( 'Category', 'zeko-jobs' ); ?></label>
						<select id="zeko-job-category" name="category">
							<option value=""><?php esc_html_e( 'Select category...', 'zeko-jobs' ); ?></option>
							<option value="technology" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'technology' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Technology', 'zeko-jobs' ); ?></option>
							<option value="marketing" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'marketing' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Marketing', 'zeko-jobs' ); ?></option>
							<option value="design" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'design' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Design', 'zeko-jobs' ); ?></option>
							<option value="sales" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'sales' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Sales', 'zeko-jobs' ); ?></option>
							<option value="finance" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'finance' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Finance', 'zeko-jobs' ); ?></option>
							<option value="healthcare" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'healthcare' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Healthcare', 'zeko-jobs' ); ?></option>
							<option value="education" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'education' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Education', 'zeko-jobs' ); ?></option>
							<option value="engineering" <?php echo ( $is_editing && ( $edit_job['category'] ?? '' ) === 'engineering' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Engineering', 'zeko-jobs' ); ?></option>
						</select>
					</div>

					<div class="zeko-field">
						<label for="zeko-job-experience"><?php esc_html_e( 'Experience Level', 'zeko-jobs' ); ?></label>
						<select id="zeko-job-experience" name="experience_level">
							<option value=""><?php esc_html_e( 'Select level...', 'zeko-jobs' ); ?></option>
							<option value="entry" <?php echo ( $is_editing && ( $edit_job['experience_level'] ?? '' ) === 'entry' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Entry Level', 'zeko-jobs' ); ?></option>
							<option value="mid" <?php echo ( $is_editing && ( $edit_job['experience_level'] ?? '' ) === 'mid' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Mid Level', 'zeko-jobs' ); ?></option>
							<option value="senior" <?php echo ( $is_editing && ( $edit_job['experience_level'] ?? '' ) === 'senior' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Senior Level', 'zeko-jobs' ); ?></option>
							<option value="lead" <?php echo ( $is_editing && ( $edit_job['experience_level'] ?? '' ) === 'lead' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Lead / Manager', 'zeko-jobs' ); ?></option>
							<option value="executive" <?php echo ( $is_editing && ( $edit_job['experience_level'] ?? '' ) === 'executive' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Executive / Director', 'zeko-jobs' ); ?></option>
						</select>
					</div>

					<div class="zeko-field">
						<label for="zeko-job-remote"><?php esc_html_e( 'Work Model', 'zeko-jobs' ); ?></label>
						<select id="zeko-job-remote" name="remote_option">
							<option value=""><?php esc_html_e( 'Select work model...', 'zeko-jobs' ); ?></option>
							<option value="Remote" <?php echo ( $is_editing && ( $edit_job['remote_option'] ?? '' ) === 'Remote' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Remote', 'zeko-jobs' ); ?></option>
							<option value="Hybrid" <?php echo ( $is_editing && ( $edit_job['remote_option'] ?? '' ) === 'Hybrid' ) ? 'selected' : ''; ?>><?php esc_html_e( 'Hybrid', 'zeko-jobs' ); ?></option>
							<option value="On-site" <?php echo ( $is_editing && ( $edit_job['remote_option'] ?? '' ) === 'On-site' ) ? 'selected' : ''; ?>><?php esc_html_e( 'On-site', 'zeko-jobs' ); ?></option>
						</select>
					</div>

					<div class="zeko-field">
						<label for="zeko-job-salary-min"><?php esc_html_e( 'Salary Min (optional)', 'zeko-jobs' ); ?></label>
						<input type="number" step="0.01" id="zeko-job-salary-min" name="salary_min" min="0" value="<?php echo $is_editing ? esc_attr( $edit_job['salary_min'] ?? 0 ) : '0'; ?>">
					</div>

					<div class="zeko-field">
						<label for="zeko-job-salary-max"><?php esc_html_e( 'Salary Max (optional)', 'zeko-jobs' ); ?></label>
						<input type="number" step="0.01" id="zeko-job-salary-max" name="salary_max" min="0" value="<?php echo $is_editing ? esc_attr( $edit_job['salary_max'] ?? 0 ) : '0'; ?>">
					</div>

				<div class="zeko-field">
					<label for="zeko-job-deadline"><?php esc_html_e( 'Application Deadline', 'zeko-jobs' ); ?></label>
					<input type="date" id="zeko-job-deadline" name="application_deadline" value="<?php echo $is_editing ? esc_attr( $edit_job['application_deadline'] ?? '' ) : ''; ?>">
				</div>

					<?php
					$my_companies = $zeko_db->get_companies_by_employer( $user_id );
					if ( ! empty( $my_companies ) ) :
						?>
					<div class="zeko-field">
						<label for="zeko-job-company"><?php esc_html_e( 'Company Brand', 'zeko-jobs' ); ?></label>
						<select id="zeko-job-company" name="company_id">
							<option value=""><?php esc_html_e( '-- Default (main profile) --', 'zeko-jobs' ); ?></option>
							<?php foreach ( $my_companies as $c ) : ?>
								<option value="<?php echo esc_attr( $c['id'] ); ?>" <?php echo ( $is_editing && (int) ( $edit_job['company_id'] ?? 0 ) === (int) $c['id'] ) ? 'selected' : ''; ?>>
									<?php echo esc_html( $c['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( $is_editing && ! empty( $edit_job['company_id'] ) ) : ?>
							<input type="hidden" name="company_id_preserved" value="<?php echo esc_attr( $edit_job['company_id'] ); ?>">
						<?php endif; ?>
					</div>
					<?php endif; ?>

				<div class="zeko-field">
					<label for="zeko-job-required-skills"><?php esc_html_e( 'Required Skills (comma separated)', 'zeko-jobs' ); ?></label>
					<input type="text" id="zeko-job-required-skills" name="required_skills" placeholder="<?php esc_attr_e( 'e.g. PHP, WordPress, SQL', 'zeko-jobs' ); ?>" value="<?php echo $is_editing ? esc_attr( $edit_job['required_skills'] ?? '' ) : ''; ?>">
				</div>


					<div class="zeko-field">
						<label for="zeko-job-industry"><?php esc_html_e( 'Company Industry', 'zeko-jobs' ); ?></label>
						<input type="text" id="zeko-job-industry" name="company_industry" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Information Technology, Healthcare', 'zeko-jobs' ); ?>" value="<?php echo $is_editing ? esc_attr( $edit_job['company_industry'] ?? '' ) : ''; ?>">
					</div>

					<div class="zeko-field">
						<label for="zeko-job-company-size"><?php esc_html_e( 'Company Size', 'zeko-jobs' ); ?></label>
						<select id="zeko-job-company-size" name="company_size">
							<option value=""><?php esc_html_e( 'Select size...', 'zeko-jobs' ); ?></option>
							<option value="1-10" <?php echo ( $is_editing && ( $edit_job['company_size'] ?? '' ) === '1-10' ) ? 'selected' : ''; ?>><?php esc_html_e( '1-10 employees', 'zeko-jobs' ); ?></option>
							<option value="11-50" <?php echo ( $is_editing && ( $edit_job['company_size'] ?? '' ) === '11-50' ) ? 'selected' : ''; ?>><?php esc_html_e( '11-50 employees', 'zeko-jobs' ); ?></option>
							<option value="51-200" <?php echo ( $is_editing && ( $edit_job['company_size'] ?? '' ) === '51-200' ) ? 'selected' : ''; ?>><?php esc_html_e( '51-200 employees', 'zeko-jobs' ); ?></option>
							<option value="201-500" <?php echo ( $is_editing && ( $edit_job['company_size'] ?? '' ) === '201-500' ) ? 'selected' : ''; ?>><?php esc_html_e( '201-500 employees', 'zeko-jobs' ); ?></option>
							<option value="501-1000" <?php echo ( $is_editing && ( $edit_job['company_size'] ?? '' ) === '501-1000' ) ? 'selected' : ''; ?>><?php esc_html_e( '501-1000 employees', 'zeko-jobs' ); ?></option>
							<option value="1001+" <?php echo ( $is_editing && ( $edit_job['company_size'] ?? '' ) === '1001+' ) ? 'selected' : ''; ?>><?php esc_html_e( '1001+ employees', 'zeko-jobs' ); ?></option>
						</select>
					</div>

					<div class="zeko-field">
						<label for="zeko-job-company-website"><?php esc_html_e( 'Company Website', 'zeko-jobs' ); ?></label>
						<input type="url" id="zeko-job-company-website" name="company_website" placeholder="https://example.com" value="<?php echo $is_editing ? esc_attr( $edit_job['company_website'] ?? '' ) : ''; ?>">
					</div>

					<div class="zeko-field zeko-field-checkbox">
						<label>
							<input type="checkbox" name="is_easy_apply" value="1" <?php echo ( $is_editing && ! empty( $edit_job['is_easy_apply'] ) ) ? 'checked' : ''; ?>>
							<?php esc_html_e( 'Enable Easy Apply (one-click apply for candidates)', 'zeko-jobs' ); ?>
						</label>
					</div>
				</div>

				<div class="zeko-field">
					<label for="zeko-job-description"><?php esc_html_e( 'Job Description', 'zeko-jobs' ); ?> *</label>
					<?php
					$desc_content = $is_editing ? ( $edit_job['description'] ?? '' ) : '';
					wp_editor(
						$desc_content,
						'zeko-job-description',
						array(
							'textarea_name' => 'description',
							'media_buttons' => false,
							'teeny'         => true,
							'textarea_rows' => 8,
							'quicktags'     => false,
						)
					);
					?>
					<p class="description zeko-field-hint"><?php esc_html_e( 'Tip: Use the editor toolbar for formatting, links, and lists.', 'zeko-jobs' ); ?></p>
				</div>

				<div class="zeko-field">
					<label for="zeko-job-responsibilities"><?php esc_html_e( 'Responsibilities', 'zeko-jobs' ); ?></label>
					<textarea id="zeko-job-responsibilities" name="responsibilities" rows="5" placeholder="<?php esc_attr_e( 'List key responsibilities for this role...', 'zeko-jobs' ); ?>"><?php echo $is_editing ? esc_textarea( $edit_job['responsibilities'] ?? '' ) : ''; ?></textarea>
					<p class="description zeko-field-hint"><?php esc_html_e( 'One responsibility per line. This will be formatted as a list on the job page.', 'zeko-jobs' ); ?></p>
				</div>

				<div class="zeko-field">
					<label for="zeko-job-requirements"><?php esc_html_e( 'Requirements', 'zeko-jobs' ); ?></label>
					<textarea id="zeko-job-requirements" name="requirements" rows="5" placeholder="<?php esc_attr_e( 'List required skills and experience...', 'zeko-jobs' ); ?>"><?php echo $is_editing ? esc_textarea( $edit_job['requirements'] ?? '' ) : ''; ?></textarea>
					<p class="description zeko-field-hint"><?php esc_html_e( 'One requirement per line.', 'zeko-jobs' ); ?></p>
				</div>

				<div class="zeko-field">
					<label for="zeko-job-qualifications"><?php esc_html_e( 'Qualifications', 'zeko-jobs' ); ?></label>
					<textarea id="zeko-job-qualifications" name="qualifications" rows="5" placeholder="<?php esc_attr_e( 'List desired qualifications...', 'zeko-jobs' ); ?>"><?php echo $is_editing ? esc_textarea( $edit_job['qualifications'] ?? '' ) : ''; ?></textarea>
				</div>

				<div class="zeko-field">
					<label for="zeko-job-benefits"><?php esc_html_e( 'Benefits', 'zeko-jobs' ); ?></label>
					<textarea id="zeko-job-benefits" name="benefits" rows="5" placeholder="<?php esc_attr_e( 'e.g. Health insurance, 401k, remote flexibility...', 'zeko-jobs' ); ?>"><?php echo $is_editing ? esc_textarea( $edit_job['benefits'] ?? '' ) : ''; ?></textarea>
				</div>

				<div class="zeko-field">
					<label for="zeko-job-company-about"><?php esc_html_e( 'About the Company', 'zeko-jobs' ); ?></label>
					<textarea id="zeko-job-company-about" name="company_about" rows="4" placeholder="<?php esc_attr_e( 'Tell candidates about your company...', 'zeko-jobs' ); ?>"><?php echo $is_editing ? esc_textarea( $edit_job['company_about'] ?? '' ) : ''; ?></textarea>
				</div>

				<div class="zeko-field zeko-submit">
					<div id="zeko-duplicate-warning" class="zeko-duplicate-warning" style="display:none;">
						<span class="dashicons dashicons-warning"></span>
						<strong><?php esc_html_e( 'Possible duplicates detected:', 'zeko-jobs' ); ?></strong>
						<ul id="zeko-duplicate-list"></ul>
						<p><button type="button" class="button button-small" id="zeko-dismiss-duplicates"><?php esc_html_e( 'Ignore and publish anyway', 'zeko-jobs' ); ?></button></p>
					</div>
					<button type="button" class="button zeko-preview-btn" id="zeko-job-preview-btn"><?php esc_html_e( 'Preview', 'zeko-jobs' ); ?></button>
					<button type="submit" class="button button-primary"><?php echo $is_editing ? esc_html__( 'Update Job', 'zeko-jobs' ) : esc_html__( 'Publish Job', 'zeko-jobs' ); ?></button>
				</div>

				<div id="zeko-job-create-message" class="zeko-inline-message"></div>
			</form>

			<div id="zeko-job-preview-modal" class="zeko-preview-modal" aria-hidden="true" role="dialog" aria-label="<?php esc_attr_e( 'Job Preview', 'zeko-jobs' ); ?>">
				<div class="zeko-preview-overlay" tabindex="-1"></div>
				<div class="zeko-preview-dialog" role="document">
					<div class="zeko-preview-header">
						<h3><?php esc_html_e( 'Preview Your Job Listing', 'zeko-jobs' ); ?></h3>
						<button type="button" class="zeko-preview-close" aria-label="<?php esc_attr_e( 'Close preview', 'zeko-jobs' ); ?>">&times;</button>
					</div>
				<div class="zeko-preview-body">
						<div class="zeko-preview-card">
							<h4 class="zeko-preview-title"></h4>
							<div class="zeko-preview-meta">
								<span class="zeko-preview-location"></span>
								<span class="zeko-preview-type"></span>
								<span class="zeko-preview-salary"></span>
							</div>
							<div class="zeko-preview-description"></div>
						</div>
					</div>
					<div class="zeko-preview-footer">
						<button type="button" class="button zeko-preview-edit"><?php esc_html_e( 'Edit', 'zeko-jobs' ); ?></button>
						<button type="button" class="button button-primary zeko-preview-publish"><?php echo $is_editing ? esc_html__( 'Update Job', 'zeko-jobs' ) : esc_html__( 'Publish Job', 'zeko-jobs' ); ?></button>
					</div>
				</div>
			</div>
		</div>
			<?php
			return ob_get_clean();
	}

	/**
	 * Job dashboard shortcode.
	 */
	public function job_dashboard_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="zeko-jobs-not-logged-in">' . esc_html__( 'Please log in to view your job dashboard.', 'zeko-jobs' ) . '</p>';
		}

		global $wpdb;

		$table_jobs = $wpdb->prefix . 'zeko_jobs';
		$table_apps = $wpdb->prefix . 'zeko_job_applications';

		$user_id = get_current_user_id();

		$dashboard_nonce = wp_create_nonce( 'zeko_job_dashboard' );

		$bookmarked_ids_raw = get_user_meta( $user_id, 'zeko_bookmarked_jobs', true );
		$bookmarked_ids     = is_array( $bookmarked_ids_raw ) ? array_map( 'intval', $bookmarked_ids_raw ) : array();

		$my_bookmarked_jobs = array();
		$zeko_db            = Zeko_Jobs_DB::get_instance();

		if ( ! empty( $bookmarked_ids ) ) {
			$my_bookmarked_jobs = $zeko_db->get_jobs_by_ids( $bookmarked_ids );
		}

		$my_applications = $zeko_db->get_user_applications( $user_id );

		$my_reminders  = array();
		$employer_jobs = $zeko_db->get_employer_all_jobs( $user_id );

		$is_employer = ! empty( $employer_jobs );

		if ( ! $is_employer ) {
			$my_reminders = $zeko_db->get_user_reminders( $user_id );
		}

		$employer_all_apps  = array();
		$employer_app_total = 0;
		if ( $is_employer ) {
			$job_ids            = array_map(
				static function ( $j ) {
					return (int) $j['id'];
				},
				$employer_jobs
			);
			$employer_app_total = $zeko_db->count_applications_for_jobs( $job_ids );
			$employer_all_apps  = $zeko_db->get_applications_for_jobs( $job_ids, 20 );
		}

		ob_start();
		?>
		<div class="zeko-jobs-dashboard" data-user-id="<?php echo esc_attr( $user_id ); ?>">

			<aside class="zeko-dashboard-sidebar">
				<h2 class="zeko-dashboard-title"><?php esc_html_e( 'Jobs Dashboard', 'zeko-jobs' ); ?></h2>
				<nav class="zeko-dashboard-nav" aria-label="<?php esc_attr_e( 'Dashboard navigation', 'zeko-jobs' ); ?>">
					<ul>
						<li><a href="#zeko-dashboard-overview" class="active" aria-current="page"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span> <?php esc_html_e( 'Overview', 'zeko-jobs' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/post-a-job/' ) ); ?>" class="zeko-nav-external"><span class="dashicons dashicons-plus-alt" aria-hidden="true"></span> <?php esc_html_e( 'Post a Job', 'zeko-jobs' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="zeko-nav-external"><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Browse Jobs', 'zeko-jobs' ); ?></a></li>
						<?php if ( $is_employer ) : ?>
							<li><a href="#zeko-dashboard-posted-jobs"><span class="dashicons dashicons-admin-post" aria-hidden="true"></span> <?php esc_html_e( 'Posted Jobs', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-applications"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-pipeline"><span class="dashicons dashicons-filter" aria-hidden="true"></span> <?php esc_html_e( 'Pipeline', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-company"><span class="dashicons dashicons-building" aria-hidden="true"></span> <?php esc_html_e( 'Company Profile', 'zeko-jobs' ); ?></a></li>
						<li><a href="#zeko-dashboard-company-blog"><span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span> <?php esc_html_e( 'Company Blog', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-calendar"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php esc_html_e( 'Calendar', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-analytics"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> <?php esc_html_e( 'Analytics', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-templates"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> <?php esc_html_e( 'Email Templates', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-auto-response"><span class="dashicons dashicons-email-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Auto-Responses', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-outreach"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> <?php esc_html_e( 'Outreach', 'zeko-jobs' ); ?></a></li>
						<li><a href="#zeko-dashboard-search-resumes"><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Search Resumes', 'zeko-jobs' ); ?></a></li>
							<?php if ( Zeko_Jobs_DB::payments_enabled() ) : ?>
							<li><a href="#zeko-dashboard-billing"><span class="dashicons dashicons-cart" aria-hidden="true"></span> <?php esc_html_e( 'Billing', 'zeko-jobs' ); ?></a></li>
						<?php endif; ?>
						<?php else : ?>
							<li><a href="#zeko-dashboard-profile-completeness"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <?php esc_html_e( 'My Profile', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-saved-jobs"><span class="dashicons dashicons-heart" aria-hidden="true"></span> <?php esc_html_e( 'Saved Jobs', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-job-alerts"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> <?php esc_html_e( 'Job Alerts', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-saved-searches"><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Saved Searches', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-documents"><span class="dashicons dashicons-media-default" aria-hidden="true"></span> <?php esc_html_e( 'Documents', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-resume-builder"><span class="dashicons dashicons-id" aria-hidden="true"></span> <?php esc_html_e( 'Resume Builder', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-recommended"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <?php esc_html_e( 'Recommended', 'zeko-jobs' ); ?></a></li>
							<li><a href="#zeko-dashboard-cover-templates"><span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( 'Cover Templates', 'zeko-jobs' ); ?></a></li>
						<?php endif; ?>
					</ul>
				</nav>
			</aside>

			<div class="zeko-dashboard-main">
				<div class="zeko-dashboard-toolbar">
					<a class="button button-primary" href="<?php echo esc_url( home_url( '/post-a-job/' ) ); ?>"><?php esc_html_e( 'Post a New Job', 'zeko-jobs' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>"><?php esc_html_e( 'Browse Jobs', 'zeko-jobs' ); ?></a>
					<div style="flex:1;"></div>
					<label for="zeko-dashboard-search" class="screen-reader-text"><?php esc_html_e( 'Search jobs or applicants', 'zeko-jobs' ); ?></label>
					<input type="search" id="zeko-dashboard-search" placeholder="<?php esc_attr_e( 'Search jobs or applicants...', 'zeko-jobs' ); ?>" style="padding:8px 12px;border:1px solid var(--color-border);border-radius:var(--radius-sm);min-width:220px;">
					<?php if ( is_user_logged_in() ) : ?>
						<?php
						// Bell moved to unified notification center in zeko-learn.
						$notifications = array();
						$unread_count  = 0;
						?>
						<?php endif; ?>
					<button type="button" id="zeko-dark-mode-toggle" class="button" aria-label="<?php esc_attr_e( 'Toggle dark mode', 'zeko-jobs' ); ?>" title="<?php esc_attr_e( 'Toggle dark mode', 'zeko-jobs' ); ?>">
						<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
					</button>
				</div>

				<?php if ( is_user_logged_in() ) : ?>

					<?php if ( ! $is_employer ) : ?>

						<?php if ( ! empty( $my_applications ) ) : ?>
					<div class="zeko-dashboard-section" id="zeko-dashboard-overview" data-section="overview">
						<h3><?php esc_html_e( 'My Applications', 'zeko-jobs' ); ?>
							<?php if ( (int) get_user_meta( $user_id, 'zeko_open_to_work', true ) ) : ?>
								<span class="zeko-badge zeko-badge-success" style="margin-left:8px;font-size:11px;vertical-align:middle;"><?php esc_html_e( 'Open to Work', 'zeko-jobs' ); ?></span>
							<?php endif; ?>
						</h3>
							<?php
							$total_apps      = count( $my_applications );
							$status_counts   = array_count_values( array_column( $my_applications, 'status' ) );
							$applied_count   = count( array_filter( $my_applications, fn( $a ) => in_array( $a['status'], array( 'pending', 'awaiting_review' ), true ) ) );
							$reviewed_count  = count( array_filter( $my_applications, fn( $a ) => 'reviewed' === $a['status'] ) );
							$interview_count = count( array_filter( $my_applications, fn( $a ) => 'interview' === $a['status'] ) );
							$hired_count     = count( array_filter( $my_applications, fn( $a ) => 'hired' === $a['status'] ) );
							?>
						<div class="zeko-seeker-stats">
							<div class="zeko-stat-card"><span class="zeko-stat-value"><?php echo esc_html( $total_apps ); ?></span><span class="zeko-stat-label"><?php esc_html_e( 'Total', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-stat-card"><span class="zeko-stat-value"><?php echo esc_html( $applied_count ); ?></span><span class="zeko-stat-label"><?php esc_html_e( 'Applied', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-stat-card"><span class="zeko-stat-value"><?php echo esc_html( $reviewed_count ); ?></span><span class="zeko-stat-label"><?php esc_html_e( 'Reviewed', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-stat-card"><span class="zeko-stat-value"><?php echo esc_html( $interview_count ); ?></span><span class="zeko-stat-label"><?php esc_html_e( 'Interview', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-stat-card"><span class="zeko-stat-value"><?php echo esc_html( $hired_count ); ?></span><span class="zeko-stat-label"><?php esc_html_e( 'Hired', 'zeko-jobs' ); ?></span></div>
							<?php
							$resp_stats = $zeko_db->get_seeker_response_stats( $user_id );
							if ( $resp_stats['total'] > 0 ) :
								?>
								<div class="zeko-stat-card"><span class="zeko-stat-value"><?php echo esc_html( $resp_stats['response_rate'] ); ?>%</span><span class="zeko-stat-label"><?php esc_html_e( 'Response Rate', 'zeko-jobs' ); ?></span></div>
							<?php endif; ?>
						</div>

						<div class="zeko-seeker-filters">
							<select id="zeko-seeker-filter-status">
								<option value=""><?php esc_html_e( 'All Statuses', 'zeko-jobs' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending', 'zeko-jobs' ); ?></option>
								<option value="awaiting_review"><?php esc_html_e( 'Awaiting Review', 'zeko-jobs' ); ?></option>
								<option value="reviewed"><?php esc_html_e( 'Reviewed', 'zeko-jobs' ); ?></option>
								<option value="interview"><?php esc_html_e( 'Interview', 'zeko-jobs' ); ?></option>
								<option value="offer"><?php esc_html_e( 'Offer', 'zeko-jobs' ); ?></option>
								<option value="hired"><?php esc_html_e( 'Hired', 'zeko-jobs' ); ?></option>
								<option value="rejected"><?php esc_html_e( 'Rejected', 'zeko-jobs' ); ?></option>
							</select>
							<select id="zeko-seeker-filter-sort">
								<option value="newest"><?php esc_html_e( 'Most Recent', 'zeko-jobs' ); ?></option>
								<option value="oldest"><?php esc_html_e( 'Oldest First', 'zeko-jobs' ); ?></option>
								<option value="company"><?php esc_html_e( 'Company Name', 'zeko-jobs' ); ?></option>
								<option value="status"><?php esc_html_e( 'Status', 'zeko-jobs' ); ?></option>
							</select>
						</div>

						<div class="zeko-seeker-apps-list" id="zeko-seeker-apps-list">
							<?php foreach ( $my_applications as $app ) : ?>
								<?php
								$app_id    = (int) $app['id'];
								$history   = $zeko_db->get_status_history( $app_id );
								$stages    = array( 'pending', 'awaiting_review', 'reviewed', 'interview', 'offer', 'hired' );
								$stage_idx = array_search( $app['status'], $stages );
								$stage_pct = ( false !== $stage_idx ) ? round( ( $stage_idx / ( count( $stages ) - 1 ) ) * 100 ) : 0;
								if ( 'rejected' === $app['status'] ) {
									$stage_pct = 0;
								}
								?>
							<div class="zeko-application-card" data-status="<?php echo esc_attr( $app['status'] ); ?>" data-company="<?php echo esc_attr( $app['title'] ); ?>" data-date="<?php echo esc_attr( $app['applied_at'] ); ?>">
								<div class="zeko-application-info">
									<h4><a href="<?php echo esc_url( home_url( '/jobs/' . $app['slug'] ) ); ?>"><?php echo esc_html( $app['title'] ); ?></a></h4>
									<div class="zeko-application-meta">
										<span class="zeko-application-date"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $app['applied_at'] ) ) ); ?></span>
										<span class="zeko-status-badge zeko-status-<?php echo esc_attr( $app['status'] ); ?>" data-application-id="<?php echo esc_attr( $app_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'View application timeline', 'zeko-jobs' ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $app['status'] ) ) ); ?></span>
										<?php if ( ! empty( $app['viewed_at'] ) ) : ?>
											<span class="zeko-viewed-indicator" title="<?php esc_attr_e( 'Employer viewed this application', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e( 'Viewed', 'zeko-jobs' ); ?></span>
										<?php endif; ?>
									</div>
									<div class="zeko-progress-track">
										<div class="zeko-progress-fill" style="width:<?php echo esc_attr( $stage_pct ); ?>%"></div>
									</div>
									<div class="zeko-application-timeline" id="zeko-timeline-<?php echo esc_attr( $app_id ); ?>" style="display:none;">
										<?php if ( ! empty( $history ) ) : ?>
											<ul class="zeko-timeline-list">
												<?php foreach ( $history as $item ) : ?>
													<li>
														<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $item['to_status'] ) ) ); ?></strong>
														<span><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $item['created_at'] ) ) ); ?></span>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p><?php esc_html_e( 'No timeline events yet.', 'zeko-jobs' ); ?></p>
										<?php endif; ?>
									</div>
								</div>
								<div class="zeko-application-actions">
									<a href="<?php echo esc_url( home_url( '/jobs/' . $app['slug'] ) ); ?>" class="button button-small"><?php esc_html_e( 'View Job', 'zeko-jobs' ); ?></a>
									<?php if ( function_exists( 'zeko_send_message' ) && ! empty( $app['employer_id'] ) ) : ?>
										<button type="button" class="button button-small zeko-message-employer-btn" data-employer-id="<?php echo esc_attr( $app['employer_id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>"><?php esc_html_e( 'Message', 'zeko-jobs' ); ?></button>
									<?php endif; ?>
									<?php if ( in_array( $app['status'], array( 'awaiting_review', 'reviewed' ), true ) ) : ?>
										<button type="button" class="button button-small zeko-withdraw-btn" data-application-id="<?php echo esc_attr( $app['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
											<?php esc_html_e( 'Withdraw', 'zeko-jobs' ); ?>
										</button>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
						</div>
					</div>
					<?php else : ?>
					<div class="zeko-dashboard-section zeko-dashboard-section-active" id="zeko-dashboard-overview" data-section="overview">
						<div class="zeko-empty-state">
							<p><?php esc_html_e( 'You have not applied for any jobs yet.', 'zeko-jobs' ); ?></p>
							<a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Browse Jobs', 'zeko-jobs' ); ?></a>
						</div>
					</div>
					<?php endif; ?>

						<?php
						$profile_data = $zeko_db->calculate_seeker_profile_completeness( $user_id );
						$pct          = $profile_data['percent'];
						?>
					<div class="zeko-dashboard-section" id="zeko-dashboard-profile-completeness" data-section="profile-completeness">
						<h3><?php esc_html_e( 'Profile Completeness', 'zeko-jobs' ); ?></h3>
						<div class="zeko-profile-completeness">
							<div class="zeko-profile-progress">
								<div class="zeko-profile-progress-track">
									<div class="zeko-profile-progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
								</div>
								<span class="zeko-profile-pct"><?php echo esc_html( $pct ); ?>%</span>
							</div>
							<?php if ( ! empty( $profile_data['suggest'] ) ) : ?>
								<ul class="zeko-profile-suggestions">
									<?php foreach ( $profile_data['suggest'] as $s ) : ?>
										<li><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span> <?php echo esc_html( $s ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</div>

						<?php if ( ! empty( $my_reminders ) ) : ?>
						<div class="zeko-dashboard-section" id="zeko-dashboard-reminders" data-section="reminders">
							<h3><?php esc_html_e( 'My Reminders', 'zeko-jobs' ); ?></h3>
							<div class="zeko-reminders-list">
								<?php foreach ( $my_reminders as $reminder ) : ?>
									<div class="zeko-reminder-card" data-reminder-id="<?php echo esc_attr( $reminder['id'] ); ?>">
										<div class="zeko-reminder-info">
											<strong><?php echo esc_html( $reminder['title'] ); ?></strong>
											<span><?php esc_html_e( 'Remind me on:', 'zeko-jobs' ); ?> <?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $reminder['remind_at'] ) ) ); ?></span>
										</div>
										<button type="button" class="button zeko-delete-reminder-btn" data-reminder-id="<?php echo esc_attr( $reminder['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
											<?php esc_html_e( 'Delete', 'zeko-jobs' ); ?>
										</button>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

						<?php
						$seeker_interviews = $zeko_db->get_seeker_interviews( $user_id );
						if ( ! empty( $seeker_interviews ) ) :
							?>
					<div class="zeko-dashboard-section" id="zeko-dashboard-interviews" data-section="interviews">
						<h3><?php esc_html_e( 'Upcoming Interviews', 'zeko-jobs' ); ?></h3>
						<div class="zeko-seeker-interviews-list">
							<?php
							foreach ( $seeker_interviews as $iv ) :
								$iv_date = date_i18n( 'l, M j, Y', strtotime( $iv['scheduled_at'] ) );
								$iv_time = date_i18n( 'g:i A', strtotime( $iv['scheduled_at'] ) );
								$iv_end  = date_i18n( 'g:i A', strtotime( $iv['scheduled_at'] . ' + ' . $iv['duration'] . ' minutes' ) );
								$ics_url = wp_nonce_url(
									add_query_arg(
										array(
											'zeko_ics_download' => $iv['id'],
										),
										home_url()
									),
									'zeko_ics_' . $iv['id']
								);
								?>
							<div class="zeko-interview-card">
								<div class="zeko-interview-info">
									<strong><a href="<?php echo esc_url( home_url( '/jobs/' . $iv['job_slug'] ) ); ?>"><?php echo esc_html( $iv['job_title'] ); ?></a></strong>
									<p class="zeko-interview-employer"><?php echo esc_html( $iv['employer_name'] ); ?></p>
									<?php if ( ! empty( $iv['interviewer_name'] ) ) : ?>
										<p class="zeko-interview-interviewer" style="font-size:13px;color:var(--color-text-secondary);margin:2px 0;"><span class="dashicons dashicons-admin-users" style="font-size:13px;width:14px;height:14px;" aria-hidden="true"></span> <?php echo esc_html( $iv['interviewer_name'] ); ?></p>
									<?php endif; ?>
									<p class="zeko-interview-time"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php echo esc_html( $iv_date . ' — ' . $iv_time . ' – ' . $iv_end ); ?></p>
									<?php if ( ! empty( $iv['location'] ) ) : ?>
										<p class="zeko-interview-location"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $iv['location'] ); ?></p>
									<?php endif; ?>
									<?php if ( ! empty( $iv['notes'] ) ) : ?>
										<p class="zeko-interview-notes"><?php echo esc_html( $iv['notes'] ); ?></p>
									<?php endif; ?>
								</div>
								<div class="zeko-interview-actions">
									<?php if ( ! empty( $iv['meeting_url'] ) ) : ?>
										<a href="<?php echo esc_url( $iv['meeting_url'] ); ?>" class="button button-small button-primary" target="_blank" rel="noopener noreferrer">
											<span class="dashicons dashicons-video-alt3" aria-hidden="true"></span> <?php esc_html_e( 'Join Video Call', 'zeko-jobs' ); ?>
										</a>
									<?php endif; ?>
									<a href="<?php echo esc_url( $ics_url ); ?>" class="button button-small"><?php esc_html_e( 'Add to Calendar', 'zeko-jobs' ); ?></a>
									<?php if ( 'scheduled' === $iv['status'] && empty( $iv['accepted_time'] ) ) : ?>
										<?php $proposed = ! empty( $iv['proposed_times'] ) ? json_decode( $iv['proposed_times'], true ) : array(); ?>
										<?php if ( empty( $proposed ) ) : ?>
											<button type="button" class="button button-small zeko-propose-times-btn" data-interview-id="<?php echo esc_attr( $iv['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
												<span class="dashicons dashicons-clock" aria-hidden="true"></span> <?php esc_html_e( 'Propose Times', 'zeko-jobs' ); ?>
											</button>
										<?php else : ?>
											<span class="zeko-badge zeko-badge-info" style="font-size:11px;"><?php esc_html_e( 'Awaiting employer response', 'zeko-jobs' ); ?></span>
										<?php endif; ?>
									<?php elseif ( 'confirmed' === $iv['status'] || ! empty( $iv['accepted_time'] ) ) : ?>
										<span class="zeko-badge zeko-badge-success" style="font-size:11px;"><?php esc_html_e( 'Confirmed', 'zeko-jobs' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
						<?php endif; ?>

					<div class="zeko-dashboard-section" id="zeko-dashboard-saved-searches" data-section="saved-searches">
						<h3><?php esc_html_e( 'Saved Searches', 'zeko-jobs' ); ?></h3>
						<?php
						$zeko_db        = Zeko_Jobs_DB::get_instance();
						$saved_searches = $zeko_db->get_saved_searches( get_current_user_id() );
						?>
						<?php if ( is_user_logged_in() && ! empty( $saved_searches ) ) : ?>
							<button type="button" class="button zeko-send-digest-btn" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" style="margin-bottom: 12px;">
								<?php esc_html_e( 'Send Me Email Digest', 'zeko-jobs' ); ?>
							</button>
						<?php endif; ?>
						<div class="zeko-saved-searches-list" id="zeko-saved-searches-list">
							<?php
							if ( empty( $saved_searches ) ) :
								?>
								<div class="zeko-empty-state">
									<p><?php esc_html_e( 'You have no saved searches yet.', 'zeko-jobs' ); ?></p>
								</div>
							<?php else : ?>
								<?php foreach ( $saved_searches as $search ) : ?>
									<div class="zeko-saved-search-card" data-search-id="<?php echo esc_attr( $search['id'] ); ?>">
										<div class="zeko-saved-search-info">
											<strong><?php echo esc_html( $search['name'] ); ?></strong>
											<span><?php echo esc_html( $search['search_term'] ?: 'All jobs' ); ?></span>
											<?php if ( $search['location'] ) : ?>
												<span><?php echo esc_html( $search['location'] ); ?></span>
											<?php endif; ?>
											<?php if ( $search['job_type'] ) : ?>
												<span><?php echo esc_html( ucfirst( $search['job_type'] ) ); ?></span>
											<?php endif; ?>
											<?php if ( $search['email_alerts'] ) : ?>
												<span class="zeko-email-alert-badge"><?php esc_html_e( 'Email alerts on', 'zeko-jobs' ); ?></span>
											<?php endif; ?>
										</div>
										<div class="zeko-saved-search-actions">
											<a href="<?php echo esc_url( add_query_arg( $search, home_url( '/jobs/' ) ) ); ?>" class="button button-small"><?php esc_html_e( 'View', 'zeko-jobs' ); ?></a>
											<button type="button" class="button button-small zeko-delete-search-btn" data-search-id="<?php echo esc_attr( $search['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
												<?php esc_html_e( 'Delete', 'zeko-jobs' ); ?>
											</button>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>

						<?php
						$saved_job_ids = get_user_meta( $user_id, 'zeko_saved_jobs', true );
						$saved_job_ids = is_array( $saved_job_ids ) ? $saved_job_ids : array();
						if ( ! empty( $saved_job_ids ) ) :
							?>
				<div class="zeko-dashboard-section" id="zeko-dashboard-saved-jobs" data-section="saved-jobs">
					<h3><?php esc_html_e( 'Saved Jobs', 'zeko-jobs' ); ?></h3>
					<div class="zeko-saved-jobs-grid">
							<?php
							$saved_jobs = get_posts(
								array(
									'post_type'   => 'job',
									'post_status' => 'publish',
									'post__in'    => array_map( 'absint', $saved_job_ids ),
									'orderby'     => 'post__in',
								)
							);
							foreach ( $saved_jobs as $sj ) :
								$sj_meta = get_post_meta( $sj->ID );
								?>
						<div class="zeko-saved-job-card" data-job-id="<?php echo esc_attr( $sj->ID ); ?>">
							<h4><a href="<?php echo esc_url( get_permalink( $sj->ID ) ); ?>"><?php echo esc_html( $sj->post_title ); ?></a></h4>
							<div class="zeko-saved-job-meta">
								<?php if ( ! empty( $sj_meta['location'][0] ) ) : ?>
									<span><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $sj_meta['location'][0] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $sj_meta['job_type'][0] ) ) : ?>
									<span><?php echo esc_html( ucfirst( $sj_meta['job_type'][0] ) ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $sj_meta['salary_min'][0] ) ) : ?>
									<span><?php echo esc_html( '$' . number_format( (float) $sj_meta['salary_min'][0] ) ); ?>+</span>
								<?php endif; ?>
							</div>
							<button type="button" class="button button-small zeko-save-job-btn" data-job-id="<?php echo esc_attr( $sj->ID ); ?>" data-saved="1" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
								<span class="dashicons dashicons-heart" aria-hidden="true"></span> <?php esc_html_e( 'Unsave', 'zeko-jobs' ); ?>
							</button>
						</div>
							<?php endforeach; ?>
					</div>
				</div>
						<?php endif; ?>

						<?php if ( ! $is_employer ) : ?>
				<div class="zeko-dashboard-section" id="zeko-dashboard-job-alerts" data-section="job-alerts">
					<h3><?php esc_html_e( 'Job Alerts', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Get notified when new jobs match your criteria. Choose instant, daily, or weekly delivery.', 'zeko-jobs' ); ?></p>

					<div class="zeko-alert-create-form zeko-form" style="margin-bottom:20px;padding:16px;border:1px solid var(--color-border);border-radius:var(--radius-md);">
						<h4 id="zeko-alert-form-title"><?php esc_html_e( 'Create New Alert', 'zeko-jobs' ); ?></h4>
						<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div class="zeko-field">
								<label for="zeko-alert-name"><?php esc_html_e( 'Alert Name', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-alert-name" placeholder="<?php esc_attr_e( 'e.g. Remote React Jobs', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label for="zeko-alert-keyword"><?php esc_html_e( 'Keyword', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-alert-keyword" placeholder="<?php esc_attr_e( 'e.g. React, Developer', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label for="zeko-alert-location"><?php esc_html_e( 'Location', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-alert-location" placeholder="<?php esc_attr_e( 'e.g. Remote, New York', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label for="zeko-alert-type"><?php esc_html_e( 'Job Type', 'zeko-jobs' ); ?></label>
								<select id="zeko-alert-type">
									<option value=""><?php esc_html_e( 'Any', 'zeko-jobs' ); ?></option>
									<option value="full-time"><?php esc_html_e( 'Full-time', 'zeko-jobs' ); ?></option>
									<option value="part-time"><?php esc_html_e( 'Part-time', 'zeko-jobs' ); ?></option>
									<option value="contract"><?php esc_html_e( 'Contract', 'zeko-jobs' ); ?></option>
									<option value="freelance"><?php esc_html_e( 'Freelance', 'zeko-jobs' ); ?></option>
									<option value="internship"><?php esc_html_e( 'Internship', 'zeko-jobs' ); ?></option>
								</select>
							</div>
							<div class="zeko-field">
								<label for="zeko-alert-frequency"><?php esc_html_e( 'Delivery', 'zeko-jobs' ); ?></label>
								<select id="zeko-alert-frequency">
									<option value="instant"><?php esc_html_e( 'Instant', 'zeko-jobs' ); ?></option>
									<option value="daily" selected><?php esc_html_e( 'Daily Digest', 'zeko-jobs' ); ?></option>
									<option value="weekly"><?php esc_html_e( 'Weekly Digest', 'zeko-jobs' ); ?></option>
								</select>
							</div>
						</div>
						<input type="hidden" id="zeko-alert-edit-id" value="">
						<div style="margin-top:12px;display:flex;gap:8px;">
							<button type="button" class="button button-primary" id="zeko-save-alert-btn"><?php esc_html_e( 'Create Alert', 'zeko-jobs' ); ?></button>
							<button type="button" class="button" id="zeko-cancel-edit-alert-btn" style="display:none;"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
						</div>
						<div class="zeko-inline-message" id="zeko-alert-form-message"></div>
					</div>

							<?php
							$zeko_db = Zeko_Jobs_DB::get_instance();
							$alerts  = $zeko_db->get_job_alerts( get_current_user_id() );
							if ( empty( $alerts ) ) :
								?>
						<div class="zeko-empty-state">
							<span class="dashicons dashicons-email-alt" aria-hidden="true" style="font-size:48px;width:48px;height:48px;opacity:0.3;"></span>
							<p><?php esc_html_e( 'No job alerts yet. Create one above to get started.', 'zeko-jobs' ); ?></p>
						</div>
						<?php else : ?>
						<div class="zeko-alerts-list" id="zeko-alerts-list">
							<?php
							foreach ( $alerts as $alert ) :
								$active     = ! empty( $alert['is_active'] );
								$freq_label = 'instant' === $alert['frequency'] ? __( 'Instant', 'zeko-jobs' ) : ( 'weekly' === $alert['frequency'] ? __( 'Weekly', 'zeko-jobs' ) : __( 'Daily', 'zeko-jobs' ) );
								$sp         = $alert['search_params'];
								?>
								<div class="zeko-alert-card<?php echo $active ? '' : ' zeko-alert-inactive'; ?>" data-alert-id="<?php echo esc_attr( $alert['id'] ); ?>">
									<div class="zeko-alert-info">
										<strong class="zeko-alert-name"><?php echo esc_html( $alert['name'] ); ?></strong>
										<div class="zeko-alert-criteria">
											<?php if ( ! empty( $sp['keyword'] ) ) : ?>
												<span class="zeko-alert-tag"><?php esc_html_e( 'Keyword:', 'zeko-jobs' ); ?> <?php echo esc_html( $sp['keyword'] ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $sp['location'] ) ) : ?>
												<span class="zeko-alert-tag"><?php esc_html_e( 'Location:', 'zeko-jobs' ); ?> <?php echo esc_html( $sp['location'] ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $sp['type'] ) ) : ?>
												<span class="zeko-alert-tag"><?php esc_html_e( 'Type:', 'zeko-jobs' ); ?> <?php echo esc_html( ucfirst( $sp['type'] ) ); ?></span>
											<?php endif; ?>
										</div>
										<div class="zeko-alert-meta">
											<span class="zeko-badge zeko-badge-<?php echo $active ? 'success' : 'muted'; ?>"><?php echo esc_html( $freq_label ); ?></span>
											<?php if ( $alert['last_sent'] ) : ?>
												<span class="zeko-alert-meta-text"><?php /* translators: %s: date the alert was last sent */ printf( esc_html__( 'Last sent %s', 'zeko-jobs' ), esc_html( human_time_diff( strtotime( $alert['last_sent'] ), time() ) . ' ago' ) ); ?></span>
											<?php endif; ?>
										</div>
									</div>
									<div class="zeko-alert-actions">
										<button type="button" class="button button-small zeko-toggle-alert-btn" data-alert-id="<?php echo esc_attr( $alert['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php echo $active ? esc_attr__( 'Pause', 'zeko-jobs' ) : esc_attr__( 'Activate', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-<?php echo $active ? 'controls-pause' : 'controls-play'; ?>" aria-hidden="true"></span>
										</button>
										<button type="button" class="button button-small zeko-edit-alert-btn" data-alert-id="<?php echo esc_attr( $alert['id'] ); ?>" data-name="<?php echo esc_attr( $alert['name'] ); ?>" data-keyword="<?php echo esc_attr( $sp['keyword'] ?? '' ); ?>" data-location="<?php echo esc_attr( $sp['location'] ?? '' ); ?>" data-type="<?php echo esc_attr( $sp['type'] ?? '' ); ?>" data-frequency="<?php echo esc_attr( $alert['frequency'] ); ?>" title="<?php esc_attr_e( 'Edit', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										</button>
										<button type="button" class="button button-small button-link-delete zeko-delete-alert-btn" data-alert-id="<?php echo esc_attr( $alert['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'Delete', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-trash" aria-hidden="true"></span>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<div class="zeko-dashboard-section" id="zeko-dashboard-profile" data-section="profile" style="display:none;">
					<h3><?php esc_html_e( 'My Profile', 'zeko-jobs' ); ?></h3>
						<?php
						$seeker_location   = get_user_meta( $user_id, 'zeko_location', true );
						$seeker_phone      = get_user_meta( $user_id, 'zeko_phone', true );
						$seeker_skills     = get_user_meta( $user_id, 'zeko_skills', true );
						$seeker_experience = get_user_meta( $user_id, 'zeko_work_experience', true );
						$seeker_education  = get_user_meta( $user_id, 'zeko_education', true );
						if ( ! is_array( $seeker_experience ) ) {
							$seeker_experience = array();
						}
						if ( ! is_array( $seeker_education ) ) {
							$seeker_education = array();
						}
						if ( ! is_array( $seeker_skills ) ) {
							$seeker_skills = array();
						}
						?>
					<form class="zeko-form zeko-profile-form" id="zeko-seeker-profile-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
						<div class="zeko-field">
							<label><?php esc_html_e( 'Location', 'zeko-jobs' ); ?></label>
							<input type="text" name="seeker_location" value="<?php echo esc_attr( $seeker_location ); ?>" placeholder="<?php esc_attr_e( 'City, Country', 'zeko-jobs' ); ?>">
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Phone', 'zeko-jobs' ); ?></label>
							<input type="tel" name="seeker_phone" value="<?php echo esc_attr( $seeker_phone ); ?>">
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Skills', 'zeko-jobs' ); ?></label>
							<p class="description"><?php esc_html_e( 'Comma-separated list of skills', 'zeko-jobs' ); ?></p>
							<input type="text" name="seeker_skills" value="<?php echo esc_attr( implode( ', ', $seeker_skills ) ); ?>" placeholder="<?php esc_attr_e( 'PHP, JavaScript, Project Management', 'zeko-jobs' ); ?>">
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Work Experience', 'zeko-jobs' ); ?></label>
							<div class="zeko-experience-list" id="zeko-experience-list">
								<?php foreach ( $seeker_experience as $i => $exp ) : ?>
								<div class="zeko-exp-row">
									<input type="text" name="exp_company[]" value="<?php echo esc_attr( $exp['company'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Company', 'zeko-jobs' ); ?>">
									<input type="text" name="exp_role[]" value="<?php echo esc_attr( $exp['role'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Role', 'zeko-jobs' ); ?>">
									<input type="text" name="exp_dates[]" value="<?php echo esc_attr( $exp['dates'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Dates (e.g. 2020 - 2023)', 'zeko-jobs' ); ?>">
									<input type="text" name="exp_desc[]" value="<?php echo esc_attr( $exp['description'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Description', 'zeko-jobs' ); ?>">
									<button type="button" class="button button-small zeko-remove-exp"><?php esc_html_e( 'Remove', 'zeko-jobs' ); ?></button>
								</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="button button-small" id="zeko-add-experience"><?php esc_html_e( '+ Add Experience', 'zeko-jobs' ); ?></button>
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Education', 'zeko-jobs' ); ?></label>
							<div class="zeko-education-list" id="zeko-education-list">
								<?php foreach ( $seeker_education as $i => $edu ) : ?>
								<div class="zeko-edu-row">
									<input type="text" name="edu_institution[]" value="<?php echo esc_attr( $edu['institution'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Institution', 'zeko-jobs' ); ?>">
									<input type="text" name="edu_degree[]" value="<?php echo esc_attr( $edu['degree'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Degree', 'zeko-jobs' ); ?>">
									<input type="text" name="edu_dates[]" value="<?php echo esc_attr( $edu['dates'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Dates (e.g. 2016 - 2020)', 'zeko-jobs' ); ?>">
									<button type="button" class="button button-small zeko-remove-edu"><?php esc_html_e( 'Remove', 'zeko-jobs' ); ?></button>
								</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="button button-small" id="zeko-add-education"><?php esc_html_e( '+ Add Education', 'zeko-jobs' ); ?></button>
						</div>
						<div class="zeko-field">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Profile', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-profile-message"></span>
						</div>

						<?php
						$profile_visible  = (int) get_user_meta( $user_id, 'zeko_profile_visible', true );
						$open_to_work     = (int) get_user_meta( $user_id, 'zeko_open_to_work', true );
						$linkedin_profile = Zeko_Jobs_Linkedin::get_profile( $user_id );
						?>
						<?php if ( Zeko_Jobs_Linkedin::is_configured() ) : ?>
						<div class="zeko-field" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--color-border);">
							<h4><?php esc_html_e( 'LinkedIn Integration', 'zeko-jobs' ); ?></h4>
							<?php if ( $linkedin_profile ) : ?>
								<div style="display:flex;align-items:center;gap:12px;margin:8px 0;">
									<?php if ( ! empty( $linkedin_profile['picture'] ) ) : ?>
										<img src="<?php echo esc_url( $linkedin_profile['picture'] ); ?>" alt="" loading="lazy" style="width:40px;height:40px;border-radius:50%;">
									<?php endif; ?>
									<div>
										<strong><?php echo esc_html( $linkedin_profile['name'] ?? '' ); ?></strong><br>
										<span style="font-size:12px;color:var(--color-text-muted);"><?php echo esc_html( $linkedin_profile['profile_url'] ?? '' ); ?></span>
									</div>
									<button type="button" class="button button-small zeko-linkedin-disconnect-btn" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" style="color:#dc3545;margin-left:auto;">
										<span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Disconnect', 'zeko-jobs' ); ?>
									</button>
								</div>
								<p class="description" style="margin-top:4px;"><?php esc_html_e( 'LinkedIn connected. You can use "Apply with LinkedIn" on job pages.', 'zeko-jobs' ); ?></p>
							<?php else : ?>
								<p style="margin:8px 0;"><?php esc_html_e( 'Connect your LinkedIn profile to enable "Apply with LinkedIn" and auto-fill applications.', 'zeko-jobs' ); ?></p>
								<button type="button" class="button button-small zeko-linkedin-connect-btn" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
									<span class="dashicons dashicons-link" aria-hidden="true"></span> <?php esc_html_e( 'Connect LinkedIn', 'zeko-jobs' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<?php endif; ?>
						<div class="zeko-field" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--color-border);">
							<h4><?php esc_html_e( 'Privacy & Visibility', 'zeko-jobs' ); ?></h4>
							<label class="zeko-toggle-label" style="display:flex;align-items:center;gap:8px;margin:8px 0;">
								<input type="checkbox" name="zeko_profile_visible" value="1" <?php checked( $profile_visible, 1 ); ?>>
								<?php esc_html_e( 'Make my profile visible to employers', 'zeko-jobs' ); ?>
							</label>
							<label class="zeko-toggle-label" style="display:flex;align-items:center;gap:8px;margin:8px 0;">
								<input type="checkbox" name="zeko_open_to_work" value="1" <?php checked( $open_to_work, 1 ); ?>>
								<?php esc_html_e( 'Show "Open to Work" badge on my profile', 'zeko-jobs' ); ?>
							</label>
						</div>
					</form>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-resume-builder" data-section="resume-builder" style="display:none;">
					<div class="zeko-section-header">
						<h3><?php esc_html_e( 'Resume Builder', 'zeko-jobs' ); ?></h3>
						<div class="zeko-section-actions">
							<button type="button" class="button button-primary button-small" id="zeko-new-resume-btn">
								<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( '+ New Resume', 'zeko-jobs' ); ?>
							</button>
						</div>
					</div>
					<p class="zeko-section-desc"><?php esc_html_e( 'Build structured resumes with templates. Export as PDF for applications.', 'zeko-jobs' ); ?></p>
						<?php
						$struct_resumes = $zeko_db->get_structured_resumes( $user_id );
						$struct_user    = get_userdata( $user_id );
						$user_email     = $struct_user ? $struct_user->user_email : '';
						$user_name      = $struct_user ? $struct_user->display_name : '';
						$user_loc       = get_user_meta( $user_id, 'zeko_location', true );
						?>
						<?php if ( ! empty( $struct_resumes ) ) : ?>
						<div class="zeko-resume-list" id="zeko-resume-list">
							<?php foreach ( $struct_resumes as $sr ) : ?>
								<div class="zeko-resume-list-item" data-resume-id="<?php echo esc_attr( $sr['id'] ); ?>">
									<div class="zeko-resume-list-info">
										<strong><?php echo esc_html( $sr['title'] ?? __( 'Untitled', 'zeko-jobs' ) ); ?></strong>
										<span class="zeko-resume-list-template"><?php echo esc_html( ucfirst( $sr['template'] ?? 'modern' ) ); ?></span>
										<?php if ( ! empty( $sr['is_default'] ) ) : ?>
											<span class="zeko-badge zeko-badge-success"><?php esc_html_e( 'Default', 'zeko-jobs' ); ?></span>
										<?php endif; ?>
									</div>
									<div class="zeko-resume-list-actions">
										<button type="button" class="button button-small zeko-edit-resume-btn" data-resume-id="<?php echo esc_attr( $sr['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>"><?php esc_html_e( 'Edit', 'zeko-jobs' ); ?></button>
										<button type="button" class="button button-small zeko-preview-resume-btn" data-resume-id="<?php echo esc_attr( $sr['id'] ); ?>"><?php esc_html_e( 'Preview', 'zeko-jobs' ); ?></button>
										<button type="button" class="button button-small zeko-export-resume-btn" data-resume-id="<?php echo esc_attr( $sr['id'] ); ?>"><?php esc_html_e( 'Export PDF', 'zeko-jobs' ); ?></button>
										<?php if ( empty( $sr['is_default'] ) ) : ?>
											<button type="button" class="button button-small zeko-set-default-resume-btn" data-resume-id="<?php echo esc_attr( $sr['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>"><?php esc_html_e( 'Set Default', 'zeko-jobs' ); ?></button>
										<?php endif; ?>
										<button type="button" class="button button-small zeko-delete-resume-btn" data-resume-id="<?php echo esc_attr( $sr['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" style="color:#dc3545;"><?php esc_html_e( 'Delete', 'zeko-jobs' ); ?></button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<div class="zeko-empty-state">
							<span class="dashicons dashicons-id" aria-hidden="true" style="font-size:48px;width:48px;height:48px;opacity:0.3;"></span>
							<p><?php esc_html_e( 'No resumes yet. Create your first structured resume to get started.', 'zeko-jobs' ); ?></p>
						</div>
					<?php endif; ?>

					<div class="zeko-resume-editor" id="zeko-resume-editor" style="display:none;">
						<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
							<div class="zeko-field" style="flex:1;min-width:200px;">
								<label for="zeko-resume-title"><?php esc_html_e( 'Resume Title', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-resume-title" placeholder="<?php esc_attr_e( 'e.g. Software Engineer Resume', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field" style="flex:0 0 150px;">
								<label for="zeko-resume-template"><?php esc_html_e( 'Template', 'zeko-jobs' ); ?></label>
								<select id="zeko-resume-template">
									<option value="modern"><?php esc_html_e( 'Modern', 'zeko-jobs' ); ?></option>
									<option value="classic"><?php esc_html_e( 'Classic', 'zeko-jobs' ); ?></option>
									<option value="minimal"><?php esc_html_e( 'Minimal', 'zeko-jobs' ); ?></option>
								</select>
							</div>
						</div>

						<div class="zeko-resume-tabs" role="tablist" style="display:flex;gap:0;border-bottom:2px solid var(--color-border,#e0e0e0);margin-bottom:16px;">
							<button type="button" class="zeko-resume-tab active" role="tab" data-tab="personal" aria-selected="true"><?php esc_html_e( 'Personal', 'zeko-jobs' ); ?></button>
							<button type="button" class="zeko-resume-tab" role="tab" data-tab="experience"><?php esc_html_e( 'Experience', 'zeko-jobs' ); ?></button>
							<button type="button" class="zeko-resume-tab" role="tab" data-tab="education"><?php esc_html_e( 'Education', 'zeko-jobs' ); ?></button>
							<button type="button" class="zeko-resume-tab" role="tab" data-tab="skills"><?php esc_html_e( 'Skills', 'zeko-jobs' ); ?></button>
							<button type="button" class="zeko-resume-tab" role="tab" data-tab="certifications"><?php esc_html_e( 'Certifications', 'zeko-jobs' ); ?></button>
							<button type="button" class="zeko-resume-tab" role="tab" data-tab="projects"><?php esc_html_e( 'Projects', 'zeko-jobs' ); ?></button>
						</div>

						<div class="zeko-resume-tab-content" id="zeko-resume-tab-personal">
							<div class="zeko-field"><label><?php esc_html_e( 'Full Name', 'zeko-jobs' ); ?></label><input type="text" id="zeko-rs-name" value="<?php echo esc_attr( $user_name ); ?>"></div>
							<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
								<div class="zeko-field"><label><?php esc_html_e( 'Email', 'zeko-jobs' ); ?></label><input type="email" id="zeko-rs-email" value="<?php echo esc_attr( $user_email ); ?>"></div>
								<div class="zeko-field"><label><?php esc_html_e( 'Phone', 'zeko-jobs' ); ?></label><input type="tel" id="zeko-rs-phone"></div>
							</div>
							<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
								<div class="zeko-field"><label><?php esc_html_e( 'Location', 'zeko-jobs' ); ?></label><input type="text" id="zeko-rs-location" value="<?php echo esc_attr( $user_loc ); ?>"></div>
								<div class="zeko-field"><label><?php esc_html_e( 'Website', 'zeko-jobs' ); ?></label><input type="url" id="zeko-rs-website" placeholder="https://"></div>
							</div>
							<div class="zeko-field"><label><?php esc_html_e( 'LinkedIn', 'zeko-jobs' ); ?></label><input type="url" id="zeko-rs-linkedin" placeholder="https://linkedin.com/in/"></div>
							<div class="zeko-field"><label><?php esc_html_e( 'Professional Summary', 'zeko-jobs' ); ?></label><textarea id="zeko-rs-summary" rows="4" placeholder="<?php esc_attr_e( 'Brief summary of your experience and goals...', 'zeko-jobs' ); ?>"></textarea></div>
						</div>

						<div class="zeko-resume-tab-content" id="zeko-resume-tab-experience" style="display:none;">
							<div id="zeko-rs-experience-list"></div>
							<button type="button" class="button button-small" id="zeko-rs-add-experience"><?php esc_html_e( '+ Add Experience', 'zeko-jobs' ); ?></button>
						</div>

						<div class="zeko-resume-tab-content" id="zeko-resume-tab-education" style="display:none;">
							<div id="zeko-rs-education-list"></div>
							<button type="button" class="button button-small" id="zeko-rs-add-education"><?php esc_html_e( '+ Add Education', 'zeko-jobs' ); ?></button>
						</div>

						<div class="zeko-resume-tab-content" id="zeko-resume-tab-skills" style="display:none;">
							<div class="zeko-field">
								<label><?php esc_html_e( 'Skills', 'zeko-jobs' ); ?></label>
								<p class="description"><?php esc_html_e( 'Enter one skill per line or separate with commas.', 'zeko-jobs' ); ?></p>
								<textarea id="zeko-rs-skills" rows="8" placeholder="<?php echo esc_attr__( 'JavaScript, React, PHP, WordPress...', 'zeko-jobs' ); ?>"></textarea>
							</div>
						</div>

						<div class="zeko-resume-tab-content" id="zeko-resume-tab-certifications" style="display:none;">
							<div id="zeko-rs-certs-list"></div>
							<button type="button" class="button button-small" id="zeko-rs-add-cert"><?php esc_html_e( '+ Add Certification', 'zeko-jobs' ); ?></button>
						</div>

						<div class="zeko-resume-tab-content" id="zeko-resume-tab-projects" style="display:none;">
							<div id="zeko-rs-projects-list"></div>
							<button type="button" class="button button-small" id="zeko-rs-add-project"><?php esc_html_e( '+ Add Project', 'zeko-jobs' ); ?></button>
						</div>

						<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--color-border);display:flex;gap:8px;align-items:center;">
							<button type="button" class="button button-primary" id="zeko-rs-save"><?php esc_html_e( 'Save Resume', 'zeko-jobs' ); ?></button>
							<button type="button" class="button" id="zeko-rs-preview-btn"><?php esc_html_e( 'Preview', 'zeko-jobs' ); ?></button>
							<button type="button" class="button" id="zeko-rs-cancel-btn"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-resume-message"></span>
						</div>
					</div>

					<div class="zeko-resume-preview" id="zeko-resume-preview" style="display:none;">
						<div style="margin-bottom:12px;display:flex;gap:8px;">
							<button type="button" class="button button-small" id="zeko-rs-back-to-editor"><?php esc_html_e( '← Back to Editor', 'zeko-jobs' ); ?></button>
							<button type="button" class="button button-small button-primary" id="zeko-rs-print"><?php esc_html_e( 'Export PDF', 'zeko-jobs' ); ?></button>
						</div>
						<div id="zeko-resume-render" style="background:#fff;padding:40px;max-width:800px;margin:0 auto;box-shadow:0 2px 8px rgba(0,0,0,.1);border-radius:4px;"></div>
					</div>
				</div>

					<div class="zeko-dashboard-section" id="zeko-dashboard-recommended" data-section="recommended">
						<h3><?php esc_html_e( 'Recommended for You', 'zeko-jobs' ); ?></h3>
						<p class="zeko-section-desc"><?php esc_html_e( 'Personalized job picks based on your profile, search history, and applications.', 'zeko-jobs' ); ?></p>
						<?php
						$rek_db   = Zeko_Jobs_DB::get_instance();
						$rek_jobs = $rek_db->get_recommended_jobs( get_current_user_id(), 8 );
						if ( empty( $rek_jobs ) ) :
							?>
							<div class="zeko-empty-state">
								<span class="dashicons dashicons-lightbulb" aria-hidden="true" style="font-size:48px;width:48px;height:48px;opacity:0.3;"></span>
								<p><?php esc_html_e( 'Complete your profile and apply to jobs to get personalized recommendations.', 'zeko-jobs' ); ?></p>
								<a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Browse Jobs', 'zeko-jobs' ); ?></a>
							</div>
						<?php else : ?>
							<div class="zeko-recommended-grid">
								<?php
								foreach ( $rek_jobs as $rj ) :
									$rj_employer = (int) ( $rj['employer_id'] ?? 0 );
									$rj_company  = __( 'N/A', 'zeko-jobs' );
									if ( $rj_employer > 0 ) {
										$rj_ud = get_userdata( $rj_employer );
										if ( $rj_ud ) {
											$rj_company = $rj_ud->display_name;
										}
									}
									?>
									<div class="zeko-recommended-card">
										<?php
										$rj_score = $rj['match_score'] ?? null;
										if ( null !== $rj_score && $rj_score > 0 ) :
											$rj_class = $rj_score >= 80 ? 'match-high' : ( $rj_score >= 50 ? 'match-medium' : 'match-low' );
											?>
											<div class="zeko-match-score-badge <?php echo esc_attr( $rj_class ); ?>">
												<span class="zeko-match-score-number"><?php echo esc_html( $rj_score ); ?>%</span>
												<span class="zeko-match-score-label"><?php esc_html_e( 'match', 'zeko-jobs' ); ?></span>
											</div>
										<?php endif; ?>
										<h4><a href="<?php echo esc_url( home_url( '/jobs/' . ( $rj['slug'] ?? $rj['id'] ) ) ); ?>"><?php echo esc_html( $rj['title'] ); ?></a></h4>
										<div class="zeko-recommended-meta">
											<?php
											if ( $rj_company ) :
												?>
												<span><span class="dashicons dashicons-building" aria-hidden="true"></span> <?php echo esc_html( $rj_company ); ?></span><?php endif; ?>
											<?php
											if ( ! empty( $rj['location'] ) ) :
												?>
												<span><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $rj['location'] ); ?></span><?php endif; ?>
											<?php if ( ! empty( $rj['salary_min'] ) || ! empty( $rj['salary_max'] ) ) : ?>
												<span><span class="dashicons dashicons-money" aria-hidden="true"></span> $<?php echo esc_html( number_format( (float) ( $rj['salary_min'] ?? 0 ) ) ); ?>
												<?php
												if ( ! empty( $rj['salary_max'] ) ) {
													echo ' – $' . esc_html( number_format( (float) $rj['salary_max'] ) );}
												?>
												</span>
											<?php endif; ?>
										</div>
										<?php if ( ! empty( $rj['category'] ) ) : ?>
											<span class="zeko-badge zeko-badge-muted"><?php echo esc_html( ucfirst( $rj['category'] ) ); ?></span>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="zeko-dashboard-section" id="zeko-dashboard-documents" data-section="documents">
						<h3><?php esc_html_e( 'My Resumes & Documents', 'zeko-jobs' ); ?></h3>
						<p class="zeko-section-desc"><?php esc_html_e( 'Upload and manage your resumes. Set a default resume to speed up applications.', 'zeko-jobs' ); ?></p>
						<div class="zeko-documents-upload zeko-form" style="margin-bottom:16px;padding:16px;border:1px solid var(--color-border);border-radius:var(--radius-md);">
							<div class="zeko-field">
								<label for="zeko-resume-label"><?php esc_html_e( 'Resume Label', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-resume-label" placeholder="<?php esc_attr_e( 'e.g. Frontend Developer Resume', 'zeko-jobs' ); ?>" style="max-width:400px;">
							</div>
							<div style="display:flex;align-items:center;gap:8px;">
								<input type="file" id="zeko-document-upload" accept=".pdf,.doc,.docx">
								<input type="hidden" id="zeko-document-application-id" value="0">
								<button type="button" class="button button-primary zeko-upload-document-btn" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
									<span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Upload Resume', 'zeko-jobs' ); ?>
								</button>
							</div>
							<p class="description"><?php esc_html_e( 'Accepted formats: PDF, DOC, DOCX. Max file size applies.', 'zeko-jobs' ); ?></p>
						</div>
						<?php
						$zeko_db    = Zeko_Jobs_DB::get_instance();
						$documents  = $zeko_db->get_resumes( get_current_user_id() );
						$default_id = $zeko_db->get_user_default_resume_id( get_current_user_id() );
						if ( empty( $documents ) ) :
							?>
							<div class="zeko-empty-state">
								<span class="dashicons dashicons-media-default" aria-hidden="true" style="font-size:48px;width:48px;height:48px;opacity:0.3;"></span>
								<p><?php esc_html_e( 'No resumes uploaded yet. Upload your first resume to get started.', 'zeko-jobs' ); ?></p>
							</div>
						<?php else : ?>
							<div class="zeko-documents-list" id="zeko-documents-list">
								<?php
								foreach ( $documents as $doc ) :
									$is_default = (int) $doc['id'] === $default_id;
									?>
									<div class="zeko-document-card<?php echo $is_default ? ' zeko-document-default' : ''; ?>" data-document-id="<?php echo esc_attr( $doc['id'] ); ?>">
										<div class="zeko-document-icon">
											<?php if ( strpos( $doc['file_type'], 'pdf' ) !== false ) : ?>
												<span class="dashicons dashicons-pdf" aria-hidden="true"></span>
											<?php else : ?>
												<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
											<?php endif; ?>
										</div>
										<div class="zeko-document-info">
											<strong class="zeko-document-label" data-doc-id="<?php echo esc_attr( $doc['id'] ); ?>">
												<?php echo esc_html( ! empty( $doc['label'] ) ? $doc['label'] : $doc['filename'] ); ?>
											</strong>
											<span class="zeko-document-meta">
												<?php echo esc_html( size_format( $doc['file_size'] ) ); ?>
												&middot;
												<?php echo esc_html( human_time_diff( strtotime( $doc['created_at'] ), time() ) ); ?> <?php esc_html_e( 'ago', 'zeko-jobs' ); ?>
											</span>
											<?php if ( $is_default ) : ?>
												<span class="zeko-badge zeko-badge-success"><?php esc_html_e( 'Default Resume', 'zeko-jobs' ); ?></span>
											<?php endif; ?>
										</div>
										<div class="zeko-document-actions">
											<?php
											// Protected controller delivery: never emit the raw public file URL.
											// The download controller enforces login + nonce + ownership before.
											// streaming the physical bytes.
											$doc_download_parts = array(
												'action' => 'zeko_job_download_document',
												'document_id' => (int) $doc['id'],
											);
											$doc_download_url   = wp_nonce_url(
												add_query_arg( $doc_download_parts, admin_url( 'admin-post.php' ) ),
												'zeko_job_download_' . (int) $doc['id']
											);
											?>
											<a href="<?php echo esc_url( $doc_download_url ); ?>" target="_blank" rel="noopener" class="button button-small" title="<?php esc_attr_e( 'View', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></a>
											<a href="<?php echo esc_url( $doc_download_url ); ?>" download class="button button-small" title="<?php esc_attr_e( 'Download', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span></a>
											<button type="button" class="button button-small zeko-edit-doc-label-btn" data-doc-id="<?php echo esc_attr( $doc['id'] ); ?>" data-label="<?php echo esc_attr( $doc['label'] ?? '' ); ?>" title="<?php esc_attr_e( 'Edit label', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>
											<?php if ( ! $is_default ) : ?>
												<button type="button" class="button button-small button-primary zeko-set-default-resume-btn" data-doc-id="<?php echo esc_attr( $doc['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'Set as default resume', 'zeko-jobs' ); ?>"><?php esc_html_e( 'Set Default', 'zeko-jobs' ); ?></button>
											<?php endif; ?>
											<button type="button" class="button button-small button-link-delete zeko-delete-document-btn" data-document-id="<?php echo esc_attr( $doc['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'Delete', 'zeko-jobs' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="zeko-dashboard-section" id="zeko-dashboard-cover-templates" data-section="cover-templates">
						<h3><?php esc_html_e( 'Cover Letter Templates', 'zeko-jobs' ); ?></h3>
						<p class="zeko-section-desc"><?php esc_html_e( 'Save reusable cover letters to speed up your applications.', 'zeko-jobs' ); ?></p>
						<div class="zeko-cover-templates-form zeko-form" style="margin-bottom:16px;">
							<div class="zeko-field">
								<label for="zeko-tpl-name"><?php esc_html_e( 'Template Name', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-tpl-name" placeholder="<?php esc_attr_e( 'e.g. General Application', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label for="zeko-tpl-content"><?php esc_html_e( 'Cover Letter Content', 'zeko-jobs' ); ?></label>
								<textarea id="zeko-tpl-content" rows="5" placeholder="<?php esc_attr_e( 'Write your cover letter template here...', 'zeko-jobs' ); ?>"></textarea>
							</div>
							<input type="hidden" id="zeko-tpl-edit-id" value="">
							<button type="button" class="button button-primary" id="zeko-save-template-btn"><?php esc_html_e( 'Save Template', 'zeko-jobs' ); ?></button>
							<button type="button" class="button" id="zeko-cancel-edit-template-btn" style="display:none;"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
						</div>
						<div class="zeko-cover-templates-list" id="zeko-cover-templates-list">
							<?php
							$cover_tpls = get_user_meta( get_current_user_id(), 'zeko_cover_templates', true );
							if ( ! is_array( $cover_tpls ) ) {
								$cover_tpls = array();
							}
							if ( empty( $cover_tpls ) ) :
								?>
								<p class="zeko-no-data"><?php esc_html_e( 'No templates saved yet.', 'zeko-jobs' ); ?></p>
							<?php else : ?>
								<?php foreach ( $cover_tpls as $tpl_id => $tpl ) : ?>
									<div class="zeko-document-card" data-template-id="<?php echo esc_attr( $tpl_id ); ?>">
										<div class="zeko-document-info">
											<strong><?php echo esc_html( $tpl['name'] ); ?></strong>
											<span><?php echo esc_html( wp_trim_words( $tpl['content'], 15 ) ); ?></span>
										</div>
										<div class="zeko-saved-search-actions">
											<button type="button" class="button button-small zeko-edit-template-btn" data-template-id="<?php echo esc_attr( $tpl_id ); ?>" data-name="<?php echo esc_attr( $tpl['name'] ); ?>" data-content="<?php echo esc_attr( $tpl['content'] ); ?>">
												<span class="dashicons dashicons-edit" aria-hidden="true"></span>
											</button>
											<button type="button" class="button button-small button-link-delete zeko-delete-template-btn" data-template-id="<?php echo esc_attr( $tpl_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
												<span class="dashicons dashicons-trash" aria-hidden="true"></span>
											</button>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>

				<?php else : ?>

					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
					<?php
						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$active_jobs_count = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$table_jobs} WHERE employer_id = %d AND status = 'publish'",
								(int) $user_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$total_applicants = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(DISTINCT seeker_id) FROM {$table_apps} app
									JOIN {$table_jobs} job ON job.id = app.job_id
									WHERE job.employer_id = %d",
								(int) $user_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$new_candidates = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(DISTINCT app.seeker_id)
								 FROM {$table_apps} app
									 JOIN {$table_jobs} job ON job.id = app.job_id
									 WHERE job.employer_id = %d AND app.applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
								(int) $user_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$pending_review_count = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$table_apps} app
								 JOIN {$table_jobs} job ON job.id = app.job_id
								 WHERE job.employer_id = %d AND app.status = 'pending'",
								(int) $user_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$total_views = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COALESCE(SUM(views), 0) FROM {$table_jobs} WHERE employer_id = %d",
								(int) $user_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$featured_count = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$table_jobs} WHERE employer_id = %d AND is_featured = 1",
								$user_id
							)
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
						$avg_views       = $active_jobs_count > 0 ? round( $total_views / $active_jobs_count ) : 0;
						$conversion_rate = $total_views > 0 ? round( ( $total_applicants / $total_views ) * 100, 1 ) : 0;
					?>

					<div class="zeko-dashboard-section zeko-dashboard-section-active" id="zeko-dashboard-overview" data-section="overview">
						<div class="zeko-jobs-metrics-ribbon">
							<div class="zeko-metric-card">
								<div class="zeko-metric-label"><?php esc_html_e( 'Active Jobs', 'zeko-jobs' ); ?></div>
								<div class="zeko-metric-value"><?php echo esc_html( $active_jobs_count ); ?></div>
							</div>
							<div class="zeko-metric-card">
								<div class="zeko-metric-label"><?php esc_html_e( 'Total Applicants', 'zeko-jobs' ); ?></div>
								<div class="zeko-metric-value"><?php echo esc_html( $total_applicants ); ?></div>
							</div>
							<div class="zeko-metric-card">
								<div class="zeko-metric-label"><?php esc_html_e( 'New Candidates (7d)', 'zeko-jobs' ); ?></div>
								<div class="zeko-metric-value"><?php echo esc_html( $new_candidates ); ?></div>
							</div>
							<?php if ( $pending_review_count > 0 ) : ?>
								<div class="zeko-metric-card zeko-metric-alert">
									<div class="zeko-metric-label"><?php esc_html_e( 'Pending Review', 'zeko-jobs' ); ?></div>
									<div class="zeko-metric-value"><?php echo esc_html( $pending_review_count ); ?></div>
								</div>
							<?php endif; ?>
							<div class="zeko-metric-card">
								<div class="zeko-metric-label"><?php esc_html_e( 'Total Views', 'zeko-jobs' ); ?></div>
								<div class="zeko-metric-value"><?php echo esc_html( number_format_i18n( $total_views ) ); ?></div>
							</div>
						</div>
						<div class="zeko-jobs-section">
							<h3><?php esc_html_e( 'Performance Analytics', 'zeko-jobs' ); ?></h3>
							<div class="zeko-performance-grid">
								<div class="zeko-performance-item">
									<div class="zeko-performance-value"><?php echo esc_html( $avg_views ); ?></div>
									<div class="zeko-performance-label"><?php esc_html_e( 'Avg Views/Job', 'zeko-jobs' ); ?></div>
								</div>
								<div class="zeko-performance-item">
									<div class="zeko-performance-value"><?php echo esc_html( $conversion_rate ); ?>%</div>
									<div class="zeko-performance-label"><?php esc_html_e( 'Conversion Rate', 'zeko-jobs' ); ?></div>
								</div>
								<div class="zeko-performance-item">
									<div class="zeko-performance-value"><?php echo esc_html( $featured_count ); ?></div>
									<div class="zeko-performance-label"><?php esc_html_e( 'Featured Jobs', 'zeko-jobs' ); ?></div>
								</div>
							</div>
						</div>
					</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-posted-jobs" data-section="posted-jobs">
					<div class="zeko-section-header">
						<h3><?php esc_html_e( 'Posted Jobs', 'zeko-jobs' ); ?></h3>
						<div class="zeko-section-actions">
							<label class="button button-small" style="cursor:pointer;">
								<span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Import CSV', 'zeko-jobs' ); ?>
								<input type="file" accept=".csv" id="zeko-csv-import-input" style="display:none;">
							</label>
							<span id="zeko-csv-import-status" style="font-size:12px;margin-left:8px;"></span>
							<a href="<?php echo esc_url( home_url( '/post-a-job/' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( '+ Post New Job', 'zeko-jobs' ); ?></a>
						</div>
					</div>

					<?php if ( ! empty( $employer_jobs ) ) : ?>
						<div class="zeko-bulk-actions">
							<label><input type="checkbox" class="zeko-select-all-jobs"> <?php esc_html_e( 'Select all', 'zeko-jobs' ); ?></label>
							<select id="zeko-bulk-action-select" class="zeko-bulk-select">
								<option value=""><?php esc_html_e( 'Bulk Actions', 'zeko-jobs' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Set Active', 'zeko-jobs' ); ?></option>
								<option value="paused"><?php esc_html_e( 'Pause', 'zeko-jobs' ); ?></option>
								<option value="closed"><?php esc_html_e( 'Close', 'zeko-jobs' ); ?></option>
								<option value="feature"><?php esc_html_e( 'Feature', 'zeko-jobs' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'zeko-jobs' ); ?></option>
							</select>
							<button type="button" class="button button-small zeko-bulk-apply-btn"><?php esc_html_e( 'Apply', 'zeko-jobs' ); ?></button>
							<span class="zeko-jobs-count"><?php /* translators: %d: number of jobs */ printf( esc_html__( '%d jobs', 'zeko-jobs' ), count( $employer_jobs ) ); ?></span>
						</div>
						<div class="zeko-jobs-grid zeko-paginated-list" data-per-page="10">
							<?php foreach ( $employer_jobs as $job ) : ?>
								<?php
									$job_id = (int) $job['id'];
									// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
									$app_count = (int) $wpdb->get_var(
										$wpdb->prepare(
											"SELECT COUNT(*) FROM {$table_apps} WHERE job_id = %d",
											$job_id
										)
									);
									// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
									$job_status = $job['status'] ?? 'draft';
								?>
								<div class="zeko-job-card" data-status="<?php echo esc_attr( $job_status ); ?>">
									<div class="zeko-job-card-header">
										<input type="checkbox" class="zeko-job-checkbox" data-job-id="<?php echo esc_attr( $job_id ); ?>">
										<h4>
											<a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] ) ); ?>">
												<?php echo esc_html( $job['title'] ); ?>
											</a>
										</h4>
										<span class="zeko-status-badge zeko-status-<?php echo esc_attr( $job_status ); ?>">
											<?php echo esc_html( ucfirst( $job_status ) ); ?>
										</span>
										<?php if ( ! empty( $job['is_featured'] ) ) : ?>
											<span class="zeko-featured-badge" title="<?php esc_attr_e( 'Featured', 'zeko-jobs' ); ?>">&#9733;</span>
										<?php endif; ?>
									</div>
									<div class="zeko-job-card-meta">
										<span class="zeko-job-card-views">
											<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
											<?php echo esc_html( number_format_i18n( (int) ( $job['views'] ?? 0 ) ) ); ?> <?php esc_html_e( 'views', 'zeko-jobs' ); ?>
										</span>
										<span class="zeko-job-card-applications">
											<span class="dashicons dashicons-groups" aria-hidden="true"></span>
											<a href="#zeko-dashboard-applications" class="zeko-nav-link" data-filter-job="<?php echo esc_attr( $job_id ); ?>"><?php echo esc_html( $app_count ); ?> <?php esc_html_e( 'applications', 'zeko-jobs' ); ?></a>
										</span>
										<span class="zeko-job-card-date">
											<span class="dashicons dashicons-calendar" aria-hidden="true"></span>
											<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $job['created_at'] ) ) ); ?>
										</span>
										<?php
										$payment_label = ! empty( $job['payment_status'] ) ? $job['payment_status'] : 'free';
										$payment_class = 'paid' === $payment_label ? 'zeko-payment-paid' : ( 'pending' === $payment_label ? 'zeko-payment-pending' : 'zeko-payment-free' );
										?>
										<span class="zeko-job-card-payment <?php echo esc_attr( $payment_class ); ?>" title="<?php esc_attr_e( 'Listing payment status', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-money" aria-hidden="true"></span>
											<?php echo esc_html( ucfirst( $payment_label ) ); ?>
										</span>
									</div>
									<?php
									$completeness = $zeko_db->calculate_completeness( $job );
									$comp_class   = $completeness >= 80 ? 'high' : ( $completeness >= 50 ? 'medium' : 'low' );
									?>
								<div class="zeko-job-card-completeness" title="<?php /* translators: %d: job profile completeness percentage */ echo esc_attr( sprintf( __( '%d%% complete', 'zeko-jobs' ), $completeness ) ); ?>">
									<div class="zeko-completeness-bar">
										<div class="zeko-completeness-fill zeko-completeness-<?php echo esc_attr( $comp_class ); ?>" style="width:<?php echo esc_attr( $completeness ); ?>%"></div>
									</div>
									<span class="zeko-completeness-label"><?php echo esc_html( $completeness ); ?>%</span>
								</div>
								<?php
								$resp_label = $zeko_db->get_employer_response_time_label( get_current_user_id() );
								?>
								<div class="zeko-job-card-meta">
									<span class="zeko-response-time">
										<span class="dashicons dashicons-clock" aria-hidden="true"></span>
										<?php echo esc_html( $resp_label ); ?>
									</span>
								</div>
								<div class="zeko-job-card-actions">
									<button type="button" class="button button-small zeko-job-analytics-btn" data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'View Analytics', 'zeko-jobs' ); ?>">
										<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
									</button>
									<select class="zeko-job-status-select" data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
											<option value="draft" <?php selected( $job_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'zeko-jobs' ); ?></option>
											<option value="publish" <?php selected( $job_status, 'publish' ); ?>><?php esc_html_e( 'Active', 'zeko-jobs' ); ?></option>
											<option value="paused" <?php selected( $job_status, 'paused' ); ?>><?php esc_html_e( 'Paused', 'zeko-jobs' ); ?></option>
											<option value="closed" <?php selected( $job_status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'zeko-jobs' ); ?></option>
											<option value="expired" <?php selected( $job_status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'zeko-jobs' ); ?></option>
									</select>
									<?php if ( 'expired' === $job_status || ( ! empty( $job['expires_at'] ) && strtotime( $job['expires_at'] ) < strtotime( '+7 days' ) ) ) : ?>
										<button type="button" class="button button-small zeko-renew-btn" data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'Renew Job (extend 30 days)', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-update" aria-hidden="true"></span>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small zeko-featured-toggle-btn" data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" aria-label="<?php echo ! empty( $job['is_featured'] ) ? esc_attr__( 'Unfeature Job', 'zeko-jobs' ) : esc_attr__( 'Feature Job', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-star-<?php echo ! empty( $job['is_featured'] ) ? 'filled' : 'empty'; ?>" aria-hidden="true"></span>
										</button>
										<a href="<?php echo esc_url( home_url( '/post-a-job/?edit=' . $job_id ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Edit Job', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										</a>
										<button type="button" class="button button-small zeko-duplicate-job-btn" data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'Duplicate Job', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
										</button>
										<button type="button" class="button button-small button-link-delete zeko-delete-job-btn" data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'Delete Job', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-trash" aria-hidden="true"></span>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="zeko-pagination zeko-jobs-pagination"></div>
					<?php else : ?>
						<div class="zeko-empty-state">
							<p><?php esc_html_e( 'You have not posted any jobs yet.', 'zeko-jobs' ); ?></p>
							<a href="<?php echo esc_url( home_url( '/post-a-job/' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Post a New Job', 'zeko-jobs' ); ?></a>
						</div>
					<?php endif; ?>

					<div id="zeko-job-analytics-panel" class="zeko-job-analytics-panel" style="display:none;">
						<div class="zeko-section-header">
							<h3 id="zeko-analytics-job-title"></h3>
							<div class="zeko-section-actions">
								<select id="zeko-analytics-period" class="zeko-bulk-select">
									<option value="7"><?php esc_html_e( 'Last 7 Days', 'zeko-jobs' ); ?></option>
									<option value="30" selected><?php esc_html_e( 'Last 30 Days', 'zeko-jobs' ); ?></option>
									<option value="90"><?php esc_html_e( 'Last 90 Days', 'zeko-jobs' ); ?></option>
								</select>
								<button type="button" class="button button-small zeko-export-analytics-btn" id="zeko-export-analytics">
									<span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export CSV', 'zeko-jobs' ); ?>
								</button>
								<button type="button" class="button button-small zeko-close-analytics-panel" id="zeko-close-analytics">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							</div>
						</div>
						<div class="zeko-analytics-grid">
							<div class="zeko-analytics-card"><span class="zeko-analytics-number" id="zeko-analytics-total-views">0</span><span class="zeko-analytics-label"><?php esc_html_e( 'Total Views', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-analytics-card"><span class="zeko-analytics-number" id="zeko-analytics-unique-visitors">0</span><span class="zeko-analytics-label"><?php esc_html_e( 'Unique Visitors', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-analytics-card"><span class="zeko-analytics-number" id="zeko-analytics-total-apps">0</span><span class="zeko-analytics-label"><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></span></div>
							<div class="zeko-analytics-card"><span class="zeko-analytics-number" id="zeko-analytics-conversion">0%</span><span class="zeko-analytics-label"><?php esc_html_e( 'Conversion Rate', 'zeko-jobs' ); ?></span></div>
						</div>
						<div id="zeko-analytics-comparison" class="zeko-analytics-comparison" style="margin-bottom:16px;"></div>
						<div class="zeko-analytics-chart-wrapper" style="position:relative;height:250px;margin-bottom:16px;">
							<canvas id="zeko-analytics-chart"></canvas>
						</div>
						<div id="zeko-analytics-status-breakdown" class="zeko-analytics-status-breakdown"></div>
					</div>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-applications" data-section="applications">
					<div class="zeko-section-header">
						<h3><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></h3>
						<div class="zeko-section-actions">
							<select id="zeko-app-filter-status" class="zeko-bulk-select">
								<option value=""><?php esc_html_e( 'All Statuses', 'zeko-jobs' ); ?></option>
								<option value="awaiting_review"><?php esc_html_e( 'Awaiting Review', 'zeko-jobs' ); ?></option>
								<option value="reviewed"><?php esc_html_e( 'Reviewed', 'zeko-jobs' ); ?></option>
								<option value="contacting"><?php esc_html_e( 'Contacting', 'zeko-jobs' ); ?></option>
								<option value="interviewing"><?php esc_html_e( 'Interviewing', 'zeko-jobs' ); ?></option>
								<option value="offered"><?php esc_html_e( 'Offered', 'zeko-jobs' ); ?></option>
								<option value="hired"><?php esc_html_e( 'Hired', 'zeko-jobs' ); ?></option>
								<option value="archived"><?php esc_html_e( 'Archived', 'zeko-jobs' ); ?></option>
								<option value="rejected"><?php esc_html_e( 'Rejected', 'zeko-jobs' ); ?></option>
							</select>
							<select id="zeko-app-filter-job" class="zeko-bulk-select">
								<option value=""><?php esc_html_e( 'All Jobs', 'zeko-jobs' ); ?></option>
								<?php foreach ( $employer_jobs as $ej ) : ?>
									<option value="<?php echo esc_attr( $ej['id'] ); ?>"><?php echo esc_html( $ej['title'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button button-small zeko-bulk-email-btn" style="display:none;"><?php esc_html_e( 'Send Email', 'zeko-jobs' ); ?></button>
							<button type="button" class="button button-small button-primary zeko-compare-apps-btn" style="display:none;" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>"><span class="dashicons dashicons-spreadsheet" aria-hidden="true"></span> <?php esc_html_e( 'Compare Selected', 'zeko-jobs' ); ?></button>
						</div>
					</div>

					<?php if ( empty( $employer_all_apps ) ) : ?>
						<div class="zeko-empty-state">
							<p><?php esc_html_e( 'No applications for your jobs yet.', 'zeko-jobs' ); ?></p>
						</div>
					<?php else : ?>
						<p class="zeko-jobs-showing-count">
							<?php
							printf(
								/* translators: 1: number of apps shown, 2: total number of apps */
								esc_html__( 'Showing %1$d of %2$d applications', 'zeko-jobs' ),
								count( $employer_all_apps ),
								(int) $employer_app_total
							);
							?>
						</p>
						<div class="zeko-applications-list zeko-paginated-list" data-per-page="15">
							<?php foreach ( $employer_all_apps as $app ) : ?>
								<div class="zeko-application-card zeko-employer-app-card" data-status="<?php echo esc_attr( $app['status'] ); ?>" data-job-id="<?php echo esc_attr( $app['job_id'] ); ?>" data-app-id="<?php echo esc_attr( $app['id'] ); ?>" data-seeker-id="<?php echo esc_attr( $app['seeker_id'] ); ?>">
									<label class="zeko-compare-checkbox">
										<input type="checkbox" class="zeko-compare-select" data-app-id="<?php echo esc_attr( $app['id'] ); ?>" data-seeker-id="<?php echo esc_attr( $app['seeker_id'] ); ?>">
										<span class="zeko-bulk-checkmark"></span>
									</label>
									<div class="zeko-application-info">
										<h4>
											<a href="<?php echo esc_url( home_url( '/jobs/' . $app['job_slug'] ) ); ?>"><?php echo esc_html( $app['job_title'] ); ?></a>
										</h4>
										<div class="zeko-application-meta">
											<span class="zeko-app-seeker"><?php echo esc_html( $app['seeker_name'] ? $app['seeker_name'] : ( $app['seeker_login'] ?? '' ) ); ?>
												<?php if ( ! empty( $app['seeker_id'] ) && (int) get_user_meta( $app['seeker_id'], 'zeko_open_to_work', true ) ) : ?>
													<span class="zeko-badge zeko-badge-success" style="font-size:10px;padding:1px 6px;margin-left:4px;"><?php esc_html_e( 'Open to Work', 'zeko-jobs' ); ?></span>
												<?php endif; ?>
											</span>
											<span class="zeko-application-date"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $app['applied_at'] ) ) ); ?></span>
											<span class="zeko-status-badge zeko-status-<?php echo esc_attr( $app['status'] ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $app['status'] ) ) ); ?></span>
										</div>
									</div>
									<div class="zeko-application-actions">
										<select class="zeko-status-select" data-application-id="<?php echo esc_attr( $app['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
											<option value="awaiting_review" <?php selected( $app['status'], 'awaiting_review' ); ?>><?php esc_html_e( 'Awaiting Review', 'zeko-jobs' ); ?></option>
											<option value="reviewed" <?php selected( $app['status'], 'reviewed' ); ?>><?php esc_html_e( 'Reviewed', 'zeko-jobs' ); ?></option>
											<option value="contacting" <?php selected( $app['status'], 'contacting' ); ?>><?php esc_html_e( 'Contacting', 'zeko-jobs' ); ?></option>
											<option value="interviewing" <?php selected( $app['status'], 'interviewing' ); ?>><?php esc_html_e( 'Interviewing', 'zeko-jobs' ); ?></option>
											<option value="offered" <?php selected( $app['status'], 'offered' ); ?>><?php esc_html_e( 'Offered', 'zeko-jobs' ); ?></option>
											<option value="hired" <?php selected( $app['status'], 'hired' ); ?>><?php esc_html_e( 'Hired', 'zeko-jobs' ); ?></option>
											<option value="archived" <?php selected( $app['status'], 'archived' ); ?>><?php esc_html_e( 'Archived', 'zeko-jobs' ); ?></option>
											<option value="rejected" <?php selected( $app['status'], 'rejected' ); ?>><?php esc_html_e( 'Rejected', 'zeko-jobs' ); ?></option>
										</select>
										<button type="button" class="button button-small zeko-view-app-btn" data-application-id="<?php echo esc_attr( $app['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" title="<?php esc_attr_e( 'View Details', 'zeko-jobs' ); ?>">
											<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="zeko-pagination zeko-apps-pagination"></div>
					<?php endif; ?>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-pipeline" data-section="pipeline">
					<div class="zeko-section-header">
						<h3><?php esc_html_e( 'Pipeline Board', 'zeko-jobs' ); ?></h3>
						<p class="zeko-section-desc"><?php esc_html_e( 'Drag and drop cards between columns to update application status.', 'zeko-jobs' ); ?></p>
					</div>
					<div class="zeko-pipeline-filters" style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
						<input type="search" class="zeko-pipeline-search" placeholder="<?php esc_attr_e( 'Search by name or job...', 'zeko-jobs' ); ?>" style="padding:6px 10px;border:1px solid var(--color-border);border-radius:var(--radius-sm);min-width:200px;">
						<select class="zeko-pipeline-filter-job" style="padding:6px 10px;border:1px solid var(--color-border);border-radius:var(--radius-sm);">
							<option value=""><?php esc_html_e( 'All Jobs', 'zeko-jobs' ); ?></option>
							<?php foreach ( $employer_jobs as $ej ) : ?>
								<option value="<?php echo esc_attr( $ej['id'] ); ?>"><?php echo esc_html( $ej['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="date" class="zeko-pipeline-filter-date" style="padding:6px 10px;border:1px solid var(--color-border);border-radius:var(--radius-sm);" title="<?php esc_attr_e( 'Filter by application date', 'zeko-jobs' ); ?>">
						<button type="button" class="button button-small zeko-pipeline-clear-filters"><?php esc_html_e( 'Clear', 'zeko-jobs' ); ?></button>
					</div>
					<?php
					$kanban_statuses = $zeko_db->get_employer_pipeline_stages( (int) $user_id );
					$kanban_colors   = $zeko_db->get_pipeline_colors();
					// Build next-status map from ordered keys.
					$kanban_keys  = array_keys( $kanban_statuses );
					$kanban_count = count( $kanban_keys ) - 1;
					$next_status  = array();
					for ( $i = 0; $i < $kanban_count; $i++ ) {
						$next_status[ $kanban_keys[ $i ] ] = $kanban_keys[ $i + 1 ];
					}
					?>
					<div class="zeko-kanban-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
						<h3 style="margin:0;"><?php esc_html_e( 'Application Pipeline', 'zeko-jobs' ); ?></h3>
						<div style="display:flex;gap:8px;align-items:center;">
							<div class="zeko-kanban-bulk-actions" style="display:none;">
								<select class="zeko-bulk-stage-select" style="padding:4px 8px;font-size:12px;">
									<option value=""><?php esc_html_e( 'Move selected to...', 'zeko-jobs' ); ?></option>
									<?php foreach ( $kanban_statuses as $s => $l ) : ?>
										<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $l ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="button button-small zeko-bulk-move-btn"><?php esc_html_e( 'Apply', 'zeko-jobs' ); ?></button>
								<span class="zeko-bulk-count" style="font-size:12px;color:#666;"></span>
							</div>
							<button type="button" class="button button-small zeko-manage-stages-btn" id="zeko-manage-stages-btn">
								<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'Manage Stages', 'zeko-jobs' ); ?>
							</button>
						</div>
					</div>
					<div class="zeko-kanban-board" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>" data-next-statuses="<?php echo esc_attr( wp_json_encode( $next_status ) ); ?>">
						<?php
						foreach ( $kanban_statuses as $status => $label ) :
							$status_apps = array_filter(
								$employer_all_apps,
								static function ( $a ) use ( $status ) {
									return $a['status'] === $status;
								}
							);
							$color       = $kanban_colors[ $status ] ?? '#6b7280';
							?>
							<div class="zeko-kanban-column" data-status="<?php echo esc_attr( $status ); ?>">
								<h4 style="border-left: 3px solid <?php echo esc_attr( $color ); ?>; padding-left: 8px;"><?php echo esc_html( $label ); ?> <span class="zeko-kanban-count"><?php echo esc_html( count( $status_apps ) ); ?></span></h4>
								<div class="zeko-kanban-cards">
									<?php
									foreach ( $status_apps as $app ) :
										$days_old = (int) floor( ( time() - strtotime( $app['applied_at'] ) ) / DAY_IN_SECONDS );
										if ( in_array( $app['status'], array( 'interviewing', 'offered', 'hired' ), true ) ) {
											$priority = 'low';
										} elseif ( 'awaiting_review' === $app['status'] && $days_old >= 7 ) {
											$priority = 'urgent';
										} elseif ( $days_old >= 3 ) {
											$priority = 'high';
										} else {
											$priority = '';
										}
										?>
										<div class="zeko-kanban-card<?php echo $priority ? ' zeko-priority-' . esc_attr( $priority ) : ''; ?>" data-app-id="<?php echo esc_attr( $app['id'] ); ?>" data-job-id="<?php echo esc_attr( $app['job_id'] ); ?>" data-date="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $app['applied_at'] ) ) ); ?>" data-search="<?php echo esc_attr( strtolower( ( $app['seeker_name'] ?? '' ) . ' ' . ( $app['seeker_login'] ?? '' ) . ' ' . $app['job_title'] ) ); ?>" draggable="true">
											<label class="zeko-kanban-select" title="<?php esc_attr_e( 'Select for bulk move', 'zeko-jobs' ); ?>">
												<input type="checkbox" class="zeko-kanban-checkbox" data-app-id="<?php echo esc_attr( $app['id'] ); ?>">
											</label>
											<?php if ( $priority ) : ?>
												<span class="zeko-priority-tag zeko-priority-tag-<?php echo esc_attr( $priority ); ?>"><?php echo esc_html( ucfirst( $priority ) ); ?></span>
											<?php endif; ?>
											<strong><?php echo esc_html( $app['job_title'] ); ?></strong>
											<span class="zeko-kanban-seeker"><?php echo esc_html( $app['seeker_name'] ? $app['seeker_name'] : ( $app['seeker_login'] ?? '' ) ); ?>
												<?php if ( ! empty( $app['seeker_id'] ) && (int) get_user_meta( $app['seeker_id'], 'zeko_open_to_work', true ) ) : ?>
													<span class="zeko-badge zeko-badge-success" style="font-size:9px;padding:1px 5px;margin-left:4px;"><?php esc_html_e( 'OTW', 'zeko-jobs' ); ?></span>
												<?php endif; ?>
											</span>
											<span class="zeko-kanban-date"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $app['applied_at'] ) ) ); ?></span>
											<div class="zeko-kanban-card-actions">
												<button type="button" class="button button-small zeko-compare-candidate-btn" data-app-id="<?php echo esc_attr( $app['id'] ); ?>" title="<?php esc_attr_e( 'Compare', 'zeko-jobs' ); ?>">
													<span class="dashicons dashicons-columns" aria-hidden="true"></span>
												</button>
												<button type="button" class="button button-small zeko-schedule-interview-btn" data-app-id="<?php echo esc_attr( $app['id'] ); ?>" data-seeker="<?php echo esc_attr( $app['seeker_name'] ? $app['seeker_name'] : ( $app['seeker_login'] ?? '' ) ); ?>" data-job="<?php echo esc_attr( $app['job_title'] ); ?>" title="<?php esc_attr_e( 'Schedule Interview', 'zeko-jobs' ); ?>">
													<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
												</button>
												<button type="button" class="button button-small zeko-view-app-btn" data-application-id="<?php echo esc_attr( $app['id'] ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
													<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
												</button>
											</div>
										</div>
									<?php endforeach; ?>
									<?php if ( empty( $status_apps ) ) : ?>
										<div class="zeko-kanban-empty"><?php esc_html_e( 'No applications', 'zeko-jobs' ); ?></div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<?php
						// Sortable.min.js is enqueued in Zeko_Jobs_Public::enqueue_assets()
						// when the employer dashboard/shortcode is present on the page.
					?>
				</div>

				<!-- Pipeline Stages Modal -->
				<div class="zeko-modal-overlay" id="zeko-pipeline-modal" style="display:none;">
					<div class="zeko-modal" style="max-width:520px;">
						<div class="zeko-modal-header">
							<h3><?php esc_html_e( 'Manage Pipeline Stages', 'zeko-jobs' ); ?></h3>
							<button type="button" class="zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
						</div>
						<div class="zeko-modal-body">
							<p class="zeko-compare-hint"><?php esc_html_e( 'Drag to reorder. Add or rename stages. Click Save when done.', 'zeko-jobs' ); ?></p>
							<div id="zeko-pipeline-stages-list"></div>
							<div style="margin-top:12px;display:flex;gap:8px;">
								<input type="text" id="zeko-new-stage-slug" placeholder="<?php esc_attr_e( 'stage-slug', 'zeko-jobs' ); ?>" style="width:140px;">
								<input type="text" id="zeko-new-stage-label" placeholder="<?php esc_attr_e( 'Stage Label', 'zeko-jobs' ); ?>" style="flex:1;">
								<button type="button" class="button button-small" id="zeko-add-stage-btn">
									<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
								</button>
							</div>
						</div>
						<div class="zeko-modal-footer">
							<button type="button" class="button zeko-pipeline-cancel-btn"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
							<button type="button" class="button button-primary" id="zeko-save-pipeline-btn">
								<span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php esc_html_e( 'Save Stages', 'zeko-jobs' ); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- Schedule Interview Modal -->
				<div class="zeko-modal-overlay" id="zeko-schedule-interview-modal" style="display:none;">
					<div class="zeko-modal" style="max-width:520px;">
						<div class="zeko-modal-header">
							<h3><?php esc_html_e( 'Schedule Interview', 'zeko-jobs' ); ?></h3>
							<button type="button" class="zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
						</div>
						<div class="zeko-modal-body">
							<p id="zeko-schedule-interview-info" style="margin-bottom:12px;color:var(--color-text-secondary);"></p>
							<form class="zeko-form" id="zeko-schedule-interview-form">
								<input type="hidden" name="application_id" id="zeko-schedule-app-id">
								<div class="zeko-field">
									<label><?php esc_html_e( 'Date & Time', 'zeko-jobs' ); ?> *</label>
									<input type="datetime-local" name="scheduled_at" required>
								</div>
								<div class="zeko-field">
									<label><?php esc_html_e( 'Duration (minutes)', 'zeko-jobs' ); ?></label>
									<select name="duration">
										<option value="15">15</option>
										<option value="30">30</option>
										<option value="45">45</option>
										<option value="60" selected>60</option>
										<option value="90">90</option>
										<option value="120">120</option>
									</select>
								</div>
								<div class="zeko-field">
									<label><?php esc_html_e( 'Type', 'zeko-jobs' ); ?></label>
									<select name="type">
										<option value="video"><?php esc_html_e( 'Video Call', 'zeko-jobs' ); ?></option>
										<option value="phone"><?php esc_html_e( 'Phone', 'zeko-jobs' ); ?></option>
										<option value="onsite"><?php esc_html_e( 'On-site', 'zeko-jobs' ); ?></option>
									</select>
								</div>
								<div class="zeko-field">
									<label><?php esc_html_e( 'Video Meeting URL', 'zeko-jobs' ); ?></label>
									<input type="url" name="meeting_url" id="zeko-meeting-url" placeholder="https://meet.google.com/... or https://zoom.us/j/...">
									<p class="description"><?php esc_html_e( 'Google Meet, Zoom, Teams, or any video call link.', 'zeko-jobs' ); ?></p>
								</div>
								<div class="zeko-field">
									<label><?php esc_html_e( 'Physical Location', 'zeko-jobs' ); ?></label>
									<input type="text" name="location" placeholder="<?php esc_attr_e( 'Office address (if on-site)', 'zeko-jobs' ); ?>">
								</div>
								<div class="zeko-field">
									<label><?php esc_html_e( 'Interviewer', 'zeko-jobs' ); ?></label>
									<select name="interviewer_id" id="zeko-interviewer-select">
										<option value=""><?php esc_html_e( '— Select interviewer —', 'zeko-jobs' ); ?></option>
										<?php
										$team_members = $zeko_db->get_employer_team_members( (int) $user_id );
										foreach ( $team_members as $tm ) :
											?>
											<option value="<?php echo esc_attr( $tm['id'] ); ?>"><?php echo esc_html( $tm['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="zeko-field">
									<label><?php esc_html_e( 'Notes', 'zeko-jobs' ); ?></label>
									<textarea name="notes" rows="3" placeholder="<?php esc_attr_e( 'Meeting agenda, preparation notes...', 'zeko-jobs' ); ?>"></textarea>
								</div>
							</form>
						</div>
						<div class="zeko-modal-footer">
							<button type="button" class="button zeko-modal-close"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
							<button type="button" class="button button-primary" id="zeko-confirm-schedule-btn">
								<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php esc_html_e( 'Schedule', 'zeko-jobs' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-calendar" data-section="calendar" style="display:none;">
					<div class="zeko-section-header">
						<h3><?php esc_html_e( 'Interview Calendar', 'zeko-jobs' ); ?></h3>
						<p class="zeko-section-desc"><?php esc_html_e( 'Upcoming scheduled interviews.', 'zeko-jobs' ); ?></p>
					</div>
					<?php
					$current_month = isset( $_GET['cal_month'] ) ? sanitize_text_field( wp_unslash( $_GET['cal_month'] ) ) : gmdate( 'Y-m' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only calendar month selector; no state change is performed based on this param.
					$interviews    = $zeko_db->get_employer_interviews( $user_id, $current_month );
					$prev_month    = gmdate( 'Y-m', strtotime( $current_month . '-01 -1 month' ) );
					$next_month    = gmdate( 'Y-m', strtotime( $current_month . '-01 +1 month' ) );
					?>
					<div class="zeko-calendar-nav" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
						<button type="button" class="button button-small zeko-cal-nav-btn" data-month="<?php echo esc_attr( $prev_month ); ?>">&larr; <?php esc_html_e( 'Prev', 'zeko-jobs' ); ?></button>
						<strong><?php echo esc_html( date_i18n( 'F Y', strtotime( $current_month . '-01' ) ) ); ?></strong>
						<button type="button" class="button button-small zeko-cal-nav-btn" data-month="<?php echo esc_attr( $next_month ); ?>"><?php esc_html_e( 'Next', 'zeko-jobs' ); ?> &rarr;</button>
					</div>
					<?php if ( empty( $interviews ) ) : ?>
						<div class="zeko-empty-state">
							<p><?php esc_html_e( 'No interviews scheduled this month.', 'zeko-jobs' ); ?></p>
						</div>
					<?php else : ?>
						<div class="zeko-calendar-grid">
							<?php
							$days_in_month     = (int) gmdate( 't', strtotime( $current_month . '-01' ) );
							$start_day         = (int) gmdate( 'w', strtotime( $current_month . '-01' ) );
							$day_names         = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
							$interviews_by_day = array();
							foreach ( $interviews as $int ) {
								$day                         = (int) gmdate( 'j', strtotime( $int['scheduled_at'] ) );
								$interviews_by_day[ $day ][] = $int;
							}
							?>
							<div class="zeko-calendar-header">
								<?php foreach ( $day_names as $dn ) : ?>
									<div class="zeko-calendar-day-name"><?php echo esc_html( $dn ); ?></div>
								<?php endforeach; ?>
							</div>
							<div class="zeko-calendar-body">
								<?php for ( $i = 0; $i < $start_day; $i++ ) : ?>
									<div class="zeko-calendar-cell zeko-calendar-empty"></div>
								<?php endfor; ?>
								<?php
								for ( $day = 1; $day <= $days_in_month; $day++ ) :
									$day_interviews = $interviews_by_day[ $day ] ?? array();
									$is_today       = ( (int) gmdate( 'j' ) === $day && gmdate( 'Y-m' ) === $current_month );
									?>
									<div class="zeko-calendar-cell<?php echo $is_today ? ' zeko-calendar-today' : ''; ?><?php echo ! empty( $day_interviews ) ? ' has-events' : ''; ?>">
										<span class="zeko-calendar-day-num"><?php echo esc_html( $day ); ?></span>
										<?php foreach ( $day_interviews as $di ) : ?>
											<div class="zeko-calendar-event" title="<?php echo esc_attr( $di['job_title'] . ' — ' . ( $di['seeker_name'] ?? $di['seeker_login'] ) . ' @ ' . date_i18n( 'g:i A', strtotime( $di['scheduled_at'] ) ) ); ?>">
												<span class="zeko-calendar-event-time"><?php echo esc_html( date_i18n( 'g:i A', strtotime( $di['scheduled_at'] ) ) ); ?></span>
												<span class="zeko-calendar-event-title"><?php echo esc_html( $di['seeker_name'] ? $di['seeker_name'] : $di['seeker_login'] ); ?></span>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endfor; ?>
								<?php for ( $i = ( $start_day + $days_in_month ); 0 !== $i % 7; $i++ ) : ?>
									<div class="zeko-calendar-cell zeko-calendar-empty"></div>
								<?php endfor; ?>
							</div>
						</div>
							<div class="zeko-calendar-list" style="margin-top:20px;">
							<h4><?php esc_html_e( 'Upcoming This Month', 'zeko-jobs' ); ?></h4>
							<?php
							foreach ( $interviews as $int ) :
								$has_feedback = ! empty( get_post_meta( $int['id'], 'zeko_interview_feedback', true ) );
								$proposed     = ! empty( $int['proposed_times'] ) ? json_decode( $int['proposed_times'], true ) : array();
								?>
								<div class="zeko-calendar-list-item">
									<span class="zeko-calendar-list-date"><?php echo esc_html( date_i18n( 'M j, g:i A', strtotime( $int['scheduled_at'] ) ) ); ?></span>
									<span class="zeko-calendar-list-seeker"><?php echo esc_html( $int['seeker_name'] ? $int['seeker_name'] : $int['seeker_login'] ); ?></span>
									<span class="zeko-calendar-list-job"><?php echo esc_html( $int['job_title'] ); ?></span>
									<span class="zeko-calendar-list-type"><?php echo esc_html( ucfirst( $int['type'] ) ); ?></span>
									<?php if ( ! empty( $int['interviewer_name'] ) ) : ?>
										<span class="zeko-calendar-list-interviewer" style="font-size:12px;color:var(--color-text-muted);"><span class="dashicons dashicons-admin-users" style="font-size:12px;width:14px;height:14px;" aria-hidden="true"></span> <?php echo esc_html( $int['interviewer_name'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $int['meeting_url'] ) ) : ?>
										<a href="<?php echo esc_url( $int['meeting_url'] ); ?>" class="button button-small button-primary" target="_blank" rel="noopener noreferrer" style="margin-top:4px;">
											<span class="dashicons dashicons-video-alt3" aria-hidden="true"></span> <?php esc_html_e( 'Join Video Call', 'zeko-jobs' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( ! empty( $proposed ) && empty( $int['accepted_time'] ) ) : ?>
										<div class="zeko-proposed-times-list" style="margin-top:6px;">
											<span style="font-size:12px;font-weight:600;color:var(--color-text-secondary);"><?php esc_html_e( 'Proposed times:', 'zeko-jobs' ); ?></span>
											<?php foreach ( $proposed as $pt ) : ?>
												<button type="button" class="button button-small zeko-accept-time-btn" data-interview-id="<?php echo esc_attr( $int['id'] ); ?>" data-time="<?php echo esc_attr( $pt ); ?>" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
													<span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' g:i A', strtotime( $pt ) ) ); ?>
												</button>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
									<button type="button" class="button button-small zeko-interview-feedback-btn" data-interview-id="<?php echo esc_attr( $int['id'] ); ?>" title="<?php esc_attr_e( 'Feedback', 'zeko-jobs' ); ?>">
										<?php if ( $has_feedback ) : ?>
											<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Feedback', 'zeko-jobs' ); ?>
										<?php else : ?>
											<span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( 'Add Feedback', 'zeko-jobs' ); ?>
										<?php endif; ?>
									</button>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-company" data-section="company" style="display:none;">
					<h3><?php esc_html_e( 'Company Profile', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Complete your company profile to build trust with applicants. Manage multiple company brands here.', 'zeko-jobs' ); ?></p>
					<?php
					$my_companies = $zeko_db->get_companies_by_employer( get_current_user_id() );
					$company      = $zeko_db->get_company_by_employer( get_current_user_id() );
					?>
					<?php if ( count( $my_companies ) > 1 ) : ?>
						<div class="zeko-field">
							<label for="zeko-company-switcher"><?php esc_html_e( 'Switch Company', 'zeko-jobs' ); ?></label>
							<select id="zeko-company-switcher">
								<option value=""><?php esc_html_e( '-- Select a brand to edit --', 'zeko-jobs' ); ?></option>
								<?php foreach ( $my_companies as $c ) : ?>
									<option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( $company['id'] ?? 0, $c['id'] ); ?>>
										<?php echo esc_html( $c['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>
					<button type="button" class="button zeko-company-add-btn" style="margin-bottom:14px;">
						<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span> <?php esc_html_e( 'Add New Company', 'zeko-jobs' ); ?>
					</button>
					<script type="application/json" id="zeko-companies-json"><?php echo wp_json_encode( array_values( $my_companies ) ); ?></script>
					<form class="zeko-form" id="zeko-company-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
						<input type="hidden" name="company_id" id="zeko-company-id" value="">
						<input type="hidden" name="company_new" id="zeko-company-new" value="0">
						<div class="zeko-field">
							<label for="zeko-company-name"><?php esc_html_e( 'Company Name', 'zeko-jobs' ); ?> *</label>
							<input type="text" id="zeko-company-name" name="company_name" value="<?php echo esc_attr( $company['name'] ?? '' ); ?>" required>
						</div>
						<div class="zeko-field">
							<label for="zeko-company-description"><?php esc_html_e( 'About', 'zeko-jobs' ); ?></label>
							<textarea id="zeko-company-description" name="company_description" rows="4"><?php echo esc_textarea( $company['description'] ?? '' ); ?></textarea>
						</div>
						<div class="zeko-field" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div>
								<label for="zeko-company-industry"><?php esc_html_e( 'Industry', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-company-industry" name="company_industry" value="<?php echo esc_attr( $company['industry'] ?? '' ); ?>">
							</div>
							<div>
								<label for="zeko-company-size"><?php esc_html_e( 'Company Size', 'zeko-jobs' ); ?></label>
								<select id="zeko-company-size" name="company_size">
									<option value=""><?php esc_html_e( 'Select...', 'zeko-jobs' ); ?></option>
									<?php foreach ( array( '1-10', '11-50', '51-200', '201-500', '501-1000', '1000+' ) as $size ) : ?>
										<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $company['size'] ?? '', $size ); ?>><?php echo esc_html( $size ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="zeko-field" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div>
								<label for="zeko-company-website"><?php esc_html_e( 'Website', 'zeko-jobs' ); ?></label>
								<input type="url" id="zeko-company-website" name="company_website" value="<?php echo esc_attr( $company['website'] ?? '' ); ?>" placeholder="https://">
							</div>
							<div>
								<label for="zeko-company-location"><?php esc_html_e( 'Location', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-company-location" name="company_location" value="<?php echo esc_attr( $company['location'] ?? '' ); ?>">
							</div>
						</div>
						<div class="zeko-field" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div>
								<label for="zeko-company-founded"><?php esc_html_e( 'Founded Year', 'zeko-jobs' ); ?></label>
								<input type="number" id="zeko-company-founded" name="company_founded" value="<?php echo esc_attr( $company['founded_year'] ?? '' ); ?>" min="1900" max="<?php echo esc_attr( gmdate( 'Y' ) ); ?>">
							</div>
							<div>
								<label for="zeko-company-linkedin"><?php esc_html_e( 'LinkedIn', 'zeko-jobs' ); ?></label>
								<input type="url" id="zeko-company-linkedin" name="company_linkedin" value="<?php echo esc_attr( $company['linkedin'] ?? '' ); ?>" placeholder="https://linkedin.com/company/">
							</div>
						</div>
						<div class="zeko-field">
							<label for="zeko-company-twitter"><?php esc_html_e( 'Twitter / X', 'zeko-jobs' ); ?></label>
							<input type="url" id="zeko-company-twitter" name="company_twitter" value="<?php echo esc_attr( $company['twitter'] ?? '' ); ?>" placeholder="https://x.com/">
						</div>
						<div class="zeko-field" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div>
								<label for="zeko-company-cover"><?php esc_html_e( 'Cover Image URL', 'zeko-jobs' ); ?></label>
								<input type="url" id="zeko-company-cover" name="company_cover" value="<?php echo esc_attr( $company['cover_url'] ?? '' ); ?>" placeholder="https://">
							</div>
							<div>
								<label for="zeko-company-accent"><?php esc_html_e( 'Accent Color', 'zeko-jobs' ); ?></label>
								<input type="color" id="zeko-company-accent" name="company_accent" value="<?php echo esc_attr( $company['accent_color'] ?? '#6366f1' ); ?>">
							</div>
						</div>
						<div class="zeko-field">
							<label for="zeko-company-video"><?php esc_html_e( 'Company Video URL (YouTube / Vimeo)', 'zeko-jobs' ); ?></label>
							<input type="url" id="zeko-company-video" name="company_video" value="<?php echo esc_attr( $company['video_url'] ?? '' ); ?>" placeholder="https://www.youtube.com/watch?v=...">
						</div>
						<div class="zeko-field">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Company Profile', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-company-message"></span>
						</div>
					</form>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-company-blog" data-section="company-blog" style="display:none;">
					<h3><?php esc_html_e( 'Company Blog', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Publish hiring updates and company news. Posts appear on your public company page.', 'zeko-jobs' ); ?></p>
					<?php
					$blog_posts = $zeko_db->get_employer_company_posts( $user_id );
					?>
					<?php if ( ! empty( $blog_posts ) ) : ?>
						<div class="zeko-company-posts-list">
							<?php foreach ( $blog_posts as $bp ) : ?>
								<div class="zeko-company-post-item" data-blog-id="<?php echo esc_attr( $bp['id'] ); ?>">
									<div class="zeko-company-post-main">
										<strong><?php echo esc_html( $bp['title'] ); ?></strong>
										<span class="zeko-company-post-meta">
											<?php echo esc_html( $bp['company_name'] ?? '' ); ?> &middot;
											<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $bp['created_at'] ) ) ); ?>
										</span>
									</div>
									<div class="zeko-company-post-actions">
										<button type="button" class="button button-small zeko-company-post-edit" data-blog-id="<?php echo esc_attr( $bp['id'] ); ?>"><?php esc_html_e( 'Edit', 'zeko-jobs' ); ?></button>
										<button type="button" class="button button-small zeko-company-post-delete" data-blog-id="<?php echo esc_attr( $bp['id'] ); ?>"><?php esc_html_e( 'Delete', 'zeko-jobs' ); ?></button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<hr>
					<?php endif; ?>
					<form class="zeko-form" id="zeko-company-post-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
						<input type="hidden" name="blog_id" id="zeko-blog-id" value="">
						<?php if ( ! empty( $my_companies ) ) : ?>
							<div class="zeko-field">
								<label for="zeko-blog-company"><?php esc_html_e( 'Company', 'zeko-jobs' ); ?> *</label>
								<select id="zeko-blog-company" name="company_id" required>
									<option value=""><?php esc_html_e( '-- Select a company --', 'zeko-jobs' ); ?></option>
									<?php foreach ( $my_companies as $c ) : ?>
										<option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endif; ?>
						<div class="zeko-field">
							<label for="zeko-blog-title"><?php esc_html_e( 'Post Title', 'zeko-jobs' ); ?> *</label>
							<input type="text" id="zeko-blog-title" name="blog_title" required maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Hiring a new Senior Developer!', 'zeko-jobs' ); ?>">
						</div>
						<div class="zeko-field">
							<label for="zeko-blog-content"><?php esc_html_e( 'Post Content', 'zeko-jobs' ); ?> *</label>
							<textarea id="zeko-blog-content" name="blog_content" rows="6" required placeholder="<?php esc_attr_e( 'Share your hiring update or company news...', 'zeko-jobs' ); ?>"></textarea>
						</div>
						<div class="zeko-field">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Publish Post', 'zeko-jobs' ); ?></button>
							<button type="button" class="button zeko-blog-cancel-edit" style="display:none;"><?php esc_html_e( 'Cancel Edit', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-blog-message"></span>
						</div>
					</form>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-search-resumes" data-section="search-resumes" style="display:none;">
					<h3><?php esc_html_e( 'Search Resumes', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Find opt-in candidates who are open to work. Only seekers who enabled profile visibility appear here.', 'zeko-jobs' ); ?></p>
					<form class="zeko-form zeko-resume-search-form" id="zeko-resume-search-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
						<div class="zeko-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
							<div>
								<label for="zeko-resume-keyword"><?php esc_html_e( 'Keyword', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-resume-keyword" name="keyword" placeholder="<?php esc_attr_e( 'Name or bio', 'zeko-jobs' ); ?>">
							</div>
							<div>
								<label for="zeko-resume-skills"><?php esc_html_e( 'Skills', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-resume-skills" name="skills" placeholder="<?php esc_attr_e( 'e.g. React, PHP', 'zeko-jobs' ); ?>">
							</div>
							<div>
								<label for="zeko-resume-location"><?php esc_html_e( 'Location', 'zeko-jobs' ); ?></label>
								<input type="text" id="zeko-resume-location" name="location" placeholder="<?php esc_attr_e( 'City or remote', 'zeko-jobs' ); ?>">
							</div>
						</div>
						<div class="zeko-field">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Search Candidates', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-resume-search-message"></span>
						</div>
					</form>
					<div class="zeko-resume-search-results" id="zeko-resume-search-results"></div>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-analytics" data-section="analytics" style="display:none;">
					<h3><?php esc_html_e( 'Analytics', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Performance overview across all your job listings.', 'zeko-jobs' ); ?></p>
					<?php
					$analytics = $zeko_db->get_employer_analytics( $user_id );
					?>
					<div class="zeko-analytics-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo esc_html( number_format_i18n( $analytics['total_jobs'] ) ); ?></span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Active Jobs', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo esc_html( number_format_i18n( $analytics['total_views'] ) ); ?></span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Total Views', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo esc_html( number_format_i18n( $analytics['total_applications'] ) ); ?></span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo esc_html( $analytics['conversion_rate'] ); ?>%</span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Conversion Rate', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo esc_html( number_format_i18n( $analytics['hired_count'] ) ); ?></span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Hired', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo esc_html( $analytics['avg_views'] ); ?></span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Avg Views / Job', 'zeko-jobs' ); ?></span>
						</div>
						<div class="zeko-analytics-card">
							<span class="zeko-analytics-number"><?php echo null !== $analytics['time_to_fill'] ? esc_html( $analytics['time_to_fill'] ) . ' ' . esc_html__( 'days', 'zeko-jobs' ) : esc_html__( '—', 'zeko-jobs' ); ?></span>
							<span class="zeko-analytics-label"><?php esc_html_e( 'Avg Time to Fill', 'zeko-jobs' ); ?></span>
						</div>
					</div>
					<?php if ( ! empty( $analytics['top_jobs'] ) ) : ?>
						<h4><?php esc_html_e( 'Top Performing Jobs', 'zeko-jobs' ); ?></h4>
						<table class="zeko-table" style="width:100%;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Job', 'zeko-jobs' ); ?></th>
									<th><?php esc_html_e( 'Views', 'zeko-jobs' ); ?></th>
									<th><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></th>
									<th><?php esc_html_e( 'Conversion', 'zeko-jobs' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $analytics['top_jobs'] as $tj ) :
									$tj_conv = (int) $tj['views'] > 0 ? round( ( (int) $tj['app_count'] / (int) $tj['views'] ) * 100, 1 ) : 0;
									?>
									<tr>
										<td><a href="<?php echo esc_url( home_url( '/jobs/' . $tj['slug'] ) ); ?>"><?php echo esc_html( $tj['title'] ); ?></a></td>
										<td><?php echo esc_html( number_format_i18n( (int) $tj['views'] ) ); ?></td>
										<td><?php echo esc_html( (int) $tj['app_count'] ); ?></td>
										<td><?php echo esc_html( $tj_conv ); ?>%</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-auto-response" data-section="auto-response" style="display:none;">
					<h3><?php esc_html_e( 'Auto-Responses', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Automatically reply to candidates when they apply to your jobs.', 'zeko-jobs' ); ?></p>
					<?php
					$auto_response = Zeko_Jobs_Auto_Response::get_instance();
					$ar_settings   = $auto_response->get_settings( $user_id );
					?>
					<form class="zeko-form zeko-auto-response-form" id="zeko-auto-response-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
						<div class="zeko-field">
							<label class="zeko-toggle-label">
								<input type="checkbox" name="auto_response_enabled" value="1" <?php checked( $ar_settings['enabled'], 1 ); ?>>
								<?php esc_html_e( 'Enable auto-responses for new applications', 'zeko-jobs' ); ?>
							</label>
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Subject', 'zeko-jobs' ); ?> *</label>
							<input type="text" name="auto_response_subject" value="<?php echo esc_attr( $ar_settings['subject'] ); ?>" required>
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Message Body', 'zeko-jobs' ); ?> *</label>
							<p class="description"><?php esc_html_e( 'Placeholders: {seeker_name}, {job_title}, {employer_name}, {application_date}', 'zeko-jobs' ); ?></p>
							<textarea name="auto_response_body" rows="8" required><?php echo esc_textarea( $ar_settings['body'] ); ?></textarea>
						</div>
						<div class="zeko-field">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Auto-Response', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-auto-response-message"></span>
						</div>
					</form>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-templates" data-section="templates" style="display:none;">
					<div class="zeko-section-header">
						<h3><?php esc_html_e( 'Email Templates', 'zeko-jobs' ); ?></h3>
						<p class="zeko-section-desc"><?php esc_html_e( 'Manage pre-written email templates for common applicant responses.', 'zeko-jobs' ); ?></p>
					</div>
					<?php
					$email_templates = $zeko_db->get_email_templates( $user_id );
					$template_labels = array(
						'thank_you'          => __( 'Thank You', 'zeko-jobs' ),
						'schedule_interview' => __( 'Schedule Interview', 'zeko-jobs' ),
						'rejection'          => __( 'Rejection', 'zeko-jobs' ),
						'offer'              => __( 'Job Offer', 'zeko-jobs' ),
					);
					?>
					<div class="zeko-email-templates-list">
						<?php foreach ( $email_templates as $tkey => $tpl ) : ?>
							<div class="zeko-email-template-card" data-key="<?php echo esc_attr( $tkey ); ?>">
								<div class="zeko-email-template-header">
									<strong><?php echo esc_html( $tpl['name'] ); ?></strong>
									<div>
										<button type="button" class="button button-small zeko-edit-template-btn" data-key="<?php echo esc_attr( $tkey ); ?>" data-name="<?php echo esc_attr( $tpl['name'] ); ?>" data-subject="<?php echo esc_attr( $tpl['subject'] ); ?>" data-body="<?php echo esc_attr( $tpl['body'] ); ?>"><?php esc_html_e( 'Edit', 'zeko-jobs' ); ?></button>
										<button type="button" class="button button-small zeko-delete-email-template-btn" data-key="<?php echo esc_attr( $tkey ); ?>"><?php esc_html_e( 'Delete', 'zeko-jobs' ); ?></button>
									</div>
								</div>
								<div class="zeko-email-template-preview">
									<span class="zeko-email-template-subject"><?php esc_html_e( 'Subject:', 'zeko-jobs' ); ?> <?php echo esc_html( $tpl['subject'] ); ?></span>
									<p><?php echo esc_html( wp_trim_words( $tpl['body'], 20 ) ); ?></p>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="zeko-add-template-form" style="margin-top:16px;">
						<h4><?php esc_html_e( 'Add New Template', 'zeko-jobs' ); ?></h4>
						<form class="zeko-form zeko-email-template-form">
							<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
							<div class="zeko-field">
								<label><?php esc_html_e( 'Template Name', 'zeko-jobs' ); ?> *</label>
								<input type="text" name="template_name" required placeholder="<?php esc_attr_e( 'e.g. Follow Up', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Subject', 'zeko-jobs' ); ?> *</label>
								<input type="text" name="template_subject" required placeholder="<?php esc_attr_e( 'e.g. Following up on your application', 'zeko-jobs' ); ?>">
							</div>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Body', 'zeko-jobs' ); ?> *</label>
								<p class="description"><?php esc_html_e( 'Placeholders: {seeker_name}, {job_title}, {employer_name}, {application_date}', 'zeko-jobs' ); ?></p>
								<textarea name="template_body" rows="6" required></textarea>
							</div>
							<div class="zeko-field">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Template', 'zeko-jobs' ); ?></button>
								<span class="zeko-inline-message" id="zeko-email-template-message"></span>
							</div>
					</form>
				</div>

					<?php
					if ( Zeko_Jobs_DB::payments_enabled() ) :
						$billing = Zeko_Jobs_DB::get_billing_settings();
						?>
				<div class="zeko-dashboard-section" id="zeko-dashboard-billing" data-section="billing" style="display:none;">
					<h3><?php esc_html_e( 'Billing & Payments', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Manage your ZekoPay wallet and view transaction history.', 'zeko-jobs' ); ?></p>

					<div class="zeko-billing-overview" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
						<div class="zeko-card" style="padding:16px;">
							<h4 style="margin:0 0 4px;"><?php esc_html_e( 'Wallet Balance', 'zeko-jobs' ); ?></h4>
							<p class="zeko-billing-balance" style="font-size:28px;font-weight:700;margin:0;" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">—</p>
						</div>
						<div class="zeko-card" style="padding:16px;">
							<h4 style="margin:0 0 4px;"><?php esc_html_e( 'Posting Fee', 'zeko-jobs' ); ?></h4>
							<p style="font-size:20px;font-weight:600;margin:0;">$<?php echo esc_html( number_format( (float) $billing['posting_fee'], 2 ) ); ?></p>
						</div>
						<div class="zeko-card" style="padding:16px;">
							<h4 style="margin:0 0 4px;"><?php esc_html_e( 'Boost Fee', 'zeko-jobs' ); ?></h4>
							<p style="font-size:20px;font-weight:600;margin:0;">$<?php echo esc_html( number_format( (float) $billing['boost_fee'], 2 ) ); ?></p>
						</div>
						<div class="zeko-card" style="padding:16px;">
							<h4 style="margin:0 0 4px;"><?php esc_html_e( 'Free Postings Left', 'zeko-jobs' ); ?></h4>
							<p class="zeko-billing-free-left" style="font-size:28px;font-weight:700;margin:0;">—</p>
						</div>
					</div>

						<?php if ( (float) ( $billing['listing_pack_price'] ?? 0 ) > 0 ) : ?>
					<div class="zeko-card" style="padding:16px;margin-bottom:24px;">
						<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Listing Pack', 'zeko-jobs' ); ?></h4>
						<p style="margin:0 0 12px;color:var(--color-text-secondary);">
							<?php
							printf(
								/* translators: 1: number of jobs, 2: price */
								esc_html__( 'Get %1$d job postings for %2$s — better value than individual postings.', 'zeko-jobs' ),
								(int) $billing['listing_pack_count'],
								'$' . number_format( (float) $billing['listing_pack_price'], 2 )
							);
							?>
						</p>
						<button type="button" class="button button-primary zeko-buy-listing-pack-btn" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
							<span class="dashicons dashicons-cart" aria-hidden="true"></span> <?php esc_html_e( 'Buy Listing Pack', 'zeko-jobs' ); ?>
						</button>
						<span class="zeko-inline-message" id="zeko-pack-message"></span>
					</div>
					<?php endif; ?>

					<div class="zeko-card" style="padding:16px;">
						<h4 style="margin:0 0 12px;"><?php esc_html_e( 'Transaction History', 'zeko-jobs' ); ?></h4>
						<div id="zeko-billing-history" class="zeko-billing-history">
							<p style="color:var(--color-text-muted);"><?php esc_html_e( 'Loading...', 'zeko-jobs' ); ?></p>
						</div>
					</div>

					<a href="<?php echo esc_url( home_url( '/wallet/' ) ); ?>" class="button" target="_blank" rel="noopener" style="margin-top:16px;">
						<span class="dashicons dashicons-money" aria-hidden="true"></span> <?php esc_html_e( 'Open ZekoPay Wallet', 'zeko-jobs' ); ?>
					</a>
				</div>
					<?php endif; ?>
				</div>

				<div class="zeko-dashboard-section" id="zeko-dashboard-outreach" data-section="outreach" style="display:none;">
					<h3><?php esc_html_e( 'Candidate Outreach', 'zeko-jobs' ); ?></h3>
					<p class="zeko-section-desc"><?php esc_html_e( 'Proactively reach out to potential candidates.', 'zeko-jobs' ); ?></p>
					<?php
					$all_seekers = get_users(
						array(
							'role'   => 'subscriber',
							'fields' => 'ID',
							'number' => 50,
						)
					);
					?>
					<form class="zeko-form zeko-outreach-form" id="zeko-outreach-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $dashboard_nonce ); ?>">
						<div class="zeko-field">
							<label><?php esc_html_e( 'Candidate', 'zeko-jobs' ); ?> *</label>
							<select name="seeker_id" required>
								<option value=""><?php esc_html_e( '-- Select candidate --', 'zeko-jobs' ); ?></option>
								<?php
								foreach ( $all_seekers as $sid ) :
									$u = get_userdata( $sid );
									if ( ! $u ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $sid ); ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Related Job (optional)', 'zeko-jobs' ); ?></label>
							<select name="outreach_job_id">
								<option value=""><?php esc_html_e( '-- None --', 'zeko-jobs' ); ?></option>
								<?php
								$employer_jobs = get_posts(
									array(
										'post_type'   => 'job',
										'post_status' => 'publish',
										'author'      => $user_id,
										'numberposts' => -1,
									)
								);
								foreach ( $employer_jobs as $ej ) :
									?>
									<option value="<?php echo esc_attr( $ej->ID ); ?>"><?php echo esc_html( $ej->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="zeko-field">
							<label><?php esc_html_e( 'Message', 'zeko-jobs' ); ?> *</label>
							<textarea name="outreach_message" rows="8" required></textarea>
						</div>
						<div class="zeko-field">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Send Outreach', 'zeko-jobs' ); ?></button>
							<span class="zeko-inline-message" id="zeko-outreach-message"></span>
						</div>
					</form>
				</div>

				<!-- Bulk Email Modal -->
				<div id="zeko-bulk-email-modal" class="zeko-modal" style="display:none;" role="dialog" aria-label="<?php esc_attr_e( 'Send Email to Applicants', 'zeko-jobs' ); ?>">
					<div class="zeko-modal-overlay"></div>
					<div class="zeko-modal-dialog">
						<div class="zeko-modal-header">
							<h3><?php esc_html_e( 'Send Email to Applicants', 'zeko-jobs' ); ?></h3>
							<button type="button" class="zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
						</div>
						<div class="zeko-modal-body">
							<p class="zeko-bulk-email-count"></p>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Use Template', 'zeko-jobs' ); ?></label>
								<select class="zeko-bulk-email-template-select">
									<option value=""><?php esc_html_e( '-- Write your own --', 'zeko-jobs' ); ?></option>
									<?php foreach ( $email_templates as $tkey => $tpl ) : ?>
										<option value="<?php echo esc_attr( $tkey ); ?>" data-subject="<?php echo esc_attr( $tpl['subject'] ); ?>" data-body="<?php echo esc_textarea( $tpl['body'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Subject', 'zeko-jobs' ); ?> *</label>
								<input type="text" class="zeko-bulk-email-subject" required>
							</div>
							<div class="zeko-field">
								<label><?php esc_html_e( 'Message', 'zeko-jobs' ); ?> *</label>
								<p class="description"><?php esc_html_e( 'Placeholders: {seeker_name}, {job_title}, {employer_name}, {application_date}', 'zeko-jobs' ); ?></p>
								<textarea class="zeko-bulk-email-body" rows="8" required></textarea>
							</div>
						</div>
						<div class="zeko-modal-footer">
							<button type="button" class="button zeko-modal-cancel"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
							<button type="button" class="button button-primary zeko-bulk-email-send-btn"><?php esc_html_e( 'Send Emails', 'zeko-jobs' ); ?></button>
						</div>
					</div>
				</div>

				<!-- Application Detail Modal -->
				<div id="zeko-app-detail-modal" class="zeko-modal" style="display:none;" role="dialog" aria-label="<?php esc_attr_e( 'Application Details', 'zeko-jobs' ); ?>">
					<div class="zeko-modal-overlay"></div>
					<div class="zeko-modal-dialog">
						<div class="zeko-modal-header">
							<h3><?php esc_html_e( 'Application Details', 'zeko-jobs' ); ?></h3>
							<button type="button" class="zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
						</div>
						<div class="zeko-modal-body">
							<div class="zeko-app-detail-loading"><?php esc_html_e( 'Loading...', 'zeko-jobs' ); ?></div>
							<div class="zeko-app-detail-content" style="display:none;">
								<div class="zeko-app-detail-header">
									<h4 class="zeko-app-detail-job-title"></h4>
									<p class="zeko-app-detail-seeker"></p>
									<p class="zeko-app-detail-date"></p>
								</div>
								<div class="zeko-app-detail-section">
									<h5><?php esc_html_e( 'Cover Letter', 'zeko-jobs' ); ?></h5>
									<div class="zeko-app-detail-cover-letter"></div>
								</div>
								<div class="zeko-app-detail-section">
									<h5><?php esc_html_e( 'Status', 'zeko-jobs' ); ?></h5>
									<select class="zeko-status-select zeko-app-detail-status-select" data-nonce="<?php echo esc_attr( $dashboard_nonce ); ?>">
										<option value="awaiting_review"><?php esc_html_e( 'Awaiting Review', 'zeko-jobs' ); ?></option>
										<option value="reviewed"><?php esc_html_e( 'Reviewed', 'zeko-jobs' ); ?></option>
										<option value="contacting"><?php esc_html_e( 'Contacting', 'zeko-jobs' ); ?></option>
										<option value="interviewing"><?php esc_html_e( 'Interviewing', 'zeko-jobs' ); ?></option>
										<option value="offered"><?php esc_html_e( 'Offered', 'zeko-jobs' ); ?></option>
										<option value="hired"><?php esc_html_e( 'Hired', 'zeko-jobs' ); ?></option>
										<option value="archived"><?php esc_html_e( 'Archived', 'zeko-jobs' ); ?></option>
										<option value="rejected"><?php esc_html_e( 'Rejected', 'zeko-jobs' ); ?></option>
									</select>
								</div>
								<div class="zeko-app-detail-section">
									<h5><?php esc_html_e( 'Rating', 'zeko-jobs' ); ?></h5>
									<div class="zeko-star-rating" data-rating="0" data-application-id="">
										<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
											<span class="dashicons dashicons-star-empty zeko-star" data-value="<?php echo esc_attr( $i ); ?>"></span>
										<?php endfor; ?>
										<span class="zeko-rating-label"></span>
									</div>
								</div>
								<div class="zeko-app-detail-section">
									<h5><?php esc_html_e( 'Notes', 'zeko-jobs' ); ?></h5>
									<div class="zeko-app-notes-list"></div>
									<div class="zeko-app-add-note">
										<textarea class="zeko-note-input" rows="3" placeholder="<?php esc_attr_e( 'Add a private note...', 'zeko-jobs' ); ?>"></textarea>
										<button type="button" class="button button-small zeko-add-note-btn"><?php esc_html_e( 'Add Note', 'zeko-jobs' ); ?></button>
									</div>
								</div>
								<div class="zeko-app-detail-section">
									<h5><?php esc_html_e( 'Status History', 'zeko-jobs' ); ?></h5>
									<ul class="zeko-app-history-list"></ul>
								</div>
								<div class="zeko-app-detail-section">
									<h5><?php esc_html_e( 'Email Read Receipts', 'zeko-jobs' ); ?></h5>
									<div class="zeko-app-email-receipts" style="display:none;"></div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div id="zeko-compare-modal" class="zeko-modal" style="display:none;" role="dialog" aria-label="<?php esc_attr_e( 'Compare Candidates', 'zeko-jobs' ); ?>">
					<div class="zeko-modal-overlay"></div>
					<div class="zeko-modal-dialog" style="max-width:900px;">
						<div class="zeko-modal-header">
							<h3><?php esc_html_e( 'Compare Candidates', 'zeko-jobs' ); ?></h3>
							<button type="button" class="zeko-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
						</div>
						<div class="zeko-modal-body">
							<p class="zeko-compare-hint"><?php esc_html_e( 'Click the column icon on kanban cards to add candidates. You can compare up to 3.', 'zeko-jobs' ); ?></p>
							<div class="zeko-compare-grid" id="zeko-compare-grid"></div>
							<div class="zeko-compare-empty" id="zeko-compare-empty" style="display:none;">
								<p><?php esc_html_e( 'No candidates selected for comparison. Use the compare button on kanban cards.', 'zeko-jobs' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<?php endif; ?>
			<?php endif; ?>
		</div>
			<?php
			return ob_get_clean();
	}
}
