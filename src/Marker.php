<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Marker {
	public static function path(): string {
		return self::runtimeDirectory() . '/paths-enabled.php';
	}

	public static function probePath(): string {
		return self::runtimeDirectory() . '/paths-probe.php';
	}

	public static function recoveryPath(): string {
		return ABSPATH . '.hide-wp-recovery';
	}

	public static function enable(): bool {
		return self::writePrimaryAndLegacy( self::path(), self::legacyEnabledPaths() );
	}

	public static function enableProbe(): bool {
		return self::writePrimaryAndLegacy( self::probePath(), self::legacyProbePaths() );
	}

	public static function disableProbe(): bool {
		foreach ( self::probePaths() as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}

		foreach ( self::probePaths() as $path ) {
			if ( is_file( $path ) ) {
				return false;
			}
		}

		return true;
	}

	public static function disable(): bool {
		foreach (
			array_merge(
				self::enabledPaths(),
				self::probePaths(),
				self::legacyFlagPaths()
			) as $path
		) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}

		foreach ( array_merge( self::enabledPaths(), self::probePaths(), self::legacyFlagPaths() ) as $path ) {
			if ( is_file( $path ) ) {
				return false;
			}
		}

		return true;
	}

	public static function requestRecovery(): bool {
		$path = self::recoveryPath();
		if ( is_file( $path ) ) {
			return true;
		}

		$handle = @fopen( $path, 'x+b' );
		if ( false === $handle ) {
			return is_file( $path );
		}

		fclose( $handle );
		@chmod( $path, 0640 );

		return is_file( $path );
	}

	public static function isEnabled(): bool {
		foreach ( self::enabledPaths() as $path ) {
			if ( is_file( $path ) ) {
				return true;
			}
		}

		return false;
	}

	public static function isRecoveryRequested(): bool {
		return defined( 'HIDE_WP_RECOVERY_MODE' ) && true === HIDE_WP_RECOVERY_MODE
			|| is_file( self::recoveryPath() );
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
	private static function enabledPaths(): array {
		return self::uniquePaths( array_merge( array( self::path() ), self::legacyEnabledPaths() ) );
	}

	/**
	 * @return list<string>
	 */
	private static function probePaths(): array {
		return self::uniquePaths( array_merge( array( self::probePath() ), self::legacyProbePaths() ) );
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
		$paths = array( rtrim( str_replace( '\\', '/', HIDE_WP_DIR ), '/' ) . '/runtime/' . $filename );

		if ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) {
			$pluginDir = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );
			$paths[]   = $pluginDir . '/hide-wp-surface/runtime/' . $filename;
			$paths[]   = $pluginDir . '/hide-wp-master/runtime/' . $filename;
		}

		return self::uniquePaths( $paths );
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private static function uniquePaths( array $paths ): array {
		$unique = array();
		foreach ( $paths as $path ) {
			if ( '' === $path || isset( $unique[ $path ] ) ) {
				continue;
			}
			$unique[ $path ] = $path;
		}

		return array_values( $unique );
	}

	/**
	 * @param list<string> $legacyPaths
	 */
	private static function writePrimaryAndLegacy( string $primaryPath, array $legacyPaths ): bool {
		$success = self::write( $primaryPath, true );
		if ( ! $success ) {
			return false;
		}

		foreach ( $legacyPaths as $legacyPath ) {
			if ( $legacyPath === $primaryPath || ! is_dir( dirname( $legacyPath ) ) ) {
				continue;
			}
			self::write( $legacyPath, false );
		}

		return true;
	}

	private static function write( string $path, bool $createDirectory ): bool {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			if ( ! $createDirectory || ( ! @mkdir( $directory, 0750, true ) && ! is_dir( $directory ) ) ) {
				return false;
			}
		}

		self::writeDenyFiles( $directory );

		$content = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n";
		$handle  = @fopen( $path, 'x+b' );
		if ( false === $handle ) {
			return false;
		}

		$locked  = flock( $handle, LOCK_EX );
		$success = false;

		try {
			if ( $locked ) {
				$bytes   = fwrite( $handle, $content );
				$success = strlen( $content ) === $bytes && fflush( $handle );
			}
		} finally {
			if ( $locked ) {
				flock( $handle, LOCK_UN );
			}
			fclose( $handle );
		}

		if ( ! $success ) {
			@unlink( $path );
			return false;
		}

		@chmod( $path, 0640 );
		return true;
	}

	private static function writeDenyFiles( string $directory ): void {
		$index = $directory . '/index.php';
		if ( ! is_file( $index ) ) {
			$handle = @fopen( $index, 'x+b' );
			if ( false !== $handle ) {
				fwrite( $handle, "<?php\n\ndefined( 'ABSPATH' ) || exit;\n" );
				fclose( $handle );
				@chmod( $index, 0640 );
			}
		}

		$htaccess = $directory . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			$handle = @fopen( $htaccess, 'x+b' );
			if ( false !== $handle ) {
				fwrite( $handle, "Deny from all\n" );
				fclose( $handle );
				@chmod( $htaccess, 0640 );
			}
		}
	}
}
