<?php
/**
 * Ref indexer tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Lrs\Statements\Ref_Indexer;
use LearningLedger\Xapi\Agent_Identity;

class Ref_Indexer_Test extends WP_UnitTestCase {

	private static function agent( string $mbox ): array {
		return array( 'mbox' => 'mailto:' . $mbox );
	}

	private static function h( array $agent ): string {
		return Agent_Identity::from_array( $agent )->hash();
	}

	private static function pairs( array $refs ): array {
		$out = array_map( static fn( $r ) => $r['kind'] . ' ' . bin2hex( $r['hash'] ), $refs );
		sort( $out );
		return $out;
	}

	private static function expect( array $expected ): array {
		$out = array_map( static fn( $r ) => $r[0] . ' ' . bin2hex( $r[1] ), $expected );
		sort( $out );
		return $out;
	}

	public function test_refs_for_substatement_and_context() {
		$st = array(
			'id'        => '6f1c3c1e-9a5e-4c1b-8d4e-2b7f0c9a1d11',
			'actor'     => self::agent( 'actor@x.example' ),
			'verb'      => array( 'id' => 'http://adlnet.gov/expapi/verbs/planned' ),
			'object'    => array(
				'objectType' => 'SubStatement',
				'actor'      => self::agent( 'sub-actor@x.example' ),
				'verb'       => array( 'id' => 'http://adlnet.gov/expapi/verbs/attempted' ),
				'object'     => array( 'id' => 'https://x.example/sub-act' ),
				'context'    => array(
					'instructor'        => self::agent( 'sub-teacher@x.example' ),
					'contextActivities' => array( 'parent' => array( array( 'id' => 'https://x.example/sub-parent' ) ) ),
				),
			),
			'authority' => self::agent( 'auth@x.example' ),
			'context'   => array(
				'instructor'        => self::agent( 'teacher@x.example' ),
				'team'              => array(
					'objectType' => 'Group',
					'mbox'       => 'mailto:team@x.example',
				),
				'contextAgents'     => array(
					array(
						'objectType' => 'contextAgent',
						'agent'      => self::agent( 'ca@x.example' ),
					),
				),
				'contextGroups'     => array(
					array(
						'objectType' => 'contextGroup',
						'group'      => array(
							'objectType' => 'Group',
							'mbox'       => 'mailto:cg@x.example',
						),
					),
				),
				'contextActivities' => array(
					'parent'   => array( array( 'id' => 'https://x.example/parent' ) ),
					'grouping' => array( 'id' => 'https://x.example/grouping' ),
					'category' => array( array( 'id' => 'https://x.example/cat' ), array( 'id' => 'https://x.example/cat' ) ),
					'other'    => array( array( 'id' => 'https://x.example/other' ) ),
				),
			),
		);

		$this->assertSame(
			self::expect(
				array(
					array( 'actor', self::h( self::agent( 'actor@x.example' ) ) ),
					array( 'authority', self::h( self::agent( 'auth@x.example' ) ) ),
					array( 'instructor', self::h( self::agent( 'teacher@x.example' ) ) ),
					array( 'team', self::h( array( 'mbox' => 'mailto:team@x.example' ) ) ),
					array( 'context-agent', self::h( self::agent( 'ca@x.example' ) ) ),
					array( 'context-group', self::h( array( 'mbox' => 'mailto:cg@x.example' ) ) ),
					array( 'context-parent', sha1( 'https://x.example/parent', true ) ),
					array( 'context-grouping', sha1( 'https://x.example/grouping', true ) ),
					array( 'context-category', sha1( 'https://x.example/cat', true ) ),
					array( 'context-other', sha1( 'https://x.example/other', true ) ),
					array( 'substatement-actor', self::h( self::agent( 'sub-actor@x.example' ) ) ),
					array( 'substatement-object-activity', sha1( 'https://x.example/sub-act', true ) ),
					array( 'substatement-instructor', self::h( self::agent( 'sub-teacher@x.example' ) ) ),
					array( 'substatement-context-parent', sha1( 'https://x.example/sub-parent', true ) ),
				)
			),
			self::pairs( Ref_Indexer::refs( $st ) )
		);
	}

	public function test_object_agent_and_anonymous_group() {
		$members = array( self::agent( 'b@x.example' ), self::agent( 'a@x.example' ) );
		$st      = array(
			'actor'  => array(
				'objectType' => 'Group',
				'member'     => $members,
			),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/met' ),
			'object' => array_merge( array( 'objectType' => 'Agent' ), self::agent( 'obj@x.example' ) ),
		);
		$anon    = sha1( 'anon:mbox:mailto:a@x.example' . "\n" . 'mbox:mailto:b@x.example', true );
		$this->assertSame( $anon, Ref_Indexer::agent_hash( $st['actor'] ) );
		$this->assertSame(
			self::expect(
				array(
					array( 'actor', $anon ),
					array( 'object-agent', self::h( self::agent( 'obj@x.example' ) ) ),
				)
			),
			self::pairs( Ref_Indexer::refs( $st ) )
		);
	}
}
