<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION = 'hide_wp_settings';
	public const LOGIN_VERIFIED_OPTION = 'hide_wp_login_verified_hash';

	/**
	 * @return array<string, bool|string>
	 */
	public static function defaults(): array {
		return array(
			'login_enabled'          => false,
			'login_slug'             => 'login',
			'path_aliases_enabled'   => false,
			'admin_slug'             => 'control',
			'content_slug'           => 'assets',
			'includes_slug'          => 'system-assets',
			'remove_generator'       => true,
			'remove_discovery_links' => true,
			'strip_core_version'     => true,
			'generic_login_errors'   => true,
			'verified_hash'          => '',
		);
	}

	/**
	 * @return array<string, bool|string>
	 */
	public function all(): array {
		$value = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $value ) ? $value : array() );
	}

	public function getString( string $key ): string {
		$value = $this->all()[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	public function getSlug( string $key ): string {
		$value    = $this->getString( $key );
		$defaults = self::defaults();
		$fallback = isset( $defaults[ $key ] ) && is_string( $defaults[ $key ] ) ? $defaults[ $key ] : '';

		return $this->isValidSlug( $value ) ? $value : $fallback;
	}

	public function getBool( string $key ): bool {
		return true === ( $this->all()[ $key ] ?? false );
	}

	public function loginEnabled(): bool {
		$verifiedHash = get_option( self::LOGIN_VERIFIED_OPTION, '' );

		return $this->loginRequested()
			&& is_string( $verifiedHash )
			&& hash_equals( $this->loginConfigurationHash(), $verifiedHash );
	}

	public function loginRequested(): bool {
		return ! is_multisite()
			&& ! Marker::isRecoveryRequested()
			&& $this->getBool( 'login_enabled' );
	}

	public function markLoginVerified( string $expectedHash ): bool {
		$hash = $this->loginConfigurationHash();
		if (
			! hash_equals( $expectedHash, $hash )
			|| ! $this->loginRequested()
			|| $this->hasRouteCollision( $this->getSlug( 'login_slug' ) )
		) {
			return false;
		}

		if ( update_option( self::LOGIN_VERIFIED_OPTION, $hash, false ) ) {
			return true;
		}

		$fresh = get_option( self::LOGIN_VERIFIED_OPTION, '' );

		return is_string( $fresh ) && hash_equals( $hash, $fresh );
	}

	public function pathsEnabled(): bool {
		if ( Marker::isRecoveryRequested() || ! Marker::isEnabled() ) {
			return false;
		}

		if ( is_multisite() || ! $this->getBool( 'path_aliases_enabled' )
			|| ! hash_equals( $this->configurationHash(), $this->getString( 'verified_hash' ) ) ) {
			if ( Marker::disable() || Marker::requestRecovery() ) {
				return false;
			}

			// Keep PHP-side aliases active if neither the server marker nor a recovery file can be written.
			return true;
		}

		return true;
	}

	public function configurationHash(): string {
		$data = array(
			'admin'    => $this->getSlug( 'admin_slug' ),
			'content'  => $this->getSlug( 'content_slug' ),
			'includes' => $this->getSlug( 'includes_slug' ),
			'siteurl'  => (string) get_option( 'siteurl', '' ),
			'home'     => (string) get_option( 'home', '' ),
		);

		return hash( 'sha256', wp_json_encode( $data ) ?: '' );
	}

	public function loginConfigurationHash(): string {
		$data = array(
			'login'   => $this->getSlug( 'login_slug' ),
			'siteurl' => (string) get_option( 'siteurl', '' ),
			'home'    => (string) get_option( 'home', '' ),
		);

		return hash( 'sha256', wp_json_encode( $data ) ?: '' );
	}

	/**
	 * @param mixed $input Untrusted Settings API input.
	 * @return array<string, bool|string>
	 */
	public function sanitize( mixed $input ): array {
		$current    = $this->all();
		$input      = is_array( $input ) ? $input : array();
		$result     = $current;
		$loginValid = true;
		$pathsValid = true;

		foreach ( array( 'login_slug', 'admin_slug', 'content_slug', 'includes_slug' ) as $key ) {
			$proposed = isset( $input[ $key ] ) && is_string( $input[ $key ] )
				? strtolower( trim( wp_unslash( $input[ $key ] ) ) )
				: (string) $current[ $key ];

			if ( ! $this->isValidSlug( $proposed ) ) {
				add_settings_error(
					self::OPTION,
					'invalid_' . $key,
					sprintf(
						/* translators: %s: invalid path slug. */
						__( 'The path "%s" is invalid or reserved. Use 1-63 lowercase ASCII letters, numbers, or hyphens.', 'hide-wp' ),
						esc_html( $proposed )
					),
					'error'
				);
				if ( 'login_slug' === $key ) {
					$loginValid = false;
				} else {
					$pathsValid = false;
				}
				continue;
			}

			$result[ $key ] = $proposed;
		}

		foreach (
			array(
				'login_enabled',
				'remove_generator',
				'remove_discovery_links',
				'strip_core_version',
				'generic_login_errors',
			) as $key
		) {
			$result[ $key ] = isset( $input[ $key ] ) && '1' === (string) $input[ $key ];
		}

		if ( ! $loginValid ) {
			$result['login_enabled'] = $current['login_enabled'];
		}

		foreach ( array( 'login_slug', 'admin_slug', 'content_slug', 'includes_slug' ) as $key ) {
			$slug = (string) $result[ $key ];
			$isNewLoginRoute = 'login_slug' === $key
				&& true === $result['login_enabled']
				&& false === (bool) $current['login_enabled'];

			if ( ( $slug === $current[ $key ] && ! $isNewLoginRoute ) || ! $this->hasRouteCollision( $slug ) ) {
				continue;
			}

			add_settings_error(
				self::OPTION,
				'collision_' . $key,
				sprintf(
					/* translators: %s: path slug that conflicts with an existing route. */
					__( 'The path "%s" conflicts with existing content or a file in the WordPress directory.', 'hide-wp' ),
					esc_html( $slug )
				),
				'error'
			);
			$result[ $key ] = $current[ $key ];
			if ( 'login_slug' === $key ) {
				$result['login_enabled'] = $current['login_enabled'];
				$loginValid             = false;
			} else {
				$pathsValid = false;
			}
		}

		$slugs = array(
			(string) $result['login_slug'],
			(string) $result['admin_slug'],
			(string) $result['content_slug'],
			(string) $result['includes_slug'],
		);

		if ( count( array_unique( $slugs ) ) !== count( $slugs ) ) {
			$loginDuplicate = count(
				array_filter(
					$slugs,
					static fn ( string $slug ): bool => $slug === (string) $result['login_slug']
				)
			) > 1;

			add_settings_error(
				self::OPTION,
				'duplicate_slugs',
				__( 'Every custom path must be unique.', 'hide-wp' ),
				'error'
			);
			$loginValid = $loginValid && ! $loginDuplicate;
			$pathsValid = false;

			foreach ( array( 'login_slug', 'admin_slug', 'content_slug', 'includes_slug' ) as $key ) {
				$result[ $key ] = $current[ $key ];
			}
		}

		$loginChanged = $result['login_slug'] !== $current['login_slug']
			|| $result['login_enabled'] !== $current['login_enabled'];
		$pathsChanged = $result['admin_slug'] !== $current['admin_slug']
			|| $result['content_slug'] !== $current['content_slug']
			|| $result['includes_slug'] !== $current['includes_slug'];

		if ( $pathsChanged || ! $pathsValid ) {
			if ( Marker::disable() ) {
				$result['path_aliases_enabled'] = false;
				$result['verified_hash']        = '';
			} elseif ( Marker::requestRecovery() ) {
				$result['path_aliases_enabled'] = false;
				$result['verified_hash']        = '';
				add_settings_error(
					self::OPTION,
					'recovery_created',
					sprintf(
						/* translators: %s: emergency recovery file path. */
						__( 'The active server marker could not be removed. An emergency recovery file was created at %s; remove it only after fixing filesystem permissions and reviewing the generated server block.', 'hide-wp' ),
						esc_html( Marker::recoveryPath() )
					),
					'error'
				);
			} else {
				foreach ( array( 'admin_slug', 'content_slug', 'includes_slug' ) as $key ) {
					$result[ $key ] = $current[ $key ];
				}
				$result['path_aliases_enabled'] = $current['path_aliases_enabled'];
				$result['verified_hash']        = $current['verified_hash'];
				add_settings_error(
					self::OPTION,
					'marker_remove_failed',
					__( 'The active server marker could not be removed, so server path changes were not saved. Use the recovery file and check filesystem permissions.', 'hide-wp' ),
					'error'
				);
			}
		}

		if ( $loginChanged || ! $loginValid || false === $result['login_enabled'] ) {
			delete_option( self::LOGIN_VERIFIED_OPTION );
		}

		return $result;
	}

	public function findAliasCollision(): string {
		$keys = array( 'admin_slug', 'content_slug', 'includes_slug' );
		if ( $this->getBool( 'login_enabled' ) ) {
			$keys[] = 'login_slug';
		}

		foreach ( $keys as $key ) {
			$slug = $this->getSlug( $key );

			if ( $this->hasRouteCollision( $slug ) ) {
				return $slug;
			}
		}

		return '';
	}

	public function aliasesAreUnique(): bool {
		$slugs = array(
			$this->getSlug( 'login_slug' ),
			$this->getSlug( 'admin_slug' ),
			$this->getSlug( 'content_slug' ),
			$this->getSlug( 'includes_slug' ),
		);

		return count( array_unique( $slugs ) ) === count( $slugs );
	}

	private function isValidSlug( string $slug ): bool {
		if ( 1 !== preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $slug ) ) {
			return false;
		}

		$reserved = array(
			'admin',
			'author',
			'category',
			'comments',
			'feed',
			'favicon-ico',
			'index-php',
			'license-txt',
			'readme-html',
			'robots-txt',
			'search',
			'sitemap',
			'sitemap-xml',
			'tag',
			'wp-admin',
			'wp-activate',
			'wp-activate-php',
			'wp-content',
			'wp-cron',
			'wp-cron-php',
			'wp-includes',
			'wp-json',
			'wp-login',
			'wp-login-php',
			'wp-signup',
			'wp-signup-php',
			'xmlrpc',
			'xmlrpc-php',
		);

		return ! in_array( $slug, $reserved, true ) && ! str_starts_with( $slug, 'wp-' );
	}

	private function hasRouteCollision( string $slug ): bool {
		if ( file_exists( ABSPATH . $slug ) ) {
			return true;
		}

		$postTypes = get_post_types( array( 'public' => true ) );
		if ( null !== get_page_by_path( $slug, OBJECT, $postTypes ) ) {
			return true;
		}

		global $wp_rewrite;
		if ( ! is_object( $wp_rewrite ) || ! method_exists( $wp_rewrite, 'wp_rewrite_rules' ) ) {
			return false;
		}

		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( ! is_array( $rules ) ) {
			return false;
		}

		foreach ( array_keys( $rules ) as $rule ) {
			if ( is_string( $rule ) && 1 === preg_match( '/\A\^?([a-z0-9-]+)/i', $rule, $match )
				&& isset( $match[1] ) && hash_equals( $slug, strtolower( $match[1] ) ) ) {
				return true;
			}
		}

		return false;
	}
}
