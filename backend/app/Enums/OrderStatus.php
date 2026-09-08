<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `orders.status` — the order lifecycle (docs/11-order-workflow.md).
 *
 * Pending → Accepted → Preparing → Ready → Completed, with Cancelled reachable
 * from any non-terminal state. Enforced by C9 in the OrderStateMachine.
 */
enum OrderStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * States reachable from this one.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Cancelled],
            self::Accepted => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Terminal states are immutable: no further transition, no item edits.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Statuses that still occupy the kitchen and the active-order list.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [
            self::Pending->value,
            self::Accepted->value,
            self::Preparing->value,
            self::Ready->value,
        ];
    }
}
