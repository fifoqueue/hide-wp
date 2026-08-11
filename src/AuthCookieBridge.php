<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class AuthCookieBridge {
	public const COOKIE_PATHS_OPTION = 'hide_wp_alias_cookie_paths';
	private const MAX_TRACKED_PATHS = 16;

	/**
	 * @var array{value: string, expire: int, scheme: string}|null
	 */
	private ?array $pendingCookie = null;

	public function __construct(
		private readonly Settings $settings,
		private readonly PathMapper $mapper
	) {
	}

	public function boot(): void {
		// Retain both the last verified and currently requested paths so logout or
		// a later re-verification can expire cookies left on an obsolete alias.
		$this->rememberAliasPath( $this->mapper->targetPath( 'admin', true ) );
		$this->rememberAliasPath( $this->mapper->targetPath( 'admin' ) );

		add_action( 'set_auth_cookie', array( $this, 'rememberAuthCookie' ), PHP_INT_MAX, 6 );
		add_filter( 'send_auth_cookies', array( $this, 'sendAliasAuthCookie' ), PHP_INT_MAX, 6 );
		add_action( 'clear_auth_cookie', array( $this, 'clearAliasAuthCookies' ), PHP_INT_MAX );
	}

	public function rememberAuthCookie(
		string $authCookie,
		int $expire,
		int $expiration,
		int $userId,
		string $scheme,
		string $token
	): void {
		unset( $expiration, $userId, $token );

		$this->pendingCookie = array(
			'value'  => $authCookie,
			'expire' => $expire,
			'scheme' => $scheme,
		);
	}

	/**
	 * Mirrors WordPress authentication cookies to the verified admin alias path.
	 *
	 * Some plugins call the documented send_auth_cookies filter with only the
	 * first argument while clearing cookies. Keep this callback permissive so
	 * those calls return unchanged instead of fataling under strict types.
	 *
	 * @param mixed $send Whether core should send auth cookies. Usually bool.
	 * @param mixed $expire Auth cookie grace expiration timestamp.
	 * @param mixed $expiration Logged-in cookie expiration timestamp.
	 * @param mixed $userId User ID.
	 * @param mixed $scheme Auth cookie scheme.
	 * @param mixed $token Session token.
	 * @return mixed The original filter value unless a normal boolean send decision is being filtered.
	 */
	public function sendAliasAuthCookie(
		mixed $send,
		mixed $expire = null,
		mixed $expiration = null,
		mixed $userId = null,
		mixed $scheme = null,
		mixed $token = null
	): mixed {
		unset( $expiration, $userId, $token );

		if ( ! is_bool( $send ) ) {
			return $send;
		}

		if ( ! $send || ! $this->settings->activeAliasEnabled( 'admin' ) || headers_sent() ) {
			$this->pendingCookie = null;
			return $send;
		}

		if ( ! is_int( $expire ) || ! is_string( $scheme ) || '' === $scheme ) {
			return $send;
		}

		$cookie = $this->pendingCookie;
		$this->pendingCookie = null;

		if ( null === $cookie || ! hash_equals( $scheme, $cookie['scheme'] ) ) {
			return $send;
		}

		$this->setAliasCookie(
			$this->cookieName( $scheme ),
			$cookie['value'],
			$expire
		);

		return $send;
	}

	public function clearAliasAuthCookies(): void {
		$this->pendingCookie = null;

		if ( headers_sent() ) {
			return;
		}

		$this->expireAliasCookies();
		delete_option( self::COOKIE_PATHS_OPTION );
	}

	public function mirrorCurrentAuthCookie(): bool {
		if ( ! $this->settings->activeAliasEnabled( 'admin' ) || headers_sent() ) {
			return false;
		}

		foreach ( array( SECURE_AUTH_COOKIE, AUTH_COOKIE ) as $name ) {
			$value = isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] )
				? wp_unslash( $_COOKIE[ $name ] )
				: '';

			if ( '' === $value ) {
				continue;
			}

			$this->setAliasCookie( $name, $value, 0 );
			return true;
		}

		return false;
	}

	private function expireAliasCookies(): void {
		$expired = time() - YEAR_IN_SECONDS;
		$paths   = $this->savedAliasPaths();
		if ( $this->settings->activeAliasEnabled( 'admin' ) ) {
			$paths[] = $this->currentAliasPath();
		}

		foreach ( array_unique( $paths ) as $path ) {
			$this->setCookieAtPath( AUTH_COOKIE, ' ', $expired, $path );
			$this->setCookieAtPath( SECURE_AUTH_COOKIE, ' ', $expired, $path );
		}
	}

	private function setAliasCookie( string $name, string $value, int $expire ): void {
		$path = $this->currentAliasPath();
		$this->expireStaleAliasPaths( $path );
		$this->setCookieAtPath( $name, $value, $expire, $path );
		update_option( self::COOKIE_PATHS_OPTION, array( $path ), false );
	}

	private function setCookieAtPath( string $name, string $value, int $expire, string $path ): void {
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expire,
				'path'     => $path,
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private function expireStaleAliasPaths( string $currentPath ): void {
		$expired = time() - YEAR_IN_SECONDS;
		foreach ( $this->savedAliasPaths() as $path ) {
			if ( hash_equals( $currentPath, $path ) ) {
				continue;
			}

			$this->setCookieAtPath( AUTH_COOKIE, ' ', $expired, $path );
			$this->setCookieAtPath( SECURE_AUTH_COOKIE, ' ', $expired, $path );
		}
	}

	private function currentAliasPath(): string {
		return $this->mapper->targetPath( 'admin', true );
	}

	/**
	 * @return list<string>
	 */
	private function savedAliasPaths(): array {
		$value = get_option( self::COOKIE_PATHS_OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$paths = array();
		foreach ( array_slice( $value, -self::MAX_TRACKED_PATHS ) as $path ) {
			if ( is_string( $path ) && $this->isValidCookiePath( $path ) ) {
				$paths[] = $path;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	private function rememberAliasPath( string $path ): void {
		if ( ! $this->isValidCookiePath( $path ) ) {
			return;
		}

		$paths = array_values( array_unique( array_merge( $this->savedAliasPaths(), array( $path ) ) ) );
		$paths = array_slice( $paths, -self::MAX_TRACKED_PATHS );
		update_option( self::COOKIE_PATHS_OPTION, $paths, false );
	}

	private function isValidCookiePath( string $path ): bool {
		return strlen( $path ) <= 256
			&& str_starts_with( $path, '/' )
			&& 1 !== preg_match( '/[\x00-\x20\x7f;,]/', $path );
	}

	private function cookieName( string $scheme ): string {
		return 'secure_auth' === $scheme ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
	}
}
