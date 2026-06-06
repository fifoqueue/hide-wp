<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class AuthCookieBridge {
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
		add_action( 'set_auth_cookie', array( $this, 'rememberAuthCookie' ), PHP_INT_MAX, 6 );
		add_filter( 'send_auth_cookies', array( $this, 'sendAliasAuthCookie' ), PHP_INT_MAX, 6 );
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

	public function sendAliasAuthCookie(
		bool $send,
		int $expire,
		int $expiration,
		int $userId,
		string $scheme,
		string $token
	): bool {
		unset( $token );

		if ( ! $send || ! $this->settings->pathsEnabled() || headers_sent() ) {
			$this->pendingCookie = null;
			return $send;
		}

		if ( 0 === $expire && 0 === $expiration && 0 === $userId && '' === $scheme ) {
			$this->expireAliasCookies();
			$this->pendingCookie = null;
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
			$cookie['expire'],
			'secure_auth' === $scheme
		);

		return $send;
	}

	public function mirrorCurrentAuthCookie(): bool {
		if ( ! $this->settings->pathsEnabled() || headers_sent() ) {
			return false;
		}

		foreach (
			array(
				'secure_auth' => SECURE_AUTH_COOKIE,
				'auth'        => AUTH_COOKIE,
			) as $scheme => $name
		) {
			$value = isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] )
				? wp_unslash( $_COOKIE[ $name ] )
				: '';

			if ( '' === $value ) {
				continue;
			}

			$this->setAliasCookie( $name, $value, 0, 'secure_auth' === $scheme );
			return true;
		}

		return false;
	}

	private function expireAliasCookies(): void {
		$expired = time() - YEAR_IN_SECONDS;

		$this->setAliasCookie( AUTH_COOKIE, ' ', $expired, false );
		$this->setAliasCookie( SECURE_AUTH_COOKIE, ' ', $expired, true );
	}

	private function setAliasCookie( string $name, string $value, int $expire, bool $secure ): void {
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expire,
				'path'     => $this->mapper->targetPath( 'admin' ),
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private function cookieName( string $scheme ): string {
		return 'secure_auth' === $scheme ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
	}
}
