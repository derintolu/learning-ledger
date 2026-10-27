<?php
/**
 * Course_Repository tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Courses\Course_Record;
use LearningLedger\Lrs\Courses\Course_Repository;
use LearningLedger\Util\Invalid_Input;

require_once __DIR__ . '/../../../fixtures/packages/class-package-fixtures.php';

class Course_Repository_Test extends WP_UnitTestCase {

	const COURSE = 'https://example.org/courses/json-basics';
	const AU     = 'https://example.org/courses/json-basics/lessons/what-is-json';
	const BLOCK  = 'https://example.org/courses/json-basics/paths/json';

	private array $fired = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'courses' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'course_aus' ) ); // phpcs:ignore WordPress.DB
		$this->fired = array();
		add_action(
			'learning_ledger/course_imported',
			function ( string $iri, int $version ) {
				$this->fired[] = array( $iri, $version );
			},
			10,
			2
		);
	}

	private static function structure( ?string $xml = null ): Course_Structure {
		return Course_Structure_Parser::parse( $xml ?? Package_Fixtures::xml() );
	}

	private static function course_json( string $award_on = 'block-satisfied' ): array {
		$json                = Package_Fixtures::inputs( 'good-full' )[1];
		$json['badges']      = array(
			array(
				'id'              => 'https://example.org/badges/json',
				'name'            => 'JSON',
				'description'     => 'Finished the JSON path.',
				'criteria'        => array( 'narrative' => 'Finish every lesson in the JSON path.' ),
				'achievementType' => 'Badge',
				'awardOn'         => $award_on,
				'for'             => 'json',
			),
		);
		$json['badgeIssuer'] = array(
			'signer'    => 'record-holder',
			'claimPage' => 'https://example.org/claim',
		);
		return $json;
	}

	public function test_import_stores_tree_badges_and_au_rows() {
		$repo   = new Course_Repository();
		$result = $repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );

		$this->assertSame(
			array(
				'course'  => self::COURSE,
				'version' => 1,
				'etag'    => '"e1"',
				'changed' => true,
				'created' => true,
			),
			$result
		);
		$record = $repo->get( self::COURSE );
		$this->assertInstanceOf( Course_Record::class, $record );
		$this->assertSame( self::COURSE, $record->course_iri );
		$this->assertSame( 1, $record->version );
		$this->assertSame( '"e1"', $record->etag );
		$this->assertSame( 'client-a', $record->owner_client );
		$this->assertFalse( $record->custom_rules );
		$this->assertSame( 'https://example.org/badges/json', $record->badges[0]['id'] );
		$this->assertSame( 'https://example.org/claim', $record->badge_issuer['claimPage'] );
		$this->assertSame( self::structure()->to_array(), $record->structure()->to_array() );
		$this->assertCount( 3, $record->structure()->aus() );

		global $wpdb;
		$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( 'course_aus' ) . ' WHERE course_iri_hash = UNHEX(%s)', bin2hex( sha1( self::COURSE, true ) ) ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( array( self::COURSE, 1 ) ), $this->fired );
		$this->assertNull( $repo->get( 'https://example.org/courses/other' ) );
	}

	public function test_same_etag_is_a_no_op_and_new_etag_bumps_version() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );

		$same = $repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );
		$this->assertFalse( $same['changed'] );
		$this->assertFalse( $same['created'] );
		$this->assertSame( 1, $same['version'] );

		$xml     = str_replace( 'Read and write JSON.', 'Read and write JSON well.', Package_Fixtures::xml() );
		$changed = $repo->import( self::structure( $xml ), self::course_json(), '"e2"', 'client-a' );
		$this->assertTrue( $changed['changed'] );
		$this->assertFalse( $changed['created'] );
		$this->assertSame( 2, $changed['version'] );
		$this->assertSame( 2, $repo->get( self::COURSE )->version );
		$this->assertSame( '"e2"', $repo->get( self::COURSE )->etag );
		$this->assertSame( array( array( self::COURSE, 1 ), array( self::COURSE, 2 ) ), $this->fired );
	}

	public function test_reimport_replaces_au_rows() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );
		$xml = str_replace( 'lessons/json-syntax"', 'lessons/json-syntax-2"', Package_Fixtures::xml() );
		$repo->import( self::structure( $xml ), self::course_json(), '"e2"', 'client-a' );

		$this->assertNull( $repo->for_au( 'https://example.org/courses/json-basics/lessons/json-syntax' ) );
		$this->assertSame( self::COURSE, $repo->for_au( 'https://example.org/courses/json-basics/lessons/json-syntax-2' )->course_iri );
	}

	public function test_other_client_may_not_reimport() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );
		try {
			$repo->import( self::structure(), self::course_json(), '"e2"', 'client-b' );
			$this->fail( 'Expected not_owner.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'not_owner', $e->error_code() );
		}
		$this->assertSame( 1, $repo->get( self::COURSE )->version );
		$this->assertSame( 'client-a', $repo->get( self::COURSE )->owner_client );
	}

	public function test_au_owned_by_another_course_is_refused() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );
		$xml = str_replace( 'course id="https://example.org/courses/json-basics"', 'course id="https://example.org/courses/copy"', Package_Fixtures::xml() );
		try {
			$repo->import( self::structure( $xml ), null, '"e9"', 'client-a' );
			$this->fail( 'Expected au_conflict.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'au_conflict', $e->error_code() );
		}
		$this->assertNull( $repo->get( 'https://example.org/courses/copy' ) );
		$this->assertSame( self::COURSE, $repo->for_au( self::AU )->course_iri );
	}

	public function test_plain_xml_has_empty_badges_and_no_issuer() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), null, '"e1"', 'client-a' );
		$record = $repo->get( self::COURSE );
		$this->assertSame( array(), $record->badges );
		$this->assertNull( $record->badge_issuer );
		$this->assertFalse( $record->custom_rules );
	}

	public function test_custom_rules_flag() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), self::course_json( 'after-review' ), '"e1"', 'client-a' );
		$this->assertTrue( $repo->get( self::COURSE )->custom_rules );
	}

	public function test_for_au_and_for_statement() {
		$repo = new Course_Repository();
		$repo->import( self::structure(), self::course_json(), '"e1"', 'client-a' );

		$this->assertSame( self::COURSE, $repo->for_au( self::AU )->course_iri );
		$this->assertNull( $repo->for_au( 'https://example.org/nope' ) );

		$from_object = $repo->for_statement(
			array(
				'object' => array(
					'objectType' => 'Activity',
					'id'         => self::AU . '/au',
				),
			)
		);
		$this->assertSame( self::COURSE, $from_object->course_iri );

		$from_grouping = $repo->for_statement(
			array(
				'object'  => array( 'id' => 'https://example.org/some/question' ),
				'context' => array(
					'contextActivities' => array(
						'grouping' => array(
							array( 'id' => 'https://example.org/unrelated' ),
							array( 'id' => self::AU ),
						),
					),
				),
			)
		);
		$this->assertSame( self::COURSE, $from_grouping->course_iri );

		$this->assertNull( $repo->for_statement( array( 'object' => array( 'id' => 'https://example.org/unrelated/au' ) ) ) );
		$this->assertNull( $repo->for_statement( array() ) );
	}
}
