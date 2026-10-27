<?php
/**
 * Connector settings option.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

/**
 * Reads and writes the `lledger_connector` option (autoload off).
 */
final class Connector_Settings {

	const OPTION = 'lledger_connector';

	const DEFAULTS = array(
		'lrs_url'           => '',
		'loopback_url'      => '',
		'api_base'          => '',
		'client_id'         => '',
		'notify_kid'        => '',
		'notify_public_key' => '',
		'allow_auto_enrol'  => false,
		'match_mbox'        => false,
		'poll_minutes'      => 15,
	);

	/**
	 * Current settings, with defaults for missing keys.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : array();
		return array_merge( self::DEFAULTS, $stored );
	}

	/**
	 * Merge changes into the stored settings. Unknown keys are ignored.
	 *
	 * @param array $changes Key => value.
	 *
	 * @return void
	 */
	public static function update( array $changes ): void {
		$settings = self::get();
		foreach ( array_intersect_key( $changes, self::DEFAULTS ) as $key => $value ) {
			$settings[ $key ] = self::normalize( $key, $value );
		}
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $settings, '', false );
			return;
		}
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * The LRS's public xAPI base (what browsers and AUs are given). Never the loopback.
	 *
	 * @return string
	 */
	public static function public_xapi_base(): string {
		return self::get()['lrs_url'];
	}

	/**
	 * Normalize one setting value.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Raw value.
	 *
	 * @return mixed
	 */
	private static function normalize( string $key, mixed $value ): mixed {
		switch ( $key ) {
			case 'lrs_url':
			case 'loopback_url':
			case 'api_base':
				$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
				return '' === $url ? '' : trailingslashit( $url );
			case 'allow_auto_enrol':
			case 'match_mbox':
				return (bool) $value;
			case 'poll_minutes':
				return max( 1, (int) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
