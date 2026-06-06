<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class PathMapper {
	public function __construct( private Settings $settings ) {
	}

	public function rewriteUrl( string $url, bool $forcePaths = false, bool $forceLogin = false ): string {
		if ( '' === $url || ! $this->isLocalUrl( $url ) ) {
			return $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return $url;
		}

		$newPath = $this->rewritePath( $path, $forcePaths, $forceLogin );
		if ( $newPath === $path ) {
			return $url;
		}

		$position = strpos( $url, $path );
		if ( false === $position ) {
			return $url;
		}

		return substr_replace( $url, $newPath, $position, strlen( $path ) );
	}

	public function rewritePath( string $path, bool $forcePaths = false, bool $forceLogin = false ): string {
		if ( $forceLogin || $this->settings->loginEnabled() ) {
			$path = $this->replacePrefix( $path, $this->sourcePath( 'login' ), $this->targetPath( 'login' ) );
		}

		$types = $forcePaths
			? $this->requestedAliasTypes()
			: $this->activeAliasTypes();
		foreach ( $types as $type ) {
			$path = $this->replacePrefix( $path, $this->sourcePath( $type ), $this->targetPath( $type, ! $forcePaths ) );
		}

		return $path;
	}

	public function rewriteEmbeddedPaths( string $value ): string {
		if ( ! $this->settings->pathsEnabled() ) {
			return $value;
		}

		return (string) preg_replace_callback(
			'~(?:https?:)?//[^\s"\'(),]+|/[^\s"\'(),]+~i',
			fn ( array $match ): string => $this->rewriteUrl( $match[0] ),
			$value
		);
	}

	public function sourcePath( string $type ): string {
		$sitePath = $this->urlPath( (string) get_option( 'siteurl', '' ) );

		return match ( $type ) {
			'login'    => $this->joinPath( $sitePath, 'wp-login.php' ),
			'admin'    => $this->joinPath( $sitePath, 'wp-admin' ),
			'content'  => $this->urlPath( $this->rawContentUrl() ),
			'includes' => $this->joinPath( $sitePath, 'wp-includes' ),
			default    => '/',
		};
	}

	public function targetPath( string $type, bool $active = false ): string {
		$source = $this->sourcePath( $type );
		$parent = $this->parentPath( $source );
		$slug   = match ( $type ) {
			'login'    => $this->settings->getSlug( 'login_slug' ),
			'admin'    => $active ? $this->settings->getActiveSlug( 'admin' ) : $this->settings->getSlug( 'admin_slug' ),
			'content'  => $active ? $this->settings->getActiveSlug( 'content' ) : $this->settings->getSlug( 'content_slug' ),
			'includes' => $active ? $this->settings->getActiveSlug( 'includes' ) : $this->settings->getSlug( 'includes_slug' ),
			default    => '',
		};

		return $this->joinPath( $parent, $slug );
	}

	/**
	 * @return list<string>
	 */
	public function requestedAliasTypes(): array {
		return $this->settings->requestedAliasTypes();
	}

	/**
	 * @return list<string>
	 */
	public function activeAliasTypes(): array {
		return $this->settings->activeAliasTypes();
	}

	public function rawContentUrl(): string {
		if ( defined( 'WP_CONTENT_URL' ) && is_string( WP_CONTENT_URL ) ) {
			return WP_CONTENT_URL;
		}

		return rtrim( (string) get_option( 'siteurl', '' ), '/' ) . '/wp-content';
	}

	public function supportsVerifiedAliases(): bool {
		$site    = wp_parse_url( (string) get_option( 'siteurl', '' ) );
		$content = wp_parse_url( $this->rawContentUrl() );

		if ( ! is_array( $site ) || ! is_array( $content ) ) {
			return false;
		}

		$siteScheme    = strtolower( (string) ( $site['scheme'] ?? '' ) );
		$contentScheme = strtolower( (string) ( $content['scheme'] ?? '' ) );
		$sitePath      = $this->urlPath( (string) get_option( 'siteurl', '' ) );
		$contentPath   = $this->urlPath( $this->rawContentUrl() );

		return 'https' === $siteScheme
			&& ! isset( $site['user'] )
			&& ! isset( $site['pass'] )
			&& ! isset( $content['user'] )
			&& ! isset( $content['pass'] )
			&& '' !== (string) ( $site['host'] ?? '' )
			&& hash_equals( $siteScheme, $contentScheme )
			&& $this->isSafeServerPath( $sitePath )
			&& $this->isSafeServerPath( $contentPath )
			&& hash_equals(
				strtolower( (string) ( $site['host'] ?? '' ) ),
				strtolower( (string) ( $content['host'] ?? '' ) )
			)
			&& $this->urlPort( $site ) === $this->urlPort( $content )
			&& hash_equals( $sitePath, $this->parentPath( $contentPath ) );
	}

	public function parentPath( string $path ): string {
		$path = '/' . trim( str_replace( '\\', '/', $path ), '/' );
		$lastSlash = strrpos( $path, '/' );

		return false === $lastSlash || 0 === $lastSlash ? '' : substr( $path, 0, $lastSlash );
	}

	private function isLocalUrl( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return false;
		}

		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return true;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );
		$port = $this->urlPort( $parts );

		foreach (
			array(
				(string) get_option( 'siteurl', '' ),
				(string) get_option( 'home', '' ),
				$this->rawContentUrl(),
			) as $allowedUrl
		) {
			$allowed = wp_parse_url( $allowedUrl );
			if ( ! is_array( $allowed ) || ! isset( $allowed['host'] )
				|| ! hash_equals( strtolower( (string) $allowed['host'] ), $host ) ) {
				continue;
			}

			$allowedScheme = strtolower( (string) ( $allowed['scheme'] ?? '' ) );
			if ( '' !== $scheme && '' !== $allowedScheme && ! hash_equals( $allowedScheme, $scheme ) ) {
				continue;
			}

			$allowedPort = $this->urlPort( $allowed );
			if ( 0 === $port || $allowedPort === $port ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $parts Parsed URL parts.
	 */
	private function urlPort( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return match ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			'http'  => 80,
			'https' => 443,
			default => 0,
		};
	}

	private function isSafeServerPath( string $path ): bool {
		if ( '' === $path ) {
			return true;
		}

		if ( 1 !== preg_match( '#\A(?:/[a-zA-Z0-9._~-]+)+\z#D', $path ) ) {
			return false;
		}

		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	private function replacePrefix( string $path, string $source, string $target ): string {
		if ( $path !== $source && ! str_starts_with( $path, $source . '/' ) ) {
			return $path;
		}

		return $target . substr( $path, strlen( $source ) );
	}

	private function urlPath( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? trim( $path, '/' ) : '';

		return '' === $path ? '' : '/' . $path;
	}

	private function joinPath( string $base, string $leaf ): string {
		return '/' . trim( trim( $base, '/' ) . '/' . trim( $leaf, '/' ), '/' );
	}
}
