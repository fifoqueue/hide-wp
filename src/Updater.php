<?php

declare(strict_types=1);

namespace HideWp;

use stdClass;
use WP_Error;

use function add_filter;
use function add_action;
use function defined;
use function delete_site_transient;
use function esc_html;
use function get_site_transient;
use function home_url;
use function is_array;
use function is_string;
use function in_array;
use function json_decode;
use function ltrim;
use function preg_match;
use function property_exists;
use function set_site_transient;
use function sprintf;
use function str_ends_with;
use function strtolower;
use function trim;
use function version_compare;
use function wp_json_encode;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wpautop;
use function wp_kses_post;

use const HOUR_IN_SECONDS;
use const MINUTE_IN_SECONDS;

final class Updater {
	private const SLUG = 'hide-wp-surface';
	private const CACHE_KEY = 'hide_wp_github_latest_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	private const PREFERRED_ASSETS = array(
		'hide-wp-surface.zip',
		'hide-wp-master.zip',
		'hide-wp.zip',
	);

	public function boot(): void {
		if ( defined( 'HIDE_WP_DISABLE_GITHUB_UPDATER' ) && true === HIDE_WP_DISABLE_GITHUB_UPDATER ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filterUpdateTransient' ) );
		add_filter( 'update_plugins_github.com', array( $this, 'filterUpdateUri' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'pluginInfo' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clearCacheAfterUpgrade' ), 10, 2 );
	}

	/**
	 * @param mixed $transient WordPress update transient.
	 * @return mixed
	 */
	public function filterUpdateTransient( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || ! property_exists( $transient, 'checked' ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! isset( $transient->checked[ HIDE_WP_BASENAME ] ) ) {
			return $transient;
		}

		$update = $this->buildUpdateObject();
		if ( null === $update ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ HIDE_WP_BASENAME ] = $update;

		return $transient;
	}

	/**
	 * Provides update data through WordPress' official Update URI flow.
	 *
	 * @param mixed $update Existing update data for this Update URI host.
	 * @param array<string, mixed> $pluginData Plugin headers from get_plugins().
	 * @param string $pluginFile Plugin basename being checked.
	 * @param string[] $locales Installed locales to look up translations for.
	 * @return mixed
	 */
	public function filterUpdateUri( mixed $update, array $pluginData, string $pluginFile, array $locales ): mixed {
		unset( $pluginData, $locales );

		if ( HIDE_WP_BASENAME !== $pluginFile ) {
			return $update;
		}

		$updateObject = $this->buildUpdateObject();
		if ( null === $updateObject ) {
			return false;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $updateObject->new_version,
			'url'          => $updateObject->url,
			'package'      => $updateObject->package,
			'tested'       => $updateObject->tested,
			'requires'     => $updateObject->requires,
			'requires_php' => $updateObject->requires_php,
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * @param mixed $result Existing plugins_api result.
	 * @param string $action Requested API action.
	 * @param mixed $args API arguments.
	 * @return mixed
	 */
	public function pluginInfo( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$release = $this->latestRelease();
		if ( null === $release ) {
			return $result;
		}

		$version = $this->releaseVersion( $release );
		$package = $this->packageUrl( $release );
		if ( '' === $version ) {
			return $result;
		}

		$info = new stdClass();
		$info->name = 'Hide WP Surface';
		$info->slug = self::SLUG;
		$info->version = $version;
		$info->author = '<a href="https://github.com/fifoqueue">fifoqueue</a>';
		$info->homepage = $this->repositoryUrl();
		$info->requires = '7.0';
		$info->tested = '7.0';
		$info->requires_php = '8.3';
		$info->last_updated = is_string( $release['published_at'] ?? null ) ? $release['published_at'] : '';
		$info->download_link = $package;
		$info->sections = array(
			'description' => esc_html__( 'Reduces exposed WordPress paths and removable HTML fingerprints with verified server-side aliases.', 'hide-wp' ),
			'changelog'   => $this->releaseNotes( $release ),
		);
		$info->banners = array();
		$info->icons = array();

		return $info;
	}

	/**
	 * @param mixed $upgrader WP_Upgrader instance.
	 * @param array<string, mixed> $hookExtra Upgrade metadata.
	 */
	public function clearCacheAfterUpgrade( mixed $upgrader, array $hookExtra ): void {
		unset( $upgrader );

		if ( 'update' === ( $hookExtra['action'] ?? '' ) && 'plugin' === ( $hookExtra['type'] ?? '' ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	private function buildUpdateObject(): ?stdClass {
		$release = $this->latestRelease();
		if ( null === $release ) {
			return null;
		}

		$version = $this->releaseVersion( $release );
		$package = $this->packageUrl( $release );
		if ( '' === $version || '' === $package || version_compare( $version, HIDE_WP_VERSION, '<=' ) ) {
			return null;
		}

		$update = new stdClass();
		$update->id = $this->repositoryUrl();
		$update->slug = self::SLUG;
		$update->plugin = HIDE_WP_BASENAME;
		$update->new_version = $version;
		$update->url = is_string( $release['html_url'] ?? null ) ? $release['html_url'] : $this->repositoryUrl();
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
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return true === ( $cached['ok'] ?? false ) && is_array( $cached['release'] ?? null ) ? $cached['release'] : null;
		}

		$repository = $this->repository();
		if ( '' === $repository ) {
			$this->cacheFailure();
			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repository . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Hide WP Surface/' . HIDE_WP_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( $response instanceof WP_Error || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->cacheFailure();
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || '' === $this->releaseVersion( $release ) ) {
			$this->cacheFailure();
			return null;
		}

		if ( '' === $this->packageUrl( $release ) ) {
			$this->cacheFailure( 5 * MINUTE_IN_SECONDS );
			return null;
		}

		set_site_transient(
			self::CACHE_KEY,
			array(
				'ok'      => true,
				'release' => $release,
			),
			self::CACHE_TTL
		);

		return $release;
	}

	private function cacheFailure( int $ttl = HOUR_IN_SECONDS ): void {
		set_site_transient(
			self::CACHE_KEY,
			array(
				'ok' => false,
			),
			$ttl
		);
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function releaseVersion( array $release ): string {
		$tag = is_string( $release['tag_name'] ?? null ) ? ltrim( $release['tag_name'], "vV \t\n\r\0\x0B" ) : '';
		if ( 1 !== preg_match( '/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $tag ) ) {
			return '';
		}

		return $tag;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function packageUrl( array $release ): string {
		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$fallbackZip = '';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = is_string( $asset['name'] ?? null ) ? strtolower( $asset['name'] ) : '';
			$url  = is_string( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';
			if ( '' === $name || '' === $url || ! str_ends_with( $name, '.zip' ) ) {
				continue;
			}

			if ( in_array( $name, self::PREFERRED_ASSETS, true ) ) {
				return $url;
			}

			if ( '' === $fallbackZip ) {
				$fallbackZip = $url;
			}
		}

		return $fallbackZip;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function releaseNotes( array $release ): string {
		$body = is_string( $release['body'] ?? null ) && '' !== trim( $release['body'] )
			? $release['body']
			: sprintf( 'Release %s', $this->releaseVersion( $release ) );

		return wp_kses_post( wpautop( esc_html( $body ) ) );
	}

	private function repository(): string {
		$repository = defined( 'HIDE_WP_GITHUB_REPOSITORY' ) && is_string( HIDE_WP_GITHUB_REPOSITORY )
			? HIDE_WP_GITHUB_REPOSITORY
			: 'fifoqueue/hide-wp-surface';

		return 1 === preg_match( '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repository ) ? $repository : '';
	}

	private function repositoryUrl(): string {
		$repository = $this->repository();

		return '' === $repository ? 'https://github.com/fifoqueue/hide-wp-surface' : 'https://github.com/' . $repository;
	}
}
