<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\ConfigHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\FieldLayout;
use DateTime;
use fostercommerce\orderchangelog\db\Table;
use fostercommerce\orderchangelog\Plugin;
use fostercommerce\orderchangelog\records\Change;
use yii\base\Component;

/**
 * Records the changes made to completed orders.
 *
 * @phpstan-type LineItemState array{description: string, sku: string, qty: int, total: string, fields: array<string, ?string>}
 * @phpstan-type OrderState array{total: string, lineItems: array<int, LineItemState>, fields: array<string, ?string>, customFields: array<string, string>}
 * @phpstan-type FieldChange array{field: string, from: ?string, to: ?string}
 * @phpstan-type CustomFieldChange array{fieldUid: string, name: string, from: ?string, to: ?string}
 * @phpstan-type LineItemSummary array{description: string, sku: string, qty: int}
 * @phpstan-type LineItemUpdate array{description: string, sku: string, qtyFrom: int, qtyTo: int, totalFrom: string, totalTo: string, fields: list<FieldChange>}
 * @phpstan-type ChangeData array{added: list<LineItemSummary>, removed: list<LineItemSummary>, updated: list<LineItemUpdate>, fields: list<FieldChange>, customFields: list<CustomFieldChange>, totalFrom: string, totalTo: string}
 */
class Changes extends Component
{
	/**
	 * @var array<int, OrderState>
	 */
	private array $statesBeforeSave = [];

	/**
	 * Database transaction levels when each remembered state was read, keyed by order ID.
	 *
	 * @var array<int, int>
	 */
	private array $saveTransactionLevels = [];

	/**
	 * Get an order's changes, newest first.
	 *
	 * @return list<array{userId: ?int, userName: ?string, impersonatorId: ?int, impersonatorName: ?string, dateCreated: DateTime, data: ChangeData}>
	 */
	public function getChangesForOrder(int $orderId): array
	{
		/** @var list<Change> $records */
		$records = Change::find()
			->where([
				'orderId' => $orderId,
			])
			->orderBy([
				'id' => SORT_DESC,
			])
			->all();

		$changes = [];
		foreach ($records as $record) {
			// Decode the column when the database returns JSON as text
			/** @var ChangeData $data */
			$data = Json::decodeIfJson($record->data);
			/** @var DateTime $dateCreated */
			$dateCreated = DateTimeHelper::toDateTime($record->dateCreated);

			$changes[] = [
				'userId' => $record->userId,
				'userName' => $record->userName,
				'impersonatorId' => $record->impersonatorId,
				'impersonatorName' => $record->impersonatorName,
				'dateCreated' => $dateCreated,
				'data' => $data,
			];
		}

		return $changes;
	}

	/**
	 * Compare two states of an order, returning null when no tracked value changed.
	 *
	 * @param OrderState $before
	 * @param OrderState $after
	 * @param array<string, string> $fieldNames custom field names keyed by their field layout UID
	 * @return ?ChangeData
	 */
	public function compare(array $before, array $after, array $fieldNames): ?array
	{
		$added = [];
		$updated = [];
		foreach ($after['lineItems'] as $lineItemId => $lineItemAfter) {
			$lineItemBefore = $before['lineItems'][$lineItemId] ?? null;

			if ($lineItemBefore === null) {
				$added[] = $this->summarize($lineItemAfter);
				continue;
			}

			$lineItemFields = $this->compareFields($lineItemBefore['fields'], $lineItemAfter['fields']);
			if ($lineItemBefore['qty'] !== $lineItemAfter['qty'] || $lineItemBefore['total'] !== $lineItemAfter['total'] || $lineItemFields !== []) {
				$updated[] = [
					'description' => $lineItemAfter['description'],
					'sku' => $lineItemAfter['sku'],
					'qtyFrom' => $lineItemBefore['qty'],
					'qtyTo' => $lineItemAfter['qty'],
					'totalFrom' => $lineItemBefore['total'],
					'totalTo' => $lineItemAfter['total'],
					'fields' => $lineItemFields,
				];
			}
		}

		$removed = [];
		foreach (array_diff_key($before['lineItems'], $after['lineItems']) as $lineItemBefore) {
			$removed[] = $this->summarize($lineItemBefore);
		}

		$fields = $this->compareFields($before['fields'], $after['fields']);

		$customFields = [];
		foreach (array_keys($before['customFields'] + $after['customFields']) as $fieldUid) {
			$valueBefore = $before['customFields'][$fieldUid] ?? null;
			$valueAfter = $after['customFields'][$fieldUid] ?? null;
			if ($valueBefore !== $valueAfter) {
				$customFields[] = [
					'fieldUid' => $fieldUid,
					'name' => $fieldNames[$fieldUid],
					'from' => $valueBefore,
					'to' => $valueAfter,
				];
			}
		}

		if ($added === [] && $removed === [] && $updated === [] && $fields === [] && $customFields === [] && $before['total'] === $after['total']) {
			return null;
		}

		return [
			'added' => $added,
			'removed' => $removed,
			'updated' => $updated,
			'fields' => $fields,
			'customFields' => $customFields,
			'totalFrom' => $before['total'],
			'totalTo' => $after['total'],
		];
	}

