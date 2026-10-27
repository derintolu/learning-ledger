<?php
/**
 * Runs the adapter contract against the in-memory fake.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Lms\Course_Spec;
use LearningLedger\Connector\Lms\Lesson_Spec;
use LearningLedger\Connector\Lms\LMS_Adapter;
use LearningLedger\Tests\Adapter_Contract_Test_Case;
use LearningLedger\Tests\Fake_Adapter;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class Fake_Adapter_Contract_Test extends Adapter_Contract_Test_Case {
	protected function make_adapter(): LMS_Adapter {
		return new Fake_Adapter();
	}

	protected function make_course( int $lessons ): array {
		$course = $this->adapter->upsert_course( new Course_Spec( 'c-' . wp_generate_uuid4(), 'Course', '', 'publish', array() ) );
		$ids    = array();
		for ( $i = 1; $i <= $lessons; $i++ ) {
			$ids[] = $this->adapter->upsert_lesson( $course, new Lesson_Spec( 'l-' . $i, 'Lesson ' . $i, '', $i, 'publish', array() ) );
		}
		return array(
			'course'  => $course,
			'lessons' => $ids,
		);
	}
}
