<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The counter behind order, expense, transfer and refund numbers.
 *
 * Never read this to compute the next number in PHP - allocation must be a
 * single atomic upsert, or two terminals will mint the same order number.
 */
#[Fillable(['branch_id', 'scope', 'sequence_date', 'last_number'])]
class DailySequence extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
