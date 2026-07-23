<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertificateRequest>
 */
class CertificateRequestFactory extends Factory
{
    protected $model = CertificateRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => CertificateRequest::STATUS_PENDING,
            'request_note' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'operator_note' => null,
            'approved_at' => null,
            'issuance_attempt_id' => null,
            'issuance_started_at' => null,
            'issued_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'failure_code' => null,
            'certificate_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => CertificateRequest::STATUS_PENDING]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => CertificateRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function rejected(?User $operator = null): static
    {
        return $this->state(fn (): array => [
            'status' => CertificateRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $operator?->id ?? User::factory(),
            'reviewed_at' => now(),
            'operator_note' => 'Rejected for a documented local test reason.',
        ]);
    }

    public function approved(?User $operator = null): static
    {
        return $this->state(fn (): array => [
            'status' => CertificateRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => $operator?->id ?? User::factory(),
            'reviewed_at' => now(),
            'approved_at' => now(),
            'issuance_attempt_id' => (string) Str::uuid(),
        ]);
    }

    public function issuing(?User $operator = null): static
    {
        return $this->approved($operator)->state(fn (): array => [
            'status' => CertificateRequest::STATUS_ISSUING,
            'issuance_started_at' => now(),
        ]);
    }

    public function failed(?User $operator = null, string $failureCode = 'ISSUANCE_RETRIES_EXHAUSTED'): static
    {
        return $this->approved($operator)->state(fn (): array => [
            'status' => CertificateRequest::STATUS_FAILED,
            'issuance_started_at' => now(),
            'failed_at' => now(),
            'failure_code' => $failureCode,
        ]);
    }
}
