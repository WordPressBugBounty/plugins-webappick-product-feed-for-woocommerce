/**
 * Jest setup for the V8 admin app tests.
 */
import '@testing-library/jest-dom';

// ── Fetch API polyfills ─────────────────────────────────────────────
// jest-environment-jsdom 27 ships no fetch primitives; RTK Query's
// fetchBaseQuery constructs Headers + Request before calling fetch —
// without these, every mutation rejects before the (test-mocked)
// fetch is ever reached.
if ( typeof global.Headers === 'undefined' ) {
	global.Headers = class Headers {
		constructor( init = {} ) {
			this._map = new Map();
			if ( init instanceof Headers ) {
				init.forEach( ( v, k ) => this._map.set( k.toLowerCase(), v ) );
			} else if ( Array.isArray( init ) ) {
				init.forEach( ( [ k, v ] ) => this._map.set( k.toLowerCase(), v ) );
			} else {
				Object.entries( init ).forEach( ( [ k, v ] ) =>
					this._map.set( k.toLowerCase(), v )
				);
			}
		}
		get( k ) {
			return this._map.get( String( k ).toLowerCase() ) ?? null;
		}
		set( k, v ) {
			this._map.set( String( k ).toLowerCase(), v );
		}
		has( k ) {
			return this._map.has( String( k ).toLowerCase() );
		}
		delete( k ) {
			this._map.delete( String( k ).toLowerCase() );
		}
		append( k, v ) {
			this.set( k, v );
		}
		forEach( cb ) {
			this._map.forEach( ( v, k ) => cb( v, k, this ) );
		}
		entries() {
			return this._map.entries();
		}
		keys() {
			return this._map.keys();
		}
		values() {
			return this._map.values();
		}
		[ Symbol.iterator ]() {
			return this._map.entries();
		}
	};
}

if ( typeof global.Request === 'undefined' ) {
	global.Request = class Request {
		constructor( input, init = {} ) {
			this.url = typeof input === 'string' ? input : input.url;
			this.method = init.method || ( typeof input === 'object' ? input.method : 'GET' ) || 'GET';
			this.headers = new global.Headers( init.headers || {} );
			this.body = init.body;
			this.signal = init.signal;
		}
		clone() {
			return this;
		}
	};
}

if ( typeof global.AbortController === 'undefined' ) {
	global.AbortController = class AbortController {
		constructor() {
			this.signal = { aborted: false, addEventListener() {}, removeEventListener() {} };
		}
		abort() {
			this.signal.aborted = true;
		}
	};
}

// HeadlessUI's Listbox/Combobox observe their trigger size on open;
// jsdom has no ResizeObserver, so give it an inert stand-in.
if ( typeof global.ResizeObserver === 'undefined' ) {
	global.ResizeObserver = class ResizeObserver {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
}

// jsdom has no Web Animations API. Headless UI's Dialog/Transition
// lazily polyfills Element.prototype.getAnimations and emits a
// console.warn when it does — which @wordpress/jest-console turns into
// a failure in whichever test happens to open the first dialog.
// Pre-defining it keeps Headless UI quiet.
if ( typeof Element !== 'undefined' && ! Element.prototype.getAnimations ) {
	Element.prototype.getAnimations = function () {
		return [];
	};
}

// The app boots from window.ctxfeedV8 (localized by V8/Admin/Assets.php).
// Provide the same shape the PHP side pins in AdminModuleTest.
window.ctxfeedV8 = {
	rest_url: '/wp-json/ctxfeed/v8/',
	nonce: 'test-nonce',
	features: {},
	is_pro: false,
	admin_url: '/wp-admin/',
	plugin_url: '/wp-content/plugins/ctxfeed/',
	code_editor: false,
};
