<?php
/**
 * IRIs derived from a program's IRI base.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Programs;

use LearningLedger\Cmi5\Au;

/**
 * Builds and parses block, AU and placement IRIs.
 */
final class Program_Iris {

	/**
	 * IRI of a path block.
	 *
	 * @param Program $p        Program.
	 * @param string  $path_key Path key.
	 *
	 * @return string
	 */
	public static function block_iri( Program $p, string $path_key ): string {
		return $p->iri_base . 'paths/' . $path_key;
	}

	/**
	 * IRI of a lesson AU.
	 *
	 * @param Program $p          Program.
	 * @param string  $lesson_key Lesson key.
	 *
	 * @return string
	 */
	public static function au_iri( Program $p, string $lesson_key ): string {
		return $p->iri_base . 'lessons/' . $lesson_key;
	}

	/**
	 * IRI of the placement AU.
	 *
	 * @param Program $p Program.
	 *
	 * @return string
	 */
	public static function placement_iri( Program $p ): string {
		return $p->iri_base . 'placement';
	}

	/**
	 * Activity id statements use for an AU.
	 *
	 * @param string $au_iri AU IRI.
	 *
	 * @return string
	 */
	public static function au_activity_id( string $au_iri ): string {
		return Au::activity_id( $au_iri );
	}

	/**
	 * Inverse of the IRI builders; accepts the `/au` suffix.
	 *
	 * @param Program $p   Program.
	 * @param string  $iri IRI.
	 *
	 * @return array{kind:string,key:string}|null
	 */
	public static function key_from_iri( Program $p, string $iri ): ?array {
		if ( '' === $p->iri_base || 0 !== strpos( $iri, $p->iri_base ) ) {
			return null;
		}
		$rest = substr( $iri, strlen( $p->iri_base ) );
		if ( 'placement' === $rest || 'placement/au' === $rest ) {
			return array(
				'kind' => 'placement',
				'key'  => 'placement',
			);
		}
		if ( 1 === preg_match( '#^lessons/([^/]+?)(?:/au)?$#', $rest, $m ) ) {
			return array(
				'kind' => 'lesson',
				'key'  => $m[1],
			);
		}
		if ( 1 === preg_match( '#^paths/([^/]+)$#', $rest, $m ) ) {
			return array(
				'kind' => 'path',
				'key'  => $m[1],
			);
		}
		return null;
	}
}
