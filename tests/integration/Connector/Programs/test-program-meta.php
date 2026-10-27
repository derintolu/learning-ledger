<?php
/**
 * Program meta sanitizer tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Connector\Programs\Program_Module;

class Program_Meta_Test extends WP_UnitTestCase {

	private int $id;

	public function set_up() {
		parent::set_up();
		$module = new Program_Module();
		$module->register_post_type();
		$module->register_meta();
		$this->id = self::factory()->post->create( array( 'post_type' => 'lledger_program' ) );
	}

	public function test_au_url_template_keeps_the_key_placeholder() {
		update_post_meta( $this->id, '_lledger_au_url_template', 'https://lms.example/launch?au={key}' );
		$this->assertSame( 'https://lms.example/launch?au={key}', get_post_meta( $this->id, '_lledger_au_url_template', true ) );
	}

	public function test_au_url_template_rejects_missing_placeholder_and_bad_scheme() {
		$this->assertSame( '', Program_Module::sanitize_au_url_template( 'https://lms.example/launch' ) );
		$this->assertSame( '', Program_Module::sanitize_au_url_template( 'javascript:alert({key})' ) );
		$this->assertSame( '', Program_Module::sanitize_au_url_template( 'ftp://lms.example/{key}' ) );
	}
}
