<?php
/**
 * Package importer, fetcher and controller tests.
 *
 * @package LearningLedger
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.WP.AlternativeFunctions -- Test double and fixtures.

use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\Lms_Object;
use LearningLedger\Connector\Lms\Result;
use LearningLedger\Connector\Packages\Import_Controller;
use LearningLedger\Connector\Packages\Package_Fetcher;
use LearningLedger\Connector\Packages\Package_Importer;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Tests\Fake_Adapter;
use LearningLedger\Util\Invalid_Input;

require_once dirname( __DIR__, 3 ) . '/fixtures/packages/class-package-fixtures.php';

/**
 * Fake adapter that keeps real posts, records specs and can fail on the Nth new lesson.
 */
class Importing_Fake_Adapter extends Fake_Adapter {

	/** @var Course_Spec[] */
	public array $course_specs = array();

	/** @var Lesson_Spec[] */
	public array $lesson_specs = array();

	public int $lesson_calls = 0;

	public int $fail_on_call = 0;

	/** Id returned for every course instead of the real one, to simulate a foreign id. */
	public int $course_id_override = 0;

	/** @var array<string,int> */
	private array $ids_by_key = array();

	public function upsert_course( Course_Spec $spec ): int {
		$this->course_specs[] = $spec;
		$id                   = parent::upsert_course( $spec );
		if ( $this->course_id_override > 0 ) {
			return $this->course_id_override;
		}
		// The importer trusts a stored hash only while the LMS post exists.
		if ( ! get_post( $id ) ) {
			wp_insert_post(
				array(
					'import_id'   => $id,
					'post_title'  => $spec->title,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);
		}
		return $id;
	}

	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		++$this->lesson_calls;
		if ( $this->fail_on_call && $this->lesson_calls === $this->fail_on_call ) {
			throw new RuntimeException( 'lms went away' );
		}
		$this->lesson_specs[]                  = $spec;
		$id                                    = parent::upsert_lesson( $course_id, $spec );
		$this->ids_by_key[ $spec->stable_key ] = $id;
		if ( ! get_post( $id ) ) {
			wp_insert_post(
				array(
					'import_id'   => $id,
					'post_title'  => $spec->title,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $spec->content,
					'post_status'  => $spec->status,
				)
			)
		);
		foreach ( $spec->meta as $k => $v ) {
			update_post_meta( $id, $k, wp_slash( $v ) );
		}
		return $id;
	}

	public function lesson_id( string $key ): int {
		return $this->ids_by_key[ $key ] ?? 0;
	}
}

class Package_Importer_Test extends WP_UnitTestCase {

	private Importing_Fake_Adapter $adapter;

