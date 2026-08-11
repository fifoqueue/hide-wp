=== Hide WP Surface ===
Contributors: fifoqueue
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hides the WordPress login route and exposes verified aliases for common WordPress paths without editing server configuration automatically.

== Description ==

Hide WP Surface reduces common WordPress fingerprints and automated requests:

* Replaces wp-login.php with a configurable login path.
* Exposes independently selectable verified aliases for wp-admin, wp-content, and wp-includes.
* Sends original server paths to the active theme's 404 template only after loopback verification succeeds.
* Sends nonessential readme, license, and sample configuration disclosure files to the theme 404 while aliases are active.
* Rewrites WordPress-generated core, plugin, theme, media, responsive image, and redirect URLs.
* Removes optional generator, discovery, pingback, and core version hints.
* Uses generic login errors to reduce account enumeration feedback.
* Provides an emergency recovery constant and recovery file.

Path hiding is not an authentication or authorization boundary. Keep WordPress, plugins, and themes updated and use strong passwords, MFA, rate limiting, backups, and a WAF where appropriate.

== Requirements ==

* WordPress 7.0 or later.
* PHP 8.3 or later.
* HTTPS.
* Apache 2.4 with mod_rewrite and .htaccess overrides, or a security-patched Nginx build. For upstream Nginx, use 1.30.4/1.31.3 or later.
* A single-site installation. Multisite is intentionally unsupported.
* wp-content must use the same origin and URL directory as the WordPress installation.
* The WordPress URL directory must contain only ASCII letters, numbers, dots, underscores, tildes, and hyphens.

== Installation ==

1. Install and activate the plugin.
2. Open Settings > Hide WP Surface.
3. Save the desired paths and integration options.
4. Back up the web server configuration.
5. Replace any older Hide WP Surface block, then install the generated Apache or Nginx block exactly where the settings page instructs.
6. Reload Nginx when applicable.
7. Verify the login path. The original wp-login.php remains available until this check passes.
8. Select Verify and Enable for wp-admin, wp-content, and wp-includes aliases.

The plugin does not edit .htaccess, Nginx configuration, or virtual host files. Server configuration is an infrastructure boundary and must remain under the operator's control.

The generated Nginx block uses the rewrite module. Do not deploy it on an upstream Nginx release affected by CVE-2026-9256. The login alias is rewritten directly to wp-login.php so login, SSO, and OIDC plugins run through the native WordPress login bootstrap.

Aliases must receive the same WAF rules, IP restrictions, Basic Auth, rate limits, and cache exclusions as their original paths at every CDN or reverse proxy. Redact the configured alias query key from access logs. The generated origin-server rules re-enter canonical paths, but they cannot configure security products in front of the origin.

== Release Packages ==

The plugin does not download or install code from GitHub at runtime. Install updates from a trusted, integrity-verified package source.

Maintainers can publish a package with the included GitHub Actions workflow:

1. Bump the `Version` header in `hide-wp.php`, `HIDE_WP_VERSION`, and the `Stable tag` in `readme.txt`.
2. Add a changelog entry for the new version.
3. Commit the change and push it to `main` or `master`.
4. The workflow reads the plugin version, creates the matching tag such as `v0.2.0`, builds `hide-wp-surface.zip` and its SHA-256 checksum, and uploads both to the GitHub Release.
5. If the matching version tag already exists, the workflow fails so an existing release is not overwritten accidentally.

== Emergency Recovery ==

For PHP-side recovery only, add the following to wp-config.php:

`define( 'HIDE_WP_RECOVERY_MODE', true );`

For full recovery from generated Apache or Nginx rules, create an empty file named `.hide-wp-recovery` in the WordPress root directory.

The generated server rules cannot read a PHP constant. They check the recovery file and immediately stop aliases and original-path blocking. Remove the constant or file only after correcting the configuration.

== Compatibility Notes ==

Plugins or themes that hard-code original WordPress URLs in opaque HTML, JavaScript, custom JSON, or third-party caches may need their own URL filters or cache purge. Verify changes on staging before production. The plugin intentionally avoids whole-response output buffering.

