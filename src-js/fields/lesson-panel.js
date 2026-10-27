import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { TextControl } from '@wordpress/components';
import { JsonField, isFiles, isSources } from './json-field';

export function LessonPanel() {
	const meta = useSelect(
		( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ),
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );
	if ( ! meta || ! ( '_lledger_starter_files' in meta ) ) {
		return null;
	}
	const set = ( key ) => ( value ) =>
		editPost( { meta: { ...meta, [ key ]: value } } );
	return (
		<PluginDocumentSettingPanel
			name="learning-ledger-lesson"
			title={ __( 'Learning Ledger lesson', 'learning-ledger' ) }
		>
			<JsonField
				label={ __( 'Starter files (JSON: [{path, content}])', 'learning-ledger' ) }
				value={ meta._lledger_starter_files }
				onChange={ set( '_lledger_starter_files' ) }
				check={ isFiles }
			/>
			<JsonField
				label={ __( 'Sources (JSON: [{title, url}])', 'learning-ledger' ) }
				value={ meta._lledger_sources }
				onChange={ set( '_lledger_sources' ) }
				check={ isSources }
			/>
			<TextControl
				type="number"
				min="0"
				label={ __( 'Minutes', 'learning-ledger' ) }
				value={ meta._lledger_minutes || '' }
				onChange={ ( v ) => set( '_lledger_minutes' )( parseInt( v, 10 ) || 0 ) }
			/>
			<TextControl
				label={ __( 'Licence', 'learning-ledger' ) }
				value={ meta._lledger_licence ?? '' }
				onChange={ set( '_lledger_licence' ) }
			/>
		</PluginDocumentSettingPanel>
	);
}
