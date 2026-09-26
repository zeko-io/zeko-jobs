<?php
/**
 * Unified email renderer for Zeko Jobs.
 *
 * Single source of truth for HTML email wrapper, tracking, logging,
 * and plain-text fallback. Used by all email-sending classes.
 *
 * @package Zeko_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_Email_Renderer. */
final class Zeko_Jobs_Email_Renderer {

	/**
	 * Instance.
	 *
	 * @var ?self Instance.
	 */
	private static ?self $instance = null;

	/**
	 * BRAND COLOR.
	 *
	 * @var string
	 */
	private const BRAND_COLOR = '#4f46e5';

	/**
	 * BRAND ALT.
	 *
	 * @var string
	 */
	private const BRAND_ALT = '#4338ca';

	/**
	 * BG COLOR.
	 *
	 * @var string
	 */
	private const BG_COLOR = '#f8fafc';

	/**
	 * CARD BG.
	 *
	 * @var string
	 */
	private const CARD_BG = '#ffffff';

	/**
	 * TEXT COLOR.
	 *
	 * @var string
	 */
	private const TEXT_COLOR = '#1e293b';

	/**
	 * MUTED COLOR.
	 *
	 * @var string
	 */
	private const MUTED_COLOR = '#475569';

	/**
	 * FOOTER COLOR.
	 *
	 * @var string
	 */
	private const FOOTER_COLOR = '#94a3b8';

	/**
	 * FOOTER BORDER.
	 *
	 * @var string
	 */
	private const FOOTER_BORDER = '#e2e8f0';

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
	 * Construct.
	 */
	private function __construct() {}

