<?php

declare(strict_types=1);

namespace HideWp;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

final class Updater {
	private const SLUG = 'hide-wp-surface';

	private static ?object $checker = null;

	public function __construct( private Settings $settings ) {
	}

	public function boot(): void {
		if ( ! $this->settings->getBool( 'github_updates_enabled' ) ) {
			return;
		}

		if ( defined( 'HIDE_WP_DISABLE_GITHUB_UPDATER' ) && true === HIDE_WP_DISABLE_GITHUB_UPDATER ) {
			return;
		}

		$repository = $this->repository();
		if ( '' === $repository || ! $this->loadPluginUpdateChecker() || ! class_exists( PucFactory::class ) ) {
			return;
		}

		$this->registerCliUpdateCheck();

		$checker = PucFactory::buildUpdateChecker(
			'https://github.com/' . $repository . '/',
			HIDE_WP_FILE,
			self::SLUG
		);

		if ( is_object( $checker ) && method_exists( $checker, 'setAuthentication' ) ) {
			$token = $this->githubToken();
			if ( '' !== $token ) {
				$checker->setAuthentication( $token );
			}
		}

		if ( is_object( $checker ) && method_exists( $checker, 'getVcsApi' ) ) {
			$api = $checker->getVcsApi();
			if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
				$api->enableReleaseAssets( '/(?:hide-wp-surface|hide-wp-master).*\.zip($|[?&#])/i' );
			}
		}

		self::$checker = is_object( $checker ) ? $checker : null;
	}

	public function checkForCliUpdate( mixed $input = null ): mixed {
		if ( is_object( self::$checker ) && method_exists( self::$checker, 'checkForUpdates' ) ) {
			self::$checker->checkForUpdates();
		}

		return $input;
	}

	private function registerCliUpdateCheck(): void {
		if (
			! defined( 'WP_CLI' )
			|| ! WP_CLI
			|| ! class_exists( '\WP_CLI', false )
			|| ! method_exists( '\WP_CLI', 'add_hook' )
		) {
			return;
		}

		\WP_CLI::add_hook( 'before_invoke:plugin update', array( $this, 'checkForCliUpdate' ) );
	}

	private function loadPluginUpdateChecker(): bool {
		$composer = HIDE_WP_DIR . 'vendor/autoload.php';
		if ( is_file( $composer ) ) {
			require_once $composer;
		}

		if ( class_exists( PucFactory::class ) ) {
			return true;
		}

		$bundled = HIDE_WP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( is_file( $bundled ) ) {
			require_once $bundled;
		}

		return class_exists( PucFactory::class );
	}

	private function repository(): string {
		$repository = $this->settings->getString( 'github_repository' );
		if ( '' === $repository && defined( 'HIDE_WP_GITHUB_REPOSITORY' ) && is_string( HIDE_WP_GITHUB_REPOSITORY ) ) {
			$repository = HIDE_WP_GITHUB_REPOSITORY;
		}

		return 1 === preg_match( '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repository ) ? $repository : '';
	}

	private function githubToken(): string {
		$token = $this->settings->getString( 'github_token' );
		if ( '' === $token && defined( 'HIDE_WP_GITHUB_TOKEN' ) && is_string( HIDE_WP_GITHUB_TOKEN ) ) {
			$token = HIDE_WP_GITHUB_TOKEN;
		}

		$token = preg_replace( '/[^A-Za-z0-9_.-]+/', '', $token );

		return is_string( $token ) ? $token : '';
	}
}
