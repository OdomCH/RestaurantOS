<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `stock_transfers.status` (docs/15-stock-transfer.md).
 *
 * The required workflow is Requested → Approved → In Transit → Received. The
 * schema adds `draft` (not yet submitted), `partially_received` (a variance was
 * recorded), `rejected` and `cancelled`, because each is a real outcome that
 * would otherwise be squeezed into a wrong status.
 */
enum TransferStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case InTransit = 'in_transit';
    case Received = 'received';
    case PartiallyReceived = 'partially_received';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Cancelled],
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::InTransit, self::Cancelled],
            self::InTransit => [self::Received, self::PartiallyReceived],
            self::Received, self::PartiallyReceived, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Stock has left the source branch and is not yet available anywhere.
     */
    public function isInFlight(): bool
    {
        return $this === self::InTransit;
    }
}