The plugin intentionally does not disable REST, XML-RPC, AJAX, cron, feeds, media, updates, or plugin/theme APIs because doing so can break normal WordPress behavior.

== Privacy ==

The plugin sends no telemetry and performs no third-party update checks. Route verification requests are loopback requests only to the configured WordPress origin.

== Upgrade Notice ==

= 0.2.2 =
Prevents external login plugins from sending successful OIDC logins back to an already-consumed callback URL.

= 0.2.1 =
Fixes admin CSS and JavaScript loading through a verified wp-admin alias and keeps external login providers on the verified login alias.

= 0.2.0 =
This security update disables markers created by older releases. After updating, replace the generated server block and verify the login and path aliases again from the standard wp-admin path. The runtime GitHub updater and Nginx FastCGI compatibility mode were removed.

== Changelog ==

= 0.2.2 =
* Removed Authorizer's redundant self-referencing `redirect_to` parameter while retaining the verified public login alias and OIDC callback parameters.

= 0.2.1 =
* Disabled WordPress admin asset concatenation while the verified wp-admin alias is active so styles and scripts use individually filtered alias URLs instead of hard-coded load-styles.php and load-scripts.php paths.
* Preserved the public login alias in REQUEST_URI while retaining the native wp-login.php script context, keeping Authorizer and other direct URL builders on the verified alias.

= 0.2.0 =
* Bound activation and probe markers to the exact configuration hash so an old verified marker cannot enable changed paths or login settings.
* Disabled active aliases before saving path changes and made every login alias require its own verified marker.
* Authenticated the original-path server handoff, removed unauthenticated internal flags, disabled their caching, and separated internal capabilities by login, admin, content, and includes purpose.
* Matched normalized Nginx URIs and added verification for percent-encoded and duplicate-slash original-path bypasses.
* Removed the direct Nginx FastCGI compatibility blocks so aliases re-enter canonical locations and retain origin access controls.
* Removed unsigned runtime GitHub update installation, third-party updater code, stored GitHub credentials, and outbound update telemetry.
* Removed whole-response HTML buffering to avoid response-sized memory amplification.
* Expire authentication cookies from old admin alias paths and clean their tracked paths during uninstall.
* Hardened marker and recovery files against symlink writes, modernized Apache deny rules, and made verification-probe cleanup fail closed.
* Registered the unauthenticated AJAX route probe only while a matching one-time verification probe exists.
* Pinned every third-party GitHub Actions step to a full commit SHA.

= 0.1.29 =
* Made generated Nginx aliases follow the activation and probe markers so disabling path aliases stops the aliases as well as original-path blocking.
* Generated login aliases only when custom login is enabled and made Apache and Nginx login aliases honor the emergency recovery file.
* Migrated installations without a saved alias token to one persistent token instead of generating a different default during each settings read.
* Corrected the privacy documentation to disclose GitHub release checks when automatic updates are enabled.
* Added a Composer lock file so release builds package a reproducible Plugin Update Checker version.

= 0.1.28 =
* Forced a fresh GitHub release check before `wp plugin update` so WP-CLI does not rely on Plugin Update Checker's normal 12-hour schedule.
* Added the same forced-check API to the bundled updater fallback for installations without Composer dependencies.

= 0.1.27 =
* Kept final HTML path rewriting enabled for administrator responses even when a page-cache plugin defines `WP_CACHE`.
* Restored aliased `load-scripts.php` and `load-styles.php` URLs on administrator pages while continuing to skip final rewriting for cacheable front-end responses.

= 0.1.26 =
* Skipped final `WP_HTML_Tag_Processor` rewriting on cacheable `GET` and `HEAD` requests when WordPress page caching is enabled.
* Preserved final HTML rewriting for uncached requests, including `POST` requests and responses marked with `DONOTCACHEPAGE`.
* Kept URL generation filters and fingerprint cleanup active so page-cache compatibility does not disable the rest of the plugin.

