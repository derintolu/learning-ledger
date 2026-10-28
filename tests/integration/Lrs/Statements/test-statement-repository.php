<?php
/**
 * Statement repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Statements\Statement_Repository;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Statement_Repository_Test extends WP_UnitTestCase {

	/**
	 * Active failure-injection query filter.
	 *
	 * @var Closure|null
	 */
	private ?Closure $failer = null;

	public function set_up() {
		parent::set_up();
		Installer::install();
	}

	public function tear_down() {
		if ( null !== $this->failer ) {
			remove_filter( 'query', $this->failer );
			$this->failer = null;
		}
		$GLOBALS['wpdb']->suppress_errors( false );
		parent::tear_down();
	}

	public function delete_queries(): array {
		return array(
			'refs'          => array( 'DELETE r FROM statement_refs' ),
			'pending voids' => array( 'DELETE v FROM pending_voids' ),
			'statements'    => array( 'DELETE FROM statements' ),
		);
	}

	/**
	 * @dataProvider delete_queries
	 *
	 * @param string $prefix Query start, with the table's short name.
	 */
	public function test_delete_for_actor_throws_when_a_delete_fails( string $prefix ) {
		[ $verb, $table ] = explode( ' FROM ', $prefix );
		$failing          = $verb . ' FROM ' . Tables::name( $table );
		$GLOBALS['wpdb']->suppress_errors( true );
		$this->failer = static function ( string $q ) use ( $failing ) {
			return str_starts_with( $q, $failing ) ? 'DELETE FROM lledger_no_such_table_fx3' : $q;
		};
		add_filter( 'query', $this->failer );

		$this->expectException( RuntimeException::class );
		( new Statement_Repository() )->delete_for_actor( sha1( 'actor', true ) );
	}

	public function test_delete_for_actor_returns_zero_when_nothing_matches() {
		$this->assertSame( 0, ( new Statement_Repository() )->delete_for_actor( sha1( 'nobody', true ) ) );
	}
}
