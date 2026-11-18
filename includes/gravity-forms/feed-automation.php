<?php
/**
 * Sets up the feeds of a duplicated or imported Networking Profile form.
 *
 * A new Networking Profile form needs two feeds, and Gravity Forms gets both
 * slightly wrong on its own:
 *
 * - The Salesforce add-on refuses to copy its feed when a form is duplicated, and
 *   an import never carries it, so every new form needed its feed built by hand.
 *   Here the feed is copied from the form it was duplicated from, or, for an
 *   import, from the template form chosen on Settings → Networking Profile Router.
 * - Easy Passthrough does copy its feed, but the copy still takes its saved
 *   answers from the original form, so an edit link would load the wrong form's
 *   entry. Here the copy is pointed at the new form.
 *
 * Only Networking Profile forms are touched: the ones with a Salesforce Event
 * Code. A duplicate also has its Event Code and page cleared, so it can't claim
 * an event until someone has set it up.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\FeedAutomation;

// Set our aliases.
use SalesforceGravityForms\GravityForms\FormSettings;
use SalesforceGravityForms\Helpers\Utilities;
use SalesforceGravityForms\Routing\FormMap;
use SalesforceGravityForms\Routing\Settings as RouterSettings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Option holding what happened, until an administrator has seen it.
const NOTICES_OPTION = 'sfgf_feed_notices';

// Start our engines. Late, so the add-ons' own handling of the same events has run first.
add_action( 'gform_post_form_duplicated', __NAMESPACE__ . '\handle_duplicated_form', 20, 2 );
add_action( 'gform_forms_post_import', __NAMESPACE__ . '\handle_imported_forms', 20 );
add_action( 'admin_notices', __NAMESPACE__ . '\show_notices' );

/**
 * The Salesforce add-on, when it is active.
 *
 * @return \GFFeedAddOn|false
 */
function salesforce_addon() {
	return function_exists( 'gf_salesforce' ) ? gf_salesforce() : false;
}

/**
 * The Easy Passthrough add-on, when it is active.
 *
 * @return \GFFeedAddOn|false
 */
function easy_passthrough_addon() {
	return function_exists( 'gp_easy_passthrough' ) ? gp_easy_passthrough() : false;
}

/**
 * A form has just been duplicated.
 *
 * @param int $source_id The form that was copied.
 * @param int $new_id    The copy.
 * @return void
 */
function handle_duplicated_form( $source_id, $new_id ) {

	$source_id = (int) $source_id;
	$new_id    = (int) $new_id;

	// Only a Networking Profile form (one with an Event Code) is managed here.
	$source = \GFAPI::get_form( $source_id );
	if ( ! is_array( $source ) || '' === trim( (string) rgar( $source, FormSettings\EVENT_CODE_PROPERTY ) ) ) {
		return;
	}

	$notes = [];

	// The Salesforce feed, which the add-on won't copy.
	if ( has_salesforce_feed( $source_id ) ) {
		$copied = copy_salesforce_feeds( $source_id, $new_id );
		if ( is_wp_error( $copied ) ) {
			/* translators: %s: why the feed couldn't be copied. */
			$notes[] = sprintf( __( 'The Salesforce feed was not copied: %s', 'salesforce-gravity-forms' ), $copied->get_error_message() );
		} else {
			$notes[] = __( 'The Salesforce feed was copied.', 'salesforce-gravity-forms' );
		}
	}

	// The Easy Passthrough feed, which was copied but still points at the original.
	if ( repoint_easy_passthrough( $new_id, [ $source_id ] ) ) {
		$notes[] = __( 'Easy Passthrough now takes its saved answers from the new form.', 'salesforce-gravity-forms' );
	}

	// The copy must not claim the original's event until it has been set up.
	if ( clear_form_settings( $new_id ) ) {
		$notes[] = __( 'Its Salesforce Event Code and page were cleared: set both in Form Settings before it goes live.', 'salesforce-gravity-forms' );
	}

	/* translators: 1: the original form's title, 2: the new form's ID, 3: what was done. */
	add_notice( sprintf( __( 'Duplicated "%1$s" as form %2$d. %3$s', 'salesforce-gravity-forms' ), (string) rgar( $source, 'title' ), $new_id, implode( ' ', $notes ) ) );
	Utilities\log( 'debug', 'Set up the feeds of a duplicated form.', [ 'source_form' => $source_id, 'new_form' => $new_id ] );
}

/**
 * Forms have just been imported.
 *
 * @param array $forms The forms that were added.
 * @return void
 */
