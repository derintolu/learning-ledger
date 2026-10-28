<?php
/**
 * Shared handling for the document resources.
 *
 * @package LearningLedger
 */

namespace LearningLedger\Lrs\Documents;

use LearningLedger\Lrs\Auth\Principal;
use LearningLedger\Lrs\Auth\Scopes;
use LearningLedger\Lrs\Http\Resource_Handler;
use LearningLedger\Lrs\Http\Xapi_Error;
use LearningLedger\Lrs\Http\Xapi_Request;
use LearningLedger\Lrs\Http\Xapi_Response;
use LearningLedger\Util\Invalid_Input;
use LearningLedger\Util\Json;
use LearningLedger\Util\Timestamp;
use LearningLedger\Xapi\Agent_Identity;
use LearningLedger\Xapi\Primitives;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never echoed; HTTP layers JSON-encode them.

/**
 * GET (one or a list), PUT, POST (merge) and DELETE for State, Agent Profile and Activity Profile.
 */
abstract class Document_Handler_Base implements Resource_Handler {

	/**
	 * Storage.
	 *
	 * @var Document_Store
	 */
	protected Document_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Document_Store|null $store Storage.
	 */
	public function __construct( ?Document_Store $store = null ) {
		$this->store = $store ?? new Document_Store();
	}

	/**
	 * Name of the id parameter (`stateId` or `profileId`).
	 *
	 * @return string
	 */
	abstract protected function id_param(): string;

	/**
	 * Scope that grants writes (and reads) of this resource.
	 *
	 * @return string
	 */
	abstract protected function scope(): string;

	/**
	 * Key from the request parameters.
	 *
	 * @param Xapi_Request $r      Request.
	 * @param string|null  $doc_id Document id, if given.
	 *
	 * @return Doc_Key
	 * @throws Invalid_Input On missing or malformed parameters.
	 */
	abstract protected function key( Xapi_Request $r, ?string $doc_id ): Doc_Key;

	/**
	 * Resource-specific authorisation, after scopes and bindings passed.
	 *
	 * @param Principal $p     Caller.
	 * @param Doc_Key   $k     Key (no id for a list or delete-all).
	 * @param boolean   $write Whether the request writes.
	 *
	 * @return void
	 * @throws Xapi_Error 403.
	 */
	abstract protected function authorize_more( Principal $p, Doc_Key $k, bool $write ): void;

	/**
	 * Whether a PUT with neither If-Match nor If-None-Match on an existing document is 409 in this version.
	 *
	 * @param string $version Negotiated version.
	 *
	 * @return boolean
	 */
	abstract protected function conflict_without_precondition( string $version ): bool;

	/**
	 * Whether a PUT with neither If-Match nor If-None-Match is 400 when the document does not
	 * exist yet, in this version. Off by default; the profile resources turn it on for 1.0.3.
	 *
	 * @param string $version Negotiated version.
	 *
	 * @return boolean
	 */
	protected function precondition_required_to_create( string $version ): bool {
		return false;
	}

	/**
	 * Whether DELETE without an id deletes the set (State only).
	 *
	 * @return boolean
	 */
	protected function supports_delete_all(): bool {
		return false;
	}

	/**
	 * Handle a request.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error For error responses.
	 */
	public function handle( Xapi_Request $r ): Xapi_Response {
		$method = $r->method();
		$doc_id = self::string_param( $r, $this->id_param() );
		if ( null === $doc_id && ( 'PUT' === $method || 'POST' === $method || ( 'DELETE' === $method && ! $this->supports_delete_all() ) ) ) {
			throw new Invalid_Input( $this->id_param() . ' is required.', 'required', $this->id_param() );
		}
		$since = null;
		if ( 'GET' === $method && null === $doc_id ) {
			$raw = self::string_param( $r, 'since' );
			if ( null !== $raw ) {
				$since = Timestamp::parse( $raw, '' === $r->version() ? '2.0.0' : $r->version() );
			}
		}
		$key = $this->key( $r, $doc_id );
		$this->authorize( $r->principal(), $key, 'GET' !== $method );

		switch ( $method ) {
			case 'GET':
				return null === $doc_id ? Xapi_Response::json( 200, $this->store->list_ids( $key, $since ) ) : $this->get_one( $r, $key );
			case 'PUT':
				return $this->put( $r, $key );
			case 'POST':
				$this->store->post_merge( $key, $r->body(), self::content_type( $r ), $r->header( 'If-Match' ), $r->header( 'If-None-Match' ) );
				return new Xapi_Response( 204, '', '' );
			default:
				if ( null === $doc_id ) {
					$this->store->delete_all( $key );
				} else {
					$this->store->delete( $key, $r->header( 'If-Match' ) );
				}
				return new Xapi_Response( 204, '', '' );
		}
	}

	/**
	 * Agent from the `agent` parameter.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return Agent_Identity
	 * @throws Invalid_Input When missing or not an Agent with one IFI.
	 */
	protected static function agent_param( Xapi_Request $r ): Agent_Identity {
		$raw = self::string_param( $r, 'agent' );
		if ( null === $raw ) {
			throw new Invalid_Input( 'agent is required.', 'required', 'agent' );
		}
		$agent = Json::decode_strict( $raw, 65536, 8 );
		if ( ! is_array( $agent ) || ( array() !== $agent && Json::is_list( $agent ) ) ) {
			throw new Invalid_Input( 'agent must be a JSON object.', 'type', 'agent' );
		}
		return Agent_Identity::from_array( $agent );
	}

