/**
 * Launch controls of the cmi5 lesson block.
 *
 * Each button posts the lesson and mode to the launch route with the REST nonce. An OwnWindow AU
 * replaces this page; an AnyWindow AU runs in an iframe in the lesson. When the AU returns, the
 * frame loads a page of this site, which this page can read, and it reloads to show the new status.
 */
const FRAME_NAME = 'lledger-cmi5-frame';

function isHttpUrl( value ) {
	return typeof value === 'string' && /^https?:\/\//i.test( value );
}

/**
 * Whether the AU has handed the frame back. The AU never shares this page's origin (it is on its
 * own content host, or opaque under the CSP sandbox), so reading the frame's location throws while
 * it runs. Any readable document other than the blank one is the site again, whatever its URL.
 *
 * @param {HTMLIFrameElement} frame The AU frame.
 */
function hasReturned( frame ) {
	try {
		return frame.contentWindow.location.href !== 'about:blank';
	} catch {
		return false;
	}
}

function openFrame( root, url ) {
	const holder = root.querySelector( '[data-lledger-frame]' );
	if ( ! holder ) {
		window.location.assign( url );
		return;
	}
	holder.textContent = '';
	const frame = document.createElement( 'iframe' );
	frame.name = FRAME_NAME;
	frame.title = root.dataset.frameTitle || '';
	frame.setAttribute(
		'sandbox',
		'allow-scripts allow-same-origin allow-forms allow-popups allow-modals'
	);
	frame.setAttribute( 'allow', 'fullscreen' );
	frame.style.cssText = 'width:100%;min-height:70vh;border:0;';
	frame.addEventListener( 'load', () => {
		if ( hasReturned( frame ) ) {
			window.location.reload();
		}
	} );
	frame.src = url;
	holder.appendChild( frame );
	const back = root.querySelector( '[data-lledger-return]' );
	if ( back ) {
		back.hidden = false;
	}
	frame.scrollIntoView( { block: 'start' } );
}

function init( root ) {
	const { endpoint, nonce, lesson } = root.dataset;
	const message = root.querySelector( '[data-lledger-message]' );
	const say = ( text ) => {
		if ( message ) {
			message.textContent = text;
		}
	};
	root.querySelectorAll( 'button[data-lledger-mode]' ).forEach(
		( button ) => {
			button.addEventListener( 'click', () => {
				const buttons = root.querySelectorAll(
					'button[data-lledger-mode]'
				);
				buttons.forEach( ( b ) => ( b.disabled = true ) );
				say( '' );
				fetch( endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( {
						lesson: Number( lesson ),
						mode: button.dataset.lledgerMode,
					} ),
				} )
					.then( ( res ) =>
						res
							.json()
							.catch( () => null )
							.then( ( data ) => ( { ok: res.ok, data } ) )
					)
					.then( ( { ok, data } ) => {
						if (
							! ok ||
							! data ||
							! isHttpUrl( data.launch_url )
						) {
							throw new Error(
								( data && data.message ) || root.dataset.failed
							);
						}
						if ( data.method === 'AnyWindow' ) {
							buttons.forEach( ( b ) => ( b.disabled = false ) );
							openFrame( root, data.launch_url );
							return;
						}
						window.location.assign( data.launch_url );
					} )
					.catch( ( error ) => {
						buttons.forEach( ( b ) => ( b.disabled = false ) );
						say( error.message || root.dataset.failed );
					} );
			} );
		}
	);
}

document
	.querySelectorAll( '.wp-block-learning-ledger-cmi5-lesson[data-endpoint]' )
	.forEach( init );
