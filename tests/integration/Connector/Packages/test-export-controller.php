<?php
/**
 * Package export route and round-trip tests.
 *
 * @package LearningLedger
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions -- Test double and fixtures.

use LearningLedger\Cmi5\Course_Structure_Parser;
use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Packages\Export_Controller;
use LearningLedger\Connector\Packages\Package_Builder;
use LearningLedger\Connector\Packages\Package_Importer;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Package\Limits;
use LearningLedger\Package\Package;
use LearningLedger\Package\Package_Validator;
use LearningLedger\Package\Package_Writer;
use LearningLedger\Tests\Fake_Adapter;

/**
 * Fake adapter that keeps a real post for every course and lesson it is asked to write.
 */
class Export_Posting_Adapter extends Fake_Adapter {

	/**
	 * The instance the temporary fixture integration hands out.
	 *
	 * @var Export_Posting_Adapter|null
	 */
	public static ?Export_Posting_Adapter $current = null;

	public function upsert_course( Course_Spec $spec ): int {
		$id = parent::upsert_course( $spec );
		$this->write( $id, 'fake_course', $spec->title, $spec->content, 0, $spec->meta, $spec->status );
		return $id;
	}

	public function upsert_lesson( int $course_id, Lesson_Spec $spec ): int {
		$id = parent::upsert_lesson( $course_id, $spec );
		$this->write( $id, 'fake_lesson', $spec->title, $spec->content, $spec->order, $spec->meta, $spec->status );
		return $id;
	}

	private function write( int $id, string $type, string $title, string $content, int $order, array $meta, string $status ): void {
		if ( ! get_post( $id ) ) {
			wp_insert_post(
				array(
					'import_id'   => $id,
					'post_type'   => $type,
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_title'   => $title,
					'post_content' => $content,
					'menu_order'   => $order,
					'post_status'  => $status,
				)
			)
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, wp_slash( $v ) );
		}
	}
}

class Export_Controller_Test extends WP_UnitTestCase {

	private const BASE = 'https://example.org/courses/demo/';

	private const ROUTE = '/learning-ledger/v1/programs/%d/package';

	private int $program;

	/** @var int[] */
	private array $lessons = array();

	/** @var int[] */
	private array $courses = array();

	private string $dir = '';

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

		$this->dir = trailingslashit( get_temp_dir() ) . 'lledger-export-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir . '/fake' );
		file_put_contents(
			$this->dir . '/fake/bootstrap.php',
			"<?php\nuse LearningLedger\\Connector\\Lms\\Integration_Descriptor;\nreturn new Integration_Descriptor( 'fake', static fn(): bool => true, static fn() => Export_Posting_Adapter::\$current, '1.0', '1.9', static fn(): string => '1.0.0' );\n"
		);
		add_filter( 'learning_ledger/integration_dirs', array( $this, 'dirs' ) );
		add_filter( 'learning_ledger/adapter_post_types', array( $this, 'post_types' ) );
		add_filter( 'learning_ledger/course_post_types', array( $this, 'course_types' ) );
		$this->start_lms();
		$this->fixture();

