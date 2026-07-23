<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    /**
     * @var list<string>
     */
    private const SAFE_KEYS = [
        'route_name',
        'operation_name',
        'public_verification_token_created',
    ];

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_PARTS = [
        'oib',
        'seller',
        'buyer',
        'name',
        'first_name',
        'last_name',
        'address',
        'city',
        'postal_code',
        'country_code',
        'phone',
        'price',
        'vin',
        'vehicle',
        'token',
        'filled_data_snapshot',
        'snapshot',
        'pdf_content',
        'path',
        'subject_dn',
        'issuer_dn',
        'serial_number',
        'certificate_subject',
        'certificate_issuer',
        'certificate_serial',
    ];

    /**
     * @param  int|null  $actorUserId  Explicit TRUSTED actor id, already resolved by
     *                                 the caller from the authentication boundary.
     *                                 Supply it whenever the caller has its own
     *                                 resolved actor (e.g. signing), so the audit
     *                                 actor and the domain actor cannot diverge.
     *                                 When null the authenticated guard is used, so
     *                                 existing call-sites keep their behaviour.
     */
    public function record(
        string $event,
        ?Contract $contract = null,
        array $metadata = [],
        ?Model $auditable = null,
        ?int $actorUserId = null,
        bool $success = true
    ): AuditEvent {
        $entity = $auditable ?? $contract;

        if ($entity === null || $entity->getKey() === null) {
            throw new InvalidArgumentException('Audit event mora imati spremljeni auditable entitet.');
        }

        return AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_user_id' => $actorUserId ?? auth()->id(),
            'action' => $event,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'success' => $success,
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'user_agent' => app()->bound('request') ? request()->userAgent() : null,
            'metadata' => $this->sanitizeMetadata($metadata),
        ]);
    }

    public function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = self::REDACTED;

                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitizeMetadata($value)
                : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', ' '], '_', $key));

        if (in_array($normalizedKey, self::SAFE_KEYS, true)
            || str_ends_with($normalizedKey, 'sha256')) {
            return false;
        }

        foreach (self::SENSITIVE_KEY_PARTS as $sensitiveKeyPart) {
            if ($normalizedKey === $sensitiveKeyPart
                || str_contains($normalizedKey, $sensitiveKeyPart.'_')
                || str_ends_with($normalizedKey, '_'.$sensitiveKeyPart)) {
                return true;
            }
        }

        return false;
    }
}
