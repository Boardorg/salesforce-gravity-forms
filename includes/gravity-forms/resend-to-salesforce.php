<?php
/**
 * "Resend to Salesforce" bulk action for the Gravity Forms entry list.
 *
 * The Salesforce add-on sends an entry once, when it's submitted. If Salesforce
 * rejects it (a value too long for its field, a key that matches no record, an
 * outage) the add-on records the failure and never tries again, and Gravity
 * Forms offers no way to ask. This adds a bulk action that queues the add-on's
 * feed again for the selected entries. The add-on then sends them in the
 * background exactly as it does on submission, so the result shows up in each
 * entry's notes.
 *
 * It refuses entries where resending would do harm:
 *
 * - Entries the add-on has already sent successfully.
 * - Entries with no usable Networking Profile Key, which Salesforce couldn't
 *   match to an attendee.
 * - Entries that have a newer entry for the same attendee, because resending
 *   an old entry would overwrite the newer answers in Salesforce.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\GravityForms\ResendToSalesforce;

// Set our aliases.
use SalesforceGravityForms\GravityForms\KeySanitizer;
use SalesforceGravityForms\Helpers\Utilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// The bulk action's slug on the entry list.
const BULK_ACTION = 'sfgf_resend_to_salesforce';

// The permission Gravity Forms' own entry-editing actions need.
const CAPABILITY = 'gravityforms_edit_entries';

// The most entries one resend will queue, so "select all" can't flood the Salesforce API.
const MAX_ENTRIES = 100;

// How many entries to look at when checking for a newer entry for the same attendee.
const NEWER_ENTRY_LOOKUP_SIZE = 25;

// Start our engines.
add_filter( 'gform_entry_list_bulk_actions', __NAMESPACE__ . '\add_bulk_action', 10, 2 );
add_action( 'gform_entry_list_action', __NAMESPACE__ . '\handle_bulk_action', 10, 3 );

/**
 * Adds "Resend to Salesforce" to the entry list's bulk actions menu.
 *
 * @param array $actions Action slug => label pairs.
 * @param int   $form_id The ID of the form whose entries are listed.
 * @return array The filtered actions.
 */
function add_bulk_action( $actions, $form_id ) {

	// The Spam and Trash views have their own, unrelated actions.
	if ( in_array( rgget( 'filter' ), [ 'spam', 'trash' ], true ) ) {
		return $actions;
	}

	// Same permission as Gravity Forms' own edit actions.
	if ( ! \GFCommon::current_user_can_any( CAPABILITY ) ) {
		return $actions;
	}

	// Only on forms the Salesforce add-on actually sends.
	if ( empty( get_active_feeds( $form_id ) ) ) {
		return $actions;
	}

	// Add the action to the menu.
	$actions[ BULK_ACTION ] = __( 'Resend to Salesforce', 'salesforce-gravity-forms' );

	return $actions;
}

/**
 * Runs the resend when the bulk action is applied, then shows the result.
 *
 * Gravity Forms checks the nonce before it fires this.
 *
 * @param string $action  The bulk action being applied.
 * @param array  $entries The IDs of the selected entries.
 * @param int    $form_id The ID of the form whose entries are listed.
 * @return void
 */
function handle_bulk_action( $action, $entries, $form_id ) {

	// Only our own action.
	if ( BULK_ACTION !== $action ) {
		return;
	}

	// The nonce is already checked, but the permission is ours to check.
	if ( ! \GFCommon::current_user_can_any( CAPABILITY ) ) {
		print_notice( 'error', [ __( "You don't have adequate permission to edit entries.", 'salesforce-gravity-forms' ) ] );
		return;
	}

	// Clean the selection down to entry IDs.
	$entry_ids = array_values( array_filter( array_map( 'absint', (array) $entries ) ) );

	// Nothing selected, nothing to do.
	if ( empty( $entry_ids ) ) {
		print_notice( 'error', [ __( 'Select at least one entry to resend.', 'salesforce-gravity-forms' ) ] );
		return;
	}

	// Refuse a selection too big to send safely in one go.
	if ( count( $entry_ids ) > MAX_ENTRIES ) {
		print_notice(
			'error',
			[
				sprintf(
					/* translators: %d: the most entries that can be resent at once. */
					__( 'Select %d entries or fewer to resend at once.', 'salesforce-gravity-forms' ),
					MAX_ENTRIES
				),
			]
		);
		return;
	}

	// Get what the resend needs, or explain that it's missing.
	$addon = get_addon();
	$form  = \GFAPI::get_form( $form_id );
	$feeds = get_active_feeds( $form_id );
	if ( null === $addon || ! is_array( $form ) || empty( $feeds ) ) {
		print_notice( 'error', [ __( 'The Salesforce add-on has no active feed for this form, so there is nothing to resend to.', 'salesforce-gravity-forms' ) ] );
		return;
	}

	// Resend, then tell the user what happened.
	print_result( resend_entries( $addon, $form, $feeds, $entry_ids ) );
}

