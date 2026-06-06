=== Hide WP Surface ===
Contributors: fifoqueue
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hides the WordPress login route and exposes verified aliases for common WordPress paths without editing server configuration automatically.

== Description ==

Hide WP Surface reduces common WordPress fingerprints and automated requests:

* Replaces wp-login.php with a configurable login path.
* Exposes verified aliases for wp-admin, wp-content, and wp-includes.
* Blocks the original server paths only after loopback verification succeeds.
* Blocks nonessential readme, license, and sample configuration disclosure files while aliases are active.
* Rewrites core, plugin, theme, media, responsive image, redirect, and HTML attribute URLs.
* Removes optional generator, discovery, pingback, and core version hints.
* Uses generic login errors to reduce account enumeration feedback.
* Provides an emergency recovery constant and recovery file.

Path hiding is not an authentication or authorization boundary. Keep WordPress, plugins, and themes updated and use strong passwords, MFA, rate limiting, backups, and a WAF where appropriate.

== Requirements ==

* WordPress 7.0 or later.
* PHP 8.3 or later.
* HTTPS.
* Apache 2.4 with mod_rewrite and .htaccess overrides, or a security-patched Nginx build. For upstream Nginx, use 1.30.2/1.31.1 or later.
* A single-site installation. Multisite is intentionally unsupported.
* wp-content must use the same origin and URL directory as the WordPress installation.
* The WordPress URL directory must contain only ASCII letters, numbers, dots, underscores, tildes, and hyphens.

== Installation ==

1. Install and activate the plugin.
2. Open Settings > Hide WP Surface.
3. Save the desired paths.
4. Verify the login path. The original wp-login.php remains available until this check passes.
5. Back up the web server configuration.
6. Replace any older Hide WP Surface block, then install the generated Apache or Nginx block exactly where the settings page instructs.
7. Reload Nginx when applicable.
8. Select Verify and Enable for wp-admin, wp-content, and wp-includes aliases.

The plugin does not edit .htaccess, Nginx configuration, or virtual host files. Server configuration is an infrastructure boundary and must remain under the operator's control.

The generated Nginx block uses the rewrite module. Do not deploy it on an upstream Nginx release affected by CVE-2026-9256.

== Emergency Recovery ==

For PHP-side recovery only, add the following to wp-config.php:

`define( 'HIDE_WP_RECOVERY_MODE', true );`

For full recovery from generated Apache or Nginx rules, create an empty file named `.hide-wp-recovery` in the WordPress root directory.

The generated server rules cannot read a PHP constant. They check the recovery file and immediately stop aliases and original-path blocking. Remove the constant or file only after correcting the configuration.

== Compatibility Notes ==

Plugins or themes that hard-code original WordPress URLs in opaque JavaScript, custom JSON, or third-party caches may need their own URL filters or cache purge. Verify changes on staging before production.

The plugin intentionally does not disable REST, XML-RPC, AJAX, cron, feeds, media, updates, or plugin/theme APIs because doing so can break normal WordPress behavior.

== Privacy ==

The plugin sends no telemetry and makes no external service requests. Verification requests are loopback requests to the configured WordPress origin.

== Changelog ==

= 0.1.0 =

* Initial security-focused implementation for WordPress 7.0 and PHP 8.3.