function handle_imported_forms( $forms ) {

	foreach ( (array) $forms as $form ) {

		// Only a Networking Profile form (one with an Event Code) is managed here.
		if ( ! is_array( $form ) || empty( $form['id'] ) || '' === trim( (string) rgar( $form, FormSettings\EVENT_CODE_PROPERTY ) ) ) {
			continue;
		}

		$form_id = (int) $form['id'];
		$notes   = [];

		// The Salesforce feed, copied from the template form.
		if ( ! has_salesforce_feed( $form_id ) ) {
			$template_id = RouterSettings\get_template_form_id();

			if ( ! $template_id || $template_id === $form_id ) {
				$notes[] = __( 'No template form is chosen on Settings → Networking Profile Router, so its Salesforce feed was not created.', 'salesforce-gravity-forms' );
			} else {
				$copied = copy_salesforce_feeds( $template_id, $form_id );
				if ( is_wp_error( $copied ) ) {
					/* translators: %s: why the feed couldn't be copied. */
					$notes[] = sprintf( __( 'The Salesforce feed was not copied: %s', 'salesforce-gravity-forms' ), $copied->get_error_message() );
				} else {
					/* translators: %d: the template form's ID. */
					$notes[] = sprintf( __( 'The Salesforce feed was copied from form %d.', 'salesforce-gravity-forms' ), $template_id );
				}
			}
		}

		// The Easy Passthrough feed: one that takes its answers from another Networking Profile form should take them from this one.
		$other_ids = array_map( 'intval', array_diff( array_keys( FormMap\get_routed_forms( false ) ), [ $form_id ] ) );
		if ( repoint_easy_passthrough( $form_id, $other_ids ) ) {
			$notes[] = __( 'Easy Passthrough now takes its saved answers from this form.', 'salesforce-gravity-forms' );
		}

		// Say what was done, if anything.
		if ( $notes ) {
			/* translators: 1: the form's title, 2: the form's ID, 3: what was done. */
			add_notice( sprintf( __( 'Imported "%1$s" as form %2$d. %3$s', 'salesforce-gravity-forms' ), (string) rgar( $form, 'title' ), $form_id, implode( ' ', $notes ) ) );
		}
	}
}

/**
 * Whether a form has a Salesforce feed.
 *
 * @param int $form_id A form's ID.
 * @return bool
 */
function has_salesforce_feed( $form_id ) {
	$addon = salesforce_addon();
	return $addon && ! empty( $addon->get_feeds( (int) $form_id ) );
}

/**
 * Copies a form's Salesforce feeds onto another form. It never replaces: a form that
 * already has a Salesforce feed is left alone. The copy is refused if the feed maps a
 * field the other form doesn't have, because it would silently send nothing for it.
 *
 * @param int $from_id The form to copy from.
 * @param int $to_id   The form to copy to.
 * @return int|\WP_Error How many feeds were copied.
 */
function copy_salesforce_feeds( $from_id, $to_id ) {

	$from_id = (int) $from_id;
	$to_id   = (int) $to_id;

	// The Salesforce add-on has to be there.
	$addon = salesforce_addon();
	if ( ! $addon ) {
		return new \WP_Error( 'sfgf_no_salesforce_addon', __( 'The Gravity Forms Salesforce add-on is not active.', 'salesforce-gravity-forms' ) );
	}

	// Never copy a form onto itself.
	if ( $from_id === $to_id ) {
		return new \WP_Error( 'sfgf_same_form', __( 'Choose two different forms.', 'salesforce-gravity-forms' ) );
	}

	// There has to be something to copy.
	$feeds = $addon->get_feeds( $from_id );
	if ( empty( $feeds ) ) {
		return new \WP_Error( 'sfgf_no_source_feed', __( 'The form to copy from has no Salesforce feed.', 'salesforce-gravity-forms' ) );
	}

	// Never add a second feed to a form that already has one.
	if ( ! empty( $addon->get_feeds( $to_id ) ) ) {
		return new \WP_Error( 'sfgf_target_has_feed', __( 'That form already has a Salesforce feed, so nothing was copied.', 'salesforce-gravity-forms' ) );
	}

	// The target form has to exist.
	$target = \GFAPI::get_form( $to_id );
	if ( ! is_array( $target ) ) {
		return new \WP_Error( 'sfgf_no_target_form', __( 'The form to copy to does not exist.', 'salesforce-gravity-forms' ) );
	}

	// Every field the feed uses has to be on the target form.
	foreach ( $feeds as $feed ) {
		$missing = find_missing_fields( (array) $feed['meta'], $target );
		if ( $missing ) {
			return new \WP_Error(
				'sfgf_fields_missing',
				sprintf(
					/* translators: %s: comma-separated field IDs. */
					__( 'The feed uses fields the other form doesn\'t have (field IDs %s), so nothing was copied.', 'salesforce-gravity-forms' ),
					implode( ', ', $missing )
				)
			);
		}
	}

	// Copy each feed, keeping whether it is switched on.
	$copied = 0;
	foreach ( $feeds as $feed ) {
		if ( $addon->insert_feed( $to_id, (int) $feed['is_active'], $feed['meta'] ) ) {
			$copied++;
		}
	}

	if ( 0 === $copied ) {
		return new \WP_Error( 'sfgf_copy_failed', __( 'The feed could not be saved.', 'salesforce-gravity-forms' ) );
	}

	Utilities\log( 'debug', 'Copied a Salesforce feed.', [ 'from_form' => $from_id, 'to_form' => $to_id, 'feeds' => $copied ] );
	return $copied;
}

