# Configuration

Order Change Log has no settings page. To change a setting, create `config/order-change-log.php` in your Craft project and return the settings you want to override.

```php
<?php

return [
    'purgeLogsDuration' => 'P6M',
];
```

| Setting | Type | Default | Notes |
|---|---|---|---|
| `purgeLogsDuration` | mixed | 1 year | How long a recorded change is kept. Craft's garbage collection deletes older changes. Accepts the values [`ConfigHelper::durationInSeconds()`](https://docs.craftcms.com/api/v5/craft-helpers-confighelper.html#method-durationinseconds) accepts, such as a number of seconds or an ISO 8601 duration like `'P6M'`. Set to `0` to keep changes forever. |
