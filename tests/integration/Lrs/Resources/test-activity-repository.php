<?php
/**
 * Activity repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Resources\Activity_Repository;

class Activity_Repository_Test extends WP_UnitTestCase {

	private const IRI = 'https://example.com/act/1';

	public function set_up() {
		parent::set_up();
		Installer::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Tables::name( 'activities' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_unknown_iri_is_null() {
		$this->assertNull( ( new Activity_Repository() )->get( 'https://example.com/none' ) );
	}

	public function test_absent_row_is_inserted_even_without_define() {
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, array( 'name' => array( 'en-US' => 'One' ) ), false );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => self::IRI,
				'definition' => array( 'name' => array( 'en-US' => 'One' ) ),
			),
			$repo->get( self::IRI )
		);
	}

	public function test_present_row_unchanged_without_define() {
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, array( 'name' => array( 'en-US' => 'One' ) ), false );
		$repo->merge( self::IRI, array( 'name' => array( 'en-US' => 'Two' ) ), false );
		$this->assertSame( array( 'en-US' => 'One' ), $repo->get( self::IRI )['definition']['name'] );
	}

	public function test_define_replaces_top_level_and_merges_language_maps() {
		$repo = new Activity_Repository();
		$repo->merge(
			self::IRI,
			array(
				'name' => array( 'en-US' => 'One' ),
				'type' => 'http://adlnet.gov/expapi/activities/course',
			),
			false
		);
		$repo->merge(
			self::IRI,
			array(
				'name'        => array( 'fr' => 'Un' ),
				'description' => array( 'en-US' => 'D' ),
				'type'        => 'http://adlnet.gov/expapi/activities/lesson',
			),
			true
		);
		$def = $repo->get( self::IRI )['definition'];
		$this->assertSame( array( 'en-US' => 'One', 'fr' => 'Un' ), $def['name'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( array( 'en-US' => 'D' ), $def['description'] );
		$this->assertSame( 'http://adlnet.gov/expapi/activities/lesson', $def['type'] );
	}

	public function test_define_with_same_key_replaces_value() {
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, array( 'name' => array( 'en-US' => 'One' ) ), false );
		$repo->merge( self::IRI, array( 'name' => array( 'en-US' => 'Uno' ) ), true );
		$this->assertSame( array( 'en-US' => 'Uno' ), $repo->get( self::IRI )['definition']['name'] );
	}

	public function test_null_definition_inserts_bare_activity() {
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, null, false );
		$this->assertSame(
			array(
				'objectType' => 'Activity',
				'id'         => self::IRI,
			),
			$repo->get( self::IRI )
		);
	}

	public function test_repeated_merge_does_not_error() {
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, null, false );
		$repo->merge( self::IRI, null, false );
		$this->assertSame( '', $GLOBALS['wpdb']->last_error );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'activities' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_get_rejects_a_hash_collision() {
		global $wpdb;
		$repo = new Activity_Repository();
		$repo->merge( self::IRI, null, false );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tables::name( 'activities' ) . ' SET iri = %s', 'https://example.com/other' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNull( $repo->get( self::IRI ) );
	}
}
