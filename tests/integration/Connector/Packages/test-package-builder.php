<?php
/**
 * Package_Builder tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Packages\Package_Builder;
use LearningLedger\Connector\Programs\Program_Iris;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Package\Secrets;
use LearningLedger\Util\Invalid_Input;

class Package_Builder_Test extends WP_UnitTestCase {

	private const BASE = 'https://example.org/courses/demo/';

	private int $program;

	/** @var int[] Lesson post ids by lesson key. */
	private array $lessons = array();

	/** @var int[] Course post ids by path key. */
	private array $courses = array();

	private array $temp = array();

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'fake_course', array( 'public' => false ) );
		register_post_type( 'fake_lesson', array( 'public' => false ) );
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		update_option( 'lledger_roles', array( 'connector' ) );
		delete_option( 'lledger_integration_state' );
		add_filter( 'learning_ledger/integration_dirs', array( $this, 'dirs' ) );
		add_filter( 'learning_ledger/adapter_post_types', array( $this, 'post_types' ) );
		( new Integration_Loader() )->load();
		$this->fixture();
	}

	public function tear_down(): void {
		foreach ( $this->temp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			}
		}
		parent::tear_down();
	}

	public function dirs(): array {
		return array( dirname( __DIR__, 3 ) . '/fixtures/integrations' );
	}

	public function post_types(): array {
		return array( 'fake' => array( 'fake_course', 'fake_lesson' ) );
	}

	/**
	 * A post whose id is the id the fake adapter handed out.
	 *
	 * @param int   $id   Id.
	 * @param array $args Post args.
	 *
	 * @return void
	 */
	private function post_with_id( int $id, array $args ): void {
		$args['post_content'] = wp_slash( $args['post_content'] ?? '' );
		$made                 = wp_insert_post(
			array_merge(
				array(
					'import_id'   => $id,
					'post_status' => 'publish',
				),
				$args
			)
		);
		$this->assertSame( $id, $made );
	}

	private function beat( string $id, string $inner ): string {
		return '<!-- wp:learning-ledger/beat ' . wp_json_encode(
			array(
				'id'       => $id,
				'goal'     => 'Goal of ' . $id,
				'question' => 'Question of ' . $id . '?',
			)
		) . ' -->' . $inner . '<!-- /wp:learning-ledger/beat -->';
	}

	private function para( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
	}

	private function checkpoint(): string {
		$attrs = array(
			'key'         => 'quotes-check',
			'kind'        => 'free-text',
			'question'    => 'Explain the quotes.',
			'maxAttempts' => 3,
			'checks'      => array(),
			'branches'    => array(
				array(
					'key'     => 'got-it',
					'when'    => 'The learner explains it.',
					'success' => true,
					'to'      => 'lesson-two',
					'message' => 'Correct.',
				),
				array(
					'key'     => 'retry',
					'when'    => 'The learner does not.',
					'success' => false,
					'to'      => '',
					'message' => 'Look again.',
				),
			),
		);
		return '<!-- wp:learning-ledger/checkpoint ' . wp_json_encode( $attrs ) . ' -->'
			. '<!-- wp:learning-ledger/rubric {"text":"RUBRIC-SECRET"} /-->'
			. '<!-- /wp:learning-ledger/checkpoint -->';
	}

	private function bug(): string {
		$attrs = array(
			'key'         => 'port-string',
			'file'        => 'config.json',
			'code'        => '{"port": "8080"}',
			'hint'        => 'Look at the quotes.',
			'revealAfter' => 3,
			'explanation' => 'EXPLANATION-SECRET',
			'fixChecks'   => array(
				array(
					'kind'    => 'contains',
					'value'   => '"port": 8080',
					'message' => 'Use a bare number.',
				),
			),
		);
		return '<!-- wp:learning-ledger/planted-bug ' . wp_json_encode( $attrs ) . ' /-->';
	}

	private function lesson_post( int $course_id, string $key, string $title, string $content, int $order ): int {
		$adapter = Integration_Loader::adapter( 'fake' );
		$id      = $adapter->upsert_lesson( $course_id, new Lesson_Spec( $key, $title, '', $order, 'publish', array() ) );
		$this->post_with_id(
			$id,
			array(
				'post_type'    => 'fake_lesson',
				'post_title'   => $title,
				'post_excerpt' => 'About ' . $title,
				'post_content' => $content,
			)
		);
		update_post_meta( $id, '_lledger_minutes', 4 );
		update_post_meta( $id, '_lledger_sources', wp_slash( '[{"title":"RFC 8259","url":"https://www.rfc-editor.org/rfc/rfc8259"}]' ) );
		update_post_meta( $id, '_lledger_starter_files', wp_slash( '[{"path":"config.json","content":"{}"}]' ) );
		$this->lessons[ $key ] = $id;
		return $id;
	}

	private function fixture(): void {
		$adapter = Integration_Loader::adapter( 'fake' );
		$paths   = array(
			'path-a' => 'Path A',
			'path-b' => 'Path B',
		);
		$ids     = array();
		foreach ( $paths as $key => $title ) {
			$cid = $adapter->upsert_course( new Course_Spec( $key, $title, '', 'publish', array() ) );
			$this->post_with_id(
				$cid,
				array(
					'post_type'    => 'fake_course',
					'post_title'   => $title,
					'post_excerpt' => 'Tagline of ' . $title,
				)
			);
			$this->courses[ $key ] = $cid;
			$ids[ $cid ]           = $key;
		}
		update_post_meta( $this->courses['path-a'], '_lledger_preview', wp_slash( '{"kind":"html","files":[{"path":"index.html","content":"<p>hi</p>"}]}' ) );
		update_post_meta( $this->courses['path-a'], '_lledger_landing', wp_slash( '{"headline":"Learn A"}' ) );

		$this->lesson_post(
			$this->courses['path-a'],
			'lesson-one',
			'Lesson One',
			$this->beat( 'quotes', $this->para( 'Keys use <strong>double</strong> quotes.' ) ) . $this->checkpoint(),
			1
		);
		$this->lesson_post(
			$this->courses['path-a'],
			'lesson-two',
			'Lesson Two',
			$this->beat( 'types', $this->para( 'Six types.' ) ) . $this->bug(),
			2
		);
		$this->lesson_post(
			$this->courses['path-b'],
			'lesson-three',
			'Lesson Three',
			$this->beat( 'nulls', $this->para( 'Null is a value.' ) ),
			1
		);

		$this->program = self::factory()->post->create(
			array(
				'post_type'    => Program_Repository::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Demo program',
				'post_excerpt' => 'A demo program.',
			)
		);
		$meta          = array(
			'_lledger_stable_key'        => 'demo',
			'_lledger_iri_base'          => self::BASE,
			'_lledger_courses'           => array_keys( $ids ),
			'_lledger_path_keys'         => $ids,
			'_lledger_placement'         => wp_slash( '{"au_key":"placement","guide":{"intro":"Ask two short questions.","maxQuestions":2}}' ),
			'_lledger_rules'             => "Be brief.\nAsk before telling.",
			'_lledger_custody'           => 'server-kept',
			'_lledger_course_server_url' => 'https://demo.example.org/mcp',
			'_lledger_au_url_template'   => 'https://demo.example.org/cmi5/launch?au={key}',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $this->program, $k, $v );
		}
	}

	private function package( string $zip ): Package {
		$path         = tempnam( sys_get_temp_dir(), 'llb' );
		$this->temp[] = $path;
		file_put_contents( $path, $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		return Package::from_zip( $path, new Limits() );
	}

	private function build( string $flavour = 'full' ) {
		return ( new Package_Builder() )->build( $this->program, $flavour );
	}

	private function all_bytes( Package $p ): string {
		$all = '';
		foreach ( $p->entries() as $entry ) {
			$all .= $p->file( $entry );
		}
		return $all;
	}

	public function test_full_package_validates_and_has_secrets() {
		$built = $this->build( 'full' );
		$this->assertSame( 'full', $built->flavour );
		$this->assertSame( 1, $built->version );
		$this->assertSame( array(), $built->warnings );
		$this->assertSame( Package_Writer::etag( $built->zip ), $built->etag );

		$pkg = $this->package( $built->zip );
		$res = Package_Validator::validate( $pkg, 'full' );
		$this->assertSame( array(), $res->errors() );

		$one = $pkg->lesson_json( 'lesson-one' );
		$this->assertSame( 'RUBRIC-SECRET', $one['checkpoint']['rubric'] );
		$this->assertSame( 'quotes', $one['beats'][0]['key'] );
		$this->assertSame( 'Goal of quotes', $one['beats'][0]['goal'] );
		$this->assertSame( 'Keys use **double** quotes.', $one['beats'][0]['content'] );
		$this->assertSame( 'Question of quotes?', $one['beats'][0]['question'] );
		$this->assertSame( 4, $one['minutes'] );
		$this->assertSame( array( 'config.json' => '{}' ), $one['starterFiles'] );
		$this->assertSame( 'RFC 8259', $one['sources'][0]['title'] );
		$this->assertSame( 3, $one['checkpoint']['maxAttempts'] );
		$this->assertNull( $one['checkpoint']['branches'][1]['to'] );
		$this->assertSame( 'lesson-two', $one['checkpoint']['branches'][0]['to'] );

		$two = $pkg->lesson_json( 'lesson-two' );
		$this->assertSame( 'EXPLANATION-SECRET', $two['bug']['explanation'] );
		$this->assertSame( 'config.json', $two['bug']['file'] );
		$this->assertSame( 'contains', $two['bug']['fixChecks'][0]['type'] );
		$this->assertSame( 'config.json', $two['bug']['fixChecks'][0]['file'] );
		$this->assertSame( 3, $two['bug']['revealAfter'] );

		$course = $pkg->course_json();
		$this->assertSame( 'authoring', $course['flavour'] );
		$this->assertSame( '1.0', $course['specVersion'] );
		$this->assertSame( 'server-kept', $course['custody'] );
		$this->assertSame( array( 'Be brief.', 'Ask before telling.' ), $course['rules'] );
		$this->assertSame( 2, $course['placement']['maxQuestions'] );
		$this->assertSame( 'Tagline of Path A', $course['paths']['path-a']['tagline'] );
		$this->assertSame( array( 'path-a', 'path-b' ), array_keys( $course['paths'] ) );
		$ext = $course['extensions']['https://github.com/derintolu/learning-ledger/extensions/course']['paths']['path-a'];
		$this->assertSame( 'html', $ext['preview']['kind'] );
		$this->assertSame( 'Learn A', $ext['landing']['headline'] );

		$this->assertNotNull( ( new Program_Repository() )->structure( $this->program ) );
	}

	public function test_public_package_validates_without_secrets() {
		$built = $this->build( 'public' );
		$this->assertSame( 'public', $built->flavour );
		$pkg = $this->package( $built->zip );
		$this->assertSame( array(), Package_Validator::validate( $pkg, 'public' )->errors() );
		$bytes = $this->all_bytes( $pkg );
		$this->assertStringNotContainsString( 'RUBRIC-SECRET', $bytes );
		$this->assertStringNotContainsString( 'EXPLANATION-SECRET', $bytes );
		foreach ( $pkg->lesson_keys() as $key ) {
			$this->assertFalse( Secrets::has_secrets( $pkg->lesson_json( $key ) ), $key );
		}
		$this->assertSame( 'distribution', $pkg->course_json()['flavour'] );
		$this->assertSame( 'quotes-check', $pkg->lesson_json( 'lesson-one' )['checkpoint']['key'] );
	}

	public function test_public_package_drops_final_files_and_fallback_options() {
		update_post_meta( $this->courses['path-a'], '_lledger_final_files', wp_slash( '[{"path":"a.txt","content":"FINALFILES-SENTINEL"}]' ) );
		wp_update_post(
			array(
				'ID'           => $this->lessons['lesson-one'],
				'post_content' => wp_slash( str_replace( '"maxAttempts":3', '"maxAttempts":3,"fallbackOptions":[{"label":"FALLBACK-SENTINEL","outcome":"x"}]', get_post_field( 'post_content', $this->lessons['lesson-one'] ) ) ),
			)
		);
		$full = $this->package( $this->build( 'full' )->zip );
		$this->assertStringContainsString( 'FINALFILES-SENTINEL', $this->all_bytes( $full ) );
		$this->assertStringContainsString( 'FALLBACK-SENTINEL', $this->all_bytes( $full ) );

		$public = $this->package( $this->build( 'public' )->zip );
		$this->assertSame( array(), Package_Validator::validate( $public, 'public' )->errors() );
		$bytes = $this->all_bytes( $public );
		$this->assertStringNotContainsString( 'FINALFILES-SENTINEL', $bytes );
		$this->assertStringNotContainsString( 'FALLBACK-SENTINEL', $bytes );
		$this->assertStringContainsString( 'Learn A', $bytes );
	}

	public function test_au_ids_and_activity_scheme() {
		$pkg       = $this->package( $this->build()->zip );
		$structure = \LearningLedger\Cmi5\Course_Structure_Parser::parse( $pkg->cmi5_xml() );
		$program   = ( new Program_Repository() )->get( $this->program );

		$this->assertSame( 'https://example.org/courses/demo', $structure->course_id() );
		foreach ( array( 'lesson-one', 'lesson-two', 'lesson-three' ) as $key ) {
			$au = $structure->au_by_key( $key );
			$this->assertNotNull( $au, $key );
			$this->assertSame( self::BASE . 'lessons/' . $key, $au->id() );
			$this->assertSame( Program_Iris::au_iri( $program, $key ), $au->id() );
			$this->assertSame( 'https://demo.example.org/cmi5/launch?au=' . $key, $au->url() );
			$this->assertSame( 'OwnWindow', $au->launch_method() );
			$this->assertSame( 'http://adlnet.gov/expapi/activities/lesson', $au->activity_type() );
			$this->assertSame( 'cxm/lessons/' . $key . '.json', $au->lesson_src() );
			$this->assertSame( self::BASE . 'lessons/' . $key . '/au', Program_Iris::au_activity_id( $au->id() ) );
		}
		$blocks = array_map( static fn( $b ) => $b->id(), $structure->blocks() );
		$this->assertSame( array( self::BASE . 'paths/path-a', self::BASE . 'paths/path-b' ), array_values( $blocks ) );
		$this->assertStringContainsString( 'specVersion="1.0"', $pkg->cmi5_xml() );
		$this->assertStringContainsString( 'mcp="https://demo.example.org/mcp"', $pkg->cmi5_xml() );
		$this->assertStringContainsString( 'src="cxm/course.json#/paths/path-a"', $pkg->cmi5_xml() );
	}

	public function test_placement_au_is_first_and_not_applicable() {
		$pkg       = $this->package( $this->build()->zip );
		$structure = \LearningLedger\Cmi5\Course_Structure_Parser::parse( $pkg->cmi5_xml() );
		$first     = $structure->children()[0];
		$this->assertInstanceOf( \LearningLedger\Cmi5\Au::class, $first );
		$this->assertSame( self::BASE . 'placement', $first->id() );
		$this->assertSame( 'placement', $first->role() );
		$this->assertSame( 'NotApplicable', $first->move_on() );
		$this->assertSame( 'https://demo.example.org/cmi5/launch?au=placement', $first->url() );
	}

	public function test_no_placement_au_when_the_program_has_none() {
		update_post_meta( $this->program, '_lledger_placement', '' );
		$pkg       = $this->package( $this->build()->zip );
		$structure = \LearningLedger\Cmi5\Course_Structure_Parser::parse( $pkg->cmi5_xml() );
		$this->assertInstanceOf( \LearningLedger\Cmi5\Block::class, $structure->children()[0] );
		$this->assertNull( $structure->find_au( self::BASE . 'placement' ) );
		$this->assertArrayHasKey( 'placement', $pkg->course_json() );
	}

	public function test_moveon_by_checkpoint_presence() {
		$pkg       = $this->package( $this->build()->zip );
		$structure = \LearningLedger\Cmi5\Course_Structure_Parser::parse( $pkg->cmi5_xml() );
		$this->assertSame( 'CompletedAndPassed', $structure->au_by_key( 'lesson-one' )->move_on() );
		$this->assertSame( 'Completed', $structure->au_by_key( 'lesson-two' )->move_on() );
		$this->assertSame( 'Completed', $structure->au_by_key( 'lesson-three' )->move_on() );
	}

	public function test_same_content_same_etag_and_version_bumps_on_change() {
		$first  = $this->build();
		$second = $this->build();
		$this->assertSame( $first->etag, $second->etag );
		$this->assertSame( $first->zip, $second->zip );
		$this->assertSame( 1, $second->version );

		$public = $this->build( 'public' );
		$this->assertSame( 1, $public->version );
		$this->assertNotSame( $first->etag, $public->etag );

		wp_update_post(
			array(
				'ID'           => $this->lessons['lesson-three'],
				'post_content' => wp_slash( $this->beat( 'nulls', $this->para( 'Null is a value, not a string.' ) ) ),
			)
		);
		$third = $this->build();
		$this->assertNotSame( $first->etag, $third->etag );
		$this->assertSame( 2, $third->version );

		$program = ( new Program_Repository() )->get( $this->program );
		$this->assertSame( 2, $program->version );
		$this->assertSame( $third->etag, $program->etag );
		$this->assertSame( '1.0.2', $this->package( $third->zip )->course_json()['version'] );
		$this->assertSame( 2, $this->build( 'public' )->version );
	}

	public function test_unallowlisted_block_skipped_with_warning() {
		wp_update_post(
			array(
				'ID'           => $this->lessons['lesson-three'],
				'post_content' => $this->beat(
					'nulls',
					$this->para( 'Null is a value.' ) . '<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>x</td></tr></tbody></table></figure><!-- /wp:table -->'
				),
			)
		);
		$built = $this->build();
		$this->assertCount( 1, $built->warnings );
		$this->assertStringContainsString( 'core/table', $built->warnings[0] );
		$this->assertStringContainsString( 'nulls', $built->warnings[0] );
		$lesson = $this->package( $built->zip )->lesson_json( 'lesson-three' );
		$this->assertSame( 'Null is a value.', $lesson['beats'][0]['content'] );
	}

	public function test_missing_stable_key_fails() {
		$id = $this->lesson_post( $this->courses['path-b'], '', 'Keyless', $this->beat( 'x', $this->para( 'X.' ) ), 2 );
		try {
			$this->build();
			$this->fail( 'Expected Invalid_Input.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'missing_stable_key', $e->error_code() );
			$this->assertSame( (string) $id, $e->path() );
		}
	}

	public function test_missing_path_key_fails() {
		$adapter = Integration_Loader::adapter( 'fake' );
		$cid     = $adapter->upsert_course( new Course_Spec( '', 'Keyless path', '', 'publish', array() ) );
		$this->post_with_id(
			$cid,
			array(
				'post_type'  => 'fake_course',
				'post_title' => 'Keyless path',
			)
		);
		update_post_meta( $this->program, '_lledger_courses', array( $cid ) );
		update_post_meta( $this->program, '_lledger_path_keys', array() );
		try {
			$this->build();
			$this->fail( 'Expected Invalid_Input.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'missing_stable_key', $e->error_code() );
			$this->assertSame( (string) $cid, $e->path() );
		}
	}

	public function test_invalid_content_fails_validation_and_changes_nothing() {
		wp_update_post(
			array(
				'ID'           => $this->lessons['lesson-three'],
				'post_content' => $this->para( 'No beats here.' ),
			)
		);
		try {
			$this->build();
			$this->fail( 'Expected Invalid_Input.' );
		} catch ( Invalid_Input $e ) {
			$this->assertSame( 'validation', $e->error_code() );
			$this->assertStringContainsString( 'lesson-three', $e->getMessage() );
		}
		$program = ( new Program_Repository() )->get( $this->program );
		$this->assertSame( 0, $program->version );
		$this->assertNull( ( new Program_Repository() )->structure( $this->program ) );
	}
}
