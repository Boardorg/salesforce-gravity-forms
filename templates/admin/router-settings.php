<?php
/**
 * Settings → NP Router admin page.
 *
 * @package SalesforceGravityForms
 *
 * @var string     $settings_group   Settings API group name.
 * @var bool       $enabled          Whether the router is switched on.
 * @var int        $router_page_id   The router page's ID (0 when none is chosen).
 * @var string     $router_page_url  The router page's address ('' when none is chosen).
 * @var array      $page_choices     Published pages: page ID => label.
 * @var int        $template_form_id The form new forms copy their Salesforce feed from (0 when none).
 * @var array      $routed_forms     Every Networking Profile form, by form ID.
 * @var string     $overrides_text   The overrides box.
 * @var array      $messages         Notice type => [ label, option, value, default ].
 * @var array      $options          Option names: enabled, page, template, overrides.
 * @var array      $rows             The status table rows.
 * @var array      $copy_sources     Forms whose Salesforce feed can be copied.
 * @var array      $copy_targets     Forms with no Salesforce feed yet.
 * @var string     $test_input       What was pasted into the link check.
 * @var array|null $test_result      The link check's result, or null when it hasn't been used.
 * @var array      $actions          Tool name => [ nonce action, nonce field name ].
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<p>
		<?php esc_html_e( 'Every Networking Profile link in a Cvent email points at one page. When this is switched on, each attendee is sent to the form for their event: the key in their link names their registration, Salesforce says which event that is, and the form whose Salesforce Event Code matches that event is shown. An event with no form, a link that isn\'t recognised, or Salesforce being out of reach shows a short notice and never another event\'s form.', 'salesforce-gravity-forms' ); ?>
	</p>

	<p>
		<strong><?php esc_html_e( 'Status:', 'salesforce-gravity-forms' ); ?></strong>
		<?php if ( $enabled && $router_page_id ) : ?>
			<span style="color:#00a32a;font-weight:600;"><?php esc_html_e( 'ON', 'salesforce-gravity-forms' ); ?></span>
			<?php esc_html_e( 'for', 'salesforce-gravity-forms' ); ?>
			<a href="<?php echo esc_url( $router_page_url ); ?>"><?php echo esc_html( get_the_title( $router_page_id ) ); ?></a>
			<?php esc_html_e( 'and the pages the forms are shown on.', 'salesforce-gravity-forms' ); ?>
		<?php elseif ( $enabled ) : ?>
			<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'ON, but no router page is chosen, so nothing is routed.', 'salesforce-gravity-forms' ); ?></span>
		<?php else : ?>
			<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'OFF', 'salesforce-gravity-forms' ); ?></span>
			<?php esc_html_e( '(links behave as they always did). Check the forms table below, test a link, then switch it on.', 'salesforce-gravity-forms' ); ?>
		<?php endif; ?>
	</p>

	<form action="options.php" method="post">
		<?php settings_fields( $settings_group ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Router', 'salesforce-gravity-forms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $options['enabled'] ); ?>" value="1" <?php checked( $enabled ); ?>>
						<?php esc_html_e( 'Send each Networking Profile link to the form for the attendee\'s event', 'salesforce-gravity-forms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'After switching it on, clear the page cache at WP Engine once, so no one is served an older copy of a link they opened before.', 'salesforce-gravity-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sfgf-router-page"><?php esc_html_e( 'Router page', 'salesforce-gravity-forms' ); ?></label></th>
				<td>
					<select id="sfgf-router-page" name="<?php echo esc_attr( $options['page'] ); ?>">
						<option value="0"><?php esc_html_e( '— Choose the page the Cvent links point at —', 'salesforce-gravity-forms' ); ?></option>
						<?php foreach ( $page_choices as $page_id => $label ) : ?>
							<option value="<?php echo esc_attr( $page_id ); ?>" <?php selected( $router_page_id, $page_id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The page every link points at (for the NALES form that is /networking-profile/).', 'salesforce-gravity-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sfgf-router-template"><?php esc_html_e( 'Template form', 'salesforce-gravity-forms' ); ?></label></th>
				<td>
					<select id="sfgf-router-template" name="<?php echo esc_attr( $options['template'] ); ?>">
						<option value="0"><?php esc_html_e( '— None —', 'salesforce-gravity-forms' ); ?></option>
						<?php foreach ( $routed_forms as $form ) : ?>
							<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $template_form_id, $form['id'] ); ?>><?php echo esc_html( sprintf( '%s (%d, %s)', $form['title'], $form['id'], $form['code'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'When a Networking Profile form is imported, its Salesforce feed is copied from this form. (A duplicated form copies from the form it was duplicated from.)', 'salesforce-gravity-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sfgf-router-overrides"><?php esc_html_e( 'Overrides', 'salesforce-gravity-forms' ); ?></label></th>
				<td>
					<textarea id="sfgf-router-overrides" name="<?php echo esc_attr( $options['overrides'] ); ?>" rows="4" class="large-text code" placeholder="TEST = 1&#10;A-NP-0000000041 = 2"><?php echo esc_textarea( $overrides_text ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line: an Event Code or a single key, an equals sign, and a form ID. Use it so test attendees (the TEST event has no form of its own) reach a form. A key is checked first, then the event. Lines starting with # are ignored.', 'salesforce-gravity-forms' ); ?></p>
				</td>
			</tr>
			<?php foreach ( $messages as $message ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $message['option'] ); ?>"><?php echo esc_html( $message['label'] ); ?></label></th>
					<td>
						<textarea id="<?php echo esc_attr( $message['option'] ); ?>" name="<?php echo esc_attr( $message['option'] ); ?>" rows="3" class="large-text" placeholder="<?php echo esc_attr( $message['default'] ); ?>"><?php echo esc_textarea( $message['value'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'What the visitor sees. Leave blank for the wording shown in grey. Links and bold are allowed.', 'salesforce-gravity-forms' ); ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button( __( 'Save Settings', 'salesforce-gravity-forms' ) ); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Forms and pages', 'salesforce-gravity-forms' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Every live form with a Salesforce Event Code. Each one needs a page, a Salesforce feed, and an Easy Passthrough feed that takes its answers from the form itself. Fix anything in red before switching the router on.', 'salesforce-gravity-forms' ); ?></p>
	<?php if ( empty( $rows ) ) : ?>
		<p><em><?php esc_html_e( 'No live form has a Salesforce Event Code yet. Set one under the form\'s Form Settings → Salesforce Integration.', 'salesforce-gravity-forms' ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Form', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Event Code', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Page', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Salesforce feed', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Easy Passthrough', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'salesforce-gravity-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms&view=settings&id=' . (int) $row['form']['id'] ) ); ?>"><?php echo esc_html( $row['form']['title'] ); ?></a>
							<br><span class="description"><?php echo esc_html( sprintf( /* translators: %d: form ID. */ __( 'Form %d', 'salesforce-gravity-forms' ), $row['form']['id'] ) ); ?></span>
						</td>
						<td><code><?php echo esc_html( $row['form']['code'] ); ?></code></td>
						<td>
							<?php if ( $row['page_id'] ) : ?>
								<a href="<?php echo esc_url( $row['page_url'] ); ?>"><?php echo esc_html( $row['page_title'] ); ?></a>
								<br><span class="description">
									<?php echo esc_html( 'setting' === $row['page_source'] ? __( 'chosen in Form Settings', 'salesforce-gravity-forms' ) : __( 'the router page, which shows this form', 'salesforce-gravity-forms' ) ); ?>
								</span>
							<?php else : ?>
								<span style="color:#d63638;">&mdash;</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $row['salesforce'] ) : ?>
								<span style="color:#00a32a;">&#10003;</span>
							<?php else : ?>
								<span style="color:#d63638;">&#10007;</span>
							<?php endif; ?>
						</td>
						<td>
							<?php foreach ( $row['passthrough'] as $feed ) : ?>
								<?php if ( $feed['ok'] ) : ?>
									<span style="color:#00a32a;">&#10003;</span> <?php esc_html_e( 'this form', 'salesforce-gravity-forms' ); ?><br>
								<?php else : ?>
									<span style="color:#d63638;">&#10007;</span>
									<?php echo esc_html( sprintf( /* translators: %d: the form ID the feed takes answers from. */ __( 'form %d', 'salesforce-gravity-forms' ), $feed['source'] ) ); ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( $actions['fix'][0], $actions['fix'][1] ); ?>
										<input type="hidden" name="sfgf_router_fix_passthrough" value="1">
										<input type="hidden" name="sfgf_router_fix_form" value="<?php echo esc_attr( $row['form']['id'] ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Fix', 'salesforce-gravity-forms' ); ?></button>
									</form><br>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( empty( $row['passthrough'] ) ) : ?>
								<span class="description">&mdash;</span>
							<?php endif; ?>
						</td>
						<td style="<?php echo esc_attr( $row['has_errors'] ? 'color:#d63638;' : '' ); ?>">
							<?php if ( $row['is_override'] ) : ?>
								<span class="description"><?php esc_html_e( 'Used by an override.', 'salesforce-gravity-forms' ); ?></span><br>
							<?php endif; ?>
							<?php foreach ( $row['notes'] as $note ) : ?>
								<?php echo esc_html( $note ); ?><br>
							<?php endforeach; ?>
							<?php if ( empty( $row['notes'] ) && ! $row['is_override'] ) : ?>
								<span style="color:#00a32a;"><?php esc_html_e( 'Ready.', 'salesforce-gravity-forms' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr>

	<h2><?php esc_html_e( 'Check a link', 'salesforce-gravity-forms' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Paste a link from a Cvent email (or just the key, or an edit link) to see where the router would send it. It asks Salesforce live and changes nothing. This is also the quickest way to find out whether the plugin\'s Salesforce user can read the Cvent objects.', 'salesforce-gravity-forms' ); ?></p>
	<form method="post">
		<?php wp_nonce_field( $actions['test'][0], $actions['test'][1] ); ?>
		<input type="hidden" name="sfgf_router_test" value="1">
		<input type="text" name="sfgf_router_test_link" value="<?php echo esc_attr( $test_input ); ?>" class="large-text" placeholder="https://theassemble.com/networking-profile?attendee=A-NP-0000000041" autocomplete="off">
		<?php submit_button( __( 'Check', 'salesforce-gravity-forms' ), 'secondary' ); ?>
	</form>

	<?php if ( is_array( $test_result ) ) : ?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:1px 16px 8px;max-width:900px;">
			<?php if ( 'none' === $test_result['kind'] ) : ?>
				<p><?php esc_html_e( 'I couldn\'t find a key (A-NP-…) or an edit link (ep_token=…) in that.', 'salesforce-gravity-forms' ); ?></p>
			<?php else : ?>

				<?php if ( 'key' === $test_result['kind'] ) : ?>
					<h3><?php esc_html_e( 'What Salesforce says', 'salesforce-gravity-forms' ); ?></h3>
					<?php $details = $test_result['details'] ?? null; ?>
					<?php if ( null === $details ) : ?>
						<p><?php esc_html_e( 'There is no usable key in that link.', 'salesforce-gravity-forms' ); ?></p>
					<?php elseif ( is_wp_error( $details ) ) : ?>
						<p style="color:#d63638;"><strong><?php esc_html_e( 'The lookup failed:', 'salesforce-gravity-forms' ); ?></strong> <?php echo esc_html( $details->get_error_message() ); ?></p>
						<p class="description"><?php esc_html_e( 'If this mentions access or permissions, the plugin\'s Salesforce user needs Read on the Cvent Attendee and Cvent Event objects.', 'salesforce-gravity-forms' ); ?></p>
					<?php elseif ( empty( $details['found'] ) ) : ?>
						<p><?php echo esc_html( sprintf( /* translators: %s: the key. */ __( 'No attendee has the key %s.', 'salesforce-gravity-forms' ), $test_result['key'] ) ); ?></p>
					<?php else : ?>
						<ul style="list-style:disc;margin-left:20px;">
							<li><?php echo esc_html( sprintf( /* translators: 1: the key, 2: the attendee record number. */ __( 'Key %1$s is attendee %2$s.', 'salesforce-gravity-forms' ), $test_result['key'], $details['attendee'] ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: 1: Cvent event record number, 2: Conference code, 3: Conference year. */ __( 'Event %1$s, Conference code "%2$s", year "%3$s".', 'salesforce-gravity-forms' ), '' === $details['event_number'] ? '—' : $details['event_number'], $details['conference'], $details['year'] ) ); ?></li>
						</ul>
					<?php endif; ?>
				<?php else : ?>
					<h3><?php esc_html_e( 'What Easy Passthrough says', 'salesforce-gravity-forms' ); ?></h3>
					<?php if ( empty( $test_result['form_id'] ) ) : ?>
						<p><?php esc_html_e( 'No saved entry has that edit token.', 'salesforce-gravity-forms' ); ?></p>
					<?php else : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: form ID, 2: form title or a note. */
									__( 'The edit link belongs to form %1$d (%2$s).', 'salesforce-gravity-forms' ),
									$test_result['form_id'],
									$test_result['form'] ? $test_result['form']['title'] : __( 'not a Networking Profile form', 'salesforce-gravity-forms' )
								)
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<h3><?php esc_html_e( 'What the router would do', 'salesforce-gravity-forms' ); ?></h3>
				<?php $decision = $test_result['decision']; ?>
				<?php if ( 'redirect' === $decision['action'] ) : ?>
					<p>
						<strong><?php esc_html_e( 'Send the visitor to', 'salesforce-gravity-forms' ); ?></strong>
						<a href="<?php echo esc_url( $test_result['destination'] ); ?>"><?php echo esc_html( $test_result['destination'] ); ?></a>
						<?php echo esc_html( sprintf( /* translators: %d: form ID. */ __( '(form %d).', 'salesforce-gravity-forms' ), $decision['form_id'] ) ); ?>
					</p>
				<?php elseif ( 'notice' === $decision['action'] ) : ?>
					<p>
						<strong><?php esc_html_e( 'Show a notice instead of a form:', 'salesforce-gravity-forms' ); ?></strong>
						&ldquo;<?php echo esc_html( $test_result['message'] ); ?>&rdquo;
					</p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Show the router page as it is.', 'salesforce-gravity-forms' ); ?></strong></p>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: the reason. */ __( 'Because: %s.', 'salesforce-gravity-forms' ), $decision['reason'] ) ); ?></p>

			<?php endif; ?>
		</div>
	<?php endif; ?>

	<hr>

	<h2><?php esc_html_e( 'Copy a Salesforce feed', 'salesforce-gravity-forms' ); ?></h2>
	<p class="description"><?php esc_html_e( 'The Salesforce add-on won\'t copy its feed when a form is duplicated. This copies one form\'s feed onto a form that has none. It only copies when every field the feed uses is on the other form, and it never replaces an existing feed.', 'salesforce-gravity-forms' ); ?></p>
	<?php if ( empty( $copy_sources ) || empty( $copy_targets ) ) : ?>
		<p><em><?php esc_html_e( 'Nothing to copy: it needs one form with a Salesforce feed and one without.', 'salesforce-gravity-forms' ); ?></em></p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( $actions['copy'][0], $actions['copy'][1] ); ?>
			<input type="hidden" name="sfgf_router_copy_feed" value="1">
			<label>
				<?php esc_html_e( 'Copy the feed from', 'salesforce-gravity-forms' ); ?>
				<select name="sfgf_router_copy_from">
					<?php foreach ( $copy_sources as $form ) : ?>
						<option value="<?php echo esc_attr( $form['id'] ); ?>"><?php echo esc_html( sprintf( '%s (%d)', $form['title'], $form['id'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'to', 'salesforce-gravity-forms' ); ?>
				<select name="sfgf_router_copy_to">
					<?php foreach ( $copy_targets as $form ) : ?>
						<option value="<?php echo esc_attr( $form['id'] ); ?>"><?php echo esc_html( sprintf( '%s (%d)', $form['title'], $form['id'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( __( 'Copy Feed', 'salesforce-gravity-forms' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<hr>

	<h2><?php esc_html_e( 'Clear the lookup cache', 'salesforce-gravity-forms' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Each key\'s event is remembered for a day (a key with no event yet, for five minutes), with a longer copy kept for when Salesforce is unreachable. Clear it after changing an event\'s Conference code in Salesforce. The list of forms is read afresh as well.', 'salesforce-gravity-forms' ); ?></p>
	<form method="post">
		<?php wp_nonce_field( $actions['cache'][0], $actions['cache'][1] ); ?>
		<input type="hidden" name="sfgf_router_clear_cache" value="1">
		<?php submit_button( __( 'Clear Lookup Cache', 'salesforce-gravity-forms' ), 'secondary' ); ?>
	</form>
</div>
