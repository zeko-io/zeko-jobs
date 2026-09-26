<?php
/**
 * Auto-response system for Zeko Jobs.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Auto_Response. */
final class Zeko_Jobs_Auto_Response {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init.
	 */
	public function init(): void {
		add_action( 'zeko_job_application_submitted', array( $this, 'send_auto_response' ), 20, 3 );
	}

	/**
	 * Send auto-response to applicant if employer has enabled it.
	 *
	 * @param int $application_id Application id.
	 * @param int $job_id Job id.
	 * @param int $seeker_id Seeker id.
	 */
	public function send_auto_response( int $application_id, int $job_id, int $seeker_id ): void {
		if ( ! $application_id || ! $job_id || ! $seeker_id ) {
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job( $job_id );
		if ( ! $job ) {
			return;
		}

		$employer_id = (int) $job['employer_id'];
		$settings    = $this->get_settings( $employer_id );

		if ( empty( $settings['enabled'] ) || empty( $settings['subject'] ) || empty( $settings['body'] ) ) {
			return;
		}

		$seeker = get_userdata( $seeker_id );
		if ( ! $seeker ) {
			return;
		}

		$employer      = get_userdata( $employer_id );
		$employer_name = $employer ? $employer->display_name : get_bloginfo( 'name' );

		$search  = array( '{seeker_name}', '{job_title}', '{employer_name}', '{application_date}' );
		$replace = array(
			$seeker->display_name,
			$job['title'],
			$employer_name,
			date_i18n( get_option( 'date_format' ) ),
		);

		$subject = str_replace( $search, $replace, $settings['subject'] );
		$body    = str_replace( $search, $replace, $settings['body'] );

		$html = '<div style="white-space:pre-wrap;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;line-height:1.6;color:#475569;">' . nl2br( esc_html( $body ) ) . '</div>';

		Zeko_Jobs_Email_Renderer::get_instance()->send_styled(
			$seeker->user_email,
			$subject,
			$html
		);
	}

	/**
	 * Settings.
	 *
	 * @param int $employer_id Employer id.
	 */
	public function get_settings( int $employer_id ): array {
		$defaults = array(
			'enabled' => 0,
			'subject' => __( 'Thank you for your application', 'zeko-jobs' ),
			'body'    => __( "Hi {seeker_name},\n\nThank you for applying for the {job_title} position at {employer_name}. We have received your application and will review it shortly.\n\nBest regards,\n{employer_name}", 'zeko-jobs' ),
		);
		$saved    = get_user_meta( $employer_id, 'zeko_auto_response_settings', true );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save settings.
	 *
	 * @param int   $employer_id Employer id.
	 * @param array $settings Settings.
	 */
	public function save_settings( int $employer_id, array $settings ): void {
		$current = $this->get_settings( $employer_id );
		$merged  = wp_parse_args( $settings, $current );
		update_user_meta( $employer_id, 'zeko_auto_response_settings', $merged );
	}
}
