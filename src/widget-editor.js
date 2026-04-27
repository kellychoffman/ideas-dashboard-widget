/**
 * Ideas Inbox — isolated block editor experiment.
 *
 * Mounts an IsolatedBlockEditor in the dashboard widget's add area and
 * keeps the existing textarea in the form as the source of truth: the
 * editor writes serialized block markup into the textarea on every
 * change, so both the REST add flow and the no-JS POST keep working.
 */

import '@automattic/isolated-block-editor/build-browser/core.css';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import IsolatedBlockEditor from '@automattic/isolated-block-editor';

function onLoad( content, parser, rawHandler ) {
	if ( ! content ) {
		return [];
	}
	if ( content.indexOf( '<!--' ) !== -1 ) {
		return parser( content );
	}
	return rawHandler( { HTML: content } );
}

function mount() {
	const widget = document.getElementById( 'ideas_inbox_widget' );
	if ( ! widget ) {
		return;
	}
	const textarea = widget.querySelector( '.ideas-inbox__textarea' );
	const host     = widget.querySelector( '.ideas-inbox__editor-mount' );
	if ( ! textarea || ! host ) {
		return;
	}

	textarea.removeAttribute( 'required' );
	host.dataset.mounted = '1';

	createRoot( host ).render(
		<IsolatedBlockEditor
			settings={ {
				iso: {
					moreMenu: false,
					sidebar: { inserter: true, inspector: false },
					toolbar: { inspector: false, navigation: false },
				},
				placeholder: __( 'Drop an idea for future you…', 'ideas-dashboard-widget' ),
			} }
			onLoad={ ( parser, rawHandler ) => onLoad( textarea.value, parser, rawHandler ) }
			onSaveContent={ ( content ) => {
				textarea.value = content;
			} }
			onError={ () => {} }
		/>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
