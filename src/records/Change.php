<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog\records;

use craft\db\ActiveRecord;
use fostercommerce\orderchangelog\db\Table;

/**
 * One saved change to a completed order.
 *
 * @property int $id
 * @property int $orderId
 * @property ?int $userId
 * @property ?string $userName
 * @property ?int $impersonatorId
 * @property ?string $impersonatorName
 * @property array<string, mixed> $data
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class Change extends ActiveRecord
{
	#[\Override]
	public static function tableName(): string
	{
		return Table::CHANGES;
	}
}
