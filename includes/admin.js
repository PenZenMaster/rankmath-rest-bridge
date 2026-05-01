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

			// Force update check card.
			html += '<div class="rrseo-card">';
			html += '<h2>Plugin Update Check</h2>';
			html += '<p>Clears the WordPress and PUC update caches. After clicking, visit <a href="update-core.php">Dashboard &rsaquo; Updates</a> and click &ldquo;Check Again&rdquo; to fetch the latest version.</p>';
			html += '<button id="rrseo-check-updates-btn" class="button">Clear Update Cache</button>';
			html += '<div id="rrseo-check-updates-result" style="margin-top:8px;"></div>';
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

			document.getElementById( 'rrseo-check-updates-btn' ).addEventListener( 'click', function () {
				var btn    = this;
				var result = document.getElementById( 'rrseo-check-updates-result' );
				btn.disabled    = true;
				btn.textContent = 'Clearing…';
				result.innerHTML = '';

				apiFetch( { path: '/rankrocket-seo/v1/check-updates', method: 'POST' } )
					.then( function ( r ) {
						result.innerHTML = '<p class="rrseo-success">' + esc( r.message ) + '</p>';
						btn.disabled    = false;
						btn.textContent = 'Clear Update Cache';
					} )
					.catch( function ( e ) {
						result.innerHTML = errHtml( e.message || 'Cache clear failed.' );
						btn.disabled    = false;
						btn.textContent = 'Clear Update Cache';
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
		container.innerHTML = loading();

		apiFetch( { path: '/rankrocket-seo/v1/llms' } ).then( function ( data ) {
			var config          = data.config || {};
			var sectionsConfig  = ( config.sections && ! Array.isArray( config.sections ) ) ? config.sections : null;
			var customSections  = config.custom_sections || ( Array.isArray( config.sections ) ? config.sections : [] );
			var businessFacts   = config.business_facts || null;
			var excludePatterns = config.exclude_patterns || [];
			var maxDesc         = config.max_description_chars || 240;
			var exclUtility     = config.exclude_utility_pages !== false;

			var html = '<div class="rrseo-llms-header">';
			html += '<p>Live URL: <a href="' + esc( data.url ) + '" target="_blank">' + esc( data.url ) + ' ↗</a></p>';
			html += '<div class="rrseo-llms-actions">';
			html += '<button id="rrseo-llms-preview-btn" class="button button-primary">Preview Generated llms.txt</button> ';
			html += '</div></div>';

			html += '<div id="rrseo-llms-preview-panel" style="display:none;">' + loading() + '</div>';

			html += '<div class="rrseo-overview-grid" style="margin-top:16px;">';

			// ── Business Facts ───────────────────────────────────────────────────
			html += '<div class="rrseo-card">';
			html += '<h2>Business Facts</h2>';
			if ( businessFacts && Object.keys( businessFacts ).length ) {
				html += '<table class="rrseo-info-table">';
				var factLabels = {
					business_name: 'Business', website: 'Website', phone: 'Phone',
					address: 'Address', schema_type: 'Schema Type', entity_id: 'Entity ID',
				};
				Object.keys( factLabels ).forEach( function ( k ) {
					if ( businessFacts[ k ] ) {
						html += '<tr><th>' + esc( factLabels[ k ] ) + '</th><td>' + esc( businessFacts[ k ] ) + '</td></tr>';
					}
				} );
				if ( businessFacts.primary_services ) {
					var svcs = Array.isArray( businessFacts.primary_services ) ? businessFacts.primary_services.join( ', ' ) : businessFacts.primary_services;
					html += '<tr><th>Services</th><td>' + esc( svcs ) + '</td></tr>';
				}
				if ( businessFacts.service_area ) {
					var area = Array.isArray( businessFacts.service_area ) ? businessFacts.service_area.join( ', ' ) : businessFacts.service_area;
					html += '<tr><th>Service Area</th><td>' + esc( area ) + '</td></tr>';
				}
				html += '</table>';
			} else {
				html += '<p><em class="rrseo-empty">Not configured. Set via POST /llms with business_facts object.</em></p>';
			}
			html += '</div>';

			// ── Section Classifier ───────────────────────────────────────────────
			html += '<div class="rrseo-card">';
			html += '<h2>Section Classifier</h2>';
			if ( sectionsConfig ) {
				var sectionKeys = Object.keys( sectionsConfig ).sort( function ( a, b ) {
					return ( sectionsConfig[ a ].order || 99 ) - ( sectionsConfig[ b ].order || 99 );
				} );
				html += '<table class="widefat rrseo-table" style="font-size:12px;">';
				html += '<thead><tr><th>#</th><th>Section Key</th><th>Label</th><th>Rules</th></tr></thead><tbody>';
				sectionKeys.forEach( function ( key ) {
					var s     = sectionsConfig[ key ];
					var rules = [];
					if ( s.exact_paths && s.exact_paths.length ) {
						rules.push( 'exact: ' + s.exact_paths.slice( 0, 3 ).map( esc ).join( ', ' ) + ( s.exact_paths.length > 3 ? '…' : '' ) );
					}
					if ( s.url_patterns && s.url_patterns.length ) {
						rules.push( 'prefix: ' + s.url_patterns.slice( 0, 3 ).map( esc ).join( ', ' ) + ( s.url_patterns.length > 3 ? '…' : '' ) );
					}
					if ( s.post_types && s.post_types.length ) {
						rules.push( 'type: ' + s.post_types.map( esc ).join( ', ' ) );
					}
					html += '<tr>';
					html += '<td>' + esc( s.order || '—' ) + '</td>';
					html += '<td><code>' + esc( key ) + '</code></td>';
					html += '<td>' + esc( s.label || key ) + '</td>';
					html += '<td style="font-size:11px;color:#555;">' + ( rules.length ? rules.join( '<br>' ) : '<em>fallback</em>' ) + '</td>';
					html += '</tr>';
				} );
				html += '</tbody></table>';
			} else {
				html += '<p><em class="rrseo-empty">No section classifier configured — llms.txt uses simple Pages / Blog Posts structure.</em></p>';
				html += '<p style="font-size:12px;color:#666;">Configure via POST /llms with a <code>sections</code> object keyed by section name.</p>';
			}
			html += '</div>';

			// ── Content Settings ─────────────────────────────────────────────────
			html += '<div class="rrseo-card">';
			html += '<h2>Content Settings</h2>';
			html += '<table class="rrseo-info-table">';
			html += '<tr><th>Max Description</th><td>' + esc( maxDesc ) + ' chars</td></tr>';
			html += '<tr><th>Exclude noindex</th><td>' + badge( config.exclude_noindex !== false ? 'Yes' : 'No', config.exclude_noindex !== false ? 'green' : 'red' ) + '</td></tr>';
			html += '<tr><th>Exclude utility pages</th><td>' + badge( exclUtility ? 'Yes' : 'No', exclUtility ? 'green' : 'red' ) + '</td></tr>';
			html += '<tr><th>Include sitemaps section</th><td>' + badge( config.include_sitemaps !== false ? 'Yes' : 'No', config.include_sitemaps !== false ? 'green' : 'orange' ) + '</td></tr>';
			var fallbackChain = Array.isArray( config.description_fallback )
				? config.description_fallback.join( ' → ' )
				: 'rrseo_description → excerpt → first_paragraph';
			html += '<tr><th>Description fallback</th><td style="font-size:11px;">' + esc( fallbackChain ) + '</td></tr>';
			html += '</table>';
			if ( excludePatterns.length ) {
				html += '<p style="margin:8px 0 4px;font-weight:600;font-size:12px;">Custom Exclude Patterns</p>';
				html += '<ul style="margin:0;padding-left:18px;font-size:12px;">';
				excludePatterns.forEach( function ( p ) { html += '<li><code>' + esc( p ) + '</code></li>'; } );
				html += '</ul>';
			}
			html += '</div>';

			// ── Intro Text ───────────────────────────────────────────────────────
			html += '<div class="rrseo-card">';
			html += '<h2>Intro Text</h2>';
			if ( config.intro ) {
				html += '<pre class="rrseo-pre">' + esc( config.intro ) + '</pre>';
			} else {
				html += '<p><em class="rrseo-empty">No intro text configured.</em></p>';
			}
			html += '</div>';

			// ── Custom Sections (legacy text blocks) ─────────────────────────────
			if ( customSections.length ) {
				html += '<div class="rrseo-card">';
				html += '<h2>Custom Text Sections (' + esc( customSections.length ) + ')</h2>';
				customSections.forEach( function ( s ) {
					html += '<h4 style="margin:8px 0 4px;">' + esc( s.heading || '' ) + '</h4><ul style="margin:0;">';
					( s.items || [] ).forEach( function ( item ) { html += '<li>' + esc( item ) + '</li>'; } );
					html += '</ul>';
				} );
				html += '</div>';
			}

			html += '</div>'; // .rrseo-overview-grid

			container.innerHTML = html;

			// ── Preview button handler ───────────────────────────────────────────
			var previewBtn   = document.getElementById( 'rrseo-llms-preview-btn' );
			var previewPanel = document.getElementById( 'rrseo-llms-preview-panel' );
			var previewLoaded = false;

			previewBtn.addEventListener( 'click', function () {
				if ( previewPanel.style.display === 'none' ) {
					previewPanel.style.display = 'block';
					if ( ! previewLoaded ) {
						previewLoaded = true;
						loadLlmsPreview( previewPanel );
					}
					previewBtn.textContent = 'Hide Preview';
				} else {
					previewPanel.style.display = 'none';
					previewBtn.textContent = 'Preview Generated llms.txt';
				}
			} );
		} ).catch( function ( e ) {
			container.innerHTML = errHtml( e.message || 'Failed to load llms.txt config.' );
		} );
	}

	/**
	 * Fetches /llms/preview and renders results into the preview panel.
	 *
	 * @param {HTMLElement} panel
	 */
	function loadLlmsPreview( panel ) {
		panel.innerHTML = loading();
		apiFetch( { path: '/rankrocket-seo/v1/llms/preview?format=json' } )
			.then( function ( data ) {
				var html = '<div style="margin-top:8px;">';

				// Summary row.
				html += '<div style="margin-bottom:12px;">';
				html += badge( data.url_count + ' URLs', 'green' ) + ' &nbsp; ';
				if ( data.warnings && data.warnings.length ) {
					html += badge( data.warnings.length + ' warning(s)', 'orange' );
				} else {
					html += badge( '0 warnings', 'green' );
				}
				html += '</div>';

				// Section counts.
				if ( data.sections && Object.keys( data.sections ).length ) {
					html += '<table class="widefat rrseo-table" style="margin-bottom:12px;font-size:12px;">';
					html += '<thead><tr><th>Section</th><th>URLs</th></tr></thead><tbody>';
					Object.keys( data.sections ).forEach( function ( key ) {
						html += '<tr><td><code>' + esc( key ) + '</code></td><td>' + esc( data.sections[ key ] ) + '</td></tr>';
					} );
					html += '</tbody></table>';
				}

				// Warnings.
				if ( data.warnings && data.warnings.length ) {
					html += '<p style="font-weight:600;font-size:12px;margin-bottom:4px;">Warnings</p>';
					html += '<ul style="font-size:12px;margin:0 0 12px;">';
					data.warnings.forEach( function ( w ) {
						html += '<li><code>' + esc( w.code ) + '</code> — ' + esc( w.message || w.url || '' ) + '</li>';
					} );
					html += '</ul>';
				}

				// Excluded URLs.
				if ( data.excluded && data.excluded.length ) {
					html += '<details style="margin-bottom:12px;"><summary style="cursor:pointer;font-size:12px;">'
						+ esc( data.excluded.length ) + ' excluded URL(s)</summary>';
					html += '<ul style="font-size:11px;margin:4px 0 0;">';
					data.excluded.slice( 0, 20 ).forEach( function ( e ) {
						html += '<li>' + esc( e.url ) + ' <span style="color:#999;">(' + esc( e.reason ) + ')</span></li>';
					} );
					if ( data.excluded.length > 20 ) {
						html += '<li style="color:#999;">… and ' + esc( data.excluded.length - 20 ) + ' more</li>';
					}
					html += '</ul></details>';
				}

				// Full generated content.
				html += '<details><summary style="cursor:pointer;font-size:12px;font-weight:600;">View full llms.txt output</summary>';
				html += '<pre class="rrseo-pre" style="max-height:400px;margin-top:8px;">' + esc( data.content ) + '</pre>';
				html += '</details>';

				html += '</div>';
				panel.innerHTML = html;
			} )
			.catch( function ( e ) {
				panel.innerHTML = errHtml( e.message || 'Failed to load preview.' );
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
