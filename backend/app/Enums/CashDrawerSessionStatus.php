<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `cash_drawer_sessions.status`.
 *
 * At most one `open` session may exist per user per branch — enforced by the
 * `open_guard` generated column and its unique index (C15).
 */
enum CashDrawerSessionStatus: string
{
    use HasValues;

    case Open = 'open';
    case Closed = 'closed';
}
