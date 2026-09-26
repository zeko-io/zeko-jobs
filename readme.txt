=== Zeko Jobs ===
Contributors: zeko
Tags: jobs, hiring, employment, recruitment
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 2.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Job board and recruitment plugin for WordPress with employer/seeker dashboards, application tracking, and interview scheduling.

== Description ==

Zeko Jobs is a job board plugin for WordPress. It provides employers and job seekers with AJAX-based dashboards and integration with the Zeko ecosystem.

= Features =

* **Employer Dashboard** — Post, edit, and manage job listings; review applications; schedule interviews; track performance analytics
* **Seeker Dashboard** — Browse and search jobs, apply with one click, track application status, manage documents and saved searches
* **Advanced Search** — Full-text keyword search, location filtering, category/type/experience filters, salary range, and remote/hybrid/onsite options
* **Geolocation** — Browser-based geolocation with Haversine distance sorting and radius selector
* **Application Pipeline** — Kanban-style drag-and-drop pipeline for employers to move candidates through hiring stages
* **Interview Scheduling** — Schedule interviews with notes, reminders, ICS calendar export, meeting URLs (Google Meet/Zoom/Teams), and feedback collection
* **Document Management** — Upload and manage resumes, cover letters, and portfolios with default resume selection
* **Email Notifications** — Automated emails for application submissions, status changes, interview scheduling, and job expirations
* **Job Alerts** — Saved search alerts with configurable frequency (instant, daily, weekly)
* **Performance Analytics** — Track views, applications, conversion rates, and time-series data per job
* **Reviews & Ratings** — Job seekers can review employers; employers can rate candidates
* **SEO Optimized** — Schema.org JobPosting structured data, Open Graph/Twitter Card meta, XML sitemap, RSS feeds
* **Dark Mode** — Full dark mode support with system preference detection
* **Mobile Responsive** — Optimized for all screen sizes with mobile-first design
* **Bulk Actions** — Select multiple jobs or applications for batch operations
  * **Data export and erasure** — Provides personal-data export and erasure requests with a 30-day grace period
* **Resume Builder** — Structured 6-section resume editor (Personal, Experience, Education, Skills, Certifications, Projects) with PDF export and multiple templates
* **LinkedIn Integration** — OAuth 2.0 "Apply with LinkedIn" and profile import
* **AI Job Matching** — 5-factor match scoring (skills, experience, type, location, education) with visual match % badges
* **Candidate Comparison** — Side-by-side comparison of 2-3 candidates
* **Email Templates** — Customizable email templates for employer communications
* **Company Profiles** — Employer company pages with logo, description, and verification

= Ecosystem Integration =

Zeko Jobs integrates with other Zeko plugins:

* **Zeko Learn** — Course recommendations based on job requirements
* **Zeko Pay** — Payment processing for job boosts and premium listings
* **Zeko QA** — Skill assessments tied to job applications
* **Zeko Dashboard** — Unified activity feed and profile stats
* **Zeko Profile** — Employment history and skills from job applications

= Shortcodes =

* `[zeko_jobs]` — Job listings with filtering and search
* `[zeko_job_single]` — Single job display
* `[zeko_job_form]` — Job posting form for employers
* `[zeko_featured_jobs]` — Featured jobs carousel
* `[zeko_job_search]` — Standalone search form
* `[zeko_jobs_dashboard]` — Full dashboard with employer/seeker views

== Installation ==

1. Upload the `zeko-jobs` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure the Zeko core plugin is active for full functionality
4. Configure settings under Zeko > Jobs Settings
5. Create pages and add the appropriate shortcodes

== Frequently Asked Questions ==

= Does this plugin work standalone? =

Yes. Zeko Jobs functions as a standalone job board. Activate the Zeko core plugin for dashboard integration, activity feeds, and profile stats.

= How do employers post jobs? =

Employers use the `[zeko_job_form]` shortcode or navigate to the Jobs dashboard tab. Jobs go through a moderation queue before publishing.

= Can seekers apply without an account? =

No. Seekers must create an account and log in to apply. This ensures application tracking and communication between employers and candidates.

= How does geolocation work? =

The plugin uses the browser's Geolocation API to determine the user's location, then calculates distance using the Haversine formula to sort jobs by proximity. Users can also set a radius for distance-based filtering.

= Is there a payment integration? =

Yes. Zeko Pay integrates for job boosts and premium listings. Payment actions are triggered via `zeko_jobs_job_posted` and `zeko_jobs_job_boosted` hooks.

== Dependencies ==

Zeko Jobs runs standalone. For full ecosystem functionality (dashboard tabs, activity feed, unified profile stats) activate Zeko Core. Zeko Pay is optional (job boosts and premium listings), Zeko Learn and Zeko QA are optional integrations.

== Privacy ==

