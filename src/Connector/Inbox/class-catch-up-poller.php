<?php
/**
 * Catch-up poll against the LRS.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Inbox;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the cursor table is plugin-owned.

use LearningLedger\Connector\Pairing\Connector_Settings;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Timestamp;

/**
 * Pulls `GET statements?since=<cursor>&limit=500` through `more` into the inbox.
 *
 * Plain xAPI, so it works against any conformant LRS. The webhook and the poll feed the same
 * inbox, de-duplicated by statement id.
 */
final class Catch_Up_Poller {

	const CURSOR   = 'poll_since';
	const RECOVERY = 'poll_recovery';
	const LIMIT    = 500;

	/**
	 * Most pages read in one run, so a long backlog spreads over several runs.
	 */
	const MAX_PAGES = 50;

	/**
	 * LRS client.
	 *
	 * @var Lrs_Client
	 */
	private Lrs_Client $client;

	/**
	 * Inbox.
	 *
	 * @var Inbox_Repository
	 */
	private Inbox_Repository $inbox;

	/**
	 * Constructor.
	 *
	 * @param Lrs_Client|null       $client LRS client.
	 * @param Inbox_Repository|null $inbox  Inbox.
	 */
	public function __construct( ?Lrs_Client $client = null, ?Inbox_Repository $inbox = null ) {
		$this->client = $client ?? new Lrs_Client();
		$this->inbox  = $inbox ?? new Inbox_Repository();
	}

	/**
	 * Run one poll.
	 *
	 * @return integer New inbox rows.
	 */
	public function run(): int {
		$settings = Connector_Settings::get();
		if ( '' === $settings['lrs_url'] || '' === $settings['client_id'] ) {
			return 0;
		}

		$since = self::get( self::CURSOR );
		$query = array( 'limit' => self::LIMIT );
		if ( null !== $since ) {
			$query = array(
				'since' => $since,
				'limit' => self::LIMIT,
			);
		}
		// Ascending, so the cursor can advance page by page.
		$query['ascending'] = 'true';

		$resource = 'statements';
		$added    = 0;
		$newest   = null === $since ? null : self::parse( $since );
		$ok       = true;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			try {
				$res = $this->client->xapi( 'GET', $resource, $query );
			} catch ( \RuntimeException $e ) {
				$ok = false;
				break;
			}
			$data = 200 === $res->status ? $res->json() : null;
			if ( ! is_array( $data ) || ! isset( $data['statements'] ) || ! is_array( $data['statements'] ) ) {
				$ok = false;
				break;
			}

			foreach ( $data['statements'] as $statement ) {
				if ( ! is_array( $statement ) ) {
					continue;
				}
				if ( $this->inbox->add( $statement, 'poll' ) ) {
					++$added;
				}
				$stored = is_string( $statement['stored'] ?? null ) ? self::parse( $statement['stored'] ) : null;
				if ( $stored && ( null === $newest || $stored > $newest ) ) {
					$newest = $stored;
				}
			}
			if ( $newest ) {
				self::set( self::CURSOR, Timestamp::to_xapi( $newest ) );
			}

			$next = self::follow( is_string( $data['more'] ?? null ) ? $data['more'] : '', $settings );
			if ( null === $next ) {
				break;
			}
			list( $resource, $query ) = $next;
		}

		if ( $ok ) {
			self::delete( self::RECOVERY );
		} else {
			self::set( self::RECOVERY, '1' );
		}
		return $added;
	}

	/**
	 * Whether the last poll failed, so the next working delivery should trigger a poll.
	 *
	 * @return boolean
	 */
	public function recovery_needed(): bool {
		return null !== self::get( self::RECOVERY );
	}

	/**
	 * Mark that a poll is owed once the LRS is reachable again.
	 *
	 * @return void
	 */
	public function need_recovery(): void {
		self::set( self::RECOVERY, '1' );
	}

	/**
	 * Resource and query for a `more` IRL, or null when it is empty or leaves the LRS.
	 *
	 * @param string $more     The `more` value.
	 * @param array  $settings Connector settings.
	 *
	 * @return array{0:string,1:array}|null
	 */
	private static function follow( string $more, array $settings ): ?array {
		if ( '' === $more ) {
			return null;
		}
		$base  = '' !== $settings['loopback_url'] ? $settings['loopback_url'] : $settings['lrs_url'];
		$parts = wp_parse_url( $more );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$path = (string) ( $parts['path'] ?? '' );

		if ( isset( $parts['host'] ) || isset( $parts['scheme'] ) ) {
			// An absolute IRL must point at the LRS this connector is paired with.
			$allowed = false;
			foreach ( array( $settings['lrs_url'], $settings['loopback_url'] ) as $candidate ) {
				if ( '' !== $candidate && self::origin( $candidate ) === self::origin( $more ) ) {
					$allowed = true;
				}
			}
			if ( ! $allowed ) {
				return null;
			}
		}

		if ( '' !== $path && '/' === $path[0] ) {
			// Relative to the server root: strip the xAPI base path (public or loopback).
			$resource = null;
			foreach ( array( $base, $settings['lrs_url'] ) as $candidate ) {
				$prefix = trailingslashit( (string) wp_parse_url( $candidate, PHP_URL_PATH ) );
				if ( 0 === strpos( $path, $prefix ) ) {
					$resource = substr( $path, strlen( $prefix ) );
					break;
				}
			}
			if ( null === $resource ) {
				return null;
			}
		} else {
			$resource = $path;
		}

		if ( '' === $resource || false !== strpos( $resource, '..' ) ) {
			return null;
		}
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		return array( $resource, $query );
	}

	/**
	 * Scheme, host and port of a URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function origin( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || ! isset( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
		$port   = $p['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $p['host'] ) . ':' . $port;
	}

	/**
	 * Parse an xAPI timestamp, or null.
	 *
	 * @param string $value Timestamp.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private static function parse( string $value ): ?\DateTimeImmutable {
		try {
			return Timestamp::parse( $value, '1.0.3' );
		} catch ( Invalid_Input $e ) {
			return null;
		}
	}

	/**
	 * Read a cursor value.
	 *
	 * @param string $name Name.
	 *
	 * @return string|null
	 */
	private static function get( string $name ): ?string {
		global $wpdb;
		$table = Tables::name( 'conn_cursor' );
		$v     = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM `{$table}` WHERE name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $v ? null : (string) $v;
	}

	/**
	 * Write a cursor value.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 *
	 * @return void
	 */
	private static function set( string $name, string $value ): void {
		global $wpdb;
		$wpdb->replace(
			Tables::name( 'conn_cursor' ),
			array(
				'name'  => $name,
				'value' => $value,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Remove a cursor value.
	 *
	 * @param string $name Name.
	 *
	 * @return void
	 */
	private static function delete( string $name ): void {
		global $wpdb;
		$wpdb->delete( Tables::name( 'conn_cursor' ), array( 'name' => $name ), array( '%s' ) );
	}
}
