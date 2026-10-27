import { registerPlugin } from '@wordpress/plugins';
import { LessonPanel } from './lesson-panel';
import { CoursePanel } from './course-panel';

registerPlugin( 'learning-ledger-lesson-fields', { render: LessonPanel } );
registerPlugin( 'learning-ledger-course-fields', { render: CoursePanel } );
