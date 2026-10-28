<?php
/**
 * Sensei LMS Certificates provider.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Integrations\Sensei;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Sensei LMS Certificates' own hooks, fired the way the plugin fires them.

use LearningLedger\Connector\Lms\Certificate_Provider;

/**
 * A learner's course certificate from Sensei LMS Certificates (2.x), as PDF bytes, without the
 * learner's session.
 *
 * The certificate post is found with the plugin's public `get_certificate_id()` and must belong to
 * the requested learner and course. The PDF is drawn with the plugin's own classes and hooks
 * (`WooThemes_Sensei_PDF_Certificate`, `Woothemes_Sensei_Certificates_TFPDF`,
 * `sensei_certificates_set_background_image`, `sensei_certificates_before_pdf_output`), in the
 * same steps as `WooThemes_Sensei_PDF_Certificate::generate_pdf()`. That method streams to the
 * browser with its own headers, so it is not called; the bytes are returned instead.
 */
final class Sensei_Certificate_Provider implements Certificate_Provider {

	/**
	 * Main plugin class.
	 */
	const PLUGIN_CLASS = 'WooThemes_Sensei_Certificates';

	/**
	 * Whether Sensei LMS Certificates is active and its rendering classes are loaded.
	 *
	 * @return boolean
	 */
	public static function available(): bool {
		if ( ! defined( 'SENSEI_CERTIFICATES_PLUGIN_FILE' ) || ! defined( 'SENSEI_CERTIFICATES_PLUGIN_BASENAME' )
			|| ! class_exists( self::PLUGIN_CLASS ) || ! class_exists( 'Woothemes_Sensei_Certificates_TFPDF' )
			|| ! method_exists( self::PLUGIN_CLASS, 'instance' ) || ! method_exists( self::PLUGIN_CLASS, 'get_certificate_id' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( (string) SENSEI_CERTIFICATES_PLUGIN_BASENAME ) ) {
			return false;
		}
		if ( ! class_exists( 'WooThemes_Sensei_PDF_Certificate' ) ) {
			$file = dirname( (string) SENSEI_CERTIFICATES_PLUGIN_FILE ) . '/classes/class-woothemes-sensei-pdf-certificate.php';
			if ( ! is_readable( $file ) ) {
				return false;
			}
			require_once $file;
		}
		return class_exists( 'WooThemes_Sensei_PDF_Certificate' );
	}

	/**
	 * The learner's certificate PDF for a course, or null when they have none.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return string|null
	 */
	public function certificate_pdf( int $user_id, int $course_id ): ?string {
		if ( ! self::available() || $user_id <= 0 || 'course' !== get_post_type( $course_id ) || ! get_userdata( $user_id ) ) {
			return null;
		}
		if ( ! \Sensei_Utils::user_completed_course( $course_id, $user_id ) ) {
			return null;
		}
		$certificate = $this->certificate_id( $user_id, $course_id );
		if ( null === $certificate ) {
			return null;
		}
		$hash = (string) get_post_meta( $certificate, 'certificate_hash', true );
		if ( '' === $hash ) {
			$hash = (string) get_post_field( 'post_name', $certificate );
		}
		return $this->render( new \WooThemes_Sensei_PDF_Certificate( $hash, $certificate ) );
	}

	/**
	 * The published certificate post of exactly this learner and course.
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return integer|null
	 */
	private function certificate_id( int $user_id, int $course_id ): ?int {
		$plugin = call_user_func( array( self::PLUGIN_CLASS, 'instance' ) );
		$id     = (int) $plugin->get_certificate_id( $course_id, $user_id );
		if ( $id <= 0
			|| 'certificate' !== get_post_type( $id )
			|| 'publish' !== get_post_status( $id )
			|| (int) get_post_meta( $id, 'learner_id', true ) !== $user_id
			|| (int) get_post_meta( $id, 'course_id', true ) !== $course_id ) {
			return null;
		}
		return $id;
	}

	/**
	 * Draw the certificate and return the PDF bytes.
	 *
	 * @param \WooThemes_Sensei_PDF_Certificate $pdf Certificate object.
	 *
	 * @return string|null
	 */
	private function render( \WooThemes_Sensei_PDF_Certificate $pdf ): ?string {
		do_action( 'sensei_certificates_set_background_image', $pdf );

		if ( isset( $pdf->bg_image_src ) && '' !== $pdf->bg_image_src ) {
			$image = (string) $pdf->bg_image_src;
		} else {
			$plugin = call_user_func( array( self::PLUGIN_CLASS, 'instance' ) );
			$image  = (string) apply_filters( 'woothemes_sensei_certificates_background', $plugin->plugin_path . 'assets/images/certificate_template.png' );
		}
		$size = is_readable( $image ) ? getimagesize( $image ) : false;
		if ( ! is_array( $size ) || $size[0] <= 0 || $size[1] <= 0 ) {
			return null;
		}

		$fpdf = \Woothemes_Sensei_Certificates_TFPDF::get_tfpdf_object(
			$size[0] > $size[1] ? 'L' : 'P',
			'pt',
			array( $size[0], $size[1] )
		);
		$fpdf->AddPage();
		$fpdf->SetAutoPageBreak( false );

		$custom_font = apply_filters( 'sensei_certificates_custom_font', false );
		if ( $custom_font ) {
			if ( isset( $custom_font['family'] ) && isset( $custom_font['file'] ) ) {
				$fpdf->AddFont( $custom_font['family'], '', $custom_font['file'], true );
			}
		} else {
			$fpdf->AddFont( 'DejaVu', '', 'DejaVuSansCondensed.ttf', true );
		}

		$fpdf->Image( $image, 0, 0, $size[0], $size[1] );

		do_action( 'sensei_certificates_before_pdf_output', $pdf, $fpdf );

		$bytes = $fpdf->output();
		return is_string( $bytes ) && str_starts_with( $bytes, '%PDF' ) ? $bytes : null;
	}
}
