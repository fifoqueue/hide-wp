<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;

	private function __construct() {
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function activate( bool $networkWide = false ): void {
		unset( $networkWide );

		if ( ! Marker::disable() ) {
			$recoveryCreated = Marker::requestRecovery();
			wp_die(
				$recoveryCreated
					? esc_html__( 'A stale server marker could not be removed. Emergency recovery mode was requested. Check filesystem permissions before activating Hide WP Surface.', 'hide-wp-surface' )
					: esc_html__( 'A stale server marker could not be removed, and the emergency recovery file could not be created. Restore the original routes manually before activating Hide WP Surface.', 'hide-wp-surface' ),
				esc_html__( 'Activation blocked', 'hide-wp-surface' ),
				array( 'back_link' => true )
			);
		}
		delete_option( 'hide_wp_operation_lock' );

		if ( is_multisite() ) {
			wp_die(
				esc_html__( 'Hide WP Surface cannot be activated on multisite. Login and server aliases require one verified configuration per origin.', 'hide-wp-surface' ),
				esc_html__( 'Activation blocked', 'hide-wp-surface' ),
				array( 'back_link' => true )
			);
		}

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		delete_option( Settings::PATH_STATE_OPTION );
		delete_option( Settings::LOGIN_VERIFIED_OPTION );
	}

	public static function deactivate(): void {
		if ( ! Marker::disable() ) {
			Marker::requestRecovery();
		}
		( new Settings() )->clearPathVerification();
		delete_option( Settings::LOGIN_VERIFIED_OPTION );
		delete_option( 'hide_wp_operation_lock' );
	}

	public function boot(): void {
		$settings = new Settings();
		$settings->aliasQueryToken();
		$mapper   = new PathMapper( $settings );
		$verifier = new ServerVerifier( $settings, $mapper );
		$cookies  = new AuthCookieBridge( $settings, $mapper );

		( new UrlRewriter( $mapper ) )->boot();
		$cookies->boot();
		( new RequestGuard( $settings, $mapper ) )->boot();
		( new HtmlCleaner( $settings, $mapper ) )->boot();
		$verifier->boot();
		( new Updater( $settings ) )->boot();

		if ( is_admin() ) {
			( new AdminPage( $settings, $mapper, new ServerConfig( $mapper, $settings ), $verifier, $cookies ) )->boot();
		}
	}
}
