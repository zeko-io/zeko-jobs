<?php
/**
 * Single Job Template
 *
 * Displays a single job listing using the theme header/footer.
 * Loaded via template_redirect when a job slug is detected.
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$job = get_post();

if ( ! $job ) {
	wp_die( esc_html__( 'Job listing not found.', 'zeko-jobs' ) );
}

$zeko_db  = Zeko_Jobs_DB::get_instance();
$job_data = $zeko_db->get_published_job( $job->ID );

if ( ! $job_data ) {
	wp_die( esc_html__( 'Job listing not found.', 'zeko-jobs' ) );
}

$location             = $job_data['location'] ?? '';
$job_type             = $job_data['type'] ?? '';
$salary_min           = isset( $job_data['salary_min'] ) ? (float) $job_data['salary_min'] : 0.00;
$salary_max           = isset( $job_data['salary_max'] ) ? (float) $job_data['salary_max'] : 0.00;
$experience_level     = $job_data['experience_level'] ?? '';
$remote_option        = $job_data['remote_option'] ?? '';
$application_deadline = $job_data['application_deadline'] ?? '';
$employer_id          = (int) $job_data['employer_id'];
$company_logo_id      = isset( $job_data['company_logo_id'] ) ? (int) $job_data['company_logo_id'] : 0;
$company_size         = $job_data['company_size'] ?? '';
$company_industry     = $job_data['company_industry'] ?? '';
$company_about        = $job_data['company_about'] ?? '';
$company_website      = $job_data['company_website'] ?? '';
$category             = $job_data['category'] ?? '';
$is_easy_apply        = isset( $job_data['is_easy_apply'] ) ? (int) $job_data['is_easy_apply'] : 0;
$requirements         = $job_data['requirements'] ?? '';
$benefits             = $job_data['benefits'] ?? '';
$responsibilities     = $job_data['responsibilities'] ?? '';
$qualifications       = $job_data['qualifications'] ?? '';

$company_name = __( 'N/A', 'zeko-jobs' );
if ( $employer_id > 0 ) {
	$user_data = get_userdata( $employer_id );
	if ( $user_data ) {
		$company_name = $user_data->display_name;
	}
}

$views     = isset( $job_data['views'] ) ? (int) $job_data['views'] : 0;
$app_count = $zeko_db->count_applications( $job->ID );

$current_user_id = get_current_user_id();
$is_bookmarked   = false;
$already_applied = false;

if ( is_user_logged_in() ) {
	$bookmarks = get_user_meta( $current_user_id, 'zeko_bookmarked_jobs', true );
	if ( is_array( $bookmarks ) && in_array( $job->ID, $bookmarks, true ) ) {
		$is_bookmarked = true;
	}
	$already_applied = $zeko_db->has_applied( $job->ID, $current_user_id );
}

$days_until_deadline = 0;
if ( $application_deadline ) {
	$days_until_deadline = ceil( ( strtotime( $application_deadline ) - time() ) / DAY_IN_SECONDS );
}

get_header();
/** Emit employer branding for licensed sites (Zeko PRO jobs_ats module). */
do_action( 'zeko_jobs_employer_brand', $employer_id );
?>

