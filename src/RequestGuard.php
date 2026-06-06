<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class RequestGuard {
	private bool $hasAliasQueryFlag = false;

	public function __construct(
		private Settings $settings,
		private PathMapper $mapper
	) {
	}

	public function boot(): void {
		$this->preventLoginCaching();
		$this->removeAliasQueryFlag();

		add_action( 'wp_loaded', array( $this, 'handle' ), 999999 );

		if ( $this->settings->getBool( 'generic_login_errors' ) ) {
			add_filter(
				'login_errors',
				static fn (): string => __( 'Authentication failed.', 'hide-wp-surface' ),
				PHP_INT_MAX
			);
		}
	}

	private function preventLoginCaching(): void {
		if ( ! $this->isLoginAliasRequest() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'DONOTMINIFY' ) ) {
			define( 'DONOTMINIFY', true );
		}

		nocache_headers();
	}

	private function isLoginAliasRequest(): bool {
		if ( ! $this->settings->loginRequested() ) {
			return false;
		}

		$path = $this->requestPath();
		if ( $this->isExactPath( $path, $this->mapper->targetPath( 'login' ) ) ) {
			return true;
		}

		return $this->hasValidAliasQueryFlag()
			&& $this->hasPathPrefix( $path, $this->mapper->sourcePath( 'login' ) );
	}

	private function removeAliasQueryFlag(): void {
		if ( ! $this->hasValidAliasQueryFlag() ) {
			return;
		}

		$key = $this->settings->aliasQueryKey();
		$this->hasAliasQueryFlag = true;

		if ( ! defined( 'HIDE_WP_ALIAS_REQUEST' ) ) {
			define( 'HIDE_WP_ALIAS_REQUEST', true );
		}

		unset( $_GET[ $key ], $_REQUEST[ $key ] );
		$queryString = $this->currentQueryString( array( $key ) );
		$_SERVER['QUERY_STRING'] = $queryString;

		$requestUri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$path = is_string( wp_parse_url( $requestUri, PHP_URL_PATH ) ) ? wp_parse_url( $requestUri, PHP_URL_PATH ) : '';

		if (
			'' !== $path
			&& $this->settings->loginRequested()
			&& $this->isExactPath( $path, $this->mapper->targetPath( 'login' ) )
		) {
			$path                   = $this->mapper->sourcePath( 'login' );
			$_SERVER['SCRIPT_NAME'] = $path;
			$_SERVER['PHP_SELF']    = $path;
			$GLOBALS['pagenow']     = 'wp-login.php';
		}

		if ( '' !== $path ) {
			$_SERVER['REQUEST_URI'] = $path . ( '' === $queryString ? '' : '?' . $queryString );
		}
	}

	private function hasValidAliasQueryFlag(): bool {
		$key = $this->settings->aliasQueryKey();
		$value = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] )
			? wp_unslash( $_GET[ $key ] )
			: '';

		return '' !== $value && hash_equals( $this->settings->aliasQueryToken(), $value );
	}

	public function handle(): void {
		if ( Marker::isRecoveryRequested() ) {
			return;
		}

		$path = $this->effectiveRequestPath();

		if ( $this->settings->loginRequested() ) {
			$isLoginAliasPath = $this->isExactPath( $path, $this->mapper->targetPath( 'login' ) );
			$isLoginSourcePath = $this->hasPathPrefix( $path, $this->mapper->sourcePath( 'login' ) );

			if ( $isLoginAliasPath || ( $this->hasAliasQueryFlag && $isLoginSourcePath ) ) {
				$this->maybeServeLoginProbe();
			}

			if ( $isLoginAliasPath ) {
				if ( $this->hasAliasQueryFlag ) {
					return;
				}

				$this->serveLogin();
			}

			if ( $this->settings->loginEnabled() && $isLoginSourcePath && ! $this->hasAliasQueryFlag ) {
				$this->serveThemeNotFound();
			}
		}

		if ( $this->isAdminAliasRequest( $path ) ) {
			if ( $this->isAdminBootstrapActive() || $this->isAlreadyExecutingAdminAliasTarget( $path ) ) {
				return;
			}

			// wp-admin aliases must be rewritten by the web server before PHP executes.
			// Loading wp-admin/*.php from wp_loaded breaks WordPress' native admin bootstrap
			// and can trigger the database-upgrade screen or broken load-styles.php responses.
			$this->serveThemeNotFound();
		}

		if ( $this->settings->pathsEnabled() && $this->isProtectedOriginalPath( $path ) && ! $this->hasAliasQueryFlag ) {
			$this->serveThemeNotFound();
		}
	}

	private function serveLogin(): never {
		$sourcePath  = $this->mapper->sourcePath( 'login' );
		$queryString = $this->currentQueryString();

		$_SERVER['REQUEST_URI']  = $sourcePath . ( '' === $queryString ? '' : '?' . $queryString );
		$_SERVER['QUERY_STRING'] = $queryString;
		$_SERVER['SCRIPT_NAME']  = $sourcePath;
		$_SERVER['PHP_SELF']     = $sourcePath;
		$GLOBALS['pagenow']      = 'wp-login.php';

		require ABSPATH . 'wp-login.php';
		exit;
	}


	private function isAdminAliasRequest( string $path ): bool {
		return $this->settings->pathAliasRequested( 'admin' )
			&& $this->hasPathPrefix( $path, $this->mapper->targetPath( 'admin' ) );
	}

	private function adminAliasRelativePath( string $path ): string {
		$aliasPath = $this->mapper->targetPath( 'admin' );
		$relative  = ltrim( substr( $this->normalizePath( $path ), strlen( untrailingslashit( $this->normalizePath( $aliasPath ) ) ) ), '/' );

		return '' === $relative ? 'index.php' : $relative;
	}


	private function isAdminBootstrapActive(): bool {
		return defined( 'WP_ADMIN' ) && true === WP_ADMIN;
	}

	private function isAlreadyExecutingAdminAliasTarget( string $path ): bool {
		$relative = $this->adminAliasRelativePath( $path );
		if ( ! $this->isSafeAdminRelativePath( $relative ) ) {
			return false;
		}

		$target     = ABSPATH . 'wp-admin/' . $relative;
		$targetReal = realpath( $target );
		$script     = isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] )
			? wp_unslash( $_SERVER['SCRIPT_FILENAME'] )
			: '';
		$scriptReal = '' !== $script ? realpath( $script ) : false;

		if ( false !== $targetReal && false !== $scriptReal && $this->sameFilesystemPath( $targetReal, $scriptReal ) ) {
			return true;
		}

		$sourcePath = $this->mapper->sourcePath( 'admin' ) . '/' . $relative;
		foreach ( array( 'SCRIPT_NAME', 'PHP_SELF' ) as $key ) {
			$value = isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] )
				? wp_unslash( $_SERVER[ $key ] )
				: '';

			if ( '' !== $value && $this->isExactPath( $value, $sourcePath ) ) {
				return true;
			}
		}

		return false;
	}

	private function sameFilesystemPath( string $left, string $right ): bool {
		$left  = rtrim( str_replace( '\\', '/', $left ), '/' );
		$right = rtrim( str_replace( '\\', '/', $right ), '/' );

		return 0 === strcasecmp( $left, $right );
	}

	private function isSafeAdminRelativePath( string $relative ): bool {
		if ( '' === $relative || str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) ) {
			return false;
		}

		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<string> $removeKeys
	 */
	private function currentQueryString( array $removeKeys = array() ): string {
		$parameters = wp_unslash( $_GET );
		if ( ! is_array( $parameters ) ) {
			return '';
		}

		foreach ( $removeKeys as $key ) {
			unset( $parameters[ $key ] );
		}

		if ( array() !== $parameters ) {
			return http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 );
		}

		$queryString = isset( $_SERVER['QUERY_STRING'] ) && is_string( $_SERVER['QUERY_STRING'] )
			? $_SERVER['QUERY_STRING']
			: '';

		if ( array() === $removeKeys ) {
			return $queryString;
		}

		return '';
	}

	private function maybeServeLoginProbe(): void {
		$token = isset( $_GET['hide_wp_login_probe'] ) && is_string( $_GET['hide_wp_login_probe'] )
			? wp_unslash( $_GET['hide_wp_login_probe'] )
			: '';

		if ( ! $this->hasAliasQueryFlag || 1 !== preg_match( '/\A[a-zA-Z0-9]{32,64}\z/D', $token ) ) {
			return;
		}

		status_header( 204 );
		nocache_headers();
		header( 'X-Hide-WP-Login-Probe: ' . $token );
		exit;
	}

	private function serveThemeNotFound(): never {
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}

		$this->maybeAddThemeNotFoundProbeHeader();
		$this->prepareSyntheticNotFoundRequest();

		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		add_filter( 'show_admin_bar', array( $this, 'showAdminBarForThemeNotFound' ), PHP_INT_MAX );

		$forceNotFound = static function ( mixed $preempt, \WP_Query $query ): bool {
			unset( $preempt );
			$query->set_404();
			status_header( 404 );
			nocache_headers();

			return true;
		};
		add_filter( 'pre_handle_404', $forceNotFound, PHP_INT_MAX, 2 );

		try {
			wp();
		} finally {
			remove_filter( 'pre_handle_404', $forceNotFound, PHP_INT_MAX );
		}

		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		require ABSPATH . WPINC . '/template-loader.php';
		exit;
	}

	public function showAdminBarForThemeNotFound( mixed $show ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$preference = get_user_option( 'show_admin_bar_front', get_current_user_id() );
		if ( 'false' === $preference ) {
			return false;
		}

		return true === $show || null === $show || '' === $show || false === $preference || 'true' === $preference;
	}

	private function prepareSyntheticNotFoundRequest(): void {
		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );
		$slug     = 'hide-wp-not-found-' . substr( hash( 'sha256', $this->requestPath() ), 0, 16 );
		$path     = '/' . trim( trim( $sitePath, '/' ) . '/' . $slug, '/' );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$_SERVER['REQUEST_URI'] = $path;
		$_SERVER['QUERY_STRING'] = '';
		$_SERVER['SCRIPT_NAME'] = $this->indexPath();
		$_SERVER['PHP_SELF']    = $this->indexPath();
		$GLOBALS['pagenow']     = 'index.php';
	}

	private function indexPath(): string {
		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );

		return '/' . trim( trim( $sitePath, '/' ) . '/index.php', '/' );
	}

	private function maybeAddThemeNotFoundProbeHeader(): void {
		$token = isset( $_GET['hide_wp_404_probe'] ) && is_string( $_GET['hide_wp_404_probe'] )
			? wp_unslash( $_GET['hide_wp_404_probe'] )
			: '';

		if ( 1 !== preg_match( '/\A[a-zA-Z0-9]{32,64}\z/D', $token ) ) {
			return;
		}

		$key   = 'hwp_404_probe_' . hash( 'sha256', $token );
		$saved = get_transient( $key );
		if ( ! is_string( $saved ) || ! hash_equals( $saved, $token ) ) {
			return;
		}

		delete_transient( $key );
		header( 'X-Hide-WP-404-Probe: ' . $token );
	}

	private function isProtectedOriginalPath( string $path ): bool {
		foreach ( $this->settings->activeAliasTypes() as $type ) {
			if ( $this->hasPathPrefix( $path, $this->mapper->sourcePath( $type ) ) ) {
				return true;
			}
		}

		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );
		foreach ( array( 'readme.html', 'license.txt', 'wp-config-sample.php' ) as $file ) {
			if ( $this->isExactPath( $path, $sitePath . '/' . $file ) ) {
				return true;
			}
		}

		return false;
	}

	private function effectiveRequestPath(): string {
		$path = $this->requestPath();

		if ( $this->settings->pathsEnabled() && $this->isProtectedOriginalPath( $path ) ) {
			return $path;
		}

		$originalPath = $this->serverProvidedOriginalPath();
		if ( '' !== $originalPath && $this->settings->pathsEnabled() && $this->isProtectedOriginalPath( $originalPath ) ) {
			return $originalPath;
		}

		return $path;
	}

	private function serverProvidedOriginalPath(): string {
		$value = isset( $_GET['hide_wp_original_path'] ) && is_string( $_GET['hide_wp_original_path'] )
			? wp_unslash( $_GET['hide_wp_original_path'] )
			: '';

		if ( '' === $value || str_contains( $value, "\0" ) ) {
			return '';
		}

		$path = wp_parse_url( $value, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = $value;
		}

		return $this->normalizePath( '/' . ltrim( $path, '/' ) );
	}

	private function requestPath(): string {
		$requestUri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$path       = wp_parse_url( $requestUri, PHP_URL_PATH );
		$path       = is_string( $path ) ? $path : '/';

		return $this->normalizePath( $path );
	}

	private function normalizePath( string $path ): string {
		$path     = rawurldecode( $path );
		$segments = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return '/' . implode( '/', $segments );
	}

	private function isExactPath( string $request, string $expected ): bool {
		return 0 === strcasecmp(
			untrailingslashit( $this->normalizePath( $request ) ),
			untrailingslashit( $this->normalizePath( $expected ) )
		);
	}

	private function hasPathPrefix( string $request, string $prefix ): bool {
		$request = $this->normalizePath( $request );
		$prefix  = trailingslashit( $this->normalizePath( $prefix ) );

		return 0 === strcasecmp( untrailingslashit( $request ), untrailingslashit( $prefix ) )
			|| 0 === strncasecmp( $request, $prefix, strlen( $prefix ) );
	}
}
