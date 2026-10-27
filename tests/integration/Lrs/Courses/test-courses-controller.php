<?php
/**
 * Courses_Controller tests (PUT and GET /learning-ledger/v1/courses).
 *
 * @package LearningLedger
 */

use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Courses\Course_Repository;
use LearningLedger\Lrs\Courses\Courses_Module;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Package\Secrets;
use LearningLedger\Tests\Client_Factory;

require_once __DIR__ . '/../../../fixtures/packages/class-package-fixtures.php';

class Courses_Controller_Test extends WP_UnitTestCase {

	const COURSE = 'https://example.org/courses/json-basics';
	const ROUTE  = '/learning-ledger/v1/courses';

	private array $server_backup;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'courses' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'course_aus' ) ); // phpcs:ignore WordPress.DB
		$this->server_backup = $_SERVER;
		( new Courses_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		$_SERVER                   = $this->server_backup;
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private static function basic( array $client ): string {
		return 'Basic ' . base64_encode( $client['id'] . ':' . $client['secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function zip( string $flavour = 'good-full', ?callable $tweak = null ): string {
		$in = Package_Fixtures::inputs( $flavour );
		if ( null !== $tweak ) {
			$in = $tweak( $in );
		}
		return Package_Writer::write( $in[0], $in[1], $in[2], $in[3] );
	}

	private function put( array $client, string $body, string $type = 'application/zip' ): WP_REST_Response {
		$r = new WP_REST_Request( 'PUT', self::ROUTE );
		$r->set_header( 'Authorization', self::basic( $client ) );
		$r->set_header( 'Content-Type', $type );
		$r->set_body( $body );
		return rest_get_server()->dispatch( $r );
	}

	public function test_import_zip_then_same_bytes_is_unchanged() {
		$c    = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$body = self::zip();

		$first = $this->put( $c, $body );
		$this->assertSame( 201, $first->get_status() );
		$this->assertSame(
			array(
				'course'  => self::COURSE,
				'version' => 1,
				'etag'    => Package_Writer::etag( $body ),
				'changed' => true,
			),
			$first->get_data()
		);

		$again = $this->put( $c, $body );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame(
			array(
				'course'  => self::COURSE,
				'version' => 1,
				'etag'    => Package_Writer::etag( $body ),
				'changed' => false,
			),
			$again->get_data()
		);
	}

	public function test_changed_bytes_give_version_two() {
		$c = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$this->put( $c, self::zip() );
		$changed = self::zip(
			'good-full',
			static function ( array $in ): array {
				$in[1]['version'] = '0.2.0';
				return $in;
			}
		);

		$res = $this->put( $c, $changed );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 2, $res->get_data()['version'] );
		$this->assertTrue( $res->get_data()['changed'] );
		$this->assertSame( Package_Writer::etag( $changed ), $res->get_data()['etag'] );
	}

	public function test_public_flavour_zip_with_badges_is_kept() {
		$c    = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$body = self::zip(
			'good-public',
			static function ( array $in ): array {
				$in[1]['badges']      = array(
					array(
						'id'              => 'https://example.org/badges/json',
						'name'            => 'JSON',
						'description'     => 'Finished the JSON path.',
						'criteria'        => array( 'narrative' => 'Finish every lesson in the JSON path.' ),
						'achievementType' => 'Badge',
						'awardOn'         => 'block-satisfied',
						'for'             => 'json',
					),
				);
				$in[1]['badgeIssuer'] = array(
					'signer'    => 'record-holder',
					'claimPage' => 'https://example.org/claim',
				);
				return $in;
			}
		);

		$res = $this->put( $c, $body );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$record = ( new Course_Repository() )->get( self::COURSE );
		$this->assertSame( 'https://example.org/badges/json', $record->badges[0]['id'] );
		$this->assertSame( 'record-holder', $record->badge_issuer['signer'] );
		$this->assertFalse( $record->custom_rules );
		$this->assertSame( $c['id'], $record->owner_client );
	}

	public function test_plain_xml_accepted_with_empty_badges() {
		$c   = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$xml = file_get_contents( __DIR__ . '/../../../fixtures/cmi5/simple.xml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( array( 'application/xml', 'text/xml; charset=utf-8' ) as $i => $type ) {
			$res = $this->put( $c, $xml, $type );
			$this->assertSame( 0 === $i ? 201 : 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
			$this->assertSame( Package_Writer::etag( $xml ), $res->get_data()['etag'] );
		}
		$record = ( new Course_Repository() )->get( 'https://example.org/courses/simple' );
		$this->assertSame( array(), $record->badges );
		$this->assertNull( $record->badge_issuer );
		$this->assertSame( 'https://example.org/courses/simple/au1', $record->structure()->aus()[0]->id() );
		$this->assertSame( 'https://example.org/courses/simple', ( new Course_Repository() )->for_au( 'https://example.org/courses/simple/au1' )->course_iri );
	}

	public function test_other_client_reimport_is_403() {
		$owner = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$other = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$this->put( $owner, self::zip() );

		$res = $this->put( $other, self::zip() );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( $owner['id'], ( new Course_Repository() )->get( self::COURSE )->owner_client );
	}

	public function test_without_scope_is_403_and_bad_secret_401_with_header() {
		$c   = Client_Factory::create( array( Scopes::STATEMENTS_WRITE ) );
		$res = $this->put( $c, self::zip() );
		$this->assertSame( 403, $res->get_status() );
		$this->assertNull( ( new Course_Repository() )->get( self::COURSE ) );

		$get = new WP_REST_Request( 'GET', self::ROUTE );
		$get->set_header( 'Authorization', self::basic( $c ) );
		$get->set_query_params( array( 'course' => self::COURSE ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $get )->get_status() );

		$c['secret'] = 'wrong';
		$res         = $this->put( $c, self::zip() );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'Basic realm="Learning Ledger"', $res->get_headers()['WWW-Authenticate'] );
	}

	public function test_invalid_package_is_422_with_errors() {
		$c   = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$res = $this->put( $c, self::zip( 'bad-xsd' ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'xsd', $res->get_data()['data']['errors'][0]['code'] );

		$res = $this->put( $c, 'not a zip' );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'zip_invalid', $res->get_data()['data']['errors'][0]['code'] );

		$res = $this->put( $c, '<courseStructure/>', 'application/xml' );
		$this->assertSame( 422, $res->get_status() );

		$res = $this->put( $c, '{}', 'application/json' );
		$this->assertSame( 415, $res->get_status() );
		$this->assertNull( ( new Course_Repository() )->get( self::COURSE ) );
	}

	public function test_no_lesson_json_or_secrets_stored() {
		$c = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$this->assertSame( 201, $this->put( $c, self::zip() )->get_status() );

		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . Tables::name( 'courses' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $row );
		$all = implode( "\n", $row );
		$this->assertStringNotContainsString( 'rubric', $all );
		$this->assertStringNotContainsString( 'Full marks if the learner', $all );
		$this->assertStringNotContainsString( 'placementHints', $all );
		foreach ( Secrets::FIELDS as $pointer ) {
			$this->assertStringNotContainsString( '"' . basename( $pointer ) . '"', $all );
		}
	}

	public function test_get_returns_version_and_etag() {
		$c    = Client_Factory::create( array( Scopes::COURSES_WRITE ) );
		$body = self::zip();
		$this->put( $c, $body );

		$r = new WP_REST_Request( 'GET', self::ROUTE );
		$r->set_header( 'Authorization', self::basic( $c ) );
		$r->set_query_params( array( 'course' => self::COURSE ) );
		$res = rest_get_server()->dispatch( $r );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			array(
				'course'  => self::COURSE,
				'version' => 1,
				'etag'    => Package_Writer::etag( $body ),
			),
			$res->get_data()
		);

		$r->set_query_params( array( 'course' => 'https://example.org/courses/unknown' ) );
		$this->assertSame( 404, rest_get_server()->dispatch( $r )->get_status() );
	}
}
