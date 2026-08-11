<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class ServerConfig {
	public function __construct( private PathMapper $mapper, private Settings $settings ) {
	}

	public function apache(): string {
		$configurationHash = $this->settings->configurationHash();
		$loginHash         = $this->settings->loginConfigurationHash();
		$aliases           = $this->relativeAliasSpecs();
		$marker            = $this->quoteApache( str_replace( '\\', '/', Marker::path( $configurationHash ) ) );
		$probe             = $this->quoteApache( str_replace( '\\', '/', Marker::probePath( $configurationHash ) ) );
		$loginMarker       = $this->quoteApache( str_replace( '\\', '/', Marker::loginPath( $loginHash ) ) );
		$loginProbe        = $this->quoteApache( str_replace( '\\', '/', Marker::loginProbePath( $loginHash ) ) );
		$recovery          = $this->quoteApache( str_replace( '\\', '/', Marker::recoveryPath() ) );
		$key               = $this->settings->aliasQueryKey();
		$token             = $this->settings->aliasRequestToken( 'login' );
		$originalGuard     = $this->settings->originalPathGuard();

		$lines = array(
			'# BEGIN Hide WP Surface',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'',
		);

		if ( $this->settings->getBool( 'login_enabled' ) ) {
			$lines[] = '# Login alias. Rewrite directly to wp-login.php so login/OIDC plugins see the native login bootstrap.';
			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteCond "%s" -f [OR]', $loginMarker );
			$lines[] = sprintf( 'RewriteCond "%s" -f', $loginProbe );
			$lines[] = sprintf(
				'RewriteRule ^%s/?$ wp-login.php?%s=%s [END,QSA,NC]',
				preg_quote( basename( $this->mapper->targetPath( 'login' ) ), '#' ),
				$key,
				$token
			);
			$lines[] = '';
		}

		$lines[] = '# Internal aliases. Keep these rules before the standard WordPress block.';

		foreach ( $aliases as $alias ) {
			$source     = $alias['source'];
			$target     = $alias['target'];
			$aliasToken = $this->settings->aliasRequestToken( $alias['type'] );

			if ( 'admin' === $alias['type'] ) {
				$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
				$lines[] = sprintf( 'RewriteCond "%s" -f [OR]', $marker );
				$lines[] = sprintf( 'RewriteCond "%s" -f', $probe );
				$lines[] = sprintf(
					'RewriteRule ^%1$s/?$ %2$s/index.php?%3$s=%4$s [END,QSA,NC]',
					preg_quote( $target, '#' ),
					$source,
					$key,
					$aliasToken
				);
			}

			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteCond "%s" -f [OR]', $marker );
			$lines[] = sprintf( 'RewriteCond "%s" -f', $probe );
			$lines[] = sprintf(
				'admin' === $alias['type']
					? 'RewriteRule ^%1$s/(.+)$ %2$s/$1?%3$s=%4$s [END,QSA,NC]'
					: 'RewriteRule ^%1$s(?:/(.*))?/?$ %2$s/$1?%3$s=%4$s [END,QSA,NC]',
				preg_quote( $target, '#' ),
				$source,
				$key,
				$aliasToken
			);
		}

		$lines[] = '';
		$lines[] = '# Send original paths to WordPress so the active theme renders its 404 template.';
		foreach ( $aliases as $alias ) {
			$lines[] = sprintf( 'RewriteCond "%s" -f', $marker );
			$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
			$lines[] = sprintf( 'RewriteRule ^(%s(?:/.*)?)$ index.php?hide_wp_original_path=$1&hide_wp_original_guard=%s [END,QSA,NC]', preg_quote( $alias['source'], '#' ), $originalGuard );
		}

		$lines[] = '';
		$lines[] = '# Send nonessential core disclosure files to the theme 404.';
		$lines[] = sprintf( 'RewriteCond "%s" -f', $marker );
		$lines[] = sprintf( 'RewriteCond "%s" !-f', $recovery );
		$lines[] = sprintf( 'RewriteRule ^(readme\.html|license\.txt|wp-config-sample\.php)$ index.php?hide_wp_original_path=$1&hide_wp_original_guard=%s [END,QSA,NC]', $originalGuard );

		$lines[] = '</IfModule>';
		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}

	public function nginx(): string {
		$configurationHash = $this->settings->configurationHash();
		$loginHash         = $this->settings->loginConfigurationHash();
		$aliases           = $this->absoluteAliasSpecs();
		$marker            = $this->quoteNginx( str_replace( '\\', '/', Marker::path( $configurationHash ) ) );
		$probe             = $this->quoteNginx( str_replace( '\\', '/', Marker::probePath( $configurationHash ) ) );
		$loginMarker       = $this->quoteNginx( str_replace( '\\', '/', Marker::loginPath( $loginHash ) ) );
		$loginProbe        = $this->quoteNginx( str_replace( '\\', '/', Marker::loginProbePath( $loginHash ) ) );
		$recovery          = $this->quoteNginx( str_replace( '\\', '/', Marker::recoveryPath() ) );
		$login             = preg_quote( $this->mapper->targetPath( 'login' ), '~' );
		$sourceLogin       = $this->mapper->sourcePath( 'login' );
		$key               = $this->settings->aliasQueryKey();
		$token             = $this->settings->aliasRequestToken( 'login' );
		$originalGuard     = $this->settings->originalPathGuard();
		$sitePath          = $this->mapper->parentPath( $sourceLogin );
		$index             = $sitePath . '/index.php';

		$lines = array(
			'# BEGIN Hide WP Surface',
			'# Place this block directly inside the WordPress server {} block, before location / and PHP/static locations.',
			'# Do not place it inside another location block.',
			'',
			'# Runtime switch for verified and verification-only path aliases.',
			'set $hwp_aliases_enabled 0;',
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 1; }', $marker ),
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 1; }', $probe ),
			sprintf( 'if (-f "%s") { set $hwp_aliases_enabled 0; }', $recovery ),
			'',
		);

		if ( $this->settings->getBool( 'login_enabled' ) ) {
			$lines[] = '# Login alias. Rewrite directly to wp-login.php so login/OIDC plugins see the native login bootstrap.';
			$lines[] = 'set $hwp_login_enabled 0;';
			$lines[] = sprintf( 'if (-f "%s") { set $hwp_login_enabled 1; }', $loginMarker );
			$lines[] = sprintf( 'if (-f "%s") { set $hwp_login_enabled 1; }', $loginProbe );
			$lines[] = sprintf( 'if (-f "%s") { set $hwp_login_enabled 0; }', $recovery );
			$lines[] = sprintf( 'if ($hwp_login_enabled = 1) { rewrite ^%s/?$ %s?%s=%s&$args last; }', $login, $sourceLogin, $key, $token );
			$lines[] = '';
		}

		$lines[] = '# Internal aliases. Re-enter the canonical paths so their existing access control, WAF, rate-limit, cache, and PHP rules still apply.';

		foreach ( $aliases as $alias ) {
			$source        = $alias['source'];
			$target        = $alias['target'];
			$targetPattern = preg_quote( $target, '~' );

			if ( 'admin' === $alias['type'] ) {
				$lines = array_merge( $lines, $this->nginxAdminAliasRewriteRules( $source, $target ) );
				continue;
			}

			$aliasToken = $this->settings->aliasRequestToken( $alias['type'] );
			$lines[]    = sprintf( 'if ($hwp_aliases_enabled = 1) { rewrite ^%s/?$ %s/?%s=%s&$args last; }', $targetPattern, $source, $key, $aliasToken );
			$lines[] = sprintf( 'if ($hwp_aliases_enabled = 1) { rewrite ^%s/(.*)$ %s/$1?%s=%s&$args last; }', $targetPattern, $source, $key, $aliasToken );
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
		$lines[] = 'set $hwp_original_path_type none;';
		$lines[] = 'set $hwp_alias_origin_type none;';
		$lines[] = '# Match the normalized URI so percent encoding, dot segments, and duplicate slashes cannot bypass blocking.';
		foreach ( $aliases as $alias ) {
			$sourcePattern = $this->nginxPathPattern( $alias['source'] );
			$targetPattern = preg_quote( $alias['target'], '~' );
			$lines[] = sprintf( 'if ($uri ~* "^((?:%s)(?:/|$).*)") { set $hwp_original_path 1; set $hwp_original_path_value $1; set $hwp_original_path_type %s; }', $sourcePattern, $alias['type'] );
			$lines[] = sprintf( 'if ($request_uri ~* "^%s(?:/|[?]|$)") { set $hwp_alias_origin_type %s; }', $targetPattern, $alias['type'] );
		}

		$disclosures = implode( '|', array_map( array( $this, 'nginxPathPattern' ), $this->absoluteDisclosurePaths() ) );
		$lines[] = sprintf( 'if ($uri ~* "^((?:%s)(?:/|$).*)") { set $hwp_original_path 1; set $hwp_original_path_value $1; set $hwp_original_path_type disclosure; }', $disclosures );
		$lines[] = 'set $hwp_alias_path_pair "$hwp_alias_origin_type:$hwp_original_path_type";';
		$lines[] = 'set $hwp_internal_alias 0;';
		foreach ( $aliases as $alias ) {
			$lines[] = sprintf( 'if ($hwp_alias_path_pair = "%1$s:%1$s") { set $hwp_internal_alias 1; }', $alias['type'] );
		}
		$lines[] = 'set $hwp_block_original "$hwp_paths_enabled$hwp_original_path$hwp_internal_alias";';
		$lines[] = sprintf( 'if ($hwp_block_original = "110") { rewrite ^ %s?hide_wp_original_path=$hwp_original_path_value&hide_wp_original_guard=%s&$args last; }', $index, $originalGuard );
		$lines[] = '# END Hide WP Surface';

		return implode( "\n", $lines );
	}


	/**
	 * @return list<string>
	 */
	private function nginxAdminAliasRewriteRules( string $source, string $target ): array {
		$key           = $this->settings->aliasQueryKey();
		$token         = $this->settings->aliasRequestToken( 'admin' );
		$targetPattern = preg_quote( $target, '~' );

		return array(
			'# wp-admin alias: uses the site PHP handler and adds an internal alias flag.',
			sprintf( 'if ($hwp_aliases_enabled = 1) { rewrite ^%s/?$ %s/index.php?%s=%s&$args last; }', $targetPattern, $source, $key, $token ),
			sprintf( 'if ($hwp_aliases_enabled = 1) { rewrite ^%s/(.*)$ %s/$1?%s=%s&$args last; }', $targetPattern, $source, $key, $token ),
		);
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

	private function nginxPathPattern( string $path ): string {
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );

		return '/+' . implode(
			'/+',
			array_map( static fn ( string $segment ): string => preg_quote( $segment, '~' ), $segments )
		);
	}

	private function quoteNginx( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}

	private function quoteApache( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}
}
