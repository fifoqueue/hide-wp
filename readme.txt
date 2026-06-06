=== Hide WP Surface ===
Contributors: fifoqueue
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hides the WordPress login route and exposes verified aliases for common WordPress paths without editing server configuration automatically.

== Description ==

Hide WP Surface reduces common WordPress fingerprints and automated requests:

* Replaces wp-login.php with a configurable login path.
* Exposes independently selectable verified aliases for wp-admin, wp-content, and wp-includes.
* Sends original server paths to the active theme's 404 template only after loopback verification succeeds.
* Sends nonessential readme, license, and sample configuration disclosure files to the theme 404 while aliases are active.
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


== Automatic Updates ==

Hide WP Surface can check the public GitHub Releases API for updates from `fifoqueue/hide-wp-surface`. To publish an update with the included GitHub Actions workflow:

1. Bump the `Version` header in `hide-wp.php`, `HIDE_WP_VERSION`, and the `Stable tag` in `readme.txt`.
2. Add a changelog entry for the new version.
3. Commit the change and push it to `main` or `master`.
4. The workflow reads the plugin version, creates the matching tag such as `v0.1.5`, builds `hide-wp-surface.zip`, and uploads it to the GitHub Release.
5. If the matching version tag already exists, the workflow fails so an existing release is not overwritten accidentally.

The updater prefers release assets named `hide-wp-surface.zip`, `hide-wp-master.zip`, or `hide-wp.zip`, and otherwise uses the first `.zip` release asset. Commits update WordPress only after the workflow creates a versioned GitHub Release with a ZIP asset.

To use a fork or a different repository, define this before the plugin loads:

`define( 'HIDE_WP_GITHUB_REPOSITORY', 'owner/repository' );`

To disable GitHub update checks entirely:

`define( 'HIDE_WP_DISABLE_GITHUB_UPDATER', true );`

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

= 0.1.16 =
* Restored wp-admin aliases to direct server rewrites so WordPress admin bootstrap, load-styles.php, and load-scripts.php execute in their native context.
* Removed the front-controller admin alias handoff that could show the WordPress database upgrade screen and break admin CSS/JS.
* Improved Nginx guidance for placing alias locations before generic PHP/static locations.

= 0.1.15 =
* Changed generated Nginx wp-admin alias routing to send admin alias requests through WordPress index.php, allowing PHP to validate activation/probe markers instead of relying on Nginx file checks.
* Fixed wp-admin alias verification on Nginx setups where /control/admin-ajax.php was intercepted by generic PHP/static handling or where Nginx could not see the runtime marker file.
* Preserved admin alias query strings while removing the internal handoff parameter before loading wp-admin targets.
* Fixed Update URI diagnostics by returning same-version GitHub release metadata to WordPress so successful checks appear under no_update instead of looking like the updater did not run.

= 0.1.14 =
* Reworked generated Nginx alias rules to use explicit location blocks so aliased PHP files such as admin-ajax.php are not intercepted by generic PHP locations before the alias rewrite runs.
* Added clearer Nginx placement guidance for verified server aliases.
* Made runtime marker writes idempotent so stale marker files from a failed verification attempt can be reused safely.
* Added explicit updater diagnostics guidance for GitHub Release checks.


= 0.1.13 =
* Fixed Nginx alias verification failures when the web server user could not traverse the runtime marker directory.
* Changed runtime marker directory permissions to be web-server-readable so Nginx `-f` checks can see verification and activation markers.
* Fixed GitHub Actions release metadata stamping by avoiding shell quoting issues in the inline PHP script.

= 0.1.12 =
* Fixed admin alias fallback so it does not re-enter WordPress admin bootstrap when the web server has already routed the request to wp-admin.
* Improved GitHub updater compatibility by stamping release packages with the current GitHub repository and by exposing update data through both WordPress update transient paths.

= 0.1.11 =
* Fixed a fatal error when an admin alias request was already rewritten to a wp-admin PHP script by the web server.
* Prevented the admin alias PHP fallback from recursively requiring the same wp-admin target during WordPress admin bootstrap.

= 0.1.10 =
* Fixed original path verification on servers that pass the internally rewritten /index.php URI to PHP by adding an explicit original-path handoff parameter to generated Apache and Nginx rules.
* Improved original path 404 handling so the request guard can recognize server-routed original WordPress paths even when REQUEST_URI was changed by the web server.