/**
 * Queues the Salesforce feed again for each entry that's safe to resend.
 *
 * @param \GFFeedAddOn $addon     The Salesforce add-on.
 * @param array        $form      Form Object.
 * @param array        $feeds     The form's active Salesforce feeds.
 * @param int[]        $entry_ids The selected entry IDs.
 * @return array { queued: int[] entry IDs queued, skipped: array entry ID => reason }
 */
function resend_entries( $addon, $form, $feeds, $entry_ids ) {

	$queued    = [];
	$skipped   = [];
	$key_field = find_key_field( $form );

	// Go through the selection one entry at a time.
	foreach ( $entry_ids as $entry_id ) {

		// Load the entry, then skip anything that wouldn't help or could do harm.
		$entry  = \GFAPI::get_entry( $entry_id );
		$reason = is_wp_error( $entry )
			? __( 'it could not be found', 'salesforce-gravity-forms' )
			: get_skip_reason( $addon, $form, $feeds, $entry, $key_field );
		if ( null !== $reason ) {
			$skipped[ $entry_id ] = $reason;
			Utilities\log(
				'debug',
				'Resend skipped an entry.',
				[
					'entry_id' => $entry_id,
					'form_id'  => $form['id'],
					'reason'   => $reason,
				]
			);
			continue;
		}

		// Queue the feed again. The add-on sends it in the background, like it does on submission.
		$addon->maybe_process_feed( $entry, $form );
		add_resend_note( $entry_id );
		Utilities\log(
			'debug',
			'Resend queued an entry.',
			[
				'entry_id' => $entry_id,
				'form_id'  => $form['id'],
			]
		);
		$queued[] = $entry_id;
	}

	return [
		'queued'  => $queued,
		'skipped' => $skipped,
	];
}

/**
 * Says why an entry shouldn't be resent, or returns null when it's safe to.
 *
 * @param \GFFeedAddOn   $addon     The Salesforce add-on.
 * @param array          $form      Form Object.
 * @param array          $feeds     The form's active Salesforce feeds.
 * @param array          $entry     Entry Object.
 * @param \GF_Field|null $key_field The form's Networking Profile Key field, if it has one.
 * @return string|null The reason to skip it, or null to go ahead.
 */
function get_skip_reason( $addon, $form, $feeds, $entry, $key_field ) {

	// Spam and trashed entries aren't sent.
	if ( 'active' !== rgar( $entry, 'status' ) ) {
		return __( 'it is in the spam or trash', 'salesforce-gravity-forms' );
	}

	// Nothing to send when every feed already succeeded for this entry, or its conditions don't apply.
	$pending = array_filter(
		$feeds,
		static function ( $feed ) use ( $addon, $form, $entry ) {
			return $addon->can_process_feed( $feed, $entry, $form ) && $addon->is_feed_condition_met( $feed, $form, $entry );
		}
	);
	if ( empty( $pending ) ) {
		return __( 'it was already sent to Salesforce', 'salesforce-gravity-forms' );
	}

	// Without a key field on the form, there's nothing more to check.
	if ( null === $key_field ) {
		return null;
	}

	// Salesforce matches the attendee by key, so the entry needs a usable one.
	$key = KeySanitizer\extract_key( trim( (string) rgar( $entry, (string) $key_field->id ) ) );
	if ( 1 !== preg_match( KeySanitizer\KEY_PATTERN, (string) $key ) ) {
		return __( 'it has no usable Networking Profile Key, so Salesforce could not match an attendee', 'salesforce-gravity-forms' );
	}

	// An older entry would overwrite a newer one for the same attendee in Salesforce.
	$newer_id = find_newer_entry_id( $form['id'], $entry, $key_field->id, $key );
	if ( is_wp_error( $newer_id ) ) {
		return sprintf(
			/* translators: %s: the error message from Gravity Forms. */
			__( 'the check for a newer entry failed (%s)', 'salesforce-gravity-forms' ),
			$newer_id->get_error_message()
		);
	}
	if ( $newer_id > 0 ) {
		return sprintf(
			/* translators: %d: the ID of the newer entry. */
			__( 'a newer entry (#%d) exists for this attendee, and resending this one would overwrite its answers', 'salesforce-gravity-forms' ),
			$newer_id
		);
	}

	// Safe to resend.
	return null;
}

/**
 * Finds the newest entry for the same attendee that is newer than this one.
 *
 * Matches the key with "contains" so an entry saved with a stray character on
 * its key still counts as the same attendee.
 *
 * @param int    $form_id      Gravity Forms form ID.
 * @param array  $entry        The entry being checked.
 * @param int    $key_field_id The ID of the key field.
 * @param string $key          The attendee's clean key.
 * @return int|\WP_Error The newer entry's ID, 0 when this is the newest, or the error if the lookup failed.
 */
