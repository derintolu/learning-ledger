<?php
/**
 * Tells the course server when a program's content changed.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Packages;

use LearningLedger\Connector\Lms\Integration_Loader;
use LearningLedger\Connector\Pairing\Connector_Keys;
use LearningLedger\Connector\Pairing\Lrs_Client;
use LearningLedger\Connector\Programs\Program_Repository;
use LearningLedger\Queue\Job_Queue;
use LearningLedger\Util\Invalid_Input;

/**
 * A save of a program, or of one of its courses or lessons, queues one delayed `content_ping` job per
 * program. The job rebuilds the full package and, when its ETag differs from the last one the course
 * server was told about, sends a signed `POST <course server>/content-changed`. Nothing is sent during the save.
 */
final class Content_Ping {

	const JOB = 'content_ping';

	/**
	 * Seconds to wait so a burst of saves becomes one ping.
	 */
	const DELAY = 10;

	/**
	 * Program meta holding the ETag the course server was last told about.
	 */
	const PINGED_META = '_lledger_pinged_etag';

	const TIMEOUT = 10;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'learning_ledger/jobs/' . self::JOB, array( $this, 'run' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Queue a ping for the program a saved post belongs to.
	 *
	 * @param integer  $post_id Post id.
	 * @param \WP_Post $post    Post.
	 *
	 * @return void
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$program = $this->program_for( $post );
		if ( null === $program ) {
			return;
		}
		Job_Queue::push( self::JOB, array( 'program' => $program ), 'ping:' . $program, self::DELAY );
	}

	/**
	 * Job handler: rebuild and, if the content changed, ping. Throws so the queue retries a failed ping.
	 *
	 * @param array $payload Job payload `{program}`.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the build fails or the course server does not accept the ping.
	 */
	public function run( array $payload ): void {
		$id      = (int) ( $payload['program'] ?? 0 );
		$program = ( new Program_Repository() )->get( $id );
		if ( null === $program || '' === $program->course_server_url || '' === $program->stable_key ) {
			return;
		}
		try {
			$built = ( new Package_Builder() )->build( $id, 'full' );
		} catch ( Invalid_Input $e ) {
			if ( 'not_found' === $e->error_code() ) {
				return;
			}
			throw new \RuntimeException( 'The package could not be built (' . esc_html( $e->error_code() ) . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( (string) get_post_meta( $id, self::PINGED_META, true ) === $built->etag ) {
			return;
		}

		$url  = trailingslashit( $program->course_server_url ) . 'content-changed';
		$body = (string) wp_json_encode(
			array(
				'program' => $program->stable_key,
				'version' => $built->version,
				'etag'    => $built->etag,
			)
		);
		$res  = Lrs_Client::to_response(
			wp_remote_request(
				$url,
				array(
					'method'      => 'POST',
					'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), Connector_Keys::sign( 'POST', $url, $body ) ),
					'body'        => $body,
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
				)
			)
		);
		if ( $res->status < 200 || $res->status >= 300 ) {
			throw new \RuntimeException( sprintf( 'The course server did not accept the content-changed ping (HTTP %d).', (int) $res->status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		update_post_meta( $id, self::PINGED_META, $built->etag );
	}

	/**
	 * The program a post is, or belongs to as a course or lesson.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return integer|null
	 */
	private function program_for( \WP_Post $post ): ?int {
		if ( Program_Repository::POST_TYPE === $post->post_type ) {
			return $post->ID;
		}
		if ( null === Integration_Loader::for_post( $post->ID ) ) {
			return null;
		}
		$repo = new Program_Repository();
		$of   = $repo->for_lms_course( $post->ID );
		if ( null !== $of ) {
			return $of;
		}
		foreach ( $repo->all_ids() as $program_id ) {
			$program = $repo->get( $program_id );
			foreach ( null === $program ? array() : $program->course_ids as $course_id ) {
				$adapter = Integration_Loader::for_post( $course_id );
				if ( null === $adapter ) {
					continue;
				}
				try {
					$lessons = $adapter->course_tree( $course_id )->lessons;
				} catch ( \Throwable $e ) {
					continue;
				}
				foreach ( $lessons as $node ) {
					if ( $node->id === $post->ID ) {
						return $program_id;
					}
				}
			}
		}
		return null;
	}
}
