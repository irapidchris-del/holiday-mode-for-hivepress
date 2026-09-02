/**
 * Holiday Mode for HivePress - live preview.
 *
 * Draws the vendor banner and the profile notice in a panel to the right of the settings, with
 * the wording, icon, sizes and colours on the page, following every change as it is made and
 * storing nothing until Save. The markup and inline styles are the ones the front end emits
 * (holiday_mode_for_hivepress_vendor_notice() and maybe_print_banner() in the main file), so
 * what is drawn is the notice itself rather than an imitation of it; the vendor name is an
 * example, and the description under the panel says so.
 *
 * Icons are emitted as `<i class="fa-solid fa-NAME">` and drawn by the shared icon library's
 * admin script, which watches the document and swaps them for inline SVG.
 *
 * Everything from "folding panels" down is the preview chrome shared with the other extensions
 * that have a preview (Action Bar, Trust Signals); only the prefix differs. Fix it in one and
 * sweep the others.
 */

/* global hphmPreviewData */

( function () {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	var PREFIX = 'hp_holiday_mode_for_hivepress_',
		STORE = 'hphmPreviewPanels',
		WIDTH_STORE = 'hphmPreviewWidth';

	window.jQuery( function ( $ ) {
		var root = document.querySelector( '.hphm-preview' );

		if ( ! root ) {
			return;
		}

		var data = window.hphmPreviewData || {},
			defaults = data.defaults || {},
			colours = data.colours || {},
			strokes = data.strokes || {},
			banner = root.querySelector( '[data-hphm-part="banner"]' ),
			notice = root.querySelector( '[data-hphm-part="notice"]' );

		function input( name ) {
			return document.querySelector( '[name="' + PREFIX + name + '"]' );
		}

		function value( name ) {
			var field = input( name );

			if ( ! field ) {
				return '';
			}

			if ( 'checkbox' === field.type ) {
				return field.checked ? '1' : '';
			}

			return ( field.value || '' ).trim();
		}

		// A 6-digit hex, or '' for anything else; 3-digit shorthand is expanded the way the
		// settings screen expands it before saving.
		function hex( raw ) {
			raw = ( raw || '' ).trim();

			if ( /^#[0-9a-f]{6}$/i.test( raw ) ) {
				return raw.toLowerCase();
			}

			var short = /^#([0-9a-f]{3})$/i.exec( raw );

			return short ? ( '#' + short[ 1 ].replace( /./g, '$&$&' ) ).toLowerCase() : '';
		}

		// The bare icon name from a picker value, which may still carry a family prefix from
		// before the icon library.
		function iconName( raw ) {
			var name = '';

			( raw || '' ).toLowerCase().split( /\s+/ ).forEach( function ( token ) {
				if ( 0 === token.indexOf( 'fa-' ) ) {
					name = token.slice( 3 );
				} else if ( ! name && /^[a-z0-9-]+$/.test( token ) && -1 === [ 'fas', 'fab', 'far' ].indexOf( token ) ) {
					name = token;
				}
			} );

			return /^[a-z0-9-]+$/.test( name ) ? name : '';
		}

		// Both halves, as the front end emits them: -webkit-text-stroke for a font glyph, stroke
		// and stroke-width for the inline SVG the icon library draws.
		function strokeCss( width ) {
			return width ? '-webkit-text-stroke:' + width + ' currentColor;stroke:currentColor;stroke-width:' + width + ';paint-order:stroke fill;' : '';
		}

		// Emitted as the class the icon library's admin script watches for; it swaps the element's
		// contents for inline SVG and reads the icon's real family from its own index.
		function icon( name, className, style ) {
			var element = document.createElement( 'i' );

			element.className = className + ' fa-solid fa-' + name;
			element.setAttribute( 'aria-hidden', 'true' );

			if ( style ) {
				element.style.cssText = style;
			}

			return element;
		}

		// The resolved settings for one of the two notices, falling back exactly as
		// get_notice_args() does: blank wording and colours use the standard ones, a blank icon
		// colour follows the label colour, and the border shades itself from the background.
		function read( context ) {
			var standard = defaults[ context ] || {},
				size = parseInt( value( context + '_icon_size' ), 10 ),
				labelColour = hex( value( context + '_label_color' ) ) || colours.text,
				bg = hex( value( context + '_bg_color' ) ) || colours.bg;

			return {
				label: username( value( context + '_label' ) || standard.label || '' ),
				message: username( value( context + '_message' ) || standard.message || '' ),
				icon: iconName( value( context + '_icon' ) ) || data.icon || 'info-circle',
				iconSize: ( isNaN( size ) || size < 50 || size > 400 ) ? ( standard.iconSize || 150 ) : size,
				stroke: strokes[ value( context + '_icon_weight' ) ] || '',
				labelColour: labelColour,
				textColour: hex( value( context + '_text_color' ) ) || colours.text,
				iconColour: hex( value( context + '_icon_color' ) ) || labelColour,
				bg: bg,
				border: border( bg ),
			};
		}

		function username( text ) {
			return String( text ).split( '%username%' ).join( data.username || '' );
		}

		// get_border_color(): the standard pairing for the standard background, otherwise the
		// chosen colour darkened by 12%.
		function border( bg ) {
			if ( bg === colours.bg ) {
				return colours.border;
			}

			var out = '#';

			[ 1, 3, 5 ].forEach( function ( offset ) {
				out += ( '0' + Math.round( parseInt( bg.substr( offset, 2 ), 16 ) * 0.88 ).toString( 16 ) ).slice( -2 );
			} );

			return out;
		}

		function clear( element ) {
			while ( element.firstChild ) {
				element.removeChild( element.firstChild );
			}
		}

		// maybe_print_banner(): the sticky strip at the top of a vendor's account pages.
		function drawBanner() {
			if ( ! banner ) {
				return;
			}

			var args = read( 'banner' ),
				box = document.createElement( 'div' ),
				strong = document.createElement( 'strong' ),
				span = document.createElement( 'span' ),
				parts = args.message.split( '%s' );

			box.className = 'hphm-preview__banner';
			box.setAttribute( 'role', 'presentation' );
			box.style.cssText = 'box-sizing:border-box;max-width:100%;background:' + args.bg + ';color:' + args.textColour + ';border:1px solid ' + args.border + ';border-radius:0.5rem;padding:0.75rem 1rem;margin:0;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.05)';

			strong.style.color = args.labelColour;
			strong.appendChild( document.createTextNode( args.label ) );

			span.appendChild( document.createTextNode( parts[ 0 ] || '' ) );

			if ( parts.length > 1 ) {
				var link = document.createElement( 'a' );

				link.href = '#';
				link.style.color = 'inherit';
				link.appendChild( document.createTextNode( data.link || '' ) );
				span.appendChild( link );
				span.appendChild( document.createTextNode( parts[ 1 ] || '' ) );
			}

			box.appendChild( icon( args.icon, 'hphm-preview__icon', 'color:' + args.iconColour + ';font-size:' + args.iconSize + '%;line-height:1;' + strokeCss( args.stroke ) ) );
			box.appendChild( strong );
			box.appendChild( span );

			clear( banner );
			banner.appendChild( box );
		}

		// holiday_mode_for_hivepress_vendor_notice(): the box in place of the listings on a
		// vendor's public profile.
		function drawNotice() {
			if ( ! notice ) {
				return;
			}

			var args = read( 'notice' ),
				box = document.createElement( 'div' ),
				text = document.createElement( 'div' ),
				strong = document.createElement( 'strong' ),
				paragraph = document.createElement( 'p' );

			box.className = 'hphm-preview__notice';
			box.setAttribute( 'role', 'presentation' );
			box.style.cssText = 'display:flex;align-items:flex-start;gap:0.75rem;box-sizing:border-box;background:' + args.bg + ';border:1px solid ' + args.border + ';border-radius:0.5rem;padding:1rem;margin:0;';

			strong.style.color = args.labelColour;
			strong.appendChild( document.createTextNode( args.label ) );

			paragraph.style.cssText = 'color:' + args.textColour + ';margin:' + ( args.label ? '0.25rem' : '0' ) + ' 0 0;';
			paragraph.appendChild( document.createTextNode( args.message ) );

			box.appendChild( icon( args.icon, 'hphm-preview__icon', 'color:' + args.iconColour + ';font-size:' + args.iconSize + '%;line-height:1.4;' + strokeCss( args.stroke ) ) );

			if ( args.label ) {
				text.appendChild( strong );
			}

			if ( args.message ) {
				text.appendChild( paragraph );
			}

			box.appendChild( text );

			clear( notice );
			notice.appendChild( box );
		}

		function paint() {
			drawBanner();
			drawNotice();
		}

		/* ---- folding panels -------------------------------------------------- */

		function readStore() {
			try {
				return JSON.parse( window.localStorage.getItem( STORE ) ) || {};
			} catch ( error ) {
				return {};
			}
		}

		function writeStore( store ) {
			try {
				window.localStorage.setItem( STORE, JSON.stringify( store ) );
			} catch ( error ) {
				// Storage blocked; the panels still fold, they just forget on the next load.
			}
		}

		function setOpen( panel, open, remember ) {
			var header = panel.querySelector( '.hphm-preview__header' ),
				body = panel.querySelector( '.hphm-preview__body' ),
				chevron = header ? header.querySelector( '.dashicons' ) : null;

			panel.classList.toggle( 'hphm-preview__panel--collapsed', ! open );

			if ( header ) {
				header.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}

			if ( body ) {
				body.hidden = ! open;
			}

			if ( chevron ) {
				chevron.className = 'dashicons ' + ( open ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' );
			}

			if ( remember ) {
				var store = readStore();

				store[ panel.getAttribute( 'data-panel' ) ] = open ? 1 : 0;
				writeStore( store );
			}
		}

		var remembered = readStore();

		Array.prototype.forEach.call( root.querySelectorAll( '.hphm-preview__panel' ), function ( panel ) {
			var key = panel.getAttribute( 'data-panel' ),
				header = panel.querySelector( '.hphm-preview__header' );

			setOpen( panel, 'undefined' === typeof remembered[ key ] ? true : !! remembered[ key ], false );

			if ( header ) {
				header.addEventListener( 'click', function () {
					setOpen( panel, panel.classList.contains( 'hphm-preview__panel--collapsed' ), true );
				} );
			}
		} );

		/* ---- follow the form ------------------------------------------------- */

		var repaintTimer = null;

		function repaint() {
			window.clearTimeout( repaintTimer );
			repaintTimer = window.setTimeout( paint, 50 );
		}

		// jQuery-delegated on purpose: select2 and Iris announce their changes with jQuery-triggered
		// events, which a native listener never hears. Iris fires "irischange" on the input when a
		// swatch or the palette is used, and no DOM event at all, so it is listened for by name.
		$( document ).on( 'input change irischange', '[name^="' + PREFIX + '"]', repaint );
		$( document ).on( 'click', '.iris-palette, .wp-picker-clear, .wp-picker-default', repaint );

		// Links inside the preview are illustrations; following one would scroll the settings away.
		root.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( 'a' ) ) {
				event.preventDefault();
			}
		} );

		paint();

		/* ---- resizable panel ------------------------------------------------ */

		var WIDTH_DEFAULT = 400,
			WIDTH_MIN = 280,
			resizer = root.querySelector( '.hphm-preview__resizer' ),
			form = root.closest( 'form' );

		function maxWidth() {
			// Leave the settings column at least 480px; below that the fields wrap badly.
			return Math.max( WIDTH_MIN, Math.floor( ( form ? form.getBoundingClientRect().width : window.innerWidth ) - 480 ) );
		}

		function applyWidth( width, remember ) {
			width = Math.round( Math.min( maxWidth(), Math.max( WIDTH_MIN, width ) ) );

			if ( form ) {
				form.style.setProperty( '--hphm-preview-width', width + 'px' );
			}

			if ( resizer ) {
				resizer.setAttribute( 'aria-valuenow', String( width ) );
				resizer.setAttribute( 'aria-valuemin', String( WIDTH_MIN ) );
				resizer.setAttribute( 'aria-valuemax', String( maxWidth() ) );
			}

			if ( remember ) {
				try {
					window.localStorage.setItem( WIDTH_STORE, String( width ) );
				} catch ( error ) {
					// Storage blocked; the width holds for this page only.
				}
			}

			return width;
		}

		function currentWidth() {
			var stored = 0;

			try {
				stored = parseInt( window.localStorage.getItem( WIDTH_STORE ), 10 );
			} catch ( error ) {
				stored = 0;
			}

			return stored > 0 ? stored : WIDTH_DEFAULT;
		}

		if ( resizer && form ) {
			applyWidth( currentWidth(), false );

			var dragging = null;

			resizer.addEventListener( 'pointerdown', function ( event ) {
				if ( 0 !== event.button ) {
					return;
				}

				dragging = { x: event.clientX, width: parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT };
				resizer.setPointerCapture( event.pointerId );
				root.classList.add( 'hphm-preview--resizing' );
				event.preventDefault();
			} );

			resizer.addEventListener( 'pointermove', function ( event ) {
				if ( ! dragging ) {
					return;
				}

				// The handle is on the LEFT edge, so moving the pointer left makes the panel wider.
				applyWidth( dragging.width + ( dragging.x - event.clientX ), false );
			} );

			function endDrag( event ) {
				if ( ! dragging ) {
					return;
				}

				dragging = null;
				root.classList.remove( 'hphm-preview--resizing' );

				if ( event.pointerId !== undefined && resizer.hasPointerCapture( event.pointerId ) ) {
					resizer.releasePointerCapture( event.pointerId );
				}

				applyWidth( parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT, true );
			}

			resizer.addEventListener( 'pointerup', endDrag );
			resizer.addEventListener( 'pointercancel', endDrag );

			resizer.addEventListener( 'dblclick', function () {
				applyWidth( WIDTH_DEFAULT, true );
			} );

			resizer.addEventListener( 'keydown', function ( event ) {
				var step = event.shiftKey ? 80 : 20,
					width = parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT;

				if ( 'ArrowLeft' === event.key ) {
					applyWidth( width + step, true );
				} else if ( 'ArrowRight' === event.key ) {
					applyWidth( width - step, true );
				} else if ( 'Home' === event.key ) {
					applyWidth( WIDTH_DEFAULT, true );
				} else {
					return;
				}

				event.preventDefault();
			} );

			window.addEventListener( 'resize', function () {
				applyWidth( parseInt( resizer.getAttribute( 'aria-valuenow' ), 10 ) || WIDTH_DEFAULT, false );
			} );
		}
	} );
}() );
