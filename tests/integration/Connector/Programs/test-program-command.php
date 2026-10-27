<?php
/**
 * Program CLI command tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Programs\Program_Command;
use LearningLedger\Connector\Programs\Program_Module;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- minimal WP_CLI stand-in for this test only.
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {

		public static function error( string $message ): void {
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		public static function success( string $message ): void {}
	}
}

class Program_Command_Test extends WP_UnitTestCase {

	private int $id;

	public function set_up() {
		parent::set_up();
		// Post type only: the command must store sanitized values even without the meta sanitize callbacks.
		( new Program_Module() )->register_post_type();
		$this->id = self::factory()->post->create( array( 'post_type' => 'lledger_program' ) );
	}

	public function test_update_stores_sanitized_values() {
		( new Program_Command() )->update(
			array( (string) $this->id ),
			array(
				'iri-base'          => 'https://a/x',
				'course-iri'        => 'https://a/x/course',
				'course-server-url' => 'https://srv.example/a b',
				'au-url-template'   => 'https://lms.example/launch?au={key}',
			)
		);
		$this->assertSame( 'https://a/x/', get_post_meta( $this->id, '_lledger_iri_base', true ) );
		$this->assertSame( 'https://srv.example/a%20b', get_post_meta( $this->id, '_lledger_course_server_url', true ) );
		$this->assertSame( 'https://a/x/course', get_post_meta( $this->id, '_lledger_course_iri', true ) );
		$this->assertSame( 'https://lms.example/launch?au={key}', get_post_meta( $this->id, '_lledger_au_url_template', true ) );
	}

	public function test_update_rejects_non_http_course_iri() {
		$this->expectException( RuntimeException::class );
		( new Program_Command() )->update( array( (string) $this->id ), array( 'course-iri' => 'javascript:alert(1)' ) );
	}
}
