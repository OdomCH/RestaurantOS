<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches are created first: nearly every other table carries a `branch_id`,
 * so this migration has to exist before any of them can declare the key.
 *
 * docs/05-database-design.md §5.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            // Appears inside human-readable order numbers (B1-20260905-0042),
            // so it is short, uppercase and permanently unique.
            $table->string('code', 20);
            $table->string('name', 150);

            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2);

            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();

            // IANA name, e.g. Asia/Bangkok. Rows are stored in UTC and rendered
            // per branch; the business day is computed from this plus
            // `business_day_start`.
            $table->string('timezone', 64)->default('UTC');
            $table->char('currency_code', 3);
            $table->time('business_day_start')->default('00:00:00');

            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();

            $table->string('tax_registration_number', 50)->nullable();
            $table->string('receipt_footer', 500)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Permanent uniqueness: a branch code must never be reused, because
            // it is baked into historical order numbers (§1.5).
            $table->unique('code', 'uq_branches_code');
            $table->index('is_active', 'idx_branches_is_active');
            $table->index('deleted_at', 'idx_branches_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
