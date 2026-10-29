<?php
/**
 * Plugin kernel.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Kernel;

use LearningLedger\Db\Installer;

/**
 * Boots the modules whose role is core or enabled.
 */
final class Plugin {

	/**
	 * Module class => role (`core`, `lrs`, `connector`). Each class is created by its own task.
	 */
	public const MODULES = array(
		'LearningLedger\Kernel\No_Page_Cache'              => 'core',
		'LearningLedger\Kernel\Status_Route'               => 'core',
		'LearningLedger\Kernel\Site_Health'                => 'core',
		'LearningLedger\Queue\Queue_Module'                => 'core',
		'LearningLedger\Lrs\Http\Xapi_Router'              => 'lrs',
		'LearningLedger\Lrs\Auth\Auth_Hooks'               => 'lrs',
		'LearningLedger\Lrs\Clients\Clients_Module'        => 'lrs',
		'LearningLedger\Lrs\Tokens\Tokens_Module'          => 'lrs',
		'LearningLedger\Lrs\Courses\Courses_Module'        => 'lrs',
		'LearningLedger\Lrs\Cmi5\Cmi5_Guard'               => 'lrs',
		'LearningLedger\Lrs\Export\Export_Module'          => 'lrs',
		'LearningLedger\Lrs\Notify\Notify_Module'          => 'lrs',
		'LearningLedger\Lrs\Privacy\Erase_Module'          => 'lrs',
		'LearningLedger\Lrs\Links\View_Links_Module'       => 'lrs',
		'LearningLedger\Lrs\Admin\Admin_Module'            => 'core',
		'LearningLedger\Lrs\Testing\Seed_Command'          => 'lrs',
		'LearningLedger\Badges\Issuer\Issuer_Module'       => 'lrs',
		'LearningLedger\Badges\Status\Status_List_Module'  => 'lrs',
		'LearningLedger\Badges\Pipeline\Pipeline_Module'   => 'lrs',
		'LearningLedger\Badges\Claim\Claim_Module'         => 'lrs',
		'LearningLedger\Badges\Revocation\Revocation_Module' => 'lrs',
		'LearningLedger\Badges\Delivery\Delivery_Module'   => 'lrs',
		'LearningLedger\Badges\Jwt\Jwt_Module'             => 'lrs',
		'LearningLedger\Connector\Lms\Integration_Loader'  => 'connector',
		'LearningLedger\Connector\Programs\Program_Module' => 'connector',
		'LearningLedger\Blocks\Blocks_Module'              => 'connector',
		'LearningLedger\Blocks\Lesson_Fields_Module'       => 'connector',
		'LearningLedger\Connector\Pairing\Pairing_Module'  => 'connector',
		'LearningLedger\Connector\Identity\Identity_Module' => 'connector',
		'LearningLedger\Connector\Outbox\Outbox_Module'    => 'connector',
		'LearningLedger\Connector\Inbox\Inbox_Module'      => 'connector',
		'LearningLedger\Connector\Identity\Link_Code_Module' => 'connector',
		'LearningLedger\Connector\Packages\Export_Module'  => 'connector',
		'LearningLedger\Connector\Packages\Import_Module'  => 'connector',
		'LearningLedger\Connector\CourseServer\Course_Server_Module' => 'connector',
		'LearningLedger\Player\Origin\Content_Origin'      => 'connector',
		'LearningLedger\Player\Import\Player_Import_Module' => 'connector',
		'LearningLedger\Player\Launch\Launch_Module'       => 'connector',
		'LearningLedger\Connector\Privacy\Connector_Privacy_Module' => 'connector',
		'LearningLedger\Player\Session\Session_Module'     => 'connector',
		'LearningLedger\Player\Testing\Catapult_Routes'    => 'connector',
	);

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run on this instance.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * The shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook the schema upgrade and rewrite flush, register WP-CLI commands, then register every
	 * module that exists and whose role is core or enabled. Runs once per instance.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'init', array( Rewrites::class, 'maybe_flush' ), 99 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'learning-ledger role', Role_Command::class );
		}

		$modules = (array) apply_filters( 'learning_ledger/modules', self::MODULES ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Plugin hook names use the `learning_ledger/` prefix.
		foreach ( $modules as $class => $role ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			if ( 'core' !== $role && ! Roles::is_enabled( (string) $role ) ) {
				continue;
			}
			$module = new $class();
			if ( $module instanceof Module ) {
				$module->register();
			}
		}
	}
}
