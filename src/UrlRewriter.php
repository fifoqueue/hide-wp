<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class UrlRewriter {
	public function __construct( private PathMapper $mapper ) {
	}

	public function boot(): void {
		$this->disableAdminAssetConcatenation();

		foreach (
			array(
				'admin_url',
				'content_url',
				'icon_dir_uri',
				'includes_url',
				'logout_url',
				'lostpassword_url',
				'network_admin_url',
				'plugins_url',
				'register_url',
				'script_loader_src',
				'stylesheet_directory_uri',
				'stylesheet_uri',
				'style_loader_src',
				'template_directory_uri',
				'theme_file_uri',
				'theme_root_uri',
				'user_admin_url',
				'wp_admin_css_uri',
				'wp_get_attachment_url',
				'wp_get_original_image_url',
				'wp_mime_type_icon',
				'parent_theme_file_uri',
			) as $hook
		) {
			add_filter( $hook, array( $this, 'rewrite' ), PHP_INT_MAX, 4 );
		}

		add_filter( 'login_url', array( $this, 'rewriteLoginUrl' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'rewriteImageSource' ), PHP_INT_MAX, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'rewriteSrcsetSources' ), PHP_INT_MAX, 5 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'rewriteImageAttributes' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_get_custom_css', array( $this, 'rewriteEmbeddedPaths' ), PHP_INT_MAX, 2 );
		add_filter( 'site_url', array( $this, 'rewrite' ), PHP_INT_MAX, 4 );
		add_filter( 'network_site_url', array( $this, 'rewrite' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_redirect', array( $this, 'rewrite' ), PHP_INT_MAX, 2 );
	}

	private function disableAdminAssetConcatenation(): void {
		if ( in_array( 'admin', $this->mapper->activeAliasTypes(), true ) ) {
			$GLOBALS['concatenate_scripts'] = false;
		}
	}

	public function rewrite( mixed $url, mixed ...$unused ): mixed {
		return is_string( $url ) ? $this->mapper->rewriteUrl( $url ) : $url;
	}

	public function rewriteLoginUrl( mixed $url, mixed $redirect = '', mixed $forceReauth = false ): mixed {
		unset( $forceReauth );

		if ( ! is_string( $url ) ) {
			return $url;
		}

		$url = $this->mapper->rewriteUrl( $url );
		if ( ! is_string( $redirect ) || ! $this->isCurrentLoginAliasUrl( $redirect, $url ) ) {
			return $url;
		}

		// Authorizer treats a public login alias as an embedded login form and
		// passes the current page back through wp_login_url(). Keeping that
		// self-reference sends a successful OIDC login to the callback URL again,
		// where its one-time authorization code can no longer be exchanged.
		return remove_query_arg( 'redirect_to', $url );
	}

	public function rewriteEmbeddedPaths( mixed $value, mixed ...$unused ): mixed {
		return is_string( $value ) ? $this->mapper->rewriteEmbeddedPaths( $value ) : $value;
	}

	private function isCurrentLoginAliasUrl( string $url, string $loginUrl ): bool {
		$requestUri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$requestPath = wp_parse_url( $requestUri, PHP_URL_PATH );
		$urlPath     = wp_parse_url( $url, PHP_URL_PATH );
		$loginPath   = wp_parse_url( $loginUrl, PHP_URL_PATH );
		$aliasPath   = $this->mapper->targetPath( 'login' );

		if ( ! is_string( $requestPath ) || ! is_string( $urlPath ) || ! is_string( $loginPath ) ) {
			return false;
		}

		if (
			0 !== strcasecmp( untrailingslashit( $requestPath ), untrailingslashit( $aliasPath ) )
			|| 0 !== strcasecmp( untrailingslashit( $urlPath ), untrailingslashit( $aliasPath ) )
			|| 0 !== strcasecmp( untrailingslashit( $loginPath ), untrailingslashit( $aliasPath ) )
		) {
			return false;
		}

		$requestQuery = wp_parse_url( $requestUri, PHP_URL_QUERY );
		$urlQuery     = wp_parse_url( $url, PHP_URL_QUERY );

		return hash_equals(
			is_string( $requestQuery ) ? $requestQuery : '',
			is_string( $urlQuery ) ? $urlQuery : ''
		);
	}

	/**
	 * @param array<int, bool|int|string>|false $image Attachment image data.
	 * @return array<int, bool|int|string>|false
	 */
	public function rewriteImageSource( array|false $image, mixed ...$unused ): array|false {
		if ( false !== $image && isset( $image[0] ) && is_string( $image[0] ) ) {
			$image[0] = $this->mapper->rewriteUrl( $image[0] );
		}

		return $image;
	}

	/**
	 * @param array<int|string, array<string, mixed>> $sources Responsive image sources.
	 * @return array<int|string, array<string, mixed>>
	 */
	public function rewriteSrcsetSources( array $sources, mixed ...$unused ): array {
		foreach ( $sources as &$source ) {
			if ( isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$source['url'] = $this->mapper->rewriteUrl( $source['url'] );
			}
		}
		unset( $source );

		return $sources;
	}

	/**
	 * @param array<string, mixed> $attributes Image element attributes.
	 * @return array<string, mixed>
	 */
	public function rewriteImageAttributes( array $attributes, mixed ...$unused ): array {
		if ( isset( $attributes['src'] ) && is_string( $attributes['src'] ) ) {
			$attributes['src'] = $this->mapper->rewriteUrl( $attributes['src'] );
		}

		if ( isset( $attributes['srcset'] ) && is_string( $attributes['srcset'] ) ) {
			$attributes['srcset'] = (string) preg_replace_callback(
				'~(?:https?:)?//[^\s,]+|/[^\s,]+~i',
				fn ( array $match ): string => $this->mapper->rewriteUrl( $match[0] ),
				$attributes['srcset']
			);
		}

		return $attributes;
	}
}
