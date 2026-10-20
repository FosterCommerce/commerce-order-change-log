<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog\tests\unit;

use fostercommerce\orderchangelog\services\Changes;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type OrderState from Changes
 * @phpstan-import-type LineItemState from Changes
 */
final class ChangesCompareTest extends TestCase
{
	public function testReturnsNullWhenLineItemsAndTotalMatch(): void
	{
		$state = $this->state('25.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '25.0000'),
		]);

		self::assertNull((new Changes())->compare($state, $state, []));
	}

	public function testListsAddedRemovedAndUpdatedLineItems(): void
	{
		$before = $this->state('85.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 4, '80.0000'),
			2 => $this->lineItem('Blue Shirt', 'SHIRT-BLUE', 1, '5.0000'),
		]);
		$after = $this->state('110.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 3, '60.0000'),
			3 => $this->lineItem('Green Shirt', 'SHIRT-GREEN', 2, '50.0000'),
		]);

		self::assertSame([
			'added' => [
				[
					'description' => 'Green Shirt',
					'sku' => 'SHIRT-GREEN',
					'qty' => 2,
				],
			],
			'removed' => [
				[
					'description' => 'Blue Shirt',
					'sku' => 'SHIRT-BLUE',
					'qty' => 1,
				],
			],
			'updated' => [
				[
					'description' => 'Red Shirt',
					'sku' => 'SHIRT-RED',
					'qtyFrom' => 4,
					'qtyTo' => 3,
					'totalFrom' => '80.0000',
					'totalTo' => '60.0000',
					'fields' => [],
				],
			],
			'fields' => [],
			'customFields' => [],
			'totalFrom' => '85.0000',
			'totalTo' => '110.0000',
		], (new Changes())->compare($before, $after, []));
	}

	public function testRecordsALineItemPriceChangeAtTheSameQuantity(): void
	{
		$before = $this->state('25.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '25.0000'),
		]);
		$after = $this->state('20.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '20.0000'),
		]);

		$data = (new Changes())->compare($before, $after, []);

		self::assertNotNull($data);
		self::assertSame(1, $data['updated'][0]['qtyTo']);
		self::assertSame('20.0000', $data['updated'][0]['totalTo']);
	}

	public function testRecordsATotalChangeWithoutLineItemChanges(): void
	{
		$lineItems = [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '25.0000'),
		];

		self::assertSame([
			'added' => [],
			'removed' => [],
			'updated' => [],
			'fields' => [],
			'customFields' => [],
			'totalFrom' => '25.0000',
			'totalTo' => '32.0000',
		], (new Changes())->compare($this->state('25.0000', $lineItems), $this->state('32.0000', $lineItems), []));
	}

	public function testRecordsFieldChangesWithoutLineItemOrTotalChanges(): void
	{
		$lineItems = [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '25.0000'),
		];
		$before = $this->state('25.0000', $lineItems, [
			'shippingMethod' => 'Ground',
			'shippingAddress' => '1 Main St, Springfield',
		]);
		$after = $this->state('25.0000', $lineItems, [
			'shippingMethod' => 'Ground',
			'shippingAddress' => '2 Oak Ave, Springfield',
		]);

		$data = (new Changes())->compare($before, $after, []);

		self::assertNotNull($data);
		self::assertSame([
			[
				'field' => 'shippingAddress',
				'from' => '1 Main St, Springfield',
				'to' => '2 Oak Ave, Springfield',
			],
		], $data['fields']);
	}

	public function testRecordsLineItemNoteOptionsAndStatusChanges(): void
	{
		$before = $this->state('25.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '25.0000', [
				'options' => null,
				'note' => 'Gift wrap',
				'privateNote' => null,
				'status' => 'Pending',
			]),
		]);
		$after = $this->state('25.0000', [
			1 => $this->lineItem('Red Shirt', 'SHIRT-RED', 1, '25.0000', [
				'options' => '{"size":"L"}',
				'note' => 'Gift wrap',
				'privateNote' => 'Customer called',
				'status' => 'Packed',
			]),
		]);

		$data = (new Changes())->compare($before, $after, []);

		self::assertNotNull($data);
		self::assertSame([
			[
				'field' => 'options',
				'from' => null,
				'to' => '{"size":"L"}',
			],
			[
				'field' => 'privateNote',
				'from' => null,
				'to' => 'Customer called',
			],
			[
				'field' => 'status',
				'from' => 'Pending',
				'to' => 'Packed',
			],
		], $data['updated'][0]['fields']);
	}

	public function testNamesCustomFieldChanges(): void
	{
		$before = $this->state('25.0000', [], [], [
			'uid-gift-note' => 'Happy birthday',
			'uid-po-number' => 'PO-100',
		]);
		$after = $this->state('25.0000', [], [], [
			'uid-gift-note' => 'Happy anniversary',
		]);

		$data = (new Changes())->compare($before, $after, [
			'uid-gift-note' => 'Gift note',
			'uid-po-number' => 'PO number',
		]);

		self::assertNotNull($data);
		self::assertSame([
			[
				'fieldUid' => 'uid-gift-note',
				'name' => 'Gift note',
				'from' => 'Happy birthday',
				'to' => 'Happy anniversary',
			],
			[
				'fieldUid' => 'uid-po-number',
				'name' => 'PO number',
				'from' => 'PO-100',
				'to' => null,
			],
		], $data['customFields']);
	}

	/**
	 * @param array<int, LineItemState> $lineItems
	 * @param array<string, ?string> $fields
	 * @param array<string, string> $customFields
	 * @return OrderState
	 */
	private function state(string $total, array $lineItems, array $fields = [], array $customFields = []): array
	{
		return [
			'total' => $total,
			'lineItems' => $lineItems,
			'fields' => $fields,
			'customFields' => $customFields,
		];
	}

	/**
	 * @param array<string, ?string> $fields
	 * @return LineItemState
	 */
	private function lineItem(string $description, string $sku, int $qty, string $total, array $fields = []): array
	{
		return [
			'description' => $description,
			'sku' => $sku,
			'qty' => $qty,
			'total' => $total,
			'fields' => $fields,
		];
	}
}
