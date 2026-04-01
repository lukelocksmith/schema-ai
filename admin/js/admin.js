/**
 * Schema AI — Admin JavaScript.
 *
 * Vanilla JS, no jQuery. Uses window.schemaAI config from wp_localize_script.
 *
 * @package Schema_AI
 */
( function () {
	'use strict';

	var config = window.schemaAI || {};

	/* ------------------------------------------------------------------ */
	/*  Helper: AJAX                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * POST to admin-ajax.php.
	 *
	 * @param {string} action  WP AJAX action name.
	 * @param {Object} data    Key/value pairs to send.
	 * @return {Promise<Object>}
	 */
	function ajax( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', config.nonce );

		if ( data ) {
			Object.keys( data ).forEach( function ( key ) {
				fd.append( key, data[ key ] );
			} );
		}

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	/* ------------------------------------------------------------------ */
	/*  Meta Box                                                           */
	/* ------------------------------------------------------------------ */

	function initMetaBox() {
		var wrapper = document.querySelector( '.schema-ai-metabox-wrapper' );
		if ( ! wrapper ) {
			return;
		}

		var postId  = wrapper.getAttribute( 'data-post-id' );
		var spinner = wrapper.querySelector( '.schema-ai-spinner' );
		var editor  = document.getElementById( 'schema-ai-json' );

		/* --- Regenerate ------------------------------------------------ */
		var regenBtn = wrapper.querySelector( '.schema-ai-regenerate' );
		if ( regenBtn ) {
			regenBtn.addEventListener( 'click', function () {
				if ( spinner ) {
					spinner.style.display = '';
				}
				regenBtn.disabled = true;

				ajax( 'schema_ai_regenerate', { post_id: postId } )
					.then( function ( res ) {
						if ( res.success ) {
							location.reload();
						} else {
							alert( ( res.data && res.data.message ) || 'Regeneration failed.' );
						}
					} )
					.catch( function () {
						alert( 'Network error. Please try again.' );
					} )
					.finally( function () {
						if ( spinner ) {
							spinner.style.display = 'none';
						}
						regenBtn.disabled = false;
					} );
			} );
		}

		/* --- Edit / Save ---------------------------------------------- */
		var editBtn = wrapper.querySelector( '.schema-ai-edit' );
		var editing = false;

		if ( editBtn && editor ) {
			editBtn.addEventListener( 'click', function () {
				if ( ! editing ) {
					/* Enter edit mode. */
					editor.removeAttribute( 'readonly' );
					editor.classList.add( 'schema-ai-editing' );
					editBtn.textContent = 'Save';
					editing = true;
				} else {
					/* Save. */
					if ( spinner ) {
						spinner.style.display = '';
					}
					editBtn.disabled = true;

					ajax( 'schema_ai_save', {
						post_id: postId,
						schema: editor.value,
					} )
						.then( function ( res ) {
							if ( res.success ) {
								location.reload();
							} else {
								alert( ( res.data && res.data.message ) || 'Save failed.' );
							}
						} )
						.catch( function () {
							alert( 'Network error. Please try again.' );
						} )
						.finally( function () {
							if ( spinner ) {
								spinner.style.display = 'none';
							}
							editBtn.disabled = false;
						} );
				}
			} );
		}

		/* --- Remove --------------------------------------------------- */
		var removeBtn = wrapper.querySelector( '.schema-ai-remove' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				if ( ! confirm( 'Remove schema from this post?' ) ) {
					return;
				}

				if ( spinner ) {
					spinner.style.display = '';
				}
				removeBtn.disabled = true;

				ajax( 'schema_ai_remove', { post_id: postId } )
					.then( function ( res ) {
						if ( res.success ) {
							location.reload();
						} else {
							alert( ( res.data && res.data.message ) || 'Remove failed.' );
						}
					} )
					.catch( function () {
						alert( 'Network error. Please try again.' );
					} )
					.finally( function () {
						if ( spinner ) {
							spinner.style.display = 'none';
						}
						removeBtn.disabled = false;
					} );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Bulk Generate                                                      */
	/* ------------------------------------------------------------------ */

	var bulkPollInterval = null;

	function initBulk() {
		var startBtn  = document.getElementById( 'schema-ai-bulk-start' );
		var cancelBtn = document.getElementById( 'schema-ai-bulk-cancel' );

		if ( ! startBtn ) {
			return;
		}

		var progressDiv = document.getElementById( 'schema-ai-bulk-progress' );
		var progressFill = document.getElementById( 'schema-ai-progress-fill' );
		var infoEl       = document.getElementById( 'schema-ai-bulk-info' );
		var logCard      = document.getElementById( 'schema-ai-bulk-log-card' );
		var logDiv       = document.getElementById( 'schema-ai-bulk-log' );

		/* --- Start ---------------------------------------------------- */
		startBtn.addEventListener( 'click', function () {
			var postTypeSelect = document.getElementById( 'schema-ai-bulk-post-type' );
			var modeRadios     = document.querySelectorAll( 'input[name="schema_ai_bulk_mode"]' );
			var batchInput     = document.getElementById( 'schema-ai-bulk-batch-size' );
			var delayInput     = document.getElementById( 'schema-ai-bulk-delay' );

			var postType  = postTypeSelect ? postTypeSelect.value : 'post';
			var mode      = 'missing';
			var batchSize = batchInput ? batchInput.value : '10';
			var delay     = delayInput ? delayInput.value : '2';

			modeRadios.forEach( function ( radio ) {
				if ( radio.checked ) {
					mode = radio.value;
				}
			} );

			if ( mode === 'all' && ! confirm( 'This will regenerate schema for ALL published posts of this type. Continue?' ) ) {
				return;
			}

			startBtn.disabled = true;
			cancelBtn.style.display = '';

			if ( progressDiv ) {
				progressDiv.style.display = '';
			}
			if ( logCard ) {
				logCard.style.display = '';
			}
			if ( progressFill ) {
				progressFill.style.width = '0%';
			}

			ajax( 'schema_ai_bulk_start', {
				post_type:  postType,
				mode:       mode,
				batch_size: batchSize,
				delay:      delay,
			} )
				.then( function ( res ) {
					if ( res.success ) {
						bulkPollInterval = setInterval( pollStatus, 2000 );
					} else {
						alert( ( res.data && res.data.message ) || 'Failed to start.' );
						startBtn.disabled = false;
						cancelBtn.style.display = 'none';
					}
				} )
				.catch( function () {
					alert( 'Network error.' );
					startBtn.disabled = false;
					cancelBtn.style.display = 'none';
				} );
		} );

		/* --- Cancel --------------------------------------------------- */
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				ajax( 'schema_ai_bulk_cancel' ).then( function () {
					if ( bulkPollInterval ) {
						clearInterval( bulkPollInterval );
						bulkPollInterval = null;
					}
					startBtn.disabled = false;
					cancelBtn.style.display = 'none';
				} );
			} );
		}

		/* --- Poll ----------------------------------------------------- */
		function pollStatus() {
			ajax( 'schema_ai_bulk_status' ).then( function ( res ) {
				if ( ! res.success || ! res.data ) {
					return;
				}

				var d         = res.data;
				var total     = d.total || 0;
				var processed = d.processed || 0;
				var success   = d.success || 0;
				var errors    = d.errors || 0;
				var pct       = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;

				if ( progressFill ) {
					progressFill.style.width = pct + '%';
				}
				if ( infoEl ) {
					infoEl.textContent = processed + ' / ' + total + ' processed (' + success + ' success, ' + errors + ' errors)';
				}

				/* Render log entries. */
				if ( logDiv && Array.isArray( d.log ) ) {
					/* Clear existing entries. */
					while ( logDiv.firstChild ) {
						logDiv.removeChild( logDiv.firstChild );
					}

					d.log.forEach( function ( entry ) {
						var el = document.createElement( 'div' );
						el.className = 'schema-ai-log-entry ' + ( entry.success ? 'schema-ai-log-ok' : 'schema-ai-log-err' );

						var icon = document.createElement( 'span' );
						icon.className = 'schema-ai-log-icon';
						icon.textContent = entry.success ? '\u2713' : '\u2717';
						el.appendChild( icon );

						var title = document.createElement( 'span' );
						title.className = 'schema-ai-log-title';
						title.textContent = entry.title || ( '#' + entry.post_id );
						el.appendChild( title );

						if ( entry.type ) {
							var typeBadge = document.createElement( 'span' );
							typeBadge.className = 'schema-ai-type-badge';
							typeBadge.textContent = entry.type;
							el.appendChild( typeBadge );
						}

						if ( entry.error ) {
							var errSpan = document.createElement( 'span' );
							errSpan.className = 'schema-ai-log-error';
							errSpan.textContent = entry.error;
							el.appendChild( errSpan );
						}

						logDiv.appendChild( el );
					} );
				}

				/* Stop on completion or cancellation. */
				if ( d.status === 'completed' || d.status === 'cancelled' ) {
					if ( bulkPollInterval ) {
						clearInterval( bulkPollInterval );
						bulkPollInterval = null;
					}
					startBtn.disabled = false;
					cancelBtn.style.display = 'none';
				}
			} );
		}

		/* --- Auto-resume if bulk is already running ------------------- */
		ajax( 'schema_ai_bulk_status' ).then( function ( res ) {
			if ( res.success && res.data && res.data.status === 'running' ) {
				startBtn.disabled = true;
				cancelBtn.style.display = '';

				if ( progressDiv ) {
					progressDiv.style.display = '';
				}
				if ( logCard ) {
					logCard.style.display = '';
				}

				bulkPollInterval = setInterval( pollStatus, 2000 );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/*  Bootstrap                                                          */
	/* ------------------------------------------------------------------ */

	function init() {
		initMetaBox();
		initBulk();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
