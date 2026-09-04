<?php
/**
 * Result of the Test Sponsor Query tool: a summary line, a table of the
 * normalized choices, and the SOQL used.
 *
 * @package SalesforceGravityForms
 *
 * @var array|\WP_Error $result Return value of run_test_query().
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div style="margin-top: 1em; max-width: 900px;">
	<?php if ( is_wp_error( $result ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<div class="notice notice-success inline">
		<p>
			<?php
			printf(
				/* translators: 1: raw qualifying row count, 2: distinct sponsor company count after deduplication. */
				esc_html__( '%1$d qualifying Attendee__c row(s) returned, %2$d distinct sponsor compan(y/ies) after deduplication by Account Id.', 'salesforce-gravity-forms' ),
				(int) $result['raw_count'],
				count( $result['choices'] )
			);
			?>
			<?php if ( $result['skipped_count'] > 0 ) : ?>
				<?php
				printf(
					/* translators: %d: count of rows skipped for missing Account Id/Name. */
					esc_html__( ' %d row(s) skipped for missing Account Id or Account Name.', 'salesforce-gravity-forms' ),
					(int) $result['skipped_count']
				);
				?>
			<?php endif; ?>
		</p>
	</div>

	<?php if ( ! empty( $result['choices'] ) ) : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label (Account Name)', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Value (Account Id)', 'salesforce-gravity-forms' ); ?></th>
					<th><?php esc_html_e( 'Attendee Rows', 'salesforce-gravity-forms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['choices'] as $choice ) : ?>
					<tr>
						<td><?php echo esc_html( $choice['label'] ); ?></td>
						<td><code><?php echo esc_html( $choice['value'] ); ?></code></td>
						<td><?php echo (int) count( $choice['metadata']['attendee_ids'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<details style="margin-top: 1em;">
		<summary><?php esc_html_e( 'SOQL used', 'salesforce-gravity-forms' ); ?></summary>
		<pre style="white-space: pre-wrap; background: #f6f7f7; padding: 1em;"><?php echo esc_html( $result['soql'] ); ?></pre>
	</details>
</div>
