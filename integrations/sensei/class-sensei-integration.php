<?php
/**
 * Sensei LMS integration hooks.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

use LearningLedger\Connector\Lms\Neutral_Event;
use LearningLedger\Connector\Programs\Program_Repository;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Slash-namespaced hook names are the plugin convention.

/**
 * Registers Sensei's post types and IRIs with the connector.
 */
final class Sensei_Integration {

	const ID = 'sensei';

	/**
	 * Hook into the connector. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( has_filter( 'learning_ledger/adapter_post_types', array( self::class, 'adapter_post_types' ) ) ) {
			return;
		}
		add_filter( 'learning_ledger/stable_key_post_types', array( self::class, 'stable_key_post_types' ) );
		add_filter( 'learning_ledger/lesson_post_types', array( self::class, 'lesson_post_types' ) );
		add_filter( 'learning_ledger/course_post_types', array( self::class, 'course_post_types' ) );
		add_filter( 'learning_ledger/adapter_post_types', array( self::class, 'adapter_post_types' ) );
		add_filter( 'learning_ledger/status', array( self::class, 'status' ), 10, 2 );
		add_filter( 'learning_ledger/direction2_iris', array( self::class, 'direction2_iris' ), 10, 2 );

		if ( class_exists( Sensei_Tested_Out_Display::class ) ) {
			new Sensei_Tested_Out_Display();
		}
		if ( class_exists( Sensei_Applier::class ) ) {
			Sensei_Applier::hooks();
		}
		add_action( 'sensei_course_status_updated', array( Sensei_Certificate_Provider::class, 'guard_duplicate' ), 8, 3 );
		add_action( 'learning_ledger/learner_deleted', array( Sensei_Storage::class, 'delete_user_progress' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'learning-ledger sensei storage', Sensei_Storage_Command::class );
		}
	}

	/**
	 * Post types that carry a stable key.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function stable_key_post_types( mixed $types ): array {
		return self::add( $types, array( 'course', 'lesson' ) );
	}

	/**
	 * Lesson post types.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function lesson_post_types( mixed $types ): array {
		return self::add( $types, array( 'lesson' ) );
	}

	/**
	 * Course post types.
	 *
	 * @param mixed $types Post types.
	 *
	 * @return string[]
	 */
	public static function course_post_types( mixed $types ): array {
		return self::add( $types, array( 'course' ) );
	}

	/**
	 * Post types this adapter handles, by integration id.
	 *
	 * @param mixed $map Integration id => post types.
	 *
	 * @return array
	 */
	public static function adapter_post_types( mixed $map ): array {
		$map             = is_array( $map ) ? $map : array();
		$map[ self::ID ] = array( 'course', 'lesson', 'quiz' );
		return $map;
	}

	/**
	 * Add Sensei and Sensei LMS Certificates versions to the status payload.
	 *
	 * @param mixed   $payload    Payload.
	 * @param boolean $privileged Unused; versions are public.
	 *
	 * @return array
	 */
	public static function status( mixed $payload, bool $privileged = false ): array {
		unset( $privileged );
		$payload   = is_array( $payload ) ? $payload : array();
		$connector = isset( $payload['connector'] ) && is_array( $payload['connector'] ) ? $payload['connector'] : array();
		$lms       = isset( $connector['lms'] ) && is_array( $connector['lms'] ) ? $connector['lms'] : array();

		$lms['sensei']              = function_exists( 'Sensei' ) ? (string) Sensei()->version : null;
		$lms['sensei_certificates'] = defined( 'SENSEI_CERTIFICATES_VERSION' ) ? (string) SENSEI_CERTIFICATES_VERSION : null;

		$payload['connector'] = array_merge( $connector, array( 'lms' => $lms ) );
		return $payload;
	}

	/**
	 * Object and parent IRIs of a Sensei event whose course is not in a
	 * program (program courses use Program_Iris).
	 *
	 * @param mixed         $iris Value from earlier callbacks.
	 * @param Neutral_Event $e    Event.
	 *
	 * @return mixed array{object:string,parent:string}|null, or $iris unchanged for other LMSs.
	 */
	public static function direction2_iris( mixed $iris, Neutral_Event $e ): mixed {
		if ( self::ID !== $e->lms ) {
			return $iris;
		}
		if ( $e->course_id <= 0 || 'course' !== get_post_type( $e->course_id ) ) {
			return null;
		}
		if ( null !== ( new Program_Repository() )->for_lms_course( $e->course_id ) ) {
			return null;
		}

		$quiz   = in_array( $e->type, array( Neutral_Event::QUIZ_SUBMITTED, Neutral_Event::QUIZ_GRADED ), true );
		$lesson = (int) $e->lesson_id;
		if ( $quiz && $lesson <= 0 && null !== $e->quiz_id ) {
			// A Sensei quiz's parent post is its lesson.
			$lesson = (int) wp_get_post_parent_id( $e->quiz_id );
		}

		$parent = Sensei_Iris::course( $e->course_id );
		$object = $parent;
		if ( $lesson > 0 ) {
			$object = $quiz ? Sensei_Iris::quiz( $e->course_id, $lesson ) : Sensei_Iris::lesson( $e->course_id, $lesson );
		}
		return array(
			'object' => $object,
			'parent' => $parent,
		);
	}

	/**
	 * Append post types once.
	 *
	 * @param mixed    $types Existing.
	 * @param string[] $add   To add.
	 *
	 * @return string[]
	 */
	private static function add( mixed $types, array $add ): array {
		$types = is_array( $types ) ? $types : array();
		return array_values( array_unique( array_merge( $types, $add ) ) );
	}
}
