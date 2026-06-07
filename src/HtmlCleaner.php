<?php

declare(strict_types=1);

namespace HideWp;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

final readonly class HtmlCleaner {
	private const MAX_BUFFER_BYTES = 8_388_608;

	public function __construct(
		private Settings $settings,
		private PathMapper $mapper
	) {
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

		add_action( 'wp_loaded', array( $this, 'startBuffer' ), 1 );
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

	public function startBuffer(): void {
		if ( ! $this->shouldBuffer() ) {
			return;
		}

		ob_start( array( $this, 'processBuffer' ) );
	}

	public function processBuffer( string $html ): string {
		if ( '' === $html || strlen( $html ) > self::MAX_BUFFER_BYTES || ! $this->isHtmlResponse( $html ) ) {
			return $html;
		}

		if ( ! $this->settings->loginEnabled() && ! $this->settings->pathsEnabled() ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		$attributes = array(
			'action',
			'data-src',
			'data-srcset',
			'formaction',
			'href',
			'poster',
			'src',
			'srcset',
			'style',
		);

		while ( $processor->next_tag() ) {
			foreach ( $attributes as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				$rewritten = 'style' === $attribute
					? $this->mapper->rewriteEmbeddedPaths( $value )
					: $this->rewriteAttribute( $value );
				if ( $rewritten !== $value ) {
					$processor->set_attribute( $attribute, $rewritten );
				}
			}
		}

		return $processor->get_updated_html();
	}

	private function shouldBuffer(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST || defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( $_SERVER['REQUEST_METHOD'] )
			: 'GET';

		if ( $this->pageCacheMayStoreResponse( $method ) ) {
			return false;
		}

		return in_array( $method, array( 'GET', 'HEAD', 'POST' ), true );
	}

	private function pageCacheMayStoreResponse( string $method ): bool {
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		if ( is_admin() ) {
			return false;
		}

		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			return false;
		}

		return ! defined( 'DONOTCACHEPAGE' ) || ! DONOTCACHEPAGE;
	}

	private function isHtmlResponse( string $html ): bool {
		foreach ( headers_list() as $header ) {
			if ( str_starts_with( strtolower( $header ), 'content-type:' ) ) {
				return str_contains( strtolower( $header ), 'text/html' )
					|| str_contains( strtolower( $header ), 'application/xhtml+xml' );
			}
		}

		$prefix = strtolower( substr( ltrim( $html ), 0, 256 ) );

		return str_starts_with( $prefix, '<!doctype html' ) || str_starts_with( $prefix, '<html' );
	}

	private function rewriteAttribute( string $value ): string {
		if ( str_starts_with( strtolower( ltrim( $value ) ), 'data:' ) ) {
			return $value;
		}

		if ( str_contains( $value, ',' ) || str_contains( $value, ' ' ) ) {
			return (string) preg_replace_callback(
				'~(?:https?:)?//[^\s,]+|/[^\s,]+~i',
				fn ( array $match ): string => $this->mapper->rewriteUrl( $match[0] ),
				$value
			);
		}

		return $this->mapper->rewriteUrl( $value );
	}
}
