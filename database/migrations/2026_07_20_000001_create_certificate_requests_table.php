<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Phase A — certificate_requests.
 *
 * A registered user asks for a local academic X.509 signer certificate; a
 * certificate_operator approves or rejects; approval enqueues issuance.
 *
 * The physical schema is the last-resort guard for the workflow: the allowed
 * status set, the "reviewer is never the subject" rule, the stable failure-code
 * format, the per-status field/timestamp shape and the one-active-request
 * invariant are all enforced by the database, not only by the service layer.
 *
 * Raw CHECK/partial-unique statements run on PostgreSQL only (same guard style
 * as 2026_07_11_000001). SQLite is the default test harness and cannot express
 * them, so behaviour that depends on them is proven separately by the
 * PostgreSQL-only opt-in suite.
 *
 * Forward-only. This migration is intentionally NOT executed against the
 * development database during the M14 implementation cycle.
 */
return new class extends Migration
{
    /** Stable failure codes are UPPER_SNAKE, never a human message. */
    private const FAILURE_CODE_REGEX = '^[A-Z][A-Z0-9_]{2,63}$';

    public function up(): void
    {
        Schema::create('certificate_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32);
            $table->text('request_note')->nullable();

            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('operator_note')->nullable();
            $table->timestampTz('approved_at')->nullable();

            $table->uuid('issuance_attempt_id')->nullable();
            $table->timestampTz('issuance_started_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('failure_code', 64)->nullable();

            $table->foreignId('certificate_id')->nullable()->unique()->constrained('certificates')->restrictOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
            $table->index('reviewed_by_user_id');
            $table->index('created_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1. Allowed statuses.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_status_check
            CHECK (status IN ('pending', 'approved', 'rejected', 'issuing', 'issued', 'failed', 'cancelled'))
        ");

        // 2. An operator may never review their own request.
        DB::statement('
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_reviewer_not_subject_check
            CHECK (reviewed_by_user_id IS NULL OR reviewed_by_user_id <> user_id)
        ');

        // 3. failure_code is a stable allow-listed code shape, never a message.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_failure_code_format_check
            CHECK (failure_code IS NULL OR failure_code ~ '".self::FAILURE_CODE_REGEX."')
        ");

        // 4. pending: nothing but the submission itself may be set.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_pending_shape_check
            CHECK (
                status <> 'pending'
                OR (
                    reviewed_by_user_id IS NULL
                    AND reviewed_at IS NULL
                    AND operator_note IS NULL
                    AND approved_at IS NULL
                    AND issuance_attempt_id IS NULL
                    AND issuance_started_at IS NULL
                    AND issued_at IS NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                    AND failure_code IS NULL
                    AND certificate_id IS NULL
                )
            )
        ");

        // 5. cancelled: owner action out of pending; no operator, no issuance.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_cancelled_shape_check
            CHECK (
                status <> 'cancelled'
                OR (
                    cancelled_at IS NOT NULL
                    AND reviewed_by_user_id IS NULL
                    AND reviewed_at IS NULL
                    AND operator_note IS NULL
                    AND approved_at IS NULL
                    AND issuance_attempt_id IS NULL
                    AND issuance_started_at IS NULL
                    AND issued_at IS NULL
                    AND failed_at IS NULL
                    AND failure_code IS NULL
                    AND certificate_id IS NULL
                )
            )
        ");

        // 6. rejected: concrete operator, review timestamp, non-empty reason.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_rejected_shape_check
            CHECK (
                status <> 'rejected'
                OR (
                    reviewed_by_user_id IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND operator_note IS NOT NULL
                    AND btrim(operator_note) <> ''
                    AND approved_at IS NULL
                    AND issuance_attempt_id IS NULL
                    AND issuance_started_at IS NULL
                    AND issued_at IS NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                    AND failure_code IS NULL
                    AND certificate_id IS NULL
                )
            )
        ");

        // 7. approved: operator + approval timestamp + issuance attempt reserved.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_approved_shape_check
            CHECK (
                status <> 'approved'
                OR (
                    reviewed_by_user_id IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND approved_at IS NOT NULL
                    AND issuance_attempt_id IS NOT NULL
                    AND issuance_started_at IS NULL
                    AND issued_at IS NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                    AND failure_code IS NULL
                    AND certificate_id IS NULL
                )
            )
        ");

        // 8. issuing: worker claimed the exact attempt.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_issuing_shape_check
            CHECK (
                status <> 'issuing'
                OR (
                    reviewed_by_user_id IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND approved_at IS NOT NULL
                    AND issuance_attempt_id IS NOT NULL
                    AND issuance_started_at IS NOT NULL
                    AND issued_at IS NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                    AND failure_code IS NULL
                    AND certificate_id IS NULL
                )
            )
        ");

        // 9. issued: bound certificate + issue timestamp, never a failure.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_issued_shape_check
            CHECK (
                status <> 'issued'
                OR (
                    reviewed_by_user_id IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND approved_at IS NOT NULL
                    AND issuance_attempt_id IS NOT NULL
                    AND issuance_started_at IS NOT NULL
                    AND issued_at IS NOT NULL
                    AND certificate_id IS NOT NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                    AND failure_code IS NULL
                )
            )
        ");

        // 10. failed: terminal, stable code, never a bound certificate.
        //     issuance_started_at is REQUIRED: a request may only fail once the
        //     worker actually claimed the attempt and entered `issuing`. Without
        //     it the row would claim a failure for work that never started, and
        //     `failed` would become reachable straight from `approved`, which the
        //     state machine forbids.
        DB::statement("
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_failed_shape_check
            CHECK (
                status <> 'failed'
                OR (
                    reviewed_by_user_id IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND approved_at IS NOT NULL
                    AND issuance_attempt_id IS NOT NULL
                    AND issuance_started_at IS NOT NULL
                    AND failed_at IS NOT NULL
                    AND failure_code IS NOT NULL
                    AND certificate_id IS NULL
                    AND issued_at IS NULL
                    AND cancelled_at IS NULL
                )
            )
        ");

        // 11. Sensible timestamp ordering across the lifecycle:
        //     created_at ≤ reviewed_at ≤ approved_at ≤ issuance_started_at
        //     ≤ issued_at / failed_at.
        DB::statement('
            ALTER TABLE certificate_requests
            ADD CONSTRAINT certificate_requests_timestamp_order_check
            CHECK (
                (reviewed_at IS NULL OR reviewed_at >= created_at)
                AND (cancelled_at IS NULL OR cancelled_at >= created_at)
                AND (approved_at IS NULL OR reviewed_at IS NULL OR approved_at >= reviewed_at)
                AND (issuance_started_at IS NULL OR approved_at IS NULL OR issuance_started_at >= approved_at)
                AND (issued_at IS NULL OR issuance_started_at IS NULL OR issued_at >= issuance_started_at)
                AND (failed_at IS NULL OR approved_at IS NULL OR failed_at >= approved_at)
                AND (failed_at IS NULL OR issuance_started_at IS NULL OR failed_at >= issuance_started_at)
            )
        ');

        // 12. At most one ACTIVE request per user. pending/approved/issuing are
        //     the only states that may still lead to an issued certificate.
        DB::statement("
            CREATE UNIQUE INDEX certificate_requests_user_active_unique
            ON certificate_requests (user_id)
            WHERE status IN ('pending', 'approved', 'issuing')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_requests');
    }
};
