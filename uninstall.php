<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$legacySettings   = get_option( 'hide_wp_settings', array() );
$legacyRepository = is_array( $legacySettings ) && is_string( $legacySettings['github_repository'] ?? null )
	? $legacySettings['github_repository']
	: '';

$markerDirectories    = array( __DIR__ . '/runtime' );
$markerRemovalFailed = false;
if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
	$markerDirectories[] = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/hide-wp-surface-runtime';
}
if ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) {
	$pluginDirectory = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );
	$markerDirectories[] = $pluginDirectory . '/hide-wp-surface/runtime';
	$markerDirectories[] = $pluginDirectory . '/hide-wp-master/runtime';
}

foreach ( array_unique( $markerDirectories ) as $directory ) {
	if ( is_link( $directory ) ) {
		$markerRemovalFailed = true;
		continue;
	}

	$files   = array( 'paths-enabled.php', 'paths-probe.php', 'paths-enabled.flag' );
	$matches = glob( rtrim( $directory, '/' ) . '/*-*.php', GLOB_NOSORT );
	if ( is_array( $matches ) ) {
		foreach ( $matches as $match ) {
			$basename = basename( $match );
			if ( 1 === preg_match( '/\A(?:paths-enabled|paths-probe|login-enabled|login-probe)-[a-f0-9]{64}\.php\z/D', $basename ) ) {
				$files[] = $basename;
			}
		}
	}

	foreach ( array_unique( $files ) as $file ) {
		$marker = rtrim( $directory, '/' ) . '/' . $file;
		if ( is_file( $marker ) || is_link( $marker ) ) {
			@unlink( $marker );
			$markerRemovalFailed = $markerRemovalFailed || is_file( $marker ) || is_link( $marker );
		}
	}
}

if ( $markerRemovalFailed ) {
	$recoveryPath = ABSPATH . '.hide-wp-recovery';
	if ( ! is_file( $recoveryPath ) && ! is_link( $recoveryPath ) ) {
		$recoveryHandle = @fopen( $recoveryPath, 'x+b' );
		if ( false !== $recoveryHandle ) {
			fclose( $recoveryHandle );
			@chmod( $recoveryPath, 0640 );
		}
	}
}

delete_option( 'hide_wp_settings' );
delete_option( 'hide_wp_login_verified_hash' );
delete_option( 'hide_wp_path_state' );
delete_option( 'hide_wp_operation_lock' );
delete_option( 'hide_wp_alias_cookie_paths' );
delete_option( 'hide_wp_alias_token' );
delete_option( 'hide_wp_data_migration_version' );
delete_site_transient( 'update_plugins' );
delete_site_transient( 'hide_wp_github_latest_release' );

$legacyRepositories = array( 'fifoqueue/hide-wp-surface' );
if ( '' !== $legacyRepository ) {
	$legacyRepositories[] = $legacyRepository;
}
if ( defined( 'HIDE_WP_GITHUB_REPOSITORY' ) && is_string( HIDE_WP_GITHUB_REPOSITORY ) ) {
	$legacyRepositories[] = HIDE_WP_GITHUB_REPOSITORY;
}
foreach ( array_unique( $legacyRepositories ) as $retiredRepository ) {
	if ( 1 !== preg_match( '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D', $retiredRepository ) ) {
		continue;
	}

	$legacySuffix = substr( hash( 'sha256', strtolower( $retiredRepository ) ), 0, 12 );
	delete_site_transient( 'hide_wp_github_latest_release_' . $legacySuffix );
	delete_site_transient( 'hide_wp_puc_latest_' . $legacySuffix );
}
