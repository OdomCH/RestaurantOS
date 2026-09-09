<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE AUDIT TRAIL - append-only.
 *
 * Answers "who changed this, when, from what, to what, and from where":
 *   - Admin changed a product price
 *   - Cashier cancelled an order
 *   - Manager approved a stock transfer
 *
 * old_values / new_values are JSON because the shape differs per model and only
 * the CHANGED attributes are stored. This is the one place where JSON is the
 * right tool: the payload is written once, read by a human, and never filtered
 * on. Everything that IS filtered on - actor, branch, event, target, time - is a
 * real indexed column.
 *
 * Passwords, PIN hashes and tokens are redacted before the row is written.
 *
 * docs/05-database-design.md 5.10, docs/22-security.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // NULL for system actions (scheduled jobs, console commands).
            $table->foreignId('user_id')->nullable();

            // The real actor when an admin is impersonating someone.
            $table->foreignId('impersonator_id')->nullable();
            $table->foreignId('branch_id')->nullable();

            $table->string('event', 50);   // created, updated, voided, login_failed

            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('description', 500)->nullable();
            $table->string('ip_address', 45)->nullable();   // IPv6-capable.
            $table->string('user_agent', 500)->nullable();

            // Correlates a database change with the application log line and
            // the HTTP request that caused it.
            $table->uuid('request_id')->nullable();
            $table->string('route', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // SET NULL, never CASCADE: deleting a user must not erase the record
            // of what they did. That would defeat the entire point of the table.
            $table->foreign('user_id', 'fk_audit_logs_user_id')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('impersonator_id', 'fk_audit_logs_impersonator_id')
                ->references('id')->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id', 'fk_audit_logs_branch_id')
                ->references('id')->on('branches')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'idx_audit_auditable');
            $table->index(['user_id', 'created_at'], 'idx_audit_user_created');
            $table->index(['event', 'created_at'], 'idx_audit_event_created');
            $table->index(['branch_id', 'created_at'], 'idx_audit_branch_created');
            $table->index('request_id', 'idx_audit_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
