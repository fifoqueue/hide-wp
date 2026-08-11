<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class HtmlCleaner {
	public function __construct( private Settings $settings ) {
	}

	public function boot(): void {
		if ( $this->settings->getBool( 'remove_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string', PHP_INT_MAX );
		}

		if ( $this->settings->getBool( 'remove_discovery_links' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
			add_filter( 'wp_headers', array( $this, 'removeDisclosureHeaders' ), PHP_INT_MAX );
		}

		if ( $this->settings->getBool( 'strip_core_version' ) ) {
			add_filter( 'script_loader_src', array( $this, 'stripCoreVersion' ), PHP_INT_MAX, 2 );
			add_filter( 'style_loader_src', array( $this, 'stripCoreVersion' ), PHP_INT_MAX, 2 );
		}
	}

	/**
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, string>
	 */
	public function removeDisclosureHeaders( array $headers ): array {
		unset( $headers['X-Pingback'], $headers['x-pingback'] );

		return $headers;
	}

	public function stripCoreVersion( mixed $src, mixed $handle = null ): mixed {
		if ( ! is_string( $src ) ) {
			return $src;
		}

		$version = wp_parse_url( $src, PHP_URL_QUERY );
		if ( ! is_string( $version ) ) {
			return $src;
		}

		parse_str( $version, $query );
		$coreVersion = (string) ( $GLOBALS['wp_version'] ?? '' );

		return isset( $query['ver'] ) && is_string( $query['ver'] ) && hash_equals( $coreVersion, $query['ver'] )
			? remove_query_arg( 'ver', $src )
			: $src;
	}
}
