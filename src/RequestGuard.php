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
				$this->sendNotFound();
			}
		}

		if ( $this->settings->pathsEnabled() && $this->hasPathPrefix( $path, $this->mapper->sourcePath( 'admin' ) ) ) {
			$this->sendNotFound();
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

	private function sendNotFound(): never {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );

		$title = esc_html__( 'Not Found', 'hide-wp' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . $title . '</title></head><body><h1>' . $title . '</h1></body></html>';
		exit;
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