	/**
	 * Activity IRI from the `activityId` parameter.
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return string
	 * @throws Invalid_Input When missing or not an IRI.
	 */
	protected static function activity_param( Xapi_Request $r ): string {
		$iri = self::string_param( $r, 'activityId' );
		if ( null === $iri ) {
			throw new Invalid_Input( 'activityId is required.', 'required', 'activityId' );
		}
		Primitives::assert_iri( $iri, 'activityId' );
		return $iri;
	}

	/**
	 * A string query parameter, or null when absent.
	 *
	 * @param Xapi_Request $r    Request.
	 * @param string       $name Name.
	 *
	 * @return string|null
	 * @throws Invalid_Input When present but not a string.
	 */
	protected static function string_param( Xapi_Request $r, string $name ): ?string {
		$v = $r->param( $name );
		if ( null === $v ) {
			return null;
		}
		if ( ! is_string( $v ) ) {
			throw new Invalid_Input( $name . ' must be a string.', 'type', $name );
		}
		return $v;
	}

	/**
	 * 403 error.
	 *
	 * @param string $message Message.
	 *
	 * @return Xapi_Error
	 */
	protected static function forbidden( string $message ): Xapi_Error {
		return new Xapi_Error( 403, $message );
	}

	/**
	 * Scopes and bindings, then the resource's own rules.
	 *
	 * @param Principal|null $p     Caller.
	 * @param Doc_Key        $k     Key.
	 * @param boolean        $write Whether the request writes.
	 *
	 * @return void
	 * @throws Xapi_Error 403.
	 */
	private function authorize( ?Principal $p, Doc_Key $k, bool $write ): void {
		if ( null === $p ) {
			throw self::forbidden( 'Authentication is required.' );
		}
		// Reads accept `all/read` by name: Scopes never lets it imply `state` or `profile`, which also allow writes.
		$allowed = $p->can( $this->scope() ) || ( ! $write && $p->can( Scopes::ALL_READ ) );
		if ( ! $allowed ) {
			throw self::forbidden( 'This client does not have the ' . $this->scope() . ' scope.' );
		}
		if ( ! $p->bindings()->allows_document( (string) $k->activity_iri, $k->agent, $k->registration() ) ) {
			throw self::forbidden( 'This document is outside the client\'s bindings.' );
		}
		$this->authorize_more( $p, $k, $write );
	}

	/**
	 * GET of one document.
	 *
	 * @param Xapi_Request $r Request.
	 * @param Doc_Key      $k Key.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error 404.
	 */
	private function get_one( Xapi_Request $r, Doc_Key $k ): Xapi_Response {
		$doc = $this->store->get( $k );
		if ( null === $doc ) {
			throw new Xapi_Error( 404, 'Document not found.' );
		}
		$headers = array( 'ETag' => '"' . $doc->etag . '"' );
		if ( '2.0.0' === $r->version() ) {
			$headers['Last-Modified'] = $doc->updated->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'D, d M Y H:i:s' ) . ' GMT';
		}
		return new Xapi_Response( 200, $doc->content, $doc->content_type, $headers );
	}

	/**
	 * PUT, with the version's rule for an existing document and no precondition.
	 *
	 * @param Xapi_Request $r Request.
	 * @param Doc_Key      $k Key.
	 *
	 * @return Xapi_Response
	 * @throws Xapi_Error 409 when the document exists and no precondition was sent (where the version requires it);
	 *                    400 when it does not and the version requires a precondition to create it.
	 */
	private function put( Xapi_Request $r, Doc_Key $k ): Xapi_Response {
		$if_match      = $r->header( 'If-Match' );
		$if_none_match = $r->header( 'If-None-Match' );
		if ( null === $if_match && null === $if_none_match && $this->precondition_required_to_create( $r->version() ) && null === $this->store->get( $k ) ) {
			throw new Xapi_Error( 400, 'Send If-None-Match: * to create this document, or If-Match with its ETag to replace it.' );
		}
		if ( null === $if_match && null === $if_none_match && $this->conflict_without_precondition( $r->version() ) ) {
			try {
				$this->store->put( $k, $r->body(), self::content_type( $r ), null, '*' );
			} catch ( Xapi_Error $e ) {
				if ( 412 !== $e->status() ) {
					throw $e;
				}
				return new Xapi_Response( 409, 'The document already exists. GET it and send its ETag in If-Match to replace it.', 'text/plain' );
			}
			return new Xapi_Response( 204, '', '' );
		}
		$this->store->put( $k, $r->body(), self::content_type( $r ), $if_match, $if_none_match );
		return new Xapi_Response( 204, '', '' );
	}

	/**
	 * Content-Type of the request body (application/octet-stream when absent).
	 *
	 * @param Xapi_Request $r Request.
	 *
	 * @return string
	 */
	private static function content_type( Xapi_Request $r ): string {
		$ct = trim( (string) $r->header( 'Content-Type' ) );
		return '' === $ct ? 'application/octet-stream' : $ct;
	}
}
