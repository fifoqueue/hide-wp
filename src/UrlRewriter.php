<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class UrlRewriter {
	public function __construct( private PathMapper $mapper ) {
	}

	public function boot(): void {
		foreach (
			array(
				'admin_url',
				'content_url',
				'icon_dir_uri',
				'includes_url',
				'login_url',
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

		add_filter( 'wp_get_attachment_image_src', array( $this, 'rewriteImageSource' ), PHP_INT_MAX, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'rewriteSrcsetSources' ), PHP_INT_MAX, 5 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'rewriteImageAttributes' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_get_custom_css', array( $this, 'rewriteEmbeddedPaths' ), PHP_INT_MAX, 2 );
		add_filter( 'site_url', array( $this, 'rewrite' ), PHP_INT_MAX, 4 );
		add_filter( 'network_site_url', array( $this, 'rewrite' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_redirect', array( $this, 'rewrite' ), PHP_INT_MAX, 2 );
	}

	public function rewrite( mixed $url, mixed ...$unused ): mixed {
		return is_string( $url ) ? $this->mapper->rewriteUrl( $url ) : $url;
	}

	public function rewriteEmbeddedPaths( mixed $value, mixed ...$unused ): mixed {
		return is_string( $value ) ? $this->mapper->rewriteEmbeddedPaths( $value ) : $value;
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