	/**
	 * Remember a completed order's recorded state before the order, or one of its addresses, saves.
	 */
	public function rememberStateBeforeSave(int $orderId): void
	{
		$transactionLevel = $this->getTransactionLevel();
		// Keep the outer save's state during nested saves, and replace a state left by a failed or vetoed save
		if (isset($this->saveTransactionLevels[$orderId]) && $transactionLevel > $this->saveTransactionLevels[$orderId]) {
			return;
		}

		unset($this->statesBeforeSave[$orderId], $this->saveTransactionLevels[$orderId]);
		$state = $this->readState($orderId);
		if ($state === null) {
			return;
		}

		$this->statesBeforeSave[$orderId] = $state;
		$this->saveTransactionLevels[$orderId] = $transactionLevel;
	}

	/**
	 * Record what the outer save of an order changed, and who made the change.
	 */
	public function recordChangesAfterSave(int $orderId): void
	{
		// Skip nested saves, whose after-save runs deeper than the outer save's transaction
		if (! isset($this->saveTransactionLevels[$orderId]) || $this->getTransactionLevel() !== $this->saveTransactionLevels[$orderId] + 1) {
			return;
		}

		$before = $this->statesBeforeSave[$orderId];
		unset($this->statesBeforeSave[$orderId], $this->saveTransactionLevels[$orderId]);

		$after = $this->readState($orderId);
		$data = $after === null ? null : $this->compare($before, $after, $this->getCustomFieldNames());
		if ($data === null) {
			return;
		}

		['user' => $user, 'impersonator' => $impersonator] = Plugin::getInstance()->getJobUsers()->getActingUsers();

		$record = new Change();
		$record->orderId = $orderId;
		$record->userId = $user?->id;
		// Keep the names, since the users can be deleted later
		$record->userName = $user === null ? null : ($user->fullName ?: $user->email);
		$record->impersonatorId = $impersonator?->id;
		$record->impersonatorName = $impersonator === null ? null : ($impersonator->fullName ?: $impersonator->email);
		$record->data = $data;
		$record->save(false);
	}

	/**
	 * Delete changes older than the `purgeLogsDuration` setting.
	 */
	public function purgeExpiredChanges(): void
	{
		$duration = ConfigHelper::durationInSeconds(Plugin::getInstance()->getSettings()->purgeLogsDuration);
		if ($duration === 0) {
			return;
		}

		Db::delete(Table::CHANGES, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$duration} seconds"))]);
	}

	/**
	 * Read an order's recorded state from the database, or null when the ID is not a completed order.
	 *
	 * A null state before the completing save stops checkout from being recorded.
	 *
	 * @return ?OrderState
	 */
	private function readState(int $orderId): ?array
	{
		/** @var array{isCompleted: bool|int|string, totalPrice: string, shippingMethodName: ?string, couponCode: ?string, email: ?string, shippingAddressId: int|string|null, billingAddressId: int|string|null}|null $order */
		$order = (new Query())
			->select(['isCompleted', 'totalPrice', 'shippingMethodName', 'couponCode', 'email', 'shippingAddressId', 'billingAddressId'])
			->from(CommerceTable::ORDERS)
			->where([
				'id' => $orderId,
			])
			->one();

		if ($order === null || ! (bool) $order['isCompleted']) {
			return null;
		}

		/** @var list<array{id: int|string, description: ?string, sku: ?string, qty: int|string, total: string, options: ?string, note: ?string, privateNote: ?string, status: ?string}> $rows */
		$rows = (new Query())
			->select([
				'lineItems.id',
				'lineItems.description',
				'lineItems.sku',
				'lineItems.qty',
				'lineItems.total',
				'lineItems.options',
				'lineItems.note',
				'lineItems.privateNote',
				'status' => 'lineItemStatuses.name',
			])
			->from([
				'lineItems' => CommerceTable::LINEITEMS,
			])
			->leftJoin([
				'lineItemStatuses' => CommerceTable::LINEITEMSTATUSES,
			], '[[lineItemStatuses.id]] = [[lineItems.lineItemStatusId]]')
			->where([
				'lineItems.orderId' => $orderId,
			])
			->all();

		$lineItems = [];
		foreach ($rows as $row) {
			$lineItems[(int) $row['id']] = [
				'description' => (string) $row['description'],
				'sku' => (string) $row['sku'],
				'qty' => (int) $row['qty'],
				'total' => $row['total'],
				'fields' => [
					// Record an empty options array as null, like an empty note
					'options' => in_array($row['options'], [null, '', '[]'], true) ? null : $row['options'],
					'note' => $row['note'] ?: null,
					'privateNote' => $row['privateNote'] ?: null,
					'status' => $row['status'],
				],
			];
		}

		$addresses = $this->readAddresses([$order['shippingAddressId'], $order['billingAddressId']]);

		$content = (new Query())
			->select(['content'])
			->from(CraftTable::ELEMENTS_SITES)
			->where([
				'elementId' => $orderId,
			])
			->scalar();

		return [
			'total' => $order['totalPrice'],
			'lineItems' => $lineItems,
			'fields' => [
				'shippingMethod' => $order['shippingMethodName'] ?: null,
				'couponCode' => $order['couponCode'] ?: null,
				'email' => $order['email'] ?: null,
				'shippingAddress' => $addresses[(int) $order['shippingAddressId']] ?? null,
				'billingAddress' => $addresses[(int) $order['billingAddressId']] ?? null,
			],
			'customFields' => $this->readCustomFieldValues(is_string($content) ? $content : null, $this->getCustomFieldNames()),
		];
	}

