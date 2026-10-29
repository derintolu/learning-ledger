<?php
/**
 * Package round trip: import an authoring package, export it, get it back.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Packages\Cmi5_Xml_Builder;
use LearningLedger\Connector\Packages\Package_Builder;
use LearningLedger\Connector\Packages\Package_Importer;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties are camelCase.

/**
 * The fixture is the course server's own authoring package (tests/fixtures/round-trip/SOURCE).
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Package_Round_Trip_Test extends WP_UnitTestCase {

	const FIXTURE = LEARNING_LEDGER_DIR . 'tests/fixtures/round-trip/learn-wp-reimagined-authoring.zip';

	private array $temp = array();

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		update_option( 'lledger_roles', array( 'connector' ) );
		delete_option( 'lledger_integration_state' );
		( new Integration_Loader() )->load();
		// Remote WP-CLI and signed course-server requests run as user 0, which kses filters.
		wp_set_current_user( 0 );
		kses_init_filters();
	}

	public function tear_down(): void {
		foreach ( $this->temp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			}
		}
		kses_remove_filters();
		parent::tear_down();
	}

	private function round_trip( bool $trusted ): Package {
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		$report = ( new Package_Importer() )->import( Package::from_zip( self::FIXTURE, new Limits() ), 'sensei', $trusted );
		$this->assertTrue( $report->ok(), (string) wp_json_encode( $report->to_array() ) );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses is on again after the import' );
		$built        = ( new Package_Builder() )->build( $report->program_id, 'full' );
		$path         = (string) wp_tempnam( 'llrt' );
		$this->temp[] = $path;
		file_put_contents( $path, $built->zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		return Package::from_zip( $path, new Limits() );
	}

	/**
	 * Every difference between the fixture and an export: JSON compared semantically (object keys
	 * unordered, {} and [] told apart), cmi5.xml by ids, titles, descriptions, langstring languages,
	 * moveOn, launchMethod and cxm attributes.
	 *
	 * Left out of the cmi5.xml comparison, on purpose: each AU's `<url>`, which comes from the
	 * program's AU URL template, and the lesson AUs' `activityType`, an optional cmi5 attribute
	 * the exporter always sets to the xAPI lesson type (the fixture has none).
	 *
	 * @param Package $export Exported package.
	 *
	 * @return string[]
	 */
	private static function differences( Package $export ): array {
		$source = Package::from_zip( self::FIXTURE, new Limits() );
		$out    = array();
		$names  = array_values( array_unique( array_merge( $source->entries(), $export->entries() ) ) );
		sort( $names );
		foreach ( $names as $name ) {
			if ( ! in_array( $name, $source->entries(), true ) || ! in_array( $name, $export->entries(), true ) ) {
				$out[] = $name . ': only in ' . ( in_array( $name, $source->entries(), true ) ? 'the source' : 'the export' );
				continue;
			}
			if ( str_ends_with( $name, '.json' ) ) {
				self::compare( json_decode( $source->file( $name ) ), json_decode( $export->file( $name ) ), $name, $out );
			} elseif ( 'cmi5.xml' === $name ) {
				self::compare( self::xml_facts( $source->file( $name ) ), self::xml_facts( $export->file( $name ) ), $name, $out );
			} elseif ( $source->file( $name ) !== $export->file( $name ) ) {
				$out[] = $name . ': bytes differ';
			}
		}
		return $out;
	}

	private static function compare( mixed $a, mixed $b, string $at, array &$out ): void {
		if ( is_object( $a ) && is_object( $b ) ) {
			$a    = get_object_vars( $a );
			$b    = get_object_vars( $b );
			$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
			sort( $keys );
			foreach ( $keys as $k ) {
				if ( ! array_key_exists( $k, $a ) || ! array_key_exists( $k, $b ) ) {
					$out[] = $at . '/' . $k . ': missing in the ' . ( array_key_exists( $k, $a ) ? 'export' : 'source' );
				} else {
					self::compare( $a[ $k ], $b[ $k ], $at . '/' . $k, $out );
				}
			}
			return;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				$out[] = $at . ': ' . count( $a ) . ' items != ' . count( $b );
			}
			foreach ( array_keys( array_intersect_key( $a, $b ) ) as $i ) {
				self::compare( $a[ $i ], $b[ $i ], $at . '/' . $i, $out );
			}
			return;
		}
		if ( $a !== $b ) {
			$out[] = $at . ': ' . wp_json_encode( $a ) . ' != ' . wp_json_encode( $b );
		}
	}

	/**
	 * The compared facts of a cmi5.xml, as nested objects keyed by element id.
	 *
	 * @param string $xml XML.
	 *
	 * @return object
	 */
	private static function xml_facts( string $xml ): object {
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xp = new DOMXPath( $dom );
		$xp->registerNamespace( 'c', Cmi5_Xml_Builder::NS_CMI5 );
		$xp->registerNamespace( 'x', Cmi5_Xml_Builder::NS_CXM );
		$facts = new stdClass();
		foreach ( $xp->query( '//c:course | //c:block | //c:au' ) as $el ) {
			$fact = array( 'element' => $el->localName );
			foreach ( array( 'moveOn', 'launchMethod' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$fact[ $attr ] = $el->getAttribute( $attr );
				}
			}
			foreach ( $el->attributes as $attr ) {
				if ( Cmi5_Xml_Builder::NS_CXM === $attr->namespaceURI ) {
					$fact[ 'cxm:' . $attr->localName ] = $attr->value;
				}
			}
			foreach ( array( 'title', 'description' ) as $name ) {
				foreach ( $xp->query( 'c:' . $name . '/c:langstring', $el ) as $ls ) {
					$fact[ $name ][ $ls->getAttribute( 'lang' ) ] = $ls->textContent;
				}
			}
			foreach ( $xp->query( 'x:*', $el ) as $ext ) {
				foreach ( $ext->attributes as $attr ) {
					$fact[ $ext->localName . '@' . $attr->localName ] = $attr->value;
				}
			}
			$facts->{$el->getAttribute( 'id' )} = (object) $fact;
		}
		$order                     = array_map( static fn( $el ) => $el->getAttribute( 'id' ), iterator_to_array( $xp->query( '//c:block | //c:au' ) ) );
		$facts->{'document order'} = $order;
		return $facts;
	}

	public function test_a_trusted_import_exports_the_package_it_imported() {
		$export = $this->round_trip( true );
		$this->assertSame( array(), self::differences( $export ) );
		$this->assertSame( Package_Builder::SPEC_VERSION, $export->course_json()['specVersion'] );
		$this->assertCount( 19, Course_Structure_Parser::parse( $export->cmi5_xml() )->aus(), 'the placement AU and 18 lessons' );
	}

	public function test_the_round_trip_holds_under_a_host_that_empties_post_queries() {
		add_action( 'pre_get_posts', static fn( $q ) => $q->set( 'post__in', array( 0 ) ) );
		$this->assertSame( array(), self::differences( $this->round_trip( true ) ) );
	}

	public function test_an_import_leaves_the_course_server_url_to_the_operator() {
		$report  = ( new Package_Importer() )->import( Package::from_zip( self::FIXTURE, new Limits() ), 'sensei', true );
		$program = $report->program_id;
		$this->assertSame( '', (string) get_post_meta( $program, '_lledger_course_server_url', true ) );
		$this->assertSame( 'https://learnwp.sqwrlz.com/api/mcp', (string) get_post_meta( $program, Package_Builder::PACKAGE_MCP, true ) );

		$exported = function () use ( $program ): string {
			$path         = (string) wp_tempnam( 'llrt' );
			$this->temp[] = $path;
			file_put_contents( $path, ( new Package_Builder() )->build( $program, 'full' )->zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
			$xml = new DOMDocument();
			$xml->loadXML( Package::from_zip( $path, new Limits() )->cmi5_xml() );
			return $xml->getElementsByTagNameNS( Cmi5_Xml_Builder::NS_CXM, 'course' )->item( 0 )->getAttribute( 'mcp' );
		};
		$this->assertSame( 'https://learnwp.sqwrlz.com/api/mcp', $exported(), 'no operator value: the package mcp' );

		update_post_meta( $program, '_lledger_course_server_url', 'https://operator.example.org/mcp' );
		$this->assertSame( 'https://operator.example.org/mcp', $exported(), 'the operator value wins' );

		( new Package_Importer() )->import( Package::from_zip( self::FIXTURE, new Limits() ), 'sensei', true );
		$this->assertSame( 'https://operator.example.org/mcp', (string) get_post_meta( $program, '_lledger_course_server_url', true ), 'a re-import never writes it' );
	}

	public function test_an_untrusted_import_still_runs_kses() {
		$diffs = self::differences( $this->round_trip( false ) );
		$this->assertNotSame( array(), preg_grep( '#/checks/\d+/pattern: #', $diffs ), 'kses rewrites regex patterns in an untrusted import' );
	}

	public function test_the_course_version_moves_with_wordpress_edits_and_returns_on_import() {
		$report  = ( new Package_Importer() )->import( Package::from_zip( self::FIXTURE, new Limits() ), 'sensei', true );
		$program = $report->program_id;
		$version = static function () use ( $program ): string {
			$path = (string) wp_tempnam( 'llrt' );
			file_put_contents( $path, ( new Package_Builder() )->build( $program, 'full' )->zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
			$v = Package::from_zip( $path, new Limits() )->course_json()['version'];
			wp_delete_file( $path );
			return $v;
		};
		$this->assertSame( '0.1.0', $version(), 'the imported version, as it came' );
		$this->assertSame( '0.1.0', $version(), 'no change, no new version' );

		global $wpdb;
		$lesson = (int) $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lledger_stable_key' AND meta_value = 'ln-publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_update_post(
			array(
				'ID'         => $lesson,
				'post_title' => 'Publishing, edited in WordPress',
			)
		);
		$this->assertSame( '0.1.1', $version(), 'a WordPress edit raises the patch' );
		update_post_meta( $lesson, '_lledger_minutes', 9 );
		$this->assertSame( '0.1.2', $version() );
		$this->assertSame( '0.1.2', $version() );

		( new Package_Importer() )->import( Package::from_zip( self::FIXTURE, new Limits() ), 'sensei', true );
		$this->assertSame( '0.1.0', $version(), 'a new import is the package version again' );
	}
}
