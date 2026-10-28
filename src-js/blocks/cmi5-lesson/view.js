/**
 * Launch controls of the cmi5 lesson block.
 *
 * Each button posts the lesson and mode to the launch route with the REST nonce. An OwnWindow AU
 * replaces this page; an AnyWindow AU runs in an iframe in the lesson. When the AU returns, the
 * frame navigates to this lesson's page on this origin, which this page can read (the AU's own
 * origin it cannot), and it reloads to show the new status.
 */
const FRAME_NAME = 'lledger-cmi5-frame';

function isHttpUrl( value ) {
	return typeof value === 'string' && /^https?:\/\//i.test( value );
}

/**
 * Whether the frame has come back to this lesson. Reading a cross-origin frame's location throws,
 * which means the AU is still running.
 *
 * @param {HTMLIFrameElement} frame The AU frame.
 */
function hasReturned( frame ) {
	try {
		const back = frame.contentWindow.location;
		return (
			back.origin === window.location.origin &&
			back.pathname === window.location.pathname &&
			back.search === window.location.search
		);
	} catch {
		return false;
	}
}

function openFrame( root, url, title ) {
	const holder = root.querySelector( '[data-lledger-frame]' );
	if ( ! holder ) {
		window.location.assign( url );
		return;
	}
	holder.textContent = '';
	const frame = document.createElement( 'iframe' );
	frame.name = FRAME_NAME;
	frame.title = title;
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
							openFrame(
								root,
								data.launch_url,
								button.textContent
							);
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
