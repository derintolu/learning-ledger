<?php
/**
 * The issuer's Open Badges Profile.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Badges\Issuer;

/**
 * OB 3.0 issuer Profile document.
 */
final class Issuer_Profile {

	/**
	 * Option holding the issuer's display name.
	 */
	const NAME_OPTION = 'lledger_issuer_name';

	/**
	 * Profile document. The name falls back to the site title when the option is empty.
	 *
	 * @return array
	 */
	public static function document(): array {
		$name = (string) get_option( self::NAME_OPTION, '' );
		return array(
			'@context' => array( 'https://www.w3.org/ns/credentials/v2', 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' ),
			'id'       => Did_Document::did(),
			'type'     => array( 'Profile' ),
			'name'     => '' !== $name ? $name : (string) get_bloginfo( 'name' ),
		);
	}
}
