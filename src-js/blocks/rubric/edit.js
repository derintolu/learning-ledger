import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { TextareaControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<TextareaControl
				label={ __( 'Rubric (never shown to learners)', 'learning-ledger' ) }
				value={ attributes.text }
				onChange={ ( text ) => setAttributes( { text } ) }
			/>
		</div>
	);
}
