<?php
/**
 * Loads LMS integrations when the connector role is on.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

use LearningLedger\Kernel\Module;

/**
 * Evaluates integrations/<id>/bootstrap.php on plugins_loaded priority 20.
 */
final class Integration_Loader implements Module {

	const IDS = array( 'sensei', 'lifterlms', 'tutor', 'learnpress' );

	/**
	 * Loaded adapters by id (guarded).
	 *
	 * @var Guarded_Adapter[]
	 */
	private static array $adapters = array();

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'load' ), 20 );
		Integration_State::hook();
	}

	/**
	 * Load available integrations.
	 *
	 * @return void
	 */
	public function load(): void {
		self::$adapters = array();
		$roles          = get_option( 'lledger_roles', array() );
		$roles          = is_array( $roles ) ? $roles : array();
		if ( ! in_array( 'connector', $roles, true ) && empty( $roles['connector'] ) ) {
			return;
		}

		$default = LEARNING_LEDGER_DIR . 'integrations';
		$dirs    = (array) apply_filters( 'learning_ledger/integration_dirs', array( $default ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$sink    = apply_filters( 'learning_ledger/event_sink', new Noop_Event_Sink() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( ! $sink instanceof Event_Sink ) {
			$sink = new Noop_Event_Sink();
		}

		foreach ( $dirs as $dir ) {
			$dir = untrailingslashit( (string) $dir );
			// The shipped folder is limited to the known ids; test folders may hold any.
			$ids = untrailingslashit( $default ) === $dir ? self::IDS : array_map( 'basename', (array) glob( $dir . '/*', GLOB_ONLYDIR ) );
			foreach ( $ids as $id ) {
				$file = $dir . '/' . $id . '/bootstrap.php';
				if ( ! is_readable( $file ) ) {
					continue;
				}
				$d = self::descriptor( $file );
				if ( ! $d || isset( self::$adapters[ $d->id ] ) ) {
					continue;
				}
				try {
					if ( ! call_user_func( $d->is_available ) ) {
						continue;
					}
					Integration_State::gate( $d );
					$adapter = call_user_func( $d->factory );
				} catch ( \Throwable $t ) {
					Integration_State::pause( $d->id, get_class( $t ) . ': ' . $t->getMessage() );
					continue;
				}
				if ( ! $adapter instanceof LMS_Adapter ) {
					continue;
				}
				$guarded = new Guarded_Adapter( $adapter );
				$guarded->register_event_listeners( $sink );
				self::$adapters[ $d->id ] = $guarded;
			}
		}
	}

	/**
	 * Adapter for an integration id, whether active or paused.
	 *
	 * @param string $id Integration id.
	 *
	 * @return LMS_Adapter|null
	 */
	public static function adapter( string $id ): ?LMS_Adapter {
		return self::$adapters[ $id ] ?? null;
	}

	/**
	 * Adapters that are not paused.
	 *
	 * @return LMS_Adapter[]
	 */
	public static function active(): array {
		return array_filter(
			self::$adapters,
			static fn( Guarded_Adapter $a ): bool => Integration_State::ACTIVE === Integration_State::status( $a->id() )
		);
	}

	/**
	 * First active adapter that handles the post's type.
	 *
	 * @param integer $post_id Post ID.
	 *
	 * @return LMS_Adapter|null
	 */
	public static function for_post( int $post_id ): ?LMS_Adapter {
		$type = get_post_type( $post_id );
		if ( ! $type ) {
			return null;
		}
		$map = (array) apply_filters( 'learning_ledger/adapter_post_types', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		foreach ( self::active() as $id => $adapter ) {
			if ( in_array( $type, (array) ( $map[ $id ] ?? array() ), true ) ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Evaluate a bootstrap file.
	 *
	 * @param string $file Path.
	 *
	 * @return Integration_Descriptor|null
	 */
	private static function descriptor( string $file ): ?Integration_Descriptor {
		$d = require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		return $d instanceof Integration_Descriptor ? $d : null;
	}
}
