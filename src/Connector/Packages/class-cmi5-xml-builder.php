<?php
/**
 * Builds cmi5.xml for a program, with the cxm extension elements.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties are camelCase.

use LearningLedger\Connector\Lms\Course_Tree;
use LearningLedger\Connector\Programs\Program;
use LearningLedger\Connector\Programs\Program_Iris;

/**
 * Course, placement AU, one block per path and one AU per lesson.
 */
final class Cmi5_Xml_Builder {

	public const NS_CMI5 = 'https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd';

	public const NS_CXM = 'https://course-xapi-mcp.sqwrlz.com/course/v1';

	public const LESSON_TYPE = 'http://adlnet.gov/expapi/activities/lesson';

	/**
	 * Builds the XML.
	 *
	 * @param Program $p           Program.
	 * @param array   $paths       List of [path key, Course_Tree], in program order.
	 * @param array   $lesson_meta Lesson facts by lesson key: checkpoint (bool) and description (string).
	 *
	 * @return string
	 */
	public static function build( Program $p, array $paths, array $lesson_meta ): string {
		// An imported package's langstring language, else the site's.
		$lang = (string) get_post_meta( $p->id, '_lledger_langstring_lang', true );
		$lang = '' !== $lang ? $lang : (string) get_bloginfo( 'language' );
		$lang = '' !== $lang ? $lang : 'en';
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );

		$root = $dom->createElementNS( self::NS_CMI5, 'courseStructure' );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cxm', self::NS_CXM );
		$dom->appendChild( $root );

		$title = (string) get_post_field( 'post_title', $p->id );
		$desc  = (string) get_post_field( 'post_excerpt', $p->id );
		$ctx   = array(
			'dom'  => $dom,
			'lang' => $lang,
		);

		$course = self::el( $dom, 'course' );
		$course->setAttribute( 'id', $p->course_iri );
		$course->appendChild( self::text_el( $ctx, 'title', $title ) );
		$course->appendChild( self::text_el( $ctx, 'description', '' !== $desc ? $desc : $title ) );
		$ext = $dom->createElementNS( self::NS_CXM, 'cxm:course' );
		$ext->setAttribute( 'src', 'cxm/course.json' );
		$ext->setAttribute( 'specVersion', Package_Builder::SPEC_VERSION );
		if ( '' !== $p->course_server_url ) {
			$ext->setAttribute( 'mcp', $p->course_server_url );
		}
		$course->appendChild( $ext );
		$root->appendChild( $course );

		$placement_key = self::placement_key( $p );
		if ( null !== $placement_key ) {
			$placement_title = is_string( $p->placement['title'] ?? null ) ? $p->placement['title'] : '';
			$placement_desc  = is_string( $p->placement['description'] ?? null ) ? $p->placement['description'] : '';
			$au              = self::au_el(
				$ctx,
				Program_Iris::placement_iri( $p ),
				$placement_key,
				'NotApplicable',
				'' !== $placement_title ? $placement_title : __( 'Placement', 'learning-ledger' ),
				'' !== $placement_desc ? $placement_desc : __( 'Find your starting point.', 'learning-ledger' ),
				$p,
				false
			);
			$au->setAttributeNS( self::NS_CXM, 'cxm:role', 'placement' );
			$root->appendChild( $au );
		}

