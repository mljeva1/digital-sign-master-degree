<?php

declare(strict_types=1);

namespace App\Services\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\IssuanceFailureCode;
use App\Exceptions\CertificateRequests\IssuanceException;
use App\Exceptions\CertificateRequests\TransientIssuanceException;
use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Signing\LocalSignerCertificateIssuanceService;
use App\Services\Signing\SignerCertificateRegistrar;
use App\Support\CertificateRequests\TransientDatabaseFailureClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The dedicated certificate-issuance worker's engine (M14 Phase B).
 *
 * Lifecycle:
 *   1. CLAIM (short tx, FOR UPDATE): approved → issuing, set issuance_started_at,
 *      audit `certificate.issuance.started`; idempotent no-op on an already
 *      issued/terminal/stale row; a same-attempt `issuing` row continues as retry.
 *   2. CRYPTO (NO db tx / row lock held): re-check the subject has no active
 *      certificate, resolve the attempt-owned leaf (winning or reusing per P2-2),
 *      hand it to the registrar.
 *   3. COMPLETE (inside the registrar's persistence tx): fresh-lock the request,
 *      re-prove request/attempt/user/state and certificate ownership, bind
 *      certificate_id, issuing → issued, audit `certificate.issuance.completed`.
 *
 * FAILURE MODEL: a PERMANENT security/domain failure marks the request terminal
 * `failed` (only from `issuing`, same attempt) with a stable
 * {@see IssuanceFailureCode} and a `certificate.issuance.failed` (success=false)
 * audit; it never rethrows for a queue retry. A TRANSIENT lock/deadlock — even one
 * WRAPPED inside a registrar exception, detected through the whole `getPrevious()`
 * chain by {@see TransientDatabaseFailureClassifier} — is rethrown as
 * {@see TransientIssuanceException} so Laravel retries the SAME attempt without
 * burning terminal state or storing a raw message; only exhausted retries record
 * ISSUANCE_RETRIES_EXHAUSTED via {@see markRetriesExhausted()}.
 *
 * OWNERSHIP CLEANUP (P2-2): only the invocation that created the attempt artefact
 * ever removes it, and only after re-proving it is still its own unchanged file.
 *
 * The audit actor is always the concrete reviewed_by_user_id operator — never
 * auth(). No note, PII, DN, serial, attempt UUID, path, PEM, key, passphrase or
 * raw exception is ever stored, audited, logged or displayed.
 */
class CertificateIssuanceProcessor
{
    public const AUDIT_STARTED = 'certificate.issuance.started';

    public const AUDIT_COMPLETED = 'certificate.issuance.completed';

    public const AUDIT_FAILED = 'certificate.issuance.failed';

    private const CLAIM_PROCEED = 'proceed';

    private const CLAIM_NOOP = 'noop';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LocalSignerCertificateIssuanceService $issuance,
        private readonly SignerCertificateRegistrar $registrar,
        private readonly TransientDatabaseFailureClassifier $transient = new TransientDatabaseFailureClassifier,
    ) {}

    public function process(int $requestId, string $attemptId): void
    {
        if ($this->claim($requestId, $attemptId) === self::CLAIM_NOOP) {
            return;
        }

        try {
            $this->issue($requestId, $attemptId);
        } catch (TransientIssuanceException $e) {
            throw $e; // same attempt stays `issuing`; queue retries; nothing persisted
        } catch (IssuanceException $e) {
            $this->markFailed($requestId, $attemptId, $e->failureCode(), $e->compensationIncomplete());
        }
    }

    public function markRetriesExhausted(int $requestId, string $attemptId): void
    {
        $this->markFailed($requestId, $attemptId, IssuanceFailureCode::RETRIES_EXHAUSTED, false);
    }

    // --- 1. claim --------------------------------------------------------------

    private function claim(int $requestId, string $attemptId): string
    {
        return $this->shortTransaction(function () use ($requestId, $attemptId): string {
            $request = CertificateRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null || (string) $request->issuance_attempt_id !== $attemptId) {
                return self::CLAIM_NOOP;
            }

            $status = (string) $request->status;

            if ($status === Status::ISSUED && $request->certificate_id !== null) {
                return self::CLAIM_NOOP;
            }

            if (in_array($status, [Status::FAILED, Status::REJECTED, Status::CANCELLED], true)) {
                return self::CLAIM_NOOP;
            }

            if ($status === Status::ISSUING) {
                return self::CLAIM_PROCEED; // same-attempt retry
            }

            if ($status !== Status::APPROVED) {
                return self::CLAIM_NOOP;
            }

            $request->status = Status::ISSUING;
            $request->issuance_started_at = now();
            $request->save();

            $this->recordAudit(self::AUDIT_STARTED, $request, [
                'operation_name' => 'issuance_started',
                'old_status' => Status::APPROVED,
                'new_status' => Status::ISSUING,
            ]);

            return self::CLAIM_PROCEED;
        });
    }

    // --- 2. crypto -------------------------------------------------------------

    private function issue(int $requestId, string $attemptId): void
    {
        $request = CertificateRequest::query()->whereKey($requestId)->first();

        if ($request === null
            || (string) $request->issuance_attempt_id !== $attemptId
            || (string) $request->status !== Status::ISSUING) {
            throw IssuanceException::of(IssuanceFailureCode::ATTEMPT_STALE);
        }

        $userId = (int) $request->user_id;
        $user = User::query()->whereKey($userId)->first();
        if ($user === null) {
            throw IssuanceException::of(IssuanceFailureCode::ATTEMPT_STALE);
        }

        if ($this->hasBlockingCertificate($userId)) {
            throw IssuanceException::of(IssuanceFailureCode::ACTIVE_CERTIFICATE_EXISTS);
        }

        // Resolve the attempt-owned leaf (win or reuse). NO db tx / lock is held
        // during this OpenSSL + filesystem work. A create-race loser gets the
        // winner's artefact with createdByCurrentInvocation=false.
        $artifact = $this->issuance->issueAttemptCertificate($requestId, $attemptId);

        try {
            $this->registrar->register(
                $user,
                $artifact->internalPath(),
                fn (Certificate $certificate, bool $recovered): mixed => $this->complete($requestId, $attemptId, $userId, $certificate),
            );
        } catch (TransientIssuanceException $e) {
            throw $e; // keep the artefact for the retry
        } catch (Throwable $e) {
            if ($this->transient->isTransient($e)) {
                // A transient DB lock/deadlock — even one wrapped by the registrar
                // — must retry the SAME attempt and keep the artefact.
                throw TransientIssuanceException::create();
            }

            // Permanent: clean up ONLY our own artefact, then propagate a neutral
            // terminal code (with the compensation flag when cleanup was unconfirmed).
            throw $this->toPermanent($e, ! $this->issuance->discardOwnedArtefact($artifact));
        }

        // Terminal success: the attempt source artefact is no longer needed.
        $this->issuance->discardOwnedArtefact($artifact);
    }

    private function toPermanent(Throwable $e, bool $cleanupIncomplete): IssuanceException
    {
        $registrarIncomplete = $e instanceof RegistrationException && $e->compensationIncomplete();

        $code = match (true) {
            $e instanceof IssuanceException => $e->failureCode(),
            $e instanceof RegistrationException => $this->mapRegistration($e),
            default => IssuanceFailureCode::FAILED,
        };

        $exception = IssuanceException::of($code);

        if ($cleanupIncomplete || $registrarIncomplete || ($e instanceof IssuanceException && $e->compensationIncomplete())) {
            $exception->markCompensationIncomplete();
        }

        return $exception;
    }

    // --- 3. complete (inside the registrar persistence transaction) ------------

    private function complete(int $requestId, string $attemptId, int $userId, Certificate $certificate): void
    {
        try {
            $request = CertificateRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null) {
                throw IssuanceException::of(IssuanceFailureCode::ATTEMPT_STALE);
            }

            if ((string) $request->status === Status::ISSUED
                && (int) $request->certificate_id === (int) $certificate->id) {
                return; // idempotent: already bound to this exact certificate
            }

            if ((string) $request->issuance_attempt_id !== $attemptId
                || (int) $request->user_id !== $userId
                || (string) $request->status !== Status::ISSUING) {
                throw IssuanceException::of(IssuanceFailureCode::ATTEMPT_STALE);
            }

            if ((string) $certificate->owner_type !== Certificate::OWNER_TYPE_USER
                || (int) $certificate->owner_user_id !== $userId) {
                throw IssuanceException::of(IssuanceFailureCode::COMPLETION_UNSAFE);
            }

            $boundElsewhere = CertificateRequest::query()
                ->where('certificate_id', $certificate->id)
                ->where('id', '!=', $requestId)
                ->exists();
            if ($boundElsewhere) {
                throw IssuanceException::of(IssuanceFailureCode::COMPLETION_UNSAFE);
            }

            $competing = CertificateRequest::query()
                ->where('user_id', $userId)
                ->where('id', '!=', $requestId)
                ->where('status', Status::ISSUED)
                ->whereNotNull('certificate_id')
                ->whereHas('certificate', fn ($q) => $q
                    ->where('is_active', true)
                    ->where('valid_to', '>', now()))
                ->exists();
            if ($competing) {
                throw IssuanceException::of(IssuanceFailureCode::ACTIVE_CERTIFICATE_EXISTS);
            }

            $request->status = Status::ISSUED;
            $request->certificate_id = (int) $certificate->id;
            $request->issued_at = now();
            $request->save();

            $this->recordAudit(self::AUDIT_COMPLETED, $request, [
                'operation_name' => 'issuance_completed',
                'old_status' => Status::ISSUING,
                'new_status' => Status::ISSUED,
                'certificate_id' => (int) $certificate->id,
            ]);
        } catch (IssuanceException|TransientIssuanceException $e) {
            throw $e;
        } catch (QueryException $e) {
            if ($this->transient->isTransient($e)) {
                throw TransientIssuanceException::create();
            }

            throw IssuanceException::of(IssuanceFailureCode::COMPLETION_UNSAFE);
        }
    }

    // --- failure persistence ---------------------------------------------------

    private function markFailed(int $requestId, string $attemptId, string $failureCode, bool $compensationIncomplete): void
    {
        $this->shortTransaction(function () use ($requestId, $attemptId, $failureCode, $compensationIncomplete): void {
            $request = CertificateRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null
                || (string) $request->issuance_attempt_id !== $attemptId
                || (string) $request->status !== Status::ISSUING
                || $request->issuance_started_at === null) {
                return; // never revive a terminal/stale/never-started request
            }

            $request->status = Status::FAILED;
            $request->failed_at = now();
            $request->failure_code = IssuanceFailureCode::normalize($failureCode);
            $request->save();

            $metadata = [
                'operation_name' => 'issuance_failed',
                'old_status' => Status::ISSUING,
                'new_status' => Status::FAILED,
                'failure_code' => $request->failure_code,
            ];
            if ($compensationIncomplete) {
                $metadata['compensation_incomplete'] = true;
            }

            $this->recordAudit(self::AUDIT_FAILED, $request, $metadata, false);
        });
    }

    // --- helpers ---------------------------------------------------------------

    private function hasBlockingCertificate(int $userId): bool
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $userId)
            ->where('is_active', true)
            ->where('valid_to', '>', now())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordAudit(string $event, CertificateRequest $request, array $metadata, bool $success = true): void
    {
        $operatorId = $request->reviewed_by_user_id === null ? null : (int) $request->reviewed_by_user_id;

        $this->audit->record(
            $event,
            null,
            array_merge($metadata, [
                'certificate_request_id' => (int) $request->getKey(),
                'subject_user_id' => (int) $request->user_id,
                'operator_user_id' => $operatorId,
            ]),
            $request,
            $operatorId,
            $success,
        );
    }

    private function mapRegistration(RegistrationException $e): string
    {
        return match ($e->errorCode()) {
            RegistrationException::CERTIFICATE_KEY_MISMATCH,
            RegistrationException::CERTIFICATE_UNTRUSTED,
            RegistrationException::CERTIFICATE_IS_CA,
            RegistrationException::CERTIFICATE_BASIC_CONSTRAINTS_INVALID,
            RegistrationException::CERTIFICATE_KEY_USAGE_INVALID,
            RegistrationException::CERTIFICATE_NOT_YET_VALID,
            RegistrationException::CERTIFICATE_EXPIRED,
            RegistrationException::CERTIFICATE_LOAD_FAILED => IssuanceFailureCode::CERTIFICATE_INVALID,
            RegistrationException::CONFIG_INVALID,
            RegistrationException::PRIVATE_KEY_LOAD_FAILED,
            RegistrationException::ROOT_CA_LOAD_FAILED => IssuanceFailureCode::SIGNING_ROOT_INVALID,
            default => IssuanceFailureCode::COMPLETION_UNSAFE,
        };
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function shortTransaction(callable $callback): mixed
    {
        $connection = (string) (CertificateRequest::query()->getModel()->getConnectionName() ?? config('database.default'));

        try {
            return DB::connection($connection)->transaction($callback);
        } catch (IssuanceException|TransientIssuanceException $e) {
            throw $e;
        } catch (QueryException $e) {
            if ($this->transient->isTransient($e)) {
                throw TransientIssuanceException::create();
            }

            throw IssuanceException::of(IssuanceFailureCode::FAILED);
        }
    }
}
