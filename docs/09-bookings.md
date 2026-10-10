# Bookings

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

- When the AI detects a booking intent, `BookingService::recordFromIntent()`
  writes (or reuses) the booking, assigns the booking number, and stores the
  conversation turns.
- Repeating the request for the same contact within the dedupe window reuses
  the open booking and tops up missing service/date/name instead of duplicating.
- On every turn the assistant loads the contact's recent bookings and injects
  them as `CUSTOMER BOOKINGS` in the prompt, so "what about my previous
  booking?" is answered from real data — the assistant is explicitly told not
  to say a booking was not confirmed while a booking row exists.

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

## Env

| Key | Default | Purpose |
|-----|---------|---------|
| `AI_ASSISTANT_TABLE_PREFIX` | `ai_assistant` | Prefix for bookings + all package tables |
| `AI_ASSISTANT_BOOKING_HOOK` | `true` | Set `false` to disable booking detection/persistence |
| `AI_ASSISTANT_TENANT_ID` | — | Fixed company id for single-tenant apps |

## Notes

- Bookings never store host table names or columns; anything app-specific goes
  in `metadata` or in a host table keyed by `booking_id`.
- The booking number is the stable reference to quote back to the customer.
