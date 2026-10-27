<?php
/**
 * Parses and validates cmi5.xml.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties are camelCase.

use LearningLedger\Util\Invalid_Input;

/**
 * Turns cmi5 course structure XML into a Course_Structure.
 */
final class Course_Structure_Parser {

	/**
	 * The bundled cmi5 CourseStructure.xsd.
	 */
	const XSD_PATH = __DIR__ . '/../../resources/cmi5/CourseStructure.xsd';

	/**
	 * Namespace of the cmi5 course structure.
	 */
	private const NS = 'https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd';

	/**
	 * Parses, trims and validates the XML.
	 *
	 * The network is never used and no DTD is accepted, so external and internal entities cannot be declared.
	 * Whitespace around every text node is stripped before validation (cmi5 13.1).
	 *
	 * @param string $xml Course structure XML.
	 *
	 * @throws Invalid_Input Code `doctype` for a DOCTYPE, `xsd` for malformed or schema-invalid XML.
	 *
	 * @return Course_Structure
	 */
	public static function parse( string $xml ): Course_Structure {
		if ( false !== stripos( $xml, '<!DOCTYPE' ) ) {
			throw new Invalid_Input( 'Course structure XML must not contain a DOCTYPE.', 'doctype' );
		}
		if ( '' === trim( $xml ) ) {
			throw new Invalid_Input( 'Course structure XML is empty.', 'xsd' );
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		try {
			$dom = new \DOMDocument();
			if ( ! $dom->loadXML( $xml, LIBXML_NONET ) ) {
				throw new Invalid_Input( self::errors( 'Course structure XML is not well formed.' ), 'xsd' );
			}
			if ( null !== $dom->doctype ) {
				throw new Invalid_Input( 'Course structure XML must not contain a DOCTYPE.', 'doctype' );
			}
			self::trim_text( $dom );
			if ( ! $dom->schemaValidate( self::XSD_PATH ) ) {
				throw new Invalid_Input( self::errors( 'Course structure XML is not valid against CourseStructure.xsd.' ), 'xsd' );
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		$root   = $dom->documentElement;
		$course = self::child( $root, 'course' );

		return new Course_Structure(
			$course->getAttribute( 'id' ),
			self::text( self::child( $course, 'title' ) ),
			self::text( self::child( $course, 'description' ) ),
			self::children( $root, null ),
			(string) $dom->saveXML()
		);
	}

	/**
	 * Joins collected libxml errors after a lead sentence.
	 *
	 * @param string $lead Lead sentence.
	 *
	 * @return string
	 */
	private static function errors( string $lead ): string {
		$messages = array();
		foreach ( libxml_get_errors() as $error ) {
			$messages[] = sprintf( 'line %d: %s', $error->line, trim( $error->message ) );
		}
		return $messages ? $lead . ' ' . implode( '; ', $messages ) : $lead;
	}

	/**
	 * Trims every text node and removes those left empty.
	 *
	 * @param \DOMNode $node Node.
	 *
	 * @return void
	 */
	private static function trim_text( \DOMNode $node ): void {
		// Iterate over a copy: removing nodes from a live list skips siblings.
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof \DOMText ) {
				$value = trim( $child->data, " \t\n\r" );
				if ( '' === $value ) {
					$node->removeChild( $child );
				} else {
					$child->data = $value;
				}
			} elseif ( $child->hasChildNodes() ) {
				self::trim_text( $child );
			}
		}
	}

	/**
	 * AU and block children of a course root or block element, in document order.
	 *
	 * @param \DOMElement $node            Element.
	 * @param string|null $parent_block_id Enclosing block id.
	 *
	 * @return array<Au|Block>
	 */
	private static function children( \DOMElement $node, ?string $parent_block_id ): array {
		$out = array();
		foreach ( $node->childNodes as $el ) {
			if ( ! $el instanceof \DOMElement || self::NS !== $el->namespaceURI ) {
				continue;
			}
			if ( 'au' === $el->localName ) {
				$out[] = self::au( $el, $parent_block_id );
			} elseif ( 'block' === $el->localName ) {
				$id    = $el->getAttribute( 'id' );
				$out[] = new Block(
					$id,
					self::cxm_attr( $el, 'key' ),
					self::text( self::child( $el, 'title' ) ),
					self::text( self::child( $el, 'description' ) ),
					$parent_block_id,
					self::children( $el, $id )
				);
			}
		}
		return $out;
	}

	/**
	 * Builds an AU.
	 *
	 * @param \DOMElement $el              The au element.
	 * @param string|null $parent_block_id Enclosing block id.
	 *
	 * @return Au
	 */
	private static function au( \DOMElement $el, ?string $parent_block_id ): Au {
		$params      = self::child( $el, 'launchParameters' );
		$entitlement = self::child( $el, 'entitlementKey' );
		$lesson      = self::child( $el, 'lesson', Vocab::CXM_NS );
		$mastery     = $el->getAttribute( 'masteryScore' );

		return new Au(
			array(
				'id'                => $el->getAttribute( 'id' ),
				'key'               => self::cxm_attr( $el, 'key' ),
				'role'              => self::cxm_attr( $el, 'role' ),
				'title'             => self::text( self::child( $el, 'title' ) ),
				'description'       => self::text( self::child( $el, 'description' ) ),
				'move_on'           => $el->getAttribute( 'moveOn' ) ? $el->getAttribute( 'moveOn' ) : 'NotApplicable',
				'mastery_score'     => '' === $mastery ? null : (float) $mastery,
				'launch_method'     => $el->getAttribute( 'launchMethod' ) ? $el->getAttribute( 'launchMethod' ) : 'AnyWindow',
				'url'               => self::child( $el, 'url' )->textContent,
				'launch_parameters' => $params ? $params->textContent : null,
				'entitlement_key'   => $entitlement ? $entitlement->textContent : null,
				'activity_type'     => $el->getAttribute( 'activityType' ),
				'parent_block_id'   => $parent_block_id,
				'lesson_src'        => $lesson ? $lesson->getAttribute( 'src' ) : null,
			)
		);
	}

	/**
	 * First child element with a local name in a namespace.
	 *
	 * @param \DOMElement $node Parent.
	 * @param string      $name Local name.
	 * @param string      $ns   Namespace.
	 *
	 * @return \DOMElement|null
	 */
	private static function child( \DOMElement $node, string $name, string $ns = self::NS ): ?\DOMElement {
		foreach ( $node->childNodes as $el ) {
			if ( $el instanceof \DOMElement && $name === $el->localName && $ns === $el->namespaceURI ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * A textType element as a language map ('' for a langstring without lang).
	 *
	 * @param \DOMElement $el Element of textType.
	 *
	 * @return array<string,string>
	 */
	private static function text( \DOMElement $el ): array {
		$out = array();
		foreach ( $el->childNodes as $ls ) {
			if ( $ls instanceof \DOMElement && 'langstring' === $ls->localName && self::NS === $ls->namespaceURI ) {
				$out[ $ls->getAttribute( 'lang' ) ] = $ls->textContent;
			}
		}
		return $out;
	}

	/**
	 * A cxm attribute, or null when absent.
	 *
	 * @param \DOMElement $el   Element.
	 * @param string      $name Local name.
	 *
	 * @return string|null
	 */
	private static function cxm_attr( \DOMElement $el, string $name ): ?string {
		return $el->hasAttributeNS( Vocab::CXM_NS, $name ) ? $el->getAttributeNS( Vocab::CXM_NS, $name ) : null;
	}
}
