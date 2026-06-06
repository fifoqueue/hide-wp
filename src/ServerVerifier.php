<?php

declare(strict_types=1);

namespace HideWp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final readonly class ServerVerifier {
	private const PROBE_ACTION = 'hide_wp_route_probe';
	private const PROBE_MARKER = 'hide-wp-route-probe-v1';
	private const LOCK_OPTION  = 'hide_wp_operation_lock';
	private const LOCK_TTL     = 120;

	public function __construct(
		private Settings $settings,
		private PathMapper $mapper
	) {
	}

	public function boot(): void {
		$this->cleanupStaleState();
		add_action( 'wp_ajax_' . self::PROBE_ACTION, array( $this, 'serveProbe' ) );
		add_action( 'wp_ajax_nopriv_' . self::PROBE_ACTION, array( $this, 'serveProbe' ) );
	}

	public function serveProbe(): never {
		$token = isset( $_GET['token'] ) && is_string( $_GET['token'] )
			? sanitize_text_field( wp_unslash( $_GET['token'] ) )
			: '';
		$key   = 'hwp_probe_' . hash( 'sha256', $token );
		$saved = get_transient( $key );

		if ( ! is_string( $saved ) || ! hash_equals( $saved, $token ) ) {
			wp_send_json_error( array( 'message' => 'invalid probe' ), 403 );
		}

		delete_transient( $key );
		wp_send_json_success( array( 'marker' => self::PROBE_MARKER ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public function verifyAndEnable(): true|WP_Error {
		$lock = $this->acquireLock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return $this->verifyAndEnableUnlocked();
		} finally {
			$this->releaseLock( $lock );
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public function verifyLoginRoute(): true|WP_Error {
		$lock = $this->acquireLock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			return $this->verifyLoginRouteUnlocked();
		} finally {
			$this->releaseLock( $lock );
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public function disableSafely(): true|WP_Error {
		$lock = $this->acquireLock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			if ( ! $this->disable() ) {
				$recoveryExists = is_file( Marker::recoveryPath() );
				return new WP_Error(
					'marker_remove_failed',
					$recoveryExists
						? sprintf(
							/* translators: %s: emergency recovery file path. */
							__( 'The server marker could not be removed. An emergency recovery file was created at %s. Check filesystem permissions before removing it.', 'hide-wp' ),
							Marker::recoveryPath()
						)
						: __( 'The server marker and emergency recovery file could not be written. Restore the original routes manually and check filesystem permissions.', 'hide-wp' )
				);
			}

			return true;
		} finally {
			$this->releaseLock( $lock );
		}
	}

	public function disable(): bool {
		$markerRemoved = Marker::disable();
		if ( ! $markerRemoved && ! Marker::requestRecovery() ) {
			return false;
		}

		$this->settings->clearPathVerification();

		return $markerRemoved;
	}

	/**
	 * @return true|WP_Error
	 */
	private function verifyAndEnableUnlocked(): true|WP_Error {
		if ( is_multisite() ) {
			return new WP_Error( 'multisite', __( 'Verified path aliases are disabled on multisite installations.', 'hide-wp' ) );
		}

		if ( Marker::isRecoveryRequested() ) {
			return new WP_Error(
				'recovery_active',
				sprintf(
					/* translators: %s: emergency recovery file path. */
					__( 'Recovery mode is active. Remove the recovery constant and the file at %s before verification.', 'hide-wp' ),
					Marker::recoveryPath()
				)
			);
		}

		if ( ! $this->mapper->supportsVerifiedAliases() ) {
			return new WP_Error(
				'origin',
				__( 'Path aliases require HTTPS, an ASCII-only WordPress URL directory, and wp-content in that same origin and directory.', 'hide-wp' )
			);
		}

		if ( ! $this->settings->hasRequestedPathAliases() ) {
			return new WP_Error( 'no_aliases', __( 'Select at least one server alias before verification.', 'hide-wp' ) );
		}

		if ( ! $this->settings->aliasesAreUnique() ) {
			return new WP_Error( 'duplicate_paths', __( 'Every enabled server alias path must be unique.', 'hide-wp' ) );
		}

		$collision = $this->settings->findAliasCollision();
		if ( '' !== $collision ) {
			return new WP_Error(
				'path_collision',
				sprintf(
					/* translators: %s: path slug that conflicts with existing content. */
					__( 'The path "%s" conflicts with existing content or a file in the WordPress directory.', 'hide-wp' ),
					$collision
				)
			);
		}

		$configurationHash = $this->settings->configurationHash();
		$hadActiveMarker   = Marker::isEnabled() && $this->settings->pathsEnabled();

		if ( $this->settings->loginRequested() && ! $this->settings->loginEnabled() ) {
			$loginResult = $this->verifyLoginRouteUnlocked();
			if ( is_wp_error( $loginResult ) ) {
				return $loginResult;
			}
		}

		if ( ! Marker::disable() ) {
			$recoveryCreated = Marker::requestRecovery();
			return new WP_Error(
				'marker_remove_failed',
				$recoveryCreated
					? __( 'The previous server marker could not be removed. Recovery mode was requested; check filesystem permissions before continuing.', 'hide-wp' )
					: __( 'The previous server marker and emergency recovery file could not be written. Restore the original routes manually before continuing.', 'hide-wp' )
			);
		}

		if ( ! Marker::enableProbe() ) {
			$this->restorePreviousMarker( $hadActiveMarker );
			return new WP_Error(
				'probe_marker',
				sprintf(
					/* translators: %s: marker file path. */
					__( 'Could not create the server verification marker at %s.', 'hide-wp' ),
					Marker::probePath()
				)
			);
		}

		$aliasResult = $this->verifyAliasRoutes();
		if ( is_wp_error( $aliasResult ) ) {
			$this->restorePreviousMarker( $hadActiveMarker );
			return $aliasResult;
		}

		if ( ! hash_equals( $configurationHash, $this->settings->configurationHash() ) ) {
			$this->restorePreviousMarker( $hadActiveMarker );
			return new WP_Error(
				'configuration_changed',
				__( 'The path settings changed during verification. Review the generated server block and try again.', 'hide-wp' )
			);
		}

		if ( ! $this->settings->markPathsVerified( $configurationHash ) ) {
			$this->disable();
			return new WP_Error(
				'save',
				__( 'The verified path state could not be saved. Check database write access for the hide_wp_path_state option.', 'hide-wp' )
			);
		}

		if ( ! hash_equals( $configurationHash, $this->settings->configurationHash() )
			|| ! $this->settings->pathStateMatches( $configurationHash ) ) {
			$this->disable();
			return new WP_Error(
				'configuration_changed',
				__( 'The path settings changed during verification. Review the generated server block and try again.', 'hide-wp' )
			);
		}

		if ( ! Marker::enable() ) {
			$this->disable();
			return new WP_Error(
				'marker',
				sprintf(
					/* translators: %s: marker file path. */
					__( 'Could not create the server activation marker at %s.', 'hide-wp' ),
					Marker::path()
				)
			);
		}

		Marker::disableProbe();

		$blockResult = $this->verifyOriginalPathsBlocked();
		if ( is_wp_error( $blockResult ) ) {
			$this->disable();
			return $blockResult;
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private function verifyLoginRouteUnlocked(): true|WP_Error {
		if ( ! $this->settings->loginRequested() ) {
			return new WP_Error( 'login_disabled', __( 'Enable and save the custom login path first.', 'hide-wp' ) );
		}

		$scheme = wp_parse_url( (string) get_option( 'siteurl', '' ), PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || 'https' !== strtolower( $scheme ) ) {
			return new WP_Error( 'login_https', __( 'Login path verification requires an HTTPS WordPress URL.', 'hide-wp' ) );
		}

		$configurationHash = $this->settings->loginConfigurationHash();
		$token             = wp_generate_password( 40, false, false );
		$url               = $this->mapper->rewriteUrl(
			rtrim( (string) get_option( 'siteurl', '' ), '/' ) . '/wp-login.php',
			false,
			true
		);
		$result = $this->get( add_query_arg( 'hide_wp_login_probe', $token, $url ), 4_096 );
		$header = is_wp_error( $result )
			? ''
			: (string) wp_remote_retrieve_header( $result, 'x-hide-wp-login-probe' );

		if ( is_wp_error( $result ) || 204 !== wp_remote_retrieve_response_code( $result )
			|| ! hash_equals( $token, $header ) ) {
			return new WP_Error(
				'login_route',
				__( 'The custom login path did not reach WordPress. Install the generated server fallback or enable standard WordPress rewrites.', 'hide-wp' )
			);
		}

		if ( ! $this->settings->markLoginVerified( $configurationHash ) ) {
			return new WP_Error( 'login_save', __( 'The verified login path could not be saved.', 'hide-wp' ) );
		}

		return true;
	}

	/**
	 * @return string|WP_Error
	 */
	private function acquireLock(): string|WP_Error {
		$token = wp_generate_uuid4();
		$value = array(
			'token'   => $token,
			'created' => time(),
		);

		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}

		$current = get_option( self::LOCK_OPTION, array() );
		$created = is_array( $current ) ? (int) ( $current['created'] ?? 0 ) : 0;

		if ( $created > 0 && $created < time() - self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
				return $token;
			}
		}

		return new WP_Error(
			'operation_locked',
			__( 'Another path operation is already running. Wait a moment and try again.', 'hide-wp' )
		);
	}

	private function releaseLock( string $token ): void {
		$current      = get_option( self::LOCK_OPTION, array() );
		$currentToken = is_array( $current ) && is_string( $current['token'] ?? null )
			? $current['token']
			: '';

		if ( '' !== $currentToken && hash_equals( $token, $currentToken ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	private function cleanupStaleState(): void {
		$current = get_option( self::LOCK_OPTION, array() );
		$created = is_array( $current ) ? (int) ( $current['created'] ?? 0 ) : 0;
		$isFresh = $created >= time() - self::LOCK_TTL;

		if ( ! $isFresh && false !== get_option( self::LOCK_OPTION, false ) ) {
			delete_option( self::LOCK_OPTION );
		}

		$probeModified = is_file( Marker::probePath() ) ? @filemtime( Marker::probePath() ) : false;
		if ( ! $isFresh && is_int( $probeModified ) && $probeModified < time() - self::LOCK_TTL ) {
			Marker::disableProbe();
		}
	}

	/**
	 * @return true|WP_Error
	 */
	private function verifyAliasRoutes(): true|WP_Error {
		$types = $this->settings->requestedAliasTypes();

		if ( in_array( 'content', $types, true ) ) {
			$contentUrl = $this->mapper->rewriteUrl( $this->rawPluginAssetUrl(), true );
			$result     = $this->get( add_query_arg( 'hwp_probe', wp_generate_password( 8, false ), $contentUrl ), 64_000 );

			if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result )
				|| ! str_contains( wp_remote_retrieve_body( $result ), self::PROBE_MARKER ) ) {
				return new WP_Error( 'content_alias', __( 'The wp-content alias did not return the plugin probe file.', 'hide-wp' ) );
			}
		}

		if ( in_array( 'includes', $types, true ) ) {
			$includesUrl = $this->mapper->rewriteUrl( $this->rawIncludesUrl( 'js/jquery/jquery.min.js' ), true );
			$result      = $this->get( add_query_arg( 'hwp_probe', wp_generate_password( 8, false ), $includesUrl ), 256_000 );
			$body        = is_wp_error( $result ) ? '' : wp_remote_retrieve_body( $result );
			$source      = ABSPATH . WPINC . '/js/jquery/jquery.min.js';
			$expected    = is_file( $source ) ? hash_file( 'sha256', $source ) : false;

			if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result )
				|| ! is_string( $expected ) || ! hash_equals( $expected, hash( 'sha256', $body ) ) ) {
				return new WP_Error( 'includes_alias', __( 'The wp-includes alias did not return a known core asset.', 'hide-wp' ) );
			}
		}

		if ( in_array( 'admin', $types, true ) ) {
			$token = wp_generate_password( 40, false, false );
			set_transient( 'hwp_probe_' . hash( 'sha256', $token ), $token, 60 );

			$adminUrl = $this->mapper->rewriteUrl( $this->rawAdminUrl( 'admin-ajax.php' ), true );
			$adminUrl = add_query_arg(
				array(
					'action' => self::PROBE_ACTION,
					'token'  => $token,
				),
				$adminUrl
			);
			$result   = $this->get( $adminUrl, 32_000 );
			$body     = is_wp_error( $result ) ? '' : wp_remote_retrieve_body( $result );

			if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result )
				|| ! str_contains( $body, self::PROBE_MARKER ) ) {
				delete_transient( 'hwp_probe_' . hash( 'sha256', $token ) );

				return new WP_Error(
					'admin_alias',
					sprintf(
						/* translators: 1: checked URL, 2: HTTP status or transport error, 3: short response excerpt. */
						__( 'The wp-admin alias did not execute admin-ajax.php. Checked %1$s. Result: %2$s. Response: %3$s', 'hide-wp' ),
						esc_url_raw( $adminUrl ),
						$this->responseStatus( $result ),
						$this->responseExcerpt( $result )
					)
				);
			}
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private function verifyOriginalPathsBlocked(): true|WP_Error {
		$types = $this->settings->requestedAliasTypes();
		$urls  = array();
		if ( in_array( 'content', $types, true ) ) {
			$urls[] = $this->rawPluginAssetUrl();
		}
		if ( in_array( 'includes', $types, true ) ) {
			$urls[] = $this->rawIncludesUrl( 'js/jquery/jquery.min.js' );
		}
		if ( in_array( 'admin', $types, true ) ) {
			$urls[] = $this->rawAdminUrl( 'admin-ajax.php' );
		}

		foreach ( $urls as $url ) {
			$result = $this->verifyThemeNotFound( $url );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'original_paths',
					__( 'An enabled original WordPress path did not reach the theme 404 handler. Replace the older generated server block and reload the web server.', 'hide-wp' )
				);
			}
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private function verifyThemeNotFound( string $url ): true|WP_Error {
		$token = wp_generate_password( 40, false, false );
		$key   = 'hwp_404_probe_' . hash( 'sha256', $token );
		set_transient( $key, $token, 60 );

		$result = $this->get( add_query_arg( 'hide_wp_404_probe', $token, $url ), 4_096 );
		delete_transient( $key );
		$header = is_wp_error( $result )
			? ''
			: (string) wp_remote_retrieve_header( $result, 'x-hide-wp-404-probe' );

		if ( is_wp_error( $result ) || 404 !== wp_remote_retrieve_response_code( $result )
			|| ! hash_equals( $token, $header ) ) {
			return new WP_Error( 'theme_404', __( 'The request did not reach the verified WordPress theme 404 handler.', 'hide-wp' ) );
		}

		return true;
	}

	private function restorePreviousMarker( bool $hadActiveMarker ): void {
		Marker::disableProbe();
		if ( $hadActiveMarker ) {
			Marker::enable();
		}
	}

	private function responseStatus( array|WP_Error $result ): string {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $result );

		return 0 === $code ? __( 'No HTTP status', 'hide-wp' ) : (string) $code;
	}

	private function responseExcerpt( array|WP_Error $result ): string {
		if ( is_wp_error( $result ) ) {
			return '-';
		}

		$body = trim( wp_strip_all_tags( wp_remote_retrieve_body( $result ) ) );
		$body = preg_replace( '/\s+/', ' ', $body );
		$body = is_string( $body ) ? $body : '';

		return '' === $body ? '-' : substr( $body, 0, 240 );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function get( string $url, int $limit ): array|WP_Error {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => $limit,
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	private function rawAdminUrl( string $path ): string {
		return rtrim( (string) get_option( 'siteurl', '' ), '/' ) . '/wp-admin/' . ltrim( $path, '/' );
	}

	private function rawIncludesUrl( string $path ): string {
		return rtrim( (string) get_option( 'siteurl', '' ), '/' ) . '/wp-includes/' . ltrim( $path, '/' );
	}

	private function rawPluginAssetUrl(): string {
		$pluginDirectory = dirname( plugin_basename( HIDE_WP_FILE ) );
		$pluginDirectory = '.' === $pluginDirectory ? '' : trailingslashit( $pluginDirectory );

		return rtrim( $this->mapper->rawContentUrl(), '/' )
			. '/plugins/'
			. $pluginDirectory
			. 'assets/probe.css';
	}
}
