<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Marker {
	private const HASH_PATTERN = '/\A[a-f0-9]{64}\z/D';

	public static function path( string $configurationHash ): string {
		return self::hashedPath( 'paths-enabled', $configurationHash );
	}

	public static function probePath( string $configurationHash ): string {
		return self::hashedPath( 'paths-probe', $configurationHash );
	}

	public static function loginPath( string $configurationHash ): string {
		return self::hashedPath( 'login-enabled', $configurationHash );
	}

	public static function loginProbePath( string $configurationHash ): string {
		return self::hashedPath( 'login-probe', $configurationHash );
	}

	public static function recoveryPath(): string {
		return ABSPATH . '.hide-wp-recovery';
	}

	public static function enable( string $configurationHash ): bool {
		return self::write( self::path( $configurationHash ), true );
	}

	public static function enableProbe( string $configurationHash ): bool {
		return self::write( self::probePath( $configurationHash ), true );
	}

	public static function enableLogin( string $configurationHash ): bool {
		return self::write( self::loginPath( $configurationHash ), true );
	}

	public static function enableLoginProbe( string $configurationHash ): bool {
		return self::write( self::loginProbePath( $configurationHash ), true );
	}

	public static function disableProbe( ?string $configurationHash = null ): bool {
		$paths = null === $configurationHash
			? self::familyPaths( 'paths-probe' )
			: array( self::probePath( $configurationHash ) );

		return self::removePaths( $paths );
	}

	public static function disableLoginProbe( ?string $configurationHash = null ): bool {
		$paths = null === $configurationHash
			? self::familyPaths( 'login-probe' )
			: array( self::loginProbePath( $configurationHash ) );

		return self::removePaths( $paths );
	}

	public static function disable(): bool {
		return self::removePaths(
			array_merge(
				self::familyPaths( 'paths-enabled' ),
				self::familyPaths( 'paths-probe' ),
				self::legacyEnabledPaths(),
				self::legacyProbePaths(),
				self::legacyFlagPaths()
			)
		);
	}

	public static function disableLogin(): bool {
		return self::removePaths(
			array_merge(
				self::familyPaths( 'login-enabled' ),
				self::familyPaths( 'login-probe' )
			)
		);
	}

	public static function disableAll(): bool {
		$pathsDisabled = self::disable();
		$loginDisabled = self::disableLogin();

		return $pathsDisabled && $loginDisabled;
	}

	/**
	 * Old generated blocks used global marker names that could activate a newly
	 * generated, unverified configuration. Remove only those obsolete names.
	 */
	public static function removeLegacyMarkers(): bool {
		return self::removePaths(
			array_merge(
				self::legacyEnabledPaths(),
				self::legacyProbePaths(),
				self::legacyFlagPaths()
			)
		);
	}

	public static function removeStaleMarkers( string $pathHash, string $loginHash ): bool {
		$keep = array(
			self::path( $pathHash ),
			self::probePath( $pathHash ),
			self::loginPath( $loginHash ),
			self::loginProbePath( $loginHash ),
		);
		$paths = array_merge(
			self::familyPaths( 'paths-enabled' ),
			self::familyPaths( 'paths-probe' ),
			self::familyPaths( 'login-enabled' ),
			self::familyPaths( 'login-probe' )
		);
		$stale = array_values(
			array_filter(
				$paths,
				static fn ( string $path ): bool => ! in_array( $path, $keep, true )
			)
		);

		return self::removePaths( $stale );
	}

	public static function requestRecovery(): bool {
		$path = self::recoveryPath();
		if ( is_link( $path ) ) {
			return false;
		}

		if ( is_file( $path ) ) {
			return true;
		}

		$handle = @fopen( $path, 'x+b' );
		if ( false === $handle ) {
			return is_file( $path ) && ! is_link( $path );
		}

		fclose( $handle );
		@chmod( $path, 0640 );

		return is_file( $path ) && ! is_link( $path );
	}

	public static function isEnabled( string $configurationHash ): bool {
		return ! is_link( self::runtimeDirectory() )
			&& is_file( self::path( $configurationHash ) )
			&& ! is_link( self::path( $configurationHash ) );
	}

	public static function isProbeEnabled( string $configurationHash ): bool {
		return ! is_link( self::runtimeDirectory() )
			&& is_file( self::probePath( $configurationHash ) )
			&& ! is_link( self::probePath( $configurationHash ) );
	}

	public static function isLoginEnabled( string $configurationHash ): bool {
		return ! is_link( self::runtimeDirectory() )
			&& is_file( self::loginPath( $configurationHash ) )
			&& ! is_link( self::loginPath( $configurationHash ) );
	}

	public static function isRecoveryRequested(): bool {
		return defined( 'HIDE_WP_RECOVERY_MODE' ) && true === HIDE_WP_RECOVERY_MODE
			|| is_file( self::recoveryPath() );
	}

	private static function hashedPath( string $family, string $configurationHash ): string {
		if ( 1 !== preg_match( self::HASH_PATTERN, $configurationHash ) ) {
			throw new \InvalidArgumentException( 'Invalid marker configuration hash.' );
		}

		return self::runtimeDirectory() . '/' . $family . '-' . $configurationHash . '.php';
	}

	private static function runtimeDirectory(): string {
		if ( defined( 'HIDE_WP_MARKER_DIR' ) && is_string( HIDE_WP_MARKER_DIR ) && '' !== HIDE_WP_MARKER_DIR ) {
			return rtrim( str_replace( '\\', '/', HIDE_WP_MARKER_DIR ), '/' );
		}

		if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
			return rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/hide-wp-surface-runtime';
		}

		return rtrim( str_replace( '\\', '/', HIDE_WP_DIR ), '/' ) . '/runtime';
	}

	/**
	 * @return list<string>
	 */
	private static function runtimeDirectories(): array {
		$directories = array( self::runtimeDirectory(), rtrim( str_replace( '\\', '/', HIDE_WP_DIR ), '/' ) . '/runtime' );

		if ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) {
			$pluginDir    = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );
			$directories[] = $pluginDir . '/hide-wp-surface/runtime';
			$directories[] = $pluginDir . '/hide-wp-master/runtime';
		}

		return self::uniquePaths( $directories );
	}

	/**
	 * @return list<string>
	 */
	private static function familyPaths( string $family ): array {
		$paths = array();
		foreach ( self::runtimeDirectories() as $directory ) {
			$matches = glob( rtrim( $directory, '/' ) . '/' . $family . '-*.php', GLOB_NOSORT );
			if ( ! is_array( $matches ) ) {
				continue;
			}

			foreach ( $matches as $path ) {
				$basename = basename( $path );
				if ( 1 === preg_match( '/\A' . preg_quote( $family, '/' ) . '-[a-f0-9]{64}\.php\z/D', $basename ) ) {
					$paths[] = str_replace( '\\', '/', $path );
				}
			}
		}

		return self::uniquePaths( $paths );
	}

	/**
	 * @return list<string>
	 */
	private static function legacyEnabledPaths(): array {
		return self::legacyRuntimeFiles( 'paths-enabled.php' );
	}

	/**
	 * @return list<string>
	 */
	private static function legacyProbePaths(): array {
		return self::legacyRuntimeFiles( 'paths-probe.php' );
	}

	/**
	 * @return list<string>
	 */
	private static function legacyFlagPaths(): array {
		return self::legacyRuntimeFiles( 'paths-enabled.flag' );
	}

	/**
	 * @return list<string>
	 */
	private static function legacyRuntimeFiles( string $filename ): array {
		return array_map(
			static fn ( string $directory ): string => rtrim( $directory, '/' ) . '/' . $filename,
			self::runtimeDirectories()
		);
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private static function uniquePaths( array $paths ): array {
		$unique = array();
		foreach ( $paths as $path ) {
			$path = str_replace( '\\', '/', $path );
			if ( '' === $path || isset( $unique[ $path ] ) ) {
				continue;
			}
			$unique[ $path ] = $path;
		}

		return array_values( $unique );
	}

	/**
	 * @param list<string> $paths
	 */
	private static function removePaths( array $paths ): bool {
		foreach ( self::runtimeDirectories() as $directory ) {
			if ( is_link( $directory ) ) {
				return false;
			}
		}

		foreach ( self::uniquePaths( $paths ) as $path ) {
			if ( is_link( dirname( $path ) ) ) {
				return false;
			}

			if ( is_file( $path ) || is_link( $path ) ) {
				@unlink( $path );
			}
		}

		foreach ( self::uniquePaths( $paths ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				return false;
			}
		}

		return true;
	}

	private static function write( string $path, bool $createDirectory ): bool {
		$directory = dirname( $path );
		if ( is_link( $directory ) ) {
			return false;
		}

		if ( ! is_dir( $directory ) ) {
			if ( ! $createDirectory || ( ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) ) {
				return false;
			}
		}
		if ( is_link( $directory ) ) {
			return false;
		}

		if ( is_link( $path ) ) {
			return false;
		}

		@chmod( $directory, 0755 );
		self::writeDenyFiles( $directory );

		$content = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n";
		try {
			$suffix = bin2hex( random_bytes( 12 ) );
		} catch ( \Throwable ) {
			$suffix = wp_generate_password( 24, false, false );
		}
		$temporary = $directory . '/.' . basename( $path ) . '.' . $suffix . '.tmp';
		$handle    = @fopen( $temporary, 'x+b' );
		if ( false === $handle ) {
			return false;
		}

		$locked  = flock( $handle, LOCK_EX );
		$success = false;

		try {
			if ( $locked ) {
				$bytes   = fwrite( $handle, $content );
				$success = is_int( $bytes ) && strlen( $content ) === $bytes && fflush( $handle );
			}
		} finally {
			if ( $locked ) {
				flock( $handle, LOCK_UN );
			}
			fclose( $handle );
		}

		if ( ! $success ) {
			@unlink( $temporary );
			return false;
		}

		@chmod( $temporary, 0640 );
		if ( ! @rename( $temporary, $path ) ) {
			@unlink( $temporary );
			return false;
		}

		return is_file( $path ) && ! is_link( $path );
	}

	private static function writeDenyFiles( string $directory ): void {
		$index = $directory . '/index.php';
		if ( ! is_file( $index ) && ! is_link( $index ) ) {
			$handle = @fopen( $index, 'x+b' );
			if ( false !== $handle ) {
				fwrite( $handle, "<?php\n\ndefined( 'ABSPATH' ) || exit;\n" );
				fclose( $handle );
				@chmod( $index, 0644 );
			}
		}

		$htaccess = $directory . '/.htaccess';
		if ( ! is_file( $htaccess ) && ! is_link( $htaccess ) ) {
			$handle = @fopen( $htaccess, 'x+b' );
			if ( false !== $handle ) {
				fwrite(
					$handle,
					"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule mod_access_compat.c>\nDeny from all\n</IfModule>\n"
				);
				fclose( $handle );
				@chmod( $htaccess, 0644 );
			}
		}
	}
}
