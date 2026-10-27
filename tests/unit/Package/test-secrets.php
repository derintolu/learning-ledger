<?php
/**
 * Secrets tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Package\Secrets;

class Secrets_Test extends \PHPUnit\Framework\TestCase {

	private function lesson(): array {
		return json_decode( file_get_contents( __DIR__ . '/../../fixtures/packages/good-full/cxm/lessons/what-is-json.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	public function test_fields_are_the_five_pointers() {
		$this->assertSame(
			array( '/checkpoint/rubric', '/checkpoint/checks', '/bug/explanation', '/bug/fixChecks', '/referenceSolution' ),
			Secrets::FIELDS
		);
	}

	public function test_strip_removes_all_five_pointers_and_nothing_else() {
		$lesson                         = $this->lesson();
		$lesson['checkpoint']['checks'] = array(
			array(
				'type'    => 'contains',
				'file'    => 'a',
				'value'   => 'b',
				'message' => 'c',
			),
		);
		$expected                       = $lesson;
		unset(
			$expected['checkpoint']['rubric'],
			$expected['checkpoint']['checks'],
			$expected['bug']['explanation'],
			$expected['bug']['fixChecks'],
			$expected['referenceSolution']
		);

		$stripped = Secrets::strip( $lesson );

		$this->assertSame( $expected, $stripped );
		$this->assertArrayHasKey( 'question', $stripped['checkpoint'] );
		$this->assertArrayHasKey( 'code', $stripped['bug'] );
		$this->assertTrue( Secrets::has_secrets( $lesson ) );
		$this->assertFalse( Secrets::has_secrets( $stripped ) );
	}

	public function test_strip_tolerates_missing_parents() {
		$this->assertSame( array( 'minutes' => 3 ), Secrets::strip( array( 'minutes' => 3 ) ) );
		$this->assertFalse( Secrets::has_secrets( array( 'minutes' => 3 ) ) );
	}

	public function test_a_secret_with_a_null_value_still_counts() {
		$this->assertTrue( Secrets::has_secrets( array( 'referenceSolution' => null ) ) );
	}
}
