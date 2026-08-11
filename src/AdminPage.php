<?php

declare(strict_types=1);

namespace HideWp;

defined( 'ABSPATH' ) || exit;

final readonly class AdminPage {
	private const PAGE = 'hide-wp-surface';

	public function __construct(
		private Settings $settings,
		private PathMapper $mapper,
		private ServerConfig $serverConfig,
		private ServerVerifier $verifier,
		private AuthCookieBridge $cookies
	) {
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'wp_ajax_hide_wp_verify_login', array( $this, 'verifyLogin' ) );
		add_action( 'wp_ajax_hide_wp_verify_paths', array( $this, 'verifyPaths' ) );
		add_action( 'wp_ajax_hide_wp_disable_paths', array( $this, 'disablePaths' ) );

		add_filter( 'plugin_action_links_' . HIDE_WP_BASENAME, array( $this, 'addPluginActionLinks' ) );
	}

	public function addMenu(): void {
		add_options_page(
			__( 'Hide WP Surface', 'hide-wp-surface' ),
			__( 'Hide WP Surface', 'hide-wp-surface' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}



	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public function addPluginActionLinks( array $links ): array {
		$settingsLink = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'hide-wp-surface' )
		);

		array_unshift( $links, $settingsLink );

		return $links;
	}

	public function registerSettings(): void {
		register_setting(
			'hide_wp_surface',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public function enqueueAssets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		$assetBase = $this->mapper->rewriteUrl( HIDE_WP_URL );

		wp_enqueue_style( 'hide-wp-admin', $assetBase . 'assets/admin.css', array(), HIDE_WP_VERSION );
		wp_enqueue_script( 'hide-wp-admin', $assetBase . 'assets/admin.js', array(), HIDE_WP_VERSION, true );
		wp_localize_script(
			'hide-wp-admin',
			'hideWpAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'hide_wp_paths' ),
				'loginAction'   => 'hide_wp_verify_login',
				'verifyAction'  => 'hide_wp_verify_paths',
				'disableAction' => 'hide_wp_disable_paths',
				'working'       => __( 'Checking server routes...', 'hide-wp-surface' ),
				'failed'        => __( 'The request failed.', 'hide-wp-surface' ),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'hide-wp-surface' ) );
		}

		$options             = $this->settings->all();
		$options['alias_query_token'] = $this->settings->aliasQueryToken();
		$loginRequested      = $this->settings->loginRequested();
		$loginEnabled        = $this->settings->loginEnabled();
		$pathsEnabled        = $this->settings->pathsEnabled();
		$aliasesSupported    = $this->mapper->supportsVerifiedAliases();
		$hasRequestedAliases = $this->settings->hasRequestedPathAliases();
		$loginUrl            = $this->mapper->rewriteUrl(
			rtrim( (string) get_option( 'siteurl', '' ), '/' ) . '/wp-login.php',
			false,
			true
		);
		$loginStatus = $loginEnabled
			? __( 'Enabled and verified', 'hide-wp-surface' )
			: ( $loginRequested ? __( 'Pending verification; wp-login.php remains available', 'hide-wp-surface' ) : __( 'Disabled', 'hide-wp-surface' ) );
		$pathStatus  = $this->pathStatusLabel( $pathsEnabled );
		?>
		<div class="wrap hide-wp-wrap">
			<h1><?php echo esc_html__( 'Hide WP Surface', 'hide-wp-surface' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Path hiding reduces automated scanning noise. It does not replace updates, strong authentication, 2FA, rate limiting, or a WAF.', 'hide-wp-surface' ); ?>
			</p>

			<?php settings_errors( Settings::OPTION ); ?>

			<?php if ( Marker::isRecoveryRequested() ) : ?>
				<div class="notice notice-error inline"><p>
					<?php echo esc_html__( 'Emergency recovery mode is active. Path verification remains disabled until the recovery constant and recovery file are removed.', 'hide-wp-surface' ); ?>
				</p></div>
			<?php endif; ?>
			<?php if ( '' !== $this->settings->activeConfigurationHash() && ! $pathsEnabled && ! Marker::isRecoveryRequested() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php echo esc_html__( 'A previously saved alias state has no matching configuration marker. Aliases are disabled; replace the generated server block and verify them again.', 'hide-wp-surface' ); ?>
				</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper hide-wp-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Settings sections', 'hide-wp-surface' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" id="hide-wp-tab-paths" role="tab" aria-selected="true" aria-controls="hide-wp-panel-paths" data-hide-wp-tab="paths">
					<?php echo esc_html__( 'Paths and Login', 'hide-wp-surface' ); ?>
				</button>
				<button type="button" class="nav-tab" id="hide-wp-tab-server" role="tab" aria-selected="false" aria-controls="hide-wp-panel-server" data-hide-wp-tab="server" tabindex="-1">
					<?php echo esc_html__( 'Server Integration', 'hide-wp-surface' ); ?>
				</button>
				<button type="button" class="nav-tab" id="hide-wp-tab-cleanup" role="tab" aria-selected="false" aria-controls="hide-wp-panel-cleanup" data-hide-wp-tab="cleanup" tabindex="-1">
					<?php echo esc_html__( 'Fingerprint Cleanup', 'hide-wp-surface' ); ?>
				</button>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( 'hide_wp_surface' ); ?>

				<section class="hide-wp-tab-panel" id="hide-wp-panel-paths" role="tabpanel" aria-labelledby="hide-wp-tab-paths" data-hide-wp-panel="paths">
					<h2><?php echo esc_html__( 'Login Path', 'hide-wp-surface' ); ?></h2>
					<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Custom login', 'hide-wp-surface' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[login_enabled]" value="1" <?php checked( true, $options['login_enabled'] ); ?> <?php disabled( is_multisite() ); ?>>
								<?php echo esc_html__( 'Hide direct wp-login.php requests', 'hide-wp-surface' ); ?>
							</label>
							<?php if ( is_multisite() ) : ?>
								<p class="description"><?php echo esc_html__( 'Custom login and server paths are unavailable on multisite.', 'hide-wp-surface' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hide-wp-login-slug"><?php echo esc_html__( 'Login path', 'hide-wp-surface' ); ?></label></th>
						<td><?php $this->pathInput( 'login_slug', (string) $options['login_slug'], 'hide-wp-login-slug' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Login status', 'hide-wp-surface' ); ?></th>
						<td>
							<strong class="<?php echo $loginEnabled ? 'hide-wp-ok' : 'hide-wp-off'; ?>">
								<?php echo esc_html( $loginStatus ); ?>
							</strong>
							<p class="description">
								<a href="<?php echo esc_url( $loginUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $loginUrl ); ?></a>
							</p>
							<button type="button" class="button" id="hide-wp-verify-login" <?php disabled( ! $loginRequested || is_multisite() ); ?>>
								<?php echo esc_html__( 'Verify Login Path', 'hide-wp-surface' ); ?>
							</button>
							<span id="hide-wp-login-result" role="status" aria-live="polite"></span>
						</td>
					</tr>
					</table>

					<h2><?php echo esc_html__( 'Verified Server Aliases', 'hide-wp-surface' ); ?></h2>
					<p>
						<?php echo esc_html__( 'Choose which WordPress directories to alias, save the paths, install the generated server configuration, then use Verify and Enable. The plugin never edits Nginx or Apache configuration.', 'hide-wp-surface' ); ?>
					</p>
					<?php if ( ! $aliasesSupported ) : ?>
						<div class="notice notice-warning inline"><p>
							<?php echo esc_html__( 'Server aliases require HTTPS, an ASCII-only WordPress URL directory, and wp-content on the same origin and URL directory.', 'hide-wp-surface' ); ?>
						</p></div>
					<?php endif; ?>
					<?php if ( ! $hasRequestedAliases ) : ?>
						<div class="notice notice-warning inline"><p>
							<?php echo esc_html__( 'Select at least one server alias before running verification. Use Disable Path Aliases to turn all verified aliases off.', 'hide-wp-surface' ); ?>
						</p></div>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<?php
						$this->aliasRow( 'admin', __( 'Admin path', 'hide-wp-surface' ), __( 'Alias wp-admin', 'hide-wp-surface' ), (bool) $options['admin_enabled'], (string) $options['admin_slug'], 'hide-wp-admin-slug' );
						$this->aliasRow( 'content', __( 'Content path', 'hide-wp-surface' ), __( 'Alias wp-content', 'hide-wp-surface' ), (bool) $options['content_enabled'], (string) $options['content_slug'], 'hide-wp-content-slug' );
						$this->aliasRow( 'includes', __( 'Includes path', 'hide-wp-surface' ), __( 'Alias wp-includes', 'hide-wp-surface' ), (bool) $options['includes_enabled'], (string) $options['includes_slug'], 'hide-wp-includes-slug' );
						?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Status', 'hide-wp-surface' ); ?></th>
							<td>
								<strong class="<?php echo $pathsEnabled ? 'hide-wp-ok' : 'hide-wp-off'; ?>">
									<?php echo esc_html( $pathStatus ); ?>
								</strong>
								<?php if ( $pathsEnabled ) : ?>
									<p class="description">
										<?php echo esc_html( $this->activeAliasesDescription() ); ?>
									</p>
								<?php endif; ?>
								<?php if ( is_multisite() ) : ?>
									<p class="description"><?php echo esc_html__( 'Server aliases are intentionally unavailable on multisite.', 'hide-wp-surface' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</section>

				<section class="hide-wp-tab-panel" id="hide-wp-panel-server" role="tabpanel" aria-labelledby="hide-wp-tab-server" data-hide-wp-panel="server">
					<h2><?php echo esc_html__( 'Nginx Integration', 'hide-wp-surface' ); ?></h2>
					<p class="description">
						<?php echo esc_html__( 'The generated aliases re-enter the canonical WordPress paths so the existing PHP handler and path-specific security rules remain in effect.', 'hide-wp-surface' ); ?>
					</p>
					<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hide-wp-alias-query-key"><?php echo esc_html__( 'Alias query key', 'hide-wp-surface' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="hide-wp-alias-query-key" name="<?php echo esc_attr( Settings::OPTION ); ?>[alias_query_key]" value="<?php echo esc_attr( (string) $options['alias_query_key'] ); ?>" placeholder="hidewp_surface_key">
							<p class="description"><?php echo esc_html__( 'Used by generated internal rewrites as a capability parameter. Each route type receives a separate derived token. Use lowercase letters, numbers, and underscores for the key.', 'hide-wp-surface' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Alias token', 'hide-wp-surface' ); ?></th>
						<td>
							<code><?php echo esc_html( substr( (string) $options['alias_query_token'], 0, 8 ) . '…' ); ?></code>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[alias_query_token_rotate]" value="1">
								<?php echo esc_html__( 'Rotate token on save', 'hide-wp-surface' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'After changing the key or rotating the token, replace the generated server block, verify the login path again, and run Verify and Enable for path aliases.', 'hide-wp-surface' ); ?></p>
						</td>
					</tr>
					</table>

					<hr>
					<h2><?php echo esc_html__( 'Server Configuration', 'hide-wp-surface' ); ?></h2>
					<p><?php echo esc_html__( 'Back up the server configuration first. Replace any older Hide WP Surface block. For Apache, insert it before the standard WordPress rewrite block. For Nginx, insert it in the WordPress server block and reload Nginx.', 'hide-wp-surface' ); ?></p>
					<p class="description"><?php echo esc_html__( 'Use a security-patched web server. For upstream Nginx, use 1.30.4/1.31.3 or later. Apply the same WAF, IP restrictions, Basic Auth, rate limits, and cache exclusions to every alias at any proxy or CDN in front of WordPress, and redact the configured alias query key from logs.', 'hide-wp-surface' ); ?></p>

					<h3><?php echo esc_html__( 'Apache 2.4+ (.htaccess)', 'hide-wp-surface' ); ?></h3>
					<textarea class="large-text code hide-wp-config" rows="18" readonly><?php echo esc_textarea( $this->serverConfig->apache() ); ?></textarea>

					<h3><?php echo esc_html__( 'Nginx', 'hide-wp-surface' ); ?></h3>
					<textarea class="large-text code hide-wp-config" rows="18" readonly><?php echo esc_textarea( $this->serverConfig->nginx() ); ?></textarea>

					<p>
						<button type="button" class="button button-primary" id="hide-wp-verify" <?php disabled( is_multisite() || ! $aliasesSupported || Marker::isRecoveryRequested() || ! $hasRequestedAliases ); ?>>
							<?php echo esc_html__( 'Verify and Enable', 'hide-wp-surface' ); ?>
						</button>
						<button type="button" class="button" id="hide-wp-disable">
							<?php echo esc_html__( 'Disable Path Aliases', 'hide-wp-surface' ); ?>
						</button>
						<span id="hide-wp-result" role="status" aria-live="polite"></span>
					</p>

					<h2><?php echo esc_html__( 'Emergency Recovery', 'hide-wp-surface' ); ?></h2>
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: wp-config.php constant, 2: recovery file path. */
								__( 'Set %1$s in wp-config.php to disable PHP-side routing. For full Apache or Nginx recovery, create an empty file at %2$s; generated server rules cannot read the PHP constant.', 'hide-wp-surface' ),
								'<code>define( \'HIDE_WP_RECOVERY_MODE\', true );</code>',
								'<code>' . esc_html( Marker::recoveryPath() ) . '</code>'
							)
						);
						?>
					</p>
				</section>

				<section class="hide-wp-tab-panel" id="hide-wp-panel-cleanup" role="tabpanel" aria-labelledby="hide-wp-tab-cleanup" data-hide-wp-panel="cleanup">
					<h2><?php echo esc_html__( 'Fingerprint Cleanup', 'hide-wp-surface' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->checkboxRow( 'remove_generator', __( 'Remove generator metadata', 'hide-wp-surface' ), (bool) $options['remove_generator'] );
						$this->checkboxRow( 'remove_discovery_links', __( 'Remove RSD, WLW, shortlink, REST, oEmbed, and pingback discovery hints', 'hide-wp-surface' ), (bool) $options['remove_discovery_links'] );
						$this->checkboxRow( 'strip_core_version', __( 'Remove only the WordPress core version from asset query strings', 'hide-wp-surface' ), (bool) $options['strip_core_version'] );
						$this->checkboxRow( 'generic_login_errors', __( 'Use a generic login error message', 'hide-wp-surface' ), (bool) $options['generic_login_errors'] );
						?>
					</table>
				</section>

				<div class="hide-wp-save-bar">
					<?php submit_button( __( 'Save Settings', 'hide-wp-surface' ) ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	public function verifyLogin(): never {
		check_ajax_referer( 'hide_wp_paths', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hide-wp-surface' ) ), 403 );
		}

		$result = $this->verifier->verifyLoginRoute();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'The login path is verified. Direct wp-login.php requests are now hidden.', 'hide-wp-surface' ),
				'redirect' => $this->settingsUrl(),
			)
		);
	}

	public function verifyPaths(): never {
		check_ajax_referer( 'hide_wp_paths', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hide-wp-surface' ) ), 403 );
		}

		$result = $this->verifier->verifyAndEnable();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->cookies->mirrorCurrentAuthCookie();

		wp_send_json_success(
			array(
				'message'  => __( 'Selected alias and blocking checks passed. Path aliases are enabled.', 'hide-wp-surface' ),
				'redirect' => $this->settingsUrl(),
			)
		);
	}

	public function disablePaths(): never {
		check_ajax_referer( 'hide_wp_paths', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hide-wp-surface' ) ), 403 );
		}

		$result = $this->verifier->disableSafely();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		$this->cookies->clearAliasAuthCookies();

		wp_send_json_success(
			array(
				'message'  => __( 'Path aliases are disabled and the server marker was removed.', 'hide-wp-surface' ),
				'redirect' => $this->settingsUrl(),
			)
		);
	}

	private function settingsUrl(): string {
		return esc_url_raw( add_query_arg( 'page', self::PAGE, admin_url( 'options-general.php' ) ) );
	}

	private function aliasRow( string $type, string $label, string $checkboxLabel, bool $checked, string $value, string $id ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $type . '_enabled]' ); ?>" value="1" <?php checked( true, $checked ); ?> <?php disabled( is_multisite() ); ?>>
					<?php echo esc_html( $checkboxLabel ); ?>
				</label>
				<p><?php $this->pathInput( $type . '_slug', $value, $id ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function pathInput( string $name, string $value, string $id ): void {
		$base = rtrim( (string) get_option( 'siteurl', '' ), '/' );
		?>
		<code><?php echo esc_html( $base . '/' ); ?></code><input
			type="text"
			class="regular-text code"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( Settings::OPTION . '[' . $name . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
			maxlength="63"
			required
		>
		<?php
	}

	private function checkboxRow( string $name, string $label, bool $checked ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $name . ']' ); ?>" value="1" <?php checked( true, $checked ); ?>>
					<?php echo esc_html__( 'Enabled', 'hide-wp-surface' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	private function pathStatusLabel( bool $pathsEnabled ): string {
		return $pathsEnabled
			? __( 'Enabled and verified', 'hide-wp-surface' )
			: __( 'Disabled', 'hide-wp-surface' );
	}

	private function activeAliasesDescription(): string {
		$labels = array_map( array( $this, 'aliasLabel' ), $this->settings->activeAliasTypes() );

		return sprintf(
			/* translators: %s: comma-separated enabled aliases. */
			__( 'Active aliases: %s.', 'hide-wp-surface' ),
			array() === $labels ? __( 'none', 'hide-wp-surface' ) : implode( ', ', $labels )
		);
	}

	private function aliasLabel( string $type ): string {
		return match ( $type ) {
			'admin'    => 'wp-admin',
			'content'  => 'wp-content',
			'includes' => 'wp-includes',
			default    => $type,
		};
	}
}
