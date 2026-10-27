import { __ } from '@wordpress/i18n';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';

/**
 * Editable list of object rows with add/remove.
 *
 * @param {Object}   props
 * @param {Array}    props.rows    Current rows.
 * @param {Function} props.onChange Receives the new rows.
 * @param {Object}   props.blank   A new empty row.
 * @param {Array}    props.fields  Field descriptors: { name, label, options? }.
 */
function Rows( { rows, onChange, blank, fields } ) {
	const update = ( i, name, value ) =>
		onChange(
			rows.map( ( r, j ) => ( j === i ? { ...r, [ name ]: value } : r ) )
		);
	return (
		<>
			{ rows.map( ( row, i ) => (
				<div key={ i } className="lledger-row">
					{ fields.map( ( f ) =>
						f.options ? (
							<SelectControl
								key={ f.name }
								label={ f.label }
								value={ row[ f.name ] ?? f.options[ 0 ].value }
								options={ f.options }
								onChange={ ( v ) => update( i, f.name, v ) }
							/>
						) : (
							<TextControl
								key={ f.name }
								label={ f.label }
								value={ row[ f.name ] ?? '' }
								onChange={ ( v ) => update( i, f.name, v ) }
							/>
						)
					) }
					<Button
						variant="link"
						isDestructive
						onClick={ () => onChange( rows.filter( ( _, j ) => j !== i ) ) }
					>
						{ __( 'Remove', 'learning-ledger' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => onChange( [ ...rows, blank ] ) }>
				{ __( 'Add', 'learning-ledger' ) }
			</Button>
		</>
	);
}

const opts = ( values ) => values.map( ( v ) => ( { label: v, value: v } ) );

export default function Edit( { attributes, setAttributes } ) {
	const set = ( name ) => ( value ) => setAttributes( { [ name ]: value } );
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Checkpoint', 'learning-ledger' ) }>
					<TextControl
						label={ __( 'Key', 'learning-ledger' ) }
						value={ attributes.key }
						onChange={ set( 'key' ) }
					/>
					<SelectControl
						label={ __( 'Kind', 'learning-ledger' ) }
						value={ attributes.kind }
						options={ opts( [ 'free-text', 'code' ] ) }
						onChange={ set( 'kind' ) }
					/>
					<TextControl
						label={ __( 'File', 'learning-ledger' ) }
						value={ attributes.file ?? '' }
						onChange={ ( v ) => setAttributes( { file: v || null } ) }
					/>
					<TextControl
						type="number"
						label={ __( 'Max attempts', 'learning-ledger' ) }
						value={ attributes.maxAttempts ?? '' }
						onChange={ ( v ) =>
							setAttributes( {
								maxAttempts: v === '' ? null : parseInt( v, 10 ),
							} )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Checks', 'learning-ledger' ) } initialOpen={ false }>
					<Rows
						rows={ attributes.checks }
						onChange={ set( 'checks' ) }
						blank={ { kind: 'contains', value: '' } }
						fields={ [
							{
								name: 'kind',
								label: __( 'Kind', 'learning-ledger' ),
								options: opts( [ 'contains', 'notContains', 'regex', 'json' ] ),
							},
							{ name: 'value', label: __( 'Value', 'learning-ledger' ) },
							{ name: 'pointer', label: __( 'Pointer (json)', 'learning-ledger' ) },
						] }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Branches', 'learning-ledger' ) } initialOpen={ false }>
					<Rows
						rows={ attributes.branches }
						onChange={ set( 'branches' ) }
						blank={ { key: '', when: '', success: false, to: '', message: '' } }
						fields={ [
							{ name: 'key', label: __( 'Key', 'learning-ledger' ) },
							{ name: 'when', label: __( 'When', 'learning-ledger' ) },
							{
								name: 'success',
								label: __( 'Success', 'learning-ledger' ),
								options: [
									{ label: 'false', value: false },
									{ label: 'true', value: true },
								],
							},
							{ name: 'to', label: __( 'To', 'learning-ledger' ) },
							{ name: 'message', label: __( 'Message', 'learning-ledger' ) },
						] }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Fallback options', 'learning-ledger' ) } initialOpen={ false }>
					<Rows
						rows={ attributes.fallbackOptions }
						onChange={ set( 'fallbackOptions' ) }
						blank={ { label: '', outcome: '' } }
						fields={ [
							{ name: 'label', label: __( 'Label', 'learning-ledger' ) },
							{ name: 'outcome', label: __( 'Outcome', 'learning-ledger' ) },
						] }
					/>
				</PanelBody>
			</InspectorControls>
			<TextControl
				label={ __( 'Question', 'learning-ledger' ) }
				value={ attributes.question }
				onChange={ set( 'question' ) }
			/>
			<InnerBlocks
				allowedBlocks={ [ 'learning-ledger/rubric' ] }
				template={ [] }
				templateLock={ false }
			/>
		</div>
	);
}
