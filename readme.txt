=== Limy AI Logger ===
Contributors: svipic
Donate link: https://idox.co.il
Tags: limy, ai analytics, log shipping, tracking pixel, custom log shipping
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Asynchronously ship WordPress access logs to Limy.ai for AI visibility and agent traffic monitoring.

== Description ==

Limy AI Logger is a lightweight integration plugin that ships your WordPress web access logs directly to Limy.ai via their Custom Log Shipping HTTP endpoint (`https://stream.getlimy.ai`).

= Author & Credits =
Developed by **Soso Janashvili** ([Instagram](https://www.instagram.com/soso_janashvili/)).
Brought to you by [iDox Digital Marketing](https://idox.co.il), [Saban Marketing](https://saban.marketing/), and [One Marketing](https://one1.co.il).

= Key Features =
* **Non-blocking Asynchronous Shipping**: Logs are dispatched at request completion without delaying page loads or affecting site performance (`'blocking' => false`).
* **Complete Payload**: Ships `timestamp`, `method`, `host`, `path`, `status_code`, `ip`, `user_agent`, `referer`, `query_params`, and `duration_ms`.
* **Smart IP Detection**: Correctly identifies client IP behind Cloudflare (`CF-Connecting-IP`), reverse proxies (`X-Forwarded-For`), and standard web servers.
* **Exclusion Controls**: Easily exclude `/wp-admin/`, logged-in administrators, and WP-Cron background requests.
* **Test Ping Feature**: Verify API Key configuration with a single click inside WP Admin.

== Installation ==

1. Download the `limy-ai-logger` directory or compress it into a `.zip` file.
2. In your WordPress Admin, navigate to **Plugins > Add New > Upload Plugin**.
3. Select the file and click **Install Now**, then **Activate**.
4. Navigate to **Settings > Limy AI Logger**.
5. Paste your Limy API Key (`lmy_xxxxxxxxxxxx`) and save settings.
6. Click **Send Test Ping** to verify the connection.

== Frequently Asked Questions ==

= Where do I find my Limy API Key? =
Sign in to your [Limy.ai](https://limy.ai) dashboard and navigate to your site settings under Integration / API Keys.

= Will this slow down my website? =
No. The plugin uses WordPress `wp_remote_post()` with `'blocking' => false` hooked into the `shutdown` action after the response has already been sent to the browser.
