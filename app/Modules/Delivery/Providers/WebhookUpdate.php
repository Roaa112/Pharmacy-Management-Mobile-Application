<?php

namespace App\Modules\Delivery\Providers;

use Illuminate\Support\Carbon;

/** One status update a provider reported for one of its shipments. */
final class WebhookUpdate
{
    public function __construct(
        public readonly ?string $externalReference,
        public readonly ?string $trackingNumber,
        public readonly string $rawStatus,
        public readonly string $eventId,
        public readonly Carbon $occurredAt,
        public readonly array $payload = [],
        public readonly ?string $note = null,
    ) {
    }
}
