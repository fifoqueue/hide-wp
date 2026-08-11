<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private const ROUTING_PROTOCOL = 2;

	public const OPTION = 'hide_wp_settings';
	public const LOGIN_VERIFIED_OPTION = 'hide_wp_login_verified_hash';
	public const PATH_STATE_OPTION = 'hide_wp_path_state';
	public const ALIAS_TOKEN_OPTION = 'hide_wp_alias_token';
	public const DATA_MIGRATION_OPTION = 'hide_wp_data_migration_version';
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
			'alias_query_key'        => 'hidewp_surface_key',
		);
	}

	/**
	 * @return array<string, bool|string>
	 */
	public function all(): array {
		$value = get_option( self::OPTION, array() );

		$settings = array_merge( self::defaults(), is_array( $value ) ? $value : array() );
		$settings['alias_query_token'] = $this->aliasQueryToken();

		return $settings;
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

	public function removeRetiredData(): void {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();

		$repository = is_string( $value['github_repository'] ?? null ) ? $value['github_repository'] : '';
		$changed    = false;
		foreach ( array( 'alias_query_token', 'github_updates_enabled', 'github_repository', 'github_token' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				unset( $value[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $value, false );
		}

		if ( $changed || 2 > (int) get_option( self::DATA_MIGRATION_OPTION, 0 ) ) {
			// Remove any package URL cached by the retired unsigned updater, even
			// when its settings were never explicitly saved in the database.
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'hide_wp_github_latest_release' );

			$repositories = array( 'fifoqueue/hide-wp-surface' );
			if ( '' !== $repository ) {
				$repositories[] = $repository;
			}
			if ( defined( 'HIDE_WP_GITHUB_REPOSITORY' ) && is_string( HIDE_WP_GITHUB_REPOSITORY ) ) {
				$repositories[] = HIDE_WP_GITHUB_REPOSITORY;
			}

			foreach ( array_unique( $repositories ) as $retiredRepository ) {
				if ( 1 !== preg_match( '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D', $retiredRepository ) ) {
					continue;
				}

				$suffix = substr( hash( 'sha256', strtolower( $retiredRepository ) ), 0, 12 );
				delete_site_transient( 'hide_wp_github_latest_release_' . $suffix );
				delete_site_transient( 'hide_wp_puc_latest_' . $suffix );
			}

			update_option( self::DATA_MIGRATION_OPTION, 2, false );
		}
	}

	public function removeRetiredUpdateOffer( mixed $transient ): mixed {
		if ( is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ) {
			unset( $transient->response[ HIDE_WP_BASENAME ] );
		}

		return $transient;
	}

	public function aliasQueryKey(): string {
		$key = $this->getString( 'alias_query_key' );

		return $this->isValidAliasQueryKey( $key ) ? $key : 'hidewp_surface_key';
	}

	public function aliasQueryToken(): string {
		$token = get_option( self::ALIAS_TOKEN_OPTION, '' );
		if ( is_string( $token ) && $this->isValidAliasQueryToken( $token ) ) {
			return $token;
		}

		$legacy = get_option( self::OPTION, array() );
		$legacy = is_array( $legacy ) && is_string( $legacy['alias_query_token'] ?? null )
			? $legacy['alias_query_token']
			: '';
		$seed   = self::defaultAliasQueryToken();
		$token  = $this->isValidAliasQueryToken( $legacy )
			? $legacy
			: ( $this->isValidAliasQueryToken( $seed ) ? $seed : self::generateAliasQueryToken() );

		if ( add_option( self::ALIAS_TOKEN_OPTION, $token, '', false ) ) {
			return $token;
		}

		$fresh = get_option( self::ALIAS_TOKEN_OPTION, '' );
		if ( is_string( $fresh ) && $this->isValidAliasQueryToken( $fresh ) ) {
			return $fresh;
		}

		update_option( self::ALIAS_TOKEN_OPTION, $token, false );
		$fresh = get_option( self::ALIAS_TOKEN_OPTION, '' );

		return is_string( $fresh ) && $this->isValidAliasQueryToken( $fresh )
			? $fresh
			: $this->deterministicAliasQueryToken();
	}

	public function originalPathGuard(): string {
		return hash_hmac( 'sha256', 'hide-wp-original-path-handoff-v1', $this->aliasQueryToken() );
	}

	public function aliasRequestToken( string $purpose ): string {
		if ( ! in_array( $purpose, array_merge( self::PATH_TYPES, array( 'login' ) ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown alias token purpose.' );
		}

		return hash_hmac( 'sha256', 'hide-wp-alias-request-' . $purpose . '-v1', $this->aliasQueryToken() );
	}

	public function loginEnabled(): bool {
		$verifiedHash = get_option( self::LOGIN_VERIFIED_OPTION, '' );

		return $this->loginRequested()
			&& is_string( $verifiedHash )
			&& hash_equals( $this->loginConfigurationHash(), $verifiedHash )
			&& Marker::isLoginEnabled( $verifiedHash );
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
		$hash  = is_string( $state['verified_hash'] ?? null ) ? $state['verified_hash'] : '';

		return ! is_multisite()
			&& ! Marker::isRecoveryRequested()
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $hash )
			&& hash_equals( $this->configurationHash(), $hash )
			&& Marker::isEnabled( $hash )
			&& true === $state['enabled']
			&& array() !== $state['aliases'];
	}

	public function activeConfigurationHash(): string {
		$state = $this->pathState();
		$hash  = is_string( $state['verified_hash'] ?? null ) ? $state['verified_hash'] : '';

		return 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $hash ) ? $hash : '';
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
			'protocol' => self::ROUTING_PROTOCOL,
			'aliases'  => array(),
			'server'   => array(
				'alias_query_key'        => $this->aliasQueryKey(),
				'alias_query_token'      => $this->aliasQueryToken(),
			),
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
			'protocol' => self::ROUTING_PROTOCOL,
			'login'    => array(
				'enabled' => $this->getBool( 'login_enabled' ),
				'slug'    => $this->getSlug( 'login_slug' ),
			),
			'server'   => array(
				'alias_query_key'   => $this->aliasQueryKey(),
				'alias_query_token' => $this->aliasQueryToken(),
			),
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
						__( 'The path "%s" is invalid or reserved. Use 1-63 lowercase ASCII letters, numbers, or hyphens.', 'hide-wp-surface' ),
						esc_html( $proposed )
					),
					'error'
				);
				if ( 'login_slug' === $key ) {
					$loginValid = false;
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

		$aliasQueryKey = isset( $input['alias_query_key'] ) && is_string( $input['alias_query_key'] )
			? strtolower( trim( wp_unslash( $input['alias_query_key'] ) ) )
			: (string) $current['alias_query_key'];
		if ( $this->isValidAliasQueryKey( $aliasQueryKey ) ) {
			$result['alias_query_key'] = $aliasQueryKey;
		} else {
			add_settings_error(
				self::OPTION,
				'invalid_alias_query_key',
				__( 'The Nginx alias query key must use 3-32 lowercase letters, numbers, or underscores.', 'hide-wp-surface' ),
				'error'
			);
		}

		if ( isset( $input['alias_query_token_rotate'] ) && '1' === (string) $input['alias_query_token_rotate'] ) {
			$result['alias_query_token'] = self::generateAliasQueryToken();
		} elseif ( ! is_string( $result['alias_query_token'] ?? null ) || ! $this->isValidAliasQueryToken( (string) $result['alias_query_token'] ) ) {
			$result['alias_query_token'] = self::generateAliasQueryToken();
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
					__( 'The path "%s" conflicts with existing content or a file in the WordPress directory.', 'hide-wp-surface' ),
					esc_html( $slug )
				),
				'error'
			);
			$result[ $key ]        = $current[ $key ];
			$result[ $enabledKey ] = $current[ $enabledKey ];
			if ( $isLoginKey ) {
				$loginValid = false;
			}
		}

		$slugs = $this->enabledRouteSlugsFromSettings( $result );
		if ( count( array_unique( $slugs ) ) !== count( $slugs ) ) {
			add_settings_error(
				self::OPTION,
				'duplicate_slugs',
				__( 'Every enabled custom path must be unique.', 'hide-wp-surface' ),
				'error'
			);
			$loginValid = false;
			foreach ( array( 'login_slug', 'login_enabled', 'admin_slug', 'admin_enabled', 'content_slug', 'content_enabled', 'includes_slug', 'includes_enabled' ) as $key ) {
				$result[ $key ] = $current[ $key ];
			}
		}

		$loginChanged = $result['login_slug'] !== $current['login_slug']
			|| $result['login_enabled'] !== $current['login_enabled']
			|| $result['alias_query_key'] !== $current['alias_query_key']
			|| $result['alias_query_token'] !== $current['alias_query_token'];
		$pathsChanged = $result['admin_slug'] !== $current['admin_slug']
			|| $result['admin_enabled'] !== $current['admin_enabled']
			|| $result['content_slug'] !== $current['content_slug']
			|| $result['content_enabled'] !== $current['content_enabled']
			|| $result['includes_slug'] !== $current['includes_slug']
			|| $result['includes_enabled'] !== $current['includes_enabled']
			|| $result['alias_query_key'] !== $current['alias_query_key']
			|| $result['alias_query_token'] !== $current['alias_query_token'];

		if ( $pathsChanged ) {
			$markerDisabled = Marker::disable();
			$stateCleared   = $this->clearPathVerification();
			if ( ! $markerDisabled || ! $stateCleared ) {
				Marker::requestRecovery();
				add_settings_error(
					self::OPTION,
					'paths_disable_failed',
					__( 'Path settings were not changed because the active server state could not be disabled safely. Recovery mode was requested; check filesystem and database permissions.', 'hide-wp-surface' ),
					'error'
				);

				foreach (
					array(
						'admin_slug',
						'admin_enabled',
						'content_slug',
						'content_enabled',
						'includes_slug',
						'includes_enabled',
						'alias_query_key',
						'alias_query_token',
					) as $key
				) {
					$result[ $key ] = $current[ $key ];
				}
			} else {
				add_settings_error(
					self::OPTION,
					'paths_need_verification',
					__( 'Path aliases were disabled before saving the new configuration. Replace the generated server block, then run Verify and Enable from the standard wp-admin path.', 'hide-wp-surface' ),
					'info'
				);
			}
		}

		if ( $loginChanged || false === $result['login_enabled'] ) {
			if ( ! Marker::disableLogin() ) {
				Marker::requestRecovery();
				$result['login_slug']        = $current['login_slug'];
				$result['login_enabled']     = $current['login_enabled'];
				$result['alias_query_key']   = $current['alias_query_key'];
				$result['alias_query_token'] = $current['alias_query_token'];
				add_settings_error(
					self::OPTION,
					'login_disable_failed',
					__( 'Login settings were not changed because the active login marker could not be disabled safely. Recovery mode was requested; check filesystem permissions.', 'hide-wp-surface' ),
					'error'
				);
			} else {
				delete_option( self::LOGIN_VERIFIED_OPTION );
			}
		}

		if ( ! hash_equals( (string) $current['alias_query_token'], (string) $result['alias_query_token'] )
			&& ! $this->persistAliasQueryToken( (string) $result['alias_query_token'] ) ) {
			$result['alias_query_token'] = $current['alias_query_token'];
			add_settings_error(
				self::OPTION,
				'alias_token_save_failed',
				__( 'The alias token could not be rotated. Path and login aliases remain disabled; check database write permissions before verification.', 'hide-wp-surface' ),
				'error'
			);
		}

		// Remove legacy internal fields that were previously stored with user-editable settings.
		unset(
			$result['alias_query_token'],
			$result['path_aliases_enabled'],
			$result['verified_hash'],
			$result['nginx_admin_alias_mode'],
			$result['nginx_fastcgi_pass'],
			$result['github_updates_enabled'],
			$result['github_repository'],
			$result['github_token']
		);

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

	private static function defaultAliasQueryToken(): string {
		if ( defined( 'HIDE_WP_ALIAS_QUERY_TOKEN' ) && is_string( HIDE_WP_ALIAS_QUERY_TOKEN )
			&& 1 === preg_match( '/\A[A-Za-z0-9_-]{16,128}\z/', HIDE_WP_ALIAS_QUERY_TOKEN ) ) {
			return HIDE_WP_ALIAS_QUERY_TOKEN;
		}

		return '';
	}

	private static function generateAliasQueryToken(): string {
		try {
			return bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable ) {
			return wp_generate_password( 48, false, false );
		}
	}

	private function deterministicAliasQueryToken(): string {
		$key = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		if ( '' === $key && defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) ) {
			$key = AUTH_KEY;
		}
		if ( '' === $key ) {
			return self::generateAliasQueryToken();
		}

		return hash_hmac(
			'sha256',
			'hide-wp-alias-token-fallback-v1|' . (string) get_option( 'siteurl', '' ),
			$key
		);
	}

	private function persistAliasQueryToken( string $token ): bool {
		if ( ! $this->isValidAliasQueryToken( $token ) ) {
			return false;
		}

		if ( update_option( self::ALIAS_TOKEN_OPTION, $token, false ) ) {
			return true;
		}

		$fresh = get_option( self::ALIAS_TOKEN_OPTION, '' );

		return is_string( $fresh ) && hash_equals( $token, $fresh );
	}

	private function isValidAliasQueryKey( string $key ): bool {
		return 1 === preg_match( '/\A[a-z][a-z0-9_]{2,31}\z/', $key );
	}

	private function isValidAliasQueryToken( string $token ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{16,128}\z/', $token );
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