= 0.1.25 =
* Prevented custom login responses from being stored by page-cache plugins through the widely supported `DONOTCACHEPAGE` signal and no-cache response headers.
* Requested an optimization bypass through the conventional `DONOTMINIFY` signal without depending on cache-plugin-specific hooks.
* Kept cache exclusions scoped to the configured login alias so normal front-end pages remain cacheable.

= 0.1.24 =
* Normalized server-rewritten login aliases to the native wp-login.php request context before login rendering and OIDC plugins run.
* Fixed Authorizer compatibility so `/login` is not mistaken for an embedded login form return URL, avoiding the redundant post-OIDC redirect back to the login page.
* Kept OAuth2/OIDC callback query parameters intact while removing only the private alias handoff parameter.

= 0.1.23 =
* Added Korean localization and standardized the plugin text domain as `hide-wp-surface`.
* Added translation catalog validation and release-time MO/PHP language file generation to CI.
* Split the settings page into accessible tabs for paths and login, server integration, fingerprint cleanup, and updates.
* Preserved the active settings tab through URL hashes and keyboard navigation while keeping all settings in one save operation.
* Replaced the hard-coded `/control/admin-ajax.php` guidance with the currently configured wp-admin alias path.
* Stopped tracking local IntelliJ IDEA module metadata and generated translation binaries.

= 0.1.22 =
* Changed generated login alias rules to rewrite /login directly to wp-login.php with the internal alias flag, so OIDC/login plugins see the native WordPress login bootstrap instead of a late PHP include.
* Fixed compatibility with plugins such as Authorizer that disable the native WordPress login form by avoiding the fallback login loader when the request already came through a verified alias rewrite.
* Preserved the standard rewrite/FastCGI compatibility split for Nginx wp-admin aliases while sharing the same alias key and token with the login rewrite.
* Improved internal alias flag handling so verified alias rewrites can pass through original WordPress entry points without being mistaken for direct wp-login.php or wp-admin access.

= 0.1.21 =
* Changed the default Nginx wp-admin alias integration to a simpler rewrite mode that appends an internal alias flag and uses the site's normal PHP handler.
* Kept the previous direct FastCGI wp-admin alias as an optional compatibility mode for Nginx stacks where standard rewrites are swallowed by the WordPress front controller.
* Added configurable Nginx admin alias mode, alias query key, and alias token settings.
* Replaced the custom GitHub updater hooks with a Plugin Update Checker-compatible integration.
* Added plugin settings for GitHub repository and token so update configuration no longer requires wp-config.php constants.
* Updated release packaging to install the Plugin Update Checker dependency during CI builds when Composer is available.

= 0.1.20 =
* Fixed generated Nginx wp-admin alias handling for static admin assets such as `/control/js/common.js`.
* Replaced the regex `alias`-based static asset mapping with a `rewrite ... break` plus explicit `root` mapping, which is more reliable across Nginx builds and avoids checking the aliased `/control/...` path on disk.

= 0.1.19 =
* Fixed Nginx wp-admin aliases for static admin assets such as js, css, images, fonts, and source maps.
* Replaced the generic wp-admin alias static location with a capture-based alias location so /control/js/... maps directly to /wp-admin/js/... instead of checking the wrong /control/... filesystem path.

= 0.1.18 =
* Changed generated Nginx wp-admin aliases to execute admin PHP files directly through FastCGI, avoiding theme 404s, broken load-styles.php/load-scripts.php, and database-upgrade-screen side effects caused by front-controller routing.
* Added a Nginx FastCGI pass setting used by the generated admin alias block.
* Added GitHub updater settings for repository, enable/disable state, and an optional API token so update checks no longer require wp-config.php constants.
* Added GitHub API token support to the updater for private repositories and rate-limit avoidance.
* Cleared update transients when GitHub updater settings change.

= 0.1.17 =
* Changed generated Nginx admin alias routing to server-level native rewrites so /control/admin-ajax.php and load-styles.php reach wp-admin before generic WordPress front-controller handling.
* Added stronger updater diagnostics support for custom GitHub releases.

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
