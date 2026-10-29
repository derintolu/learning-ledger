<?php
/**
 * Plugin Name: Learning Ledger
 * Description: An xAPI LRS and Open Badges issuer for WordPress, with LMS connectors
 * Version: 0.1.6
 * Requires at least: 6.6
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: learning-ledger
 *
 * @package LearningLedger
 */

defined( 'ABSPATH' ) || exit;

define( 'LEARNING_LEDGER_VERSION', '0.1.6' );
define( 'LEARNING_LEDGER_FILE', __FILE__ );
define( 'LEARNING_LEDGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEARNING_LEDGER_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( LEARNING_LEDGER_DIR . 'vendor/autoload.php' ) ) {
	require_once LEARNING_LEDGER_DIR . 'vendor/autoload.php';
}
require_once LEARNING_LEDGER_DIR . 'src/autoload.php';

if ( class_exists( '\LearningLedger\Kernel\Activation' ) ) {
	register_activation_hook( __FILE__, array( '\LearningLedger\Kernel\Activation', 'activate' ) );
	register_deactivation_hook( __FILE__, array( '\LearningLedger\Kernel\Activation', 'deactivate' ) );
}

if ( class_exists( '\LearningLedger\Kernel\Plugin' ) ) {
	\LearningLedger\Kernel\Plugin::instance()->boot();
}
