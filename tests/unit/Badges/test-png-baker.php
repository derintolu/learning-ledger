<?php
/**
 * Png_Baker tests.
 *
 * @package LearningLedger
 */

use LearningLedger\Badges\Delivery\Png_Baker;
use LearningLedger\Util\Invalid_Input;

class Png_Baker_Test extends \PHPUnit\Framework\TestCase {

	const CREDENTIAL = '{"@context":["https://www.w3.org/ns/credentials/v2"],"id":"urn:uuid:0b6c6a43-3c8e-4a8f-9c43-2d8f1f6f8a10","name":"Café badge","narrative":"Français"}';

	/**
	 * A small PNG drawn with GD.
	 *
	 * @return string
	 */
	private static function png(): string {
		$img = imagecreatetruecolor( 4, 3 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 200, 30, 40 ) );
		ob_start();
		imagepng( $img );
		return (string) ob_get_clean();
	}

	/**
	 * Chunks of a PNG as [type, data, crc-is-valid].
	 *
	 * @param string $png PNG bytes.
	 *
	 * @return array<int, array{0:string,1:string,2:bool}>
	 */
	private static function chunks( string $png ): array {
		$out = array();
		$pos = 8;
		$end = strlen( $png );
		while ( $pos < $end ) {
			$len   = unpack( 'N', substr( $png, $pos, 4 ) )[1];
			$type  = substr( $png, $pos + 4, 4 );
			$data  = substr( $png, $pos + 8, $len );
			$crc   = unpack( 'N', substr( $png, $pos + 8 + $len, 4 ) )[1];
			$out[] = array( $type, $data, crc32( $type . $data ) === $crc );
			$pos  += 12 + $len;
		}
		return $out;
	}

	public function test_bake_then_extract_round_trips_the_credential() {
		$json  = json_decode( self::CREDENTIAL, true );
		$text  = (string) json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in unit tests.
		$baked = Png_Baker::bake( self::png(), $text );

		$this->assertSame( $text, Png_Baker::extract( $baked ) );
		$this->assertStringContainsString( 'Café badge', (string) Png_Baker::extract( $baked ) );
	}

	public function test_baked_png_has_one_uncompressed_itxt_before_iend_with_valid_crcs() {
		$baked  = Png_Baker::bake( self::png(), self::CREDENTIAL );
		$chunks = self::chunks( $baked );

		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( $baked, 0, 8 ) );
		foreach ( $chunks as $chunk ) {
			$this->assertTrue( $chunk[2], 'CRC of ' . $chunk[0] );
		}
		$types = array_column( $chunks, 0 );
		$this->assertSame( 'IEND', end( $types ) );
		$this->assertSame( 'iTXt', $types[ count( $types ) - 2 ] );
		$this->assertSame( 1, count( array_keys( $types, 'iTXt', true ) ) );
		$this->assertSame( "openbadgecredential\0\0\0\0\0" . self::CREDENTIAL, $chunks[ count( $chunks ) - 2 ][1] );
	}

	public function test_baked_png_still_decodes() {
		$baked = Png_Baker::bake( self::png(), self::CREDENTIAL );
		$img   = imagecreatefromstring( $baked );

		$this->assertInstanceOf( \GdImage::class, $img );
		$this->assertSame( 4, imagesx( $img ) );
		$this->assertSame( 3, imagesy( $img ) );
	}

	public function test_bundled_default_badge_bakes_and_decodes() {
		$png   = (string) file_get_contents( dirname( __DIR__, 3 ) . '/resources/badges/default-badge.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$baked = Png_Baker::bake( $png, self::CREDENTIAL );

		$this->assertInstanceOf( \GdImage::class, imagecreatefromstring( $baked ) );
		$this->assertSame( self::CREDENTIAL, Png_Baker::extract( $baked ) );
	}

	public function test_baking_again_replaces_the_credential() {
		$once  = Png_Baker::bake( self::png(), self::CREDENTIAL );
		$twice = Png_Baker::bake( $once, 'eyJhbGciOiJFZERTQSJ9.eyJzdWIiOiJ4In0.c2ln' );

		$this->assertSame( 'eyJhbGciOiJFZERTQSJ9.eyJzdWIiOiJ4In0.c2ln', Png_Baker::extract( $twice ) );
		$this->assertSame( 1, count( array_keys( array_column( self::chunks( $twice ), 0 ), 'iTXt', true ) ) );
	}

	public function test_baking_drops_an_existing_credential_chunk_even_when_it_cannot_be_decoded() {
		$png  = self::png();
		$data = "openbadgecredential\0\1\0\0\0not zlib data";
		$iend = strrpos( $png, 'IEND' ) - 4;
		$png  = substr( $png, 0, $iend ) . pack( 'N', strlen( $data ) ) . 'iTXt' . $data . pack( 'N', crc32( 'iTXt' . $data ) ) . substr( $png, $iend );

		$baked = Png_Baker::bake( $png, self::CREDENTIAL );

		$this->assertSame( 1, count( array_keys( array_column( self::chunks( $baked ), 0 ), 'iTXt', true ) ) );
		$this->assertSame( self::CREDENTIAL, Png_Baker::extract( $baked ) );
	}

	public function test_extract_reads_a_compressed_itxt() {
		$png  = self::png();
		$data = "openbadgecredential\0\1\0\0\0" . gzcompress( self::CREDENTIAL );
		$iend = strrpos( $png, 'IEND' ) - 4;
		$png  = substr( $png, 0, $iend ) . pack( 'N', strlen( $data ) ) . 'iTXt' . $data . pack( 'N', crc32( 'iTXt' . $data ) ) . substr( $png, $iend );

		$this->assertSame( self::CREDENTIAL, Png_Baker::extract( $png ) );
	}

	public function test_extract_is_null_without_a_credential_or_for_bad_input() {
		$this->assertNull( Png_Baker::extract( self::png() ) );
		$this->assertNull( Png_Baker::extract( 'not a png' ) );
		$this->assertNull( Png_Baker::extract( substr( self::png(), 0, 20 ) ) );
	}

	public function test_bake_refuses_bytes_that_are_not_a_png() {
		foreach ( array( 'not a png', "\x89PNG\r\n\x1a\n", substr( self::png(), 0, 30 ) ) as $bad ) {
			try {
				Png_Baker::bake( $bad, self::CREDENTIAL );
				$this->fail( 'Expected Invalid_Input' );
			} catch ( Invalid_Input $e ) {
				$this->assertSame( 'invalid_png', $e->error_code() );
			}
		}
	}

	public function test_bake_refuses_a_chunk_with_a_bad_crc() {
		$png = self::png();
		$png = substr( $png, 0, 29 ) . chr( ord( $png[29] ) ^ 0xff ) . substr( $png, 30 ); // IHDR CRC bytes are 29..32.

		$this->expectException( Invalid_Input::class );
		Png_Baker::bake( $png, self::CREDENTIAL );
	}
}
