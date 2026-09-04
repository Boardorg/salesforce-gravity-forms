/**
 * Shows the Dynamic Choice Source setting only for Checkbox fields, and
 * keeps its dropdown in sync with whichever field is selected in the
 * Gravity Forms form editor.
 */
( function ( $ ) {
	'use strict';

	// Show this setting only for Checkbox fields.
	if ( fieldSettings.checkbox ) {
		fieldSettings.checkbox += ', .sfgf_choice_source_setting';
	}

	// Populate the dropdown whenever a field is selected.
	$( document ).on( 'gform_load_field_settings', function ( event, field ) {
		$( '#sfgf_choice_source' ).val( field[ sfgfFieldSettings.choiceSourceProperty ] || '' );
	} );
} )( jQuery );
