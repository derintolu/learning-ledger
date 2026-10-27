<?php
/**
 * Vocabulary of cmi5 and course-xapi-mcp.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Cmi5;

/**
 * IRIs used by cmi5 defined statements and the course-xapi-mcp extensions.
 */
final class Vocab {

	const LAUNCHED    = 'http://adlnet.gov/expapi/verbs/launched';
	const INITIALIZED = 'http://adlnet.gov/expapi/verbs/initialized';
	const COMPLETED   = 'http://adlnet.gov/expapi/verbs/completed';
	const PASSED      = 'http://adlnet.gov/expapi/verbs/passed';
	const FAILED      = 'http://adlnet.gov/expapi/verbs/failed';
	const ABANDONED   = 'https://w3id.org/xapi/adl/verbs/abandoned';
	const WAIVED      = 'https://w3id.org/xapi/adl/verbs/waived';
	const TERMINATED  = 'http://adlnet.gov/expapi/verbs/terminated';
	const SATISFIED   = 'https://w3id.org/xapi/adl/verbs/satisfied';

	/**
	 * Every cmi5 defined verb.
	 */
	const VERBS = array(
		self::LAUNCHED,
		self::INITIALIZED,
		self::COMPLETED,
		self::PASSED,
		self::FAILED,
		self::ABANDONED,
		self::WAIVED,
		self::TERMINATED,
		self::SATISFIED,
	);

	const CAT_CMI5   = 'https://w3id.org/xapi/cmi5/context/categories/cmi5';
	const CAT_MOVEON = 'https://w3id.org/xapi/cmi5/context/categories/moveon';

	const EXT_SESSIONID        = 'https://w3id.org/xapi/cmi5/context/extensions/sessionid';
	const EXT_MASTERYSCORE     = 'https://w3id.org/xapi/cmi5/context/extensions/masteryscore';
	const EXT_LAUNCHMODE       = 'https://w3id.org/xapi/cmi5/context/extensions/launchmode';
	const EXT_LAUNCHURL        = 'https://w3id.org/xapi/cmi5/context/extensions/launchurl';
	const EXT_MOVEON           = 'https://w3id.org/xapi/cmi5/context/extensions/moveon';
	const EXT_LAUNCHPARAMETERS = 'https://w3id.org/xapi/cmi5/context/extensions/launchparameters';
	const EXT_REASON           = 'https://w3id.org/xapi/cmi5/result/extensions/reason';

	const CXM_EXT_CARRIED_OVER      = 'https://course-xapi-mcp.sqwrlz.com/xapi/extensions/carried-over';
	const CXM_EXT_WAIVE_RECOMMENDED = 'https://course-xapi-mcp.sqwrlz.com/xapi/extensions/waive-recommended';
	const CXM_NS                    = 'https://course-xapi-mcp.sqwrlz.com/course/v1';

	const EARNED = 'http://specification.openbadges.org/xapi/verbs/earned';
}
