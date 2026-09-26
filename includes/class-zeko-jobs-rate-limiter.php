<?php
/**
 * Rate limiter for Zeko Jobs.
 *
 * Uses transients to throttle AJAX requests per user or IP.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Rate_Limiter. */
final class Zeko_Jobs_Rate_Limiter {

	/**
	 * LIMITS.
	 *
	 * @var mixed
	 */
	private const LIMITS = array(
		'apply'       => array( 20, DAY_IN_SECONDS ),
		'bookmark'    => array( 60, HOUR_IN_SECONDS ),
		'post_job'    => array( 10, DAY_IN_SECONDS ),
		'review'      => array( 5, DAY_IN_SECONDS ),
		'message'     => array( 50, HOUR_IN_SECONDS ),
		'api_request' => array( 100, MINUTE_IN_SECONDS ),
	);

	/**
	 * Check and increment the rate counter for an action.
	 *
	 * @return bool  True if allowed, false if rate limit exceeded.
	 * @param string $action Action name (must be in LIMITS or provide custom limits).
	 * @param int    $max Max allowed requests in the period (used when action not in LIMITS).
	 * @param int    $period Period in seconds (used when action not in LIMITS).
	 */
	public static function check( string $action, int $max = 0, int $period = 0 ): bool {
		if ( class_exists( 'Zeko_Core_Rate_Limiter' ) ) {
			return Zeko_Core_Rate_Limiter::get_instance()->check( $action, $max, $period, self::LIMITS );
		}

		$identifier = self::get_identifier();
		$key        = self::transient_key( $action, $identifier );

		if ( $max <= 0 || $period <= 0 ) {
			$limits = self::LIMITS[ $action ] ?? array( 0, 0 );
			$max    = $limits[0];
			$period = $limits[1];
		}

		if ( $max <= 0 || $period <= 0 ) {
			return true; // No limit configured.
		}

		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		$new_count = $count + 1;

		if ( 1 === $new_count ) {
			set_transient( $key, $new_count, $period );
		} else {
			// Preserve the original TTL — get remaining time from the transient.
			// Transients don't expose TTL, so we just refresh with the same period.
			set_transient( $key, $new_count, $period );
		}

		return true;
	}

	/**
	 * Get the remaining allowed requests for an action.
	 *
	 * @param string $action Action.
	 * @param int    $max Max.
	 * @param int    $period Period.
	 */
	public static function remaining( string $action, int $max = 0, int $period = 0 ): int {
		if ( class_exists( 'Zeko_Core_Rate_Limiter' ) ) {
			return Zeko_Core_Rate_Limiter::get_instance()->remaining( $action, $max, $period, self::LIMITS );
		}

		$identifier = self::get_identifier();
		$key        = self::transient_key( $action, $identifier );

		if ( $max <= 0 || $period <= 0 ) {
			$limits = self::LIMITS[ $action ] ?? array( 0, 0 );
			$max    = $limits[0];
		}

		$count = (int) get_transient( $key );

		return max( 0, $max - $count );
	}

	/**
	 * Get the identifier: logged-in user ID or IP address.
	 */
	private static function get_identifier(): string {
		if ( is_user_logged_in() ) {
			return 'user_' . get_current_user_id();
		}
		return 'ip_' . self::get_client_ip();
	}

	/**
	 * Get the client IP address.
	 */
	private static function get_client_ip(): string {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return sanitize_text_field( $ip );
	}

	/**
	 * Build the transient key.
	 *
	 * @param string $action Action.
	 * @param string $identifier Identifier.
	 */
	private static function transient_key( string $action, string $identifier ): string {
		return 'zeko_rl_' . $action . '_' . md5( $identifier );
	}
}
