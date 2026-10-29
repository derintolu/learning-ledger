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

	public function test_fields_are_the_three_pointers_of_spec_0_2() {
		// course-xapi-mcp 0.2 package.md: the planted bug's explanation and fixChecks, and referenceSolution.
		$this->assertSame(
			array( '/bug/explanation', '/bug/fixChecks', '/referenceSolution' ),
			Secrets::FIELDS
		);
	}

	public function test_strip_removes_the_three_pointers_and_nothing_else() {
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
			$expected['bug']['explanation'],
			$expected['bug']['fixChecks'],
			$expected['referenceSolution']
		);

		$stripped = Secrets::strip( $lesson );

		$this->assertSame( $expected, $stripped );
		$this->assertArrayHasKey( 'question', $stripped['checkpoint'] );
		$this->assertArrayHasKey( 'rubric', $stripped['checkpoint'], 'not secret in 0.2' );
		$this->assertArrayHasKey( 'checks', $stripped['checkpoint'], 'not secret in 0.2' );
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
