( function () {
	var editor = document.getElementById( 'formstead-form-editor' );
	var code = document.getElementById( 'formstead-integration-html' );
	var copy = document.getElementById( 'formstead-copy-code' );
	var controls = document.getElementById( 'formstead-copy-controls' );
	var status = document.getElementById( 'formstead-copy-status' );
	var unsaved = document.getElementById( 'formstead-integration-unsaved' );
	var copying = false;
	var platform = document.getElementById( 'formstead-integration-platform' );
	var platformControls = document.getElementById( 'formstead-platform-controls' );

	if ( ! editor || ! code || ! copy || ! controls || ! status || ! unsaved ) {
		return;
	}

	function markUnsaved() {
		unsaved.hidden = false;
	}

	editor.addEventListener( 'input', markUnsaved );
	editor.addEventListener( 'change', markUnsaved );
	editor.addEventListener( 'click', function ( event ) {
		if ( event.target instanceof Element && event.target.closest( '#formstead-add-field, [data-remove-field]' ) ) {
			markUnsaved();
		}
	} );

	function selectCode() {
		code.focus();
		code.select();
		status.textContent = copy.getAttribute( 'data-copy-fallback' );
	}

	if ( platform && platformControls ) {
		function showVariant() {
			document.querySelectorAll( '[data-integration-panel]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-integration-panel' ) !== platform.value;
			} );
			code = document.getElementById( 'formstead-integration-' + platform.value );
			status.textContent = '';
		}
		platform.addEventListener( 'change', showVariant );
		showVariant();
		platformControls.hidden = false;
	}

	copy.addEventListener( 'click', async function () {
		if ( copying ) {
			return;
		}
		copying = true;
		if ( platform ) {
			platform.disabled = true;
		}
		copy.setAttribute( 'aria-busy', 'true' );
		status.textContent = '';
		try {
			if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
				await navigator.clipboard.writeText( code.value );
				status.textContent = copy.getAttribute( 'data-copy-success' );
			} else {
				selectCode();
			}
		} catch {
			selectCode();
		} finally {
			copying = false;
			if ( platform ) {
				platform.disabled = false;
			}
			copy.removeAttribute( 'aria-busy' );
		}
	} );
	controls.hidden = false;
}() );
