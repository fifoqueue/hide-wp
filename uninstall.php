<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$markerDirectories = array( __DIR__ . '/runtime' );
if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
	$markerDirectories[] = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/hide-wp-surface-runtime';
}
if ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) {
	$pluginDirectory = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );
	$markerDirectories[] = $pluginDirectory . '/hide-wp-surface/runtime';
	$markerDirectories[] = $pluginDirectory . '/hide-wp-master/runtime';
}

foreach ( array_unique( $markerDirectories ) as $directory ) {
	foreach ( array( 'paths-enabled.php', 'paths-probe.php', 'paths-enabled.flag' ) as $file ) {
		$marker = rtrim( $directory, '/' ) . '/' . $file;
		if ( is_file( $marker ) ) {
			@unlink( $marker );
		}
	}
}

delete_option( 'hide_wp_settings' );
delete_option( 'hide_wp_login_verified_hash' );
delete_option( 'hide_wp_path_state' );
delete_option( 'hide_wp_operation_lock' );
