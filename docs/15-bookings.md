# 15 — Bookings

Bookings live in the **package**, not in the host application. Every chat
channel (WhatsApp today, web chat / in-app next) writes to the same booking
tables, so a booking made in one system is understood everywhere.

## Tables

Created by `php vendor/excelle-insights/ai-assistant/migrate.php`. Both use the
package prefix (`AI_ASSISTANT_TABLE_PREFIX`, default `ai_assistant`).

| Table | Purpose |
|-------|---------|
| `{prefix}_bookings` | One row per booking request, with a booking number and status |
| `{prefix}_booking_messages` | The chat turns attached to a booking (customer / AI / staff) |

### `{prefix}_bookings`

| Column | Notes |
|--------|-------|
| `id` | Primary key |
| `booking_number` | Human-readable, unique, e.g. `BK-20261010-00012` |
| `company_id` | Tenant scope (0 for single-tenant apps) |
| `conversation_id` | Source conversation (nullable) |
| `channel` | `whatsapp`, `web`, … |
| `contact_user_id` | WhatsApp BSUID / stable user id (nullable) |
| `contact_phone` | Phone when shared (nullable) |
| `contact_name` | Display name |
| `service` | Requested service / summary |
| `preferred_date` | `Y-m-d` or free text, nullable |
| `status` | `pending`, `confirmed`, `rescheduled`, `cancelled`, `completed` |
| `source_message` / `ai_reply` | The turn that triggered the booking |
| `confidence` | Extractor confidence 0.00–1.00 |
| `metadata` | JSON: channel + host extras |
| `confirmed_at` / `cancelled_at` | Lifecycle timestamps |
| `created_at` / `updated_at` | Row timestamps |

## Behaviour

- A booking row is created **only after the customer confirms a complete
  request** — a concrete service plus a date or an explicit confirmation. Simply
  asking "can I book?" or still supplying details does **not** create a booking.
- On confirmation, `BookingService::recordFromIntent()` writes (or reuses an
  open) booking, assigns the booking number, and stores the conversation turns.
  The number is available to the reply in the same turn.
- Repeating the request for the same contact within the dedupe window reuses
  the open (pending) booking and tops up missing service/date/name instead of
  duplicating.
- On a turn where the customer asks about an existing booking, the assistant
  loads the contact's bookings as `CUSTOMER BOOKINGS`; it never announces them
  unprompted.

## Host responsibilities (your app)

The package owns persistence and recall. Your app owns:

1. **Where bookings are displayed** — read them with `BookingService`.
2. **What action is needed** — confirm / reschedule / cancel, and any host
   side effects (notify staff, link a vehicle/work order, etc.).

Implement `ExcelleInsights\AiAssistant\Contracts\BookingHandlerInterface` and
register it on the service. It is called after the booking is persisted and
receives a `BookingIntent` carrying `bookingId` and `bookingNumber`:

```php
$ai = new \ExcelleInsights\AiAssistant\Facade\AiAssistantManager($pdo);
$ai->getService()->setBookingHandler(new MyBookingHandler()); // notify staff, link domain rows
```

Read / act on bookings through the service:

```php
$bookings = $ai->bookings();                       // BookingService

$bookings->listForCompany($companyId, ['status' => 'pending'], 50);
$booking  = $bookings->getByNumber('BK-20261010-00012');
$bookings->setStatus((int) $booking['id'], 'confirmed');   // user confirmed
$bookings->setPreferredDate((int) $booking['id'], '2026-10-20'); // user picked a date
$bookings->messages((int) $booking['id']);         // conversation thread
$bookings->addMessage((int) $booking['id'], 'staff', 'Agent Jane', 'We booked you for Monday.', false);
```

## Settings

| Setting | Column in `{prefix}_config` | Default | Purpose |
|---------|-----------------------------|---------|---------|
| `channels.whatsapp.booking_hook` | `channels_json` | `true` | `no` disables booking detection and persistence |
| `channels.whatsapp.table_prefix` | `channels_json` | `whatsapp` | Host WhatsApp tables read for contact identity |
| `AI_ASSISTANT_TABLE_PREFIX` (`.env`) | — | `ai_assistant` | Prefix for bookings and all package tables |
| `AI_ASSISTANT_TENANT_ID` (`.env`) | — | — | Fixed company id for single-tenant apps |

Set the behaviour through the API or your settings screen:

```php
$api->handle('config.save', [
    'company_id' => 0,
    'settings'   => ['channels.whatsapp.booking_hook' => 'no'], // yes/no/on/off/1/0
]);
```

The legacy `AI_ASSISTANT_BOOKING_HOOK` / `AI_WHATSAPP_BOOKING_HOOK` keys still
work as a break-glass env override; they are not needed in `.env` otherwise.

## Notes

- Bookings never store host table names or columns; anything app-specific goes
  in `metadata` or in a host table keyed by `booking_id`.
- The booking number is the stable reference to quote back to the customer.
