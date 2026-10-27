<?php
/**
 * `wp learning-ledger connector connect|set`.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Pairing;

use LearningLedger\Util\Invalid_Input;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to WP-CLI only, never into HTML.

/**
 * Pairs the connector with an LRS and changes connector settings. Prints no secret.
 */
final class Pairing_Command {

	const EXTENSION = 'https://github.com/derintolu/learning-ledger';

	const SETTINGS = array(
		'allow-auto-enrol' => 'allow_auto_enrol',
		'match-mbox'       => 'match_mbox',
		'poll-minutes'     => 'poll_minutes',
		'loopback-url'     => 'loopback_url',
	);

	/**
	 * Pair with an LRS: pins its notification key and generates this site's secret.
	 *
	 * The LRS admin then registers this connector with the secret SHA-256 read
	 * from the authenticated status route.
	 *
	 * ## OPTIONS
	 *
	 * --lrs=<url>
	 * : The LRS's public xAPI base URL.
	 *
	 * --client=<id>
	 * : The client id the LRS admin will register for this connector.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function connect( array $args, array $assoc_args ): void {
		try {
			self::pair( (string) ( $assoc_args['lrs'] ?? '' ), (string) ( $assoc_args['client'] ?? '' ) );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Paired. Read the secret SHA-256 from the status route and register it on the LRS.' );
	}

	/**
	 * Change a connector setting.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Setting name.
	 * ---
	 * options:
	 *   - allow-auto-enrol
	 *   - match-mbox
	 *   - poll-minutes
	 *   - loopback-url
	 * ---
	 *
	 * <value>
	 * : New value: true/false for flags, minutes for poll-minutes, a URL (or "" to clear) for loopback-url.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function set( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		try {
			self::apply_setting( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( 'Setting saved.' );
	}

	/**
	 * Fetch `<lrs>about`, pin the notification key, generate the secret if absent and store settings.
	 *
	 * @param string $lrs    Public xAPI base.
	 * @param string $client Client id.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input On bad arguments or an unusable `/about`.
	 * @throws \RuntimeException When the LRS cannot be reached.
	 */
	public static function pair( string $lrs, string $client ): void {
		$lrs    = self::http_url( $lrs, 'lrs' );
		$client = trim( $client );
		if ( '' === $client || sanitize_text_field( $client ) !== $client || strlen( $client ) > 191 || str_contains( $client, ':' ) ) {
			throw new Invalid_Input( 'The client id is missing or invalid.', 'invalid', 'client' );
		}

		$res = Lrs_Client::to_response(
			wp_remote_get(
				$lrs . 'about',
				array(
					'headers'     => array( 'X-Experience-API-Version' => Lrs_Client::XAPI_VERSION ),
					'timeout'     => Lrs_Client::TIMEOUT,
					'redirection' => 0,
				)
			)
		);
		if ( 0 === $res->status ) {
			throw new \RuntimeException( 'The LRS could not be reached.' );
		}
		if ( 200 !== $res->status ) {
			throw new \RuntimeException( sprintf( 'The LRS answered /about with HTTP %d.', $res->status ) );
		}

		$about = $res->json();
		$ext   = is_array( $about ) && isset( $about['extensions'][ self::EXTENSION ] ) && is_array( $about['extensions'][ self::EXTENSION ] )
			? $about['extensions'][ self::EXTENSION ]
			: null;
		if ( null === $ext ) {
			throw new Invalid_Input( 'The LRS /about has no Learning Ledger extension.', 'invalid', 'about' );
		}

		$api_base = self::http_url( is_string( $ext['api_base'] ?? null ) ? $ext['api_base'] : '', 'api_base' );
		$kid      = $ext['notifications']['kid'] ?? null;
		$public   = $ext['notifications']['public_key'] ?? null;
		if ( ! is_string( $kid ) || '' === $kid || sanitize_text_field( $kid ) !== $kid ) {
			throw new Invalid_Input( 'The LRS /about has no notification key id.', 'invalid', 'notifications.kid' );
		}
		$raw = is_string( $public ) ? base64_decode( $public, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key transport encoding.
		if ( false === $raw || 32 !== strlen( $raw ) ) {
			throw new Invalid_Input( 'The LRS /about has no valid notification public key.', 'invalid', 'notifications.public_key' );
		}

		Connector_Secret::generate();
		Connector_Keys::key_id();
		Connector_Settings::update(
			array(
				'lrs_url'           => $lrs,
				'client_id'         => $client,
				'api_base'          => $api_base,
				'notify_kid'        => $kid,
				'notify_public_key' => base64_encode( $raw ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- public key transport encoding.
			)
		);
	}

	/**
	 * Apply `connector set <name> <value>`.
	 *
	 * @param string $name  CLI setting name.
	 * @param string $value Raw value.
	 *
	 * @return void
	 *
	 * @throws Invalid_Input On an unknown setting or a bad value.
	 */
	public static function apply_setting( string $name, string $value ): void {
		if ( ! isset( self::SETTINGS[ $name ] ) ) {
			throw new Invalid_Input( 'Unknown connector setting.', 'invalid', 'name' );
		}
		switch ( $name ) {
			case 'allow-auto-enrol':
			case 'match-mbox':
				$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $parsed ) {
					throw new Invalid_Input( 'Expected true or false.', 'invalid', 'value' );
				}
				break;
			case 'poll-minutes':
				if ( 1 !== preg_match( '/^[1-9][0-9]{0,4}$/', $value ) ) {
					throw new Invalid_Input( 'Expected a whole number of minutes.', 'invalid', 'value' );
				}
				$parsed = (int) $value;
				break;
			default:
				$parsed = '' === trim( $value ) ? '' : self::http_url( $value, 'value' );
		}
		Connector_Settings::update( array( self::SETTINGS[ $name ] => $parsed ) );
	}

	/**
	 * Validate an http(s) URL and add a trailing slash.
	 *
	 * @param string $url  URL.
	 * @param string $path Argument name for the error.
	 *
	 * @return string
	 *
	 * @throws Invalid_Input When it is not an http(s) URL.
	 */
	private static function http_url( string $url, string $path ): string {
		$url    = trim( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			throw new Invalid_Input( 'Expected an http or https URL.', 'invalid', $path );
		}
		return trailingslashit( $url );
	}
}
