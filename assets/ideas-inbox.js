/**
 * Ideas Inbox — client-side progressive enhancement.
 *
 * Replaces the inline `confirm()` fallback on the Delete link with a
 * WPDS ConfirmDialog for a friendlier confirmation experience.
 */

( function () {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.components || ! wp.element || ! wp.i18n ) {
		return;
	}

	var ConfirmDialog = wp.components.__experimentalConfirmDialog;
	var createElement = wp.element.createElement;
	var createRoot    = wp.element.createRoot;
	var useState      = wp.element.useState;
	var useEffect     = wp.element.useEffect;
	var useCallback   = wp.element.useCallback;
	var __            = wp.i18n.__;

	if ( ! ConfirmDialog || ! createRoot ) {
		return;
	}

	var DIALOG_EVENT = 'ideas-inbox:confirm-delete';

	function DeleteConfirm() {
		var state    = useState( null );
		var pending  = state[ 0 ];
		var setPending = state[ 1 ];

		useEffect( function () {
			function onRequest( event ) {
				setPending( event.detail.url );
			}
			document.addEventListener( DIALOG_EVENT, onRequest );
			return function () {
				document.removeEventListener( DIALOG_EVENT, onRequest );
			};
		}, [] );

		var close = useCallback( function () {
			setPending( null );
		}, [] );

		return createElement(
			ConfirmDialog,
			{
				isOpen: pending !== null,
				confirmButtonText: __( 'Delete', 'ideas-dashboard-widget' ),
				cancelButtonText: __( 'Never mind', 'ideas-dashboard-widget' ),
				onConfirm: function () {
					var url = pending;
					setPending( null );
					if ( url ) {
						window.location.href = url;
					}
				},
				onCancel: close,
			},
			__( "Just double checking you want to delete this idea. It can't be undone.", 'ideas-dashboard-widget' )
		);
	}

	function init() {
		// Mount the dialog portal outside the dashboard widget so it
		// isn't clipped by postbox overflow rules.
		var container = document.createElement( 'div' );
		container.className = 'ideas-inbox-dialog-root';
		document.body.appendChild( container );
		createRoot( container ).render( createElement( DeleteConfirm ) );

		// Strip the native confirm() fallback now that the component
		// dialog is ready. Links without JS keep the inline handler.
		document.querySelectorAll( '.ideas-inbox__delete[onclick]' ).forEach( function ( link ) {
			link.removeAttribute( 'onclick' );
		} );

		// Delegate click handling so it also covers any links added
		// to the DOM later in this page load.
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.ideas-inbox__delete' );
			if ( ! link ) {
				return;
			}
			event.preventDefault();
			document.dispatchEvent(
				new CustomEvent( DIALOG_EVENT, {
					detail: { url: link.href },
				} )
			);
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
