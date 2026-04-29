/* globals wp, rrSEOMeta */
/**
 * RankRocket SEO — Edit Post/Page Meta Box JS
 *
 * Detects the #rrseo-meta-box div on Edit Post / Edit Page screens, reads the
 * post ID from its data-post-id attribute, then fires three parallel REST calls:
 *   GET /rankrocket-seo/v1/get/{id}       — SEO meta fields
 *   GET /rankrocket-seo/v1/schema/{id}    — schema @type (if set)
 *   GET /rankrocket-seo/v1/log/{id}       — last 3 audit log entries
 *
 * Renders the results as a read-only table. Character counts are colour-coded
 * using the same thresholds as the validation layer (passed via rrSEOMeta).
 *
 * Loaded only on post.php / post-new.php for allowed post types (scoped by
 * admin_enqueue_scripts in RRSEO_MetaBox::enqueue_assets).
 */
( function ( apiFetch ) {
	'use strict';

	apiFetch.use( apiFetch.createNonceMiddleware( rrSEOMeta.nonce ) );
	apiFetch.use( apiFetch.createRootURLMiddleware( rrSEOMeta.root ) );

	var m = rrSEOMeta;

	// ── Utilities ─────────────────────────────────────────────────────────────────

	/**
	 * HTML-escapes a value for safe innerHTML insertion.
	 *
	 * @param {*} str
	 * @return {string}
	 */
	function esc( str ) {
		return String( null === str || undefined === str ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Returns the CSS class for a character-count badge.
	 *
	 * @param {number} len
	 * @param {number} warnL Lower warning threshold.
	 * @param {number} warnH Upper warning threshold.
	 * @param {number} max   Hard maximum.
	 * @return {string}
	 */
	function charCls( len, warnL, warnH, max ) {
		if ( len > max )   { return 'over'; }
		if ( len > warnH ) { return 'warn'; }
		if ( len < warnL ) { return 'low'; }
		return 'ok';
	}

	/**
	 * Returns a table row for a plain meta field.
	 *
	 * @param {string}       label
	 * @param {string|null}  value
	 * @return {string}
	 */
	function metaRow( label, value ) {
		return '<tr><th>' + esc( label ) + '</th><td>'
			+ ( value
				? '<span class="rrseo-val">' + esc( value ) + '</span>'
				: '<em class="rrseo-empty">—</em>' )
			+ '</td></tr>';
	}

	/**
	 * Returns a table row with an inline character-count badge.
	 *
	 * @param {string}       label
	 * @param {string|null}  value
	 * @param {number}       warnL
	 * @param {number}       warnH
	 * @param {number}       max
	 * @return {string}
	 */
	function metaRowCounted( label, value, warnL, warnH, max ) {
		if ( ! value ) {
			return metaRow( label, null );
		}
		var len = value.length;
		var cls = charCls( len, warnL, warnH, max );
		return '<tr><th>' + esc( label ) + '</th><td>'
			+ '<span class="rrseo-val">' + esc( value ) + '</span>'
			+ '<span class="rrseo-char ' + cls + '">' + len + '</span>'
			+ '</td></tr>';
	}

	// ── Render ────────────────────────────────────────────────────────────────────

	/**
	 * Populates the meta box with fetched data.
	 *
	 * @param {HTMLElement} box
	 * @param {Object}      metaResp
	 * @param {Object}      schemaResp
	 * @param {Object}      logResp
	 */
	function render( box, metaResp, schemaResp, logResp ) {
		var meta   = metaResp.meta || {};
		var schema = schemaResp.schema;
		var log    = ( logResp.log || [] ).slice( 0, 3 );

		var html = '<table class="rrseo-mb-table">';
		html += metaRowCounted( 'SEO Title',    meta.rr_seo_title,       m.titleWarnL, m.titleWarnH, m.titleMax );
		html += metaRowCounted( 'Description',  meta.rr_seo_description, m.descWarnL,  m.descWarnH,  m.descMax );
		html += metaRow( 'Focus KW', meta.rr_seo_focus_keyword );
		html += metaRow( 'Robots',   meta.rr_seo_robots );
		html += metaRow( 'OG Title', meta.rr_seo_og_title );
		html += metaRow( 'OG Image', meta.rr_seo_og_image );
		html += metaRow( 'Schema',   schema ? schema[ '@type' ] : null );
		html += '</table>';

		if ( log.length ) {
			html += '<hr class="rrseo-divider">';
			html += '<p class="rrseo-log-head">Recent Changes</p>';
			html += '<ul class="rrseo-log">';
			log.forEach( function ( entry ) {
				var when   = ( entry.timestamp || '' ).slice( 0, 10 );
				var fields = Object.keys( entry.changes || {} ).join( ', ' );
				html += '<li>'
					+ '<span class="rrseo-log-date">' + esc( when ) + '</span>'
					+ esc( fields )
					+ '</li>';
			} );
			html += '</ul>';
		}

		box.innerHTML = html;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────────

	document.addEventListener( 'DOMContentLoaded', function () {
		var box = document.getElementById( 'rrseo-meta-box' );
		if ( ! box ) {
			return;
		}
		var postId = box.getAttribute( 'data-post-id' );
		if ( ! postId ) {
			return;
		}

		Promise.all( [
			apiFetch( { path: '/rankrocket-seo/v1/get/' + postId } ),
			apiFetch( { path: '/rankrocket-seo/v1/schema/' + postId } ),
			apiFetch( { path: '/rankrocket-seo/v1/log/' + postId } ),
		] ).then( function ( results ) {
			render( box, results[ 0 ], results[ 1 ], results[ 2 ] );
		} ).catch( function ( e ) {
			box.innerHTML = '<p class="rrseo-error">Failed to load SEO data: '
				+ esc( e.message || 'Unknown error' ) + '</p>';
		} );
	} );
}( window.wp.apiFetch ) );
