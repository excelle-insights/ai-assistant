<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Support\BookingIntent;

/**
 * BookingHandlerInterface — host-side seam for booking requests detected
 * by the AI assistant (mirrors the SystemContextProviderInterface pattern).
 *
 * The package detects "the customer wants to book" and extracts service +
 * date from the conversation; the HOST implements this interface to persist
 * it however its own domain requires (booking table, ticket, CRM lead, …).
 *
 * Rules for implementations:
 *  - Never throw out of this method for business reasons the package can't
 *    understand — still, the package invokes it inside try/catch so a host
 *    failure can never break the customer reply or the webhook ack.
 *  - De-duplicate on the host side (same contact + recent open request).
 *  - Keep it fast; the webhook acknowledges the provider after this runs.
 */
interface BookingHandlerInterface
{
    public function handleBookingIntent(BookingIntent $intent): void;
}
