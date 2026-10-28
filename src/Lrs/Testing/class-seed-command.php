<?php
/**
 * Load-test seeding.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Testing;

use LearningLedger\Kernel\Module;
use LearningLedger\Lrs\Auth\Client_Repository;
use LearningLedger\Lrs\Auth\Client_Spec;
use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Statements\Statement_Store;
use LearningLedger\Util\Uuid;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages go to WP-CLI only, never into HTML.

/**
 * `wp learning-ledger dev seed`: bulk statements for load tests, stored through Statement_Store
 * in batches of 500. Registers nothing unless `LLEDGER_TESTING` is true.
 *
 * The data is a function of the statement's index, so load scripts can rebuild the same actors,
 * registrations and activities: registration r belongs to actor r % actors; statement i is in
 * registration i % registrations, on a lesson of course r % COURSES, with the course as context
 * parent. Every 200th statement voids the one before it.
 */
final class Seed_Command implements Module {

	/**
	 * Statements per Statement_Store::store() call (the xAPI batch cap).
	 */
	public const BATCH = 500;

	/**
	 * Courses, and lessons per course.
	 */
	public const COURSES = 50;

	public const LESSONS = 20;

	/**
	 * Every VOID_EVERY-th statement voids the previous one.
	 */
	public const VOID_EVERY = 200;

	/**
	 * Base of the seeded IRIs and the actors' account homePage.
	 */
	public const BASE = 'https://seed.example';

	/**
	 * Client the seed writes as (statements/write and void; its secret is random and never kept).
	 */
	public const CLIENT = 'lledger-seed';

	/**
	 * Verbs cycled through.
	 *
	 * @var string[]
	 */
	private const VERBS = array( 'launched', 'initialized', 'experienced', 'completed', 'passed', 'terminated' );

	/**
	 * Whether seeding is enabled (`LLEDGER_TESTING` true).
	 *
	 * @return boolean
	 */
	public static function enabled(): bool {
		return defined( 'LLEDGER_TESTING' ) && LLEDGER_TESTING;
	}

	/**
	 * Register the WP-CLI command when testing.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger dev seed', array( $this, 'command' ) );
		}
	}

	/**
	 * Store generated statements for load testing.
	 *
	 * ## OPTIONS
	 *
	 * --statements=<n>
	 * : Statements to store.
	 *
	 * [--actors=<n>]
	 * : Distinct actors.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * [--registrations=<n>]
	 * : Distinct registrations.
	 * ---
	 * default: 10000
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function command( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$started = microtime( true );
		try {
			$stored = $this->seed(
				(int) ( $assoc_args['statements'] ?? 0 ),
				(int) ( $assoc_args['actors'] ?? 1000 ),
				(int) ( $assoc_args['registrations'] ?? 10000 ),
				static function ( int $done, int $total ) use ( $started ): void {
					$elapsed = microtime( true ) - $started;
					\WP_CLI::log( sprintf( '%d/%d statements, %.1f s, %d/s', $done, $total, $elapsed, (int) ( $done / max( $elapsed, 0.001 ) ) ) );
				}
			);
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		$elapsed = microtime( true ) - $started;
		\WP_CLI::success( sprintf( 'Stored %d statements in %.1f s (%d/s).', $stored, $elapsed, (int) ( $stored / max( $elapsed, 0.001 ) ) ) );
	}

	/**
	 * Store `$n` generated statements as the seed client.
	 *
	 * @param integer       $n             Statements.
	 * @param integer       $actors        Distinct actors.
	 * @param integer       $registrations Distinct registrations.
	 * @param callable|null $progress      Called with (done, total) every 20 batches and at the end.
	 *
	 * @return integer Statements stored.
	 * @throws \InvalidArgumentException When a count is below 1.
	 */
	public function seed( int $n, int $actors, int $registrations, ?callable $progress = null ): int {
		if ( $n < 1 || $actors < 1 || $registrations < 1 ) {
			throw new \InvalidArgumentException( '--statements, --actors and --registrations must be at least 1.' );
		}
		$store   = new Statement_Store();
		$writer  = self::writer();
		$batches = 0;
		for ( $start = 0; $start < $n; $start += self::BATCH ) {
			$batch = array();
			$ids   = array();
			$end   = min( $n, $start + self::BATCH );
			for ( $i = $start; $i < $end; $i++ ) {
				if ( 0 === ( $i + 1 ) % self::VOID_EVERY && isset( $ids[ $i - 1 ] ) ) {
					$batch[] = self::voiding( $ids[ $i - 1 ], self::actor( ( ( $i - 1 ) % $registrations ) % $actors ) );
					continue;
				}
				$st = self::statement( $i, $actors, $registrations );
				if ( 0 === ( $i + 2 ) % self::VOID_EVERY && $i + 1 < $end ) {
					$st['id']  = Uuid::v4();
					$ids[ $i ] = $st['id'];
				}
				$batch[] = $st;
			}
			$store->store( $batch, $writer, '2.0.0' );
			++$batches;
			if ( null !== $progress && ( 0 === $batches % 20 || $end === $n ) ) {
				$progress( $end, $n );
			}
		}
		return $n;
	}

