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

	add.addEventListener( 'click', function () {
		var row = template.content.querySelector( '[data-field-row]' );
		if ( ! row ) {
			return;
		}

		var copy = row.cloneNode( true );
		copy.querySelectorAll( '[name]' ).forEach( function ( input ) {
			input.name = input.name.replace( '__INDEX__', String( next ) );
		} );
		root.appendChild( copy );
		next += 1;
	} );

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-remove-field]' );
		if ( ! button || ! root.contains( button ) ) {
			return;
		}

		var row = button.closest( '[data-field-row]' );
		if ( row ) {
			row.remove();
		}
	} );
}() );
