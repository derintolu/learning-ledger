import { __ } from '@wordpress/i18n';
import { TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * A meta value stored as a JSON string. Invalid JSON is flagged and not saved.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {string}   props.value    Stored JSON string.
 * @param {Function} props.onChange Receives the new JSON string.
 * @param {Function} props.check    Optional shape check on the parsed value.
 */
export function JsonField( { label, value, onChange, check } ) {
	const [ draft, setDraft ] = useState( null );
	const [ error, setError ] = useState( '' );
	const text = draft ?? value ?? '';
	const edit = ( next ) => {
		setDraft( next );
		if ( next.trim() === '' ) {
			setError( '' );
			onChange( '' );
			return;
		}
		try {
			const parsed = JSON.parse( next );
			if ( check && ! check( parsed ) ) {
				setError( __( 'Unexpected JSON shape.', 'learning-ledger' ) );
				return;
			}
			setError( '' );
			onChange( next );
		} catch ( e ) {
			setError( __( 'Invalid JSON.', 'learning-ledger' ) );
		}
	};
	return (
		<TextareaControl
			label={ label }
			value={ text }
			rows={ 6 }
			onChange={ edit }
			help={ error || undefined }
			className={ error ? 'has-error' : undefined }
		/>
	);
}

const isObj = ( v ) => v !== null && typeof v === 'object' && ! Array.isArray( v );
const strs = ( o, keys ) => keys.every( ( k ) => typeof o[ k ] === 'string' );

export const isFiles = ( v ) =>
	Array.isArray( v ) && v.every( ( f ) => isObj( f ) && strs( f, [ 'path', 'content' ] ) );
export const isSources = ( v ) =>
	Array.isArray( v ) && v.every( ( s ) => isObj( s ) && strs( s, [ 'title', 'url' ] ) );
export const isPreview = ( v ) =>
	isObj( v ) && typeof v.kind === 'string' && isFiles( v.files );
export const isOpaqueObject = isObj;