<div class="zeko-single-job">
	<nav class="zeko-breadcrumb" aria-label="<?php esc_attr_e( 'Job breadcrumb', 'zeko-jobs' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'zeko-jobs' ); ?></a>
		<span class="zeko-breadcrumb-sep" aria-hidden="true">/</span>
		<a href="<?php echo esc_url( home_url( '/jobs/' ) ); ?>"><?php esc_html_e( 'Jobs', 'zeko-jobs' ); ?></a>
		<?php if ( $category ) : ?>
			<span class="zeko-breadcrumb-sep" aria-hidden="true">/</span>
			<a href="<?php echo esc_url( add_query_arg( 'job_category', $category, home_url( '/jobs/' ) ) ); ?>"><?php echo esc_html( ucfirst( $category ) ); ?></a>
		<?php endif; ?>
		<span class="zeko-breadcrumb-sep" aria-hidden="true">/</span>
		<span class="zeko-breadcrumb-current" aria-current="page"><?php echo esc_html( get_the_title() ); ?></span>
	</nav>

	<div class="zeko-single-job-layout">
		<article class="zeko-single-job-main">
			<header class="zeko-single-job-header">
				<h1 class="zeko-single-job-title"><?php echo esc_html( get_the_title() ); ?></h1>

				<div class="zeko-single-job-meta">
					<?php if ( $company_name ) : ?>
						<span class="zeko-single-job-meta-item">
							<span class="dashicons dashicons-building" aria-hidden="true"></span>
							<?php echo esc_html( $company_name ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $location ) : ?>
						<span class="zeko-single-job-meta-item">
							<span class="dashicons dashicons-location" aria-hidden="true"></span>
							<?php echo esc_html( $location ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $job_type ) : ?>
						<span class="zeko-single-job-meta-item">
							<span class="dashicons dashicons-tag" aria-hidden="true"></span>
							<?php echo esc_html( ucfirst( str_replace( '-', ' ', $job_type ) ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $category ) : ?>
						<span class="zeko-job-badge zeko-job-badge-category">
							<?php echo esc_html( ucfirst( $category ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $experience_level ) : ?>
						<span class="zeko-job-badge zeko-job-badge-experience">
							<?php echo esc_html( $experience_level ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $remote_option ) : ?>
						<span class="zeko-job-badge zeko-job-badge-remote zeko-job-badge-<?php echo esc_attr( sanitize_title( $remote_option ) ); ?>">
							<?php echo esc_html( $remote_option ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $salary_min > 0 || $salary_max > 0 ) : ?>
						<span class="zeko-single-job-meta-item zeko-salary-range">
							<span class="dashicons dashicons-money" aria-hidden="true"></span>
							<?php if ( $salary_min > 0 && $salary_max > 0 ) : ?>
								$<?php echo esc_html( number_format( $salary_min ) ); ?> &ndash; $<?php echo esc_html( number_format( $salary_max ) ); ?>
							<?php elseif ( $salary_min > 0 ) : ?>
								<?php esc_html_e( 'From', 'zeko-jobs' ); ?> $<?php echo esc_html( number_format( $salary_min ) ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Up to', 'zeko-jobs' ); ?> $<?php echo esc_html( number_format( $salary_max ) ); ?>
							<?php endif; ?>
							<?php if ( $salary_min > 0 || $salary_max > 0 ) : ?>
								<span class="zeko-pay-transparency-badge"><?php esc_html_e( 'Pay Transparency', 'zeko-jobs' ); ?></span>
							<?php endif; ?>
						</span>
					<?php endif; ?>
					<?php if ( $application_deadline ) : ?>
						<span class="zeko-single-job-meta-item zeko-deadline-countdown" data-deadline="<?php echo esc_attr( $application_deadline ); ?>">
							<span class="dashicons dashicons-calendar" aria-hidden="true"></span>
							<?php esc_html_e( 'Closes:', 'zeko-jobs' ); ?> <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $application_deadline ) ) ); ?>
							<span class="zeko-countdown-text"></span>
						</span>
					<?php endif; ?>
					<span class="zeko-single-job-meta-item zeko-single-job-date">
						<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
						<?php echo esc_html( human_time_diff( strtotime( $job_data['created_at'] ?? $job->post_date ), time() ) ); ?> <?php esc_html_e( 'ago', 'zeko-jobs' ); ?>
					</span>
					<span class="zeko-single-job-meta-item zeko-single-job-views">
						<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: %s: view count */
							esc_html( _n( '%s view', '%s views', $views, 'zeko-jobs' ) ),
							esc_html( number_format_i18n( $views ) )
						);
						?>
					</span>
					<span class="zeko-single-job-meta-item zeko-single-job-applications">
						<span class="dashicons dashicons-groups" aria-hidden="true"></span>
						<?php echo esc_html( $app_count ); ?> <?php esc_html_e( 'applications', 'zeko-jobs' ); ?>
					</span>
				</div>
			</header>

			<?php
			$urgency_class = '';
			if ( $application_deadline && $days_until_deadline > 0 ) {
				if ( $days_until_deadline <= 3 ) {
					$urgency_class = ' zeko-insights-urgent';
				} elseif ( $days_until_deadline <= 7 ) {
					$urgency_class = ' zeko-insights-soon';
				}
			}
			?>

			<div class="zeko-job-insights<?php echo esc_attr( $urgency_class ); ?>">
				<?php if ( $salary_min > 0 || $salary_max > 0 ) : ?>
					<div class="zeko-insight-item">
						<div class="zeko-insight-value">
							<?php if ( $salary_min > 0 && $salary_max > 0 ) : ?>
								$<?php echo esc_html( number_format( $salary_min ) ); ?>k – $<?php echo esc_html( number_format( $salary_max ) ); ?>k
							<?php else : ?>
								$<?php echo esc_html( number_format( $salary_min ? $salary_min : $salary_max ) ); ?>k
							<?php endif; ?>
						</div>
						<div class="zeko-insight-label"><?php esc_html_e( 'Salary Range', 'zeko-jobs' ); ?></div>
					</div>
				<?php endif; ?>
				<div class="zeko-insight-item">
					<div class="zeko-insight-value"><?php echo esc_html( ucfirst( str_replace( '-', ' ', $job_type ) ) ); ?></div>
					<div class="zeko-insight-label"><?php esc_html_e( 'Job Type', 'zeko-jobs' ); ?></div>
				</div>
				<?php if ( $remote_option ) : ?>
					<div class="zeko-insight-item">
						<div class="zeko-insight-value"><?php echo esc_html( $remote_option ); ?></div>
						<div class="zeko-insight-label"><?php esc_html_e( 'Work Model', 'zeko-jobs' ); ?></div>
					</div>
				<?php endif; ?>
				<div class="zeko-insight-item">
					<div class="zeko-insight-value"><?php echo esc_html( $app_count ); ?></div>
					<div class="zeko-insight-label"><?php esc_html_e( 'Applications', 'zeko-jobs' ); ?></div>
				</div>
				<?php if ( $application_deadline && $days_until_deadline > 0 ) : ?>
					<div class="zeko-insight-item">
						<div class="zeko-insight-value"><?php echo esc_html( $days_until_deadline ); ?></div>
						<div class="zeko-insight-label"><?php esc_html_e( 'Days Left', 'zeko-jobs' ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<?php
			$show_match = is_user_logged_in() && $current_user_id !== $employer_id;
			if ( $show_match ) :
				$match   = $zeko_db->calculate_profile_match( $current_user_id, $job_data );
				$m_score = $match['score'];
				$bd      = $match['breakdown'];
				if ( $m_score >= 75 ) {
					$m_class = 'zeko-match-high';
					$m_msg   = __( 'Great match! Your profile aligns well with this role.', 'zeko-jobs' );
				} elseif ( $m_score >= 50 ) {
					$m_class = 'zeko-match-mid';
					$m_msg   = __( 'Good match. Some areas may need attention.', 'zeko-jobs' );
				} else {
					$m_class = 'zeko-match-low';
					$m_msg   = __( 'Lower match. Consider upskilling to better fit this role.', 'zeko-jobs' );
				}
				?>
			<div class="zeko-profile-match <?php echo esc_attr( $m_class ); ?>">
				<div class="zeko-match-header">
					<div class="zeko-match-score-circle">
						<span class="zeko-match-score-num"><?php echo esc_html( $m_score ); ?></span><span class="zeko-match-score-pct">%</span>
						<span class="zeko-match-score-label"><?php esc_html_e( 'Match', 'zeko-jobs' ); ?></span>
					</div>
					<div class="zeko-match-msg"><?php echo esc_html( $m_msg ); ?></div>
				</div>
				<div class="zeko-match-breakdown">
					<?php
					$labels = array(
						'skills'     => __( 'Skills', 'zeko-jobs' ),
						'experience' => __( 'Experience', 'zeko-jobs' ),
						'type'       => __( 'Job Type', 'zeko-jobs' ),
						'location'   => __( 'Location', 'zeko-jobs' ),
						'education'  => __( 'Education', 'zeko-jobs' ),
					);
					foreach ( $labels as $key => $label ) :
						$pct = $bd[ $key ]['pct'] ?? 0;
						?>
						<div class="zeko-match-bar-row">
							<span class="zeko-match-bar-label"><?php echo esc_html( $label ); ?></span>
							<div class="zeko-match-bar-track"><div class="zeko-match-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div></div>
							<span class="zeko-match-bar-pct"><?php echo esc_html( $pct ); ?>%</span>
						</div>
					<?php endforeach; ?>
					<?php if ( ! empty( $bd['skills']['matched'] ) ) : ?>
						<div class="zeko-match-skills-list">
							<strong><?php esc_html_e( 'Matched skills:', 'zeko-jobs' ); ?></strong>
							<?php foreach ( $bd['skills']['matched'] as $ms ) : ?>
								<span class="zeko-match-skill-tag"><?php echo esc_html( $ms ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

				<?php
				$best_time = $zeko_db->get_best_time_to_apply();
				if ( ! empty( $best_time['message'] ) ) :
					?>
			<div class="zeko-best-time-apply" style="background:var(--color-bg-muted,#f8f9fa);border:1px solid var(--color-border,#e2e8f0);border-radius:8px;padding:12px 16px;margin:12px 0;font-size:14px;">
				<span class="dashicons dashicons-clock" style="color:var(--color-primary,#1f618d);margin-right:6px;" aria-hidden="true"></span>
					<?php echo esc_html( $best_time['message'] ); ?>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<?php
			$salary_insights = $zeko_db->get_salary_insights( $job_data['category'] ?? '', $job_data['location'] ?? '' );
			$has_insights    = false;
			foreach ( $salary_insights as $insight ) {
				if ( $insight && (float) ( $insight['avg_salary'] ?? 0 ) > 0 ) {
					$has_insights = true;
					break;
				}
			}
			if ( $has_insights ) :
				?>
			<div class="zeko-salary-insights">
				<h3 class="zeko-salary-insights-title"><?php esc_html_e( 'Salary Insights', 'zeko-jobs' ); ?></h3>
				<?php
				$insight_rows = array();
				if ( ! empty( $salary_insights['category']['avg_salary'] ) && (int) $salary_insights['category']['sample_count'] >= 2 ) {
					$insight_rows[] = array(
						'label' => __( 'Same Category', 'zeko-jobs' ),
						'avg'   => $salary_insights['category']['avg_salary'],
						'min'   => $salary_insights['category']['min_salary'],
						'max'   => $salary_insights['category']['max_salary'],
						'count' => $salary_insights['category']['sample_count'],
					);
				}
				if ( ! empty( $salary_insights['location']['avg_salary'] ) && (int) $salary_insights['location']['sample_count'] >= 2 ) {
					$insight_rows[] = array(
						'label' => __( 'Same Location', 'zeko-jobs' ),
						'avg'   => $salary_insights['location']['avg_salary'],
						'min'   => $salary_insights['location']['min_salary'],
						'max'   => $salary_insights['location']['max_salary'],
						'count' => $salary_insights['location']['sample_count'],
					);
				}
				if ( ! empty( $salary_insights['overall']['avg_salary'] ) && (int) $salary_insights['overall']['sample_count'] >= 2 ) {
					$insight_rows[] = array(
						'label' => __( 'All Jobs', 'zeko-jobs' ),
						'avg'   => $salary_insights['overall']['avg_salary'],
						'min'   => $salary_insights['overall']['min_salary'],
						'max'   => $salary_insights['overall']['max_salary'],
						'count' => $salary_insights['overall']['sample_count'],
					);
				}
				if ( ! empty( $insight_rows ) ) :
					?>
				<div class="zeko-salary-insights-list">
					<?php
					foreach ( $insight_rows as $row ) :
						$this_avg  = (float) $row['avg'];
						$this_min  = (float) $row['min'];
						$this_max  = (float) $row['max'];
						$bar_left  = ( $salary_min > 0 && $this_max > $this_min ) ? max( 0, ( ( $salary_min - $this_min ) / ( $this_max - $this_min ) ) * 100 ) : 0;
						$bar_width = ( $salary_max > 0 && $this_max > $this_min ) ? min( 100 - $bar_left, ( ( $salary_max - $salary_min ) / ( $this_max - $this_min ) ) * 100 ) : 10;
						?>
						<div class="zeko-salary-insight-row">
							<div class="zeko-salary-insight-header">
								<span class="zeko-salary-insight-label"><?php echo esc_html( $row['label'] ); ?></span>
								<span class="zeko-salary-insight-avg"><?php echo esc_html( '$' . number_format( $this_avg ) ); ?> <?php esc_html_e( 'avg', 'zeko-jobs' ); ?></span>
							</div>
							<div class="zeko-salary-insight-bar">
								<div class="zeko-salary-insight-range" style="left:<?php echo esc_attr( $bar_left ); ?>%;width:<?php echo esc_attr( max( $bar_width, 4 ) ); ?>%"></div>
								<div class="zeko-salary-insight-endpoints">
									<span>$<?php echo esc_html( number_format( $this_min ) ); ?></span>
									<span>$<?php echo esc_html( number_format( $this_max ) ); ?></span>
								</div>
							</div>
							<span class="zeko-salary-insight-count"><?php /* translators: %d: number of jobs */ printf( esc_html__( '%d jobs', 'zeko-jobs' ), (int) $row['count'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="zeko-single-job-content">
				<?php if ( $job_data['description'] ) : ?>
					<section class="zeko-job-detail-section zeko-job-description-section">
						<h2><?php esc_html_e( 'About this job', 'zeko-jobs' ); ?></h2>
						<div class="zeko-job-detail-content"><?php echo wp_kses_post( wpautop( $job_data['description'] ) ); ?></div>
					</section>
				<?php endif; ?>

				<?php
				$detail_sections = array();
				if ( $responsibilities ) {
					$detail_sections[] = array(
						'title'   => __( 'Responsibilities', 'zeko-jobs' ),
						'content' => $responsibilities,
					);
				}
				if ( $requirements ) {
					$detail_sections[] = array(
						'title'   => __( 'Requirements', 'zeko-jobs' ),
						'content' => $requirements,
					);
				}
				if ( $qualifications ) {
					$detail_sections[] = array(
						'title'   => __( 'Qualifications', 'zeko-jobs' ),
						'content' => $qualifications,
					);
				}
				if ( $benefits ) {
					$detail_sections[] = array(
						'title'   => __( 'Benefits', 'zeko-jobs' ),
						'content' => $benefits,
					);
				}
				?>

				<?php if ( ! empty( $detail_sections ) ) : ?>
					<div class="zeko-job-details-sections">
						<?php foreach ( $detail_sections as $section ) : ?>
							<section class="zeko-job-detail-section">
								<h2><?php echo esc_html( $section['title'] ); ?></h2>
								<div class="zeko-job-detail-content"><?php echo wp_kses_post( wpautop( $section['content'] ) ); ?></div>
							</section>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php
				$meta_items = array();
				if ( $experience_level ) {
					$meta_items[] = array( __( 'Experience Level', 'zeko-jobs' ), $experience_level );
				}
				if ( $remote_option ) {
					$meta_items[] = array( __( 'Work Model', 'zeko-jobs' ), $remote_option );
				}
				if ( $company_size ) {
					$meta_items[] = array( __( 'Company Size', 'zeko-jobs' ), $company_size );
				}
				if ( $company_industry ) {
					$meta_items[] = array( __( 'Industry', 'zeko-jobs' ), $company_industry );
				}
				if ( $application_deadline ) {
					$meta_items[] = array( __( 'Application Deadline', 'zeko-jobs' ), date_i18n( 'F j, Y', strtotime( $application_deadline ) ) );
				}
				if ( $category ) {
					$meta_items[] = array( __( 'Category', 'zeko-jobs' ), ucfirst( $category ) );
				}
				?>

				<?php if ( ! empty( $meta_items ) ) : ?>
					<section class="zeko-job-detail-section zeko-job-detail-meta">
						<h2><?php esc_html_e( 'Job Details', 'zeko-jobs' ); ?></h2>
						<div class="zeko-job-detail-content">
							<ul class="zeko-job-meta-list">
								<?php foreach ( $meta_items as $item ) : ?>
									<li>
										<strong><?php echo esc_html( $item[0] ); ?></strong>
										<span><?php echo esc_html( $item[1] ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</section>
				<?php endif; ?>

				<?php
				$zeko_db_reviews = Zeko_Jobs_DB::get_instance();
				$job_reviews     = $zeko_db_reviews->get_reviews( $job_data['id'] );
				$review_avg      = $zeko_db_reviews->get_average_rating( $job_data['id'] );
				$avg_rating      = round( (float) ( $review_avg['avg_rating'] ?? 0 ), 1 );
				$review_count    = (int) ( $review_avg['review_count'] ?? 0 );
				?>
				<section class="zeko-job-reviews" id="zeko-job-reviews">
					<div class="zeko-reviews-header">
						<h2><?php esc_html_e( 'Reviews', 'zeko-jobs' ); ?></h2>
						<?php if ( $review_count > 0 ) : ?>
							<div class="zeko-reviews-summary">
								<span class="zeko-reviews-avg"><?php echo esc_html( $avg_rating ); ?></span>
								<div class="zeko-reviews-stars" data-rating="<?php echo esc_attr( $avg_rating ); ?>">
									<?php for ( $search_term = 1; $search_term <= 5; $search_term++ ) : ?>
										<span class="dashicons dashicons-star<?php echo $search_term <= $avg_rating ? '' : '-empty'; ?>" aria-hidden="true"></span>
									<?php endfor; ?>
								</div>
								<span class="zeko-reviews-count"><?php /* translators: %d: number of reviews */ printf( esc_html( _n( '(%d review)', '(%d reviews)', $review_count, 'zeko-jobs' ) ), absint( $review_count ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( is_user_logged_in() ) : ?>
						<form id="zeko-review-form" class="zeko-review-form zeko-form">
							<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_data['id'] ); ?>">
							<input type="hidden" name="zeko_job_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zeko_job_apply' ) ); ?>">
							<div class="zeko-star-rating-input" role="radiogroup" aria-label="<?php esc_attr_e( 'Rating', 'zeko-jobs' ); ?>">
								<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
									<button type="button" class="zeko-star-btn" data-value="<?php echo esc_attr( $i ); ?>" aria-label="<?php /* translators: %d: star rating number */ printf( esc_attr__( '%d star', 'zeko-jobs' ), absint( $i ) ); ?>">
										<span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
									</button>
								<?php endfor; ?>
								<input type="hidden" name="rating" value="0">
							</div>
							<textarea name="review_text" rows="3" placeholder="<?php esc_attr_e( 'Share your experience (optional)...', 'zeko-jobs' ); ?>"></textarea>
							<button type="submit" class="button"><?php esc_html_e( 'Submit Review', 'zeko-jobs' ); ?></button>
							<div class="zeko-review-status"></div>
						</form>
					<?php endif; ?>

					<div class="zeko-reviews-list">
						<?php if ( empty( $job_reviews ) ) : ?>
							<p class="zeko-reviews-empty"><?php esc_html_e( 'No reviews yet. Be the first to share your experience!', 'zeko-jobs' ); ?></p>
						<?php else : ?>
							<?php foreach ( $job_reviews as $review ) : ?>
								<div class="zeko-review-card">
									<div class="zeko-review-header">
										<span class="zeko-review-author"><?php echo esc_html( $review['reviewer_name'] ?? __( 'Anonymous', 'zeko-jobs' ) ); ?></span>
										<div class="zeko-reviews-stars" data-rating="<?php echo esc_attr( $review['rating'] ); ?>">
											<?php for ( $r = 1; $r <= 5; $r++ ) : ?>
												<span class="dashicons dashicons-star<?php echo $r <= $review['rating'] ? '' : '-empty'; ?>" aria-hidden="true"></span>
											<?php endfor; ?>
										</div>
										<time class="zeko-review-date" datetime="<?php echo esc_attr( $review['created_at'] ); ?>"><?php echo esc_html( human_time_diff( strtotime( $review['created_at'] ), time() ) . ' ago' ); ?></time>
									</div>
									<?php if ( ! empty( $review['review_text'] ) ) : ?>
										<p class="zeko-review-text"><?php echo esc_html( $review['review_text'] ); ?></p>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</section>

				<?php
				// ── Required-skills match + course recommendations (seekers only) ──.
				$zeko_db_ecosystem = Zeko_Jobs_DB::get_instance();
				if ( is_user_logged_in() && (int) $current_user_id !== (int) $employer_id ) :
					$skills_match = $zeko_db_ecosystem->get_skills_match( $current_user_id, $job_data );
					if ( $skills_match['total'] > 0 ) :
						$match_pct = (int) round( ( count( $skills_match['matched'] ) / $skills_match['total'] ) * 100 );
						?>
						<section class="zeko-job-skills-match" id="zeko-job-skills-match">
							<h2>
								<?php esc_html_e( 'Your Skills Match', 'zeko-jobs' ); ?>
								<span class="zeko-badge"><?php echo esc_html( count( $skills_match['matched'] ) . ' / ' . $skills_match['total'] ); ?></span>
							</h2>
							<div class="zeko-skills-match-bar">
								<div class="zeko-skills-match-fill" style="width:<?php echo esc_attr( $match_pct ); ?>%;"></div>
							</div>
							<?php if ( ! empty( $skills_match['matched'] ) ) : ?>
								<p><strong><?php esc_html_e( 'You have:', 'zeko-jobs' ); ?></strong>
									<?php foreach ( $skills_match['matched'] as $skill ) : ?>
										<span class="zeko-skill-tag zeko-skill-tag-matched"><?php echo esc_html( $skill ); ?></span>
									<?php endforeach; ?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $skills_match['missing'] ) ) : ?>
								<p><strong><?php esc_html_e( 'Consider adding:', 'zeko-jobs' ); ?></strong>
									<?php foreach ( $skills_match['missing'] as $skill ) : ?>
										<span class="zeko-skill-tag zeko-skill-tag-missing"><?php echo esc_html( $skill ); ?></span>
									<?php endforeach; ?>
								</p>
								<?php
								$skill_gap_courses = $zeko_db_ecosystem->recommend_courses_for_job( $current_user_id, $job_data, 3 );
								if ( ! empty( $skill_gap_courses ) ) :
									?>
									<div class="zeko-skill-gap-courses">
										<h3><?php esc_html_e( 'Close the gap with these courses', 'zeko-jobs' ); ?></h3>
										<ul>
											<?php foreach ( $skill_gap_courses as $gap ) : ?>
												<li>
													<a href="<?php echo esc_url( home_url( '/courses/' . $gap['course']['slug'] . '/' ) ); ?>" target="_blank" rel="noopener">
														<?php echo esc_html( $gap['course']['title'] ); ?>
													</a>
													<span class="zeko-skill-gap-covers"><?php echo esc_html( implode( ', ', $gap['covers'] ) ); ?></span>
												</li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</section>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				// ── Related Q&A + industry insights (Zeko-QA, fail-soft) ──.
				$related_questions = $zeko_db_ecosystem->get_related_qa_questions( $job_data, 4 );
				$industry_insights = $zeko_db_ecosystem->get_industry_insights( $job_data, 3 );
				if ( ! empty( $related_questions ) || ! empty( $industry_insights ) ) :
					?>
					<section class="zeko-job-qa" id="zeko-job-qa">
						<h2><?php esc_html_e( 'Community Q&A', 'zeko-jobs' ); ?></h2>
						<?php if ( ! empty( $related_questions ) ) : ?>
							<h3 class="zeko-qa-subtitle"><?php esc_html_e( 'Related discussions', 'zeko-jobs' ); ?></h3>
							<ul class="zeko-qa-list">
								<?php foreach ( $related_questions as $q ) : ?>
									<li>
										<a href="<?php echo esc_url( home_url( '/questions/' . $q['slug'] . '/' ) ); ?>" target="_blank" rel="noopener">
											<?php echo esc_html( $q['title'] ); ?>
										</a>
										<?php if ( ! empty( $q['answer_count'] ) ) : ?>
											<span class="zeko-qa-answers"><?php /* translators: %d: number of answers */ printf( esc_html( _n( '%d answer', '%d answers', (int) $q['answer_count'], 'zeko-jobs' ) ), (int) $q['answer_count'] ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( ! empty( $industry_insights ) ) : ?>
							<h3 class="zeko-qa-subtitle"><?php esc_html_e( 'Industry insights', 'zeko-jobs' ); ?></h3>
							<ul class="zeko-qa-list">
								<?php foreach ( $industry_insights as $q ) : ?>
									<li>
										<a href="<?php echo esc_url( home_url( '/questions/' . $q['slug'] . '/' ) ); ?>" target="_blank" rel="noopener">
											<?php echo esc_html( $q['title'] ); ?>
										</a>
										<?php if ( ! empty( $q['answer_count'] ) ) : ?>
											<span class="zeko-qa-answers"><?php /* translators: %d: number of answers */ printf( esc_html( _n( '%d answer', '%d answers', (int) $q['answer_count'], 'zeko-jobs' ) ), (int) $q['answer_count'] ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>
		</article>

		<aside class="zeko-single-job-sidebar">
			<?php if ( is_user_logged_in() ) : ?>
				<div class="zeko-single-job-actions-card">
					<?php if ( $already_applied ) : ?>
						<div class="zeko-already-applied">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<p><?php esc_html_e( 'You have already applied for this position.', 'zeko-jobs' ); ?></p>
						</div>
					<?php else : ?>

						<h2 class="zeko-single-job-sidebar-title"><?php esc_html_e( 'Apply for this Position', 'zeko-jobs' ); ?></h2>

						<form id="zeko-job-apply-form" class="zeko-job-apply-form zeko-form" method="post" enctype="multipart/form-data">
							<input type="hidden" name="job_id" value="<?php echo esc_attr( $job->ID ); ?>">
							<input type="hidden" name="zeko_job_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zeko_job_apply' ) ); ?>">

							<div class="zeko-field">
								<label for="single-cover-letter"><?php esc_html_e( 'Cover Letter', 'zeko-jobs' ); ?></label>
								<textarea id="single-cover-letter" name="cover_letter" rows="5" required placeholder="<?php esc_attr_e( 'Tell the employer why you are a great fit...', 'zeko-jobs' ); ?>"></textarea>
								<div class="zeko-char-count">0 / 2000</div>
							</div>

							<div class="zeko-field">
								<label for="single-resume"><?php esc_html_e( 'Resume (PDF or DOC/DOCX)', 'zeko-jobs' ); ?></label>
								<div class="zeko-file-upload" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload resume file', 'zeko-jobs' ); ?>">
									<span class="dashicons dashicons-upload" aria-hidden="true"></span>
									<span class="zeko-file-upload-text"><?php esc_html_e( 'Click to upload or drag & drop', 'zeko-jobs' ); ?></span>
									<span class="zeko-file-upload-hint"><?php esc_html_e( 'PDF, DOC, or DOCX — max 5MB', 'zeko-jobs' ); ?></span>
									<input type="file" id="single-resume" name="resume" accept=".pdf,.doc,.docx,application/pdf,.docx" required class="zeko-file-input">
								</div>
								<div class="zeko-file-preview" style="display:none;">
									<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
									<span class="zeko-file-name"></span>
									<span class="zeko-file-size"></span>
									<button type="button" class="zeko-file-remove" aria-label="<?php esc_attr_e( 'Remove file', 'zeko-jobs' ); ?>">&times;</button>
								</div>
							</div>

							<div class="zeko-upload-progress" style="display:none;">
								<div class="zeko-progress-bar"><div class="zeko-progress-fill"></div></div>
								<span class="zeko-progress-text">0%</span>
							</div>

							<div class="zeko-field zeko-submit">
								<button type="submit" class="button button-primary" <?php echo $already_applied ? 'disabled' : ''; ?>>
									<?php esc_html_e( 'Submit Application', 'zeko-jobs' ); ?>
								</button>
							</div>
							<div class="zeko-application-message zeko-inline-message"></div>
						</form>

					<?php endif; ?>

				<?php else : ?>
					<h2 class="zeko-single-job-sidebar-title"><?php esc_html_e( 'Interested in this job?', 'zeko-jobs' ); ?></h2>
					<p class="login-prompt">
						<?php
						printf(
							/* translators: %s: login URL */
							esc_html__( 'Please %1$slog in%2$search_term to apply for this position.', 'zeko-jobs' ),
							'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">',
							'</a>'
						);
						?>
					</p>
				<?php endif; ?>

				<div class="zeko-single-job-share">
					<h3><?php esc_html_e( 'Share this job', 'zeko-jobs' ); ?></h3>
					<div class="zeko-single-job-share-links">
						<a
							href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_url( get_permalink() ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="zeko-single-job-share-link"
							aria-label="<?php esc_attr_e( 'Share on Facebook', 'zeko-jobs' ); ?>"
						>
							<span class="dashicons dashicons-facebook" aria-hidden="true"></span>
							<?php esc_html_e( 'Facebook', 'zeko-jobs' ); ?>
						</a>
						<a
							href="https://twitter.com/intent/tweet?url=<?php echo esc_url( get_permalink() ); ?>&text=<?php echo esc_attr( get_the_title() ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="zeko-single-job-share-link"
							aria-label="<?php esc_attr_e( 'Share on Twitter', 'zeko-jobs' ); ?>"
						>
							<span class="dashicons dashicons-twitter" aria-hidden="true"></span>
							<?php esc_html_e( 'Twitter', 'zeko-jobs' ); ?>
						</a>
						<a
							href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo esc_url( get_permalink() ); ?>&title=<?php echo esc_attr( get_the_title() ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="zeko-single-job-share-link"
							aria-label="<?php esc_attr_e( 'Share on LinkedIn', 'zeko-jobs' ); ?>"
						>
							<span class="dashicons dashicons-linkedin" aria-hidden="true"></span>
							<?php esc_html_e( 'LinkedIn', 'zeko-jobs' ); ?>
						</a>
						<button type="button" class="zeko-single-job-share-link zeko-copy-link-btn" data-url="<?php echo esc_url( get_permalink() ); ?>" aria-label="<?php esc_attr_e( 'Copy link', 'zeko-jobs' ); ?>">
							<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
							<?php esc_html_e( 'Copy Link', 'zeko-jobs' ); ?>
						</button>
						<a
							href="mailto:?subject=<?php /* translators: %s: job title */ echo esc_attr( sprintf( __( 'Job Opportunity: %s', 'zeko-jobs' ), get_the_title() ) ); ?>&body=<?php echo esc_url( get_permalink() ); ?>"
							class="zeko-single-job-share-link"
							aria-label="<?php esc_attr_e( 'Share via Email', 'zeko-jobs' ); ?>"
						>
							<span class="dashicons dashicons-email" aria-hidden="true"></span>
							<?php esc_html_e( 'Email', 'zeko-jobs' ); ?>
						</a>
					</div>
				</div>
				</div>

				<?php if ( is_user_logged_in() ) : ?>
					<div class="zeko-single-job-secondary-actions">
						<?php if ( $is_easy_apply && ! $already_applied ) : ?>
							<button type="button" class="button button-primary zeko-easy-apply-btn" data-job-id="<?php echo esc_attr( $job->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_easy_apply' ) ); ?>">
								<?php esc_html_e( 'Easy Apply', 'zeko-jobs' ); ?>
							</button>
						<?php endif; ?>
						<button
							type="button"
							class="button zeko-bookmark-btn<?php echo $is_bookmarked ? ' bookmarked' : ''; ?>"
							data-job-id="<?php echo esc_attr( $job->ID ); ?>"
							aria-label="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'zeko-jobs' ) : esc_attr__( 'Bookmark this job', 'zeko-jobs' ); ?>"
						>
							<?php echo $is_bookmarked ? esc_html__( 'Bookmarked', 'zeko-jobs' ) : esc_html__( 'Bookmark Job', 'zeko-jobs' ); ?>
						</button>

						<?php if ( $current_user_id !== $employer_id ) : ?>
							<button
								type="button"
								class="button button-secondary zeko-message-employer-btn"
								data-employer-id="<?php echo esc_attr( $employer_id ); ?>"
								data-job-title="<?php echo esc_attr( get_the_title() ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_message_employer' ) ); ?>"
								aria-label="<?php esc_attr__( 'Message the employer', 'zeko-jobs' ); ?>"
							>
								<?php esc_html_e( 'Message Employer', 'zeko-jobs' ); ?>
							</button>
						<?php endif; ?>

						<button type="button" class="button zeko-not-interested-btn" data-job-id="<?php echo esc_attr( $job->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_not_interested' ) ); ?>">
							<?php esc_html_e( 'Not Interested', 'zeko-jobs' ); ?>
						</button>

						<button type="button" class="button zeko-report-job-btn" data-job-id="<?php echo esc_attr( $job->ID ); ?>" aria-label="<?php esc_attr_e( 'Report this listing', 'zeko-jobs' ); ?>">
							<?php esc_html_e( 'Report', 'zeko-jobs' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $company_logo_id || $company_name || $company_size || $company_industry || $company_about || $company_website ) : ?>
				<div class="zeko-single-job-company-card">
					<h3><?php esc_html_e( 'About the Company', 'zeko-jobs' ); ?></h3>
					<div class="zeko-company-info">
						<?php if ( $company_logo_id ) : ?>
							<div class="zeko-company-logo">
								<?php echo wp_get_attachment_image( $company_logo_id, 'thumbnail', false, array( 'alt' => esc_attr( $company_name ) ) ); ?>
							</div>
						<?php endif; ?>
						<div class="zeko-company-details">
							<h4><?php echo esc_html( $company_name ); ?></h4>
							<?php if ( $company_industry ) : ?>
								<p><strong><?php esc_html_e( 'Industry:', 'zeko-jobs' ); ?></strong> <?php echo esc_html( $company_industry ); ?></p>
							<?php endif; ?>
							<?php if ( $company_size ) : ?>
								<p><strong><?php esc_html_e( 'Company Size:', 'zeko-jobs' ); ?></strong> <?php echo esc_html( $company_size ); ?></p>
							<?php endif; ?>
							<?php if ( $company_about ) : ?>
								<p><?php echo wp_kses_post( wpautop( $company_about ) ); ?></p>
							<?php endif; ?>
							<?php if ( $company_website ) : ?>
								<p><a href="<?php echo esc_url( $company_website ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit website', 'zeko-jobs' ); ?></a></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</aside>
	</div>

	<?php
	$zeko_db      = Zeko_Jobs_DB::get_instance();
	$similar_jobs = $zeko_db->get_similar_jobs( (int) $job->ID, 6 );

	if ( ! empty( $similar_jobs ) ) :
		?>
	<section class="zeko-carousel-section" aria-label="<?php esc_attr_e( 'Jobs you may like', 'zeko-jobs' ); ?>">
		<div class="zeko-carousel-header">
			<h2><?php esc_html_e( 'Jobs You May Like', 'zeko-jobs' ); ?></h2>
			<div class="zeko-carousel-nav">
				<button type="button" class="zeko-carousel-btn zeko-carousel-prev" aria-label="<?php esc_attr_e( 'Previous', 'zeko-jobs' ); ?>" disabled>&lsaquo;</button>
				<button type="button" class="zeko-carousel-btn zeko-carousel-next" aria-label="<?php esc_attr_e( 'Next', 'zeko-jobs' ); ?>">&rsaquo;</button>
			</div>
		</div>
		<div class="zeko-carousel" role="region">
			<div class="zeko-carousel-track">
				<?php
				foreach ( $similar_jobs as $similar ) :
					$similar_employer_id = (int) $similar['employer_id'];
					$similar_company     = __( 'N/A', 'zeko-jobs' );
					if ( $similar_employer_id > 0 ) {
						$ud = get_userdata( $similar_employer_id );
						if ( $ud ) {
							$similar_company = $ud->display_name;
						}
					}
					$salary_text = '';
					if ( ! empty( $similar['salary_min'] ) || ! empty( $similar['salary_max'] ) ) {
						$salary_text = '$' . number_format_i18n( (int) $similar['salary_min'] ) . ' - $' . number_format_i18n( (int) $similar['salary_max'] );
					}
					?>
					<a href="<?php echo esc_url( home_url( '/jobs/' . $similar['slug'] ) ); ?>" class="zeko-carousel-card">
						<h3 class="zeko-carousel-card-title"><?php echo esc_html( $similar['title'] ); ?></h3>
						<span class="zeko-carousel-card-company"><?php echo esc_html( $similar_company ); ?></span>
						<div class="zeko-carousel-card-meta">
							<span class="zeko-carousel-card-location"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $similar['location'] ); ?></span>
							<span class="zeko-carousel-card-type"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span> <?php echo esc_html( ucfirst( str_replace( '-', ' ', $similar['type'] ?? '' ) ) ); ?></span>
							<?php if ( $salary_text ) : ?>
								<span class="zeko-carousel-card-salary"><span class="dashicons dashicons-money" aria-hidden="true"></span> <?php echo esc_html( $salary_text ); ?></span>
							<?php endif; ?>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>
</div>

<div class="zeko-report-modal-overlay" id="zeko-report-modal" hidden>
	<div class="zeko-report-modal" role="dialog" aria-modal="true" aria-labelledby="zeko-report-title">
		<div class="zeko-report-modal-header">
			<h3 id="zeko-report-title"><?php esc_html_e( 'Report this listing', 'zeko-jobs' ); ?></h3>
			<button type="button" class="zeko-report-modal-close" aria-label="<?php esc_attr_e( 'Close', 'zeko-jobs' ); ?>">&times;</button>
		</div>
		<div class="zeko-report-modal-body">
			<p class="description"><?php esc_html_e( 'Help keep the job board safe. Tell us why this listing should be reviewed.', 'zeko-jobs' ); ?></p>
			<div class="zeko-field">
				<label for="zeko-report-reason"><?php esc_html_e( 'Reason', 'zeko-jobs' ); ?></label>
				<select id="zeko-report-reason">
					<option value="inappropriate"><?php esc_html_e( 'Inappropriate content', 'zeko-jobs' ); ?></option>
					<option value="scam"><?php esc_html_e( 'Scam or fraudulent', 'zeko-jobs' ); ?></option>
					<option value="misleading"><?php esc_html_e( 'Misleading or inaccurate', 'zeko-jobs' ); ?></option>
					<option value="expired"><?php esc_html_e( 'Listing already filled or expired', 'zeko-jobs' ); ?></option>
					<option value="other"><?php esc_html_e( 'Other', 'zeko-jobs' ); ?></option>
				</select>
			</div>
			<div class="zeko-field">
				<label for="zeko-report-details"><?php esc_html_e( 'Details (optional)', 'zeko-jobs' ); ?></label>
				<textarea id="zeko-report-details" rows="4" maxlength="1000" placeholder="<?php esc_attr_e( 'Anything the moderation team should know...', 'zeko-jobs' ); ?>"></textarea>
			</div>
			<div class="zeko-report-modal-message" role="status"></div>
		</div>
		<div class="zeko-report-modal-footer">
			<button type="button" class="button zeko-report-modal-cancel"><?php esc_html_e( 'Cancel', 'zeko-jobs' ); ?></button>
			<button type="button" class="button button-primary zeko-report-submit-btn" data-job-id="<?php echo esc_attr( $job->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_flag' ) ); ?>"><?php esc_html_e( 'Submit Report', 'zeko-jobs' ); ?></button>
		</div>
	</div>
</div>

<?php
get_footer();
