<?php
/**
 * Direction 2 setting tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Outbox\Direction2;
use LearningLedger\Util\Invalid_Input;

class Direction2_Test extends WP_UnitTestCase {

	private int $course;

	public function set_up() {
		parent::set_up();
		$this->course = self::factory()->post->create();
	}

	private function program( array $meta ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'lledger_program' ) );
		update_post_meta( $id, '_lledger_iri_base', 'https://author.test/prog/' );
		update_post_meta( $id, '_lledger_courses', array( $this->course ) );
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	public function test_on_by_default_for_an_ordinary_course() {
		$this->assertTrue( Direction2::is_on( $this->course ) );
	}

	public function test_on_by_default_in_a_program_without_course_server() {
		$this->program( array() );
		$this->assertTrue( Direction2::is_on( $this->course ) );
	}

	public function test_off_by_default_in_a_program_with_a_course_server() {
		$this->program( array( '_lledger_course_server_url' => 'https://server.test/' ) );
		$this->assertFalse( Direction2::is_on( $this->course ) );
	}

	public function test_explicit_setting_wins_over_the_default() {
		$this->program( array( '_lledger_course_server_url' => 'https://server.test/' ) );
		Direction2::set( $this->course, 'on' );
		$this->assertSame( 'on', get_post_meta( $this->course, Direction2::META, true ) );
		$this->assertTrue( Direction2::is_on( $this->course ) );

		Direction2::set( $this->course, 'off' );
		$this->assertFalse( Direction2::is_on( $this->course ) );
	}

	public function test_forced_off_in_a_player_program() {
		$this->program( array( '_lledger_player' => 1 ) );
		Direction2::set( $this->course, 'on' );
		$this->assertFalse( Direction2::is_on( $this->course ) );
		$this->assertTrue( Direction2::is_forced_off( $this->course ) );
	}

	public function test_set_rejects_other_values() {
		$this->expectException( Invalid_Input::class );
		Direction2::set( $this->course, 'maybe' );
	}
}
