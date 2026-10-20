<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog\models;

use craft\base\Model;

class Settings extends Model
{
	/**
	 * @var mixed How long a recorded change is kept before garbage collection deletes it. Set to `0` to keep changes forever.
	 *
	 * See [craft\helpers\ConfigHelper::durationInSeconds()](craft5:craft\helpers\ConfigHelper::durationInSeconds()) for a list of supported value types.
	 *
	 * @defaultAlt 1 year
	 */
	public mixed $purgeLogsDuration = 31536000;
}
