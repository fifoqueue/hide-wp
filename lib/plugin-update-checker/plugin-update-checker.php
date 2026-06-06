<?php
/**
 * Minimal Plugin Update Checker-compatible fallback for Hide WP Surface.
 *
 * The preferred runtime path is the official Composer package
 * yahnis-elsts/plugin-update-checker. This fallback implements only the small
 * subset of the v5 API that this plugin uses, so manually installed ZIPs keep
 * update support even when Composer dependencies were not bundled.
 */

declare(strict_types=1);

namespace YahnisElsts\PluginUpdateChecker\v5;

use stdClass;
use WP_Error;

if ( class_exists( __NAMESPACE__ . '\\PucFactory', false ) ) {
	return;
}

final class PucFactory {
	/**
	 * @return GitHubUpdateChecker
	 */
	public static function buildUpdateChecker( string $metadataUrl, string $pluginFile, string $slug ): object {
		return new GitHubUpdateChecker( $metadataUrl, $pluginFile, $slug );
	}
}

final class GitHubUpdateChecker {
	private string $authentication = '';
	private string $assetPattern = '/\.zip($|[?&#])/i';
	private string $repository = '';
	private string $basename = '';

	public function __construct(
		private string $metadataUrl,
		private string $pluginFile,
		private string $slug
	) {
		$this->repository = $this->repositoryFromUrl( $metadataUrl );
		$this->basename   = function_exists( 'plugin_basename' ) ? plugin_basename( $pluginFile ) : basename( dirname( $pluginFile ) ) . '/' . basename( $pluginFile );

		if ( '' === $this->repository ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filterUpdateTransient' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filterUpdateTransient' ) );
		add_filter( 'plugins_api', array( $this, 'filterPluginInfo' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clearCacheAfterUpgrade' ), 10, 2 );
	}

	public function setAuthentication( string $token ): void {
		$this->authentication = preg_replace( '/[^A-Za-z0-9_.-]+/', '', $token ) ?: '';
	}

	public function setBranch( string $branch ): void {
		unset( $branch );
	}

	public function getVcsApi(): self {
		return $this;
	}

	public function enableReleaseAssets( string $pattern = '/\.zip($|[?&#])/i' ): void {
		$this->assetPattern = $pattern;
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public function filterUpdateTransient( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || ! isset( $transient->checked ) || ! is_array( $transient->checked ) || ! isset( $transient->checked[ $this->basename ] ) ) {
			return $transient;
		}

		$update = $this->buildUpdateObject();
		if ( null === $update ) {
			return $transient;
		}

		$installed = is_string( $transient->checked[ $this->basename ] ?? null ) ? $transient->checked[ $this->basename ] : '';
		if ( '' !== $installed && version_compare( $update->new_version, $installed, '<=' ) ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->basename ] = $update;

			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->basename ] = $update;

		return $transient;
	}

	/**
	 * @param mixed $result
	 * @param mixed $args
	 * @return mixed
	 */
	public function filterPluginInfo( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}

		$release = $this->latestRelease();
		if ( null === $release ) {
			return $result;
		}

		$version = $this->releaseVersion( $release );
		$package = $this->packageUrl( $release );
		if ( '' === $version || '' === $package ) {
			return $result;
		}

		$info = new stdClass();
		$info->name = 'Hide WP Surface';
		$info->slug = $this->slug;
		$info->version = $version;
		$info->author = '<a href="https://github.com/fifoqueue">fifoqueue</a>';
		$info->homepage = 'https://github.com/' . $this->repository;
		$info->requires = '7.0';
		$info->tested = '7.0';
		$info->requires_php = '8.3';
		$info->last_updated = is_string( $release['published_at'] ?? null ) ? $release['published_at'] : '';
		$info->download_link = $package;
		$body = is_string( $release['body'] ?? null ) && '' !== trim( $release['body'] ) ? $release['body'] : 'Release ' . $version;
		$info->sections = array(
			'description' => 'Reduces exposed WordPress paths and removable HTML fingerprints with verified server-side aliases.',
			'changelog'   => function_exists( 'wpautop' ) && function_exists( 'esc_html' ) ? wpautop( esc_html( $body ) ) : $body,
		);
		$info->banners = array();
		$info->icons = array();

		return $info;
	}

	/**
	 * @param mixed $upgrader
	 * @param array<string, mixed> $hookExtra
	 */
	public function clearCacheAfterUpgrade( mixed $upgrader, array $hookExtra ): void {
		unset( $upgrader );

		if ( 'update' === ( $hookExtra['action'] ?? '' ) && 'plugin' === ( $hookExtra['type'] ?? '' ) ) {
			delete_site_transient( $this->cacheKey() );
			delete_site_transient( 'update_plugins' );
		}
	}

	private function buildUpdateObject(): ?stdClass {
		$release = $this->latestRelease();
		if ( null === $release ) {
			return null;
		}

		$version = $this->releaseVersion( $release );
		$package = $this->packageUrl( $release );
		if ( '' === $version || '' === $package ) {
			return null;
		}

		$update = new stdClass();
		$update->id = 'https://github.com/' . $this->repository;
		$update->slug = $this->slug;
		$update->plugin = $this->basename;
		$update->new_version = $version;
		$update->version = $version;
		$update->url = is_string( $release['html_url'] ?? null ) ? $release['html_url'] : 'https://github.com/' . $this->repository;
		$update->package = $package;
		$update->tested = '7.0';
		$update->requires = '7.0';
		$update->requires_php = '8.3';

		return $update;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function latestRelease(): ?array {
		$cacheKey = $this->cacheKey();
		$cached = get_site_transient( $cacheKey );
		if ( is_array( $cached ) ) {
			return true === ( $cached['ok'] ?? false ) && is_array( $cached['release'] ?? null ) ? $cached['release'] : null;
		}

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'Hide WP Surface Plugin Update Checker; ' . ( function_exists( 'home_url' ) ? home_url( '/' ) : 'WordPress' ),
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== $this->authentication ) {
			$headers['Authorization'] = 'Bearer ' . $this->authentication;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repository . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if ( $response instanceof WP_Error || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( $cacheKey, array( 'ok' => false ), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || '' === $this->releaseVersion( $release ) || '' === $this->packageUrl( $release ) ) {
			set_site_transient( $cacheKey, array( 'ok' => false ), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		set_site_transient( $cacheKey, array( 'ok' => true, 'release' => $release ), 12 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function releaseVersion( array $release ): string {
		$tag = is_string( $release['tag_name'] ?? null ) ? ltrim( $release['tag_name'], "vV \t\n\r\0\x0B" ) : '';

		return 1 === preg_match( '/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $tag ) ? $tag : '';
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function packageUrl( array $release ): string {
		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$fallback = '';
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$url = is_string( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';
			$name = is_string( $asset['name'] ?? null ) ? $asset['name'] : '';
			if ( '' === $url || '' === $name || 1 !== preg_match( $this->assetPattern, $url ) && 1 !== preg_match( $this->assetPattern, $name ) ) {
				continue;
			}
			if ( 1 === preg_match( '/hide-wp-surface.*\.zip\z/i', $name ) ) {
				return $url;
			}
			if ( '' === $fallback ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}

	private function repositoryFromUrl( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );
		$parts = explode( '/', $path );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$repo = $parts[0] . '/' . $parts[1];

		return 1 === preg_match( '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repo ) ? $repo : '';
	}

	private function cacheKey(): string {
		return 'hide_wp_puc_latest_' . substr( hash( 'sha256', strtolower( $this->repository ) ), 0, 12 );
	}
}
