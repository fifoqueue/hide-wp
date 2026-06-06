<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final class Marker {
	public static function path(): string {
		return HIDE_WP_DIR . 'runtime/paths-enabled.php';
	}

	public static function probePath(): string {
		return HIDE_WP_DIR . 'runtime/paths-probe.php';
	}

	public static function recoveryPath(): string {
		return ABSPATH . '.hide-wp-recovery';
	}

	public static function enable(): bool {
		return self::write( self::path() );
	}

	public static function enableProbe(): bool {
		return self::write( self::probePath() );
	}

	public static function disableProbe(): bool {
		if ( is_file( self::probePath() ) ) {
			@unlink( self::probePath() );
		}

		return ! is_file( self::probePath() );
	}

	public static function disable(): bool {
		foreach (
			array(
				self::path(),
				self::probePath(),
				HIDE_WP_DIR . 'runtime/paths-enabled.flag',
			) as $path
		) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}

		return ! is_file( self::path() )
			&& ! is_file( self::probePath() )
			&& ! is_file( HIDE_WP_DIR . 'runtime/paths-enabled.flag' );
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
		return is_file( self::path() );
	}

	public static function isRecoveryRequested(): bool {
		return defined( 'HIDE_WP_RECOVERY_MODE' ) && true === HIDE_WP_RECOVERY_MODE
			|| is_file( self::recoveryPath() );
	}

	private static function write( string $path ): bool {
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
}
