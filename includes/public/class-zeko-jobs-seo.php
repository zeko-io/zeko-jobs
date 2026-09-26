<?php
/**
 * SEO features for Zeko Jobs.
 *
 * Responsibilities:
 * - Output JSON-LD structured data for job pages
 * - Output Open Graph and Twitter Card meta tags
 * - Filter canonical URL for job listings
 * - Output lazy loading attributes for job images
 * - Register jobs sitemap endpoint
 * - Output feed discovery link tag
 * - Serve Google Jobs-compatible XML feed
 *
 * @package Zeko_ZEKO_JOBS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Jobs_SEO. */
final class Zeko_Jobs_SEO {

	/**
	 * Construct.
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema_json_ld' ) );
		add_action( 'wp_head', array( $this, 'output_opengraph_twitter_tags' ) );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'output_lazy_loading_attributes' ) );
		add_action( 'wp_head', array( $this, 'output_prefetch_hints' ) );
		add_action( 'wp_head', array( $this, 'output_feed_discovery' ) );
		add_action( 'init', array( $this, 'maybe_add_jobs_sitemap' ) );
		add_action( 'template_redirect', array( $this, 'serve_jobs_feed' ) );
	}

	/**
	 * Output schema json ld.
	 */
	public function output_schema_json_ld(): void {
		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job_by_slug( $job_slug );

		if ( empty( $job ) ) {
			return;
		}

		$employer_id   = (int) $job['employer_id'];
		$employer_name = get_bloginfo( 'name' );
		if ( $employer_id > 0 ) {
			$user_data = get_userdata( $employer_id );
			if ( $user_data ) {
				$employer_name = $user_data->display_name;
			}
		}

		$structured_data = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'JobPosting',
			'url'                => get_permalink(),
			'title'              => $job['title'],
			'description'        => wp_strip_all_tags( $job['description'] ),
			'datePosted'         => mysql2date( 'c', $job['created_at'], false ),
			'validThrough'       => mysql2date( 'c', $job['expires_at'], false ),
			'employmentType'     => strtoupper( str_replace( '-', '_', $job['type'] ) ),
			'hiringOrganization' => array(
				'@type'  => 'Organization',
				'name'   => $employer_name,
				'sameAs' => home_url(),
			),
			'jobLocation'        => array(
				'@type'   => 'Place',
				'address' => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => $job['location'],
				),
			),
		);

		if ( ! empty( $job['experience_level'] ) ) {
			$structured_data['experienceLevel'] = array(
				'@type' => 'Text',
				'name'  => $job['experience_level'],
			);
		}

		if ( ! empty( $job['remote_option'] ) ) {
			$structured_data['remoteWorkModel'] = array(
				'@type' => 'Text',
				'name'  => $job['remote_option'],
			);
		}

		if ( ! empty( $job['requirements'] ) ) {
			$structured_data['qualifications'] = array(
				'@type' => 'Text',
				'name'  => wp_strip_all_tags( $job['requirements'] ),
			);
		}

		if ( ! empty( $job['benefits'] ) ) {
			$structured_data['jobBenefits'] = array(
				'@type' => 'Text',
				'name'  => wp_strip_all_tags( $job['benefits'] ),
			);
		}

		if ( ! empty( $job['responsibilities'] ) ) {
			$structured_data['responsibilities'] = array(
				'@type' => 'Text',
				'name'  => wp_strip_all_tags( $job['responsibilities'] ),
			);
		}

		if ( ! empty( $job['qualifications'] ) ) {
			$structured_data['qualifications'] = array(
				'@type' => 'Text',
				'name'  => wp_strip_all_tags( $job['qualifications'] ),
			);
		}

		if ( ! empty( $job['application_deadline'] ) ) {
			$structured_data['applicationDeadline'] = mysql2date( 'c', $job['application_deadline'], false );
		}

		if ( ! empty( $job['company_industry'] ) ) {
			$structured_data['industry'] = $job['company_industry'];
		}

		if ( (float) $job['salary_min'] > 0 || (float) $job['salary_max'] > 0 ) {
			$salary_value                  = (float) $job['salary_min'] > 0 ? (float) $job['salary_min'] : (float) $job['salary_max'];
			$structured_data['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => 'USD',
				'value'    => array(
					'@type'    => 'QuantitativeValue',
					'value'    => $salary_value,
					'unitText' => 'YEAR',
				),
			);
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $structured_data ) . '</script>' . "\n";
	}

	/**
	 * Output Open Graph and Twitter Card meta tags for job pages.
	 */
	public function output_opengraph_twitter_tags(): void {
		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job_by_slug( $job_slug );

		if ( empty( $job ) ) {
			return;
		}

		$job_url     = get_permalink();
		$title       = $job['title'];
		$description = wp_trim_words( wp_strip_all_tags( $job['description'] ), 30 );
		$image_id    = isset( $job['company_logo_id'] ) ? (int) $job['company_logo_id'] : 0;
		$image_url   = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		$tags = array(
			'og:type'             => 'website',
			'og:url'              => $job_url,
			'og:title'            => $title,
			'og:description'      => $description,
			'og:site_name'        => get_bloginfo( 'name' ),
			'twitter:card'        => 'summary_large_image',
			'twitter:url'         => $job_url,
			'twitter:title'       => $title,
			'twitter:description' => $description,
		);

		if ( $image_url ) {
			$tags['og:image']      = $image_url;
			$tags['twitter:image'] = $image_url;
		}

		foreach ( $tags as $property => $content ) {
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
		}
	}

	/**
	 * Filter canonical URL for job listings.
	 *
	 * @param string $url Url.
	 * @param mixed  $post_id Post id.
	 */
	public function filter_canonical_url( string $url, $post_id ): string {
		if ( $post_id instanceof WP_Post ) {
			$post_id = $post_id->ID;
		}

		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return $url;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$job     = $zeko_db->get_job_by_slug( $job_slug );

		if ( ! empty( $job ) ) {
			return home_url( '/jobs/' . $job_slug . '/' );
		}

		return $url;
	}

	/**
	 * Output lazy loading attributes for job images.
	 */
	public function output_lazy_loading_attributes(): void {
		if ( ! is_singular() ) {
			return;
		}

		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return;
		}
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				var images = document.querySelectorAll('.zeko-company-logo img, .zeko-job-logo img');
				images.forEach(function(img) {
					if (!img.hasAttribute('loading')) {
						img.setAttribute('loading', 'lazy');
					}
				});
			});
		</script>
		<?php
	}

	/**
	 * Prefetch the jobs archive from a single job page — the most common
	 * next navigation after reading a listing.
	 */
	public function output_prefetch_hints(): void {
		if ( ! is_singular() ) {
			return;
		}

		$job_slug = get_query_var( 'zeko_job_slug' );
		if ( ! $job_slug ) {
			return;
		}

		echo '<link rel="prefetch" href="' . esc_url( home_url( '/jobs/' ) ) . '">' . "\n";
	}

	/**
	 * Register jobs sitemap endpoint if requested via ?zeko_jobs_sitemap=1.
	 */
	public function maybe_add_jobs_sitemap(): void {
		if ( ! isset( $_GET['zeko_jobs_sitemap'] ) || '1' !== $_GET['zeko_jobs_sitemap'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sitemap request that must remain crawlable by search engines; no state change is performed.
			return;
		}

		$zeko_db = Zeko_Jobs_DB::get_instance();
		$jobs    = $zeko_db->get_jobs(
			array(
				'status' => 'publish',
				'limit'  => 500,
			)
		);

		header( 'Content-Type: application/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		?>
		<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
			<?php foreach ( $jobs as $job ) : ?>
				<url>
					<loc><?php echo esc_url( home_url( '/jobs/' . $job['slug'] . '/' ) ); ?></loc>
					<lastmod><?php echo esc_html( mysql2date( 'c', $job['updated_at'], false ) ); ?></lastmod>
					<changefreq>weekly</changefreq>
					<priority>0.8</priority>
				</url>
			<?php endforeach; ?>
		</urlset>
		<?php
		exit;
	}

	/**
	 * Output <link> tag for Google Jobs XML feed discovery.
	 */
	public function output_feed_discovery(): void {
		if ( ! is_page() && ! is_home() ) {
			return;
		}
		$has_shortcode = false;
		$page_id       = get_queried_object_id();
		if ( $page_id ) {
			$has_shortcode = has_shortcode( get_post_field( 'post_content', $page_id ), 'zeko_jobs_archive' );
		}
		if ( ! $has_shortcode && ! is_home() ) {
			return;
		}
		echo '<link rel="alternate" type="application/rss+xml" title="' . esc_attr( get_bloginfo( 'name' ) ) . ' — Jobs Feed" href="' . esc_url( home_url( '/jobs/feed/' ) ) . '" />' . "\n";
	}

	/**
	 * Serve Google Jobs-compatible XML feed at /jobs/feed/.
	 */
	public function serve_jobs_feed(): void {
		if ( ! get_query_var( 'zeko_jobs_feed' ) ) {
			return;
		}

		$zeko_db  = Zeko_Jobs_DB::get_instance();
		$site_url = home_url( '/' );
		$jobs     = $zeko_db->get_jobs(
			array(
				'status' => 'publish',
				'limit'  => 500,
			)
		);

		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		// XML namespace URIs below are spec-mandated identifiers (sitemaps.org /
		// Google schema), not resources fetched at runtime; they must remain http.
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:job="http://www.google.com/schemas/sitemap-news/1.0">' . "\n";

		foreach ( $jobs as $job ) {
			$job_url  = $site_url . 'jobs/' . rawurlencode( $job['slug'] ) . '/';
			$modified = ! empty( $job['updated_at'] ) ? $job['updated_at'] : $job['created_at'];
			$expired  = ! empty( $job['application_deadline'] ) ? $job['application_deadline'] : '';
			$emp_type = strtoupper( str_replace( '-', '_', $job['type'] ?? 'FULL_TIME' ) );
			$company  = $job['company_name'] ?? get_bloginfo( 'name' );
			$desc     = wp_strip_all_tags( $job['description'] ?? '' );
			$loc      = $job['location'] ?? '';
			$valid    = $expired
				? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $expired ) )
				: gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+30 days' ) );

			$salary_xml = '';
			if ( ! empty( $job['salary_min'] ) || ! empty( $job['salary_max'] ) ) {
				$s_min      = (float) ( $job['salary_min'] ?? 0 );
				$s_max      = (float) ( $job['salary_max'] ?? 0 );
				$salary_arr = array(
					'@type'    => 'MonetaryAmount',
					'currency' => 'USD',
					'value'    => array(
						'@type'    => 'QuantitativeValue',
						'minValue' => $s_min,
						'maxValue' => $s_max,
						'unitText' => 'YEAR',
					),
				);
				$salary_xml = '<job:baseSalary>' . esc_html( wp_json_encode( $salary_arr ) ) . '</job:baseSalary>';
			}

			echo '<url>' . "\n";
			echo '  <loc>' . esc_url( $job_url ) . '</loc>' . "\n";
			echo '  <lastmod>' . esc_html( gmdate( 'Y-m-d', strtotime( $modified ) ) ) . '</lastmod>' . "\n";
			echo '  <changefreq>weekly</changefreq>' . "\n";
			echo '  <priority>0.8</priority>' . "\n";
			echo '  <job:job>' . "\n";
			echo '    <job:title>' . esc_html( $job['title'] ) . '</job:title>' . "\n";
			echo '    <job:description>' . esc_html( $desc ) . '</job:description>' . "\n";
			echo '    <job:datePosted>' . esc_html( gmdate( 'Y-m-d', strtotime( $job['created_at'] ) ) ) . '</job:datePosted>' . "\n";
			echo '    <job:validThrough>' . esc_html( $valid ) . '</job:validThrough>' . "\n";
			echo '    <job:employmentType>' . esc_html( $emp_type ) . '</job:employmentType>' . "\n";
			echo '    <job:hiringOrganization>' . "\n";
			echo '      <job:Organization>' . "\n";
			echo '        <job:name>' . esc_html( $company ) . '</job:name>' . "\n";
			echo '        <job:sameAs>' . esc_url( $site_url ) . '</job:sameAs>' . "\n";
			echo '      </job:Organization>' . "\n";
			echo '    </job:hiringOrganization>' . "\n";
			echo '    <job:jobLocation>' . "\n";
			echo '      <job:Place>' . "\n";
			echo '        <job:address>' . "\n";
			echo '          <job:addressLocality>' . esc_html( $loc ) . '</job:addressLocality>' . "\n";
			echo '          <job:addressCountry>US</job:addressCountry>' . "\n";
			echo '        </job:address>' . "\n";
			echo '      </job:Place>' . "\n";
			echo '    </job:jobLocation>' . "\n";
			echo '    <job:identifier>' . "\n";
			echo '      <job:PropertyValue>' . "\n";
			echo '        <job:propertyID>' . esc_html( $job['id'] ) . '</job:propertyID>' . "\n";
			echo '      </job:PropertyValue>' . "\n";
			echo '    </job:identifier>' . "\n";
			echo '    ' . $salary_xml . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML markup already assembled with esc_html() on the JSON payload (line above); kses-escaping would corrupt the structured-data element.
			echo '  </job:job>' . "\n";
			echo '</url>' . "\n";
		}

		echo '</urlset>';
		exit;
	}
}
