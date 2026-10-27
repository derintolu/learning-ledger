import { __ } from '@wordpress/i18n';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';

// Mirror of LearningLedger\Blocks\Markdown_Allowlist::BLOCKS.
export const ALLOWED = [
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/list-item',
	'core/code',
	'core/preformatted',
	'core/quote',
	'core/image',
	'learning-ledger/checkpoint',
];
const MARKDOWN = ALLOWED.filter( ( n ) => n !== 'learning-ledger/checkpoint' );

export default function Edit( { attributes, setAttributes, clientId } ) {
	const outside = useSelect(
		( select ) =>
			select( 'core/block-editor' )
				.getBlocks( clientId )
				.some(
					( b ) =>
						! MARKDOWN.includes( b.name ) &&
						b.name !== 'learning-ledger/checkpoint'
				),
		[ clientId ]
	);
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Beat', 'learning-ledger' ) }>
					<TextControl
						label={ __( 'Beat ID', 'learning-ledger' ) }
						value={ attributes.id }
						onChange={ ( id ) => setAttributes( { id } ) }
					/>
					<TextControl
						label={ __( 'Goal (never shown to learners)', 'learning-ledger' ) }
						value={ attributes.goal }
						onChange={ ( goal ) => setAttributes( { goal } ) }
					/>
					<TextControl
						label={ __( 'Question', 'learning-ledger' ) }
						value={ attributes.question }
						onChange={ ( question ) => setAttributes( { question } ) }
					/>
				</PanelBody>
			</InspectorControls>
			{ outside && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'This block will not be exported', 'learning-ledger' ) }
				</Notice>
			) }
			<InnerBlocks allowedBlocks={ ALLOWED } />
		</div>
	);
}
