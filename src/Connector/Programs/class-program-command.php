<?php
/**
 * WP-CLI commands for programs.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Programs;

/**
 * Manage Learning Ledger programs.
 */
final class Program_Command {

	/**
	 * Updates a program's settings.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Program post id.
	 *
	 * [--course-server-url=<url>]
	 * : Course server URL.
	 *
	 * [--custody=<custody>]
	 * : One of lms-launched, author-lrs, server-kept, practice.
	 *
	 * [--iri-base=<iri>]
	 * : Absolute IRI base ending in a slash.
	 *
	 * [--course-iri=<iri>]
	 * : Course IRI.
	 *
	 * [--au-url-template=<url>]
	 * : AU URL template containing {key}.
	 *
	 * [--waive-recommended=<on|off>]
	 * : Whether recommended AUs may be waived.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function update( array $args, array $assoc_args ): void {
		$id = (int) $args[0];
		if ( null === ( new Program_Repository() )->get( $id ) ) {
			\WP_CLI::error( 'No program with that id.' );
		}
		$map = array(
			'course-server-url' => '_lledger_course_server_url',
			'custody'           => '_lledger_custody',
			'iri-base'          => '_lledger_iri_base',
			'course-iri'        => '_lledger_course_iri',
			'au-url-template'   => '_lledger_au_url_template',
		);
		$set = array();
		foreach ( $map as $flag => $meta_key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$value = (string) $assoc_args[ $flag ];
				if ( 'custody' === $flag && ! in_array( $value, Program_Module::CUSTODY, true ) ) {
					\WP_CLI::error( 'custody must be one of: ' . implode( ', ', Program_Module::CUSTODY ) );
				}
				if ( 'iri-base' === $flag ) {
					$value = Program_Module::sanitize_iri_base( $value );
					if ( '' === $value ) {
						\WP_CLI::error( 'iri-base must be an absolute http(s) IRI.' );
					}
				}
				if ( in_array( $flag, array( 'course-iri', 'course-server-url' ), true ) ) {
					$value = Program_Module::sanitize_url( $value );
					if ( '' === $value ) {
						\WP_CLI::error( $flag . ' must be an absolute http(s) URL.' );
					}
				}
				if ( 'au-url-template' === $flag ) {
					$value = Program_Module::sanitize_au_url_template( $value );
					if ( '' === $value ) {
						\WP_CLI::error( 'au-url-template must be an http(s) URL containing {key}.' );
					}
				}
				$set[ $meta_key ] = $value;
			}
		}
		if ( isset( $assoc_args['waive-recommended'] ) ) {
			$waive = (string) $assoc_args['waive-recommended'];
			if ( ! in_array( $waive, array( 'on', 'off' ), true ) ) {
				\WP_CLI::error( 'waive-recommended must be on or off.' );
			}
			$set['_lledger_waive_recommended'] = 'on' === $waive;
		}
		if ( ! $set ) {
			\WP_CLI::error( 'Nothing to update.' );
		}
		foreach ( $set as $meta_key => $value ) {
			update_post_meta( $id, $meta_key, $value );
			if ( is_string( $value ) && '' !== $value && '' === (string) get_post_meta( $id, $meta_key, true ) ) {
				\WP_CLI::error( 'Value rejected for ' . $meta_key . '.' );
			}
		}
		\WP_CLI::success( 'Program ' . $id . ' updated.' );
	}

	/**
	 * Lists programs.
	 *
	 * @subcommand list
	 *
	 * @return void
	 */
	public function list_(): void {
		$repo  = new Program_Repository();
		$items = array();
		foreach ( $repo->all_ids() as $id ) {
			$p       = $repo->get( $id );
			$items[] = array(
				'id'         => $p->id,
				'stable_key' => $p->stable_key,
				'iri_base'   => $p->iri_base,
				'custody'    => $p->custody,
				'courses'    => implode( ',', $p->course_ids ),
				'version'    => $p->version,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'stable_key', 'iri_base', 'custody', 'courses', 'version' ) );
	}
}
