<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `kitchen_tickets.status` (docs/13-kitchen-workflow.md).
 *
 * The kitchen tracks its own vocabulary because a ticket is per station: an
 * order whose drinks are ready but whose food is not has one ticket `ready` and
 * one `preparing`, while the order itself is still `preparing`.
 */
enum KitchenTicketStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Queued → Ready is permitted only with `kitchen.skip_preparing`.
            self::Queued => [self::Preparing, self::Ready, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            // Ready → Preparing is the recall path (`kitchen.recall_ticket`).
            self::Ready => [self::Served, self::Preparing],
            self::Served, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Statuses shown on the kitchen display.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [
            self::Queued->value,
            self::Preparing->value,
            self::Ready->value,
        ];
    }
}
