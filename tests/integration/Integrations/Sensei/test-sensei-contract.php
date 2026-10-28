<?php
/**
 * Runs the adapter contract against Sensei LMS.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Db\Installer;
use LearningLedger\Integrations\Sensei\Sensei_Adapter;
use LearningLedger\Integrations\Sensei\Sensei_Iris;
use LearningLedger\Tests\Adapter_Contract_Test_Case;

/**
 * Sensei contract.
 *
 * @group sensei-contract
 * @requires function Sensei
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Sensei_Contract_Test extends Adapter_Contract_Test_Case {

	public function set_up(): void {
		Installer::install();
		parent::set_up();
	}

	protected function make_adapter(): LMS_Adapter {
		return new Sensei_Adapter();
	}

	protected function make_course( int $lessons ): array {
		$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		$course = $this->adapter->upsert_course( new Course_Spec( 'c-' . $suffix, 'Course', '', 'publish', array() ) );
		$ids    = array();
		for ( $i = 1; $i <= $lessons; $i++ ) {
			$ids[] = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'l-' . $suffix . '-' . $i, 'Lesson ' . $i, '', $i, 'publish', array() ) );
		}
		return array(
			'course'  => $course,
			'lessons' => $ids,
		);
	}

	protected function lesson_iri( int $course_id, int $lesson_id ): string {
		return Sensei_Iris::lesson( $course_id, $lesson_id );
	}

	private function require_applier(): void {
		if ( ! class_exists( 'LearningLedger\Integrations\Sensei\Sensei_Applier' ) ) {
			$this->markTestSkipped( 'Sensei_Applier (Task 46) is not present yet.' );
		}
	}

	public function test_apply_lesson_completed_shows_in_progress() {
		$this->require_applier();
		parent::test_apply_lesson_completed_shows_in_progress();
	}

	public function test_apply_waived_completes_and_is_waived() {
		$this->require_applier();
		parent::test_apply_waived_completes_and_is_waived();
	}

	public function test_apply_is_idempotent() {
		$this->require_applier();
		parent::test_apply_is_idempotent();
	}

	public function test_events_emitted_for_lesson_completion() {
		$this->require_applier();
		if ( ! class_exists( 'LearningLedger\Integrations\Sensei\Sensei_Listeners' ) ) {
			$this->markTestSkipped( 'Sensei_Listeners (Task 47) is not present yet.' );
		}
		parent::test_events_emitted_for_lesson_completion();
	}
}
