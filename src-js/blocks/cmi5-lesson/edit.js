import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Placeholder, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * The editor preview is the block's own server render in the edit context, which carries the
 * warning when the lesson has a Sensei quiz that must be passed.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export default function Edit( { attributes, setAttributes } ) {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'cmi5 lesson', 'learning-ledger' ) }>
					<TextControl
						label={ __( 'AU id', 'learning-ledger' ) }
						help={ __(
							'The id of the assignable unit this lesson launches.',
							'learning-ledger'
						) }
						value={ attributes.auId }
						onChange={ ( auId ) => setAttributes( { auId } ) }
					/>
				</PanelBody>
			</InspectorControls>
			{ attributes.auId ? (
				<ServerSideRender
					block="learning-ledger/cmi5-lesson"
					attributes={ attributes }
					urlQueryArgs={ { post_id: postId } }
				/>
			) : (
				<Placeholder
					label={ __( 'cmi5 lesson', 'learning-ledger' ) }
					instructions={ __(
						'Set the AU id in the block settings.',
						'learning-ledger'
					) }
				/>
			) }
		</div>
	);
}