		foreach ( $paths as $path ) {
			list( $key, $tree ) = $path;
			$block              = self::el( $dom, 'block' );
			$block->setAttribute( 'id', Program_Iris::block_iri( $p, $key ) );
			$block->setAttributeNS( self::NS_CXM, 'cxm:key', $key );
			$path_desc = (string) get_post_meta( $tree->course_id, Package_Builder::BLOCK_DESCRIPTION, true );
			$path_desc = '' !== $path_desc ? $path_desc : (string) get_post_field( 'post_excerpt', $tree->course_id );
			$block->appendChild( self::text_el( $ctx, 'title', $tree->title ) );
			$block->appendChild( self::text_el( $ctx, 'description', '' !== $path_desc ? $path_desc : $tree->title ) );
			foreach ( $tree->lessons as $lesson ) {
				$lesson_key = (string) $lesson->stable_key;
				$meta       = $lesson_meta[ $lesson_key ] ?? array();
				$described  = (string) ( $meta['description'] ?? '' );
				$block->appendChild(
					self::au_el(
						$ctx,
						Program_Iris::au_iri( $p, $lesson_key ),
						$lesson_key,
						! empty( $meta['checkpoint'] ) ? 'CompletedAndPassed' : 'Completed',
						$lesson->title,
						'' !== $described ? $described : $lesson->title,
						$p,
						true
					)
				);
			}
			$path_ext = $dom->createElementNS( self::NS_CXM, 'cxm:path' );
			$path_ext->setAttribute( 'src', 'cxm/course.json#/paths/' . $key );
			$block->appendChild( $path_ext );
			$root->appendChild( $block );
		}

		$dom->formatOutput = true;
		return (string) $dom->saveXML();
	}

	/**
	 * Key of the placement AU, or null when the program has none.
	 *
	 * @param Program $p Program.
	 *
	 * @return string|null
	 */
	public static function placement_key( Program $p ): ?string {
		$key = $p->placement['au_key'] ?? '';
		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * An AU element.
	 *
	 * @param array   $ctx         DOM and language.
	 * @param string  $id          AU id.
	 * @param string  $key         Local key (the cxm:key attribute).
	 * @param string  $move_on     Value of the moveOn attribute.
	 * @param string  $title       Title.
	 * @param string  $description Description.
	 * @param Program $p           Program.
	 * @param boolean $lesson      Whether it is a lesson AU.
	 *
	 * @return \DOMElement
	 */
	private static function au_el( array $ctx, string $id, string $key, string $move_on, string $title, string $description, Program $p, bool $lesson ): \DOMElement {
		$dom = $ctx['dom'];
		$au  = self::el( $dom, 'au' );
		$au->setAttribute( 'id', $id );
		$au->setAttributeNS( self::NS_CXM, 'cxm:key', $key );
		$au->setAttribute( 'moveOn', $move_on );
		$au->setAttribute( 'launchMethod', 'OwnWindow' );
		if ( $lesson ) {
			$au->setAttribute( 'activityType', self::LESSON_TYPE );
		}
		$au->appendChild( self::text_el( $ctx, 'title', $title ) );
		$au->appendChild( self::text_el( $ctx, 'description', $description ) );
		$url = $dom->createElementNS( self::NS_CMI5, 'url' );
		$url->appendChild( $dom->createTextNode( str_replace( '{key}', rawurlencode( $key ), $p->au_url_template ) ) );
		$au->appendChild( $url );
		if ( $lesson ) {
			$src = $dom->createElementNS( self::NS_CXM, 'cxm:lesson' );
			$src->setAttribute( 'src', 'cxm/lessons/' . $key . '.json' );
			$au->appendChild( $src );
		}
		return $au;
	}

	/**
	 * An element in the cmi5 namespace.
	 *
	 * @param \DOMDocument $dom  Document.
	 * @param string       $name Local name.
	 *
	 * @return \DOMElement
	 */
	private static function el( \DOMDocument $dom, string $name ): \DOMElement {
		return $dom->createElementNS( self::NS_CMI5, $name );
	}

	/**
	 * A title or description element holding one langstring.
	 *
	 * @param array  $ctx  DOM and language.
	 * @param string $name Either title or description.
	 * @param string $text Text.
	 *
	 * @return \DOMElement
	 */
	private static function text_el( array $ctx, string $name, string $text ): \DOMElement {
		$dom = $ctx['dom'];
		$el  = self::el( $dom, $name );
		$str = self::el( $dom, 'langstring' );
		$str->setAttribute( 'lang', $ctx['lang'] );
		$str->appendChild( $dom->createTextNode( $text ) );
		$el->appendChild( $str );
		return $el;
	}
}