Data stored includes the data you submit on employer/seeker profiles, applications, saved searches, uploaded documents/resumes, and application/interview records. Job view logs and activity entries capture an IP address. The plugin registers personal-data exporters and erasers (Tools > Export/Erase Personal Data) covering candidate documents and accounts.

* **Email open tracking** — Transactional email for application events includes a
  hidden read-receipt image. Recipients can disable this at any time from the
  email preferences page (`/jobs/email-preferences/`, linked in every email
  footer); opted-out recipients never receive the tracking pixel. Open records
  are purged automatically after 90 days.

== Uninstall ==

Deleting the plugin drops all Zeko Jobs custom tables (the shared messaging tables are only dropped when Zeko Core is not present), removes jobs-owned user meta and options, deletes pages it created, and clears its cron events.

== Troubleshooting ==

* Jobs not appearing? Check the moderation queue (Jobs > Approval) — new posts stay pending until approved.
* Geolocation not sorting by distance? The browser must grant the Geolocation permission on the jobs page.
* Application emails not arriving? Check the mail log/fail log; the tracking pixel requires HTML-enabled email.

== External Services ==

Zeko Jobs makes outbound requests to two external services. Both are optional and
off unless you configure them.

* **LinkedIn OAuth** — Optionally enables "Apply with LinkedIn" and candidate
  profile import. What is sent: your LinkedIn app credentials (OAuth client id /
  secret) and, during sign-in, the OAuth authorization code exchanged at the
  hardcoded `https://www.linkedin.com` endpoints; the returned email + basic
  profile fields are stored locally as the candidate account. When: only when an
  administrator configures LinkedIn OAuth (developer.linkedin.com app) and a
  user chooses to apply/import with LinkedIn. Retention: LinkedIn's own policies
  apply to the exchange; locally only the imported profile fields are stored
  (removable via the plugin's personal-data export/erase tools).
* **Nominatim / OpenStreetMap** — Optionally geocodes an employer-entered
  location string into latitude/longitude for job locations. What is sent: the
  location string only, to the fixed `https://nominatim.openstreetmap.org/`
  endpoint. When: only when an employer saves a job location and the
  administrator enables geocoding. Retention: the resulting coordinates are
  cached locally; no data is stored by Nominatim beyond standard request logs
  (see osm.org policies). Full endpoint/payload detail (with file:line
  citations) is in `REMOTE-SERVICES.txt` in this plugin folder.

== Third-party resources ==

Zeko Jobs bundles three third-party JavaScript libraries, licensed as follows:

* **Chart.js v4.4.7** (MIT) — `assets/vendor/chartjs/chart.umd.min.js`,
  employer analytics charts. Copyright (c) 2024 Chart.js Contributors.
* **Leaflet 1.9.4** (BSD-2-Clause) — `assets/vendor/leaflet/` (JS, CSS, and
  marker/layer marker images), job-location maps. Copyright (c) 2010-2023
  Vladimir Agafonkin, (c) 2010-2011 CloudMade.
* **Sortable 1.15.6 / SortableJS** (MIT) — `assets/vendor/sortable/Sortable.min.js`,
  drag-and-drop application pipeline. Copyright SortableJS contributors.

All three licenses are GPL-2.0-or-later-compatible; full license texts and the
dev-only Composer package list are in `THIRD-PARTY-NOTICES.txt` in this plugin
folder. All other assets (CSS, JS, icons via WordPress dashicons, screenshot)
are original Zeko work under GPL-2.0-or-later. Composer packages in `vendor/`
are development-only (PHPCS/PHPUnit) and are excluded from the release ZIP via
`.distignore`.

== Screenshots ==

1. Job board dashboard with applications pipeline


== Sections ==

* [Description](#description)
* [Installation](#installation)
* [Frequently Asked Questions](#frequently-asked-questions)
* [External Services](#external-services)
* [Development](#development)
* [Changelog](#changelog)
* [Upgrade Notice](#upgrade-notice)

== Development ==

= Prerequisites =

* PHP 7.4+
* Composer

= Setup =

1. Clone or download the plugin
2. Run `composer install` to install dependencies (PHPCS, PHPUnit)
3. Run `composer lint` to check coding standards
4. Run `composer test` to run PHPUnit tests

= Testing =

Unit tests cover the rate limiter, LinkedIn OAuth, main plugin class, and DB singleton. Run with `vendor/bin/phpunit`.

= Coding Standards =

The plugin follows WordPress Coding Standards. Configuration is in `.phpcs.xml.dist`.

== Changelog ==

= 2.1.4 =
* Multiple companies per employer (DB schema v3.7.0): create and manage multiple company brands from the dashboard, assign jobs to a brand, and company pages list only that brand's jobs
* Employer blog: `wp_zeko_company_posts` table, dashboard publishing/editing/deleting, and a "News & Updates" section on the public company page
* Required skills + skills matching: new `required_skills` column, "You have X of Y required skills" badges on job cards, and a skills-match panel with course recommendations on single-job pages
* Zeko-Learn integration: seeker profiles show skills aggregated from completed courses + earned certificates; "close the gap" course recommendations
* Zeko-QA integration: related community questions and industry insights on job postings (fail-soft when Q&A is inactive)
* Pipeline automation: completing an interview now auto-advances the application from interviewing to offered (rule map filterable)
* Message templates: saved reusable responses with a picker in the compose box
* Message search: full-text search across all of a user's conversations with clickable results
* Typing indicators: polling-based "is typing..." status in conversations
* Payment status tracking: `payment_status` column (free/paid/pending) set on posting, shown in the admin jobs table and employer dashboard
* Fix: undefined `ZEKO_JOBS_VERSION` constant would fatal the messaging page asset enqueue
* Fix: `.phpcs.xml.dist` referenced WPCS sniffs renamed in WPCS 3.x — config now loads; pre-commit PHPCS check added (`.githooks/pre-commit`, `composer lint:new`)

= 2.1.3 =
* Company page customization: cover image, accent color, and video embed on the company profile
* Company follow system: new `wp_zeko_company_follows` table (DB schema v3.6.0), follow/unfollow button with follower count, and `company_new_job` notifications to followers when an employer posts a job
* Resume search: employers can search opt-in candidates (name/bio, skills, location) from the dashboard; only seekers who enabled profile visibility + open-to-work appear
* Batch notifications: multiple applications for the same job consolidate into a single in-app notification
* Database cleanup cron: daily purge of notifications, email opens, view logs, and resolved flags older than the retention window
* Admin jobs page: Export CSV (publish jobs only, admin-only, nonce-protected)

= 2.1.2 =
* Trust & safety: users can report job listings (scam, inappropriate, misleading, etc.) from the single-job page
* New `wp_zeko_job_flags` moderation queue (DB schema v3.5.0) + admin Reports submenu with pending badge and Resolve/Dismiss actions
* Email preferences: per-type opt-in/out for all notification emails + one-click unsubscribe, managed from `/jobs/email-preferences/`
* Email promotion: featured jobs now lead digest and job-alert emails (featured-first ordering + Featured badge)
* Fix: job-alert matching now filters on `publish` status (previously `published`, so alerts never matched)

= 2.1.1 =
* Featured company section: premium employers highlighted on the jobs homepage
* New `wp_zeko_companies.is_featured` column (DB schema v3.4.0) + `get_featured_companies()` with open-job counts
* Admin Companies submenu with Feature/Unfeature toggle
* New `[zeko_jobs_companies]` shortcode rendering the featured company strip

= 2.1.0 =
* Email templates upgrade: unified email renderer with consistent branding across all 12+ email types
* Plain-text multipart MIME fallback for better deliverability
* Tracking pixel support on all transactional emails (previously missing on application_submitted)
* Performance: object caching on get_job(), get_job_by_slug(), employer_verified, response_time, distinct_locations, applied_job_ids, company_by_employer
* Performance: N+1 query fix in archive loop — hoisted 6 per-user queries outside the foreach
* Performance: eliminated redundant calculate_profile_match() calls in load-more AJAX handler
* Performance: improved cache invalidation with targeted key deletion alongside group flush
* Fixed double get_userdata() calls in bulk email and outreach handlers
* Added 9 new unit tests for email renderer (33 total, 59 assertions)

= 2.0.0 =
* Major release: messaging system, email notifications, job alerts, interview scheduling
* Company profiles with verification, reviews and ratings backend
* Application analytics with time-series and CSV export
* Profile match scoring, similar/recommended jobs, best-time-to-apply
* Candidate notes, document management, bulk emails, email templates
* Ecosystem integration (dashboard tab, activity feed, profile, payment hooks)
* Saved searches, employer response time, job completeness scoring
* Read receipts, auto-responses, notification table migration
* Gutenberg blocks, REST API routes, Leaflet map data layer
* Moderation queue with bulk approve/reject, auto-approve trusted employers
* GDPR data export/deletion, comprehensive uninstall cleanup
* DB schema v3.0.0 (18 migrations, 15+ tables)

= 1.0.0 =
* Initial release
* Employer and seeker dashboards
* Job posting and application system
* Advanced search with geolocation
* Application pipeline (Kanban)
* Interview scheduling
* Document management
* Email notifications
* SEO (Schema.org, OG, sitemap, RSS)
* Dark mode
* Loading skeletons
* Ecosystem integration (dashboard, activity feed, profile)
* Zeko Pay integration hooks

== Upgrade Notice ==

= 2.0.0 =
Major release with messaging, email notifications, analytics, company profiles, and ecosystem integration.

= 1.0.0 =
Initial release of Zeko Jobs.
