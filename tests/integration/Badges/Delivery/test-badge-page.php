<?php
/**
 * Badge_Page and Achievement_Controller tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Claim\Claim_Page;
use LearningLedger\Badges\Claim\Credential_Builder;
use LearningLedger\Badges\Delivery\Badge_Page;
use LearningLedger\Badges\Delivery\Delivery_Module;
use LearningLedger\Badges\Delivery\Png_Baker;
use LearningLedger\Badges\Issuer\Issuer_Profile;
use LearningLedger\Badges\Pipeline\Badge_Repository;
use LearningLedger\Crypto\Hmac;
use LearningLedger\Db\Installer;
use LearningLedger\Db\Tables;
use LearningLedger\Util\Clock;
use LearningLedger\Util\Uuid;

class Badge_Page_Test extends WP_UnitTestCase {

	const ACHIEVEMENT = 'https://example.org/badges/one';
	const NAME        = 'Badge <b>one</b> & "more"';
	const SALT        = '0123456789abcdef0123456789abcdef';
	const RECIPIENT   = 'sha256$1f2e3d4c5b6a79881f2e3d4c5b6a79881f2e3d4c5b6a79881f2e3d4c5b6a7988';

	private \DateTimeImmutable $t0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( 'badges' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( Issuer_Profile::NAME_OPTION, 'Learn <i>WordPress</i>' );
		Hmac::set_key_for_tests( 'test-key' );
		$this->t0 = new \DateTimeImmutable( '2026-10-27T10:00:00.000000Z' );
		Clock::freeze( $this->t0 );
	}

	public function tear_down() {
		delete_option( Issuer_Profile::NAME_OPTION );
		Clock::freeze( null );
		Hmac::set_key_for_tests( null );
		parent::tear_down();
	}

	/**
	 * Create an issued badge.
	 *
	 * @param boolean           $shared      Whether the learner shared it.
	 * @param array             $achievement Achievement fields to merge into the default.
	 * @param array|string|null $credential  Credential; null for a JSON-LD one.
	 *
	 * @return array{0:string,1:array|string} Badge id and credential.
	 */
	private function issued( bool $shared, array $achievement = array(), array|string|null $credential = null ): array {
		$repo       = new Badge_Repository();
		$id         = (string) $repo->create_pending(
			$achievement + array(
				'id'              => self::ACHIEVEMENT,
				'name'            => self::NAME,
				'description'     => 'Earned <script>alert(1)</script>.',
				'criteria'        => array( 'narrative' => 'Do one & two.' ),
				'achievementType' => 'Badge',
			),
			Uuid::v4(),
			sha1( 'actor', true ),
			'https://example.org/courses/one',
			Uuid::v4(),
			'1 of 1 lessons completed',
			0
		);
		$credential = $credential ?? array(
			'@context'          => array( 'https://www.w3.org/ns/credentials/v2' ),
			'id'                => 'urn:uuid:' . $id,
			'type'              => array( 'VerifiableCredential', 'OpenBadgeCredential' ),
			'issuer'            => array(
				'id'   => 'did:web:example.org',
				'type' => array( 'Profile' ),
				'name' => 'Learn <i>WordPress</i>',
			),
			'validFrom'         => '2026-10-27T10:00:00Z',
			'name'              => self::NAME,
			'credentialSubject' => array(
				'identifier' => array(
					array(
						'type'         => 'IdentityObject',
						'identityHash' => self::RECIPIENT,
						'hashed'       => true,
						'salt'         => self::SALT,
					),
				),
			),
		);
		$this->assertTrue( $repo->store_issued( $id, $credential, 1, 7, self::RECIPIENT, self::SALT, $shared ) );
		return array( $id, $credential );
	}

	private function page(): Badge_Page {
		return new class() extends Badge_Page {
			public int $status   = 0;
			public array $sent   = array();
			public bool $missing = false;

			protected function send_headers( int $status, array $headers ): void {
				$this->status = $status;
				$this->sent   = $headers;
			}

			protected function not_found(): void {
				$this->missing = true;
				$this->status  = 404;
			}

			protected function finish(): void {
				throw new RuntimeException( 'finished' );
			}
		};
	}

	/**
	 * Serve one request and return the body.
	 *
	 * @param Badge_Page $page   Page.
	 * @param string     $id     Badge id from the path.
	 * @param string     $format '', 'json' or 'png'.
	 * @param string     $exp    `exp` parameter.
	 * @param string     $sig    `sig` parameter.
	 *
	 * @return string
	 */
	private function serve( Badge_Page $page, string $id, string $format = '', string $exp = '', string $sig = '' ): string {
		ob_start();
		try {
			$page->serve( $id, $format, $exp, $sig );
			if ( ! $page->missing ) {
				$this->fail( 'Expected the response to finish' );
			}
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			$body = (string) ob_get_clean();
		}
		return $body;
	}

	/**
	 * The `exp` and `sig` of a signed download link, as the claim page builds them.
	 *
	 * @param string  $id  Badge id.
	 * @param integer $exp Expiry.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function signed( string $id, int $exp ): array {
		$query = array();
		wp_parse_str( (string) wp_parse_url( Claim_Page::download_url( $id, 'json', $exp ), PHP_URL_QUERY ), $query );
		return array( (string) $query['exp'], (string) $query['sig'] );
	}

	private function assert_not_found( Badge_Page $page, string $body ): void {
		$this->assertTrue( $page->missing );
		$this->assertSame( 404, $page->status );
		$this->assertSame( '', $body );
	}

	public function test_unshared_badge_page_and_downloads_are_404() {
		[ $id ] = $this->issued( false );
		foreach ( array( '', 'json', 'png' ) as $format ) {
			$page = $this->page();
			$this->assert_not_found( $page, $this->serve( $page, $id, $format ) );
		}
	}

	public function test_shared_badge_page_shows_escaped_fields_and_no_recipient() {
		[ $id ] = $this->issued( true );
		$page   = $this->page();
		$body   = $this->serve( $page, $id );

		$this->assertSame( 200, $page->status );
		$this->assertSame( 'text/html; charset=utf-8', $page->sent['Content-Type'] );
		$this->assertSame( 'nosniff', $page->sent['X-Content-Type-Options'] );
		$this->assertStringContainsString( "default-src 'none'", $page->sent['Content-Security-Policy'] );
		$this->assertStringContainsString( 'Badge &lt;b&gt;one&lt;/b&gt; &amp; &quot;more&quot;', $body );
		$this->assertStringNotContainsString( '<b>one</b>', $body );
		$this->assertStringContainsString( 'Earned &lt;script&gt;alert(1)&lt;/script&gt;.', $body );
		$this->assertStringNotContainsString( '<script>alert', $body );
		$this->assertStringContainsString( 'Do one &amp; two.', $body );
		$this->assertStringContainsString( 'Learn &lt;i&gt;WordPress&lt;/i&gt;', $body );
		$this->assertStringContainsString( 'datetime="2026-10-27T10:00:00Z"', $body );
		$this->assertStringContainsString( esc_html( wp_date( (string) get_option( 'date_format' ), $this->t0->getTimestamp() ) ), $body );
		$this->assertStringContainsString( 'value="' . esc_attr( home_url( '/badges/' . $id ) ) . '"', $body );
		$this->assertStringContainsString( 'Copy link', $body );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/badges/' . $id . '.json' ) ) . '"', $body );
		$this->assertStringContainsString( 'src="' . esc_url( home_url( '/badges/' . $id . '.png' ) ) . '"', $body );
		$this->assertStringNotContainsString( self::SALT, $body );
		$this->assertStringNotContainsString( self::RECIPIENT, $body );
	}

	public function test_shared_badge_downloads_the_json_and_a_baked_png() {
		[ $id, $credential ] = $this->issued( true );

		$page = $this->page();
		$json = $this->serve( $page, $id, 'json' );
		$this->assertSame( 200, $page->status );
		$this->assertSame( 'application/ld+json; charset=utf-8', $page->sent['Content-Type'] );
		$this->assertSame( 'attachment; filename="badge-' . $id . '.json"', $page->sent['Content-Disposition'] );
		$this->assertSame( $credential, json_decode( $json, true ) );

		$page = $this->page();
		$png  = $this->serve( $page, strtoupper( $id ), 'png' );
		$this->assertSame( 200, $page->status );
		$this->assertSame( 'image/png', $page->sent['Content-Type'] );
		$this->assertSame( $credential, json_decode( (string) Png_Baker::extract( $png ), true ) );
		$this->assertInstanceOf( \GdImage::class, imagecreatefromstring( $png ) );
		$this->assertSame( 256, imagesx( imagecreatefromstring( $png ) ), 'The bundled default image' );
	}

	public function test_signed_download_works_for_15_minutes_then_fails() {
		[ $id, $credential ] = $this->issued( false );
		[ $exp, $sig ]       = self::signed( $id, $this->t0->getTimestamp() + Claim_Page::DOWNLOAD_TTL );

		foreach ( array( 'json', 'png' ) as $format ) {
			$page = $this->page();
			$body = $this->serve( $page, $id, $format, $exp, $sig );
			$this->assertSame( 200, $page->status, $format );
			$this->assertSame( 'no-store', $page->sent['Cache-Control'] );
			$this->assertSame( 'no-referrer', $page->sent['Referrer-Policy'] );
			$this->assertSame( $credential, json_decode( 'png' === $format ? (string) Png_Baker::extract( $body ) : $body, true ) );
		}

		// The signature never opens the HTML page of an unshared badge.
		$page = $this->page();
		$this->assert_not_found( $page, $this->serve( $page, $id, '', $exp, $sig ) );

		Clock::freeze( $this->t0->modify( '+900 seconds' ) );
		$page = $this->page();
		$this->serve( $page, $id, 'json', $exp, $sig );
		$this->assertSame( 200, $page->status, 'Still valid at the expiry second' );

		Clock::freeze( $this->t0->modify( '+901 seconds' ) );
		foreach ( array( 'json', 'png' ) as $format ) {
			$page = $this->page();
			$this->assert_not_found( $page, $this->serve( $page, $id, $format, $exp, $sig ) );
		}
	}

	public function test_signed_download_refuses_a_bad_signature() {
		[ $id ]         = $this->issued( false );
		[ $other ]      = $this->issued( false, array( 'id' => 'https://example.org/badges/two' ) );
		$exp            = $this->t0->getTimestamp() + Claim_Page::DOWNLOAD_TTL;
		[ $e, $sig ]    = self::signed( $id, $exp );
		[ , $osig ]     = self::signed( $other, $exp );
		[ $far, $fsig ] = self::signed( $id, $exp + 86400 );
		$purpose        = rtrim( strtr( base64_encode( Hmac::sign( $id . '|' . $exp, 'other-purpose' ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$cases          = array(
			'other badge'     => array( $e, $osig ),
			'changed expiry'  => array( (string) ( $exp - 60 ), $sig ),
			'other purpose'   => array( $e, $purpose ),
			'padded'          => array( $e, $sig . '=' ),
			'empty'           => array( $e, '' ),
			'no expiry'       => array( '', $sig ),
			'beyond the TTL'  => array( $far, $fsig ),
			'not a timestamp' => array( $e . 'x', $sig ),
		);
		foreach ( $cases as $label => [ $exp_param, $sig_param ] ) {
			$page = $this->page();
			$this->assert_not_found( $page, $this->serve( $page, $id, 'json', $exp_param, $sig_param ) );
			$this->assertTrue( $page->missing, $label );
		}
	}

	public function test_only_issued_badges_are_served() {
		[ $id ]  = $this->issued( true );
		$pending = (string) ( new Badge_Repository() )->create_pending(
			array(
				'id'   => 'https://example.org/badges/pending',
				'name' => 'Pending',
			),
			Uuid::v4(),
			sha1( 'actor', true ),
			'https://example.org/courses/one',
			Uuid::v4(),
			'n',
			0
		);
		( new Badge_Repository() )->set_status( $id, Badge_Repository::REVOKED );
		[ $exp, $sig ] = self::signed( $pending, $this->t0->getTimestamp() + 900 );

		foreach ( array( array( $id, '' ), array( $id, 'json' ), array( $pending, 'json' ), array( Uuid::v4(), '' ), array( 'not-a-uuid', 'png' ) ) as [ $badge, $format ] ) {
			$page = $this->page();
			$this->assert_not_found( $page, $this->serve( $page, $badge, $format, $exp, $sig ) );
		}
	}

	public function test_vc_jwt_credential_is_served_as_stored() {
		$jwt    = 'eyJhbGciOiJFZERTQSIsInR5cCI6InZjK2p3dCJ9.eyJzdWIiOiJ4In0.c2lnbmF0dXJl';
		[ $id ] = $this->issued( true, array(), $jwt );

		$page = $this->page();
		$body = $this->serve( $page, $id, 'json' );
		$this->assertSame( 200, $page->status );
		$this->assertSame( 'application/vc+jwt', $page->sent['Content-Type'] );
		$this->assertSame( 'attachment; filename="badge-' . $id . '.jwt"', $page->sent['Content-Disposition'] );
		$this->assertSame( $jwt, $body );

		$page = $this->page();
		$this->assertSame( $jwt, Png_Baker::extract( $this->serve( $page, $id, 'png' ) ) );

		$page = $this->page();
		$html = $this->serve( $page, $id );
		$this->assertSame( 200, $page->status );
		$this->assertStringContainsString( 'Learn &lt;i&gt;WordPress&lt;/i&gt;', $html );
	}

	public function test_png_uses_the_achievement_image_only_when_it_is_a_png_attachment_here() {
		$file = get_temp_dir() . 'lledger-badge-art.png';
		imagepng( imagecreatetruecolor( 12, 10 ), $file );
		$attachment = self::factory()->attachment->create_upload_object( $file );
		wp_delete_file( $file );
		$url = (string) wp_get_attachment_url( $attachment );

		[ $id ] = $this->issued( true, array( 'image' => $url ) );
		$page   = $this->page();
		$png    = $this->serve( $page, $id, 'png' );
		$this->assertSame( 12, imagesx( imagecreatefromstring( $png ) ) );
		$this->assertNotNull( Png_Baker::extract( $png ) );

		$fetched = false;
		add_filter(
			'pre_http_request',
			static function () use ( &$fetched ) {
				$fetched = true;
				return new WP_Error( 'blocked', 'no outbound fetch' );
			}
		);
		[ $remote ] = $this->issued(
			true,
			array(
				'id'    => 'https://example.org/badges/remote',
				'image' => 'https://images.example.org/badge.png',
			)
		);
		$page       = $this->page();
		$png        = $this->serve( $page, $remote, 'png' );
		$this->assertSame( 256, imagesx( imagecreatefromstring( $png ) ) );
		$this->assertFalse( $fetched );
		remove_all_filters( 'pre_http_request' );
		wp_delete_attachment( $attachment, true );
	}

	public function test_maybe_serve_answers_only_on_its_own_rewrite_rules() {
		[ $id ] = $this->issued( true );
		$page   = $this->page();
		set_query_var( Badge_Page::QUERY_VAR, $id );
		set_query_var( Badge_Page::FORMAT_VAR, 'json' );
		$wp   = $GLOBALS['wp'];
		$rule = $wp->matched_rule;

		$wp->matched_rule = null;
		ob_start();
		$page->maybe_serve();
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( 0, $page->status );

		$wp->matched_rule = Badge_Page::FILE_RULE;
		try {
			ob_start();
			$page->maybe_serve();
			$this->fail( 'Expected the response to finish' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'finished', $e->getMessage() );
		} finally {
			$body             = (string) ob_get_clean();
			$wp->matched_rule = $rule;
			set_query_var( Badge_Page::QUERY_VAR, '' );
			set_query_var( Badge_Page::FORMAT_VAR, '' );
		}
		$this->assertSame( 200, $page->status );
		$this->assertSame( 'urn:uuid:' . $id, json_decode( $body, true )['id'] );
	}

	public function test_rewrite_rules_route_badge_urls_but_not_claim_urls() {
		$id = Uuid::v4();
		$this->assertSame( 1, preg_match( '#' . Badge_Page::PAGE_RULE . '#', 'badges/' . $id ) );
		$this->assertSame( 1, preg_match( '#' . Badge_Page::FILE_RULE . '#', 'badges/' . $id . '.png' ) );
		$this->assertSame( 1, preg_match( '#' . Badge_Page::FILE_RULE . '#', 'badges/' . $id . '.json' ) );
		$this->assertSame( 0, preg_match( '#' . Badge_Page::FILE_RULE . '#', 'badges/' . $id . '.svg' ) );
		$this->assertSame( 0, preg_match( '#' . Badge_Page::PAGE_RULE . '#', 'badges/claim/' . str_repeat( 'A', 43 ) ) );
	}

	// Achievement_Controller.

	private function get_achievement( string $hash ): WP_REST_Response {
		( new Delivery_Module() )->register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		try {
			return rest_do_request( new WP_REST_Request( 'GET', '/learning-ledger/v1/achievements/' . $hash ) );
		} finally {
			$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_achievement_route_returns_the_declaration_fields_only_and_is_cacheable() {
		[ $id ]   = $this->issued(
			true,
			array(
				'image'       => 'https://example.org/badge.png',
				'awardOn'     => 'course-satisfied',
				'countWaived' => true,
				'alignment'   => array(
					array(
						'targetName' => 'Skill',
						'targetUrl'  => 'https://example.org/skill',
					),
				),
			)
		);
		$response = $this->get_achievement( sha1( self::ACHIEVEMENT ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'public, max-age=3600', $response->get_headers()['Cache-Control'] );
		$data = $response->get_data();
		$this->assertSame( self::ACHIEVEMENT, $data['id'] );
		$this->assertSame( array( 'Achievement' ), $data['type'] );
		$this->assertSame( self::NAME, $data['name'] );
		$this->assertSame( 'Earned <script>alert(1)</script>.', $data['description'] );
		$this->assertSame( array( 'narrative' => 'Do one & two.' ), $data['criteria'] );
		$this->assertSame( 'Badge', $data['achievementType'] );
		$this->assertSame(
			array(
				'id'   => 'https://example.org/badge.png',
				'type' => 'Image',
			),
			$data['image']
		);
		$this->assertSame( 'Skill', $data['alignment'][0]['targetName'] );
		$this->assertSame(
			array( '@context', 'id', 'type', 'name', 'description', 'criteria', 'image', 'achievementType', 'alignment' ),
			array_keys( $data )
		);
		$this->assertStringNotContainsString( self::SALT, (string) wp_json_encode( $data ) );
		$this->assertNotEmpty( $id );
	}

	public function test_achievement_route_publishes_the_achievement_exactly_as_the_credential_embeds_it() {
		$declared = array(
			'id'              => 'https://example.org/achievements/bare',
			'name'            => 'Bare',
			'description'     => 'Kept',
			'criteria'        => array( 'narrative' => 'Do it.' ),
			'achievementType' => 'Badge',
		);
		$this->issued( true, $declared );

		$data = $this->get_achievement( sha1( $declared['id'] ) )->get_data();

		$this->assertSame( array( '@context' => Credential_Builder::CONTEXT ) + Credential_Builder::achievement( $declared ), $data );
	}

	public function test_achievement_route_404s_for_an_unknown_or_unissued_achievement() {
		( new Badge_Repository() )->create_pending(
			array(
				'id'   => 'https://example.org/badges/pending',
				'name' => 'Pending',
			),
			Uuid::v4(),
			sha1( 'actor', true ),
			'https://example.org/courses/one',
			Uuid::v4(),
			'n',
			0
		);
		foreach ( array( sha1( 'https://example.org/badges/none' ), sha1( 'https://example.org/badges/pending' ), 'not-a-hash', strtoupper( sha1( self::ACHIEVEMENT ) ) ) as $hash ) {
			$this->assertSame( 404, $this->get_achievement( $hash )->get_status(), $hash );
		}
	}
}
