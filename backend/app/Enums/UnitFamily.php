<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `units.family`.
 *
 * Conversion is only ever legal **within** a family (FR-INV-003): grams convert
 * to kilograms, millilitres never convert to grams without a density the system
 * does not model. C12 enforces this in the UnitConverter.
 */
enum UnitFamily: string
{
    use HasValues;

    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';
}
