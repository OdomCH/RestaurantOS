<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `settings.type` — tells the resolver how to cast the `TEXT` value back into a
 * PHP type. Without it every setting would come back as a string and a
 * threshold comparison would silently compare text.
 */
enum SettingType: string
{
    use HasValues;

    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Json = 'json';
}
