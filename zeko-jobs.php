<?php
/**
 * Plugin Name: Zeko Jobs
 * Plugin URI: https://ozconsultz.com/zeko-jobs
 * Description: Advanced recruitment module for the Zeko Ecosystem.
 * Version: 2.1.4
 * Author: Zeko Team
 * Author URI: https://ozconsultz.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zeko-jobs
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 7.1.2
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'ZEKO_JOBS_VERSION' ) ) {
	define( 'ZEKO_JOBS_VERSION', '2.1.4' );
}

// Include the core class if available.
$core_file = plugin_dir_path( __FILE__ ) . 'includes/class-zeko-jobs.php';
if ( file_exists( $core_file ) ) {
	require_once $core_file;
} else {
	// Core class missing; abort safely to avoid fatal error.
	return;
}

// Initialize the plugin.
Zeko_Jobs::get_instance();

// Account-domain privacy: applications, interviews, alerts, etc. Complements
// the Candidate Documents exporter/eraser registered above.
$account_privacy_file = plugin_dir_path( __FILE__ ) . 'includes/privacy/class-zeko-jobs-privacy.php';
if ( file_exists( $account_privacy_file ) ) {
	require_once $account_privacy_file;
}

// Document retention: daily WP-Cron sweep over expired candidate documents.
// The DB layer owns the rows + the physical bytes (see purge_expired_documents().
// and delete_physical_document_file() in class-zeko-jobs-db.php). Retention.
// window is filterable; default 730 days past the application's terminal date.
add_action(
	'init',
	function (): void {
		if ( ! wp_next_scheduled( 'zeko_job_document_retention_daily' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_job_document_retention_daily' );
		}
	}
);

add_action(
	'zeko_job_document_retention_daily',
	function (): void {
		$older_than_days = (int) apply_filters( 'zeko_job_document_retention_days', 730 );
		if ( $older_than_days < 1 ) {
			$older_than_days = 730;
		}
		Zeko_Jobs_DB::get_instance()->purge_expired_documents( $older_than_days );
	}
);

// GDPR/CCPA: export and erase candidate documents (rows AND physical bytes).
add_filter(
	'wp_privacy_personal_data_exporters',
	function ( array $exporters ): array {
		$exporters['zeko-job-documents'] = array(
			'exporter_friendly_name' => __( 'Zeko Jobs Candidate Documents', 'zeko-jobs' ),
			'callback'               => function ( string $email_address, int $page = 1 ): array {
				unset( $page );
				$user        = get_user_by( 'email', $email_address );
				$found       = 0;
				$export_data = array();
				if ( $user ) {
					$documents = Zeko_Jobs_DB::get_instance()->get_documents( (int) $user->ID );
					foreach ( $documents as $document ) {
						$found++;
						$export_data[] = array(
							'group_id'    => 'zeko-job-documents',
							'group_label' => __( 'Zeko Jobs Candidate Documents', 'zeko-jobs' ),
							'item_id'     => 'zeko-job-document-' . (int) $document['id'],
							'data'        => array(
								array(
									'name'  => __( 'Document ID', 'zeko-jobs' ),
									'value' => (string) $document['id'],
								),
								array(
									'name'  => __( 'Label', 'zeko-jobs' ),
									'value' => (string) $document['label'],
								),
								array(
									'name'  => __( 'Stored at', 'zeko-jobs' ),
									'value' => (string) $document['created_at'],
								),
							),
						);
					}
				}
				return array(
					'data' => $export_data,
					'done' => true,
				);
			},
		);
		return $exporters;
	}
);

add_filter(
	'wp_privacy_personal_data_erasers',
	function ( array $erasers ): array {
		$erasers['zeko-job-documents'] = array(
			'eraser_friendly_name' => __( 'Zeko Jobs Candidate Documents', 'zeko-jobs' ),
			'callback'             => function ( string $email_address, int $page = 1 ): array {
				unset( $page );
				$user      = get_user_by( 'email', $email_address );
				$removed   = 0;
				$retained  = 0;
				$messages  = array();
				if ( $user ) {
					$documents = Zeko_Jobs_DB::get_instance()->get_documents( (int) $user->ID );
					foreach ( $documents as $document ) {
						if ( Zeko_Jobs_DB::get_instance()->delete_document( (int) $document['id'], (int) $user->ID ) ) {
							$removed++;
						} else {
							$retained++;
						}
					}
				}
				if ( $retained > 0 ) {
					$messages[] = __( 'Some Zeko Jobs candidate documents could not be erased.', 'zeko-jobs' );
				}
				return array(
					'items_removed'  => $removed > 0,
					'items_retained' => $retained > 0,
					'messages'       => $messages,
					'done'           => true,
				);
			},
		);
		return $erasers;
	}
);

// Register activation hook using correct main plugin file path context.
register_activation_hook( __FILE__, array( Zeko_Jobs::get_instance(), 'activate_plugin' ) );
