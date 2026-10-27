<?php
/**
 * Per-integration pause state and LMS version gate.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Lms;

/**
 * Stored in option lledger_integration_state (autoload off). Entries are
 * id => array{status, reason, confirmed_major, notice}.
 */
final class Integration_State {

	const OPTION = 'lledger_integration_state';
	const ACTIVE = 'active';
	const PAUSED = 'paused';
	const MAJOR  = 'major_version';
	const CAP    = 'lledger_manage_connector';

	/**
	 * Current status: active or paused.
	 *
	 * @param string $id Integration id.
	 *
	 * @return string
	 */
	public static function status( string $id ): string {
		$all = self::all();
		return ( $all[ $id ]['status'] ?? self::ACTIVE ) === self::PAUSED ? self::PAUSED : self::ACTIVE;
	}

	/**
	 * Pause an integration.
	 *
	 * @param string $id     Integration id.
	 * @param string $reason Short reason shown in the notice.
	 *
	 * @return void
	 */
	public static function pause( string $id, string $reason ): void {
		self::update(
			$id,
			array(
				'status' => self::PAUSED,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Resume an integration.
	 *
	 * @param string $id Integration id.
	 *
	 * @return void
	 */
	public static function resume( string $id ): void {
		self::update(
			$id,
			array(
				'status' => self::ACTIVE,
				'reason' => '',
			)
		);
	}

	/**
	 * Accept a newer major LMS version.
	 *
	 * @param string $id      Integration id.
	 * @param string $version Version confirmed.
	 *
	 * @return void
	 */
	public static function confirm_major( string $id, string $version ): void {
		self::update( $id, array( 'confirmed_major' => self::major( $version ) ) );
	}

	/**
	 * Apply the version policy and return the resulting status. A newer
	 * major than tested_up_to pauses until confirmed. A newer minor runs and
	 * leaves a notice.
	 *
	 * @param Integration_Descriptor $d Descriptor.
	 *
	 * @return string active or paused.
	 */
	public static function gate( Integration_Descriptor $d ): string {
		$installed = (string) call_user_func( $d->version );
		$tested    = explode( '.', $d->tested_up_to );
		$have      = explode( '.', $installed );
		$entry     = self::all()[ $d->id ] ?? array();

		$newer_major = (int) $have[0] > (int) $tested[0];
		$newer_minor = ! $newer_major && (int) $have[0] === (int) $tested[0] && (int) ( $have[1] ?? 0 ) > (int) ( $tested[1] ?? 0 );

		if ( $newer_major && (int) ( $entry['confirmed_major'] ?? 0 ) < (int) $have[0] ) {
			self::update(
				$d->id,
				array(
					'status' => self::PAUSED,
					'reason' => self::MAJOR,
					'notice' => sprintf( '%s %s is a newer major version than the tested %s.', $d->id, $installed, $d->tested_up_to ),
				)
			);
			return self::PAUSED;
		}

		$update = array( 'notice' => $newer_minor || $newer_major ? sprintf( '%s %s is newer than the tested %s.', $d->id, $installed, $d->tested_up_to ) : '' );
		if ( self::MAJOR === ( $entry['reason'] ?? '' ) && self::PAUSED === ( $entry['status'] ?? '' ) ) {
			$update['status'] = self::ACTIVE;
			$update['reason'] = '';
		}
		self::update( $d->id, $update );
		return self::status( $d->id );
	}

	/**
	 * Print notices for paused integrations and newer LMS versions.
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( self::all() as $id => $entry ) {
			if ( self::PAUSED === ( $entry['status'] ?? '' ) ) {
				$detail = (string) ( $entry['reason'] ?? '' );
				if ( self::MAJOR === $detail && ! empty( $entry['notice'] ) ) {
					$detail = (string) $entry['notice'];
				}
				$text = sprintf(
					/* translators: 1: integration id, 2: reason or notice. */
					__( 'Learning Ledger paused the %1$s integration: %2$s. Events keep queueing; nothing is applied.', 'learning-ledger' ),
					$id,
					$detail
				);
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $text ) );
			} elseif ( '' !== ( $entry['notice'] ?? '' ) ) {
				printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( (string) $entry['notice'] ) );
			}
		}
	}

	/**
	 * Attach the notice renderer (idempotent).
	 *
	 * @return void
	 */
	public static function hook(): void {
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
	}

	/**
	 * Major component of a version string.
	 *
	 * @param string $version Version.
	 *
	 * @return integer
	 */
	private static function major( string $version ): int {
		return (int) explode( '.', $version )[0];
	}

	/**
	 * Whole state.
	 *
	 * @return array
	 */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Merge fields into one entry and save.
	 *
	 * @param string $id     Integration id.
	 * @param array  $fields Fields.
	 *
	 * @return void
	 */
	private static function update( string $id, array $fields ): void {
		$all        = self::all();
		$all[ $id ] = array_merge( $all[ $id ] ?? array(), $fields );
		update_option( self::OPTION, $all, false );
		self::hook();
	}
}
