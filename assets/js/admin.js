( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var selectAll = document.getElementById( 'cb-select-all-1' );
		var form = document.querySelector( 'form select[name="user_action"]' );

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				document.querySelectorAll( 'input[name="user_ids[]"]' ).forEach( function (checkbox) {
					checkbox.checked = selectAll.checked;
				} );
			} );
		}

		if ( form && form.form ) {
			form.form.addEventListener( 'submit', function (event) {
				var action = document.getElementById( 'zmai-bulk-action' );
				var messages = window.zmaiAdmin || {};
				var message = '';
				if ( action && action.value === 'delete' ) {
					message = messages.confirmDelete;
				} else if ( action && action.value === 'block' ) {
					message = messages.confirmBlock;
				}

				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		}
	} );
}() );
