<?php
/**
 * The Sensei adapter on a host that empties anonymous post queries.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Packages\Package_Builder;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Db\Installer;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Util\Uuid;

/**
 * Some hosts hook pre_get_posts on anonymous requests and empty every post query, which
 * suppress_filters does not prevent. Signed course-server requests are anonymous.
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Host_Filter_Test extends WP_UnitTestCase {

	private Sensei_Adapter $adapter;

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		$this->adapter = new Sensei_Adapter();
	}

	private static function host_filter(): void {
		add_action( 'pre_get_posts', static fn( $q ) => $q->set( 'post__in', array( 0 ) ) );
	}

	private function course( string $title = 'Course' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'course',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private function lesson( int $course, int $order, string $date, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_status' => $status,
				'post_title'  => 'Lesson ' . $date,
				'post_date'   => $date,
			)
		);
		update_post_meta( $id, '_lesson_course', $course );
		if ( $order > 0 ) {
			update_post_meta( $id, '_order_' . $course, $order );
		}
		return $id;
	}

	private function quiz( int $lesson, int $quiz_parent, bool $pass_required, bool $questions ): int {
		$quiz = self::factory()->post->create(
			array(
				'post_type'   => 'quiz',
				'post_status' => 'publish',
				'post_parent' => $quiz_parent,
			)
		);
		update_post_meta( $lesson, '_lesson_quiz', $quiz );
		update_post_meta( $lesson, '_quiz_has_questions', $questions ? 1 : '' );
		update_post_meta( $quiz, '_pass_required', $pass_required ? 'on' : '' );
		return $quiz;
	}

	private static function beat( string $id ): string {
		return '<!-- wp:learning-ledger/beat ' . wp_json_encode(
			array(
				'id'       => $id,
				'goal'     => 'Goal of ' . $id,
				'question' => 'Question of ' . $id . '?',
			)
		) . ' --><!-- wp:paragraph --><p>About ' . $id . '.</p><!-- /wp:paragraph --><!-- /wp:learning-ledger/beat -->';
	}

	private function ids( int $course ): array {
		return array_map( static fn( $n ) => $n->id, $this->adapter->course_tree( $course )->lessons );
	}

	public function test_lesson_ids_match_course_lessons_and_survive_the_host_filter() {
		$course = $this->course();
		$a      = $this->lesson( $course, 2, '2026-01-05 00:00:00' );
		$b      = $this->lesson( $course, 0, '2026-01-01 00:00:00' );
		$c      = $this->lesson( $course, 1, '2026-01-03 00:00:00' );
		$d      = $this->lesson( $course, 0, '2026-01-02 00:00:00' );
		$e      = $this->lesson( $course, 3, '2026-01-04 00:00:00', 'draft' );
		$h      = $this->lesson( $course, 0, '2026-01-06 00:00:00', 'private' );
		$this->lesson( $course, 4, '2026-01-07 00:00:00', 'trash' );
		$this->lesson( $this->course( 'Other' ), 1, '2026-01-01 00:00:00' );

		$sensei = array_map( 'intval', Sensei()->course->course_lessons( $course, 'any', 'ids' ) );
		$this->assertSame( array( $c, $a, $e, $b, $d, $h ), $sensei );
		$this->assertSame( $sensei, $this->ids( $course ) );

		self::host_filter();
		$this->assertSame( array(), Sensei()->course->course_lessons( $course, 'any', 'ids' ), 'the filter empties Sensei\'s query' );
		$this->assertSame( $sensei, $this->ids( $course ) );
	}

	public function test_pass_required_quiz_matches_sensei_under_the_host_filter() {
		$course  = $this->course();
		$lessons = array();
		foreach ( range( 1, 5 ) as $i ) {
			$lessons[ $i ] = $this->lesson( $course, $i, '2026-01-0' . $i . ' 00:00:00' );
		}
		$this->quiz( $lessons[1], $lessons[1], true, true );
		$this->quiz( $lessons[2], 0, true, true );
		$this->quiz( $lessons[3], $lessons[3], false, true );
		$this->quiz( $lessons[4], $lessons[4], true, false );

		$sensei = array_map( static fn( int $l ): bool => (bool) Sensei()->lesson->lesson_has_quiz_with_questions_and_pass_required( $l ), array_values( $lessons ) );
		$this->assertSame( array( true, true, false, false, false ), $sensei );
		$flags = static fn( $tree ) => array_map( static fn( $n ) => $n->has_pass_required_quiz, $tree->lessons );
		$this->assertSame( $sensei, $flags( $this->adapter->course_tree( $course ) ) );

		self::host_filter();
		$this->assertSame( $sensei, $flags( $this->adapter->course_tree( $course ) ) );
	}

	public function test_upserts_find_existing_posts_under_the_host_filter() {
		self::host_filter();
		$spec   = new Course_Spec( 'hosted', 'Hosted', '<p>Intro</p>', 'publish', array() );
		$course = $this->adapter->upsert_course( $spec );
		$one    = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'one', 'One', '<p>1</p>', 1, 'publish', array() ) );
		$two    = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'two', 'Two', '<p>2</p>', 2, 'publish', array() ) );
		$this->assertSame( $one . ',' . $two, get_post_meta( $course, '_lesson_order', true ) );

		$this->assertSame( $course, $this->adapter->upsert_course( $spec ) );
		$this->assertSame( $one, $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'one', 'One', '<p>1</p>', 3, 'publish', array() ) ) );
		$this->assertSame( $two . ',' . $one, get_post_meta( $course, '_lesson_order', true ) );

		$other = $this->adapter->upsert_course( new Course_Spec( 'elsewhere', 'Elsewhere', '', 'publish', array() ) );
		$this->assertNotSame( $one, $this->adapter->upsert_lesson( $other, new Lesson_Spec( 'one', 'One', '', 1, 'publish', array() ) ), 'a key new to the course makes a new lesson' );

		global $wpdb;
		$count = static fn( string $type ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 2, $count( 'course' ) );
		$this->assertSame( 3, $count( 'lesson' ) );
	}

	public function test_enrol_and_progress_under_the_host_filter() {
		$course = $this->course();
		$l1     = $this->lesson( $course, 1, '2026-01-01 00:00:00' );
		$l2     = $this->lesson( $course, 2, '2026-01-02 00:00:00' );
		$user   = self::factory()->user->create();
		Sensei_Utils::sensei_start_lesson( $l1, $user, true );
		( new Waiver_Ledger() )->record( $user, $l2, Uuid::v4(), Uuid::v4(), 'Tested Out', false );

		self::host_filter();
		$this->assertTrue( $this->adapter->enrol( $user, $course ) );
		$this->assertTrue( Sensei_Course_Enrolment::get_course_instance( $course )->is_enrolled( $user, false ) );
		$progress = $this->adapter->progress( $user, $course );
		$this->assertSame( array( $l1 ), $progress->completed_lesson_ids );
		$this->assertSame( array( $l2 ), $progress->tested_out_lesson_ids );
		$this->assertFalse( $progress->course_completed );
	}

	public function test_a_full_package_build_is_the_same_under_the_host_filter() {
		update_option( 'lledger_roles', array( 'connector' ) );
		delete_option( 'lledger_integration_state' );
		( new Integration_Loader() )->load();
		$paths = array();
		foreach ( array(
			'path-a' => 2,
			'path-b' => 1,
		) as $key => $count ) {
			$course = $this->adapter->upsert_course( new Course_Spec( $key, 'Path ' . $key, '', 'publish', array() ) );
			foreach ( range( $count, 1 ) as $i ) {
				$this->adapter->upsert_lesson( $course, new Lesson_Spec( $key . '-' . $i, 'Lesson ' . $i, self::beat( $key . '-' . $i ), $i, 'publish', array( '_lledger_minutes' => 4 ) ) );
			}
			$paths[ $course ] = $key;
		}
		$program = self::factory()->post->create(
			array(
				'post_type'   => Program_Repository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Hosted program',
			)
		);
		$meta    = array(
			'_lledger_stable_key'        => 'hosted',
			'_lledger_iri_base'          => 'https://example.org/courses/hosted/',
			'_lledger_courses'           => array_keys( $paths ),
			'_lledger_path_keys'         => $paths,
			'_lledger_custody'           => 'server-kept',
			'_lledger_course_server_url' => 'https://hosted.example.org/mcp',
			'_lledger_au_url_template'   => 'https://hosted.example.org/cmi5/launch?au={key}',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $program, $k, $v );
		}

		( new Package_Builder() )->build( $program, 'full' );
		$plain = ( new Package_Builder() )->build( $program, 'full' );
		$zip   = new ZipArchive();
		$file  = wp_tempnam( 'llhost' );
		file_put_contents( $file, $plain->zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		$this->assertTrue( $zip->open( $file ) );
		$xml = (string) $zip->getFromName( 'cmi5.xml' );
		$zip->close();
		wp_delete_file( $file );
		$this->assertSame( 3, substr_count( $xml, 'cxm:lesson ' ), 'every lesson is an AU' );

		self::host_filter();
		$this->assertSame( array(), get_posts( array( 'post_type' => 'lesson' ) ) );
		$this->assertSame( $program, ( new Program_Repository() )->find_by_key( 'hosted' ) );
		$hosted = ( new Package_Builder() )->build( $program, 'full' );
		$this->assertSame( $plain->etag, $hosted->etag );
		$this->assertSame( $plain->version, $hosted->version );
		$this->assertSame( $plain->zip, $hosted->zip );
	}
}
