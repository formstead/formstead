( function () {
	var root = document.getElementById( 'formstead-fields' );
	var add = document.getElementById( 'formstead-add-field' );
	var template = document.getElementById( 'formstead-field-template' );

	if ( ! root || ! add || ! template || ! template.content ) {
		return;
	}

	var next = parseInt( root.getAttribute( 'data-next-index' ) || '0', 10 );
	if ( Number.isNaN( next ) ) {
		next = root.querySelectorAll( '[data-field-row]' ).length;
	}

	function showSettings( row ) {
		var select = row.querySelector( '[data-field-type]' );
		var type = select ? select.value : '';
		row.querySelectorAll( '[data-settings-for]' ).forEach( function ( block ) {
			var types = ( block.getAttribute( 'data-settings-for' ) || '' ).split( ' ' );
			block.hidden = types.indexOf( type ) === -1;
		} );
	}

	function enhance( row ) {
		var button = row.querySelector( '[data-remove-field]' );
		var fallback = row.querySelector( '[data-remove-fallback]' );
		if ( button ) {
			button.hidden = false;
		}
		if ( fallback ) {
			fallback.hidden = true;
		}
		showSettings( row );
	}

	function refreshReplyTo() {
		var select = document.getElementById( 'formstead-reply-to' );
		if ( ! select ) {
			return;
		}

		var current = select.value;
		var none = select.querySelector( 'option[value=""]' );
		var noneLabel = none ? none.textContent : '';
		select.replaceChildren();

		var empty = document.createElement( 'option' );
		empty.value = '';
		empty.textContent = noneLabel;
		select.appendChild( empty );

		root.querySelectorAll( '[data-field-row]' ).forEach( function ( row ) {
			var type = row.querySelector( '[data-field-type]' );
			var name = row.querySelector( '[name$="[name]"]' );
			var label = row.querySelector( '[name$="[label]"]' );
			if ( ! type || type.value !== 'email' || ! name ) {
				return;
			}

			var value = name.value.trim();
			if ( ! /^[a-z][a-z0-9_]{0,63}$/.test( value ) ) {
				return;
			}

			var option = document.createElement( 'option' );
			option.value = value;
			option.textContent = label && label.value.trim() ? label.value.trim() : value;
			if ( value === current ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );
	}

	root.querySelectorAll( '[data-field-row]' ).forEach( enhance );
	refreshReplyTo();

	add.addEventListener( 'click', function () {
		var row = template.content.querySelector( '[data-field-row]' );
		if ( ! row ) {
			return;
		}

		var copy = row.cloneNode( true );
		copy.querySelectorAll( '[name]' ).forEach( function ( input ) {
			input.name = input.name.replace( '__INDEX__', String( next ) );
		} );
		enhance( copy );
		root.appendChild( copy );
		next += 1;
		refreshReplyTo();
	} );

	root.addEventListener( 'change', function ( event ) {
		var select = event.target.closest( '[data-field-type]' );
		if ( ! select || ! root.contains( select ) ) {
			return;
		}

		var row = select.closest( '[data-field-row]' );
		if ( row ) {
			showSettings( row );
		}
		refreshReplyTo();
	} );

	root.addEventListener( 'input', function ( event ) {
		var field = event.target.closest( '[name$="[name]"], [name$="[label]"]' );
		if ( ! field || ! root.contains( field ) ) {
			return;
		}

		refreshReplyTo();
	} );

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-remove-field]' );
		if ( ! button || ! root.contains( button ) ) {
			return;
		}

		var row = button.closest( '[data-field-row]' );
		if ( row ) {
			row.remove();
			refreshReplyTo();
		}
	} );
}() );