function find_newer_entry_id( $form_id, $entry, $key_field_id, $key ) {

	// Newest first, so the first newer entry is the newest one.
	$entries = \GFAPI::get_entries(
		$form_id,
		[
			'status'        => 'active',
			'field_filters' => [
				[
					'key'      => (string) $key_field_id,
					'operator' => 'contains',
					'value'    => $key,
				],
			],
		],
		[
			'key'       => 'date_created',
			'direction' => 'DESC',
		],
		[
			'offset'    => 0,
			'page_size' => NEWER_ENTRY_LOOKUP_SIZE,
		]
	);
	if ( is_wp_error( $entries ) ) {
		return $entries;
	}

	// Look for an entry created after this one (or at the same moment with a higher ID).
	foreach ( $entries as $other ) {

		// Skip the entry itself.
		if ( (int) $other['id'] === (int) $entry['id'] ) {
			continue;
		}

		// Dates are 'Y-m-d H:i:s' in UTC, so a string comparison orders them.
		$order = strcmp( (string) $other['date_created'], (string) $entry['date_created'] );
		if ( $order > 0 || ( 0 === $order && (int) $other['id'] > (int) $entry['id'] ) ) {
			return (int) $other['id'];
		}
	}

	// Nothing newer, so this is the attendee's latest entry.
	return 0;
}

/**
 * Finds the form's Networking Profile Key field, identified by its parameter name.
 *
 * @param array $form Form Object.
 * @return \GF_Field|null The key field, or null when the form doesn't have one.
 */
function find_key_field( $form ) {

	// Look through the fields for the one populated from the key parameter.
	foreach ( $form['fields'] as $field ) {
		if ( KeySanitizer\KEY_PARAMETER_NAME === rgar( $field, 'inputName' ) ) {
			return $field;
		}
	}

	// This form has no key field.
	return null;
}

/**
 * Returns the Salesforce add-on's instance.
 *
 * @return \GFFeedAddOn|null The add-on, or null when it isn't active.
 */
function get_addon() {

	// The add-on's own accessor only exists when the add-on is active.
	if ( ! function_exists( 'gf_salesforce' ) ) {
		return null;
	}

	// Make sure it's what we expect before calling its methods.
	$addon = gf_salesforce();
	return $addon instanceof \GFFeedAddOn ? $addon : null;
}

/**
 * Returns a form's active Salesforce feeds.
 *
 * @param int $form_id Gravity Forms form ID.
 * @return array The active feeds, empty when there are none or the add-on isn't active.
 */
function get_active_feeds( $form_id ) {

	// No add-on, no feeds.
	$addon = get_addon();
	if ( null === $addon ) {
		return [];
	}

	// Keep only the feeds that are switched on.
	$feeds = array_filter(
		(array) $addon->get_feeds( $form_id ),
		static function ( $feed ) {
			return ! empty( $feed['is_active'] );
		}
	);

	return array_values( $feeds );
}

/**
 * Leaves a note on the entry so its history shows who asked for the resend.
 *
 * @param int $entry_id Entry ID.
 * @return void
 */
function add_resend_note( $entry_id ) {

	// Attribute the note to whoever applied the bulk action.
	$user = wp_get_current_user();
	\GFAPI::add_note( $entry_id, $user->ID, $user->display_name, __( 'Queued to resend to Salesforce.', 'salesforce-gravity-forms' ) );
}

/**
 * Shows what a resend did: how many entries were queued, and why any were skipped.
 *
 * @param array $result The result from resend_entries().
 * @return void
 */
function print_result( $result ) {

	$queued_count = count( $result['queued'] );
	$lines        = [];

	// Lead with what was queued.
	if ( $queued_count > 0 ) {
		$lines[] = sprintf(
			/* translators: %d: the number of entries queued. */
			_n(
				'%d entry queued for Salesforce. Its notes will show the result within a minute.',
				'%d entries queued for Salesforce. Each entry\'s notes will show the result within a minute.',
				$queued_count,
				'salesforce-gravity-forms'
			),
			$queued_count
		);
	} else {
		$lines[] = __( 'Nothing was queued for Salesforce.', 'salesforce-gravity-forms' );
	}

	// Then say why each skipped entry was skipped.
	foreach ( $result['skipped'] as $entry_id => $reason ) {
		$lines[] = sprintf(
			/* translators: 1: the entry ID, 2: why it was skipped. */
			__( 'Entry #%1$d was skipped: %2$s.', 'salesforce-gravity-forms' ),
			$entry_id,
			$reason
		);
	}

	// Green when something was queued, red when nothing was.
	print_notice( $queued_count > 0 ? 'success' : 'error', $lines );
}

/**
 * Prints a message in the same box Gravity Forms uses above the entry list.
 *
 * @param string   $type  'success' or 'error'.
 * @param string[] $lines One paragraph per line.
 * @return void
 */
function print_notice( $type, $lines ) {

	// Open the box.
	echo '<div id="message" class="alert ' . esc_attr( $type ) . '">';

	// One escaped paragraph per line.
	foreach ( $lines as $line ) {
		echo '<p>' . esc_html( $line ) . '</p>';
	}

	// Close the box.
	echo '</div>';
}