	private Program_Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		$this->adapter = new Importing_Fake_Adapter();
		$this->repo    = new Program_Repository();
		// The fake adapter keeps its courses as plain posts.
		add_filter( 'learning_ledger/course_post_types', array( $this, 'course_types' ) );
	}

	public function course_types( $types ): array {
		return array_merge( (array) $types, array( 'post' ) );
	}

	/**
	 * The good-full fixture plus a second path (yaml) with one lesson.
	 */
	private function inputs(): array {
		$in = Package_Fixtures::inputs( 'good-full' );
		$ns = 'https://example.org/courses/json-basics';

		$in[0] = str_replace(
			'</courseStructure>',
			'<block id="' . $ns . '/paths/yaml" cxm:key="yaml">
    <title><langstring lang="en">YAML</langstring></title>
    <description><langstring lang="en">The YAML path.</langstring></description>
    <au id="' . $ns . '/lessons/yaml-intro" cxm:key="yaml-intro" moveOn="Completed" launchMethod="OwnWindow"
        activityType="http://adlnet.gov/expapi/activities/lesson">
      <title><langstring lang="en">YAML intro</langstring></title>
      <description><langstring lang="en">Indentation matters.</langstring></description>
      <url>https://json-basics.example.org/cmi5/launch?au=yaml-intro</url>
      <cxm:lesson src="cxm/lessons/yaml-intro.json"/>
    </au>
    <cxm:path src="cxm/course.json#/paths/yaml"/>
  </block>
</courseStructure>',
			$in[0]
		);

		$in[1]['paths']['yaml'] = array( 'tagline' => 'Indentation as syntax.' );
		$in[2]['yaml-intro']    = array(
			'minutes' => 3,
			'beats'   => array(
				array(
					'key'     => 'indent',
					'goal'    => 'Indent with spaces.',
					'content' => "YAML uses spaces.\n\n<script>alert(1)</script>\n\n- one\n- two",
				),
			),
		);
		return $in;
	}

	private function package( ?array $in = null ): Package {
		$in   = $in ?? $this->inputs();
		$path = wp_tempnam( 'llpkg' );
		file_put_contents( $path, Package_Writer::write( $in[0], $in[1], $in[2], $in[3] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return Package::from_zip( $path, new Limits() );
	}

	private function import( ?Package $p = null ) {
		return ( new Package_Importer( $this->adapter ) )->import( $p ?? $this->package(), 'fake' );
	}

	private function lesson_content( string $key ): string {
		return (string) get_post( $this->adapter->lesson_id( $key ) )->post_content;
	}

	public function test_imports_program_courses_and_lessons_in_order() {
		$report = $this->import();

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertSame( array( 'course:json', 'lesson:what-is-json', 'lesson:json-syntax', 'course:yaml', 'lesson:yaml-intro' ), $report->created );
		$this->assertSame( array(), $report->updated );
		$this->assertSame( array(), $report->drafted );

		$program = $this->repo->get( $report->program_id );
		$this->assertSame( 'json-basics', $program->stable_key );
		$this->assertSame( 'https://example.org/courses/json-basics', $program->course_iri );
		$this->assertSame( 'https://example.org/courses/json-basics/', $program->iri_base );
		$this->assertSame( 'server-kept', $program->custody );
		$this->assertSame( 'Ask the learner to read JSON back as data types before commenting.', $program->rules );
		$this->assertSame( 'placement', $program->placement['au_key'] );
		$this->assertSame( 2, $program->placement['guide']['maxQuestions'] );
		$this->assertCount( 2, $program->course_ids );
		$this->assertSame( array( 'json', 'yaml' ), array_values( $program->path_keys ) );
		$this->assertNotNull( $this->repo->structure( $report->program_id ) );

		$this->assertSame( array( 'json', 'yaml' ), array_column( $this->adapter->course_specs, 'stable_key' ) );
		$this->assertSame( array( 'what-is-json', 'json-syntax', 'yaml-intro' ), array_column( $this->adapter->lesson_specs, 'stable_key' ) );
		$this->assertSame( array( 1, 2, 1 ), array_column( $this->adapter->lesson_specs, 'order' ) );
		$this->assertCount( 2, $this->adapter->course_tree( $program->course_ids[0] )->lessons );

		$placement = array_filter( $this->adapter->lesson_specs, static fn( $s ) => 'placement' === $s->stable_key );
		$this->assertSame( array(), $placement, 'the placement AU is program meta, not a lesson' );
	}

	public function test_course_tagline_becomes_the_course_excerpt() {
		$report  = $this->import();
		$courses = $this->repo->get( $report->program_id )->course_ids;

		$this->assertSame( 'Indentation as syntax.', get_post_field( 'post_excerpt', $courses[1], 'raw' ) );
	}

	public function test_tagline_never_overwrites_a_post_of_another_type() {
		$page                              = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_excerpt' => 'Keep me.',
			)
		);
		$this->adapter->course_id_override = $page;

		$report = $this->import();

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertSame( 'Keep me.', get_post_field( 'post_excerpt', $page, 'raw' ) );
	}

	public function test_tagline_is_skipped_when_no_course_post_type_is_reported() {
		remove_filter( 'learning_ledger/course_post_types', array( $this, 'course_types' ) );
		$report  = $this->import();
		$courses = $this->repo->get( $report->program_id )->course_ids;

		$this->assertSame( '', get_post_field( 'post_excerpt', $courses[1], 'raw' ) );
	}

	public function test_lesson_blocks_and_secrets_land_in_post_content() {
		$this->import();

		$blocks = array_values( array_filter( parse_blocks( $this->lesson_content( 'what-is-json' ) ), static fn( $b ) => $b['blockName'] ) );
		$this->assertSame(
			array( 'learning-ledger/beat', 'learning-ledger/checkpoint', 'learning-ledger/planted-bug' ),
			array_column( $blocks, 'blockName' )
		);
		$this->assertSame( 'six-types', $blocks[0]['attrs']['id'] );
		$this->assertSame( 'learning-ledger/rubric', $blocks[1]['innerBlocks'][0]['blockName'] );
		$this->assertStringContainsString( 'Full marks if the learner says', $blocks[1]['innerBlocks'][0]['attrs']['text'] );
		$this->assertSame( 'types-check', $blocks[1]['attrs']['key'] );
		$this->assertCount( 2, $blocks[1]['attrs']['branches'] );
		$this->assertSame( 'port-string', $blocks[2]['attrs']['key'] );
		$this->assertStringContainsString( 'The port is a string', $blocks[2]['attrs']['explanation'] );
		$this->assertSame( 'contains', $blocks[2]['attrs']['fixChecks'][0]['type'] );

		$this->assertSame( '5', (string) get_post_meta( $this->adapter->lesson_id( 'what-is-json' ), '_lledger_minutes', true ) );
		$this->assertStringContainsString( 'rfc-editor.org', (string) get_post_meta( $this->adapter->lesson_id( 'what-is-json' ), '_lledger_sources', true ) );
		$this->assertStringContainsString( 'config.json', (string) get_post_meta( $this->adapter->lesson_id( 'what-is-json' ), '_lledger_starter_files', true ) );
	}

	public function test_imported_markdown_is_sanitized() {
		$this->import();
		$content = $this->adapter->lesson_specs[2]->content;

		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringContainsString( '<!-- wp:list', $content );
		$this->assertSame( $content, wp_kses_post( $content ), 'post_content is already stable under wp_kses_post' );
	}

	public function test_second_import_reports_no_changes_and_writes_nothing() {
		$first = $this->import();
		$calls = $this->adapter->lesson_calls;
		$specs = count( $this->adapter->course_specs );

		$second = $this->import();

		$this->assertTrue( $second->ok() );
		$this->assertSame( $first->program_id, $second->program_id );
		$this->assertSame( array(), $second->created );
		$this->assertSame( array(), $second->updated );
		$this->assertSame( array(), $second->drafted );
		$this->assertSame( $calls, $this->adapter->lesson_calls );
		$this->assertCount( $specs, $this->adapter->course_specs );
	}

	public function test_changed_lesson_is_the_only_update() {
		$this->import();
		$in = $this->inputs();
		$in[2]['json-syntax']['beats'][0]['content'] = 'Changed text.';

		$report = $this->import( $this->package( $in ) );

		$this->assertSame( array( 'lesson:json-syntax' ), $report->updated );
		$this->assertSame( array(), $report->created );
		$this->assertStringContainsString( 'Changed text.', $this->lesson_content( 'json-syntax' ) );
	}

	public function test_lesson_removed_from_package_is_drafted_and_listed() {
		$this->import();
		$id = $this->adapter->lesson_id( 'json-syntax' );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$in = $this->inputs();
		unset( $in[2]['json-syntax'] );
		$in[0] = preg_replace( '#<au id="[^"]*json-syntax".*?</au>#s', '', $in[0] );
		// The what-is-json branch points at json-syntax; drop that branch target.
		$in[2]['what-is-json']['checkpoint']['branches'][0]['to'] = null;

		$report = $this->import( $this->package( $in ) );

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertSame( array( 'lesson:json-syntax' ), $report->drafted );
		$this->assertSame( 'draft', get_post_status( $id ) );

		$again = $this->import( $this->package( $in ) );
		$this->assertSame( array(), $again->drafted, 'already drafted lessons are not reported again' );
	}

	public function test_invalid_package_writes_nothing() {
		$report = $this->import( Package::from_xml( '<courseStructure>' ) );

		$this->assertFalse( $report->ok() );
		$this->assertSame( 0, $report->program_id );
		$this->assertContains( 'xsd', array_column( $report->errors, 'code' ) );
		$this->assertSame( array(), $this->repo->all_ids() );
		$this->assertSame( array(), $this->adapter->course_specs );
		$this->assertSame( 0, $this->adapter->lesson_calls );
	}

	public function test_schema_invalid_lesson_writes_nothing() {
		$in                              = $this->inputs();
		$in[2]['json-syntax']['minutes'] = 'many';
		$report                          = $this->import( $this->package( $in ) );

		$this->assertFalse( $report->ok() );
		$this->assertSame( array(), $this->repo->all_ids() );
		$this->assertSame( 0, $this->adapter->lesson_calls );
	}

	public function test_unavailable_adapter_is_reported() {
		$report = ( new Package_Importer() )->import( $this->package(), 'nothing-loaded' );
		$this->assertFalse( $report->ok() );
		$this->assertSame( 'adapter_unavailable', $report->errors[0]['code'] );
		$this->assertSame( array(), $this->repo->all_ids() );
	}

	public function test_interrupted_import_resumes_without_duplicates() {
		$prev_log                    = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->adapter->fail_on_call = 3;
		$first                       = $this->import();
		ini_set( 'error_log', (string) $prev_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->assertFalse( $first->ok() );
		$this->assertSame( 'import_failed', $first->errors[0]['code'] );
		$this->assertNull( $this->repo->structure( $first->program_id ), 'structure is stored last' );

		$this->adapter->fail_on_call = 0;
		$second                      = $this->import();

		$this->assertTrue( $second->ok(), wp_json_encode( $second->errors ) );
		$this->assertSame( $first->program_id, $second->program_id );
		$this->assertSame( array( 'lesson:yaml-intro' ), array_values( array_filter( $second->created, static fn( $r ) => 0 === strpos( $r, 'lesson:' ) ) ) );
		$this->assertCount( 1, $this->repo->all_ids() );
		$this->assertSame( 2, count( $this->adapter->course_tree( $this->repo->get( $second->program_id )->course_ids[0] )->lessons ) );
		$keys = array();
		foreach ( $this->repo->get( $second->program_id )->course_ids as $cid ) {
			foreach ( $this->adapter->course_tree( $cid )->lessons as $node ) {
				$keys[] = $node->stable_key;
			}
		}
		sort( $keys );
		$this->assertSame( array( 'json-syntax', 'what-is-json', 'yaml-intro' ), $keys, 'one lesson per stable key' );
		$this->assertNotNull( $this->repo->structure( $second->program_id ) );
	}

	public function test_enrolled_learner_progress_is_unchanged_by_reimport() {
		$first  = $this->import();
		$course = $this->repo->get( $first->program_id )->course_ids[0];
		$lesson = $this->adapter->lesson_id( 'what-is-json' );
		$this->adapter->enrol( 7, $course );
		$this->adapter->apply( new Result( Result::LESSON_COMPLETED, 7, new Lms_Object( 'lesson', $lesson ), 'reg', 'stmt' ) );
		$before = $this->adapter->progress( 7, $course );

		$in = $this->inputs();
		$in[2]['what-is-json']['beats'][0]['content'] = 'Rewritten.';
		$report                                       = $this->import( $this->package( $in ) );

		$this->assertSame( array( 'lesson:what-is-json' ), $report->updated );
		$this->assertEquals( $before, $this->adapter->progress( 7, $course ) );
	}

	public function test_script_in_titles_is_stripped() {
		$in             = $this->inputs();
		$in[0]          = str_replace( '<langstring lang="en">JSON basics', '<langstring lang="en">JSON &lt;script&gt;alert(1)&lt;/script&gt;basics', $in[0] );
		$in[0]          = str_replace( '<langstring lang="en">YAML</langstring>', '<langstring lang="en">YAML&lt;script&gt;x&lt;/script&gt;</langstring>', $in[0] );
		$in[1]['rules'] = array( 'Be kind <script>bad()</script>.' );

		$report = $this->import( $this->package( $in ) );

		$this->assertTrue( $report->ok(), wp_json_encode( $report->errors ) );
		$this->assertStringNotContainsString( '<', get_post_field( 'post_title', $report->program_id, 'raw' ) );
		$this->assertStringNotContainsString( '<', $this->adapter->course_specs[1]->title );
		$this->assertStringNotContainsString( '<script', $this->repo->get( $report->program_id )->rules );
	}

	public function test_code_quote_and_image_content_is_stable_under_kses() {
		$in = $this->inputs();
		$in[2]['yaml-intro']['beats'][0]['content'] = "Intro\n\n```yaml\na: \"<b>\"\n```\n\n> Quoted *text*\n\n![Alt text](https://example.org/a.png)\n\n1. one\n2. two";
		$this->import( $this->package( $in ) );
		$content = $this->adapter->lesson_specs[2]->content;

		$this->assertStringContainsString( 'wp:code', $content );
		$this->assertStringContainsString( 'wp:quote', $content );
		$this->assertStringContainsString( 'wp:image', $content );
		$this->assertSame( $content, wp_kses_post( $content ) );
	}

	/**
	 * Whether kses was on at each post save during a callable.
	 */
	private static function kses_during( callable $run ): array {
		$seen = array();
		$spy  = static function () use ( &$seen ): void {
			$seen[] = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		};
		add_action( 'save_post', $spy );
		try {
			$run();
		} finally {
			remove_action( 'save_post', $spy );
		}
		return array_values( array_unique( $seen ) );
	}

	public function test_only_a_trusted_import_suspends_kses_and_it_is_restored() {
		// Each import starts from no program, so every item is written.
		$fresh = function ( bool $trusted ): void {
			$program = $this->repo->find_by_key( 'json-basics' );
			if ( null !== $program ) {
				wp_delete_post( $program, true );
			}
			( new Package_Importer( new Importing_Fake_Adapter() ) )->import( $this->package(), 'fake', $trusted );
		};
		kses_init_filters();
		try {
			$this->assertSame( array( true ), self::kses_during( fn() => $fresh( false ) ) );
			$this->assertSame( array( false ), self::kses_during( fn() => $fresh( true ) ) );
			$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'restored after a trusted import' );
		} finally {
			kses_remove_filters();
		}
		$this->assertSame( array( false ), self::kses_during( fn() => $fresh( true ) ) );
		$this->assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'not turned on when it was off' );
	}

	public function test_kses_is_restored_when_a_trusted_import_throws() {
		kses_init_filters();
		$boom = static function () {
			throw new RuntimeException( 'save failed' );
		};
		add_action( 'save_post', $boom );
		try {
			( new Package_Importer( new Importing_Fake_Adapter() ) )->import( $this->package(), 'fake', true );
			$this->fail( 'the exception escapes the import' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'save failed', $e->getMessage() );
		} finally {
			remove_action( 'save_post', $boom );
		}
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses is on again after the throw' );
		kses_remove_filters();
	}

	public function test_fetcher_streams_https_url_to_a_temp_file() {
		$zip = Package_Writer::write( ...$this->inputs() );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $zip ) {
				unset( $pre, $url );
				file_put_contents( $args['filename'], $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			3
		);

		$path = Package_Fetcher::fetch( 'https://example.org/pkg.zip', new Limits() );
		$this->assertSame( $zip, file_get_contents( $path ) );
		wp_delete_file( $path );
	}

	public function test_fetcher_rejects_other_schemes_and_oversize_and_bad_status() {
		foreach ( array( 'ftp://example.org/p.zip', 'file:///etc/passwd', 'not a url' ) as $url ) {
			try {
				Package_Fetcher::fetch( $url, new Limits() );
				$this->fail( 'accepted ' . $url );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'fetch_url', $e->error_code() );
			}
		}

		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) {
				unset( $pre );
				file_put_contents( $args['filename'], str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			2
		);
		try {
			Package_Fetcher::fetch( 'https://example.org/p.zip', new Limits( 10 ) );
			$this->fail( 'oversize accepted' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'fetch_too_large', $e->error_code() );
		}

		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'cookies'  => array(),
			)
		);
		try {
			Package_Fetcher::fetch( 'https://example.org/p.zip', new Limits() );
			$this->fail( 'bad status accepted' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'fetch_status', $e->error_code() );
		}
	}

	public function test_controller_requires_the_connector_capability() {
		( new Import_Controller() )->register();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/programs/import' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'url' => 'https://example.org/p.zip' ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_controller_imports_a_fetched_package_and_returns_422_for_an_invalid_one() {
		add_filter( 'learning_ledger/integration_dirs', static fn() => array( dirname( __DIR__, 3 ) . '/fixtures/integrations' ) );
		update_option( 'lledger_roles', array( 'connector' ) );
		( new Integration_Loader() )->load();
		( new Import_Controller() )->register();
		do_action( 'rest_api_init' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( Program_Module::CAP );
		wp_set_current_user( $admin );

		$serve = static function ( string $bytes ): callable {
			return static function ( $pre, $args ) use ( $bytes ) {
				unset( $pre );
				file_put_contents( $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			};
		};
		$send  = static function ( string $url ) {
			$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/programs/import' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body(
				wp_json_encode(
					array(
						'url'     => $url,
						'adapter' => 'fake',
					)
				)
			);
			return rest_do_request( $request );
		};

		add_filter( 'pre_http_request', $serve( Package_Writer::write( ...$this->inputs() ) ), 10, 2 );
		kses_init_filters();
		$ok = null;
		try {
			// An administrator can post unfiltered HTML, so the import is trusted; a connector manager without it is not.
			$this->assertSame(
				array( false ),
				self::kses_during(
					static function () use ( $send, &$ok ) {
						$ok = $send( 'https://example.org/p.zip' );
					}
				)
			);
			$manager = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			get_user_by( 'id', $manager )->add_cap( Program_Module::CAP );
			wp_set_current_user( $manager );
			wp_delete_post( (int) $ok->get_data()['program_id'], true );
			$this->assertSame( array( true ), self::kses_during( static fn() => $send( 'https://example.org/p.zip' ) ) );
			wp_set_current_user( $admin );
		} finally {
			kses_remove_filters();
		}
		$this->assertSame( 200, $ok->get_status() );
		$this->assertGreaterThan( 0, $ok->get_data()['program_id'] );
		$this->assertContains( 'course:json', $ok->get_data()['created'] );

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', $serve( 'this is not a zip' ), 10, 2 );
		$bad = $send( 'https://example.org/p.zip' );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'zip_invalid', $bad->get_data()['errors'][0]['code'] );
	}
}