/**
 * The form fields a feed uses that a form doesn't have.
 *
 * @param array $meta The feed's settings.
 * @param array $form The form to check against.
 * @return string[] The missing field IDs.
 */
function find_missing_fields( array $meta, array $form ) {

	$wanted = [];

	// The fields each Salesforce field is mapped from.
	foreach ( $meta as $name => $value ) {
		if ( 0 === strpos( (string) $name, 'fieldMapping_' ) && is_scalar( $value ) ) {
			$wanted[] = (string) $value;
		}
	}

	// The fields the feed's condition looks at.
	$condition = $meta['feed_condition_conditional_logic_object'] ?? [];
	array_walk_recursive(
		$condition,
		function ( $value, $name ) use ( &$wanted ) {
			if ( 'fieldId' === $name && is_scalar( $value ) ) {
				$wanted[] = (string) $value;
			}
		}
	);

	// Check each one. Anything that isn't a number (or an input like 39.3) is custom text typed into the mapping, which copies as it is.
	$missing = [];
	foreach ( array_unique( $wanted ) as $field_id ) {
		if ( 1 === preg_match( '/^\d+(\.\d+)?$/', $field_id ) && ! \GFAPI::get_field( $form, $field_id ) ) {
			$missing[] = $field_id;
		}
	}

	return $missing;
}

/**
 * Points a form's Easy Passthrough feeds at the form itself, when they take their
 * answers from one of the given forms.
 *
 * @param int   $form_id         The form whose feeds to check.
 * @param int[] $stale_source_ids Source forms that are wrong for this form (the original it was copied from).
 * @return int How many feeds were changed.
 */
function repoint_easy_passthrough( $form_id, array $stale_source_ids ) {

	$form_id = (int) $form_id;

	// Easy Passthrough has to be there.
	$addon = easy_passthrough_addon();
	if ( ! $addon ) {
		return 0;
	}

	$changed = 0;
	foreach ( $addon->get_feeds( $form_id ) as $feed ) {

		// Only a feed taking its answers from one of the stale forms.
		$source = (int) ( $feed['meta']['sourceForm'] ?? 0 );
		if ( $source === $form_id || ! in_array( $source, $stale_source_ids, true ) ) {
			continue;
		}

		// Everything else about the feed stays.
		$meta                = $feed['meta'];
		$meta['sourceForm']  = (string) $form_id;
		if ( $addon->update_feed_meta( $feed['id'], $meta ) ) {
			$changed++;
		}
	}

	return $changed;
}

/**
 * What a form's feeds look like, for the admin screen.
 *
 * @param int $form_id A form's ID.
 * @return array{salesforce: int, passthrough: array<int, array{feed_id: int, source: int}>}
 */
function describe_feeds( $form_id ) {

	$salesforce = salesforce_addon();
	$passthru   = easy_passthrough_addon();

	$summary = [
		'salesforce'  => $salesforce ? count( $salesforce->get_feeds( (int) $form_id ) ) : 0,
		'passthrough' => [],
	];

	// Where each Easy Passthrough feed takes its answers from.
	if ( $passthru ) {
		foreach ( $passthru->get_feeds( (int) $form_id ) as $feed ) {
			$summary['passthrough'][] = [
				'feed_id' => (int) $feed['id'],
				'source'  => (int) ( $feed['meta']['sourceForm'] ?? 0 ),
			];
		}
	}

	return $summary;
}

/**
 * Removes a form's Event Code and page, so a copy can't claim an event yet.
 *
 * @param int $form_id A form's ID.
 * @return bool True when the form was saved without them.
 */
function clear_form_settings( $form_id ) {

	$form = \GFAPI::get_form( (int) $form_id );
	if ( ! is_array( $form ) ) {
		return false;
	}

	unset( $form[ FormSettings\EVENT_CODE_PROPERTY ], $form[ FormSettings\PAGE_ID_PROPERTY ] );

	return ! is_wp_error( \GFAPI::update_form( $form, (int) $form_id ) );
}

/**
 * Keeps a note of what was done, to show the next time an administrator opens wp-admin.
 *
 * @param string $message Plain text.
 * @return void
 */
function add_notice( $message ) {

	$notices   = get_option( NOTICES_OPTION, [] );
	$notices   = is_array( $notices ) ? $notices : [];
	$notices[] = (string) $message;

	// Keep only the latest few.
	update_option( NOTICES_OPTION, array_slice( $notices, -10 ), false );
}

/**
 * Shows the notes once, then forgets them.
 *
 * @return void
 */
function show_notices() {

	// Only administrators need to know.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notices = get_option( NOTICES_OPTION, [] );
	if ( empty( $notices ) || ! is_array( $notices ) ) {
		return;
	}

	delete_option( NOTICES_OPTION );

	foreach ( $notices as $notice ) {
		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
	}
}
