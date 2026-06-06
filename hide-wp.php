<?php
/**
 * Plugin Name: Hide WP Surface
 * Description: Reduces exposed WordPress paths and removable HTML fingerprints with verified server-side aliases.
 * Version:     0.1.2
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author:      fifoqueue
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hide-wp
 */

defined( 'ABSPATH' ) || exit;

define( 'HIDE_WP_VERSION', '0.1.2' );
define( 'HIDE_WP_FILE', __FILE__ );
define( 'HIDE_WP_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIDE_WP_URL', plugin_dir_url( __FILE__ ) );

if ( PHP_VERSION_ID < 80300 || version_compare( (string) ( $GLOBALS['wp_version'] ?? '0' ), '7.0', '<' ) ) {
	$markerRemovalFailed = false;
	foreach ( array( 'paths-enabled.php', 'paths-probe.php', 'paths-enabled.flag' ) as $markerFile ) {
		$markerPath = __DIR__ . '/runtime/' . $markerFile;
		if ( is_file( $markerPath ) ) {
			@unlink( $markerPath );
			$markerRemovalFailed = $markerRemovalFailed || is_file( $markerPath );
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
			echo esc_html__( 'Hide WP Surface requires WordPress 7.0 or later and PHP 8.3 or later.', 'hide-wp' );
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
require_once HIDE_WP_DIR . 'src/Plugin.php';

register_activation_hook( __FILE__, array( \HideWp\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \HideWp\Plugin::class, 'deactivate' ) );

\HideWp\Plugin::instance()->boot();