	/**
	 * Read custom field values as text, keyed by field layout UID, with arrays and other values JSON encoded.
	 *
	 * @param array<string, string> $fieldNames
	 * @return array<string, string>
	 */
	private function readCustomFieldValues(?string $content, array $fieldNames): array
	{
		/** @var array<string, mixed> $values */
		$values = $content === null ? [] : Json::decode($content);

		$texts = [];
		// Skip generated fields and fields no longer in the order field layout
		foreach (array_intersect_key($values, $fieldNames) as $fieldUid => $value) {
			$texts[$fieldUid] = is_string($value) ? $value : Json::encode($value);
		}

		return $texts;
	}

	/**
	 * @return array<string, string>
	 */
	private function getCustomFieldNames(): array
	{
		$names = [];
		/** @var FieldLayout $fieldLayout */
		$fieldLayout = Craft::$app->getFields()->getLayoutByType(Order::class);
		foreach ($fieldLayout->getCustomFieldElements() as $customField) {
			$names[(string) $customField->uid] = (string) $customField->label();
		}

		return $names;
	}

	/**
	 * Read addresses as one line of text each, keyed by address ID.
	 *
	 * @param list<int|string|null> $addressIds
	 * @return array<int, string>
	 */
	private function readAddresses(array $addressIds): array
	{
		/** @var list<array<string, string|int|null>> $rows */
		$rows = (new Query())
			->select(['id', 'fullName', 'organization', 'organizationTaxId', 'addressLine1', 'addressLine2', 'addressLine3', 'dependentLocality', 'locality', 'administrativeArea', 'postalCode', 'sortingCode', 'countryCode'])
			->from(CraftTable::ADDRESSES)
			->where([
				'id' => array_filter($addressIds),
			])
			->all();

		$addresses = [];
		foreach ($rows as $row) {
			$addressId = (int) $row['id'];
			unset($row['id']);
			$addresses[$addressId] = implode(', ', array_filter(array_map(strval(...), $row)));
		}

		return $addresses;
	}

	private function getTransactionLevel(): int
	{
		return Craft::$app->getDb()->getTransaction()?->level ?? 0;
	}

	/**
	 * @param array<string, ?string> $fieldsBefore
	 * @param array<string, ?string> $fieldsAfter
	 * @return list<FieldChange>
	 */
	private function compareFields(array $fieldsBefore, array $fieldsAfter): array
	{
		$fieldChanges = [];
		foreach ($fieldsAfter as $field => $valueAfter) {
			$valueBefore = $fieldsBefore[$field];
			if ($valueBefore !== $valueAfter) {
				$fieldChanges[] = [
					'field' => $field,
					'from' => $valueBefore,
					'to' => $valueAfter,
				];
			}
		}

		return $fieldChanges;
	}

	/**
	 * @param LineItemState $lineItem
	 * @return LineItemSummary
	 */
	private function summarize(array $lineItem): array
	{
		return [
			'description' => $lineItem['description'],
			'sku' => $lineItem['sku'],
			'qty' => $lineItem['qty'],
		];
	}
}
