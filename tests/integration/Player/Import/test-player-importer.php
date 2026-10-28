<?php
/**
 * cmi5 player package import tests.
 *
 * @package LearningLedger
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- tests read fixtures and write temp files directly.

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Course_Structure;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Outbox\Direction2;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Player\Import\Player_Import_Module;
use LearningLedger\Player\Import\Player_Importer;
use LearningLedger\Player\Store\Au_Store;
use LearningLedger\Player\Store\Content_Host;
use LearningLedger\Player\Store\Extract_Job;
use LearningLedger\Queue\Job_Queue;

require_once dirname( __DIR__, 3 ) . '/fixtures/packages/class-package-fixtures.php';

/**
 * Player_Importer, its REST route and its job hook.
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Player_Importer_Test extends WP_UnitTestCase {

	const FIX = LEARNING_LEDGER_DIR . 'tests/fixtures/catapult';

	const ESSENTIALS_AU = 'https://w3id.org/xapi/cmi5/catapult/lts/au/001-essentials';

	private Sensei_Adapter $adapter;

	private Program_Repository $repo;

	/**
	 * Temp files written by a test.
	 *
	 * @var string[]
	 */
	private array $temp = array();

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		global $wpdb;
		foreach ( array( 'jobs', 'cmi5_packages', 'cmi5_files' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		$this->adapter = new Sensei_Adapter();
		$this->repo    = new Program_Repository();
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( $wpdb->get_col( 'SELECT id FROM ' . Tables::name( 'cmi5_packages' ) ) as $id ) { // phpcs:ignore WordPress.DB
			Au_Store::delete( (int) $id );
		}
		foreach ( $this->temp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		remove_all_actions( 'learning_ledger/jobs/cmi5_extract' );
		remove_all_actions( 'learning_ledger/jobs/cmi5_supersede' );
		parent::tear_down();
	}

	private function importer(): Player_Importer {
		return new Player_Importer( $this->adapter, $this->repo );
	}

	/**
	 * A package ZIP: the fixture's cmi5.xml plus CATAPULT's index.html.
	 *
	 * @param string $xml cmi5.xml contents.
	 */
	private function zip( string $xml ): string {
		$html         = file_get_contents( self::FIX . '/index.html' );
		$path         = Package_Fixtures::zip(
			static function ( ZipArchive $z ) use ( $xml, $html ) {
				$z->addFromString( 'cmi5.xml', $xml );
				$z->addFromString( 'index.html', $html );
			}
		);
		$this->temp[] = $path;
		return $path;
	}

	private function xml_file( string $xml ): string {
		$path = tempnam( sys_get_temp_dir(), 'llxml' );
		file_put_contents( $path, $xml );
		$this->temp[] = $path;
		return $path;
	}

	private function fixture( string $name ): string {
		return file_get_contents( self::FIX . '/' . $name );
	}

	private function nested_xml(): string {
		return file_get_contents( LEARNING_LEDGER_DIR . 'tests/fixtures/cmi5/nested-blocks.xml' );
	}

	private function count_posts( string $type ): int {
		return count(
			get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	private function count_packages(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'cmi5_packages' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Lessons of a course: [title, au id, content] in Sensei's order.
	 */
	private function lessons( int $course_id ): array {
		$out = array();
		foreach ( $this->adapter->course_tree( $course_id )->lessons as $node ) {
			$out[] = array(
				'title'   => $node->title,
				'au'      => (string) get_post_meta( $node->id, Player_Importer::AU_META, true ),
				'content' => get_post_field( 'post_content', $node->id, 'raw' ),
				'id'      => $node->id,
			);
		}
		return $out;
	}

	private function assert_refused_whole( $report, string $code ): void {
		$this->assertFalse( $report->ok() );
		$this->assertSame( 0, $report->program_id );
		$this->assertContains( $code, array_column( $report->errors, 'code' ) );
		$this->assertSame( 0, $this->count_posts( Program_Repository::POST_TYPE ) );
		$this->assertSame( 0, $this->count_posts( 'course' ) );
		$this->assertSame( 0, $this->count_posts( 'lesson' ) );
		$this->assertSame( 0, $this->count_packages() );
	}

	public function test_essentials_imports_one_program_with_its_block_and_lesson() {
		$report = $this->importer()->import( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ) );

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertSame( 1, $this->count_posts( Program_Repository::POST_TYPE ) );
		$program = $this->repo->get( $report->program_id );
		$this->assertSame( 'CATAPULT LMS Test Course: 001 Essentials', get_the_title( $report->program_id ) );
		$this->assertSame( 'lms-launched', $program->custody );
		$this->assertSame( '1', (string) get_post_meta( $report->program_id, '_lledger_player', true ) );
		$this->assertSame( 'https://w3id.org/xapi/cmi5/catapult/lts/course/001-essentials', $program->course_iri );

		$this->assertCount( 1, $program->course_ids );
		$course = $program->course_ids[0];
		$this->assertSame( 'course', get_post_type( $course ) );
		$this->assertSame( 'CATAPULT LMS Test Block: 001 Essentials', get_the_title( $course ) );

		$lessons = $this->lessons( $course );
		$this->assertCount( 1, $lessons );
		$this->assertSame( 'CATAPULT LMS Test AU: 001 Essentials', $lessons[0]['title'] );
		$this->assertSame( self::ESSENTIALS_AU, $lessons[0]['au'] );
		$this->assertSame( '<!-- wp:learning-ledger/cmi5-lesson {"auId":"' . self::ESSENTIALS_AU . '"} /-->', $lessons[0]['content'] );
		$this->assertEmpty( Sensei()->lesson->lesson_quizzes( $lessons[0]['id'] ) );
		$this->assertSame( 1, $this->count_posts( 'lesson' ) );

		$structure = $this->repo->structure( $report->program_id );
		$this->assertInstanceOf( Course_Structure::class, $structure );
		$this->assertCount( 1, $structure->blocks() );
		$au = $structure->find_au( self::ESSENTIALS_AU );
		$this->assertSame( 'CompletedAndPassed', $au->move_on() );
		$this->assertSame( 0.9, $au->mastery_score() );
		$this->assertSame( 'sample string', $au->launch_parameters() );
	}

	public function test_nested_blocks_are_flattened_into_their_top_level_course_in_order() {
		$report = $this->importer()->import( $this->xml_file( $this->nested_xml() ) );

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$courses = $this->repo->get( $report->program_id )->course_ids;
		$this->assertSame( array( 'Block A', 'Block B' ), array_map( 'get_the_title', $courses ) );
		$this->assertSame( array( 'https://example.org/courses/nested/aus/a1' ), array_column( $this->lessons( $courses[0] ), 'au' ) );
		$this->assertSame(
			array( 'https://example.org/courses/nested/aus/b1', 'https://example.org/courses/nested/aus/b2' ),
			array_column( $this->lessons( $courses[1] ), 'au' )
		);
		$this->assertSame( array( 'B1', 'B2' ), array_column( $this->lessons( $courses[1] ), 'title' ) );
		// Each course records the top-level block it came from.
		$this->assertSame( 'https://example.org/courses/nested/blocks/a', get_post_meta( $courses[0], Player_Importer::BLOCK_META, true ) );
		$this->assertSame( 'https://example.org/courses/nested/blocks/b', get_post_meta( $courses[1], Player_Importer::BLOCK_META, true ) );

		// The stored tree keeps the nesting for satisfaction.
		$structure = $this->repo->structure( $report->program_id );
		$this->assertSame( 'https://example.org/courses/nested/blocks/b1', $structure->find_au( 'https://example.org/courses/nested/aus/b1' )->parent_block_id() );
		$this->assertNotNull( $structure->find_block( 'https://example.org/courses/nested/blocks/b1' ) );
		// Absolute URLs stay remote, and a standalone XML needs no content store.
		$this->assertSame( 'https://example.org/aus/b1.html', $structure->find_au( 'https://example.org/courses/nested/aus/b1' )->url() );
		$this->assertSame( 0, $this->count_packages() );
	}

	public function test_aus_directly_under_the_course_go_into_one_course_titled_after_it() {
		$xml    = str_replace(
			'</courseStructure>',
			'<au id="https://w3id.org/xapi/cmi5/catapult/lts/au/002-allowed-second"><title><langstring lang="en">Second</langstring></title><description><langstring lang="en">Second.</langstring></description><url>https://example.org/second.html</url></au></courseStructure>',
			$this->fixture( '002-allowed/cmi5.xml' )
		);
		$report = $this->importer()->import( $this->zip( $xml ) );

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$courses = $this->repo->get( $report->program_id )->course_ids;
		$this->assertCount( 1, $courses );
		$this->assertSame( 'CATAPULT LMS Test Course: 002 Allowed', get_the_title( $courses[0] ) );
		$this->assertSame(
			array( 'https://w3id.org/xapi/cmi5/catapult/lts/au/002-allowed', 'https://w3id.org/xapi/cmi5/catapult/lts/au/002-allowed-second' ),
			array_column( $this->lessons( $courses[0] ), 'au' )
		);
		$this->assertFalse( metadata_exists( 'post', $courses[0], Player_Importer::BLOCK_META ), 'the course made of root AUs has no block' );
	}

	public function test_relative_urls_resolve_under_the_content_base_and_absolute_ones_stay_remote() {
		$xml    = str_replace(
			'</block>',
			'<au id="https://w3id.org/xapi/cmi5/catapult/lts/au/001-essentials-remote"><title><langstring lang="en">Remote</langstring></title><description><langstring lang="en">Remote.</langstring></description><url>https://au.example.net/remote/index.html?x=1</url></au></block>',
			$this->fixture( '001-essentials/cmi5.xml' )
		);
		$report = $this->importer()->import( $this->zip( $xml ) );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );

		$package = (int) get_post_meta( $report->program_id, Player_Importer::PACKAGE_META, true );
		$this->assertGreaterThan( 0, $package );
		$this->assertSame( Au_Store::STATUS_PENDING, Au_Store::status( $package ) );
		$this->assertSame( $report->program_id, (int) Au_Store::row( $package )->program_id );

		$structure = $this->repo->structure( $report->program_id );
		$this->assertSame( Content_Host::base_url( $package ) . 'index.html?paramA=1&paramB=2', $structure->find_au( self::ESSENTIALS_AU )->url() );
		$this->assertSame( 'https://au.example.net/remote/index.html?x=1', $structure->find_au( 'https://w3id.org/xapi/cmi5/catapult/lts/au/001-essentials-remote' )->url() );
	}

	public function test_package_url_is_recorded_on_the_store_row() {
		$report  = $this->importer()->import( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ), 'https://packages.example.org/001-essentials.zip' );
		$package = (int) get_post_meta( $report->program_id, Player_Importer::PACKAGE_META, true );
		$this->assertSame( 'https://packages.example.org/001-essentials.zip', Au_Store::row( $package )->source_url );
	}

	public function test_module_hooks_the_extract_job_so_the_package_becomes_ready() {
		( new Player_Import_Module() )->register();
		$this->assertSame( 10, has_action( 'learning_ledger/jobs/cmi5_extract', array( Extract_Job::class, 'run' ) ) );
		$this->assertSame( 10, has_action( 'learning_ledger/jobs/cmi5_supersede', array( Player_Importer::class, 'sweep' ) ) );

		$report  = $this->importer()->import( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ) );
		$package = (int) get_post_meta( $report->program_id, Player_Importer::PACKAGE_META, true );
		Job_Queue::run();
		$this->assertSame( Au_Store::STATUS_READY, Au_Store::status( $package ) );
		$this->assertSame( hash( 'sha256', $this->fixture( 'index.html' ) ), Au_Store::lookup( $package, 'index.html' )['sha256'] );
	}

	public function test_invalid_xml_is_rejected_whole() {
		$this->assert_refused_whole( $this->importer()->import( $this->zip( $this->fixture( '207-1-invalid-courseStructure.xml' ) ) ), 'xsd' );
		$this->assert_refused_whole( $this->importer()->import( $this->xml_file( '<courseStructure' ) ), 'xsd' );
	}

	public function test_standalone_xml_with_a_relative_url_is_rejected() {
		$this->assert_refused_whole( $this->importer()->import( $this->xml_file( $this->fixture( '202-1-relative-url-no-zip.xml' ) ) ), 'au_url_relative' );
	}

	public function test_relative_url_not_in_the_zip_is_rejected() {
		$this->assert_refused_whole( $this->importer()->import( $this->zip( $this->fixture( '203-1-relative-url-no-reference/cmi5.xml' ) ) ), 'au_url_missing' );
	}

	public function test_duplicate_au_ids_are_rejected() {
		$this->assert_refused_whole( $this->importer()->import( $this->xml_file( $this->fixture( '205-3-duplicated-au.xml' ) ) ), 'duplicate_id' );
	}

	public function test_invalid_au_url_is_rejected() {
		$this->assert_refused_whole( $this->importer()->import( $this->xml_file( $this->fixture( '206-1-invalid-au-url.xml' ) ) ), 'au_url' );
		$js = str_replace( 'https://example.org/aus/a1.html', 'javascript:alert(1)', $this->nested_xml() );
		$this->assert_refused_whole( $this->importer()->import( $this->xml_file( $js ) ), 'au_url' );
		$up = str_replace( '<![CDATA[', '<![CDATA[../', $this->fixture( '001-essentials/cmi5.xml' ) );
		$this->assert_refused_whole( $this->importer()->import( $this->zip( $up ) ), 'au_url' );
	}

	public function test_au_url_carrying_a_launch_parameter_is_rejected() {
		$this->assert_refused_whole( $this->importer()->import( $this->zip( $this->fixture( '204-query-string-conflict-endpoint.xml' ) ) ), 'au_url_query' );
	}

	public function test_reimport_creates_nothing_new() {
		$zip   = $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) );
		$first = $this->importer()->import( $zip );
		$this->assertNotEmpty( $first->created );
		$package = (int) get_post_meta( $first->program_id, Player_Importer::PACKAGE_META, true );
		$counts  = array( $this->count_posts( Program_Repository::POST_TYPE ), $this->count_posts( 'course' ), $this->count_posts( 'lesson' ), $this->count_packages() );

		$again = $this->importer()->import( $zip );
		$this->assertTrue( $again->ok() );
		$this->assertSame( $first->program_id, $again->program_id );
		$this->assertSame( array(), $again->created );
		$this->assertSame( array(), $again->updated );
		$this->assertSame( $counts, array( $this->count_posts( Program_Repository::POST_TYPE ), $this->count_posts( 'course' ), $this->count_posts( 'lesson' ), $this->count_packages() ) );
		$this->assertSame( $package, (int) get_post_meta( $again->program_id, Player_Importer::PACKAGE_META, true ) );

		// A changed title updates the same lesson, matched by AU id.
		$lesson  = $this->lessons( $this->repo->get( $first->program_id )->course_ids[0] )[0]['id'];
		$renamed = $this->importer()->import( $this->zip( str_replace( 'CATAPULT LMS Test AU: 001 Essentials', 'Renamed AU', $this->fixture( '001-essentials/cmi5.xml' ) ) ) );
		$this->assertSame( array(), $renamed->created );
		$this->assertSame( 1, $this->count_posts( 'lesson' ) );
		$this->assertSame( 'Renamed AU', get_the_title( $lesson ) );
	}

	public function test_deleted_lesson_and_course_are_recreated_on_an_unchanged_reimport() {
		$zip    = $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) );
		$first  = $this->importer()->import( $zip );
		$course = $this->repo->get( $first->program_id )->course_ids[0];
		$lesson = $this->lessons( $course )[0]['id'];

		wp_delete_post( $lesson, true );
		$again = $this->importer()->import( $zip );
		$this->assertTrue( $again->ok(), wp_json_encode( $again->errors ) );
		$this->assertSame( 1, $this->count_posts( 'lesson' ), 'the deleted lesson is written again' );
		$this->assertNotSame( $lesson, $this->lessons( $course )[0]['id'] );

		wp_delete_post( $course, true );
		$third = $this->importer()->import( $zip );
		$this->assertTrue( $third->ok(), wp_json_encode( $third->errors ) );
		$this->assertSame( 1, $this->count_posts( 'course' ), 'the deleted course is written again' );
		$new_course = $this->repo->get( $third->program_id )->course_ids[0];
		$this->assertNotSame( $course, $new_course );
		$this->assertCount( 1, $this->lessons( $new_course ) );
	}

	public function test_imported_titles_are_sanitized() {
		$xml    = str_replace( 'CATAPULT LMS Test Course: 001 Essentials</langstring>', '<![CDATA[<script>alert(1)</script><b>Essentials</b>]]></langstring>', $this->fixture( '001-essentials/cmi5.xml' ) );
		$report = $this->importer()->import( $this->zip( $xml ) );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertSame( 'Essentials', get_post_field( 'post_title', $report->program_id, 'raw' ) );
	}

	public function test_direction2_is_off_for_imported_courses() {
		$report  = $this->importer()->import( $this->xml_file( $this->nested_xml() ) );
		$courses = $this->repo->get( $report->program_id )->course_ids;
		$this->assertCount( 2, $courses );
		foreach ( $courses as $course ) {
			Direction2::set( $course, 'on' );
			$this->assertFalse( Direction2::is_on( $course ) );
		}
	}

	public function test_cxm_package_goes_through_the_package_importer_and_is_marked_player() {
		$path = wp_tempnam( 'llcxm' );
		file_put_contents( $path, Package_Writer::write( ...Package_Fixtures::inputs( 'good-full' ) ) );
		$this->temp[] = $path;

		$report = $this->importer()->import( $path );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertSame( 'json-basics', $this->repo->get( $report->program_id )->stable_key );
		$this->assertSame( '1', (string) get_post_meta( $report->program_id, '_lledger_player', true ) );
		$this->assertContains( 'course:json', $report->created );
		foreach ( $this->repo->get( $report->program_id )->course_ids as $course ) {
			$this->assertFalse( Direction2::is_on( $course ) );
		}
	}

	private function cxm_zip( callable $edit_xml ): string {
		$in    = Package_Fixtures::inputs( 'good-full' );
		$in[0] = $edit_xml( $in[0] );
		$path  = wp_tempnam( 'llcxm' );
		file_put_contents( $path, Package_Writer::write( ...$in ) );
		$this->temp[] = $path;
		return $path;
	}

	public function test_cxm_package_with_a_bad_au_url_is_refused_whole() {
		$js = $this->cxm_zip( static fn( string $xml ): string => str_replace( 'https://json-basics.example.org/cmi5/launch?au=json-syntax', 'javascript:alert(1)', $xml ) );
		$this->assert_refused_whole( $this->importer()->import( $js ), 'au_url' );
		$relative = $this->cxm_zip( static fn( string $xml ): string => str_replace( 'https://json-basics.example.org/cmi5/launch?au=json-syntax', 'cmi5.xml', $xml ) );
		$this->assert_refused_whole( $this->importer()->import( $relative ), 'au_url_relative' );
	}

	public function test_changed_package_keeps_the_old_one_until_the_new_one_is_ready() {
		( new Player_Import_Module() )->register();
		$first = $this->importer()->import( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ) );
		$old   = (int) get_post_meta( $first->program_id, Player_Importer::PACKAGE_META, true );
		Job_Queue::run();
		$this->assertSame( Au_Store::STATUS_READY, Au_Store::status( $old ) );

		$second = $this->importer()->import( $this->zip( str_replace( 'CATAPULT LMS Test AU: 001 Essentials', 'Version two', $this->fixture( '001-essentials/cmi5.xml' ) ) . "\n" ) );
		$this->assertTrue( $second->ok(), wp_json_encode( $second->errors ) );
		$new = (int) get_post_meta( $first->program_id, Player_Importer::PACKAGE_META, true );
		$this->assertNotSame( $old, $new );
		$this->assertSame( Au_Store::STATUS_PENDING, Au_Store::status( $new ) );
		$this->assertSame( Content_Host::base_url( $new ) . 'index.html?paramA=1&paramB=2', $this->repo->structure( $first->program_id )->find_au( self::ESSENTIALS_AU )->url() );
		// The old package still serves while the new one extracts.
		$this->assertNotNull( Au_Store::lookup( $old, 'index.html' ) );
		$this->assertSame( array( $old ), get_post_meta( $first->program_id, Player_Importer::SUPERSEDED_META, true ) );

		Player_Importer::sweep( array( 'program_id' => $first->program_id ) );
		$this->assertSame( Au_Store::STATUS_READY, Au_Store::status( $old ), 'swept before the new package was ready' );

		Job_Queue::run();
		$this->assertSame( Au_Store::STATUS_READY, Au_Store::status( $new ) );
		Player_Importer::sweep( array( 'program_id' => $first->program_id ) );
		$this->assertSame( Au_Store::STATUS_MISSING, Au_Store::status( $old ) );
		$this->assertSame( Au_Store::STATUS_READY, Au_Store::status( $new ) );
		$this->assertSame( '', get_post_meta( $first->program_id, Player_Importer::SUPERSEDED_META, true ) );
	}

	public function test_reimport_without_relative_urls_deletes_the_stored_package() {
		$first = $this->importer()->import( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ) );
		$old   = (int) get_post_meta( $first->program_id, Player_Importer::PACKAGE_META, true );
		$this->assertGreaterThan( 0, $old );

		$remote = str_replace( 'index.html?paramA=1&paramB=2', 'https://au.example.net/essentials/index.html', $this->fixture( '001-essentials/cmi5.xml' ) );
		$second = $this->importer()->import( $this->zip( $remote ) );
		$this->assertTrue( $second->ok(), wp_json_encode( $second->errors ) );
		$this->assertSame( $first->program_id, $second->program_id );
		$this->assertSame( '', get_post_meta( $second->program_id, Player_Importer::PACKAGE_META, true ) );
		$this->assertSame( Au_Store::STATUS_MISSING, Au_Store::status( $old ) );
		$this->assertSame( 0, $this->count_packages() );
	}

	public function test_store_failure_leaves_no_empty_program() {
		$zip    = $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) );
		$vanish = static function ( $id, $post ) use ( $zip ) {
			if ( Program_Repository::POST_TYPE === $post->post_type && file_exists( $zip ) ) {
				unlink( $zip ); // The ZIP disappears before Au_Store::begin reads it.
			}
		};
		add_action( 'wp_insert_post', $vanish, 10, 2 );
		$report = $this->importer()->import( $zip );
		remove_action( 'wp_insert_post', $vanish, 10 );
		$this->assert_refused_whole( $report, 'zip_invalid' );
	}

	public function test_for_activity_finds_player_programs_by_exact_au_id() {
		$essentials = $this->importer()->import( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ) )->program_id;
		$allowed    = $this->importer()->import( $this->zip( $this->fixture( '002-allowed/cmi5.xml' ) ) )->program_id;
		$deeper_xml = str_replace(
			array( 'course/002-allowed', 'au/002-allowed"' ),
			array( 'course/002-deeper', 'au/001-essentials/deeper"' ),
			$this->fixture( '002-allowed/cmi5.xml' )
		);
		$deeper     = $this->importer()->import( $this->zip( $deeper_xml ) )->program_id;
		$this->assertCount( 3, array_unique( array( $essentials, $allowed, $deeper ) ) );
		$allowed_au = 'https://w3id.org/xapi/cmi5/catapult/lts/au/002-allowed';
		$deeper_au  = self::ESSENTIALS_AU . '/deeper';

		$this->assertSame( $essentials, $this->repo->for_activity( self::ESSENTIALS_AU ) );
		$this->assertSame( $essentials, $this->repo->for_activity( Au::activity_id( self::ESSENTIALS_AU ) ) );
		$this->assertSame( $allowed, $this->repo->for_activity( $allowed_au ) );
		$this->assertSame( $allowed, $this->repo->for_activity( Au::activity_id( $allowed_au ) ) );
		$this->assertSame( $deeper, $this->repo->for_activity( $deeper_au ) );
		$this->assertSame( $deeper, $this->repo->for_activity( Au::activity_id( $deeper_au ) ) );
		$this->assertNull( $this->repo->for_activity( 'https://w3id.org/xapi/cmi5/catapult/lts/au/' ) );
		$this->assertNull( $this->repo->for_activity( self::ESSENTIALS_AU . '/other' ) );
		$this->assertNull( $this->repo->for_activity( Au::activity_id( self::ESSENTIALS_AU ) . '/extra' ) );
		$this->assertSame( '', get_post_meta( $essentials, '_lledger_iri_base', true ) );
	}

	private function admin(): int {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( Program_Module::CAP );
		return $admin;
	}

	private function upload_request( string $zip ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/cmi5/import' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'package.zip',
					'type'     => 'application/zip',
					'tmp_name' => $zip,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $zip ),
				),
			)
		);
		return $request;
	}

	public function test_route_needs_the_capability_and_a_nonce() {
		( new Player_Import_Module() )->register();
		do_action( 'rest_api_init' );
		$zip = $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $this->upload_request( $zip ) )->get_status() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$request = $this->upload_request( $zip );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( $this->admin() );
		$this->assertSame( 403, rest_do_request( $this->upload_request( $zip ) )->get_status() );
		$this->assertSame( 0, $this->count_posts( Program_Repository::POST_TYPE ) );
	}

	public function test_route_imports_an_upload_and_returns_422_for_an_invalid_one() {
		( new Player_Import_Module() )->register();
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->admin() );
		update_option( 'lledger_roles', array( 'connector' ) );
		( new Integration_Loader() )->load();

		$request = $this->upload_request( $this->zip( $this->fixture( '001-essentials/cmi5.xml' ) ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$ok = rest_do_request( $request );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertGreaterThan( 0, $ok->get_data()['program_id'] );

		$request = $this->upload_request( $this->zip( $this->fixture( '207-1-invalid-courseStructure.xml' ) ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$bad = rest_do_request( $request );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'xsd', $bad->get_data()['errors'][0]['code'] );

		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/cmi5/import' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 422, rest_do_request( $request )->get_status() );
	}
}
