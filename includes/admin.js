/* globals wp, rrSEOAdmin */
/**
 * RankRocket SEO — Admin Panel JS
 *
 * Detects which admin page container is present in the DOM and calls the
 * corresponding render function. All data is fetched via wp.apiFetch against
 * the existing rankrocket-seo/v1 REST endpoints.
 *
 * Loaded only on RankRocket admin pages (scoped by admin_enqueue_scripts hook).
 */
( function ( apiFetch ) {
	'use strict';

	// ── Setup ─────────────────────────────────────────────────────────────────────
	apiFetch.use( apiFetch.createNonceMiddleware( rrSEOAdmin.nonce ) );
	apiFetch.use( apiFetch.createRootURLMiddleware( rrSEOAdmin.root ) );

	var cfg = rrSEOAdmin;

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
	 * Returns a coloured badge span.
	 *
	 * @param {string} text
	 * @param {string} color 'green' | 'red' | 'orange'
	 * @return {string}
	 */
	function badge( text, color ) {
		return '<span class="rrseo-badge rrseo-badge-' + esc( color ) + '">' + esc( text ) + '</span>';
	}

	/**
	 * Truncates a string to the given length, appending an ellipsis if cut.
	 *
	 * @param {string} str
	 * @param {number} max
	 * @return {string}
	 */
	function truncate( str, max ) {
		if ( ! str ) {
			return '';
		}
		if ( str.length <= max ) {
			return str;
		}
		return str.slice( 0, max ) + '…';
	}

	/**
	 * Returns a "Loading…" paragraph string.
	 *
	 * @return {string}
	 */
	function loading() {
		return '<p class="rrseo-loading">Loading…</p>';
	}

	/**
	 * Returns an error paragraph string.
	 *
	 * @param {string} msg
	 * @return {string}
	 */
	function errHtml( msg ) {
		return '<p class="rrseo-error"><strong>Error:</strong> ' + esc( msg ) + '</p>';
	}

	// ── Overview ──────────────────────────────────────────────────────────────────

	/**
	 * Renders the Overview page by fetching /status.
	 *
	 * @param {HTMLElement} container
	 */
	function renderOverview( container ) {
		apiFetch( { path: '/rankrocket-seo/v1/status' } ).then( function ( data ) {
			var html = '<div class="rrseo-overview-grid">';

			// Status card.
			html += '<div class="rrseo-card">';
			html += '<h2>Plugin Status</h2>';
			html += '<table class="rrseo-info-table">';
			html += '<tr><th>Plugin Version</th><td>' + esc( data.version ) + '</td></tr>';
			html += '<tr><th>PHP</th><td>' + esc( data.php_version ) + '</td></tr>';
			html += '<tr><th>WordPress</th><td>' + esc( data.wp_version ) + '</td></tr>';
			html += '<tr><th>RankMath</th><td>' + ( data.rankmath_active
				? badge( 'Active — RankMath is handling SEO output', 'orange' )
				: badge( 'Not active', 'green' )
			) + '</td></tr>';
			html += '<tr><th>Active Snippets</th><td>' + esc( data.snippet_count ) + '</td></tr>';
			html += '</table></div>';

			// URLs card.
			html += '<div class="rrseo-card">';
			html += '<h2>Site URLs</h2>';
			html += '<table class="rrseo-info-table">';
			html += '<tr><th>Sitemap</th><td><a href="' + esc( data.sitemap_url ) + '" target="_blank">'
				+ esc( data.sitemap_url ) + '</a></td></tr>';
			html += '<tr><th>llms.txt</th><td><a href="' + esc( data.llms_url ) + '" target="_blank">'
				+ esc( data.llms_url ) + '</a></td></tr>';
			html += '<tr><th>Update Manifest</th><td><a href="' + esc( data.update_url )
				+ '" target="_blank">View on GitHub</a></td></tr>';
			html += '</table></div>';

			// Cache purge card.
			html += '<div class="rrseo-card">';
			html += '<h2>Cache</h2>';
			html += '<p>Purge all supported cache layers (LiteSpeed, Breeze, WP Rocket, W3TC, Varnish, SiteGround).</p>';
			html += '<button id="rrseo-purge-btn" class="button button-primary">Purge Cache Now</button>';
			html += '<div id="rrseo-purge-result" style="margin-top:8px;"></div>';
			html += '</div>';

			html += '</div>';
			container.innerHTML = html;

			document.getElementById( 'rrseo-purge-btn' ).addEventListener( 'click', function () {
				var btn    = this;
				var result = document.getElementById( 'rrseo-purge-result' );
				btn.disabled    = true;
				btn.textContent = 'Purging…';
				result.innerHTML = '';

				apiFetch( { path: '/rankrocket-seo/v1/cache/purge', method: 'POST' } )
					.then( function ( r ) {
						result.innerHTML = '<p class="rrseo-success">' + esc( r.message ) + '</p>';
						btn.disabled    = false;
						btn.textContent = 'Purge Cache Now';
					} )
					.catch( function ( e ) {
						result.innerHTML = errHtml( e.message || 'Cache purge failed.' );
						btn.disabled    = false;
						btn.textContent = 'Purge Cache Now';
					} );
			} );
		} ).catch( function ( e ) {
			container.innerHTML = errHtml( e.message || 'Failed to load status.' );
		} );
	}

	// ── Posts & Pages ─────────────────────────────────────────────────────────────

	var postsState = { type: 'posts', page: 1, total: 0, pages: 0 };

	/**
	 * Renders the Posts & Pages page shell with tab controls.
	 *
	 * @param {HTMLElement} container
	 */
	function renderPosts( container ) {
		var html = '<div class="rrseo-tabs">';
		html += '<button class="rrseo-tab-btn active" data-type="posts">Posts</button>';
		html += '<button class="rrseo-tab-btn" data-type="pages">Pages</button>';
		html += '</div>';
		html += '<div id="rrseo-posts-table">' + loading() + '</div>';
		html += '<div id="rrseo-posts-pagination" class="rrseo-pagination"></div>';
		container.innerHTML = html;

		container.querySelectorAll( '.rrseo-tab-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				container.querySelectorAll( '.rrseo-tab-btn' ).forEach( function ( b ) {
					b.classList.remove( 'active' );
				} );
				this.classList.add( 'active' );
				postsState.type = this.getAttribute( 'data-type' );
				postsState.page = 1;
				loadPostsPage();
			} );
		} );

		loadPostsPage();
	}

	/**
	 * Fetches one page of posts/pages and their SEO meta, then renders the table.
	 */
	function loadPostsPage() {
		var tableDiv = document.getElementById( 'rrseo-posts-table' );
		var pagDiv   = document.getElementById( 'rrseo-posts-pagination' );
		if ( ! tableDiv ) {
			return;
		}
		tableDiv.innerHTML = loading();

		var path = '/wp/v2/' + postsState.type
			+ '?status=publish&per_page=' + cfg.batch
			+ '&page=' + postsState.page
			+ '&_fields=id,title,link';

		apiFetch( { path: path, parse: false } ).then( function ( response ) {
			postsState.total = parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 );
			postsState.pages = parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 );
			return response.json();
		} ).then( function ( wpPosts ) {
			if ( ! wpPosts.length ) {
				tableDiv.innerHTML = '<p>No published ' + esc( postsState.type ) + ' found.</p>';
				return;
			}

			var wpMap = {};
			var ids   = [];
			wpPosts.forEach( function ( p ) {
				wpMap[ p.id ] = p;
				ids.push( p.id );
			} );

			return apiFetch( {
				path:   '/rankrocket-seo/v1/meta/bulk-get',
				method: 'POST',
				data:   { post_ids: ids },
			} ).then( function ( seoResp ) {
				var html = '<table class="widefat rrseo-table">';
				html += '<thead><tr>';
				html += '<th>Title</th><th>SEO Title</th><th>Description</th><th>Focus KW</th><th>Robots</th>';
				html += '</tr></thead><tbody>';

				seoResp.pages.forEach( function ( item ) {
					var wp       = wpMap[ item.post_id ] || {};
					var title    = ( wp.title && wp.title.rendered ) ? wp.title.rendered : '(Post #' + item.post_id + ')';
					var meta     = item.meta || {};
					var seoTitle = meta.rr_seo_title || '';
					var seoDesc  = meta.rr_seo_description || '';
					var focusKw  = meta.rr_seo_focus_keyword || '';
					var robots   = meta.rr_seo_robots || '';

					html += '<tr>';
					html += '<td><a href="' + esc( wp.link || '' ) + '" target="_blank">' + esc( title ) + '</a></td>';
					html += '<td>' + ( seoTitle
						? esc( truncate( seoTitle, 60 ) )
						: '<em class="rrseo-empty">empty</em>' ) + '</td>';
					html += '<td>' + ( seoDesc
						? esc( truncate( seoDesc, 80 ) )
						: '<em class="rrseo-empty">empty</em>' ) + '</td>';
					html += '<td>' + ( focusKw ? esc( focusKw ) : '<em class="rrseo-empty">—</em>' ) + '</td>';
					html += '<td>' + ( robots ? esc( robots ) : '<em class="rrseo-empty">—</em>' ) + '</td>';
					html += '</tr>';
				} );

				html += '</tbody></table>';
				tableDiv.innerHTML = html;
				renderPostsPagination( pagDiv );
			} );
		} ).catch( function ( e ) {
			tableDiv.innerHTML = errHtml( e.message || 'Failed to load data.' );
		} );
	}

	/**
	 * Renders prev/next pagination controls for the Posts & Pages table.
	 *
	 * @param {HTMLElement|null} pagDiv
	 */
	function renderPostsPagination( pagDiv ) {
		if ( ! pagDiv ) {
			return;
		}
		if ( postsState.pages <= 1 ) {
			pagDiv.innerHTML = '';
			return;
		}

		var html = '<span>Page ' + postsState.page + ' of ' + postsState.pages
			+ ' (' + postsState.total + ' total)</span> ';
		if ( postsState.page > 1 ) {
			html += '<button class="button" id="rrseo-prev-page">← Prev</button> ';
		}
		if ( postsState.page < postsState.pages ) {
			html += '<button class="button" id="rrseo-next-page">Next →</button>';
		}
		pagDiv.innerHTML = html;

		var prevBtn = document.getElementById( 'rrseo-prev-page' );
		var nextBtn = document.getElementById( 'rrseo-next-page' );
		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				postsState.page--;
				loadPostsPage();
			} );
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				postsState.page++;
				loadPostsPage();
			} );
		}
	}

	// ── Image ALT ─────────────────────────────────────────────────────────────────

	var imagesState = { page: 1, missingOnly: false };

	/**
	 * Renders the Image ALT Text page shell with filter toggle.
	 *
	 * @param {HTMLElement} container
	 */
	function renderImages( container ) {
		var html = '<div class="rrseo-toolbar">';
		html += '<label><input type="checkbox" id="rrseo-missing-only"> Show missing ALT only</label>';
		html += '</div>';
		html += '<div id="rrseo-images-table">' + loading() + '</div>';
		container.innerHTML = html;

		document.getElementById( 'rrseo-missing-only' ).addEventListener( 'change', function () {
			imagesState.missingOnly = this.checked;
			imagesState.page        = 1;
			loadImagesPage();
		} );

		loadImagesPage();
	}

	/**
	 * Fetches and renders one page of images.
	 */
	function loadImagesPage() {
		var tableDiv = document.getElementById( 'rrseo-images-table' );
		if ( ! tableDiv ) {
			return;
		}
		tableDiv.innerHTML = loading();

		apiFetch( { path: '/rankrocket-seo/v1/images?per_page=50&page=' + imagesState.page } )
			.then( function ( data ) {
				var images = data.images || [];
				if ( imagesState.missingOnly ) {
					images = images.filter( function ( img ) { return img.missing; } );
				}

				if ( ! images.length ) {
					tableDiv.innerHTML = '<p>' + esc( imagesState.missingOnly
						? 'No images with missing ALT text.'
						: 'No images found.' ) + '</p>';
					return;
				}

				var html = '<p>'
					+ badge( data.missing_alt_count + ' missing ALT', data.missing_alt_count > 0 ? 'red' : 'green' )
					+ ' &nbsp; ' + esc( data.count ) + ' images total.</p>';
				html += '<table class="widefat rrseo-table">';
				html += '<thead><tr>';
				html += '<th>Thumbnail</th><th>Filename</th><th>ALT Text</th><th>Status</th>';
				html += '</tr></thead><tbody>';

				images.forEach( function ( img ) {
					html += '<tr>';
					html += '<td><img src="' + esc( img.url ) + '" style="max-width:60px;max-height:45px;" '
						+ 'alt="' + esc( img.alt ) + '"></td>';
					html += '<td>' + esc( img.filename ) + '</td>';
					html += '<td>' + ( img.alt ? esc( img.alt ) : '<em class="rrseo-empty">— missing —</em>' ) + '</td>';
					html += '<td>' + ( img.missing ? badge( 'Missing', 'red' ) : badge( 'OK', 'green' ) ) + '</td>';
					html += '</tr>';
				} );

				html += '</tbody></table>';
				tableDiv.innerHTML = html;
			} )
			.catch( function ( e ) {
				tableDiv.innerHTML = errHtml( e.message || 'Failed to load images.' );
			} );
	}

	// ── Snippets ──────────────────────────────────────────────────────────────────

	/**
	 * Renders the Snippets page by fetching /snippets.
	 *
	 * @param {HTMLElement} container
	 */
	function renderSnippets( container ) {
		apiFetch( { path: '/rankrocket-seo/v1/snippets' } ).then( function ( data ) {
			var snippets = data.snippets || [];
			if ( ! snippets.length ) {
				container.innerHTML = '<p>No snippets configured. Use the REST API or pipeline to create snippets.</p>';
				return;
			}

			var html = '<p>' + badge( data.count + ' snippet(s)', 'green' ) + '</p>';
			html += '<table class="widefat rrseo-table">';
			html += '<thead><tr>';
			html += '<th>Title / ID</th><th>Location</th><th>Display On</th><th>Status</th><th>Created</th>';
			html += '</tr></thead><tbody>';

			snippets.forEach( function ( s ) {
				var cls = 'active' === s.status ? 'green' : 'red';
				html += '<tr>';
				html += '<td><strong>' + esc( s.title ) + '</strong><br><code style="font-size:11px;">'
					+ esc( s.id ) + '</code></td>';
				html += '<td>' + esc( s.location ) + '</td>';
				html += '<td>' + esc( s.display_on ) + '</td>';
				html += '<td>' + badge( s.status, cls ) + '</td>';
				html += '<td>' + esc( ( s.created_at || '' ).slice( 0, 10 ) ) + '</td>';
				html += '</tr>';
			} );

			html += '</tbody></table>';
			container.innerHTML = html;
		} ).catch( function ( e ) {
			container.innerHTML = errHtml( e.message || 'Failed to load snippets.' );
		} );
	}

	// ── llms.txt ──────────────────────────────────────────────────────────────────

	/**
	 * Renders the llms.txt page by fetching /llms.
	 *
	 * @param {HTMLElement} container
	 */
	function renderLlms( container ) {
		apiFetch( { path: '/rankrocket-seo/v1/llms' } ).then( function ( data ) {
			var config   = data.config || {};
			var sections = config.sections || [];

			var html = '<p>Live URL: <a href="' + esc( data.url ) + '" target="_blank">'
				+ esc( data.url ) + '</a></p>';

			html += '<div class="rrseo-card"><h3>Intro Text</h3>';
			if ( config.intro ) {
				html += '<pre class="rrseo-pre">' + esc( config.intro ) + '</pre>';
			} else {
				html += '<p><em class="rrseo-empty">No intro text configured.</em></p>';
			}
			html += '</div>';

			if ( sections.length ) {
				html += '<div class="rrseo-card"><h3>Custom Sections (' + esc( sections.length ) + ')</h3>';
				sections.forEach( function ( s ) {
					html += '<h4>' + esc( s.heading || '' ) + '</h4><ul>';
					( s.items || [] ).forEach( function ( item ) {
						html += '<li>' + esc( item ) + '</li>';
					} );
					html += '</ul>';
				} );
				html += '</div>';
			} else {
				html += '<p><em class="rrseo-empty">No custom sections configured.</em></p>';
			}

			container.innerHTML = html;
		} ).catch( function ( e ) {
			container.innerHTML = errHtml( e.message || 'Failed to load llms.txt config.' );
		} );
	}

	// ── Sitemap ───────────────────────────────────────────────────────────────────

	/**
	 * Renders the Sitemap Preview page by fetching /sitemap/preview.
	 *
	 * @param {HTMLElement} container
	 */
	function renderSitemap( container ) {
		container.innerHTML = loading();

		apiFetch( { path: '/rankrocket-seo/v1/sitemap/preview' } ).then( function ( data ) {
			var html = '<div class="rrseo-sitemap-summary">';
			html += '<span class="rrseo-stat">' + badge( data.included_count + ' included', 'green' ) + '</span>';
			html += '<span class="rrseo-stat">' + badge( data.excluded_count + ' excluded (noindex)', 'red' ) + '</span>';
			html += ' &nbsp; <a href="' + esc( data.sitemap_url ) + '" target="_blank">View live sitemap ↗</a>';
			html += '</div>';

			html += '<table class="widefat rrseo-table">';
			html += '<thead><tr>';
			html += '<th>URL</th><th>Type</th><th>Last Modified</th><th>Priority</th><th>Status</th>';
			html += '</tr></thead><tbody>';

			( data.entries || [] ).forEach( function ( e ) {
				var rowCls = e.included ? '' : 'rrseo-row-excluded';
				html += '<tr class="' + rowCls + '">';
				html += '<td><a href="' + esc( e.loc ) + '" target="_blank">' + esc( e.loc ) + '</a></td>';
				html += '<td>' + esc( e.type ) + '</td>';
				html += '<td>' + esc( e.lastmod ) + '</td>';
				html += '<td>' + esc( e.priority ) + '</td>';
				html += '<td>' + ( e.included ? badge( 'Included', 'green' ) : badge( 'Excluded', 'red' ) ) + '</td>';
				html += '</tr>';
			} );

			html += '</tbody></table>';
			container.innerHTML = html;
		} ).catch( function ( e ) {
			container.innerHTML = errHtml( e.message || 'Failed to load sitemap.' );
		} );
	}

	// ── Router ────────────────────────────────────────────────────────────────────

	var pages = {
		'rrseo-page-overview': renderOverview,
		'rrseo-page-posts':    renderPosts,
		'rrseo-page-images':   renderImages,
		'rrseo-page-snippets': renderSnippets,
		'rrseo-page-llms':     renderLlms,
		'rrseo-page-sitemap':  renderSitemap,
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		Object.keys( pages ).forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				pages[ id ]( el );
			}
		} );
	} );
}( window.wp.apiFetch ) );
