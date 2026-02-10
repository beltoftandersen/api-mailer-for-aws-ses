# API Mailer for AWS SES

Amazon SES API mailer for WordPress that replaces wp_mail() with direct SES SendRawEmail API calls. No SMTP layer, minimal overhead, and
optional background queueing.

- Stable version: 1.2
- Requires: WordPress 5.6+, PHP 7.4+
- Author: Chimkins IT
- Text domain: api-mailer-for-aws-ses

## Overview

This plugin integrates WordPress with Amazon Simple Email Service (SES) by signing and sending requests directly to the SES SendRawEmail
API (AWS SigV4). It avoids SMTP and PHPMailer on the hot path, reducing latency and improving reliability.

## Features

- Direct SES API (SendRawEmail) integration — no SMTP required
- Background sending queue with Action Scheduler or wp_cron fallback
- Small cron/action payloads via job_id indirection (payload stored server-side)
- Configurable send rate limiting to respect SES quotas
- From/Reply-To management with optional forced From and custom X-* headers
- Test tab (send a test email) and Status tab (fetch SES GetSendQuota)
- Log viewer with success/failure entries and an option to disable logging in production
- Credentials from wp-config.php (constants) or saved settings
- Uninstall cleanup toggle to remove settings and logs on deletion
- PSR-4, lightweight codebase with minimal I/O and no Composer dependency

## Why It’s Fast

- Bypasses SMTP and talks to SES directly; uses PHPMailer only for battle-tested MIME construction
- Optional background queue returns control to the request immediately
- Uses tiny cron/action arguments: stores the full payload server‑side and passes only a job_id
- Log trimming and an option to disable logging keep disk I/O low

## Requirements

- WordPress 5.6 or newer
- PHP 7.4 or newer
- An active Amazon SES account with a verified sender/identity

## Installation

1. Upload the plugin to wp-content/plugins/ or install it from a ZIP.
2. Activate the plugin in wp-admin.
3. Go to Settings → API Mailer for AWS SES.
4. Provide AWS credentials and Region either in settings or via wp-config.php.
5. (Optional) Enable background sending and adjust the rate limit.
6. Use the Test tab to send a test email.

## Configuration

### Credentials in wp-config.php (recommended)

Add these constants above the “That’s all, stop editing!” line in wp-config.php, then enable “Read AWS credentials from wp-config” in the
plugin settings.

define('SES_MAILER_ACCESS_KEY', 'YOUR_ACCESS_KEY_ID');<br>
define('SES_MAILER_SECRET_KEY', 'YOUR_SECRET_ACCESS_KEY');<br>
define('SES_MAILER_REGION', 'us-east-1');<br>

When this mode is enabled, the Access Key, Secret, and Region fields on the Settings page are disabled and ignored.

### Amazon SES Setup (quick guide)

Based on the FluentSMTP SES setup flow, here’s the condensed path from “Get Credentials from AWS Console” through “FluentSMTP AWS SES Settings”:

1) Create/verify your sender identity in SES: in the AWS console go to SES > Verified Identities; add a domain (preferred) or email and complete DNS/email verification.  
2) Move out of the SES sandbox if needed: under SES “Account dashboard” request production access so you can send to unverified recipients.  
3) Create an IAM user for sending: IAM > Users > Create user, enable programmatic access, attach policy `AmazonSESFullAccess` or a scoped send-only policy; download Access Key ID and Secret.  
4) Ensure your region is noted (e.g., `us-east-1`) from the SES console.  
5) In WordPress (this plugin’s Settings): enter Access Key ID, Secret Access Key, and Region; save.  
6) (Optional) For wp-config mode: define `SES_MAILER_ACCESS_KEY`, `SES_MAILER_SECRET_KEY`, and `SES_MAILER_REGION` in wp-config.php, then enable “Read AWS credentials from wp-config.”  
7) Send a test email from the plugin’s Test tab; if it fails, check that the From address matches a verified identity and that your account is out of sandbox.

### Background Sending

- If Action Scheduler is available, the queue uses it; otherwise it falls back to wp_cron.
- For wp_cron, the plugin stores the full email payload in an option keyed by ses_mailer_job_{uuid} (autoload = no) and schedules a tiny
cron argument containing only { job_id }.
- Retries: up to 3 total attempts with exponential backoff (60s, then 120s).
- On low‑traffic sites, configure a real system cron to call wp-cron.php periodically.

### Rate Limiting

The plugin pauses between sends based on the configured per‑second rate to help keep you under SES send rate limits.

### Logging

- Success and failure entries are written to wp-content/ses-mailer-logs/email-log.txt.
- View and clear logs from the Logs tab.
- Enable “Disable logging in production” to avoid writing new entries.

### External Services

- This plugin connects to Amazon Simple Email Service (AWS SES) at `https://email.{region}.amazonaws.com` to send email and to fetch sending quotas.
- Data sent on each email: recipients, subject, message body, headers, attachments, and your AWS access key ID (signed via AWS SigV4); required to deliver the email.
- Data sent when checking quotas: your AWS access key ID and a signed request; no recipient data is sent.
- Service terms: https://aws.amazon.com/service-terms/ — Privacy: https://aws.amazon.com/privacy/

## Usage

- Test tab: send a test email to verify your configuration.
- Status tab: fetches GetSendQuota (Max24HourSend, MaxSendRate, SentLast24Hours).
- A “Settings” link appears on the Plugins page for quick access to the options screen.

## Troubleshooting

- 403 SignatureDoesNotMatch or ses_api_error 403:
   - Check AWS Access Key/Secret and ensure the Region matches your SES setup.
   - Ensure the From address is verified in SES and your server clock is accurate.
- Test emails not appearing in Logs:
   - Ensure logging is not disabled in settings.

## Development

- Autoloaded PSR-4 classes are under src/.
- No Composer dependencies; relies on WordPress core HTTP functions for requests.

## Translations

- Text domain: api-mailer-for-aws-ses
- Translation template: languages/api-mailer-for-aws-ses.pot
- Example Danish translation: languages/api-mailer-for-aws-ses-da_DK.po
- Compiled binary: languages/api-mailer-for-aws-ses-da_DK.mo

## Changelog

### 1.2

- Use PHPMailer (bundled with WordPress) for MIME construction instead of manual MIME building; consistent with FluentSMTP and other major WP mailer plugins.
- CC/BCC support via PHPMailer header parsing (previously silently dropped).
- Remove dead `SendEmail` structured API code and manual MIME helpers.
- Fix broken plain text generation in background queue (was using `wp_strip_all_tags` instead of `html_to_text`).

### 1.1

- Updated text domain to match plugin slug and aligned Action Scheduler group and translation assets.
- Documented AWS SES as an external service (data sent, endpoints, terms/privacy).
- Improved log path resolution using uploads directory helpers and removed direct core includes; log clearing uses `wp_delete_file`.
- Added Amazon SES setup guide and updated readme contributors.

### 1.0.1

- Rename plugin to “API Mailer for AWS SES”
- Change text domain to `api-mailer-for-aws-ses`
- Update language files and Action Scheduler group

### 1.0.0

- Initial public release
   - Direct SendRawEmail integration
   - Background queue with Action Scheduler / wp_cron fallback
   - Job ID indirection for small cron args
   - Logging viewer with production disable
   - Settings link in plugin list
   - wp-config.php credential mode

## License

GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html.
