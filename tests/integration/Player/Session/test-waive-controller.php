<?php
/**
 * Waive route tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Cmi5\Au;
use LearningLedger\Cmi5\Vocab;
use LearningLedger\Connector\Ledger\Registration_Map;
use LearningLedger\Connector\Ledger\Waiver_Ledger;
use LearningLedger\Connector\Programs\Program_Module;
use LearningLedger\Player\Session\Player_Evaluator;
use LearningLedger\Xapi\Statement_Validator;

require_once __DIR__ . '/test-player-evaluator.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test class.
class Waive_Controller_Test extends Lledger_Session_Test_Case {

	private function manager(): int {
		$admin = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $admin )->add_cap( Program_Module::CAP );
		return $admin;
	}

	private function waive( array $body, bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/learning-ledger/v1/cmi5/waive' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_waive_writes_waived_and_the_sensei_lesson_completes_with_the_reason() {
		$cxm    = $this->import_cxm();
		$lesson = $this->cxm_lesson( $cxm, 'what-is-json' );
		$au     = $this->cxm_au( $cxm, 'what-is-json' );
		$calls  = array();
		add_action(
			'learning_ledger/cmi5_registration_created',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			3
		);
		wp_set_current_user( $this->manager() );

		$res = $this->waive(
			array(
				'user'   => $this->learner,
				'lesson' => $lesson,
				'reason' => 'Equivalent AU',
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$registration = ( new Registration_Map() )->find( $this->learner, 'cmi5:' . $cxm );
		$this->assertSame( $registration, $res->get_data()['registration'] );
		$this->assertSame( array( array( $this->learner, $cxm, $registration ) ), $calls, 'the registration is created and announced' );

		$waived = $this->statements( Vocab::WAIVED );
		$this->assertCount( 1, $waived );
		Statement_Validator::validate( $waived[0], '1.0.3' );
		$this->assertSame( $res->get_data()['statement_id'], $waived[0]['id'] );
		$this->assertSame( Au::activity_id( $au ), $waived[0]['object']['id'] );
		$this->assertSame( $this->actor( $this->learner ), array_intersect_key( $waived[0]['actor'], array_flip( array( 'objectType', 'account' ) ) ) );
		$this->assertSame( $registration, $waived[0]['context']['registration'] );
		$this->assertSame( 'Equivalent AU', $waived[0]['result']['extensions'][ Vocab::EXT_REASON ] );
		$this->assertTrue( $waived[0]['result']['success'] );
		$this->assertTrue( $waived[0]['result']['completion'] );

		$this->assertTrue( Sensei_Utils::user_completed_lesson( $lesson, $this->learner ), 'the inbox completed the Sensei lesson' );
		$ledger = ( new Waiver_Ledger() )->get( $this->learner, $lesson );
		$this->assertNotNull( $ledger );
		$this->assertSame( 'Equivalent AU', $ledger['reason'] );
		$this->assertSame( $waived[0]['id'], $ledger['statement_id'] );
		$this->assertSame( $registration, $ledger['registration'] );
	}

	public function test_waive_on_a_plain_cmi5_lesson_uses_the_learners_registration() {
		$this->launch_au( self::A1 );
		$registration = $this->registration_of( $this->learner, $this->t59 );
		wp_set_current_user( $this->manager() );
		$res = $this->waive(
			array(
				'user'   => $this->learner,
				'lesson' => $this->lessons[ self::A2 ],
				'reason' => 'Administrative',
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$waived = $this->about( Vocab::WAIVED, Au::activity_id( self::A2 ) );
		$this->assertCount( 1, $waived );
		$this->assertSame( $registration, $waived[0]['context']['registration'] );
		$this->assertSame( Player_Evaluator::waiver_session( $registration, self::A2 ), $waived[0]['context']['extensions'][ Vocab::EXT_SESSIONID ] );
		$this->assertSame( self::A2, $waived[0]['context']['contextActivities']['grouping'][0]['id'] );
	}

	public function test_a_second_waive_is_409_and_writes_nothing() {
		$cxm    = $this->import_cxm();
		$lesson = $this->cxm_lesson( $cxm, 'json-syntax' );
		wp_set_current_user( $this->manager() );
		$body = array(
			'user'   => $this->learner,
			'lesson' => $lesson,
			'reason' => 'Tested Out',
		);
		$this->assertSame( 200, $this->waive( $body )->get_status() );
		$body['reason'] = 'Administrative';
		$again          = $this->waive( $body );
		$this->assertSame( 409, $again->get_status() );
		$this->assertCount( 1, $this->statements( Vocab::WAIVED ) );
		$this->assertSame( 'Tested Out', ( new Waiver_Ledger() )->get( $this->learner, $lesson )['reason'] );
	}

	public function test_lesson_editors_and_managers_may_waive_and_nobody_else() {
		$body = array(
			'user'   => $this->learner,
			'lesson' => $this->lessons[ self::A1 ],
			'reason' => 'Tested Out',
		);
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->waive( $body )->get_status() );

		wp_set_current_user( $this->learner );
		$this->assertSame( 403, $this->waive( $body )->get_status(), 'a learner cannot waive' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( current_user_can( 'edit_post', $this->lessons[ self::A1 ] ) );
		$this->assertSame( 403, $this->waive( $body, false )->get_status(), 'the REST nonce is required' );
		$this->assertSame( array(), $this->statements( Vocab::WAIVED ) );
		$this->assertSame( 200, $this->waive( $body )->get_status(), 'a user who can edit the lesson' );

		wp_set_current_user( $this->manager() );
		$body['lesson'] = $this->lessons[ self::A2 ];
		$this->assertSame( 200, $this->waive( $body )->get_status(), 'a connector manager' );
		$this->assertCount( 2, $this->statements( Vocab::WAIVED ) );
	}

	public function test_bad_input_is_refused() {
		wp_set_current_user( $this->manager() );
		$body = array(
			'user'   => $this->learner,
			'lesson' => $this->lessons[ self::A1 ],
			'reason' => 'Because',
		);
		$this->assertSame( 400, $this->waive( $body )->get_status(), 'reasons are the four cmi5 ones' );
		$body['reason'] = 'Equivalent Outside Activity';
		$body['lesson'] = self::factory()->post->create();
		$this->assertSame( 404, $this->waive( $body )->get_status(), 'not a cmi5 lesson' );
		$body['lesson'] = $this->lessons[ self::A1 ];
		$body['user']   = 999999;
		$this->assertSame( 404, $this->waive( $body )->get_status(), 'unknown user' );
		$this->assertSame( array(), $this->statements( Vocab::WAIVED ) );
	}

	public function test_lrs_failure_is_502() {
		wp_set_current_user( $this->manager() );
		$this->fail = 'statements';
		$res        = $this->waive(
			array(
				'user'   => $this->learner,
				'lesson' => $this->lessons[ self::A1 ],
				'reason' => 'Tested Out',
			)
		);
		$this->assertSame( 502, $res->get_status() );
		$this->assertSame( array(), $this->statements( Vocab::WAIVED ) );
	}
}
