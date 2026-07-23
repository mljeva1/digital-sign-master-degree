<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Models\User;
use App\Services\CertificateRequests\CertificateOperatorAuthority;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL/TESTING ONLY — grant the certificate_operator role to an EXISTING user.
 *
 * Deliberately minimal: it never creates a user, never sets or prints a
 * password, and never logs anyone in. The operator is an ordinary registered
 * user who has been given one extra role, so bootstrapping an operator can never
 * become a way to mint a privileged account from source.
 */
class GrantCertificateOperatorRole extends Command
{
    protected $signature = 'certificate-operator:grant
        {user : Existing numeric user ID (never auto-created)}
        {--revoke : Remove the role instead of granting it}';

    protected $description = 'LOCAL-ONLY: grant (or revoke) the certificate_operator role for an existing user.';

    public function __construct(private readonly CertificateOperatorAuthority $authority)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: this command runs only in the local or testing environment.');

            return self::FAILURE;
        }

        $argument = (string) $this->argument('user');
        if (preg_match('/^\d+$/', $argument) !== 1) {
            $this->error('The user argument must be an existing numeric user ID.');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $argument);
        if ($user === null) {
            $this->error('User not found. This command never creates a user.');

            return self::FAILURE;
        }

        if ($this->authority->operatorRoleId() === null) {
            $this->error('Role certificate_operator is missing. Run the RoleSeeder first.');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        $userId = (int) $user->id;

        // Same authority protocol as approve/reject: lock the operator's user
        // row, then their exact pivot slot FOR UPDATE, and only then mutate. That
        // is what makes a revoke and an in-flight approval serialize against each
        // other instead of interleaving.
        try {
            $changed = DB::transaction(function () use ($userId, $revoke): bool {
                $this->authority->lockUserOrFail($userId, WorkflowException::OPERATOR_UNAVAILABLE);

                $slot = $this->authority->lockMembershipSlot($userId);
                $roleId = $slot['role_id'];
                $membership = $slot['membership'];

                if ($revoke) {
                    if ($membership === null) {
                        return false;
                    }

                    DB::table('role_user')->where('user_id', $userId)->where('role_id', $roleId)->delete();

                    return true;
                }

                if ($membership !== null) {
                    return false; // idempotent: no duplicate pivot row
                }

                DB::table('role_user')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            });
        } catch (WorkflowException $e) {
            $this->error('Refused: '.$e->errorCode());

            return self::FAILURE;
        }

        if ($revoke) {
            $this->info('Result: REVOKED');
            $this->line('User ID: '.$userId.' no longer holds certificate_operator.'.($changed ? '' : ' (was not held)'));

            return self::SUCCESS;
        }

        $this->info('Result: GRANTED');
        $this->line('User ID: '.$userId.' now holds certificate_operator.'.($changed ? '' : ' (already held)'));
        $this->line('No user was created and no password was set or displayed.');

        return self::SUCCESS;
    }
}
