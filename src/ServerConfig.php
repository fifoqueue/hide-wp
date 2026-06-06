<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class ServerConfig {
	public function __construct( private PathMapper $mapper ) {
	}

	public function apache(): string {
		$aliases  = $this->relativeAliases();
		$marker   = str_replace( '\\', '/', Marker::path() );
		$probe    = str_replace( '\\', '/', Marker::probePath() );
		$recovery = str_replace( '\\', '/', Marker::recoveryPath() );

		$lines = array(
			'# BEGIN Hide WP Surface',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'',
			'# Login front controller fallback.',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteCond %{REQUEST_FILENAME} !-d',
			sprintf(
				'RewriteRule ^%s/?$ index.php [END,QSA,NC]',
				preg_quote( basename( $this->mapper->targetPath( 'login' ) ), '#' )
			),
			'',
			'# Internal aliases. Keep these rules before the standard WordPress block.',
		);

		foreach ( $aliases as $source => $target ) {
			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteCond "%s" -f [OR]', $marker );
			$lines[] = sprintf( 'RewriteCond "%s" -f', $probe );
			$lines[] = sprintf(
				'RewriteRule ^%1$s(?:/(.*))?/?$ %2$s/$1 [END,QSA,NC]',
				preg_quote( $target, '#' ),
				$source
			);
		}

		$lines[] = '';
		$lines[] = '# Send original paths to WordPress so the active theme renders its 404 template.';
		foreach ( array_keys( $aliases ) as $source ) {
			$lines[] = sprintf( 'RewriteCond "%s" -f', $marker );
			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteRule ^%s(?:/.*)?$ index.php [END,QSA,NC]', preg_quote( $source, '#' ) );
		}

		$lines[] = '';
		$lines[] = '# Send nonessential core disclosure files to the theme 404.';
		$lines[] = sprintf( 'RewriteCond "%s" -f', $marker );
		$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
		$lines[] = 'RewriteRule ^(?:readme\.html|license\.txt|wp-config-sample\.php)$ index.php [END,QSA,NC]';

		$lines[] = '</IfModule>';
		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}

	public function nginx(): string {
		$aliases  = $this->absoluteAliases();
		$marker   = $this->quoteNginx( str_replace( '\\', '/', Marker::path() ) );
		$probe    = $this->quoteNginx( str_replace( '\\', '/', Marker::probePath() ) );
		$recovery = $this->quoteNginx( str_replace( '\\', '/', Marker::recoveryPath() ) );
		$blocked  = array_merge( array_keys( $aliases ), $this->absoluteDisclosurePaths() );
		$sources  = implode( '|', array_map( static fn ( string $path ): string => preg_quote( $path, '~' ), $blocked ) );
		$login    = preg_quote( $this->mapper->targetPath( 'login' ), '~' );
		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );
		$index    = $sitePath . '/index.php';

		$lines = array(
			'# BEGIN Hide WP Surface',
			'# Place inside the WordPress server {} block, before the generic location rules.',
			'# Login front controller fallback.',
			sprintf( 'rewrite ^%s/?$ %s last;', $login, $index ),
			'',
			'set $hwp_aliases_enabled 0;',
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 1; }', $marker ),
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 1; }', $probe ),
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 0; }', $recovery ),
			'set $hwp_paths_enabled 0;',
			sprintf( 'if (-f "%s") { set $hwp_paths_enabled 1; }', $marker ),
			sprintf( 'if (-f "%s") { set $hwp_paths_enabled 0; }', $recovery ),
			'set $hwp_original_path 0;',
			sprintf( 'if ($uri ~* "^(?:%s)(?:/|$)") { set $hwp_original_path 1; }', $sources ),
			'set $hwp_block_original "$hwp_paths_enabled$hwp_original_path";',
			sprintf( 'if ($hwp_block_original = "11") { rewrite ^ %s last; }', $index ),
			'',
			'# Internal aliases.',
		);

		foreach ( $aliases as $source => $target ) {
			$lines[] = 'if ($hwp_aliases_enabled = 1) {';
			$lines[] = sprintf(
				'    rewrite ^%1$s(?:/(.*))?/?$ %2$s/$1 break;',
				preg_quote( $target, '~' ),
				$source
			);
			$lines[] = '}';
		}

		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}

	/**
	 * @return array<string, string>
	 */
	private function relativeAliases(): array {
		return array(
			basename( $this->mapper->sourcePath( 'admin' ) )    => basename( $this->mapper->targetPath( 'admin' ) ),
			basename( $this->mapper->sourcePath( 'content' ) )  => basename( $this->mapper->targetPath( 'content' ) ),
			basename( $this->mapper->sourcePath( 'includes' ) ) => basename( $this->mapper->targetPath( 'includes' ) ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function absoluteAliases(): array {
		return array(
			$this->mapper->sourcePath( 'admin' )    => $this->mapper->targetPath( 'admin' ),
			$this->mapper->sourcePath( 'content' )  => $this->mapper->targetPath( 'content' ),
			$this->mapper->sourcePath( 'includes' ) => $this->mapper->targetPath( 'includes' ),
		);
	}

	/**
	 * @return list<string>
	 */
	private function absoluteDisclosurePaths(): array {
		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );

		return array_map(
			static fn ( string $file ): string => $sitePath . '/' . $file,
			array( 'readme.html', 'license.txt', 'wp-config-sample.php' )
		);
	}

	private function quoteNginx( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}
}
