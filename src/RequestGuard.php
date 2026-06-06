<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class RequestGuard {
	public function __construct(
		private Settings $settings,
		private PathMapper $mapper
	) {
	}

	public function boot(): void {
		add_action( 'wp_loaded', array( $this, 'handle' ), 999999 );

		if ( $this->settings->getBool( 'generic_login_errors' ) ) {
			add_filter(
				'login_errors',
				static fn (): string => __( 'Authentication failed.', 'hide-wp' ),
				PHP_INT_MAX
			);
		}
	}

	public function handle(): void {
		if ( Marker::isRecoveryRequested() ) {
			return;
		}

		$path = $this->requestPath();

		if ( $this->settings->loginRequested() ) {
			if ( $this->isExactPath( $path, $this->mapper->targetPath( 'login' ) ) ) {
				$this->maybeServeLoginProbe();
				$this->serveLogin();
			}

			if ( $this->settings->loginEnabled()
				&& $this->hasPathPrefix( $path, $this->mapper->sourcePath( 'login' ) ) ) {
				$this->serveThemeNotFound();
			}
		}

		if ( $this->settings->pathsEnabled() && $this->isProtectedOriginalPath( $path ) ) {
			$this->serveThemeNotFound();
		}
	}

	private function serveLogin(): never {
		$sourcePath             = $this->mapper->sourcePath( 'login' );
		$_SERVER['SCRIPT_NAME'] = $sourcePath;
		$_SERVER['PHP_SELF']    = $sourcePath;
		$GLOBALS['pagenow']     = 'wp-login.php';

		require ABSPATH . 'wp-login.php';
		exit;
	}

	private function maybeServeLoginProbe(): void {
		$token = isset( $_GET['hide_wp_login_probe'] ) && is_string( $_GET['hide_wp_login_probe'] )
			? wp_unslash( $_GET['hide_wp_login_probe'] )
			: '';

		if ( 1 !== preg_match( '/\A[a-zA-Z0-9]{32,64}\z/D', $token ) ) {
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

		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		$forceNotFound = static function ( mixed $preempt, \WP_Query $query ): bool {
			unset( $preempt );
			$query->set_404();
			status_header( 404 );
			nocache_headers();

			return true;
		};
		add_filter( 'pre_handle_404', $forceNotFound, PHP_INT_MAX, 2 );

		try {
			wp( array( 'post__in' => array( 0 ) ) );
		} finally {
			remove_filter( 'pre_handle_404', $forceNotFound, PHP_INT_MAX );
		}

		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->posts             = array();
			$wp_query->post              = null;
			$wp_query->post_count        = 0;
			$wp_query->current_post      = -1;
			$wp_query->queried_object    = null;
			$wp_query->queried_object_id = 0;
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		require ABSPATH . WPINC . '/template-loader.php';
		exit;
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
