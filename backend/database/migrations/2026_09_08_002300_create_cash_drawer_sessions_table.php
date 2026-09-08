<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cashier's shift: opening float, takings, closing count and the variance
 * between them. Cash payments and orders reference the session, which is what
 * makes "who was short 12.50 on Tuesday?" answerable.
 *
 * docs/05-database-design.md §5.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_drawer_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id');
            $table->foreignId('user_id');
            $table->date('business_date');

            $table->decimal('opening_float', 12, 2)->default(0);

            // Computed at close: float + cash in - cash out.
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();

            // Signed: counted - expected. Negative means the drawer is short.
            $table->decimal('variance', 12, 2)->nullable();

            $table->string('status', 20)->default('open');

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // May differ from user_id when a manager closes an abandoned drawer.
            $table->foreignId('closed_by')->nullable();

            // Mandatory when |variance| exceeds the configured tolerance.
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->foreign('branch_id', 'fk_cash_drawer_sessions_branch_id')
                ->references('id')->on('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('user_id', 'fk_cash_drawer_sessions_user_id')
                ->references('id')->on('users')
                ->restrictOnDelete()  // Financial evidence: never lose the owner.
                ->cascadeOnUpdate();

            $table->foreign('closed_by', 'fk_cash_drawer_sessions_closed_by')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // At most one OPEN session per user per branch (C15). MySQL has no
            // partial index, so a stored generated column carries the guard: it
            // is NULL for closed sessions (and NULLs never collide) and a
            // branch:user key while open.
            $table->string('open_guard', 64)->nullable()->storedAs(
                "(CASE WHEN `status` = 'open' THEN ".
                SchemaSupport::concat(["CAST(`branch_id` AS CHAR)", "':'", "CAST(`user_id` AS CHAR)"]).
                ' ELSE NULL END)'
            );
            $table->unique('open_guard', 'uq_cds_open');

            $table->index(['branch_id', 'business_date'], 'idx_cds_branch_date');
            $table->index(['user_id', 'status'], 'idx_cds_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drawer_sessions');
    }
};
