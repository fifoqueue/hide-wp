<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'paths-enabled.php', 'paths-probe.php', 'paths-enabled.flag' ) as $file ) {
	$marker = __DIR__ . '/runtime/' . $file;
	if ( is_file( $marker ) ) {
		@unlink( $marker );
	}
}

delete_option( 'hide_wp_settings' );
delete_option( 'hide_wp_login_verified_hash' );
delete_option( 'hide_wp_path_state' );
delete_option( 'hide_wp_operation_lock' );
