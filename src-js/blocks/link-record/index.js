import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Program', 'learning-ledger' ) }>
					<TextControl
						label={ __( 'Program ID', 'learning-ledger' ) }
						type="number"
						value={ attributes.program || '' }
						onChange={ ( value ) =>
							setAttributes( { program: parseInt( value, 10 ) || 0 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<p>{ __( 'Learners enter the one-time code from the course server here.', 'learning-ledger' ) }</p>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
