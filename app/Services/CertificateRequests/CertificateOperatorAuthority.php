<?php

declare(strict_types=1);

namespace App\Services\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative locking protocol for certificate_operator membership.
 *
 * WHY THIS EXISTS
 * A plain `->exists()` role check is only a snapshot: a concurrent revoke can
 * commit between the check and the approval commit, so a request could be
 * approved by someone whose role was already removed. Membership must therefore
 * be re-proven while the pivot row is LOCKED, and the revoke path must contend
 * for that same lock.
 *
 * LOCK ORDER (deadlock-safe, used identically by every caller):
 *   1. both `users` rows, locked in ASCENDING numeric id order — NOT semantic
 *      "subject then operator" order, which two concurrent reviews could take in
 *      opposite directions and deadlock;
 *   2. the `certificate_requests` row (review paths only);
 *   3. the exact `role_user` pivot row for (operator, certificate_operator),
 *      locked FOR UPDATE;
 *   4. only then is membership re-confirmed and the domain decision made.
 *
 * Grant/revoke follows the same protocol on its subset (user row → pivot row),
 * so review and revoke serialize against each other on the very same rows.
 *
 * Nothing here creates users, changes unrelated role assignments, takes a table
 * lock, or locks every operator/role.
 */
class CertificateOperatorAuthority
{
    public const OPERATOR_ROLE = 'certificate_operator';

    /**
     * Lock the two participating user rows in ascending id order.
     *
     * @return array{subject: User, operator: User}
     */
    public function lockParticipants(int $subjectId, int $operatorId): array
    {
        $ids = array_unique([$subjectId, $operatorId]);
        sort($ids, SORT_NUMERIC);

        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = $this->lockUserOrFail(
                $id,
                $id === $subjectId
                    ? WorkflowException::SUBJECT_UNAVAILABLE
                    : WorkflowException::OPERATOR_UNAVAILABLE
            );
        }

        return [
            'subject' => $locked[$subjectId],
            'operator' => $locked[$operatorId],
        ];
    }

    public function lockUserOrFail(int $userId, string $failureCode): User
    {
        $user = User::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();

        if ($user === null || $user->deleted_at !== null) {
            throw WorkflowException::of($failureCode);
        }

        return $user;
    }

    /**
     * Re-prove operator membership with the exact pivot row LOCKED.
     *
     * This is the authoritative check: a concurrent revoke either already
     * committed (membership gone → refuse) or must wait for this lock, so it can
     * never slip in between the check and the caller's commit.
     *
     * @throws WorkflowException when the role is missing or membership is absent
     */
    public function assertLockedOperatorMembership(int $operatorId): void
    {
        $roleId = $this->operatorRoleId();

        if ($roleId === null) {
            throw WorkflowException::of(WorkflowException::OPERATOR_NOT_AUTHORIZED);
        }

        // Lock ONLY this operator's exact pivot row for this exact role.
        $membership = DB::table('role_user')
            ->where('user_id', $operatorId)
            ->where('role_id', $roleId)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw WorkflowException::of(WorkflowException::OPERATOR_NOT_AUTHORIZED);
        }
    }

    /**
     * Lock the operator's pivot slot for a grant/revoke mutation.
     *
     * Uses the same user-row → pivot-row order as the review path, so a grant or
     * revoke and an in-flight approval contend on identical rows.
     *
     * @return array{role_id: int, membership: object|null}
     */
    public function lockMembershipSlot(int $operatorId): array
    {
        $roleId = $this->operatorRoleId();

        if ($roleId === null) {
            throw WorkflowException::of(WorkflowException::OPERATOR_NOT_AUTHORIZED);
        }

        $membership = DB::table('role_user')
            ->where('user_id', $operatorId)
            ->where('role_id', $roleId)
            ->lockForUpdate()
            ->first();

        return ['role_id' => $roleId, 'membership' => $membership];
    }

    public function operatorRoleId(): ?int
    {
        $id = Role::query()->where('name', self::OPERATOR_ROLE)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Unlocked convenience read — never the final authority for a mutation. */
    public function hasOperatorRole(User $user): bool
    {
        return $user->roles()->where('name', self::OPERATOR_ROLE)->exists();
    }
}
