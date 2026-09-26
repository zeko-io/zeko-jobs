<?php
/**
 * Core class for Zeko Jobs plugin.
 *
 * Implements a Singleton pattern to manage initialization,
 * load text domain, and hook registration.
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main plugin class.
 */
final class Zeko_Jobs {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Zeko_Jobs
	 */
	public static function get_instance(): Zeko_Jobs {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Set up hooks.
	 */
	private function __construct() {
		// Load textdomain.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Init hook.
		add_action( 'init', array( $this, 'init_plugin' ) );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'zeko-jobs' );
	}

	/**
	 * Activation routine.
	 */
	public function activate_plugin(): void {
		$db_file = plugin_dir_path( __FILE__ ) . 'db/class-zeko-jobs-db.php';
		if ( file_exists( $db_file ) ) {
			require_once $db_file;
			Zeko_Jobs_DB::get_instance()->create_tables();
		}

		$this->create_pages();

		if ( ! wp_next_scheduled( 'zeko_jobs_db_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_jobs_db_cleanup' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Init routine.
	 */
	public function init_plugin(): void {
		// Include class files if available.
		$db_file     = plugin_dir_path( __FILE__ ) . 'db/class-zeko-jobs-db.php';
		$admin_file  = plugin_dir_path( __FILE__ ) . 'admin/class-zeko-jobs-admin.php';
		$public_file = plugin_dir_path( __FILE__ ) . 'public/class-zeko-jobs-public.php';

		if ( file_exists( $db_file ) ) {
			require_once $db_file;
			Zeko_Jobs_DB::get_instance()->maybe_upgrade();
		}

		if ( file_exists( $admin_file ) ) {
			require_once $admin_file;
			Zeko_Jobs_Admin::get_instance();
		}

		// Shared rate limiter (delegates to zeko-core when present).
		$rate_limiter_file = plugin_dir_path( __FILE__ ) . 'class-zeko-jobs-rate-limiter.php';
		if ( file_exists( $rate_limiter_file ) ) {
			require_once $rate_limiter_file;
		}

		$public_sub_files = array(
			'public/class-zeko-jobs-ajax.php',
			'public/class-zeko-jobs-shortcodes.php',
			'public/class-zeko-jobs-templates.php',
			'public/class-zeko-jobs-seo.php',
			'public/class-zeko-jobs-auto-response.php',
		);
		foreach ( $public_sub_files as $sub_file ) {
			$sub_path = plugin_dir_path( __FILE__ ) . $sub_file;
			if ( file_exists( $sub_path ) ) {
				require_once $sub_path;
			}
		}

		if ( file_exists( $public_file ) ) {
			require_once $public_file;
			Zeko_Jobs_Public::get_instance();
		}

		$this->register_blocks();

		$digest_file = plugin_dir_path( __FILE__ ) . 'public/class-zeko-jobs-digest.php';
		if ( file_exists( $digest_file ) ) {
			require_once $digest_file;
			Zeko_Jobs_Digest::get_instance()->init();
			Zeko_Jobs_Digest::get_instance()->schedule_digest();
		}

		// Ecosystem integration (dashboard, activity feed, profile, payments).
		$ecosystem_file = plugin_dir_path( __FILE__ ) . 'ecosystem/class-zeko-jobs-ecosystem.php';
		if ( file_exists( $ecosystem_file ) ) {
			require_once $ecosystem_file;
			Zeko_Jobs_Ecosystem::get_instance();
		}

		// Email notifications.
		$renderer_file = plugin_dir_path( __FILE__ ) . 'email/class-zeko-jobs-email-renderer.php';
		if ( file_exists( $renderer_file ) ) {
			require_once $renderer_file;
		}
		$prefs_file = plugin_dir_path( __FILE__ ) . 'email/class-zeko-jobs-email-preferences.php';
		if ( file_exists( $prefs_file ) ) {
			require_once $prefs_file;
			Zeko_Jobs_Email_Preferences::get_instance();
		}
		$email_file = plugin_dir_path( __FILE__ ) . 'email/class-zeko-jobs-email.php';
		if ( file_exists( $email_file ) ) {
			require_once $email_file;
			Zeko_Jobs_Email::get_instance();
		}

		// LinkedIn OAuth integration.
		$linkedin_file = plugin_dir_path( __FILE__ ) . 'class-zeko-jobs-linkedin.php';
		if ( file_exists( $linkedin_file ) ) {
			require_once $linkedin_file;
			Zeko_Jobs_Linkedin::get_instance();
		}

		// Job expiration system.
		$expiration_file = plugin_dir_path( __FILE__ ) . 'public/class-zeko-jobs-expiration.php';
		if ( file_exists( $expiration_file ) ) {
			require_once $expiration_file;
			Zeko_Jobs_Expiration::get_instance()->init();
			Zeko_Jobs_Expiration::get_instance()->schedule_events();
		}

		$auto_response_file = plugin_dir_path( __FILE__ ) . 'public/class-zeko-jobs-auto-response.php';
		if ( file_exists( $auto_response_file ) ) {
			require_once $auto_response_file;
			Zeko_Jobs_Auto_Response::get_instance()->init();
		}

		// Job alerts system.
		$alerts_file = plugin_dir_path( __FILE__ ) . 'public/class-zeko-jobs-alerts.php';
		if ( file_exists( $alerts_file ) ) {
			require_once $alerts_file;
			Zeko_Jobs_Alerts::get_instance()->init();
			Zeko_Jobs_Alerts::get_instance()->schedule_events();
		}

		// Daily DB cleanup (old logs, notifications, resolved flags).
		if ( ! wp_next_scheduled( 'zeko_jobs_db_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_jobs_db_cleanup' );
		}
		add_action( 'zeko_jobs_db_cleanup', array( Zeko_Jobs_DB::get_instance(), 'run_cleanup' ) );

		// Messaging system (migrated from theme).
		if ( ! function_exists( 'zeko_send_message' ) ) {
			$messaging_file = plugin_dir_path( __FILE__ ) . 'public/class-zeko-jobs-messaging.php';
			if ( file_exists( $messaging_file ) ) {
				require_once $messaging_file;
				Zeko_Jobs_Messaging::get_instance();
			}
		}

		// Migration support — register WP Job Manager migrator.
		$base_file = plugin_dir_path( __FILE__ ) . '../class-zeko-migrator-base.php';
		if ( file_exists( $base_file ) ) {
			require_once $base_file;
		}
		$migrator_file = plugin_dir_path( __FILE__ ) . 'migrator/class-zeko-migrate-wpjm.php';
		if ( file_exists( $migrator_file ) ) {
			require_once $migrator_file;
			add_filter(
				'zbp_available_migrators',
				function ( array $migrators ): array {
					$migrators['wp_job_manager'] = array(
						'class'   => 'Zeko_Migrate_WPJM',
						'label'   => 'WP Job Manager',
						'package' => 'zeko-jobs',
					);
					return $migrators;
				}
			);
		}
	}

	/**
	 * Create core pages if they do not exist.
	 */
	private function create_pages(): void {
		$pages = array(
			array(
				'title'   => __( 'Jobs', 'zeko-jobs' ),
				'slug'    => 'jobs',
				'content' => '[zeko_jobs_archive]',
			),
			array(
				'title'   => __( 'Post a Job', 'zeko-jobs' ),
				'slug'    => 'post-a-job',
				'content' => '[zeko_jobs_post_form]',
			),
			array(
				'title'   => __( 'Job Dashboard', 'zeko-jobs' ),
				'slug'    => 'job-dashboard',
				'content' => '[zeko_jobs_dashboard]',
			),
			array(
				'title'   => __( 'Messages', 'zeko-jobs' ),
				'slug'    => 'messages',
				'content' => '[zeko_messaging]',
			),
		);

		foreach ( $pages as $page ) {
			$existing = class_exists( 'Zeko_Core_Helpers' )
				? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $page['slug'] )
				: get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( ! $existing ) {
				$job_page_id = wp_insert_post(
					array(
						'post_title'   => $page['title'],
						'post_name'    => $page['slug'],
						'post_content' => $page['content'],
						'post_status'  => 'publish',
						'post_type'    => 'page',
					)
				);
				if ( ! is_wp_error( $job_page_id ) && 'messages' !== $page['slug'] ) {
					if ( function_exists( 'zeko_mark_plugin_page' ) ) {
						zeko_mark_plugin_page( $job_page_id, 'jobs' );
					}
				}
			}
		}
	}

	/**
	 * Get a user's job role.
	 *
	 * @return string 'seeker', 'employer', or empty string.
	 * @param int $user_id User ID.
	 */
	public function get_user_job_role( $user_id ): string {
		return (string) get_user_meta( $user_id, 'zeko_job_role', true );
	}

	/**
	 * Set a user's job role.
	 *
	 * @return bool True on success, false on failure.
	 * @param int    $user_id User ID.
	 * @param string $role 'seeker' or 'employer'.
	 */
	public function set_user_job_role( $user_id, $role ): bool {
		$allowed = array( 'seeker', 'employer' );
		if ( ! in_array( $role, $allowed, true ) ) {
			return false;
		}
		return update_user_meta( $user_id, 'zeko_job_role', $role );
	}

	/**
	 * Get a user's company logo URL.
	 *
	 * @return string Logo URL or empty string.
	 * @param int $user_id User ID.
	 */
	public function get_user_company_logo( $user_id ): string {
		$attachment_id = (int) get_user_meta( $user_id, 'zeko_company_logo_id', true );
		if ( ! $attachment_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		return $url ?: '';
	}

	/**
	 * Get a user's resume URL.
	 *
	 * @return string Resume URL or empty string.
	 * @param int $user_id User ID.
	 */
	public function get_user_resume( $user_id ): string {
		return (string) get_user_meta( $user_id, 'zeko_resume', true );
	}

	/**
	 * Log an activity to the Zeko activity feed.
	 *
	 * @param int       $user_id User ID.
	 * @param string    $action Activity type slug.
	 * @param string    $message Activity message.
	 * @param int|float $item_id Related item ID (job/application).
	 * @param array     $meta Optional meta data.
	 */
	public function log_activity( $user_id, $action, $message, $item_id = 0, $meta = array() ): void {
		if ( class_exists( 'Zeko_Core_Activity' ) ) {
			if ( empty( $meta['module'] ) ) {
				$meta['module'] = 'zeko-jobs';
			}
			Zeko_Core_Activity::get_instance()->log( (int) $user_id, $action, $message, (int) $item_id, $meta );
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$zeko_db->log_activity( (int) $user_id, $action, $message, (int) $item_id, $meta );
	}

	/**
	 * Register Gutenberg blocks.
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
			return;
		}

		$blocks = array(
			'zeko-jobs-archive' => array(
				'path'   => 'blocks/zeko-jobs-archive/block.json',
				'render' => 'zeko_jobs_archive_block_render',
			),
			'zeko-job-featured' => array(
				'path'   => 'blocks/zeko-job-featured/block.json',
				'render' => 'zeko_jobs_featured_block_render',
			),
		);

		foreach ( $blocks as $config ) {
			$full_path = plugin_dir_path( __FILE__ ) . $config['path'];
			if ( file_exists( $full_path ) ) {
				$render_file = plugin_dir_path( __FILE__ ) . str_replace( 'block.json', 'index.php', $config['path'] );
				if ( file_exists( $render_file ) ) {
					require_once $render_file;
				}
				$args = array();
				if ( ! empty( $config['render'] ) ) {
					$args['render_callback'] = $config['render'];
				}
				register_block_type_from_metadata( $full_path, $args );
			}
		}
	}
}
