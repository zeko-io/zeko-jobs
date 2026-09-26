<?php
/**
 * Company Profile Template
 *
 * Public-facing company profile page at /companies/{slug}/.
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slug = get_query_var( 'zeko_company_slug' );
if ( ! $slug ) {
	wp_die( esc_html__( 'Company not found.', 'zeko-jobs' ) );
}

$zeko_db = Zeko_Jobs_DB::get_instance();
$company = $zeko_db->get_company_by_slug( $slug );

if ( ! $company ) {
	wp_die( esc_html__( 'Company not found.', 'zeko-jobs' ) );
}

$employer_id = (int) $company['employer_id'];
$employer    = get_userdata( $employer_id );

$open_jobs      = $zeko_db->get_jobs_by_company( (int) $company['id'] );
$total_open     = count( $open_jobs );
$company_posts  = $zeko_db->get_company_posts( (int) $company['id'], 6 );
$accent         = ! empty( $company['accent_color'] ) ? sanitize_hex_color( $company['accent_color'] ) : '';
$is_following   = is_user_logged_in() && $zeko_db->is_following_company( get_current_user_id(), $employer_id );
$follower_count = $zeko_db->count_company_followers( $employer_id );

get_header();
/** Emit employer branding for licensed sites (Zeko PRO jobs_ats module). */
do_action( 'zeko_jobs_employer_brand', $employer_id );
?>

