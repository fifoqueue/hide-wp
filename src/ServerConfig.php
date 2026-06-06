<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class ServerConfig {
	public function __construct( private PathMapper $mapper ) {
	}

	public function apache(): string {
		$aliases  = $this->relativeAliasSpecs();
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

		foreach ( $aliases as $alias ) {
			$source = $alias['source'];
			$target = $alias['target'];

			if ( 'admin' === $alias['type'] ) {
				$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
				$lines[] = sprintf( 'RewriteCond "%s" -f [OR]', $marker );
				$lines[] = sprintf( 'RewriteCond "%s" -f', $probe );
				$lines[] = sprintf(
					'RewriteRule ^%1$s/?$ %2$s/index.php [END,QSA,NC]',
					preg_quote( $target, '#' ),
					$source
				);
			}

			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteCond "%s" -f [OR]', $marker );
			$lines[] = sprintf( 'RewriteCond "%s" -f', $probe );
			$lines[] = sprintf(
				'admin' === $alias['type']
					? 'RewriteRule ^%1$s/(.+)$ %2$s/$1 [END,QSA,NC]'
					: 'RewriteRule ^%1$s(?:/(.*))?/?$ %2$s/$1 [END,QSA,NC]',
				preg_quote( $target, '#' ),
				$source
			);
		}

		$lines[] = '';
		$lines[] = '# Send original paths to WordPress so the active theme renders its 404 template.';
		foreach ( $aliases as $alias ) {
			$lines[] = sprintf( 'RewriteCond "%s" -f', $marker );
			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteRule ^%s(?:/.*)?$ index.php [END,QSA,NC]', preg_quote( $alias['source'], '#' ) );
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
		$aliases  = $this->absoluteAliasSpecs();
		$marker   = $this->quoteNginx( str_replace( '\\', '/', Marker::path() ) );
		$probe    = $this->quoteNginx( str_replace( '\\', '/', Marker::probePath() ) );
		$recovery = $this->quoteNginx( str_replace( '\\', '/', Marker::recoveryPath() ) );
		$blocked  = array_merge( array_column( $aliases, 'source' ), $this->absoluteDisclosurePaths() );
		$sources  = implode( '|', array_map( static fn ( string $path ): string => preg_quote( $path, '~' ), $blocked ) );
		$login    = preg_quote( $this->mapper->targetPath( 'login' ), '~' );
		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );
		$index    = $sitePath . '/index.php';

		$lines = array(
			'# BEGIN Hide WP Surface',
			'# Place inside the WordPress server {} block, before the generic location rules.',
			'# Login front controller fallback.',
			sprintf( 'rewrite ^%s/?$ %s$is_args$args last;', $login, $index ),
			'',
			'set $hwp_aliases_enabled 0;',
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 1; }', $marker ),
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 1; }', $probe ),
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 0; }', $recovery ),
			'set $hwp_paths_enabled 0;',
			sprintf( 'if (-f "%s") { set $hwp_paths_enabled 1; }', $marker ),
			sprintf( 'if (-f "%s") { set $hwp_paths_enabled 0; }', $recovery ),
			'# Detect only the original client request path, not the internally rewritten alias target.',
			'set $hwp_original_path 0;',
			sprintf( 'if ($request_uri ~* "^(?:%s)(?:[/?]|$)") { set $hwp_original_path 1; }', $sources ),
			'set $hwp_block_original "$hwp_paths_enabled$hwp_original_path";',
			sprintf( 'if ($hwp_block_original = "11") { rewrite ^ %s$is_args$args last; }', $index ),
			'',
			'# Internal aliases.',
		);

		foreach ( $aliases as $alias ) {
			$source = $alias['source'];
			$target = preg_quote( $alias['target'], '~' );

			$lines[] = 'if ($hwp_aliases_enabled = 1) {';
			if ( 'admin' === $alias['type'] ) {
				$lines[] = sprintf( '    rewrite ^%1$s/?$ %2$s/index.php$is_args$args last;', $target, $source );
				$lines[] = sprintf( '    rewrite ^%1$s/(.+)$ %2$s/$1$is_args$args last;', $target, $source );
			} else {
				$lines[] = sprintf( '    rewrite ^%1$s(?:/(.*))?/?$ %2$s/$1$is_args$args last;', $target, $source );
			}
			$lines[] = '}';
		}

		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}

	/**
	 * @return list<array{type: string, source: string, target: string}>
	 */
	private function relativeAliasSpecs(): array {
		$aliases = array();
		foreach ( $this->mapper->requestedAliasTypes() as $type ) {
			$aliases[] = array(
				'type'   => $type,
				'source' => basename( $this->mapper->sourcePath( $type ) ),
				'target' => basename( $this->mapper->targetPath( $type ) ),
			);
		}

		return $aliases;
	}

	/**
	 * @return list<array{type: string, source: string, target: string}>
	 */
	private function absoluteAliasSpecs(): array {
		$aliases = array();
		foreach ( $this->mapper->requestedAliasTypes() as $type ) {
			$aliases[] = array(
				'type'   => $type,
				'source' => $this->mapper->sourcePath( $type ),
				'target' => $this->mapper->targetPath( $type ),
			);
		}

		return $aliases;
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
