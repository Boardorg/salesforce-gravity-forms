<?php

use WP_Mock\Tools\TestCase;
use function BoardMC\SalesforceGravityForms\Providers\SalesforceSponsorProvider\group_by_account;
use function BoardMC\SalesforceGravityForms\Providers\SalesforceSponsorProvider\sort_choices;

/**
 * @covers \BoardMC\SalesforceGravityForms\Providers\SalesforceSponsorProvider
 */
class SponsorProviderTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	private function record( $attendee_id, $account_id, $account_name ) {
		return [
			'Id'          => $attendee_id,
			'Delegate__r' => [
				'AccountId' => $account_id,
				'Account'   => [ 'Name' => $account_name ],
			],
		];
	}

	public function test_two_rows_with_one_account_id_produce_one_choice() {
		$records = [
			$this->record( 'a1', '001A', 'Acme Corp' ),
			$this->record( 'a2', '001A', 'Acme Corp' ),
		];

		list( $companies, $skipped ) = group_by_account( $records );

		$this->assertCount( 1, $companies );
		$this->assertSame( 0, $skipped );
		$this->assertSame( '001A', $companies['001A']['value'] );
		$this->assertSame( '001A', $companies['001A']['source_id'] );
		$this->assertSame( 'Acme Corp', $companies['001A']['label'] );
		$this->assertSame( [ 'a1', 'a2' ], $companies['001A']['metadata']['attendee_ids'] );
	}

	public function test_two_accounts_with_same_name_remain_distinct() {
		$records = [
			$this->record( 'a1', '001A', 'Acme Corp' ),
			$this->record( 'a2', '001B', 'Acme Corp' ),
		];

		list( $companies, $skipped ) = group_by_account( $records );

		$this->assertCount( 2, $companies );
		$this->assertSame( 0, $skipped );
		$this->assertArrayHasKey( '001A', $companies );
		$this->assertArrayHasKey( '001B', $companies );
	}

	public function test_rows_missing_account_id_or_name_are_skipped_safely() {
		$records = [
			$this->record( 'a1', '001A', 'Acme Corp' ),
			$this->record( 'a2', null, 'No Account Id Co' ),
			$this->record( 'a3', '001C', null ),
			$this->record( 'a4', '', '' ),
		];

		list( $companies, $skipped ) = group_by_account( $records );

		$this->assertCount( 1, $companies );
		$this->assertSame( 3, $skipped );
	}

	public function test_account_id_is_value_and_account_name_is_label() {
		$records = [ $this->record( 'a1', '001A', 'Acme Corp' ) ];

		list( $companies ) = group_by_account( $records );

		$this->assertSame( '001A', $companies['001A']['value'] );
		$this->assertSame( 'Acme Corp', $companies['001A']['label'] );
		$this->assertNotSame( $companies['001A']['label'], $companies['001A']['value'] );
	}

	public function test_a_company_name_change_updates_only_the_label() {
		// Same Account ID, later row reports an updated Account name — the
		// stored identity (value/source_id) must not move.
		$records = [
			$this->record( 'a1', '001A', 'Acme Corp' ),
			$this->record( 'a2', '001A', 'Acme Corporation' ),
		];

		list( $companies ) = group_by_account( $records );

		$this->assertCount( 1, $companies );
		$this->assertSame( '001A', $companies['001A']['value'] );
		$this->assertSame( 'Acme Corporation', $companies['001A']['label'] );
	}

	public function test_sort_choices_orders_case_insensitively_without_changing_identity() {
		$choices = [
			[ 'value' => '001B', 'label' => 'beta co' ],
			[ 'value' => '001A', 'label' => 'Acme Corp' ],
			[ 'value' => '001C', 'label' => 'Charlie Inc' ],
		];

		$sorted = sort_choices( $choices );

		$this->assertSame( [ '001A', '001B', '001C' ], array_column( $sorted, 'value' ) );
		$this->assertSame( [ 'Acme Corp', 'beta co', 'Charlie Inc' ], array_column( $sorted, 'label' ) );
	}
}
