<?php
/**
 * Statement_Normalizer tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Xapi\Statement_Normalizer;

class Statement_Normalizer_Test extends \PHPUnit\Framework\TestCase {

	private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

	private function authority(): array {
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => 'https://lrs.example.org',
				'name'     => 'client-1',
			),
		);
	}

	private function stored(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-10-27T08:00:00.250000Z' );
	}

	private function statement(): array {
		return array(
			'actor'  => array( 'mbox' => 'mailto:learner@example.org' ),
			'verb'   => array( 'id' => 'http://adlnet.gov/expapi/verbs/experienced' ),
			'object' => array( 'id' => 'https://example.org/activities/1' ),
		);
	}

	public function test_id_added_as_v4_when_absent() {
		$out = Statement_Normalizer::normalize( $this->statement(), '2.0.0', $this->authority(), $this->stored() );
		$this->assertMatchesRegularExpression( self::UUID_V4, $out['id'] );
	}

	public function test_id_lowercased_when_present() {
		$st       = $this->statement();
		$st['id'] = '6F1C0E1A-4B2D-4C3E-9F00-0123456789AB';
		$out      = Statement_Normalizer::normalize( $st, '2.0.0', $this->authority(), $this->stored() );
		$this->assertSame( '6f1c0e1a-4b2d-4c3e-9f00-0123456789ab', $out['id'] );
	}

	public function test_stored_and_authority_set_overwriting_client_values() {
		$st              = $this->statement();
		$st['stored']    = '2000-01-01T00:00:00Z';
		$st['authority'] = array( 'mbox' => 'mailto:forged@example.org' );
		$out             = Statement_Normalizer::normalize( $st, '2.0.0', $this->authority(), $this->stored() );
		$this->assertSame( '2026-10-27T08:00:00.250000Z', $out['stored'] );
		$this->assertSame( $this->authority(), $out['authority'] );
	}

	public function test_version_defaults_per_negotiated_version() {
		$this->assertSame( '1.0.0', Statement_Normalizer::normalize( $this->statement(), '1.0.3', $this->authority(), $this->stored() )['version'] );
		$this->assertSame( '2.0.0', Statement_Normalizer::normalize( $this->statement(), '2.0.0', $this->authority(), $this->stored() )['version'] );
	}

	public function test_version_kept_when_present() {
		$st            = $this->statement();
		$st['version'] = '1.0.3';
		$this->assertSame( '1.0.3', Statement_Normalizer::normalize( $st, '2.0.0', $this->authority(), $this->stored() )['version'] );
	}

	public function test_timestamp_defaults_to_stored_and_is_kept_when_present() {
		$out = Statement_Normalizer::normalize( $this->statement(), '2.0.0', $this->authority(), $this->stored() );
		$this->assertSame( $out['stored'], $out['timestamp'] );

		$st              = $this->statement();
		$st['timestamp'] = '2026-10-27T10:00:00+02:00';
		$out             = Statement_Normalizer::normalize( $st, '2.0.0', $this->authority(), $this->stored() );
		$this->assertSame( '2026-10-27T10:00:00+02:00', $out['timestamp'] );
	}

	public function test_single_context_activities_become_arrays() {
		$parent        = array( 'id' => 'https://example.org/activities/p' );
		$grouping      = array( array( 'id' => 'https://example.org/activities/g' ) );
		$st            = $this->statement();
		$st['context'] = array(
			'contextActivities' => array(
				'parent'   => $parent,
				'grouping' => $grouping,
				'category' => array(),
			),
		);
		$st['object']  = array(
			'objectType' => 'SubStatement',
			'actor'      => $st['actor'],
			'verb'       => $st['verb'],
			'object'     => array( 'id' => 'https://example.org/activities/1' ),
			'context'    => array( 'contextActivities' => array( 'other' => $parent ) ),
		);

		$out = Statement_Normalizer::normalize( $st, '1.0.3', $this->authority(), $this->stored() );

		$this->assertSame( array( $parent ), $out['context']['contextActivities']['parent'] );
		$this->assertSame( $grouping, $out['context']['contextActivities']['grouping'] );
		$this->assertSame( array(), $out['context']['contextActivities']['category'] );
		$this->assertSame( array( $parent ), $out['object']['context']['contextActivities']['other'] );
	}

	public function test_comparable_equal_with_reordered_keys_and_offset_timestamp() {
		$a        = array(
			'id'        => '6f1c0e1a-4b2d-4c3e-9f00-0123456789ab',
			'actor'     => array(
				'name' => 'L',
				'mbox' => 'mailto:learner@example.org',
			),
			'verb'      => array( 'id' => 'http://adlnet.gov/expapi/verbs/experienced' ),
			'object'    => array(
				'id'         => 'https://example.org/activities/1',
				'objectType' => 'Activity',
			),
			'timestamp' => '2026-10-27T10:00:00.5+02:00',
		);
		$b        = array(
			'timestamp' => '2026-10-27T08:00:00.500Z',
			'object'    => array(
				'objectType' => 'Activity',
				'id'         => 'https://example.org/activities/1',
			),
			'verb'      => array( 'id' => 'http://adlnet.gov/expapi/verbs/experienced' ),
			'actor'     => array(
				'mbox' => 'mailto:learner@example.org',
				'name' => 'L',
			),
		);
		$stored_a = Statement_Normalizer::normalize( $a, '2.0.0', $this->authority(), $this->stored() );
		$stored_b = Statement_Normalizer::normalize( $b, '2.0.0', array( 'mbox' => 'mailto:other@example.org' ), new \DateTimeImmutable( '2030-01-01T00:00:00Z' ) );

		$this->assertSame( Statement_Normalizer::comparable( $stored_a ), Statement_Normalizer::comparable( $stored_b ) );
		$this->assertSame( Statement_Normalizer::comparable( $a ), Statement_Normalizer::comparable( $b ) );
	}

	public function test_comparable_strips_server_fields_and_utc_timestamp() {
		$st  = Statement_Normalizer::normalize( $this->statement(), '1.0.3', $this->authority(), $this->stored() );
		$cmp = Statement_Normalizer::comparable( $st );
		foreach ( array( 'id', 'stored', 'authority', 'version' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $cmp );
		}
		$this->assertSame( '2026-10-27T08:00:00.250000Z', $cmp['timestamp'] );
	}

	public function test_comparable_differs_when_content_differs() {
		$a                 = $this->statement();
		$b                 = $this->statement();
		$b['object']['id'] = 'https://example.org/activities/2';
		$c                 = $this->statement();
		$c['result']       = array( 'success' => true );
		$this->assertNotSame( Statement_Normalizer::comparable( $a ), Statement_Normalizer::comparable( $b ) );
		$this->assertNotSame( Statement_Normalizer::comparable( $a ), Statement_Normalizer::comparable( $c ) );
	}

	public function test_comparable_equal_for_single_and_wrapped_context_activity() {
		$single              = $this->statement();
		$single['timestamp'] = '2026-10-27T08:00:00Z';
		$single['context']   = array( 'contextActivities' => array( 'parent' => array( 'id' => 'https://example.org/p' ) ) );
		$stored              = Statement_Normalizer::normalize( $single, '2.0.0', $this->authority(), $this->stored() );
		$this->assertSame( array( array( 'id' => 'https://example.org/p' ) ), Statement_Normalizer::comparable( $single )['context']['contextActivities']['parent'] );
		$this->assertSame( Statement_Normalizer::comparable( $single ), Statement_Normalizer::comparable( $stored ) );
	}

	public function test_comparable_keeps_list_order() {
		$a            = $this->statement();
		$a['context'] = array(
			'contextActivities' => array(
				'grouping' => array( array( 'id' => 'https://example.org/a' ), array( 'id' => 'https://example.org/b' ) ),
			),
		);
		$b            = $a;
		$b['context']['contextActivities']['grouping'] = array_reverse( $a['context']['contextActivities']['grouping'] );
		$this->assertNotSame( Statement_Normalizer::comparable( $a ), Statement_Normalizer::comparable( $b ) );
	}
}
