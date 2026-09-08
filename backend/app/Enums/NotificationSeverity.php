<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `notifications.severity` — a RestaurantOS addition to Laravel's standard
 * notifications table, so that a bell icon can show unread *critical* items
 * without deserialising every JSON payload (docs/19-notifications.md).
 */
enum NotificationSeverity: string
{
    use HasValues;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
