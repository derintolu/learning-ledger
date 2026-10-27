import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useRef } from '@wordpress/element';

const KINDS = [ 'contains', 'notContains', 'regex', 'json' ].map( ( v ) => ( {
	label: v,
	value: v,
} ) );

function FixChecks( { rows, onChange } ) {
	// Stable per-row keys, kept parallel to rows (never stored in attributes).
	const keys = useRef( [] );
	const next = useRef( 0 );
	while ( keys.current.length < rows.length ) {
		keys.current.push( next.current++ );
	}
	keys.current.length = rows.length;
	const update = ( i, name, value ) =>
		onChange(
			rows.map( ( r, j ) => ( j === i ? { ...r, [ name ]: value } : r ) )
		);
	return (
		<>
			{ rows.map( ( row, i ) => (
				<div key={ keys.current[ i ] } className="lledger-row">
					<SelectControl
						label={ __( 'Kind', 'learning-ledger' ) }
						value={ row.kind ?? 'contains' }
						options={ KINDS }
						onChange={ ( v ) => update( i, 'kind', v ) }
					/>
					<TextControl
						label={ __( 'Value', 'learning-ledger' ) }
						value={ row.value ?? '' }
						onChange={ ( v ) => update( i, 'value', v ) }
					/>
					<TextControl
						label={ __( 'Pointer (json)', 'learning-ledger' ) }
						value={ row.pointer ?? '' }
						onChange={ ( v ) => update( i, 'pointer', v ) }
					/>
					<Button
						variant="link"
						isDestructive
						onClick={ () => {
							keys.current.splice( i, 1 );
							onChange( rows.filter( ( _, j ) => j !== i ) );
						} }
					>
						{ __( 'Remove', 'learning-ledger' ) }
					</Button>
				</div>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					onChange( [ ...rows, { kind: 'contains', value: '' } ] )
				}
			>
				{ __( 'Add', 'learning-ledger' ) }
			</Button>
		</>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const set = ( name ) => ( value ) => setAttributes( { [ name ]: value } );
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Planted bug', 'learning-ledger' ) }>
					<TextControl
						label={ __( 'Key', 'learning-ledger' ) }
						value={ attributes.key }
						onChange={ set( 'key' ) }
					/>
					<TextControl
						type="number"
						label={ __( 'Reveal explanation after (attempts)', 'learning-ledger' ) }
						value={ attributes.revealAfter ?? '' }
						onChange={ ( v ) =>
							setAttributes( {
								revealAfter: v === '' ? null : parseInt( v, 10 ),
							} )
						}
					/>
					<TextareaControl
						label={ __( 'Hint', 'learning-ledger' ) }
						value={ attributes.hint }
						onChange={ set( 'hint' ) }
					/>
					<TextareaControl
						label={ __( 'Explanation', 'learning-ledger' ) }
						value={ attributes.explanation }
						onChange={ set( 'explanation' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Fix checks', 'learning-ledger' ) } initialOpen={ false }>
					<FixChecks rows={ attributes.fixChecks } onChange={ set( 'fixChecks' ) } />
				</PanelBody>
			</InspectorControls>
			<TextControl
				label={ __( 'File', 'learning-ledger' ) }
				value={ attributes.file }
				onChange={ set( 'file' ) }
			/>
			<TextareaControl
				label={ __( 'Code', 'learning-ledger' ) }
				value={ attributes.code }
				onChange={ set( 'code' ) }
				rows={ 10 }
			/>
		</div>
	);
}
