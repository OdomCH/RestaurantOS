<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `expenses.status` (docs/17-expense-management.md).
 */
enum ExpenseStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Paid],
            self::Rejected => [self::Draft],
            self::Paid => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Only a draft expense may be edited or deleted by its creator.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
