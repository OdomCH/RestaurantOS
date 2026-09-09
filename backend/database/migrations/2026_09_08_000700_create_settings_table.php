<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key–value configuration with a branch override: a row with `branch_id = NULL`
 * is the global default, a row with a branch overrides it for that branch.
 *
 * This is one of the few justified key–value tables in the schema. The
 * alternative — a wide `branch_settings` table — needs a migration for every new
 * knob, and there are dozens.
 *
 * docs/05-database-design.md §5.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->nullable();
            $table->string('key', 100);
            $table->text('value')->nullable();

            // Tells the resolver how to cast `value` back to a PHP type.
            $table->string('type', 20)->default('string');

            // Only public settings are exposed to the SPA. Discount thresholds
            // and financial rates are deliberately not public.
            $table->boolean('is_public')->default(false);

            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            // Same NULL-folding technique as `role_user`: without it, two global
            // rows could share a key.
            $table->unsignedBigInteger('branch_key')
                ->storedAs(SchemaSupport::nullSafe('branch_id'));

            $table->foreign('branch_id', 'fk_settings_branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('updated_by', 'fk_settings_updated_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['branch_key', 'key'], 'uq_settings_branch_key');
            $table->index('key', 'idx_settings_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
