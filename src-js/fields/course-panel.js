import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { JsonField, isFiles, isPreview, isOpaqueObject } from './json-field';

export function CoursePanel() {
	const meta = useSelect(
		( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ),
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );
	if ( ! meta || ! ( '_lledger_preview' in meta ) ) {
		return null;
	}
	const set = ( key ) => ( value ) =>
		editPost( { meta: { ...meta, [ key ]: value } } );
	return (
		<PluginDocumentSettingPanel
			name="learning-ledger-course"
			title={ __( 'Learning Ledger course', 'learning-ledger' ) }
		>
			<JsonField
				label={ __( 'Preview (JSON: {kind, files})', 'learning-ledger' ) }
				value={ meta._lledger_preview }
				onChange={ set( '_lledger_preview' ) }
				check={ isPreview }
			/>
			<JsonField
				label={ __( 'Final files (JSON: [{path, content}])', 'learning-ledger' ) }
				value={ meta._lledger_final_files }
				onChange={ set( '_lledger_final_files' ) }
				check={ isFiles }
			/>
			<JsonField
				label={ __( 'Landing (JSON object)', 'learning-ledger' ) }
				value={ meta._lledger_landing }
				onChange={ set( '_lledger_landing' ) }
				check={ isOpaqueObject }
			/>
		</PluginDocumentSettingPanel>
	);
}
