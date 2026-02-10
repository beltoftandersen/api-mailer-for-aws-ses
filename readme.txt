=== API Mailer for AWS SES ===
Contributors: christian198521, Chimkins IT
Tags: ses, email, aws, api, mailer
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Amazon SES API mailer for WordPress that bypasses SMTP and sends via the SES SendRawEmail API with a lightweight, high‑performance design.

== Description ==

API Mailer for AWS SES replaces `wp_mail()` with a direct Amazon SES API integration (SendRawEmail). There is no SMTP layer or PHPMailer handoff in the critical path, reducing overhead and improving reliability.

The plugin focuses on correctness, performance, and operational clarity:
- Direct AWS SigV4 signed requests to SES
- Minimal overhead in the `wp_mail()` hook
- Optional background queue with Action Scheduler or wp_cron fallback
- Explicit logging with a production-safe disable switch
- Clean, PSR-4 structured codebase

=== Key Features ===
1. Direct SES API (SendRawEmail) integration — no SMTP required.
2. Background sending queue with Action Scheduler or wp_cron fallback.
3. Tiny-args queueing with job IDs to keep cron payloads small and private.
4. Configurable rate limiting per second to stay under SES send rate.
5. From/Reply-To handling with optional forced From and custom X-* headers.
6. Test tab to send a test email and a Status tab to fetch SES GetSendQuota.
7. Log viewer with success/failure entries and optional production disable.
8. Credentials from wp-config (constants) or saved settings.
9. Lightweight, minimal I/O, no Composer dependency.
10. Uninstall cleanup toggle to remove settings and logs on deletion.

=== Why It's High Performance ===
- Avoids SMTP for the send path; uses PHPMailer only for MIME construction, then sends via SES API directly.
- Background queue returns control to the request immediately when enabled.
- Job payloads are stored server-side and referenced by a small `job_id` to avoid large serialized cron arguments.
- Logging is optional and trimmed automatically to keep I/O low.

== Installation ==
1. Upload the plugin to `wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin.
3. Go to Settings → API Mailer for AWS SES.
4. Provide AWS credentials and Region in one of two ways:
   - Saved settings (fields on the Settings screen), or
   - From wp-config (recommended for production).
5. Optionally enable background sending and configure rate limit.
6. Use the Test tab to send a test email.

== Configuration ==

=== Credentials in wp-config (recommended) ===
Define the following constants above “That’s all, stop editing!” in `wp-config.php`, then enable “Read AWS credentials from wp-config” in the plugin settings.

    define('SES_MAILER_ACCESS_KEY', 'YOUR_ACCESS_KEY_ID');
    define('SES_MAILER_SECRET_KEY', 'YOUR_SECRET_ACCESS_KEY');
    define('SES_MAILER_REGION', 'us-east-1');

When this mode is enabled, the Access Key, Secret, and Region fields are cleared, disabled, and ignored.

=== Background Sending ===
- When enabled, emails are queued and sent out of band by Action Scheduler if available, or by wp_cron as a fallback.
- The plugin stores the full email payload in an option keyed by `ses_mailer_job_{uuid}` (autoload = no) and schedules a tiny cron/action with only `job_id`.
- Retries: up to 3 total attempts with exponential backoff (60s, then 120s).
- For consistent cron execution on low-traffic sites, configure a real system cron to call `wp-cron.php` regularly.

=== Rate Limiting ===
- A per-email pause is applied based on the configured send rate to avoid exceeding SES limits.

=== Logging ===
- Success and failure entries are written to `wp-content/ses-mailer-logs/email-log.txt`.
- Use the Logs tab to view or clear logs.
- Enable “Disable logging in production” to avoid writing new entries.

=== External Services ===
- This plugin connects to Amazon Simple Email Service (AWS SES) at `https://email.{region}.amazonaws.com` to send email and to fetch sending quotas.
- Data sent on each email: recipients, subject, message body, headers, attachments, and your AWS access key ID (signed via AWS SigV4); required to deliver the email.
- Data sent when checking quotas: your AWS access key ID and a signed request; no recipient data is sent.
- Service terms: https://aws.amazon.com/service-terms/ — Privacy: https://aws.amazon.com/privacy/

=== Translations ===
- Text domain: `api-mailer-for-aws-ses`
- Translation template: `languages/api-mailer-for-aws-ses.pot`
- Example: Danish `languages/api-mailer-for-aws-ses-da_DK.po` (compile to `.mo` for runtime)

== Frequently Asked Questions ==

Q: I get 403 SignatureDoesNotMatch.
A: Check Access Key/Secret and ensure the Region matches your SES setup. Verify your “From” address is verified in SES.

Q: Do test emails appear in Logs?
A: Yes. Test emails are tagged and logged unless logging is disabled in settings.

Q: Do I need Action Scheduler installed?
A: No. If unavailable, the plugin falls back to wp_cron automatically. A real cron is recommended for consistent processing.

Q: Can I keep credentials out of the database?
A: Yes. Use the wp-config constants and enable “Read AWS credentials from wp-config”.

== Screenshots ==
1. Settings screen with credential and queue options
2. Test tab to send a test email
3. Status tab showing SES send quotas
4. Logs tab with recent entries

== Changelog ==
= 1.3 =
- Use PHPMailer (bundled with WordPress) for MIME construction instead of manual MIME building; consistent with FluentSMTP and other major WP mailer plugins.
- Auto-detect HTML content when plugins send HTML without explicit Content-Type header.
- CC/BCC support via PHPMailer header parsing (previously silently dropped).
- Remove dead `SendEmail` structured API code and manual MIME helpers.
= 1.2 =
- Fix broken plain text generation in background queue (was using `wp_strip_all_tags` instead of `html_to_text`).
= 1.1 =
- Updated text domain to match plugin slug; aligned Action Scheduler group and translation assets.
- Documented AWS SES as an external service (data sent, endpoints, terms/privacy).
- Improved log path resolution using uploads directory helpers; removed direct core includes; log clearing uses `wp_delete_file`.
- Added Amazon SES setup guide and updated contributors list.
= 1.0.1 =
Rename plugin to “API Mailer for AWS SES”. Update text domain to `api-mailer-for-aws-ses`, language files, and internal identifiers.
= 1.0.0 =
Initial public release.
- Direct SendRawEmail integration
- Background queue with Action Scheduler / wp_cron
- Job ID indirection for small cron args
- Logging viewer with production disable
- Settings link in plugin list
- wp-config credential mode

== Upgrade Notice ==
= 1.0.0 =
Initial release. Configure credentials and test your SES connection on the Status tab before enabling in production.
