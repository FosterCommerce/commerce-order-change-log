# Schema

`src/migrations/Install.php` defines this schema.

## `orderchangelog_changes`

One row per save that changed a completed order.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `orderId` | int | Foreign key to `commerce_orders.id`. Permanently deleting the order deletes the row. Indexed. |
| `userId` | int, nullable | Foreign key to `users.id`: the signed-in user, or for a queue job, the user who queued it. Null when there was no user, or after the user is permanently deleted. Indexed. |
| `userName` | string, nullable | The user's name when the change was recorded, shown after the user is deleted. Null when there was no user. |
| `impersonatorId` | int, nullable | Foreign key to `users.id`: the user who impersonated `userId`. Null unless a user impersonated `userId`, or after the impersonating user is permanently deleted. |
| `impersonatorName` | string, nullable | The impersonating user's name when the change was recorded, shown after that user is deleted. Null unless a user impersonated `userId`. |
| `data` | JSON | The change. See [`data`](#data). |
| `dateCreated` | datetime | When the change was recorded, in UTC. |
| `dateUpdated` | datetime | Same as `dateCreated`. The plugin does not update rows. |
| `uid` | char(36) | |

### `data`

| Key | Type | Notes |
|---|---|---|
| `added` | array | Line items added, each `{description, sku, qty}`. |
| `removed` | array | Line items removed, each `{description, sku, qty}`. |
| `updated` | array | Line items whose quantity, total, options, notes, or status changed, each `{description, sku, qtyFrom, qtyTo, totalFrom, totalTo, fields}`. `fields` lists each change as `{field, from, to}`, where `field` is `options`, `note`, `privateNote`, or `status`. `options` is the options JSON as stored, `status` is the line item status name, and `from` or `to` is null when the value was empty. |
| `fields` | array | Other values that changed, each `{field, from, to}`. `field` is `shippingMethod`, `couponCode`, `email`, `shippingAddress`, or `billingAddress`. An address is one line of text: name, organization, tax ID, address lines 1-3, dependent locality, locality, administrative area, postal code, sorting code, and country code, joined with commas, skipping empty parts. `from` or `to` is null when the value was empty. |
| `customFields` | array | Custom fields that changed, each `{fieldUid, name, from, to}`. `fieldUid` is the field layout element UID, and `name` is the field's label when the change was recorded. Only fields in the order field layout are compared. `from` and `to` are the stored values as text, with arrays and other values JSON encoded, or null when the field had no value. |
| `totalFrom` | string | The order's `totalPrice` before the save, as a decimal string such as `"190.5000"`. |
| `totalTo` | string | The order's `totalPrice` after the save. |

Line item totals are the line item's `total` column, as decimal strings.

### `data` for a placed order

The change recorded when an order is placed has a different shape.

| Key | Type | Notes |
|---|---|---|
| `placed` | bool | Always `true`. |
| `lineItems` | array | Each line item, `{description, sku, qty, total, fields}`. `fields` is keyed like the order's `fields`, and holds the line item's `options`, `note`, `privateNote`, and `status`, leaving out empty ones. |
| `fields` | object | Values keyed by field, or `[]` when every value is empty. The order's `shippingMethod`, `couponCode`, `email`, `shippingAddress`, and `billingAddress`, leaving out empty ones. |
| `customFields` | array | Each custom field with a value, `{fieldUid, name, value}`. |
| `total` | string | The order's `totalPrice`. |

## `orderchangelog_jobusers`

One row per queue job queued while a user was signed in. The plugin deletes a row when its job succeeds, and keeps a failed job's row for a retry. During Craft's garbage collection, it deletes rows older than 30 days.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `jobId` | string | The queue job ID. Indexed. |
| `userId` | int | Foreign key to `users.id`. Permanently deleting the user deletes the row. Indexed. |
| `impersonatorId` | int, nullable | Foreign key to `users.id`: the user who impersonated `userId` when the job was queued. Null unless a user impersonated `userId`, or after the impersonating user is permanently deleted. |
| `dateCreated` | datetime | When the job was queued, in UTC. |
| `dateUpdated` | datetime | Same as `dateCreated`. |
| `uid` | char(36) | |
