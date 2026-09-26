<?php
/**
 * Admin facing functionality for Zeko Jobs.
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/** Class Zeko_Jobs_Admin. */
final class Zeko_Jobs_Admin {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Zeko_Jobs_Admin
	 */
	public static function get_instance(): Zeko_Jobs_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Register hooks.
	 */
	private function __construct() {

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );

		// Admin jobs management (CRUD).
		add_action( 'admin_menu', array( $this, 'add_jobs_admin_pages' ) );

		// Admin: CSV export (jobs list page).
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );

		// AJAX: Generate demo data.
		add_action( 'wp_ajax_zeko_jobs_generate_demo_data', array( $this, 'generate_demo_data_ajax' ) );
		// AJAX: Clear demo data.
		add_action( 'wp_ajax_zeko_jobs_clear_demo_data', array( $this, 'clear_demo_data_ajax' ) );

		// Profile fields.
		add_action( 'show_user_profile', array( $this, 'render_job_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_job_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_job_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_job_profile_fields' ) );
	}
	/**
	 * Add admin menu.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Zeko Jobs', 'zeko-jobs' ),
			__( 'Zeko Jobs', 'zeko-jobs' ),
			'manage_options',
			'zeko-jobs',
			array( $this, 'options_page' ),
			'dashicons-clipboard',
			6
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function settings_init(): void {
		register_setting( 'zekoJobs', 'zeko_jobs_settings' );

		add_settings_section(
			'zeko_jobs_general_section',
			__( 'General Settings', 'zeko-jobs' ),
			function () {},
			'zekoJobs'
		);

		add_settings_field(
			'zeko_jobs_expiry_days',
			__( 'Job Expiration (days)', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_general_section',
			array(
				'id'          => 'expiry_days',
				'default'     => 30,
				'min'         => 1,
				'max'         => 365,
				'description' => __( 'Number of days before a job listing expires. Set to 0 to disable expiration.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_max_daily_apps',
			__( 'Max Daily Applications', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_general_section',
			array(
				'id'          => 'max_daily_applications',
				'default'     => 20,
				'min'         => 1,
				'max'         => 100,
				'description' => __( 'Maximum applications a seeker can submit per day.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_expiry_reminder_days',
			__( 'Expiry Reminder (days before)', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_general_section',
			array(
				'id'          => 'expiry_reminder_days',
				'default'     => 7,
				'min'         => 1,
				'max'         => 30,
				'description' => __( 'Send reminder email this many days before job expires.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_email_notifications',
			__( 'Email Notifications', 'zeko-jobs' ),
			array( $this, 'render_toggle_field' ),
			'zekoJobs',
			'zeko_jobs_general_section',
			array(
				'id'          => 'send_status_emails',
				'default'     => 1,
				'description' => __( 'Send automated email notifications for application status changes.', 'zeko-jobs' ),
			)
		);

		add_settings_section(
			'zeko_jobs_approval_section',
			__( 'Job Approval', 'zeko-jobs' ),
			function () {},
			'zekoJobs'
		);

		add_settings_field(
			'zeko_jobs_require_approval',
			__( 'Require Approval', 'zeko-jobs' ),
			array( $this, 'render_toggle_field' ),
			'zekoJobs',
			'zeko_jobs_approval_section',
			array(
				'id'          => 'require_approval',
				'default'     => 0,
				'description' => __( 'Require admin approval before new job listings are published.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_auto_approve_trusted',
			__( 'Auto-approve Trusted Employers', 'zeko-jobs' ),
			array( $this, 'render_toggle_field' ),
			'zekoJobs',
			'zeko_jobs_approval_section',
			array(
				'id'          => 'auto_approve_trusted',
				'default'     => 1,
				'description' => __( 'Automatically approve jobs from verified/trusted employers.', 'zeko-jobs' ),
			)
		);

		add_settings_section(
			'zeko_jobs_linkedin_section',
			__( 'LinkedIn Integration', 'zeko-jobs' ),
			function () {
				echo '<p>' . esc_html__( 'Configure LinkedIn OAuth to enable "Apply with LinkedIn" and profile import. Create an app at developer.linkedin.com.', 'zeko-jobs' ) . '</p>';
			},
			'zekoJobs'
		);

		add_settings_field(
			'zeko_linkedin_client_id',
			__( 'LinkedIn Client ID', 'zeko-jobs' ),
			array( $this, 'render_text_field' ),
			'zekoJobs',
			'zeko_jobs_linkedin_section',
			array(
				'id'          => 'linkedin_client_id',
				'default'     => '',
				'description' => __( 'From your LinkedIn app at developer.linkedin.com.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_linkedin_client_secret',
			__( 'LinkedIn Client Secret', 'zeko-jobs' ),
			array( $this, 'render_text_field' ),
			'zekoJobs',
			'zeko_jobs_linkedin_section',
			array(
				'id'          => 'linkedin_client_secret',
				'default'     => '',
				'description' => __( 'Keep this secret. Never share it publicly.', 'zeko-jobs' ),
			)
		);

		// ── ZekoPay / Monetization Section ──.
		add_settings_section(
			'zeko_jobs_billing_section',
			__( 'Monetization (ZekoPay)', 'zeko-jobs' ),
			function () {
				$pay_active = class_exists( 'Zeko_Pay' );
				$icon       = $pay_active ? '<span style="color:#16a34a;">&#10003;</span>' : '<span style="color:#dc2626;">&#10007;</span>';
				printf(
					'<p>%s %s</p>',
					wp_kses( $icon, array( 'span' => array( 'style' => true ) ) ),
					esc_html__( 'ZekoPay integration for job posting fees, boosts, and listing packs.', 'zeko-jobs' )
				);
				if ( ! $pay_active ) {
					echo '<p class="description" style="color:#dc2626;">' . esc_html__( 'Zeko Pay plugin is not active. Install and activate it to enable monetization.', 'zeko-jobs' ) . '</p>';
				}
			},
			'zekoJobs'
		);

		add_settings_field(
			'zeko_jobs_enable_payments',
			__( 'Enable Payments', 'zeko-jobs' ),
			array( $this, 'render_toggle_field' ),
			'zekoJobs',
			'zeko_jobs_billing_section',
			array(
				'id'          => 'enable_payments',
				'default'     => 0,
				'description' => __( 'Require employers to pay for job postings via ZekoPay wallet.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_free_jobs',
			__( 'Free Job Postings', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_billing_section',
			array(
				'id'          => 'free_jobs',
				'default'     => 0,
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Number of free job postings allowed per employer before payment is required. Set to 0 to charge for every posting.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_posting_fee',
			__( 'Job Posting Fee', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_billing_section',
			array(
				'id'          => 'posting_fee',
				'default'     => 25,
				'min'         => 0,
				'max'         => 10000,
				'description' => __( 'Fee charged per job posting (in USD). Deducted from employer ZekoPay wallet.', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_boost_fee',
			__( 'Boost / Feature Fee', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_billing_section',
			array(
				'id'          => 'boost_fee',
				'default'     => 10,
				'min'         => 0,
				'max'         => 10000,
				'description' => __( 'Fee charged to boost/feature a job listing (in USD).', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_listing_pack_price',
			__( 'Listing Pack Price', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_billing_section',
			array(
				'id'          => 'listing_pack_price',
				'default'     => 99,
				'min'         => 0,
				'max'         => 100000,
				'description' => __( 'Price for a bulk listing pack (in USD).', 'zeko-jobs' ),
			)
		);

		add_settings_field(
			'zeko_jobs_listing_pack_count',
			__( 'Listing Pack Job Count', 'zeko-jobs' ),
			array( $this, 'render_number_field' ),
			'zekoJobs',
			'zeko_jobs_billing_section',
			array(
				'id'          => 'listing_pack_count',
				'default'     => 10,
				'min'         => 1,
				'max'         => 1000,
				'description' => __( 'Number of job postings included in one listing pack.', 'zeko-jobs' ),
			)
		);
	}
	/**
	 * Render a number input field for settings.
	 *
	 * @param array $args Args.
	 */
	public function render_number_field( array $args ): void {
		$options = get_option( 'zeko_jobs_settings', array() );
		$value   = isset( $options[ $args['id'] ] ) ? (int) $options[ $args['id'] ] : $args['default'];
		printf(
			'<input type="number" id="zeko_jobs_%1$s" name="zeko_jobs_settings[%1$s]" value="%2$d" min="%3$d" max="%4$d" class="small-text">',
			esc_attr( $args['id'] ),
			absint( $value ),
			absint( $args['min'] ),
			absint( $args['max'] )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a toggle (checkbox) field for settings.
	 *
	 * @param array $args Args.
	 */
	public function render_toggle_field( array $args ): void {
		$options = get_option( 'zeko_jobs_settings', array() );
		$value   = isset( $options[ $args['id'] ] ) ? (int) $options[ $args['id'] ] : $args['default'];
		printf(
			'<label><input type="checkbox" id="zeko_jobs_%1$s" name="zeko_jobs_settings[%1$s]" value="1" %2$s> %3$s</label>',
			esc_attr( $args['id'] ),
			checked( $value, 1, false ),
			esc_html( $args['description'] )
		);
	}

	/**
	 * Render a text input field for settings.
	 *
	 * @param array $args Args.
	 */
	public function render_text_field( array $args ): void {
		$options = get_option( 'zeko_jobs_settings', array() );
		$value   = isset( $options[ $args['id'] ] ) ? $options[ $args['id'] ] : $args['default'];
		printf(
			'<input type="text" id="zeko_jobs_%1$s" name="zeko_jobs_settings[%1$s]" value="%2$s" class="regular-text">',
			esc_attr( $args['id'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Get a settings value with fallback to default.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 */
	public static function get_setting( string $key, $default = null ) {
		$defaults = array(
			'expiry_days'            => 30,
			'max_daily_applications' => 20,
			'expiry_reminder_days'   => 7,
			'send_status_emails'     => 1,
			'require_approval'       => 0,
			'auto_approve_trusted'   => 1,
			'enable_payments'        => 0,
			'free_jobs'              => 0,
			'posting_fee'            => 25,
			'boost_fee'              => 10,
			'listing_pack_price'     => 99,
			'listing_pack_count'     => 10,
		);
		$options  = get_option( 'zeko_jobs_settings', array() );
		$default  = null !== $default ? $default : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : null );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Render options page.
	 */
	public function options_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zeko Jobs Settings', 'zeko-jobs' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'zekoJobs' );
				do_settings_sections( 'zekoJobs' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Generate Demo Data', 'zeko-jobs' ); ?></th>
						<td>
						<button type="button" class="button button-secondary" id="zeko-generate-demo"><?php esc_html_e( 'Generate Demo Data', 'zeko-jobs' ); ?></button>
						<button type="button" class="button button-secondary" id="zeko-clear-demo"><?php esc_html_e( 'Clear Demo Data', 'zeko-jobs' ); ?></button>
						<p class="description"><?php esc_html_e( 'Click to generate sample companies, jobs, and applications, or clear them afterwards.', 'zeko-jobs' ); ?></p>
						<div id="zeko-demo-data-message"></div>
						<script>
						jQuery(document).ready(function($) {
							$('#zeko-generate-demo').on('click', function() {
								if (confirm('<?php esc_html_e( 'Are you sure you want to generate demo data? This will add new entries.', 'zeko-jobs' ); ?>')) {
									var $button = $(this);
									$button.prop('disabled', true).text('<?php esc_attr_e( 'Generating...', 'zeko-jobs' ); ?>');
									$('#zeko-demo-data-message').html('');

									$.ajax({
										url: ajaxurl, // WordPress AJAX URL.
										type: 'POST',
										data: {
											action: 'zeko_jobs_generate_demo_data',
											_wpnonce: '<?php echo esc_attr( wp_create_nonce( 'zeko_jobs_generate_demo_data_nonce' ) ); ?>'
										},
										success: function(response) {
											if (response.success) {
												$('#zeko-demo-data-message').html('<p style="color: green;">' + response.data.message + '</p>');
											} else {
												$('#zeko-demo-data-message').html('<p style="color: red;">' + response.data.message + '</p>');
											}
										},
										error: function() {
											$('#zeko-demo-data-message').html('<p style="color: red;"><?php esc_html_e( 'An error occurred during demo data generation.', 'zeko-jobs' ); ?></p>');
										},
										complete: function() {
											$button.prop('disabled', false).text('<?php esc_attr_e( 'Generate Demo Data', 'zeko-jobs' ); ?>');
										}
									});
								}
							});
							$('#zeko-clear-demo').on('click', function() {
								if (confirm('<?php esc_html_e( 'Are you sure you want to clear demo data? This deletes demo companies, jobs, applications, and users.', 'zeko-jobs' ); ?>')) {
									var $button = $(this);
									$button.prop('disabled', true).text('<?php esc_attr_e( 'Clearing...', 'zeko-jobs' ); ?>');
									$('#zeko-demo-data-message').html('');

									$.ajax({
										url: ajaxurl, // WordPress AJAX URL.
										type: 'POST',
										data: {
											action: 'zeko_jobs_clear_demo_data',
											_wpnonce: '<?php echo esc_attr( wp_create_nonce( 'zeko_jobs_clear_demo_data_nonce' ) ); ?>'
										},
										success: function(response) {
											if (response.success) {
												$('#zeko-demo-data-message').html('<p style="color: green;">' + response.data.message + '</p>');
											} else {
												$('#zeko-demo-data-message').html('<p style="color: red;">' + response.data.message + '</p>');
											}
										},
										error: function() {
											$('#zeko-demo-data-message').html('<p style="color: red;"><?php esc_html_e( 'An error occurred while clearing demo data.', 'zeko-jobs' ); ?></p>');
										},
										complete: function() {
											$button.prop('disabled', false).text('<?php esc_attr_e( 'Clear Demo Data', 'zeko-jobs' ); ?>');
										}
									});
								}
							});
						});
						</script>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add dashboard widgets.
	 */
	public function add_dashboard_widgets(): void {
		wp_add_dashboard_widget(
			'zeko_jobs_dashboard_widget',
			__( 'Zeko Jobs Overview', 'zeko-jobs' ),
			array( $this, 'dashboard_widget_content' )
		);
	}

	/**
	 * Render dashboard widget content.
	 */
	public function dashboard_widget_content(): void {
		$job_count   = Zeko_Jobs_DB::get_instance()->get_job_count();
		$recent_jobs = Zeko_Jobs_DB::get_instance()->get_recent_jobs( 5 );
		?>
		<div class="zeko-jobs-dashboard-widget">
			<h3><?php esc_html_e( 'Jobs Overview', 'zeko-jobs' ); ?></h3>
			<p><?php /* translators: %d: number of published jobs */ printf( esc_html__( 'Total published jobs: %d', 'zeko-jobs' ), absint( $job_count ) ); ?></p>
			<?php if ( ! empty( $recent_jobs ) ) : ?>
				<h4><?php esc_html_e( 'Recent Jobs', 'zeko-jobs' ); ?></h4>
				<ul>
					<?php foreach ( $recent_jobs as $job ) : ?>
						<li><?php echo esc_html( $job['title'] ); ?> (<?php echo esc_html( $job['location'] ); ?>)</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No recent jobs found.', 'zeko-jobs' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler to generate demo data.
	 */
	public function generate_demo_data_ajax(): void {
		check_ajax_referer( 'zeko_jobs_generate_demo_data_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'zeko-jobs' ) ) );
		}

		// Implement demo data generation logic here.
		// For now, a placeholder.
		$result = $this->generate_demo_data();

		if ( $result ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Demo data generated successfully!', 'zeko-jobs' ) ) );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to generate demo data.', 'zeko-jobs' ) ) );
		}
	}

	/**
	 * AJAX handler to clear demo data.
	 */
	public function clear_demo_data_ajax(): void {
		check_ajax_referer( 'zeko_jobs_clear_demo_data_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'zeko-jobs' ) ) );
		}

		$count = $this->clear_demo_data();

		wp_send_json_success(
			array(
				/* translators: %d: number of demo users removed */
				'message' => sprintf( esc_html__( 'Demo data cleared (%d demo users removed).', 'zeko-jobs' ), $count ),
			)
		);
	}

	/**
	 * Render job profile fields on user profile page.
	 *
	 * @param WP_User $user User object.
	 */
	public function render_job_profile_fields( WP_User $user ): void {
		$role       = Zeko_Jobs::get_instance()->get_user_job_role( $user->ID );
		$logo_id    = get_user_meta( $user->ID, 'zeko_company_logo_id', true );
		$logo_url   = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$resume_url = Zeko_Jobs::get_instance()->get_user_resume( $user->ID );
		?>
		<h2><?php esc_html_e( 'Zeko Jobs Profile', 'zeko-jobs' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="zeko_job_role"><?php esc_html_e( 'Job Role', 'zeko-jobs' ); ?></label></th>
				<td>
					<select name="zeko_job_role" id="zeko_job_role">
						<option value=""><?php esc_html_e( '-- Select Role --', 'zeko-jobs' ); ?></option>
						<option value="seeker" <?php selected( $role, 'seeker' ); ?>><?php esc_html_e( 'Job Seeker', 'zeko-jobs' ); ?></option>
						<option value="employer" <?php selected( $role, 'employer' ); ?>><?php esc_html_e( 'Employer', 'zeko-jobs' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="zeko-employer-fields" style="display: none;">
				<th><label for="zeko_company_logo"><?php esc_html_e( 'Company Logo', 'zeko-jobs' ); ?></label></th>
				<td>
					<input type="file" name="zeko_company_logo" id="zeko_company_logo" accept="image/*">
					<?php if ( $logo_url ) : ?>
						<p><img src="<?php echo esc_url( $logo_url ); ?>" style="max-width: 120px; height: auto;"></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Recommended: square image, 200x200px or larger.', 'zeko-jobs' ); ?></p>
				</td>
			</tr>
			<tr class="zeko-seeker-fields" style="display: none;">
				<th><label for="zeko_resume"><?php esc_html_e( 'Resume', 'zeko-jobs' ); ?></label></th>
				<td>
					<input type="file" name="zeko_resume" id="zeko_resume" accept=".pdf,.doc,.docx">
					<?php if ( $resume_url ) : ?>
						<p><a href="<?php echo esc_url( $resume_url ); ?>" target="_blank"><?php esc_html_e( 'Current resume', 'zeko-jobs' ); ?></a></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'PDF or DOC/DOCX, max 5MB.', 'zeko-jobs' ); ?></p>
				</td>
			</tr>
		</table>
		<script>
		jQuery(document).ready(function($) {
			function toggleJobFields() {
				var role = $('#zeko_job_role').val();
				if (role === 'employer') {
					$('.zeko-employer-fields').show();
					$('.zeko-seeker-fields').hide();
				} else if (role === 'seeker') {
					$('.zeko-employer-fields').hide();
					$('.zeko-seeker-fields').show();
				} else {
					$('.zeko-employer-fields').hide();
					$('.zeko-seeker-fields').hide();
				}
			}
			$('#zeko_job_role').on('change', toggleJobFields);
			toggleJobFields();
		});
		</script>
		<?php
	}

	/**
	 * Save job profile fields.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_job_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$role          = isset( $_POST['zeko_job_role'] ) ? sanitize_text_field( wp_unslash( $_POST['zeko_job_role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce verified by WP core (check_admin_referer( 'update-user_' . $user_id )) before the profile-update hooks are fired.
		$allowed_roles = array( 'seeker', 'employer' );
		if ( in_array( $role, $allowed_roles, true ) ) {
			update_user_meta( $user_id, 'zeko_job_role', $role );
		} else {
			delete_user_meta( $user_id, 'zeko_job_role' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Upload reading; form guarded by core's update-user_{ID} nonce.
		if ( ! empty( $_FILES['zeko_company_logo']['name'] ) ) {
			$allowed_logo_mimes = array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			);

			$logo_valid = false;
			if ( class_exists( 'Zeko_Core_Upload' ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WP core before hook; $_FILES validated via Zeko_Core_Upload::validate_file().
				$logo_valid = ! is_wp_error( Zeko_Core_Upload::validate_file( $_FILES['zeko_company_logo'], 'image', 2 * 1024 * 1024 ) );
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WP core before hook; $_FILES validated via wp_check_filetype_and_ext().
				$logo_mime_check = wp_check_filetype_and_ext( $_FILES['zeko_company_logo']['tmp_name'], $_FILES['zeko_company_logo']['name'], $allowed_logo_mimes );
				$logo_valid      = ! empty( $logo_mime_check['type'] ) && ! empty( $logo_mime_check['ext'] ) && $_FILES['zeko_company_logo']['size'] <= 2 * 1024 * 1024; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce verified by WP core before hook; upload size validated above.
			}

			if ( $logo_valid ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';

				$logo_id = media_handle_upload( 'zeko_company_logo', 0 );
				if ( ! is_wp_error( $logo_id ) ) {
					update_user_meta( $user_id, 'zeko_company_logo_id', $logo_id );
				}
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Upload reading; form guarded by core's update-user_{ID} nonce.
		if ( ! empty( $_FILES['zeko_resume']['name'] ) ) {
			$allowed_mimes = array(
				'pdf'  => 'application/pdf',
				'doc'  => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WP core before hook; extension allow-list checked below.
			$file_ext = strtolower( pathinfo( $_FILES['zeko_resume']['name'], PATHINFO_EXTENSION ) );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WP core before hook; $_FILES validated via wp_check_filetype_and_ext().
			$mime_check = wp_check_filetype_and_ext( $_FILES['zeko_resume']['tmp_name'], $_FILES['zeko_resume']['name'] );

			if ( isset( $allowed_mimes[ $file_ext ] )
				&& ! empty( $mime_check['ext'] )
				&& in_array( $mime_check['type'], $allowed_mimes, true )
				&& $_FILES['zeko_resume']['size'] <= 5 * 1024 * 1024 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce verified by WP core before hook; upload size validated here.
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';

				$upload = wp_handle_upload(
					$_FILES['zeko_resume'], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce verified by WP core before hook; wp_handle_upload() validates the file.
					array(
						'test_form' => false,
						'mimes'     => $allowed_mimes,
					)
				);
				if ( ! isset( $upload['error'] ) && isset( $upload['url'] ) ) {
					update_user_meta( $user_id, 'zeko_resume', esc_url_raw( $upload['url'] ) );
				}
			}
		}
	}

	/**
	 * Generate demo data (companies, jobs, applications).
	 */
	public function generate_demo_data(): bool {
		global $wpdb;
		$table_jobs      = $wpdb->prefix . 'zeko_jobs';
		$table_apps      = $wpdb->prefix . 'zeko_job_applications';
		$table_companies = $wpdb->prefix . 'zeko_companies';

		// Create employer users if none exist.
		$employer_ids = get_users(
			array(
				'role'   => 'employer',
				'fields' => 'ID',
			)
		);
		if ( empty( $employer_ids ) ) {
			$employer_names = array( 'TechCorp', 'DataFlow Inc', 'CloudNine', 'GreenLeaf', 'InnovateLab' );
			foreach ( $employer_names as $name ) {
				$username = sanitize_title( $name );
				$user_id  = wp_create_user( $username, wp_generate_password(), strtolower( $name ) . '@demo.com' );
				if ( ! is_wp_error( $user_id ) ) {
					$user = new WP_User( $user_id );
					$user->set_role( 'employer' );
					update_user_meta( $user_id, 'display_name', $name );
					update_user_meta( $user_id, 'demo_seeded', 1 );
					update_user_meta( $user_id, 'zeko_demo_user', 1 );
					$employer_ids[] = $user_id;
				}
			}
		}

		// Create seeker users if none exist.
		$seeker_ids = get_users(
			array(
				'role'   => 'seeker',
				'fields' => 'ID',
			)
		);
		if ( empty( $seeker_ids ) ) {
			$seeker_names = array(
				'Alice Johnson',
				'Bob Smith',
				'Carol White',
				'David Lee',
				'Eva Martinez',
				'Frank Brown',
				'Grace Kim',
				'Henry Wang',
				'Iris Patel',
				'Jack Davis',
			);
			foreach ( $seeker_names as $name ) {
				$parts    = explode( ' ', $name, 2 );
				$username = sanitize_title( $name );
				$user_id  = wp_create_user( $username, wp_generate_password(), strtolower( $username ) . '@demo.com' );
				if ( ! is_wp_error( $user_id ) ) {
					$user = new WP_User( $user_id );
					$user->set_role( 'seeker' );
					update_user_meta( $user_id, 'display_name', $name );
					update_user_meta( $user_id, 'first_name', $parts[0] );
					update_user_meta( $user_id, 'last_name', $parts[1] ?? '' );
					update_user_meta( $user_id, 'demo_seeded', 1 );
					update_user_meta( $user_id, 'zeko_demo_user', 1 );
					$seeker_ids[] = $user_id;
				}
			}
		}

		if ( empty( $employer_ids ) || empty( $seeker_ids ) ) {
			return false;
		}

		// Demo job data.
		$job_templates = array(
			array(
				'title'            => 'Senior Frontend Developer',
				'type'             => 'full_time',
				'category'         => 'technology',
				'experience_level' => 'senior',
				'remote_option'    => 'remote',
				'salary_min'       => 80000,
				'salary_max'       => 120000,
				'location'         => 'San Francisco, CA',
			),
			array(
				'title'            => 'Backend Engineer',
				'type'             => 'full_time',
				'category'         => 'technology',
				'experience_level' => 'mid',
				'remote_option'    => 'hybrid',
				'salary_min'       => 70000,
				'salary_max'       => 110000,
				'location'         => 'New York, NY',
			),
			array(
				'title'            => 'UX Designer',
				'type'             => 'full_time',
				'category'         => 'design',
				'experience_level' => 'mid',
				'remote_option'    => 'remote',
				'salary_min'       => 65000,
				'salary_max'       => 95000,
				'location'         => 'Austin, TX',
			),
			array(
				'title'            => 'DevOps Engineer',
				'type'             => 'full_time',
				'category'         => 'technology',
				'experience_level' => 'senior',
				'remote_option'    => 'onsite',
				'salary_min'       => 90000,
				'salary_max'       => 140000,
				'location'         => 'Seattle, WA',
			),
			array(
				'title'            => 'Product Manager',
				'type'             => 'full_time',
				'category'         => 'management',
				'experience_level' => 'senior',
				'remote_option'    => 'hybrid',
				'salary_min'       => 95000,
				'salary_max'       => 150000,
				'location'         => 'Chicago, IL',
			),
			array(
				'title'            => 'Data Analyst',
				'type'             => 'full_time',
				'category'         => 'technology',
				'experience_level' => 'entry',
				'remote_option'    => 'remote',
				'salary_min'       => 50000,
				'salary_max'       => 75000,
				'location'         => 'Denver, CO',
			),
			array(
				'title'            => 'Marketing Specialist',
				'type'             => 'full_time',
				'category'         => 'marketing',
				'experience_level' => 'mid',
				'remote_option'    => 'onsite',
				'salary_min'       => 55000,
				'salary_max'       => 80000,
				'location'         => 'Miami, FL',
			),
			array(
				'title'            => 'QA Engineer',
				'type'             => 'full_time',
				'category'         => 'technology',
				'experience_level' => 'mid',
				'remote_option'    => 'remote',
				'salary_min'       => 60000,
				'salary_max'       => 90000,
				'location'         => 'Portland, OR',
			),
			array(
				'title'            => 'Content Writer',
				'type'             => 'part_time',
				'category'         => 'marketing',
				'experience_level' => 'entry',
				'remote_option'    => 'remote',
				'salary_min'       => 30000,
				'salary_max'       => 50000,
				'location'         => 'Remote',
			),
			array(
				'title'            => 'Sales Representative',
				'type'             => 'full_time',
				'category'         => 'sales',
				'experience_level' => 'mid',
				'remote_option'    => 'onsite',
				'salary_min'       => 45000,
				'salary_max'       => 70000,
				'location'         => 'Dallas, TX',
			),
			array(
				'title'            => 'Mobile Developer',
				'type'             => 'contract',
				'category'         => 'technology',
				'experience_level' => 'senior',
				'remote_option'    => 'remote',
				'salary_min'       => 100000,
				'salary_max'       => 150000,
				'location'         => 'Remote',
			),
			array(
				'title'            => 'HR Coordinator',
				'type'             => 'full_time',
				'category'         => 'management',
				'experience_level' => 'entry',
				'remote_option'    => 'onsite',
				'salary_min'       => 40000,
				'salary_max'       => 55000,
				'location'         => 'Boston, MA',
			),
			array(
				'title'            => 'Financial Analyst',
				'type'             => 'full_time',
				'category'         => 'finance',
				'experience_level' => 'mid',
				'remote_option'    => 'hybrid',
				'salary_min'       => 65000,
				'salary_max'       => 95000,
				'location'         => 'Charlotte, NC',
			),
			array(
				'title'            => 'Graphic Designer',
				'type'             => 'freelance',
				'category'         => 'design',
				'experience_level' => 'mid',
				'remote_option'    => 'remote',
				'salary_min'       => 40000,
				'salary_max'       => 70000,
				'location'         => 'Remote',
			),
			array(
				'title'            => 'Technical Writer',
				'type'             => 'full_time',
				'category'         => 'technology',
				'experience_level' => 'mid',
				'remote_option'    => 'remote',
				'salary_min'       => 55000,
				'salary_max'       => 85000,
				'location'         => 'Remote',
			),
		);

		$company_names = array( 'TechCorp', 'DataFlow Inc', 'CloudNine Solutions', 'GreenLeaf Corp', 'InnovateLab' );
		$industries    = array( 'technology', 'finance', 'healthcare', 'education', 'marketing' );
		$descriptions  = array(
			'We are a leading technology company focused on building innovative solutions for modern businesses. Our team is passionate about creating products that make a difference.',
			'DataFlow helps organizations harness the power of their data. We provide cutting-edge analytics and business intelligence solutions.',
			'CloudNine Solutions delivers enterprise cloud infrastructure and DevOps tools. We help companies scale with confidence.',
			'GreenLeaf Corp is committed to sustainability and environmental innovation. We build products that are good for people and the planet.',
			'InnovateLab is a research-driven company developing next-generation AI and machine learning technologies.',
		);
		$cover_letters = array(
			'I am excited about this opportunity and believe my skills align well with the requirements. I have extensive experience in the field and am eager to contribute to your team.',
			'With my background in the industry, I am confident I can make a meaningful impact. I am particularly drawn to your company\'s mission and values.',
			'I would love to bring my expertise to your organization. My previous roles have prepared me well for this position.',
			'Your company\'s innovative approach resonates with me. I am looking for an opportunity where I can grow and contribute meaningfully.',
			'I am passionate about this field and have the skills and experience to excel in this role. I would welcome the chance to discuss how I can contribute.',
		);
		$statuses      = array( 'awaiting_review', 'reviewed', 'contacting', 'interviewing', 'offered', 'hired' );

		$job_count = 0;
		$app_count = 0;

		foreach ( $job_templates as $idx => $tpl ) {
			$employer_id = $employer_ids[ $idx % count( $employer_ids ) ];
			$company_idx = $idx % count( $company_names );
			$slug        = sanitize_title( $tpl['title'] );
			$deadline    = gmdate( 'Y-m-d', strtotime( '+' . wp_rand( 14, 90 ) . ' days' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			// Check if job already exists.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_jobs} WHERE slug = %s LIMIT 1", $slug ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( $existing ) {
				continue;
			}

			$wpdb->insert(
				$table_jobs,
				array(
					'title'                => $tpl['title'],
					'slug'                 => $slug,
					'description'          => $descriptions[ $company_idx ],
					'responsibilities'     => '• Lead projects and deliver high-quality results<br>• Collaborate with cross-functional teams<br>• Mentor junior team members',
					'requirements'         => '• 3+ years of relevant experience<br>• Strong communication skills<br>• Ability to work independently',
					'qualifications'       => '• Bachelor\'s degree in a related field<br>• Relevant certifications preferred',
					'benefits'             => '• Competitive salary and benefits<br>• Flexible work arrangements<br>• Professional development opportunities',
					'location'             => $tpl['location'],
					'type'                 => $tpl['type'],
					'salary_min'           => $tpl['salary_min'],
					'salary_max'           => $tpl['salary_max'],
					'category'             => $tpl['category'],
					'experience_level'     => $tpl['experience_level'],
					'remote_option'        => $tpl['remote_option'],
					'company_name'         => $company_names[ $company_idx ],
					'company_industry'     => $industries[ $company_idx ],
					'company_size'         => '51-200',
					'is_featured'          => ( $idx < 3 ) ? 1 : 0,
					'is_easy_apply'        => ( 0 === $idx % 3 ) ? 1 : 0,
					'application_deadline' => $deadline,
					'employer_id'          => $employer_id,
					'status'               => 'publish',
					'created_at'           => current_time( 'mysql' ),
					'updated_at'           => current_time( 'mysql' ),
				)
			);

			$job_id = $wpdb->insert_id;
			if ( ! $job_id ) {
				continue;
			}
			++$job_count;

			// Create applications for this job.
			$num_apps      = wp_rand( 2, 6 );
			$used_seekers  = array();
			$seeker_count  = count( $seeker_ids );
			for ( $a = 0; $a < $num_apps && $a < $seeker_count; $a++ ) {
				$seeker_id = $seeker_ids[ array_rand( $seeker_ids ) ];
				if ( in_array( $seeker_id, $used_seekers, true ) ) {
					continue;
				}
				$used_seekers[] = $seeker_id;
				$status         = $statuses[ array_rand( $statuses ) ];

				$wpdb->insert(
					$table_apps,
					array(
						'job_id'       => $job_id,
						'seeker_id'    => $seeker_id,
						'status'       => $status,
						'stage'        => $status,
						'cover_letter' => $cover_letters[ array_rand( $cover_letters ) ],
						'applied_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-' . wp_rand( 1, 30 ) . ' days' ) ),
					)
				);

				if ( $wpdb->insert_id ) {
					++$app_count;
				}
			}
		}

		// Create companies table entries.
		foreach ( $company_names as $idx => $name ) {
			$slug = sanitize_title( $name );
			$wpdb->insert(
				$table_companies,
				array(
					'name'       => $name,
					'slug'       => $slug,
					'industry'   => $industries[ $idx ],
					'size'       => '51-200',
					'about'      => $descriptions[ $idx ],
					'created_at' => current_time( 'mysql' ),
				)
			);
		}

		return true;
	}

	/**
	 * Remove demo data created by generate_demo_data().
	 * Deletes demo companies, demo jobs (by seeded slug), their applications,
	 * and the demo employer/seeker users (@demo.com accounts).
	 *
	 * @return int Number of demo users removed.
	 */
	public function clear_demo_data(): int {
		global $wpdb;
		$table_jobs      = $wpdb->prefix . 'zeko_jobs';
		$table_apps      = $wpdb->prefix . 'zeko_job_applications';
		$table_companies = $wpdb->prefix . 'zeko_companies';
		$table_views     = $wpdb->prefix . 'zeko_job_views_log';

		$job_titles = array(
			'Senior Frontend Developer',
			'Backend Engineer',
			'UX Designer',
			'DevOps Engineer',
			'Product Manager',
			'Data Analyst',
			'Marketing Specialist',
			'QA Engineer',
			'Content Writer',
			'Sales Representative',
			'Mobile Developer',
			'HR Coordinator',
			'Financial Analyst',
			'Graphic Designer',
			'Technical Writer',
		);
		$slugs      = array_map( 'sanitize_title', $job_titles );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Demo applications + jobs (identify jobs by seeded slug).
		foreach ( $slugs as $slug ) {
			$job_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_jobs} WHERE slug = %s LIMIT 1", $slug ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( ! $job_id ) {
				continue;
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_apps} WHERE job_id = %d", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_views} WHERE job_id = %d", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_jobs} WHERE id = %d", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Demo companies (seeded slugs).
		$company_names = array( 'techcorp', 'dataflow-inc', 'cloudnine-solutions', 'greenleaf-corp', 'innovate-lab' );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$company_slugs = array_map( 'sanitize_title', $company_names );
		if ( ! empty( $company_slugs ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $company_slugs ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_companies} WHERE slug IN ({$placeholders})", ...$company_slugs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		}

		// Demo users (tagged by zeko_demo_user meta). Never delete the currently logged-in admin.
		$demo_users = get_users(
			array(
				'meta_key'   => 'zeko_demo_user',
				'meta_value' => '1',
				'fields'     => 'ID',
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Legacy accounts seeded before the zeko_demo_user tag existed.
		$legacy_users = get_users(
			array(
				'meta_key'   => 'demo_seeded',
				'meta_value' => '1',
				'fields'     => 'ID',
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$demo_users = array_unique( array_merge( $demo_users, $legacy_users ) );

		foreach ( $demo_users as $user_id ) {
			if ( get_current_user_id() !== (int) $user_id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( (int) $user_id );
			}
		}

		// Fallback: wipe any @demo.com accounts not tagged above.
		$by_email = get_users(
			array(
				'search'         => '*@demo.com',
				'search_columns' => array( 'user_email' ),
				'fields'         => 'ID',
			)
		);
		foreach ( $by_email as $user_id ) {
			if ( get_current_user_id() !== (int) $user_id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( (int) $user_id );
			}
		}

		return count( $demo_users );
	}

	/**
	 * Register the Jobs admin menu + submenus.
	 */
	public function add_jobs_admin_pages(): void {
		add_submenu_page(
			'zeko-jobs',
			__( 'Jobs', 'zeko-jobs' ),
			__( 'Jobs', 'zeko-jobs' ),
			'manage_options',
			'zeko-jobs-manage',
			array( $this, 'jobs_list_page' )
		);

		add_submenu_page(
			'zeko-jobs',
			__( 'Pending Jobs', 'zeko-jobs' ),
			__( 'Pending Jobs', 'zeko-jobs' ),
			'manage_options',
			'zeko-jobs-pending',
			array( $this, 'pending_jobs_page' )
		);

		add_submenu_page(
			'zeko-jobs',
			__( 'Companies', 'zeko-jobs' ),
			__( 'Companies', 'zeko-jobs' ),
			'manage_options',
			'zeko-jobs-companies',
			array( $this, 'companies_page' )
		);

		add_submenu_page(
			'zeko-jobs',
			__( 'Reports', 'zeko-jobs' ),
			__( 'Reports', 'zeko-jobs' ) . $this->reports_menu_badge(),
			'manage_options',
			'zeko-jobs-reports',
			array( $this, 'reports_page' )
		);
	}

	/**
	 * Pending-flags count badge for the Reports menu title.
	 */
	private function reports_menu_badge(): string {
		$pending = Zeko_Jobs_DB::get_instance()->count_job_flags_by_status( 'pending' );
		if ( $pending <= 0 ) {
			return '';
		}
		return ' <span class="awaiting-mod" style="display:inline-block;vertical-align:top;box-sizing:border-box;margin:1px 0 -1px 2px;padding:0 5px;min-width:18px;height:18px;border-radius:9px;background-color:#d63638;color:#fff;font-size:11px;line-height:1.6;text-align:center;z-index:26;">' . (int) $pending . '</span>';
	}

	/**
	 * Render the reported-content moderation queue.
	 */
	public function reports_page(): void {
		$db = Zeko_Jobs_DB::get_instance();

		if ( isset( $_GET['flag_action'] ) && isset( $_GET['flag_id'] ) && check_admin_referer( 'zeko_flag_' . (int) $_GET['flag_id'] ) ) {
			$flag_action = sanitize_text_field( wp_unslash( $_GET['flag_action'] ) );
			$flag_id     = absint( $_GET['flag_id'] );

			if ( in_array( $flag_action, array( 'resolved', 'dismissed' ), true ) && $flag_id ) {
				if ( $db->update_job_flag_status( $flag_id, $flag_action, get_current_user_id() ) ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Report updated.', 'zeko-jobs' ) . '</p></div>';
				}
			}
		}

		$tab    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$tabs   = array(
			'pending'   => __( 'Pending', 'zeko-jobs' ),
			'resolved'  => __( 'Resolved', 'zeko-jobs' ),
			'dismissed' => __( 'Dismissed', 'zeko-jobs' ),
		);
		$status = array_key_exists( $tab, $tabs ) ? $tab : 'pending';

		$flags = $db->get_job_flags( $status );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Reported Content', 'zeko-jobs' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px;">';
		foreach ( $tabs as $slug => $label ) {
			$count = $db->count_job_flags_by_status( $slug );
			$cls   = $slug === $status ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=zeko-jobs-reports&status=' . $slug ) ) . '">' . esc_html( $label ) . ' (' . (int) $count . ')</a>';
		}
		echo '</nav>';

		if ( empty( $flags ) ) {
			echo '<p>' . esc_html__( 'No reports in this queue.', 'zeko-jobs' ) . '</p>';
			echo '</div>';
			return;
		}

		$reason_labels = array(
			'inappropriate' => __( 'Inappropriate content', 'zeko-jobs' ),
			'scam'          => __( 'Scam or fraudulent', 'zeko-jobs' ),
			'misleading'    => __( 'Misleading or inaccurate', 'zeko-jobs' ),
			'expired'       => __( 'Listing already filled or expired', 'zeko-jobs' ),
			'other'         => __( 'Other', 'zeko-jobs' ),
		);

		echo '<table class="widefat striped" style="background:#fff;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Job', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Reporter', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Reported', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'zeko-jobs' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $flags as $flag ) {
			$flag_id   = (int) $flag['id'];
			$job_title = $flag['job_title'] ?: __( '(deleted)', 'zeko-jobs' );
			$job_link  = $flag['job_slug'] ? '<a href="' . esc_url( home_url( '/jobs/' . $flag['job_slug'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $job_title ) . '</a>' : esc_html( $job_title );

			$reporter = '';
			if ( (int) $flag['user_id'] > 0 ) {
				$ru       = get_userdata( (int) $flag['user_id'] );
				$reporter = $ru ? $ru->display_name : '#' . $flag['user_id'];
			}

			$base = admin_url( 'admin.php?page=zeko-jobs-reports&status=' . $status );

			echo '<tr>';
			echo '<td><strong>' . wp_kses_post( $job_link ) . '</strong><br><small>#' . (int) $flag['job_id'] . '</small></td>';
			echo '<td>' . esc_html( $reporter ) . '</td>';
			echo '<td>' . esc_html( $reason_labels[ $flag['reason'] ] ?? $flag['reason'] ) . '</td>';
			echo '<td>' . esc_html( $flag['details'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $flag['created_at'] ) . '</td>';
			echo '<td>';
			if ( 'pending' === $status ) {
				echo '<a class="button button-small button-primary" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'flag_action' => 'resolved',
								'flag_id'     => $flag_id,
							),
							$base
						),
						'zeko_flag_' . $flag_id
					)
				) . '">' . esc_html__( 'Resolve', 'zeko-jobs' ) . '</a> ';
				echo '<a class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Dismiss this report?', 'zeko-jobs' ) ) . '\')" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'flag_action' => 'dismissed',
								'flag_id'     => $flag_id,
							),
							$base
						),
						'zeko_flag_' . $flag_id
					)
				) . '">' . esc_html__( 'Dismiss', 'zeko-jobs' ) . '</a>';
			} else {
				$rb = (int) $flag['resolved_by'];
				$ru = $rb ? get_userdata( $rb ) : null;
				echo '<span class="description">' . esc_html__( 'by', 'zeko-jobs' ) . ' ' . esc_html( $ru ? $ru->display_name : '#' . $rb ) . ' &mdash; ' . esc_html( $flag['resolved_at'] ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
	/**
	 * Render jobs list CRUD (basic).
	 */
	/**
	 * Stream the current jobs list as a CSV download (admin only).
	 */
	public function handle_csv_export(): void {
		if ( empty( $_GET['export'] ) || 'csv' !== sanitize_text_field( wp_unslash( $_GET['export'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export jobs.', 'zeko-jobs' ) );
		}

		check_admin_referer( 'zeko_jobs_export_csv' );

		$db   = Zeko_Jobs_DB::get_instance();
		$jobs = $db->get_jobs(
			array(
				'status' => 'publish',
				'limit'  => 2000,
			)
		);

		$columns = array( 'ID', 'Title', 'Location', 'Type', 'Salary Min', 'Salary Max', 'Featured', 'Created' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zeko-jobs-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, $columns );

		foreach ( $jobs as $job ) {
			fputcsv(
				$out,
				array(
					$job['id'],
					$job['title'],
					$job['location'],
					$job['type'],
					$job['salary_min'],
					$job['salary_max'],
					$job['is_featured'],
					$job['created_at'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Jobs list page.
	 */
	public function jobs_list_page(): void {
		$db = Zeko_Jobs_DB::get_instance();

		// Basic bulk/status actions placeholder.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;

		if ( $action && $job_id && check_admin_referer( 'zeko_job_admin_manage_' . $action, 'nonce' ) ) {
			switch ( $action ) {
				case 'delete':
					$db->delete_job( $job_id, 0 );
					break;
				case 'publish':
					$db->update_job(
						$job_id,
						array(
							'status'     => 'publish',
							'updated_at' => current_time( 'mysql' ),
						)
					);
					break;
				case 'draft':
					$db->update_job(
						$job_id,
						array(
							'status'     => 'draft',
							'updated_at' => current_time( 'mysql' ),
						)
					);
					break;
			}
		}

		$jobs = $db->get_jobs(
			array(
				'status' => 'publish',
				'limit'  => 200,
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zeko Jobs', 'zeko-jobs' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Basic job management (list + status + delete).', 'zeko-jobs' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url(
			wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'zeko-jobs-manage',
						'export' => 'csv',
					),
					admin_url( 'admin.php' )
				),
				'zeko_jobs_export_csv'
			)
		) . '">' . esc_html__( 'Export CSV', 'zeko-jobs' ) . '</a></p>';

		echo '<table class="widefat striped" style="background:#fff;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Payment', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'zeko-jobs' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $jobs ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No jobs found.', 'zeko-jobs' ) . '</td></tr>';
		} else {
			foreach ( $jobs as $job ) {
				$job_id_row = (int) $job['id'];
				echo '<tr>';
				echo '<td>' . esc_html( $job_id_row ) . '</td>';
				echo '<td>' . esc_html( $job['title'] ) . '</td>';
				echo '<td>' . esc_html( $job['location'] ) . '</td>';
				echo '<td>' . esc_html( $job['type'] ) . '</td>';
				echo '<td>' . esc_html( $job['status'] ) . '</td>';
				$payment_status = ! empty( $job['payment_status'] ) ? $job['payment_status'] : 'free';
				echo '<td><span style="padding:2px 8px;border-radius:12px;font-size:12px;background:' . esc_attr( 'paid' === $payment_status ? '#d1fae5' : ( 'pending' === $payment_status ? '#fef3cd' : '#e2e8f0' ) ) . ';color:' . esc_attr( 'paid' === $payment_status ? '#065f46' : ( 'pending' === $payment_status ? '#b45309' : '#475569' ) ) . ';">' . esc_html( ucfirst( $payment_status ) ) . '</span></td>';
				echo '<td>' . esc_html( $job['created_at'] ) . '</td>';
				echo '<td>';

				$action_url = admin_url( 'admin.php' );

				echo '<a class="button button-small" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'   => 'zeko-jobs-manage',
								'action' => 'publish',
								'job_id' => $job_id_row,
							),
							$action_url
						),
						'zeko_job_admin_manage_publish',
						'nonce'
					)
				) . '">' . esc_html__( 'Publish', 'zeko-jobs' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'   => 'zeko-jobs-manage',
								'action' => 'draft',
								'job_id' => $job_id_row,
							),
							$action_url
						),
						'zeko_job_admin_manage_draft',
						'nonce'
					)
				) . '">' . esc_html__( 'Draft', 'zeko-jobs' ) . '</a> ';
				echo '<a class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this job?', 'zeko-jobs' ) ) . '\')" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'   => 'zeko-jobs-manage',
								'action' => 'delete',
								'job_id' => $job_id_row,
							),
							$action_url
						),
						'zeko_job_admin_manage_delete',
						'nonce'
					)
				) . '">' . esc_html__( 'Delete', 'zeko-jobs' ) . '</a>';

				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Render the companies admin page with a featured (premium highlight) toggle.
	 */
	public function companies_page(): void {
		$db = Zeko_Jobs_DB::get_instance();

		if ( isset( $_POST['zeko_company_feature'] ) && check_admin_referer( 'zeko_companies_page' ) ) {
			$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
			$feature    = ! empty( $_POST['feature'] );

			if ( $company_id && $db->set_company_featured( $company_id, $feature ) ) {
				echo '<div class="notice notice-success"><p>' .
					( $feature ? esc_html__( 'Company added to the featured homepage section.', 'zeko-jobs' ) : esc_html__( 'Company removed from the featured homepage section.', 'zeko-jobs' ) ) .
					'</p></div>';
			}
		}

		$companies = $db->get_companies( array( 'limit' => 500 ) );
		$featured  = array_column( $db->get_featured_companies( 20 ), 'id' );
		$featured  = array_map( 'intval', $featured );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zeko Jobs — Companies', 'zeko-jobs' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Feature premium employers to highlight them on the jobs homepage.', 'zeko-jobs' ) . '</p>';

		echo '<table class="widefat striped" style="background:#fff;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Industry', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Verified', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Featured', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'zeko-jobs' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $companies ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No companies found.', 'zeko-jobs' ) . '</td></tr>';
		} else {
			foreach ( $companies as $company ) {
				$company_id  = (int) $company['id'];
				$is_featured = in_array( $company_id, $featured, true );
				echo '<tr>';
				echo '<td>' . esc_html( $company_id ) . '</td>';
				echo '<td>' . esc_html( $company['name'] ) . '</td>';
				echo '<td>' . esc_html( $company['industry'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $company['location'] ?? '' ) . '</td>';
				echo '<td>' . ( ! empty( $company['is_verified'] ) ? esc_html__( 'Yes', 'zeko-jobs' ) : esc_html__( 'No', 'zeko-jobs' ) ) . '</td>';
				echo '<td>' . ( $is_featured ? '<span class="dashicons dashicons-star-filled" style="color:#f59e0b;" aria-label="' . esc_attr__( 'Featured', 'zeko-jobs' ) . '"></span>' : '—' ) . '</td>';
				echo '<td>';
				?>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'zeko_companies_page' ); ?>
					<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>">
					<input type="hidden" name="feature" value="<?php echo esc_attr( $is_featured ? '0' : '1' ); ?>">
					<button class="button button-small" type="submit" name="zeko_company_feature" value="1">
						<?php echo $is_featured ? esc_html__( 'Unfeature', 'zeko-jobs' ) : esc_html__( 'Feature', 'zeko-jobs' ); ?>
					</button>
				</form>
				<?php
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Render pending jobs admin page with approval/rejection actions.
	 */
	public function pending_jobs_page(): void {
		$db = Zeko_Jobs_DB::get_instance();

		if ( isset( $_POST['zeko_pending_jobs_action'] ) && check_admin_referer( 'zeko_pending_jobs_bulk' ) ) {
			$bulk_action = sanitize_text_field( wp_unslash( $_POST['zeko_pending_jobs_action'] ) );
			$job_ids     = isset( $_POST['job_ids'] ) ? array_map( 'absint', (array) $_POST['job_ids'] ) : array();
			$job_ids     = array_filter( $job_ids );

			if ( ! empty( $job_ids ) ) {
				switch ( $bulk_action ) {
					case 'approve':
						$count = 0;
						foreach ( $job_ids as $jid ) {
							if ( $db->approve_job( $jid ) ) {
								++$count;
							}
						}
						echo '<div class="notice notice-success"><p>' . sprintf(
							/* translators: %d: number of jobs approved */
							esc_html__( '%d job(s) approved.', 'zeko-jobs' ),
							absint( $count )
						) . '</p></div>';
						break;
					case 'reject':
						$reason = isset( $_POST['bulk_rejection_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk_rejection_reason'] ) ) : '';
						$count  = 0;
						foreach ( $job_ids as $jid ) {
							if ( $db->reject_job( $jid, $reason ) ) {
								++$count;
							}
						}
						echo '<div class="notice notice-success"><p>' . sprintf(
							/* translators: %d: number of jobs rejected */
							esc_html__( '%d job(s) rejected.', 'zeko-jobs' ),
							absint( $count )
						) . '</p></div>';
						break;
					case 'delete':
						$count = 0;
						foreach ( $job_ids as $jid ) {
							if ( $db->delete_job( $jid, 0 ) ) {
								++$count;
							}
						}
						echo '<div class="notice notice-success"><p>' . sprintf(
							/* translators: %d: number of jobs deleted */
							esc_html__( '%d job(s) deleted.', 'zeko-jobs' ),
							absint( $count )
						) . '</p></div>';
						break;
				}
			}
		}

		if ( isset( $_GET['zeko_single_action'], $_GET['job_id'] ) && check_admin_referer( 'zeko_pending_single_' . absint( wp_unslash( $_GET['job_id'] ) ) ) ) {
			$single_action = sanitize_text_field( wp_unslash( $_GET['zeko_single_action'] ) );
			$job_id        = isset( $_GET['job_id'] ) ? absint( wp_unslash( $_GET['job_id'] ) ) : 0;

			if ( 'approve' === $single_action && $job_id ) {
				if ( $db->approve_job( $job_id ) ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Job approved.', 'zeko-jobs' ) . '</p></div>';
				}
			} elseif ( 'reject' === $single_action && $job_id ) {
				$reason = isset( $_GET['reason'] ) ? sanitize_textarea_field( wp_unslash( $_GET['reason'] ) ) : '';
				if ( $db->reject_job( $job_id, $reason ) ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Job rejected.', 'zeko-jobs' ) . '</p></div>';
				}
			} elseif ( 'delete' === $single_action && $job_id ) {
				if ( $db->delete_job( $job_id, 0 ) ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Job deleted.', 'zeko-jobs' ) . '</p></div>';
				}
			}
		}

		$pending_jobs = $db->get_pending_jobs();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Pending Jobs', 'zeko-jobs' ) . '</h1>';

		if ( empty( $pending_jobs ) ) {
			echo '<p>' . esc_html__( 'No pending jobs to review.', 'zeko-jobs' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'zeko_pending_jobs_bulk' );

		echo '<div id="zeko-reject-bulk-wrap" style="display:none;margin:10px 0;">';
		echo '<label><strong>' . esc_html__( 'Rejection reason (bulk):', 'zeko-jobs' ) . '</strong></label><br>';
		echo '<textarea name="bulk_rejection_reason" rows="3" cols="60" class="large-text"></textarea>';
		echo '</div>';

		echo '<div class="tablenav top"><div class="alignleft actions">';
		echo '<select name="zeko_pending_jobs_action">';
		echo '<option value="">' . esc_html__( 'Bulk Actions', 'zeko-jobs' ) . '</option>';
		echo '<option value="approve">' . esc_html__( 'Approve', 'zeko-jobs' ) . '</option>';
		echo '<option value="reject">' . esc_html__( 'Reject', 'zeko-jobs' ) . '</option>';
		echo '<option value="delete">' . esc_html__( 'Delete', 'zeko-jobs' ) . '</option>';
		echo '</select>';
		echo '<input type="submit" class="button action" value="' . esc_attr__( 'Apply', 'zeko-jobs' ) . '">';
		echo '</div></div>';

		echo '<table class="widefat striped" style="background:#fff;">';
		echo '<thead><tr>';
		echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></td>';
		echo '<th>' . esc_html__( 'ID', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Employer', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'zeko-jobs' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'zeko-jobs' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $pending_jobs as $job ) {
			$jid = (int) $job['id'];
			echo '<tr>';
			echo '<th class="check-column"><input type="checkbox" name="job_ids[]" value="' . esc_attr( $jid ) . '"></th>';
			echo '<td>' . esc_html( $jid ) . '</td>';
			echo '<td><strong>' . esc_html( $job['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( $job['employer_name'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $job['location'] ) . '</td>';
			echo '<td>' . esc_html( $job['type'] ) . '</td>';
			echo '<td>' . esc_html( $job['created_at'] ) . '</td>';
			echo '<td>';

			$base_url = admin_url( 'admin.php?page=zeko-jobs-pending' );
			echo '<a class="button button-small" href="' . esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'zeko_single_action' => 'approve',
							'job_id'             => $jid,
						),
						$base_url
					),
					'zeko_pending_single_' . $jid
				)
			) . '">' . esc_html__( 'Approve', 'zeko-jobs' ) . '</a> ';
			echo '<a class="button button-small zeko-reject-btn" data-job-id="' . esc_attr( $jid ) . '" href="#">' . esc_html__( 'Reject', 'zeko-jobs' ) . '</a> ';
			echo '<a class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this job?', 'zeko-jobs' ) ) . '\')" href="' . esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'zeko_single_action' => 'delete',
							'job_id'             => $jid,
						),
						$base_url
					),
					'zeko_pending_single_' . $jid
				)
			) . '">' . esc_html__( 'Delete', 'zeko-jobs' ) . '</a>';

			echo '<div class="zeko-reject-form" id="zeko-reject-form-' . esc_attr( $jid ) . '" style="display:none;margin-top:8px;">';
			echo '<textarea class="zeko-reject-reason" rows="2" cols="40" placeholder="' . esc_attr__( 'Rejection reason (optional)', 'zeko-jobs' ) . '"></textarea><br>';
			echo '<button type="button" class="button button-small zeko-confirm-reject" data-job-id="' . esc_attr( $jid ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'zeko_pending_single_' . $jid ) ) . '" data-base="' . esc_url( $base_url ) . '">' . esc_html__( 'Confirm Reject', 'zeko-jobs' ) . '</button> ';
			echo '<button type="button" class="button button-small zeko-cancel-reject" data-job-id="' . esc_attr( $jid ) . '">' . esc_html__( 'Cancel', 'zeko-jobs' ) . '</button>';
			echo '</div>';

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</form>';

		?>
		<script>
		jQuery(document).ready(function($) {
			$('#cb-select-all-1').on('change', function() {
				$('input[name="job_ids[]"]').prop('checked', this.checked);
			});

			$('select[name="zeko_pending_jobs_action"]').on('change', function() {
				if ($(this).val() === 'reject') {
					$('#zeko-reject-bulk-wrap').show();
				} else {
					$('#zeko-reject-bulk-wrap').hide();
				}
			});

			$('.zeko-reject-btn').on('click', function(e) {
				e.preventDefault();
				var jobId = $(this).data('job-id');
				$('#zeko-reject-form-' + jobId).show();
			});

			$('.zeko-cancel-reject').on('click', function(e) {
				e.preventDefault();
				var jobId = $(this).data('job-id');
				$('#zeko-reject-form-' + jobId).hide();
			});

			$('.zeko-confirm-reject').on('click', function(e) {
				e.preventDefault();
				var jobId = $(this).data('job-id');
				var nonce = $(this).data('nonce');
				var baseUrl = $(this).data('base');
				var reason = $('#zeko-reject-form-' + jobId + ' .zeko-reject-reason').val();
				if (confirm('<?php echo esc_js( __( 'Are you sure you want to reject this job?', 'zeko-jobs' ) ); ?>')) {
					var url = baseUrl + '&zeko_single_action=reject&job_id=' + jobId + '&_wpnonce=' + nonce;
					if (reason) {
						url += '&reason=' + encodeURIComponent(reason);
					}
					window.location.href = url;
				}
			});
		});
		</script>
		<?php

		echo '</div>';
	}
}
