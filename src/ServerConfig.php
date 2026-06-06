<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class ServerConfig {
	public function __construct( private PathMapper $mapper, private Settings $settings ) {
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
			$lines[] = sprintf( 'RewriteRule ^(%s(?:/.*)?)$ index.php?hide_wp_original_path=$1 [END,QSA,NC]', preg_quote( $alias['source'], '#' ) );
		}

		$lines[] = '';
		$lines[] = '# Send nonessential core disclosure files to the theme 404.';
		$lines[] = sprintf( 'RewriteCond "%s" -f', $marker );
		$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
		$lines[] = 'RewriteRule ^(readme\.html|license\.txt|wp-config-sample\.php)$ index.php?hide_wp_original_path=$1 [END,QSA,NC]';

		$lines[] = '</IfModule>';
		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}

	public function nginx(): string {
		$aliases  = $this->absoluteAliasSpecs();
		$marker   = $this->quoteNginx( str_replace( '\\', '/', Marker::path() ) );
		$recovery = $this->quoteNginx( str_replace( '\\', '/', Marker::recoveryPath() ) );
		$blocked  = array_merge( array_column( $aliases, 'source' ), $this->absoluteDisclosurePaths() );
		$sources  = implode( '|', array_map( static fn ( string $path ): string => preg_quote( $path, '~' ), $blocked ) );
		$login    = preg_quote( $this->mapper->targetPath( 'login' ), '~' );
		$sitePath = $this->mapper->parentPath( $this->mapper->sourcePath( 'login' ) );
		$index    = $sitePath . '/index.php';

		$lines = array(
			'# BEGIN Hide WP Surface',
			'# Place this block directly inside the WordPress server {} block, before location / and PHP/static locations.',
			'# Do not place it inside another location block.',
			'',
			'# Login front controller fallback.',
			sprintf( 'rewrite ^%s/?$ %s$is_args$args last;', $login, $index ),
			'',
			'# Internal aliases. These are server-rewrite rules, not front-controller fallbacks.',
			'# They must run before Nginx chooses a generic PHP or WordPress try_files location.',
			'# The aliases stay active while this server block is installed; remove the block to fully disable them.',
		);

		foreach ( $aliases as $alias ) {
			$source = $alias['source'];
			$target = $alias['target'];
			$targetPattern = preg_quote( $target, '~' );

			if ( 'admin' === $alias['type'] ) {
				$lines = array_merge( $lines, $this->nginxAdminAliasLocations( $source, $target, $recovery ) );
				continue;
			}

			$lines[] = sprintf( 'if (!-f "%s") { rewrite ^%s/?$ %s/$is_args$args last; }', $recovery, $targetPattern, $source );
			$lines[] = sprintf( 'if (!-f "%s") { rewrite ^%s/(.*)$ %s/$1$is_args$args last; }', $recovery, $targetPattern, $source );
		}

		$lines[] = '';
		$lines[] = '# Runtime switch for blocking original WordPress paths only.';
		$lines[] = 'set $hwp_paths_enabled 0;';
		$lines[] = sprintf( 'if (-f "%s") { set $hwp_paths_enabled 1; }', $marker );
		$lines[] = sprintf( 'if (-f "%s") { set $hwp_paths_enabled 0; }', $recovery );
		$lines[] = '';
		$lines[] = '# Send original WordPress paths to WordPress so the active theme renders its 404 template.';
		$lines[] = 'set $hwp_original_path 0;';
		$lines[] = 'set $hwp_original_path_value "";';
		$lines[] = sprintf( 'if ($request_uri ~* "^((?:%s)(?:[/?]|$)[^?]*)") { set $hwp_original_path 1; set $hwp_original_path_value $1; }', $sources );
		$lines[] = 'set $hwp_block_original "$hwp_paths_enabled$hwp_original_path";';
		$lines[] = sprintf( 'if ($hwp_block_original = "11") { rewrite ^ %s?hide_wp_original_path=$hwp_original_path_value&$args last; }', $index );
		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}


	/**
	 * @return list<string>
	 */
	private function nginxAdminAliasLocations( string $source, string $target, string $recovery ): array {
		$root = $this->quoteNginx( rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) );
		$pass = $this->quoteNginx( $this->settings->getString( 'nginx_fastcgi_pass' ) );
		if ( '' === $pass ) {
			$pass = '__SET_NGINX_FASTCGI_PASS_IN_HIDE_WP_SETTINGS__';
		}

		$targetPattern = preg_quote( $target, '~' );
		$sourcePrefix = rtrim( $source, '/' );
		$lines = array(
			'# wp-admin alias: execute admin PHP files through FastCGI directly.',
			'# Keep these blocks before any generic PHP location. Set Nginx FastCGI pass in plugin settings.',
			sprintf( 'location = %s {', $target ),
			sprintf( '    if (-f "%s") { return 404; }', $recovery ),
			sprintf( '    return 301 %s/;', $target ),
			'}',
			sprintf( 'location = %s/ {', $target ),
			sprintf( '    if (-f "%s") { return 404; }', $recovery ),
			'    include fastcgi_params;',
			sprintf( '    fastcgi_param SCRIPT_FILENAME %s/wp-admin/index.php;', $root ),
			sprintf( '    fastcgi_param SCRIPT_NAME %s/index.php;', $sourcePrefix ),
			sprintf( '    fastcgi_param PHP_SELF %s/index.php;', $sourcePrefix ),
			sprintf( '    fastcgi_param DOCUMENT_ROOT %s;', $root ),
			sprintf( '    fastcgi_pass %s;', $pass ),
			'}',
			sprintf( 'location ~ ^%s/(?<hwp_admin_script>[A-Za-z0-9_./-]+\\.php)$ {', $targetPattern ),
			sprintf( '    if (-f "%s") { return 404; }', $recovery ),
			'    if ($hwp_admin_script ~ "\\.\\.") { return 404; }',
			sprintf( '    if (!-f %s/wp-admin/$hwp_admin_script) { return 404; }', $root ),
			'    include fastcgi_params;',
			sprintf( '    fastcgi_param SCRIPT_FILENAME %s/wp-admin/$hwp_admin_script;', $root ),
			sprintf( '    fastcgi_param SCRIPT_NAME %s/$hwp_admin_script;', $sourcePrefix ),
			sprintf( '    fastcgi_param PHP_SELF %s/$hwp_admin_script;', $sourcePrefix ),
			sprintf( '    fastcgi_param DOCUMENT_ROOT %s;', $root ),
			sprintf( '    fastcgi_pass %s;', $pass ),
			'}',
			'# wp-admin alias: serve admin static assets directly from wp-admin.',
			'# Keep this before generic static locations; do not use try_files with the alias URI.',
			sprintf( 'location ~ ^%s/(?<hwp_admin_asset>[A-Za-z0-9_./-]+)$ {', $targetPattern ),
			sprintf( '    if (-f "%s") { return 404; }', $recovery ),
			'    if ($hwp_admin_asset ~ "\.\.") { return 404; }',
			sprintf( '    alias %s/wp-admin/$hwp_admin_asset;', $root ),
			'}',
		);

		return $lines;
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
