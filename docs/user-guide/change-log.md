# The change log

What the plugin records when a completed order changes, and how to read the Change Log tab.

## What the plugin records

Each time a completed order or one of its addresses is saved, the plugin compares the order with what it was before the save. If a tracked value differs, the plugin records one change with:

- each line item added or removed, with its quantity and SKU;
- each line item whose quantity, total, options, customer note, private note, or status changed, with the values before and after;
- the shipping method, coupon code, customer email, shipping address, or billing address, before and after, for each one that changed;
- each custom field from **Commerce -> Settings -> Order Fields** that changed, before and after;
- the order total before and after;
- the user who made the change, the user who impersonated them, if any, and the time.

A user impersonates another user with **Sign in as** on the user's account page, or with an impersonation URL.

The plugin compares an address as one line: name, organization, tax ID, address lines, dependent locality, locality, administrative area, postal code, sorting code, and country code. Empty parts are left out.

## What the plugin does not record

| Change | Where to find it instead |
|---|---|
| Order status changes | The order's **Status History** tab |
| Payments and refunds | The order's **Transactions** tab |
| Adjustments, such as a discount or shipping cost | Recorded only as a change to line item and order totals |
| Address custom fields | Not recorded |
| Matrix, Content Block, and Addresses fields, and generated fields | Not recorded |
| Carts and the save that completes an order | Not recorded. The first recorded change is the first change after checkout. |
| Changes made before the plugin was installed | Not recorded |

## Read the Change Log tab

Open an order from **Commerce -> Orders** and select the **Change Log** tab. The newest change is first. Every user who can open the order can see the tab.

| Column | Shows |
|---|---|
| Date | When the order was saved. |
| User | The user who made the change, linked to their account. “System” means no user was signed in, as with a console command or a job a guest queued. |
| Changes | One line per change, described in [What each line shows](#what-each-line-shows). |
| Order total | The order total before and after, or “Unchanged”. |

### What each line shows

- Each added or removed line item shows its quantity, description, and SKU.
- If a line item's quantity changed, the line shows the quantity before and after. If only its total changed, the line shows the total before and after.
- Each option, note, or status change on a line item gets its own line. Options show as Craft stores them, such as `{"size":"Large"}`.
- Shipping method, coupon code, customer email, address, and custom field changes show their values before and after. “None” means the value was empty.
- “Only the order total changed” means no other tracked value changed.
- If a user impersonated another user, the User column shows the impersonating user first, followed by “(signed in as {user})”, where `{user}` is the account they acted as.

A recorded change keeps the line item's description and SKU as they were when the change was recorded. Renaming or deleting the product later does not change it.

## Custom field values

A custom field change shows the field's label and the value as Craft stores it. Text and number fields show their value. Dropdowns show the option value, and lightswitches show `true` or `false`. Relation fields such as Entries or Assets show element IDs, such as `[42,57]`.

A recorded change keeps the field's label from when the change was recorded. The plugin compares only fields in the order field layout, so removing a field from the layout does not record a change.

## Changes made by queue jobs

When a queue job changes an order, the plugin records the user whose action queued the job, whichever browser or console process runs the queue. A job queued by a guest or a console command shows “System”. If you retry a failed job within 30 days of when it was queued, the plugin records the user who queued it. If the user who queued a job was impersonated, the plugin records both users.

## How long changes are kept

During Craft's garbage collection, the plugin deletes recorded changes older than 1 year. To keep them for a different length of time, or forever, see [configuration](../reference/configuration.md).

## Deleted orders and users

Permanently deleting an order deletes its recorded changes. A deleted user's recorded changes stay, and show the name the user had when the change was recorded. The same applies to a deleted user who impersonated another user.
