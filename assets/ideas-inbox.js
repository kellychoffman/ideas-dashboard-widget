/**
 * Ideas Inbox — client-side progressive enhancement.
 *
 * Enhances the server-rendered dashboard widget and submenu page:
 *  - Intercepts the add form to POST to the REST endpoint and insert
 *    the returned row HTML without a page reload.
 *  - Replaces the inline confirm() fallback on delete links with a
 *    WPDS ConfirmDialog that, on confirm, DELETEs via REST and
 *    removes the row in place.
 *
 * Without JS (or without wp.apiFetch), the form's native POST and
 * the delete link's own href keep the existing redirect flow.
 */

( function () {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.element || ! wp.i18n ) {
		return;
	}

	var createElement = wp.element.createElement;
	var createRoot    = wp.element.createRoot;
	var useState      = wp.element.useState;
	var useEffect     = wp.element.useEffect;
	var useCallback   = wp.element.useCallback;
	var __            = wp.i18n.__;
	var _n            = wp.i18n._n;
	var apiFetch      = wp.apiFetch;

	// The shared confirm modal depends on Components; the add/edit flow
	// degrades gracefully without it. Guard them independently so the
	// add-form enhancement still activates even if __experimentalConfirmDialog
	// is renamed or removed.
	var ConfirmDialog = wp.components && wp.components.__experimentalConfirmDialog;
	var hasModal      = !! ( ConfirmDialog && createRoot );

	function confirmAction( opts ) {
		if ( hasModal ) {
			return confirmWith( opts );
		}
		return Promise.resolve( window.confirm( opts.message ) );
	}

	var DIALOG_EVENT = 'ideas-inbox:confirm';
	var REST_BASE    = '/ideas-inbox/v1/ideas';

	// Promise-based wrapper around the ConfirmDialog portal. Lets both the
	// delete flow and the discard-edit flow use the same modal UI.
	function confirmWith( opts ) {
		return new Promise( function ( resolve ) {
			document.dispatchEvent(
				new CustomEvent( DIALOG_EVENT, {
					detail: {
						message: opts.message,
						confirmText: opts.confirmText,
						cancelText: opts.cancelText,
						resolve: resolve,
					},
				} )
			);
		} );
	}

	function ConfirmManager() {
		var state      = useState( null );
		var pending    = state[ 0 ];
		var setPending = state[ 1 ];

		useEffect( function () {
			function onRequest( event ) {
				// If a previous prompt is still pending (shouldn't normally
				// happen), resolve it as cancelled before swapping in the new one.
				setPending( function ( prev ) {
					if ( prev && prev.resolve ) {
						prev.resolve( false );
					}
					return event.detail;
				} );
			}
			document.addEventListener( DIALOG_EVENT, onRequest );
			return function () {
				document.removeEventListener( DIALOG_EVENT, onRequest );
			};
		}, [] );

		var settle = useCallback( function ( value ) {
			setPending( function ( prev ) {
				if ( prev && prev.resolve ) {
					prev.resolve( value );
				}
				return null;
			} );
		}, [] );

		return createElement(
			ConfirmDialog,
			{
				isOpen: pending !== null,
				confirmButtonText: pending ? pending.confirmText : '',
				cancelButtonText: pending ? pending.cancelText : '',
				onConfirm: function () { settle( true ); },
				onCancel: function () { settle( false ); },
			},
			pending ? pending.message : ''
		);
	}

	function isWidgetContainer( container ) {
		return !! container.closest( '#ideas_inbox_widget' );
	}

	function formatCount( total ) {
		// %s is replaced with the raw number; %d is not used here because
		// we don't have a locale number formatter in wp.i18n for JS.
		return _n( '%s idea', '%s ideas', total, 'ideas-dashboard-widget' ).replace( '%s', String( total ) );
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function buildEmptyState( container ) {
		var hint = isWidgetContainer( container )
			? __( 'Drop one above to save it for later.', 'ideas-dashboard-widget' )
			: __( 'Head to your dashboard to add one.', 'ideas-dashboard-widget' );
		return (
			'<div class="ideas-inbox__empty">'
			+ '<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>'
			+ '<p class="ideas-inbox__empty-title">' + escapeHtml( __( 'No ideas yet', 'ideas-dashboard-widget' ) ) + '</p>'
			+ '<p class="ideas-inbox__empty-hint">' + escapeHtml( hint ) + '</p>'
			+ '</div>'
		);
	}

	function buildHeaderAndList( total ) {
		return (
			'<div class="ideas-inbox__header">'
			+ '<span class="ideas-inbox__count">' + escapeHtml( formatCount( total ) ) + '</span>'
			+ '</div>'
			+ '<ul class="ideas-inbox__list"></ul>'
		);
	}

	function updateCount( container, total ) {
		var countEl = container.querySelector( '.ideas-inbox__count' );
		if ( countEl ) {
			countEl.textContent = formatCount( total );
		}
	}

	function updateViewAllLink( container, total ) {
		// Only the widget shows the "View all" link.
		if ( ! isWidgetContainer( container ) ) {
			return;
		}

		var wrap = container.querySelector( '.ideas-inbox__view-all' );

		if ( total <= 5 ) {
			if ( wrap ) {
				wrap.style.display = 'none';
			}
			return;
		}

		var text = __( 'View all ideas (%d) →', 'ideas-dashboard-widget' ).replace( '%d', String( total ) );

		if ( wrap ) {
			var link = wrap.querySelector( 'a' );
			if ( link ) {
				link.textContent = text;
			}
			wrap.style.display = '';
			return;
		}

		// Create the wrapper when crossing the 5 → 6 threshold.
		var url = ( window.IdeasInbox && window.IdeasInbox.viewAllUrl ) || '';
		if ( ! url ) {
			return;
		}
		var p = document.createElement( 'p' );
		p.className = 'ideas-inbox__view-all';
		var a = document.createElement( 'a' );
		a.href = url;
		a.textContent = text;
		p.appendChild( a );

		var list = container.querySelector( '.ideas-inbox__list' );
		if ( list && list.parentNode ) {
			list.parentNode.insertBefore( p, list.nextSibling );
		} else {
			container.appendChild( p );
		}
	}

	function getOrCreateNotice( container ) {
		var existing = container.querySelector( '.ideas-inbox__notice' );
		if ( existing ) {
			return existing;
		}
		var notice = document.createElement( 'div' );
		notice.className = 'ideas-inbox__notice notice notice-error inline';
		notice.setAttribute( 'role', 'alert' );
		var form = container.querySelector( '.ideas-inbox__form' );
		if ( form && form.parentNode ) {
			form.parentNode.insertBefore( notice, form.nextSibling );
		} else {
			container.insertBefore( notice, container.firstChild );
		}
		return notice;
	}

	function showError( container, message ) {
		var notice = getOrCreateNotice( container );
		notice.innerHTML = '<p>' + escapeHtml( message ) + '</p>';
	}

	function clearError( container ) {
		var notice = container.querySelector( '.ideas-inbox__notice' );
		if ( notice ) {
			notice.remove();
		}
	}

	function extractErrorMessage( err ) {
		if ( err && err.message ) {
			return err.message;
		}
		return __( 'Something went wrong. Please try again.', 'ideas-dashboard-widget' );
	}

	function onAddSuccess( container, res ) {
		clearError( container );

		var empty = container.querySelector( '.ideas-inbox__empty' );
		if ( empty ) {
			empty.outerHTML = buildHeaderAndList( res.total );
		}

		var list = container.querySelector( '.ideas-inbox__list' );
		if ( list ) {
			list.insertAdjacentHTML( 'afterbegin', res.html );

			if ( isWidgetContainer( container ) ) {
				while ( list.children.length > 5 ) {
					list.removeChild( list.lastElementChild );
				}
			}
		}

		updateCount( container, res.total );
		updateViewAllLink( container, res.total );

		var textarea = container.querySelector( '.ideas-inbox__textarea' );
		if ( textarea ) {
			textarea.value = '';
			textarea.focus();
		}
	}

	function deleteIdea( pending ) {
		var container = pending.row && pending.row.closest( '.ideas-inbox' );
		if ( ! container ) {
			window.location.href = pending.url;
			return;
		}

		apiFetch( {
			path: REST_BASE + '/' + pending.id,
			method: 'DELETE',
		} ).then( function ( res ) {
			onDeleteSuccess( container, pending.row, res );
		} ).catch( function ( err ) {
			showError( container, extractErrorMessage( err ) );
		} );
	}

	function onDeleteSuccess( container, row, res ) {
		clearError( container );

		if ( row && row.parentNode ) {
			row.remove();
		}

		if ( res.total === 0 ) {
			var header  = container.querySelector( '.ideas-inbox__header' );
			var list    = container.querySelector( '.ideas-inbox__list' );
			var viewAll = container.querySelector( '.ideas-inbox__view-all' );
			if ( header ) header.remove();
			if ( list ) list.remove();
			if ( viewAll ) viewAll.remove();

			var form = container.querySelector( '.ideas-inbox__form' );
			var html = buildEmptyState( container );
			if ( form ) {
				form.insertAdjacentHTML( 'afterend', html );
			} else {
				container.insertAdjacentHTML( 'beforeend', html );
			}
			return;
		}

		// Widget: if the list now has fewer than 5 visible rows but more
		// ideas exist off-screen, backfill with the server-rendered row.
		if ( isWidgetContainer( container ) && res.fill_html ) {
			var widgetList = container.querySelector( '.ideas-inbox__list' );
			if ( widgetList && widgetList.children.length < 5 ) {
				widgetList.insertAdjacentHTML( 'beforeend', res.fill_html );
			}
		}

		updateCount( container, res.total );
		updateViewAllLink( container, res.total );

		// Submenu page: if current page is now empty and isn't page 1,
		// reload to land on a populated page.
		if ( ! isWidgetContainer( container ) ) {
			var list2 = container.querySelector( '.ideas-inbox__list' );
			if ( list2 && list2.children.length === 0 ) {
				var params = new URLSearchParams( window.location.search );
				var paged  = parseInt( params.get( 'paged' ) || '1', 10 );
				if ( paged > 1 ) {
					window.location.reload();
				}
			}
		}
	}

	function onUpdateSuccess( container, id, res ) {
		clearError( container );
		if ( ! res || ! res.html ) {
			return;
		}
		var row = container.querySelector(
			'.ideas-inbox__row[data-id="' + id + '"]'
		);
		if ( ! row ) {
			return;
		}
		var template = document.createElement( 'template' );
		template.innerHTML = res.html.trim();
		var newRow = template.content.firstElementChild;
		if ( newRow ) {
			row.replaceWith( newRow );
		}
	}

	function bindAddForm( container ) {
		if ( ! apiFetch ) {
			return;
		}
		var form = container.querySelector( '.ideas-inbox__form' );
		if ( ! form ) {
			return;
		}

		var textarea  = form.querySelector( '.ideas-inbox__textarea' );
		var submit    = form.querySelector( 'button[type="submit"]' );
		var actions   = form.querySelector( '.ideas-inbox__form-actions' );
		var addLabel  = submit ? submit.textContent : __( 'Add idea', 'ideas-dashboard-widget' );
		var saveLabel = __( 'Save', 'ideas-dashboard-widget' );

		// Inject a Cancel button alongside Save/Add. JS-only — no-JS users
		// don't need a way to cancel because they can't enter edit mode.
		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'button ideas-inbox__cancel';
		cancelBtn.textContent = __( 'Cancel', 'ideas-dashboard-widget' );
		cancelBtn.style.display = 'none';
		if ( actions ) {
			actions.appendChild( cancelBtn );
		}

		var editing = null; // { id, originalText } when editing.

		function enterEdit( id, text ) {
			editing = { id: id, originalText: text };
			if ( textarea ) {
				textarea.value = text;
				textarea.focus();
				try {
					textarea.setSelectionRange( text.length, text.length );
				} catch ( e ) {}
			}
			if ( submit ) {
				submit.textContent = saveLabel;
			}
			cancelBtn.style.display = '';
		}

		function exitEdit() {
			editing = null;
			if ( textarea ) {
				textarea.value = '';
			}
			if ( submit ) {
				submit.textContent = addLabel;
			}
			cancelBtn.style.display = 'none';
		}

		function hasUnsavedChanges() {
			if ( ! textarea ) {
				return false;
			}
			var current = textarea.value;
			if ( editing ) {
				return current !== editing.originalText;
			}
			return current.trim() !== '';
		}

		cancelBtn.addEventListener( 'click', function () {
			clearError( container );
			exitEdit();
		} );

		// Click on an idea's text button → load it into the textarea for editing.
		container.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.ideas-inbox__row-text' );
			if ( ! btn || ! container.contains( btn ) ) {
				return;
			}
			var row = btn.closest( '.ideas-inbox__row' );
			var id  = row ? row.getAttribute( 'data-id' ) : '';
			if ( ! id ) {
				return;
			}

			if ( editing && editing.id === id ) {
				if ( textarea ) textarea.focus();
				return;
			}

			// The button contains only the raw idea text rendered via esc_html;
			// textContent decodes entities back to the original characters.
			var nextText = btn.textContent;

			if ( hasUnsavedChanges() ) {
				confirmAction( {
					message: __( 'Discard your unsaved idea?', 'ideas-dashboard-widget' ),
					confirmText: __( 'Discard', 'ideas-dashboard-widget' ),
					cancelText: __( 'Keep editing', 'ideas-dashboard-widget' ),
				} ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}
					clearError( container );
					enterEdit( id, nextText );
				} );
				return;
			}

			clearError( container );
			enterEdit( id, nextText );
		} );

		form.addEventListener( 'submit', function ( event ) {
			var text = textarea ? textarea.value.trim() : '';
			if ( ! text ) {
				return;
			}
			event.preventDefault();

			if ( submit ) submit.disabled = true;

			if ( editing ) {
				var editingId = editing.id;
				apiFetch( {
					path: REST_BASE + '/' + editingId,
					method: 'PATCH',
					data: { text: text },
				} ).then( function ( res ) {
					onUpdateSuccess( container, editingId, res );
					exitEdit();
				} ).catch( function ( err ) {
					showError( container, extractErrorMessage( err ) );
				} ).finally( function () {
					if ( submit ) submit.disabled = false;
				} );
				return;
			}

			apiFetch( {
				path: REST_BASE,
				method: 'POST',
				data: { text: text },
			} ).then( function ( res ) {
				onAddSuccess( container, res );
			} ).catch( function ( err ) {
				showError( container, extractErrorMessage( err ) );
			} ).finally( function () {
				if ( submit ) submit.disabled = false;
			} );
		} );
	}

	function init() {
		if ( hasModal ) {
			// Mount the dialog portal outside the dashboard widget so it
			// isn't clipped by postbox overflow rules.
			var dialogRoot = document.createElement( 'div' );
			dialogRoot.className = 'ideas-inbox-dialog-root';
			document.body.appendChild( dialogRoot );
			createRoot( dialogRoot ).render( createElement( ConfirmManager ) );

			// Strip the native confirm() fallback now that the component
			// dialog is ready. Links without JS keep the inline handler.
			document.querySelectorAll( '.ideas-inbox__delete[onclick]' ).forEach( function ( link ) {
				link.removeAttribute( 'onclick' );
			} );

			// Delegate delete click handling (also covers rows inserted later).
			document.addEventListener( 'click', function ( event ) {
				var link = event.target.closest( '.ideas-inbox__delete' );
				if ( ! link ) {
					return;
				}
				event.preventDefault();
				var row = link.closest( '.ideas-inbox__row' );
				var id  = row ? row.getAttribute( 'data-id' ) : '';
				confirmAction( {
					message: __( "Just double checking you want to delete this idea. It can't be undone.", 'ideas-dashboard-widget' ),
					confirmText: __( 'Delete', 'ideas-dashboard-widget' ),
					cancelText: __( 'Never mind', 'ideas-dashboard-widget' ),
				} ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}
					if ( id && apiFetch ) {
						deleteIdea( { id: id, row: row, url: link.href } );
					} else if ( link.href ) {
						window.location.href = link.href;
					}
				} );
			} );
		}

		document.querySelectorAll( '.ideas-inbox' ).forEach( bindAddForm );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
