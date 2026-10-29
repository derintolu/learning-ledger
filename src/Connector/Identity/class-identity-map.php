<?php
/**
 * Maps xAPI actors to WordPress users.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Connector\Identity;

use LearningLedger\Db\Tables;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Xapi\Agent_Identity;

/**
 * Actor <-> user mapping: own accounts, explicit links, optional mbox match.
 */
final class Identity_Map {

	const META = 'lledger_actor';

	/**
	 * The account homePage this site issues: home_url() without a trailing slash.
	 *
	 * @return string
	 */
	public function home_page(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * The stable pseudonymous actor for a user, minted on first use.
	 *
	 * @param integer $user User id.
	 *
	 * @return array
	 */
	public function actor_for_user( int $user ): array {
		$name = (string) get_user_meta( $user, self::META, true );
		if ( '' === $name ) {
			$name = wp_generate_uuid4();
			update_user_meta( $user, self::META, $name );
		}
		return array(
			'objectType' => 'Agent',
			'account'    => array(
				'homePage' => $this->home_page(),
				'name'     => $name,
			),
		);
	}

	/**
	 * Resolve an actor to a user id, or null.
	 *
	 * @param array $actor Agent object.
	 *
	 * @return integer|null
	 */
	public function user_for_actor( array $actor ): ?int {
		global $wpdb;

		$account = $actor['account'] ?? null;
		if ( is_array( $account ) && ( $account['homePage'] ?? null ) === $this->home_page() && is_string( $account['name'] ?? null ) && '' !== $account['name'] ) {
			// A direct query, not get_users(): some hosts filter user queries on anonymous requests
			// (pre_get_users), which inbox deliveries and their shutdown job are. Same semantics as
			// get_users( meta_key, meta_value, number 1 ): ordered by login and, on multisite, only
			// members of this site.
			$member = is_multisite() ? $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} c WHERE c.user_id = u.ID AND c.meta_key = %s)", $wpdb->get_blog_prefix() . 'capabilities' ) : '';
			$id     = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT u.ID FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE m.meta_key = %s AND m.meta_value = %s{$member} ORDER BY u.user_login ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $member is prepared above.
					self::META,
					$account['name']
				)
			);
			if ( null !== $id ) {
				return (int) $id;
			}
		}

		try {
			$hash = Agent_Identity::from_array( $actor )->hash();
		} catch ( Invalid_Input $e ) {
			return null;
		}

		$table = Tables::name( 'identity_links' );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM `{$table}` WHERE actor_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( null !== $id ) {
			return (int) $id;
		}

		$options = get_option( 'lledger_connector', array() );
		if ( is_array( $options ) && ! empty( $options['match_mbox'] ) && is_string( $actor['mbox'] ?? null ) && 0 === strpos( $actor['mbox'], 'mailto:' ) ) {
			$email = substr( $actor['mbox'], 7 );
			$user  = '' === $email ? false : get_user_by( 'email', $email );
			if ( $user ) {
				return (int) $user->ID;
			}
		}
		return null;
	}

	/**
	 * Link an identity to a user (replaces an existing link for that identity).
	 *
	 * @param Agent_Identity $identity Identity.
	 * @param integer        $user     User id.
	 *
	 * @return void
	 */
	public function link( Agent_Identity $identity, int $user ): void {
		global $wpdb;
		$table = Tables::name( 'identity_links' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"REPLACE INTO `{$table}` (actor_hash, actor, user_id, linked_at) VALUES (%s, %s, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$identity->hash(),
				wp_json_encode( $identity->to_array() ),
				$user,
				gmdate( 'Y-m-d H:i:s' ) . '.000000'
			)
		);
	}

	/**
	 * Remove every link of a user.
	 *
	 * @param integer $user User id.
	 *
	 * @return integer Rows removed.
	 */
	public function unlink_user( int $user ): int {
		global $wpdb;
		$table = Tables::name( 'identity_links' );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE user_id = %d", $user ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Links of a user.
	 *
	 * @param integer $user User id.
	 *
	 * @return array[] Each `{actor: array, linked_at: string}`.
	 */
	public function links_for_user( int $user ): array {
		global $wpdb;
		$table = Tables::name( 'identity_links' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT actor, linked_at FROM `{$table}` WHERE user_id = %d ORDER BY linked_at", $user ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'actor'     => json_decode( $row['actor'], true ),
				'linked_at' => $row['linked_at'],
			);
		}
		return $out;
	}

	/**
	 * Create an anonymous learner account: no email, read-only role.
	 *
	 * @return integer User id.
	 *
	 * @throws \RuntimeException When WordPress refuses to create the user.
	 */
	public function create_anonymous_learner(): int {
		$id = wp_insert_user(
			array(
				'user_login'      => 'lledger-' . bin2hex( random_bytes( 6 ) ),
				'user_pass'       => bin2hex( random_bytes( 32 ) ),
				'user_email'      => '',
				'role'            => Identity_Module::ROLE,
				'user_registered' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( esc_html( $id->get_error_message() ) );
		}
		return (int) $id;
	}

	/**
	 * Remove a learner's mapping data. The caller deletes the user itself.
	 *
	 * @param integer $user User id.
	 *
	 * @return void
	 */
	public function delete_learner( int $user ): void {
		delete_user_meta( $user, self::META );
		$this->unlink_user( $user );
	}
}
