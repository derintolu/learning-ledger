<?php
/**
 * Identity map tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Identity\Identity_Map;
use LearningLedger\Connector\Identity\Identity_Module;
use LearningLedger\Db\Installer;
use LearningLedger\Xapi\Agent_Identity;

class Identity_Map_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::install();
		delete_option( 'lledger_connector' );
	}

	private function map(): Identity_Map {
		return new Identity_Map();
	}

	public function test_actor_stable_per_user() {
		$user = self::factory()->user->create();
		$a    = $this->map()->actor_for_user( $user );
		$b    = $this->map()->actor_for_user( $user );
		$this->assertSame( $a, $b );
		$this->assertSame( 'Agent', $a['objectType'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a['account']['name'] );
		$this->assertSame( $a['account']['name'], get_user_meta( $user, 'lledger_actor', true ) );
		$other = self::factory()->user->create();
		$this->assertNotSame( $a['account']['name'], $this->map()->actor_for_user( $other )['account']['name'] );
		$this->assertSame( $user, $this->map()->user_for_actor( $a ) );
	}

	public function test_home_page_is_untrailingslashed_and_round_trips() {
		$filter = static fn() => 'https://site.example/';
		add_filter( 'home_url', $filter );
		$user   = self::factory()->user->create();
		$actor  = $this->map()->actor_for_user( $user );

		$this->assertSame( 'https://site.example', $actor['account']['homePage'] );
		$this->assertSame( 'https://site.example', $this->map()->home_page() );
		$this->assertSame( $user, $this->map()->user_for_actor( $actor ) );

		$slash                         = $actor;
		$slash['account']['homePage'] .= '/';
		$this->assertNull( $this->map()->user_for_actor( $slash ), 'exact match only' );

		$module = new Identity_Module();
		$module->register();
		$status = apply_filters( 'learning_ledger/status', array( 'connector' => array( 'keep' => 1 ) ), true ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( $this->map()->home_page(), $status['connector']['actor_home_page'] );
		$this->assertSame( 1, $status['connector']['keep'] );
		remove_filter( 'home_url', $filter );
	}

	public function test_explicit_link_matches() {
		$user  = self::factory()->user->create();
		$ident = Agent_Identity::from_array( array( 'openid' => 'https://id.example/learner' ) );
		$this->assertNull( $this->map()->user_for_actor( $ident->to_array() ) );

		$this->map()->link( $ident, $user );
		$this->assertSame( $user, $this->map()->user_for_actor( $ident->to_array() ) );
		$this->assertCount( 1, $this->map()->links_for_user( $user ) );

		$this->assertSame( 1, $this->map()->unlink_user( $user ) );
		$this->assertNull( $this->map()->user_for_actor( $ident->to_array() ) );
		$this->assertSame( array(), $this->map()->links_for_user( $user ) );
	}

	public function test_mbox_only_when_enabled() {
		$user  = self::factory()->user->create( array( 'user_email' => 'learner@example.org' ) );
		$actor = array(
			'objectType' => 'Agent',
			'mbox'       => 'mailto:learner@example.org',
		);
		$this->assertNull( $this->map()->user_for_actor( $actor ) );

		update_option( 'lledger_connector', array( 'match_mbox' => true ) );
		$this->assertSame( $user, $this->map()->user_for_actor( $actor ) );

		update_option( 'lledger_connector', array( 'match_mbox' => false ) );
		$this->assertNull( $this->map()->user_for_actor( $actor ) );
	}

	public function test_anonymous_learner_has_no_email_and_only_read() {
		( new Identity_Module() )->ensure_role();
		$id   = $this->map()->create_anonymous_learner();
		$user = get_userdata( $id );
		$this->assertMatchesRegularExpression( '/^lledger-[0-9a-f]{12}$/', $user->user_login );
		$this->assertSame( '', $user->user_email );
		$this->assertSame( array( 'lledger_learner' ), $user->roles );
		$this->assertSame( array( 'read' => true ), get_role( 'lledger_learner' )->capabilities );

		$actor = $this->map()->actor_for_user( $id );
		$this->map()->link( Agent_Identity::from_array( array( 'openid' => 'https://id.example/anon' ) ), $id );
		$this->map()->delete_learner( $id );
		$this->assertSame( '', (string) get_user_meta( $id, 'lledger_actor', true ) );
		$this->assertSame( array(), $this->map()->links_for_user( $id ) );
		$this->assertNull( $this->map()->user_for_actor( $actor ) );
	}
}