= 0.1.9 =
* Restored the Settings action link on the Plugins screen.
* Added a WordPress-level fallback for wp-admin alias requests that reach the front controller, including admin-ajax.php verification requests.
* Improved wp-admin alias verification error details with the checked URL, HTTP status, and a short response excerpt.
* Reduced GitHub release caching so newly published releases can appear during the next WordPress update check.
* Added a repository-specific updater cache key to avoid stale release data when the GitHub repository changes.


= 0.1.8 =
* Moved server marker files to a stable wp-content runtime directory to avoid breakage when the plugin folder name changes.
* Kept compatibility with existing marker files while sites transition to the newly generated server block.
* Fixed Nginx original-path blocking so internally rewritten admin aliases are not mistaken for direct wp-admin requests.


= 0.1.7 =

* Fixed GitHub update detection for plugins with an `Update URI` header by adding WordPress' hostname-specific `update_plugins_github.com` update provider.
* Fixed update payload compatibility by returning the `version` field required by WordPress' Update URI update flow.
* Improved GitHub release caching so releases without an attached ZIP package are not cached as successful update metadata for six hours.
* Kept the legacy update transient filter as a compatibility fallback for older/custom update checks.

= 0.1.6 =

* Fixed custom login requests so the aliased login route preserves the original query string when handing the request to wp-login.php.
* Fixed OIDC and other external login callbacks that arrive at paths such as /login?code=...&state=... by emulating the native wp-login.php request URI before loading WordPress' login controller.
* Updated generated Nginx rewrite rules to preserve request arguments explicitly with `$is_args$args` for login, admin, asset, include, and protected original-path rewrites.

= 0.1.5 =

* Fixed a fatal error on OIDC and cache-plugin logout flows where third-party code calls the `send_auth_cookies` filter with fewer arguments than WordPress passes during normal login.
* Added explicit alias-cookie clearing on WordPress' `clear_auth_cookie` action instead of treating shortened `send_auth_cookies` calls as a full login-cookie event.
* Renamed the generated release package and top-level plugin directory from `hide-wp-master` to `hide-wp-surface`.
* Updated the GitHub Actions release workflow to build `hide-wp-surface.zip` with a `hide-wp-surface/` top-level directory.

= 0.1.4 =

* Added a GitHub Actions workflow that validates plugin metadata, lints PHP files, builds a production ZIP, uploads workflow artifacts, and publishes the ZIP to tagged GitHub Releases.
* Fixed protected original-path 404 rendering so the active theme receives a normal front-end 404 request instead of a stripped-down synthetic query.
* Fixed admin bar visibility on protected original-path 404 responses for logged-in users who normally show the front-end admin bar.
* Fixed generated Nginx alias rewrites to restart location matching instead of staying in the current location.
* Fixed the admin alias root path so requests such as /control and /control/ route to wp-admin/index.php instead of falling through to a 404.

= 0.1.3 =

* Added GitHub Releases based update checks for custom plugin updates outside WordPress.org.
* Added one-click update package support when a compatible ZIP asset is attached to the latest GitHub release.
* Added plugin information modal details from the latest GitHub release notes.
* Added update cache clearing after plugin upgrades.

= 0.1.2 =

* Added independent enable/disable controls for wp-admin, wp-content, and wp-includes server aliases.
* Added per-alias server configuration generation, so Apache and Nginx rules are generated only for selected aliases.
* Added per-alias verification checks for selected server aliases.
* Added status messaging to distinguish current verified paths from newly saved paths that still require verification.
* Fixed an issue where saving path settings immediately disabled existing verified rewrite rules.
* Fixed live routing so the previously verified alias set remains active until the newly saved alias configuration passes verification.
* Fixed original path blocking so only enabled and verified aliases are blocked.
* Fixed auth cookie path handling to use the active verified admin alias instead of an unverified saved admin path.
* Improved verification failure handling by restoring the previous path alias marker when possible.
* Improved backwards compatibility for existing installations by migrating legacy verified path state to the new per-alias state model.

= 0.1.1 =

* Render protected original paths through the active theme's 404 template.
* Store verified server state separately from sanitized administrator settings.

= 0.1.0 =

* Initial security-focused implementation for WordPress 7.0 and PHP 8.3.