	/**
	 * Determine whether an email recipient is a demo account.
	 * Blocks delivery to @demo.com addresses and to users tagged with the
	 * zeko_demo_user meta so demo seed data is never used for real mailouts.
	 *
	 * @return bool True when the recipient is demo-generated.
	 * @param string $to Recipient email.
	 * @param int    $recipient_id Recipient user ID (0 = unknown).
	 */
	private function is_demo_recipient( string $to, int $recipient_id = 0 ): bool {
		$host = strtolower( (string) wp_parse_url( $to, PHP_URL_HOST ) );
		if ( 'demo.com' === $host ) {
			return true;
		}

		if ( $recipient_id > 0 ) {
			$user = get_userdata( $recipient_id );
			if ( $user ) {
				if ( get_user_meta( $recipient_id, 'zeko_demo_user', true ) ) {
					return true;
				}
				if ( 'demo.com' === strtolower( (string) wp_parse_url( (string) $user->user_email, PHP_URL_HOST ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// ── Public API ──────────────────────────────────────────────.

	/**
	 * Wrap HTML body in a consistent email template.
	 *
	 * @return string Full HTML document.
	 * @param string $body_html Inner HTML content.
	 * @param string $header_title Optional header title (defaults to site name).
	 * @param bool   $plain_background Use light background on body (default true).
	 */
	public function wrap( string $body_html, string $header_title = '', bool $plain_background = true ): string {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );
		$year      = gmdate( 'Y' );
		$title     = $header_title ?: $site_name;
		$tagline   = wp_strip_all_tags( get_bloginfo( 'description' ) );
		$tagline_html = '' !== $tagline
			? '<p style="margin:6px 0 0;font-size:12px;color:rgba(255,255,255,0.85);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">' . esc_html( $tagline ) . '</p>'
			: '';

		return '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:' . self::BG_COLOR . ';color:' . self::TEXT_COLOR . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;}
img{border:0;line-height:1;outline:none;text-decoration:none;}
a{color:' . self::BRAND_COLOR . ';text-decoration:none;}
</style></head>
<body' . ( $plain_background ? ' style="background:' . self::BG_COLOR . ';padding:20px 10px;"' : '' ) . '>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::BG_COLOR . ';">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:' . self::CARD_BG . ';border-radius:8px;overflow:hidden;margin:20px 0;">
<!-- Header -->
<tr><td style="background:' . self::BRAND_COLOR . ';padding:24px 32px;">
<h1 style="margin:0;font-size:20px;font-weight:600;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">' . esc_html( $title ) . '</h1>' . $tagline_html . '
</td></tr>
<!-- Content -->
<tr><td style="padding:32px;">' . $body_html . '</td></tr>
<!-- Footer -->
<tr><td style="padding:16px 32px;background:' . self::BG_COLOR . ';border-top:1px solid ' . self::FOOTER_BORDER . ';">
<p style="margin:0;font-size:12px;color:' . self::FOOTER_COLOR . ';text-align:center;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
&copy; ' . $year . ' ' . esc_html( $site_name ) . ' &middot; <a href="' . esc_url( $site_url ) . '" style="color:#64748b;">' . esc_html__( 'Visit site', 'zeko-jobs' ) . '</a>
</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	/**
	 * Build a plain-text version from HTML content.
	 * Strips tags, collapses whitespace, normalizes line breaks.
	 *
	 * @param string $html Html.
	 */
	public function to_plain( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Send an HTML email with plain-text fallback, tracking, and logging.
	 *
	 * @return bool Whether the email was sent successfully.
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $html_body HTML body (without wrapper).
	 * @param string $email_type Tracking type slug (e.g. 'application_submitted').
	 * @param int    $application_id Application ID for tracking (0 = no tracking).
	 * @param int    $recipient_id Recipient user ID for logging (0 = skip).
	 * @param string $header_title Optional header title override.
	 */
	public function send( string $to, string $subject, string $html_body, string $email_type = '', int $application_id = 0, int $recipient_id = 0, string $header_title = '' ): bool {
		if ( empty( $to ) ) {
			return false;
		}

		if ( $this->is_demo_recipient( $to, $recipient_id ) ) {
			return false;
		}

		if ( ! $this->recipient_opted_in( $recipient_id, $email_type ) ) {
			return false;
		}

		$html_body .= $this->preferences_footer( $recipient_id, $email_type );

		$wrapped = $this->wrap( $html_body, $header_title );

		// Append tracking pixel (skipped when the recipient opted out).
		if ( $email_type && $application_id && $this->open_tracking_allowed( $recipient_id ) ) {
			$tracking_id = wp_generate_password( 32, false );
			$pixel_url   = add_query_arg(
				array(
					'zeko_email_track' => $tracking_id,
					'type'             => $email_type,
					'app'              => $application_id,
				),
				home_url( '/' )
			);
			$wrapped     = str_replace( '</body>', '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" style="display:none;" alt=""></body>', $wrapped );
		}

		$plain = $this->to_plain( $html_body );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
			'MIME-Version: 1.0',
		);

		/**
		 * Filters email headers before sending.
		 *
		 * @param array  $headers Headers.
		 * @param string $to      Recipient.
		 * @param string $subject Subject.
		 */
		$headers = apply_filters( 'zeko_jobs_email_headers', $headers, $to, $subject );

		// Build multipart body with plain-text alternative.
		$boundary   = wp_generate_password( 24, false );
		$multipart  = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
		$multipart .= "--{$boundary}\r\n";
		$multipart .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
		$multipart .= $plain . "\r\n\r\n";
		$multipart .= "--{$boundary}\r\n";
		$multipart .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
		$multipart .= $wrapped . "\r\n\r\n";
		$multipart .= "--{$boundary}--";

		// Send multipart email.
		$result = wp_mail( $to, $subject, $multipart, $headers );

		// Log the email.
		$this->log( $to, $subject, $email_type, $application_id, $recipient_id, $result );

		return $result;
	}

	/**
	 * Send a plain HTML email without multipart wrapping.
	 * For cron/bulk emails where multipart is unnecessary.
	 *
	 * @param string $to To.
	 * @param string $subject Subject.
	 * @param string $html_body Html body.
	 * @param string $email_type Email type.
	 * @param int    $application_id Application id.
	 * @param int    $recipient_id Recipient id.
	 * @param string $header_title Header title.
	 */
	public function send_html( string $to, string $subject, string $html_body, string $email_type = '', int $application_id = 0, int $recipient_id = 0, string $header_title = '' ): bool {
		if ( empty( $to ) ) {
			return false;
		}

		if ( $this->is_demo_recipient( $to, $recipient_id ) ) {
			return false;
		}

		if ( ! $this->recipient_opted_in( $recipient_id, $email_type ) ) {
			return false;
		}

		$html_body .= $this->preferences_footer( $recipient_id, $email_type );

		$wrapped = $this->wrap( $html_body, $header_title );

		// Append tracking pixel (skipped when the recipient opted out).
		if ( $email_type && $application_id && $this->open_tracking_allowed( $recipient_id ) ) {
			$tracking_id = wp_generate_password( 32, false );
			$pixel_url   = add_query_arg(
				array(
					'zeko_email_track' => $tracking_id,
					'type'             => $email_type,
					'app'              => $application_id,
				),
				home_url( '/' )
			);
			$wrapped     = str_replace( '</body>', '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" style="display:none;" alt=""></body>', $wrapped );
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		$headers = apply_filters( 'zeko_jobs_email_headers', $headers, $to, $subject );

		$result = wp_mail( $to, $subject, $wrapped, $headers );

		$this->log( $to, $subject, $email_type, $application_id, $recipient_id, $result );

		return $result;
	}

	/**
	 * Send a styled but un-wrapped email (for employer-authored templates).
	 * Applies minimal inline styling to the user-provided body without
	 * the full site header/footer.
	 *
	 * @param string $to To.
	 * @param string $subject Subject.
	 * @param string $html_body Html body.
	 */
	public function send_styled( string $to, string $subject, string $html_body ): bool {
		if ( empty( $to ) ) {
			return false;
		}

		if ( $this->is_demo_recipient( $to ) ) {
			return false;
		}

		$styled = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;color:' . self::TEXT_COLOR . ';">'
			. $html_body
			. '</div>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		$headers = apply_filters( 'zeko_jobs_email_headers', $headers, $to, $subject );

		return wp_mail( $to, $subject, $styled, $headers );
	}

	// ── Helpers for building common content blocks ──────────────.

	/**
	 * Build a button block.
	 *
	 * @param string $url Url.
	 * @param string $label Label.
	 */
	public function button( string $url, string $label ): string {
		return '<p style="margin:24px 0 16px;">
<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;background:' . self::BRAND_COLOR . ';color:#ffffff!important;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;">' . esc_html( $label ) . '</a>
</p>';
	}

	/**
	 * Build a paragraph block.
	 *
	 * @param string $text Text.
	 */
	public function p( string $text ): string {
		return '<p style="margin:0 0 16px;line-height:1.6;color:' . self::MUTED_COLOR . ';">' . $text . '</p>';
	}

	/**
	 * Build a heading block.
	 *
	 * @param string $text Text.
	 */
	public function h2( string $text ): string {
		return '<h2 style="margin:0 0 16px;font-size:18px;font-weight:600;color:' . self::TEXT_COLOR . ';">' . esc_html( $text ) . '</h2>';
	}

	/**
	 * Build an info label block (key: value).
	 *
	 * @param string $key Key.
	 * @param string $value Value.
	 */
	public function label( string $key, string $value ): string {
		return '<p style="margin:0 0 8px;"><strong style="color:' . self::TEXT_COLOR . ';">' . esc_html( $key ) . ':</strong> <span style="color:' . self::MUTED_COLOR . ';">' . esc_html( $value ) . '</span></p>';
	}

	/**
	 * Build a status badge.
	 *
	 * @param string $text Text.
	 * @param string $color Color.
	 */
	public function badge( string $text, string $color = '' ): string {
		$color = $color ?: self::BRAND_COLOR;
		return '<span style="display:inline-block;padding:4px 12px;background:' . $color . ';color:#ffffff;border-radius:12px;font-size:13px;font-weight:600;">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Build a horizontal divider.
	 */
	public function divider(): string {
		return '<hr style="border:none;border-top:1px solid ' . self::FOOTER_BORDER . ';margin:24px 0;">';
	}

	// ── Private ─────────────────────────────────────────────────.

	/**
	 * Whether a recipient is opted in to the given email type.
	 * When the preferences class is not loaded (e.g. tests, odd boot order)
	 * the email is allowed through.
	 *
	 * @param int    $recipient_id Recipient id.
	 * @param string $email_type Email type.
	 */
	private function recipient_opted_in( int $recipient_id, string $email_type ): bool {
		if ( $recipient_id <= 0 || '' === $email_type ) {
			return true;
		}
		if ( ! class_exists( 'Zeko_Jobs_Email_Preferences' ) ) {
			return true;
		}
		return Zeko_Jobs_Email_Preferences::get_instance()->is_opted_in( $recipient_id, $email_type );
	}

	/**
	 * Whether open-tracking (read-receipt) pixels are permitted for a recipient.
	 * Unknown/logged-out recipients and boot-order fallbacks keep tracking on;
	 * opted-out users have the pixel omitted so their opens cannot be recorded.
	 *
	 * @param int $recipient_id Recipient id.
	 */
	private function open_tracking_allowed( int $recipient_id ): bool {
		if ( $recipient_id <= 0 ) {
			return true;
		}
		if ( ! class_exists( 'Zeko_Jobs_Email_Preferences' ) ) {
			return true;
		}
		return Zeko_Jobs_Email_Preferences::get_instance()->open_tracking_consent( $recipient_id );
	}

	/**
	 * Append the unsubscribe/manage-preferences footer for a recipient.
	 *
	 * @param int    $recipient_id Recipient id.
	 * @param string $email_type Email type.
	 */
	private function preferences_footer( int $recipient_id, string $email_type ): string {
		if ( $recipient_id <= 0 || '' === $email_type || ! class_exists( 'Zeko_Jobs_Email_Preferences' ) ) {
			return '';
		}
		return Zeko_Jobs_Email_Preferences::get_instance()->footer( $recipient_id, $email_type );
	}

	/**
	 * Log sent email.
	 *
	 * @param string $to To.
	 * @param string $subject Subject.
	 * @param string $email_type Email type.
	 * @param int    $application_id Application id.
	 * @param int    $recipient_id Recipient id.
	 * @param bool   $success Success.
	 */
	private function log( string $to, string $subject, string $email_type, int $application_id, int $recipient_id, bool $success ): void {
		unset( $success );
		if ( ! $email_type ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_job_email_opens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tracking_id'    => wp_generate_password( 32, false ),
				'application_id' => $application_id ?: null,
				'recipient_id'   => $recipient_id ?: null,
				'email_type'     => $email_type,
				'opened_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
	}
}