<div class="zeko-company-profile"<?php echo $accent ? ' style="--accent:' . esc_attr( $accent ) . '"' : ''; ?>>
	<?php if ( ! empty( $company['cover_url'] ) ) : ?>
		<div class="zeko-company-cover" style="background-image:url(<?php echo esc_url( $company['cover_url'] ); ?>)"></div>
	<?php else : ?>
		<div class="zeko-company-cover zeko-company-cover-default"></div>
	<?php endif; ?>

	<div class="zeko-company-header">
		<?php if ( ! empty( $company['logo_url'] ) ) : ?>
			<img src="<?php echo esc_url( $company['logo_url'] ); ?>" alt="<?php echo esc_attr( $company['name'] ); ?>" class="zeko-company-logo" loading="lazy">
		<?php endif; ?>
		<div class="zeko-company-header-info">
			<div class="zeko-company-title-row">
				<h1><?php echo esc_html( $company['name'] ); ?></h1>
				<?php if ( get_current_user_id() !== $employer_id ) : ?>
					<?php if ( is_user_logged_in() ) : ?>
						<button type="button" class="zeko-follow-btn<?php echo $is_following ? ' is-following' : ''; ?>" data-company="<?php echo esc_attr( $employer_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'zeko_job_follow_company' ) ); ?>">
							<?php echo $is_following ? esc_html__( 'Following', 'zeko-jobs' ) : esc_html__( 'Follow', 'zeko-jobs' ); ?>
						</button>
					<?php else : ?>
						<a class="zeko-follow-btn zeko-follow-login" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Follow', 'zeko-jobs' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php if ( $follower_count > 0 ) : ?>
				<span class="zeko-company-followers"><?php /* translators: %d: number of followers */ printf( esc_html( _n( '%d follower', '%d followers', $follower_count, 'zeko-jobs' ) ), absint( $follower_count ) ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $company['industry'] ) ) : ?>
				<span class="zeko-company-industry"><?php echo esc_html( $company['industry'] ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $company['location'] ) ) : ?>
				<span class="zeko-company-location"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $company['location'] ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $company['size'] ) ) : ?>
				<span class="zeko-company-size"><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php echo esc_html( $company['size'] ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<div class="zeko-company-body">
		<div class="zeko-company-main">
			<?php if ( ! empty( $company['description'] ) ) : ?>
				<div class="zeko-company-section">
					<h2><?php esc_html_e( 'About', 'zeko-jobs' ); ?></h2>
					<div class="zeko-company-description"><?php echo wp_kses_post( wpautop( $company['description'] ) ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $company['video_url'] ) ) : ?>
				<div class="zeko-company-section">
					<h2><?php esc_html_e( 'Company Video', 'zeko-jobs' ); ?></h2>
					<div class="zeko-company-video">
						<?php echo wp_kses_post( (string) ( wp_oembed_get( esc_url( $company['video_url'] ) ) ?: '<a href="' . esc_url( $company['video_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Watch company video', 'zeko-jobs' ) . '</a>' ) ); ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="zeko-company-section">
				<h2><?php esc_html_e( 'Open Positions', 'zeko-jobs' ); ?> <span class="zeko-badge"><?php echo esc_html( $total_open ); ?></span></h2>
				<?php if ( ! empty( $open_jobs ) ) : ?>
					<div class="zeko-company-jobs">
						<?php
						foreach ( $open_jobs as $job ) :
							$job_salary = '';
							if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
								$job_salary = '$' . number_format( (float) $job['salary_min'] ) . ' - $' . number_format( (float) $job['salary_max'] );
							}
							?>
							<a href="<?php echo esc_url( home_url( '/jobs/' . $job['slug'] ) ); ?>" class="zeko-company-job-card">
								<h3><?php echo esc_html( $job['title'] ); ?></h3>
								<div class="zeko-company-job-meta">
									<?php if ( ! empty( $job['location'] ) ) : ?>
										<span><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php echo esc_html( $job['location'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $job['type'] ) ) : ?>
										<span><?php echo esc_html( ucfirst( str_replace( '-', ' ', $job['type'] ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( $job_salary ) : ?>
										<span><span class="dashicons dashicons-money" aria-hidden="true"></span> <?php echo esc_html( $job_salary ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $job['experience_level'] ) ) : ?>
										<span><?php echo esc_html( ucfirst( $job['experience_level'] ) ); ?></span>
									<?php endif; ?>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="zeko-empty-state"><?php esc_html_e( 'No open positions at this time.', 'zeko-jobs' ); ?></p>
				<?php endif; ?>
			</div>

				<?php if ( ! empty( $company_posts ) ) : ?>
					<div class="zeko-company-section zeko-company-blog">
						<h2><?php esc_html_e( 'News & Updates', 'zeko-jobs' ); ?></h2>
						<div class="zeko-company-posts">
							<?php foreach ( $company_posts as $company_post ) : ?>
								<article class="zeko-company-post">
									<h3><?php echo esc_html( $company_post['title'] ); ?></h3>
									<time datetime="<?php echo esc_attr( $company_post['created_at'] ); ?>">
										<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $company_post['created_at'] ) ) ); ?>
									</time>
									<div class="zeko-company-post-content"><?php echo wp_kses_post( wpautop( $company_post['content'] ) ); ?></div>
								</article>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php
				$company_avg  = $zeko_db->get_company_average_rating( $employer_id );
				$avg_rating   = round( (float) $company_avg['avg_rating'], 1 );
				$review_count = (int) $company_avg['review_count'];
				$reviews      = $zeko_db->get_company_reviews( $employer_id, 10 );
				?>
			<div class="zeko-company-section zeko-company-reviews">
				<h2><?php esc_html_e( 'Reviews & Ratings', 'zeko-jobs' ); ?> <span class="zeko-badge"><?php echo esc_html( $review_count ); ?></span></h2>

				<div class="zeko-reviews-summary">
					<div class="zeko-reviews-avg">
						<span class="zeko-reviews-avg-value"><?php echo esc_html( $avg_rating ?: '–' ); ?></span>
						<span class="zeko-reviews-avg-label"><?php esc_html_e( 'out of 5', 'zeko-jobs' ); ?></span>
						<div class="zeko-reviews-stars" data-rating="<?php echo esc_attr( $avg_rating ); ?>">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<span class="dashicons dashicons-star-<?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>" aria-hidden="true"></span>
							<?php endfor; ?>
						</div>
						<span class="zeko-reviews-count"><?php /* translators: %d: number of reviews */ printf( esc_html( _n( '%d review', '%d reviews', $review_count, 'zeko-jobs' ) ), absint( $review_count ) ); ?></span>
					</div>
				</div>

				<?php if ( is_user_logged_in() ) : ?>
					<div class="zeko-review-form-wrap">
						<h3><?php esc_html_e( 'Write a Review', 'zeko-jobs' ); ?></h3>
						<?php
						$user_jobs         = array_filter(
							$open_jobs,
							function ( $j ) use ( $employer_id ) {
								return (int) $j['employer_id'] === $employer_id;
							}
						);
						$user_applied_jobs = array();
						if ( is_user_logged_in() ) {
							$all_apps = $zeko_db->get_user_applications( get_current_user_id() );
							foreach ( $all_apps as $app ) {
								if ( (int) $app['employer_id'] === $employer_id ) {
									$user_applied_jobs[] = $app;
								}
							}
						}
						?>
						<?php if ( ! empty( $user_applied_jobs ) ) : ?>
							<form id="zeko-review-form" class="zeko-review-form" data-company="<?php echo esc_attr( $employer_id ); ?>">
								<?php wp_nonce_field( 'zeko_job_apply', 'zeko_job_nonce' ); ?>
								<div class="zeko-form-group">
									<label for="zeko-review-job"><?php esc_html_e( 'Which position did you apply for?', 'zeko-jobs' ); ?></label>
									<select id="zeko-review-job" name="job_id" required>
										<option value=""><?php esc_html_e( 'Select a position...', 'zeko-jobs' ); ?></option>
										<?php foreach ( $user_applied_jobs as $app ) : ?>
											<option value="<?php echo esc_attr( $app['job_id'] ); ?>"><?php echo esc_html( $app['job_title'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="zeko-form-group">
									<label><?php esc_html_e( 'Your Rating', 'zeko-jobs' ); ?></label>
									<div class="zeko-star-rating-input">
										<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
											<button type="button" class="zeko-star-btn" data-value="<?php echo esc_attr( $i ); ?>" aria-label="<?php /* translators: %d: star rating number */ printf( esc_attr__( '%d star', 'zeko-jobs' ), absint( $i ) ); ?>">
												<span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
											</button>
										<?php endfor; ?>
										<input type="hidden" name="rating" value="0" required>
									</div>
								</div>
								<div class="zeko-form-group">
									<label for="zeko-review-text"><?php esc_html_e( 'Your Review', 'zeko-jobs' ); ?></label>
									<textarea id="zeko-review-text" name="review_text" rows="4" placeholder="<?php esc_attr_e( 'Share your experience working with this company...', 'zeko-jobs' ); ?>" required></textarea>
								</div>
								<button type="submit" class="button button-primary zeko-review-submit"><?php esc_html_e( 'Submit Review', 'zeko-jobs' ); ?></button>
								<span class="zeko-review-status"></span>
							</form>
						<?php else : ?>
							<p class="zeko-empty-state"><?php esc_html_e( 'You need to apply for a position at this company before leaving a review.', 'zeko-jobs' ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<p class="zeko-review-login"><a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in', 'zeko-jobs' ); ?></a> <?php esc_html_e( 'to write a review.', 'zeko-jobs' ); ?></p>
				<?php endif; ?>

				<div class="zeko-reviews-list">
					<?php if ( ! empty( $reviews ) ) : ?>
						<?php foreach ( $reviews as $review ) : ?>
							<div class="zeko-review-item">
								<div class="zeko-review-header">
									<span class="zeko-review-author"><?php echo esc_html( $review['reviewer_name'] ); ?></span>
									<span class="zeko-review-date"><?php echo esc_html( human_time_diff( strtotime( $review['created_at'] ), time() ) . ' ' . __( 'ago', 'zeko-jobs' ) ); ?></span>
									<div class="zeko-review-stars" data-rating="<?php echo esc_attr( $review['rating'] ); ?>">
										<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
											<span class="dashicons dashicons-star-<?php echo $i <= (int) $review['rating'] ? 'filled' : 'empty'; ?>" aria-hidden="true"></span>
										<?php endfor; ?>
									</div>
								</div>
								<?php if ( ! empty( $review['job_title'] ) ) : ?>
									<p class="zeko-review-job"><?php /* translators: %s: job title */ printf( esc_html__( 'Applied for: %s', 'zeko-jobs' ), '<a href="' . esc_url( home_url( '/jobs/' . $review['job_slug'] ) ) . '">' . esc_html( $review['job_title'] ) . '</a>' ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $review['review_text'] ) ) : ?>
									<div class="zeko-review-text"><?php echo wp_kses_post( wpautop( $review['review_text'] ) ); ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="zeko-empty-state"><?php esc_html_e( 'No reviews yet. Be the first to review this company!', 'zeko-jobs' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<aside class="zeko-company-sidebar">
			<div class="zeko-company-info-card">
				<h3><?php esc_html_e( 'Company Info', 'zeko-jobs' ); ?></h3>
				<?php if ( ! empty( $company['website'] ) ) : ?>
					<p><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <a href="<?php echo esc_url( $company['website'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $company['website'] ); ?></a></p>
				<?php endif; ?>
				<?php if ( ! empty( $company['founded_year'] ) ) : ?>
					<p><span class="dashicons dashicons-calendar" aria-hidden="true"></span> <?php /* translators: %s: year the company was founded */ printf( esc_html__( 'Founded: %s', 'zeko-jobs' ), esc_html( $company['founded_year'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $company['size'] ) ) : ?>
					<p><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php /* translators: %s: company size */ printf( esc_html__( 'Company Size: %s', 'zeko-jobs' ), esc_html( $company['size'] ) ); ?></p>
				<?php endif; ?>

				<?php
				$social = array();
				if ( ! empty( $company['linkedin'] ) ) {
					$social[] = '<a href="' . esc_url( $company['linkedin'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'LinkedIn', 'zeko-jobs' ) . '</a>';
				}
				if ( ! empty( $company['twitter'] ) ) {
					$social[] = '<a href="' . esc_url( $company['twitter'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Twitter / X', 'zeko-jobs' ) . '</a>';
				}
				if ( ! empty( $social ) ) :
					?>
					<div class="zeko-company-social">
						<?php echo wp_kses_post( implode( ' | ', $social ) ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $employer ) : ?>
				<div class="zeko-company-info-card">
					<h3><?php esc_html_e( 'Contact', 'zeko-jobs' ); ?></h3>
					<p><?php echo esc_html( $employer->display_name ); ?></p>
					<p><a href="mailto:<?php echo esc_attr( $employer->user_email ); ?>"><?php echo esc_html( $employer->user_email ); ?></a></p>
				</div>
			<?php endif; ?>
		</aside>
	</div>
</div>

<?php
get_footer();
