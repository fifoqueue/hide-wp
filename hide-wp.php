<?php
/**
 * Plugin Name: Hide WP Surface
 * Plugin URI:  https://github.com/fifoqueue/hide-wp-surface
 * Description: Reduces exposed WordPress paths and removable HTML fingerprints with verified server-side aliases.
 * Version:     0.1.23
 * Update URI:  https://github.com/fifoqueue/hide-wp-surface
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author:      fifoqueue
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hide-wp-surface
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'HIDE_WP_VERSION', '0.1.23' );
define( 'HIDE_WP_BASENAME', plugin_basename( __FILE__ ) );
if ( ! defined( 'HIDE_WP_GITHUB_REPOSITORY' ) ) {
	define( 'HIDE_WP_GITHUB_REPOSITORY', 'fifoqueue/hide-wp-surface' );
}
define( 'HIDE_WP_FILE', __FILE__ );
define( 'HIDE_WP_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIDE_WP_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'hide-wp-surface',
			false,
			dirname( HIDE_WP_BASENAME ) . '/languages'
		);
	}
);

if ( PHP_VERSION_ID < 80300 || version_compare( (string) ( $GLOBALS['wp_version'] ?? '0' ), '7.0', '<' ) ) {
	$markerRemovalFailed = false;
	$markerDirectories    = array( __DIR__ . '/runtime' );
	if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
		$markerDirectories[] = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/hide-wp-surface-runtime';
	}
	foreach ( array_unique( $markerDirectories ) as $markerDirectory ) {
		foreach ( array( 'paths-enabled.php', 'paths-probe.php', 'paths-enabled.flag' ) as $markerFile ) {
			$markerPath = rtrim( $markerDirectory, '/' ) . '/' . $markerFile;
			if ( is_file( $markerPath ) ) {
				@unlink( $markerPath );
				$markerRemovalFailed = $markerRemovalFailed || is_file( $markerPath );
			}
		}
	}

	if ( $markerRemovalFailed ) {
		$recoveryPath = ABSPATH . '.hide-wp-recovery';
		if ( ! is_file( $recoveryPath ) ) {
			$recoveryHandle = @fopen( $recoveryPath, 'x+b' );
			if ( false !== $recoveryHandle ) {
				fclose( $recoveryHandle );
				@chmod( $recoveryPath, 0640 );
			}
		}
	}

	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Hide WP Surface requires WordPress 7.0 or later and PHP 8.3 or later.', 'hide-wp-surface' );
			echo '</p></div>';
		}
	);

	return;
}

require_once HIDE_WP_DIR . 'src/Settings.php';
require_once HIDE_WP_DIR . 'src/Marker.php';
require_once HIDE_WP_DIR . 'src/PathMapper.php';
require_once HIDE_WP_DIR . 'src/AuthCookieBridge.php';
require_once HIDE_WP_DIR . 'src/UrlRewriter.php';
require_once HIDE_WP_DIR . 'src/RequestGuard.php';
require_once HIDE_WP_DIR . 'src/HtmlCleaner.php';
require_once HIDE_WP_DIR . 'src/ServerConfig.php';
require_once HIDE_WP_DIR . 'src/ServerVerifier.php';
require_once HIDE_WP_DIR . 'src/AdminPage.php';
require_once HIDE_WP_DIR . 'src/Updater.php';
require_once HIDE_WP_DIR . 'src/Plugin.php';

register_activation_hook( __FILE__, array( \HideWp\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \HideWp\Plugin::class, 'deactivate' ) );

\HideWp\Plugin::instance()->boot();
