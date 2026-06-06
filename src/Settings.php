<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION = 'hide_wp_settings';
	public const LOGIN_VERIFIED_OPTION = 'hide_wp_login_verified_hash';
	public const PATH_STATE_OPTION = 'hide_wp_path_state';
	public const PATH_TYPES = array( 'admin', 'content', 'includes' );

	/**
	 * @return array<string, bool|string>
	 */
	public static function defaults(): array {
		return array(
			'login_enabled'          => false,
			'login_slug'             => 'login',
			'admin_enabled'          => true,
			'admin_slug'             => 'control',
			'content_enabled'        => true,
			'content_slug'           => 'assets',
			'includes_enabled'       => true,
			'includes_slug'          => 'system-assets',
			'remove_generator'       => true,
			'remove_discovery_links' => true,
			'strip_core_version'     => true,
			'generic_login_errors'   => true,
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

	public function getActiveSlug( string $type ): string {
		$this->assertPathType( $type );

		$state = $this->pathState();
		$slug  = is_string( $state['aliases'][ $type ] ?? null ) ? $state['aliases'][ $type ] : '';

		return $this->isValidSlug( $slug ) ? $slug : $this->getSlug( $type . '_slug' );
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

	public function pathAliasRequested( string $type ): bool {
		$this->assertPathType( $type );

		return ! is_multisite()
			&& ! Marker::isRecoveryRequested()
			&& $this->getBool( $type . '_enabled' );
	}

	/**
	 * @return list<string>
	 */
	public function requestedAliasTypes(): array {
		$types = array();
		foreach ( self::PATH_TYPES as $type ) {
			if ( $this->pathAliasRequested( $type ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	public function hasRequestedPathAliases(): bool {
		return array() !== $this->requestedAliasTypes();
	}

	public function pathsEnabled(): bool {
		$state = $this->pathState();

		return ! is_multisite()
			&& ! Marker::isRecoveryRequested()
			&& Marker::isEnabled()
			&& true === $state['enabled']
			&& array() !== $state['aliases'];
	}

	public function activeAliasEnabled( string $type ): bool {
		$this->assertPathType( $type );

		return $this->pathsEnabled() && isset( $this->pathState()['aliases'][ $type ] );
	}

	/**
	 * @return list<string>
	 */
	public function activeAliasTypes(): array {
		return $this->pathsEnabled() ? array_keys( $this->pathState()['aliases'] ) : array();
	}

	public function markPathsVerified( string $expectedHash ): bool {
		$hash    = $this->configurationHash();
		$aliases = $this->aliasesFromSettings( $this->all() );
		if ( ! hash_equals( $expectedHash, $hash ) || array() === $aliases ) {
			return false;
		}

		$state = array(
			'enabled'       => true,
			'verified_hash' => $hash,
			'aliases'       => $aliases,
		);
		update_option( self::PATH_STATE_OPTION, $state, false );

		return $this->pathStateMatches( $hash );
	}

	public function clearPathVerification(): bool {
		delete_option( self::PATH_STATE_OPTION );

		return null === get_option( self::PATH_STATE_OPTION, null );
	}

	public function pathStateMatches( string $expectedHash ): bool {
		$state = $this->pathState();
		$hash  = is_string( $state['verified_hash'] ?? null ) ? $state['verified_hash'] : '';

		return true === ( $state['enabled'] ?? false )
			&& array() !== $state['aliases']
			&& '' !== $hash
			&& hash_equals( $expectedHash, $hash );
	}

	public function configurationHash(): string {
		$data = array(
			'aliases' => array(),
			'siteurl' => (string) get_option( 'siteurl', '' ),
			'home'    => (string) get_option( 'home', '' ),
		);

		foreach ( self::PATH_TYPES as $type ) {
			$enabled = $this->pathAliasRequested( $type );
			$data['aliases'][ $type ] = array( 'enabled' => $enabled );
			if ( $enabled ) {
				$data['aliases'][ $type ]['slug'] = $this->getSlug( $type . '_slug' );
			}
		}

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
		$this->migrateLegacyPathState( $current );
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
				'admin_enabled',
				'content_enabled',
				'includes_enabled',
				'remove_generator',
				'remove_discovery_links',
				'strip_core_version',
				'generic_login_errors',
			) as $key
		) {
			$result[ $key ] = isset( $input[ $key ] ) && '1' === (string) $input[ $key ];
		}

		if ( is_multisite() ) {
			$result['login_enabled']    = false;
			$result['admin_enabled']    = false;
			$result['content_enabled']  = false;
			$result['includes_enabled'] = false;
		}

		if ( ! $loginValid ) {
			$result['login_enabled'] = $current['login_enabled'];
		}

		foreach ( array( 'login_slug', 'admin_slug', 'content_slug', 'includes_slug' ) as $key ) {
			$slug       = (string) $result[ $key ];
			$isLoginKey = 'login_slug' === $key;
			$type       = $isLoginKey ? '' : substr( $key, 0, -5 );
			$enabledKey = $isLoginKey ? 'login_enabled' : $type . '_enabled';
			$isEnabled  = true === ( $result[ $enabledKey ] ?? false );
			$wasEnabled = true === ( $current[ $enabledKey ] ?? false );
			$changed    = $slug !== (string) $current[ $key ] || $isEnabled !== $wasEnabled;

			if ( ! $isEnabled || ! $changed || ! $this->hasRouteCollision( $slug ) ) {
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
			$result[ $key ]        = $current[ $key ];
			$result[ $enabledKey ] = $current[ $enabledKey ];
			if ( $isLoginKey ) {
				$loginValid = false;
			} else {
				$pathsValid = false;
			}
		}

		$slugs = $this->enabledRouteSlugsFromSettings( $result );
		if ( count( array_unique( $slugs ) ) !== count( $slugs ) ) {
			add_settings_error(
				self::OPTION,
				'duplicate_slugs',
				__( 'Every enabled custom path must be unique.', 'hide-wp' ),
				'error'
			);
			$loginValid = false;
			$pathsValid = false;

			foreach ( array( 'login_slug', 'login_enabled', 'admin_slug', 'admin_enabled', 'content_slug', 'content_enabled', 'includes_slug', 'includes_enabled' ) as $key ) {
				$result[ $key ] = $current[ $key ];
			}
		}

		$loginChanged = $result['login_slug'] !== $current['login_slug']
			|| $result['login_enabled'] !== $current['login_enabled'];
		$pathsChanged = $result['admin_slug'] !== $current['admin_slug']
			|| $result['admin_enabled'] !== $current['admin_enabled']
			|| $result['content_slug'] !== $current['content_slug']
			|| $result['content_enabled'] !== $current['content_enabled']
			|| $result['includes_slug'] !== $current['includes_slug']
			|| $result['includes_enabled'] !== $current['includes_enabled'];

		if ( $pathsChanged && $pathsValid && $this->pathsEnabled() ) {
			add_settings_error(
				self::OPTION,
				'paths_need_verification',
				__( 'Path settings were saved. Previously verified aliases remain active until you replace the generated server block and run Verify and Enable.', 'hide-wp' ),
				'info'
			);
		}

		if ( $loginChanged || ! $loginValid || false === $result['login_enabled'] ) {
			delete_option( self::LOGIN_VERIFIED_OPTION );
		}

		// Remove legacy internal fields that were previously stored with user-editable settings.
		unset( $result['path_aliases_enabled'], $result['verified_hash'] );

		return $result;
	}

	public function findAliasCollision(): string {
		foreach ( $this->enabledRouteSlugsFromSettings( $this->all() ) as $slug ) {
			if ( $this->hasRouteCollision( $slug ) ) {
				return $slug;
			}
		}

		return '';
	}

	public function aliasesAreUnique(): bool {
		$slugs = $this->enabledRouteSlugsFromSettings( $this->all() );

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

	/**
	 * @return array{enabled: bool, verified_hash: string, aliases: array<string, string>}
	 */
	private function pathState(): array {
		$value   = get_option( self::PATH_STATE_OPTION, null );
		$aliases = array();
		if ( is_array( $value ) && is_array( $value['aliases'] ?? null ) ) {
			foreach ( self::PATH_TYPES as $type ) {
				$slug = $value['aliases'][ $type ] ?? '';
				if ( is_string( $slug ) && $this->isValidSlug( $slug ) ) {
					$aliases[ $type ] = $slug;
				}
			}
		}

		if ( is_array( $value ) && true === ( $value['enabled'] ?? false ) && array() === $aliases ) {
			$aliases = $this->aliasesFromSettings( $this->all() );
		}

		if ( is_array( $value ) ) {
			return array(
				'enabled'       => true === ( $value['enabled'] ?? false ),
				'verified_hash' => is_string( $value['verified_hash'] ?? null ) ? $value['verified_hash'] : '',
				'aliases'       => $aliases,
			);
		}

		return array(
			'enabled'       => false,
			'verified_hash' => '',
			'aliases'       => array(),
		);
	}

	/**
	 * @param array<string, bool|string> $settings
	 * @return array<string, string>
	 */
	private function aliasesFromSettings( array $settings ): array {
		$aliases = array();
		foreach ( self::PATH_TYPES as $type ) {
			$enabled = true === ( $settings[ $type . '_enabled' ] ?? false );
			$slug    = is_string( $settings[ $type . '_slug' ] ?? null ) ? $settings[ $type . '_slug' ] : '';
			if ( $enabled && $this->isValidSlug( $slug ) ) {
				$aliases[ $type ] = $slug;
			}
		}

		return $aliases;
	}

	/**
	 * @param array<string, bool|string> $settings
	 * @return list<string>
	 */
	private function enabledRouteSlugsFromSettings( array $settings ): array {
		$slugs = array();
		if ( true === ( $settings['login_enabled'] ?? false ) && is_string( $settings['login_slug'] ?? null ) ) {
			$slugs[] = $settings['login_slug'];
		}

		foreach ( $this->aliasesFromSettings( $settings ) as $slug ) {
			$slugs[] = $slug;
		}

		return $slugs;
	}

	/**
	 * @param array<string, bool|string> $settings
	 */
	private function migrateLegacyPathState( array $settings ): void {
		$value = get_option( self::PATH_STATE_OPTION, null );
		if ( ! is_array( $value ) || true !== ( $value['enabled'] ?? false ) || is_array( $value['aliases'] ?? null ) ) {
			return;
		}

		$aliases = $this->aliasesFromSettings( $settings );
		if ( array() === $aliases ) {
			return;
		}

		$value['aliases'] = $aliases;
		update_option( self::PATH_STATE_OPTION, $value, false );
	}

	private function assertPathType( string $type ): void {
		if ( ! in_array( $type, self::PATH_TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown path alias type.' );
		}
	}
}