	/**
	 * The seed client's principal, creating the client on first use.
	 *
	 * @return Principal
	 */
	private static function writer(): Principal {
		$clients = new Client_Repository();
		$client  = $clients->find( self::CLIENT );
		if ( null === $client ) {
			$clients->create( new Client_Spec( self::CLIENT, 'Load-test seed', hash( 'sha256', random_bytes( 32 ), true ), array( Scopes::STATEMENTS_WRITE, Scopes::VOID ), array(), array(), false, 'unlimited' ) );
			$client = $clients->find( self::CLIENT );
		}
		return Principal::for_client( $client );
	}

	/**
	 * Statement i.
	 *
	 * @param integer $i             Index.
	 * @param integer $actors        Distinct actors.
	 * @param integer $registrations Distinct registrations.
	 *
	 * @return array
	 */
	private static function statement( int $i, int $actors, int $registrations ): array {
		$r      = $i % $registrations;
		$course = $r % self::COURSES;
		$verb   = self::VERBS[ intdiv( $i, $registrations ) % count( self::VERBS ) ];
		return array(
			'actor'   => self::actor( $r % $actors ),
			'verb'    => array(
				'id'      => 'http://adlnet.gov/expapi/verbs/' . $verb,
				'display' => array( 'en-US' => $verb ),
			),
			'object'  => array(
				'objectType' => 'Activity',
				'id'         => self::lesson( $course, intdiv( $i, $registrations ) % self::LESSONS ),
			),
			'result'  => array(
				'completion' => true,
				'success'    => 0 !== $i % 3,
				'duration'   => 'PT' . ( 30 + $i % 900 ) . 'S',
				'score'      => array( 'scaled' => ( $i % 101 ) / 100 ),
			),
			'context' => array(
				'registration'      => self::registration( $r ),
				'contextActivities' => array(
					'parent'   => array( array( 'id' => self::course( $course ) ) ),
					'category' => array( array( 'id' => 'https://w3id.org/xapi/cmi5/context/categories/moveon' ) ),
				),
				'extensions'        => array( 'https://w3id.org/xapi/cmi5/context/extensions/sessionid' => 'seed-session-' . $r ),
			),
		);
	}

	/**
	 * A statement voiding another.
	 *
	 * @param string $target Statement id.
	 * @param array  $actor  Actor.
	 *
	 * @return array
	 */
	private static function voiding( string $target, array $actor ): array {
		return array(
			'actor'  => $actor,
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/voided' ),
			'object' => array(
				'objectType' => 'StatementRef',
				'id'         => $target,
			),
		);
	}

	/**
	 * Actor a: an account on BASE named `learner-<a>`.
	 *
	 * @param integer $a Index.
	 *
	 * @return array
	 */
	public static function actor( int $a ): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => self::BASE,
				'name'     => 'learner-' . $a,
			),
		);
	}

	/**
	 * Registration r: `5eed0000-0000-4000-8000-` followed by r as 12 hex digits.
	 *
	 * @param integer $r Index.
	 *
	 * @return string
	 */
	public static function registration( int $r ): string {
		return sprintf( '5eed0000-0000-4000-8000-%012x', $r );
	}

	/**
	 * Course c's IRI.
	 *
	 * @param integer $c Index.
	 *
	 * @return string
	 */
	public static function course( int $c ): string {
		return self::BASE . '/course/' . $c;
	}

	/**
	 * Lesson l of course c.
	 *
	 * @param integer $c Course index.
	 * @param integer $l Lesson index.
	 *
	 * @return string
	 */
	public static function lesson( int $c, int $l ): string {
		return self::course( $c ) . '/lesson/' . $l;
	}
}