		( new Export_Controller() )->register();
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->user_with_cap() );
	}

	public function tear_down(): void {
		Export_Posting_Adapter::$current = null;
		foreach ( $this->temp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			}
		}
		if ( is_file( $this->dir . '/fake/bootstrap.php' ) ) {
			unlink( $this->dir . '/fake/bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file.
			rmdir( $this->dir . '/fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- temp dir.
			rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- temp dir.
		}
		parent::tear_down();
	}

	public function dirs(): array {
		return array( $this->dir );
	}

	public function course_types( $types ): array {
		return array_merge( (array) $types, array( 'fake_course' ) );
	}

	public function post_types(): array {
		return array( 'fake' => array( 'fake_course', 'fake_lesson' ) );
	}

	/**
	 * A fresh, empty LMS behind the fixture integration.
	 */
	private function start_lms(): void {
		Export_Posting_Adapter::$current = new Export_Posting_Adapter();
		( new Integration_Loader() )->load();
	}

	private function user_with_cap(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( Program_Module::CAP );
		return $user;
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

	private function lesson_post( int $course_id, string $key, string $title, string $content, int $order ): void {
		$id = Export_Posting_Adapter::$current->upsert_lesson( $course_id, new Lesson_Spec( $key, $title, $content, $order, 'publish', array() ) );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_excerpt' => 'About ' . $title,
				)
			)
		);
		update_post_meta( $id, '_lledger_minutes', 4 );
		update_post_meta( $id, '_lledger_sources', wp_slash( '[{"title":"RFC 8259","url":"https://www.rfc-editor.org/rfc/rfc8259"}]' ) );
		update_post_meta( $id, '_lledger_starter_files', wp_slash( '[{"path":"config.json","content":"{}"}]' ) );
		$this->lessons[ $key ] = $id;
	}

	private function fixture(): void {
		$ids = array();
		foreach ( array(
			'path-a' => 'Path A',
			'path-b' => 'Path B',
		) as $key => $title ) {
			$cid = Export_Posting_Adapter::$current->upsert_course( new Course_Spec( $key, $title, '', 'publish', array() ) );
			wp_update_post(
				array(
					'ID'           => $cid,
					'post_excerpt' => 'Tagline of ' . $title,
				)
			);
			$this->courses[ $key ] = $cid;
			$ids[ $cid ]           = $key;
		}
		update_post_meta( $this->courses['path-a'], '_lledger_preview', wp_slash( '{"kind":"html","files":[{"path":"index.html","content":"<p>hi</p>"}]}' ) );
		update_post_meta( $this->courses['path-a'], '_lledger_landing', wp_slash( '{"headline":"Learn A"}' ) );
		update_post_meta( $this->courses['path-a'], '_lledger_final_files', wp_slash( '[{"path":"config.json","content":"{\\"port\\": 8080}"}]' ) );
		update_post_meta( $this->courses['path-b'], '_lledger_placement_hints', 'Start here if you know JSON.' );

		$this->lesson_post( $this->courses['path-a'], 'lesson-one', 'Lesson One', $this->beat( 'quotes', $this->para( 'Keys use <strong>double</strong> quotes.' ) ) . $this->checkpoint(), 1 );
		$this->lesson_post( $this->courses['path-a'], 'lesson-two', 'Lesson Two', $this->beat( 'types', $this->para( 'Six types.' ) ) . $this->bug(), 2 );
		$this->lesson_post( $this->courses['path-b'], 'lesson-three', 'Lesson Three', $this->beat( 'nulls', $this->para( 'Null is a value.' ) ), 1 );

		$this->program = self::factory()->post->create(
			array(
				'post_type'   => Program_Repository::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Demo program',
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

	private function get( int $id, array $query = array(), array $headers = array(), bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', sprintf( self::ROUTE, $id ) );
		$request->set_query_params( $query );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_do_request( $request );
	}

	private function package( string $zip ): Package {
		$path         = tempnam( sys_get_temp_dir(), 'llx' );
		$this->temp[] = $path;
		file_put_contents( $path, $zip );
		return Package::from_zip( $path, new Limits() );
	}

	public function test_a_user_without_the_connector_capability_is_refused() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->get( $this->program )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( $this->program )->get_status() );
	}

	public function test_a_cookie_request_needs_the_rest_nonce() {
		$this->assertSame( 403, $this->get( $this->program, array(), array(), false )->get_status() );
		$this->assertSame( 200, $this->get( $this->program )->get_status() );
	}

	public function test_full_flavour_is_a_zip_with_secrets_and_cache_headers() {
		$res = $this->get( $this->program );
		$this->assertSame( 200, $res->get_status() );
		$headers = $res->get_headers();
		$this->assertSame( 'application/zip', $headers['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^"[0-9a-f]{64}"$/', $headers['ETag'] );
		$this->assertSame( '1', $headers['X-Package-Version'] );
		$this->assertStringContainsString( 'demo-full.zip', $headers['Content-Disposition'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );

		$zip = $res->get_data();
		$this->assertSame( Package_Writer::etag( $zip ), $headers['ETag'] );
		$pkg = $this->package( $zip );
		$this->assertSame( array(), Package_Validator::validate( $pkg, 'full' )->errors() );
		$this->assertSame( 'RUBRIC-SECRET', $pkg->lesson_json( 'lesson-one' )['checkpoint']['rubric'] );
	}

	public function test_public_flavour_has_no_secrets() {
		$res = $this->get( $this->program, array( 'flavour' => 'public' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'demo-public.zip', $res->get_headers()['Content-Disposition'] );
		$pkg = $this->package( $res->get_data() );
		$this->assertSame( array(), Package_Validator::validate( $pkg, 'public' )->errors() );
		// Spec 0.2: the rubric is not secret; the planted bug's explanation is.
		$this->assertStringContainsString( 'RUBRIC-SECRET', $pkg->file( 'cxm/lessons/lesson-one.json' ) );
		$this->assertStringNotContainsString( 'EXPLANATION-SECRET', $pkg->file( 'cxm/lessons/lesson-two.json' ) );
	}

	public function test_an_unknown_flavour_or_program_is_refused() {
		$this->assertSame( 400, $this->get( $this->program, array( 'flavour' => 'secret' ) )->get_status() );
		$this->assertSame( 404, $this->get( 999999 )->get_status() );
	}

	public function test_a_matching_if_none_match_gets_304_without_a_body() {
		$etag = $this->get( $this->program )->get_headers()['ETag'];

		$res = $this->get( $this->program, array(), array( 'If-None-Match' => $etag ) );
		$this->assertSame( 304, $res->get_status() );
		$this->assertNull( $res->get_data() );
		$this->assertSame( $etag, $res->get_headers()['ETag'] );

		$this->assertSame( 304, $this->get( $this->program, array(), array( 'If-None-Match' => 'W/' . $etag ) )->get_status() );
		$this->assertSame( 304, $this->get( $this->program, array(), array( 'If-None-Match' => '"other", ' . $etag ) )->get_status() );
		$this->assertSame( 200, $this->get( $this->program, array(), array( 'If-None-Match' => '"other"' ) )->get_status() );

		$public = $this->get( $this->program, array( 'flavour' => 'public' ), array( 'If-None-Match' => $etag ) );
		$this->assertSame( 200, $public->get_status(), 'the full flavour ETag does not match the public flavour' );
	}

	public function test_the_zip_bytes_are_served_raw_and_a_304_serves_nothing() {
		$res = $this->get( $this->program );
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $res, new WP_REST_Request( 'GET', sprintf( self::ROUTE, $this->program ) ), rest_get_server() );
		$this->assertSame( $res->get_data(), ob_get_clean() );
		$this->assertTrue( $served );

		$not_modified = $this->get( $this->program, array(), array( 'If-None-Match' => $res->get_headers()['ETag'] ) );
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $not_modified, new WP_REST_Request( 'GET', sprintf( self::ROUTE, $this->program ) ), rest_get_server() );
		$this->assertSame( '', ob_get_clean() );
		$this->assertTrue( $served );

		$error = $this->get( 999999 );
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $error, new WP_REST_Request( 'GET', sprintf( self::ROUTE, 999999 ) ), rest_get_server() );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( $served, 'errors are left to the JSON serializer' );
	}

	/**
	 * Exports the program, imports the package into an empty LMS and exports again.
	 *
	 * Compared: every lesson (beats, checkpoint with rubric, planted bug, minutes, sources, starter files,
	 * extensions), the whole course document (license, custody, rules, placement guide, paths with tagline
	 * and placement hints, path previews, landing pages and final files) and the course structure.
	 * A lesson's `referenceSolution` is carried too. An empty program takes its AU URL template from the
	 * package; the course server URL is set by the operator after an import.
	 */
	public function test_round_trip_export_equals_import() {
		update_post_meta( $this->program, '_lledger_licence', 'CC-BY-4.0' );
		$first = $this->package( $this->get( $this->program )->get_data() );

		// An author-supplied package may carry a reference solution next to the exported content.
		$lesson_one                      = $first->lesson_json( 'lesson-one' );
		$lesson_one['referenceSolution'] = array( 'files' => array( 'config.json' => '{"port": 8080}' ) );
		$lessons                         = array();
		foreach ( $first->lesson_keys() as $key ) {
			$lessons[ $key ] = 'lesson-one' === $key ? $lesson_one : $first->lesson_json( $key );
		}
		$authored = Package_Writer::write( $first->cmi5_xml(), $first->course_json(), $lessons, array() );
		$this->assertSame( array(), Package_Validator::validate( $this->package( $authored ), 'full' )->errors() );

		// Start from an empty site: no program, courses or lessons.
		wp_delete_post( $this->program, true );
		foreach ( array_merge( $this->courses, $this->lessons ) as $id ) {
			wp_delete_post( $id, true );
		}
		$this->start_lms();

		$report = ( new Package_Importer() )->import( $this->package( $authored ), 'fake' );
		$this->assertTrue( $report->ok(), wp_json_encode( $report->to_array() ) );
		$program = ( new Program_Repository() )->find_by_key( 'demo' );
		$this->assertNotNull( $program );

		// Where the course server lives is deployment setup, not package content: an import leaves it empty.
		$this->assertSame( '', ( new Program_Repository() )->get( $program )->course_server_url );
		$this->assertSame( 'https://demo.example.org/cmi5/launch?au={key}', ( new Program_Repository() )->get( $program )->au_url_template );
		update_post_meta( $program, '_lledger_course_server_url', 'https://demo.example.org/mcp' );

		$exported = $this->get( $program );
		$this->assertSame( 200, $exported->get_status(), wp_json_encode( $exported->get_data() ) );
		$second = $this->package( $exported->get_data() );
		$this->assertSame( array(), Package_Validator::validate( $second, 'full' )->errors() );

		// Lessons: identical, the reference solution included.
		$this->assertSame( $first->lesson_keys(), $second->lesson_keys() );
		$this->assertArrayHasKey( 'referenceSolution', $lesson_one );
		$this->assertSame( array( 'config.json' => '{}' ), $first->lesson_json( 'lesson-one' )['starterFiles'] );
		foreach ( $first->lesson_keys() as $key ) {
			$this->assertSame( $lessons[ $key ], $second->lesson_json( $key ), $key );
		}
		$this->assertSame( $lesson_one['referenceSolution'], $second->lesson_json( 'lesson-one' )['referenceSolution'] );

		// Course document: identical.
		$a = $first->course_json();
		$this->assertSame( 'CC-BY-4.0', $a['license'] );
		$this->assertSame( 'Start here if you know JSON.', $a['paths']['path-b']['placementHints'] );
		$this->assertSame( array( 'preview', 'finalFiles', 'landing' ), array_keys( $a['extensions'][ Package_Builder::EXTENSION ]['paths']['path-a'] ) );
		$this->assertSame( $a, $second->course_json() );

		// Course structure: same blocks and AUs, ids, order, titles and moveOn.
		$summary = static function ( Package $p ): array {
			$s   = Course_Structure_Parser::parse( $p->cmi5_xml() );
			$out = array( 'course' => $s->course_id() );
			foreach ( $s->aus() as $au ) {
				$out['aus'][ $au->id() ] = array( $au->key(), $au->title(), $au->move_on(), $au->lesson_src(), $au->role() );
			}
			foreach ( $s->blocks() as $block ) {
				$out['blocks'][ $block->id() ] = array( $block->key(), $block->title() );
			}
			return $out;
		};
		$this->assertSame( $summary( $first ), $summary( $second ) );

		// The program-level data the importer does keep.
		$kept = ( new Program_Repository() )->get( $program );
		$this->assertSame( self::BASE, $kept->iri_base );
		$this->assertSame( "Be brief.\nAsk before telling.", $kept->rules );
		$this->assertSame( 'server-kept', $kept->custody );
	}
}
