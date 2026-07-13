<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\User;
use App\Services\Signing\SignerCertificateRegistrar;
use Illuminate\Console\Command;
use Throwable;

/**
 * Local-only command to register an X.509 signer certificate for an existing
 * user. It uses the configured private key, passphrase file and Root CA, and
 * prints only safe fields — never a path, passphrase, PEM, or raw OpenSSL error.
 * No web route or UI is involved.
 */
final class RegisterUserCertificate extends Command
{
    protected $signature = 'signing:register-user-certificate
        {user : Existing numeric user ID (never auto-created)}
        {--certificate= : Path to the public signer certificate (PEM)}';

    protected $description = 'Register a local X.509 signer certificate for an existing user (no CMS signing).';

    public function handle(SignerCertificateRegistrar $registrar): int
    {
        $userArgument = (string) $this->argument('user');
        if (preg_match('/^\d+$/', $userArgument) !== 1) {
            $this->error('The user argument must be an existing numeric user ID.');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $userArgument);
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $certificatePath = (string) ($this->option('certificate') ?? '');
        if (trim($certificatePath) === '') {
            $this->error('The --certificate path to the public signer certificate is required.');

            return self::FAILURE;
        }

        try {
            $certificate = $registrar->register($user, $certificatePath);
        } catch (RegistrationException $e) {
            $this->error('Certificate registration failed: '.$e->errorCode());
            if ($e->compensationIncomplete()) {
                $this->error('Cleanup: INCOMPLETE');
            }

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Certificate registration failed: UNEXPECTED_ERROR');

            return self::FAILURE;
        }

        $this->info('Result: '.($certificate->wasRecentlyCreated ? 'REGISTERED' : 'IDEMPOTENT'));
        $this->line('Certificate ID: '.$certificate->id);
        $this->line('Owner user ID: '.$certificate->owner_user_id);
        $this->line('Fingerprint (SHA-256): '.$certificate->thumbprint_sha256);
        $this->line('Valid from: '.$certificate->valid_from?->toIso8601String());
        $this->line('Valid to: '.$certificate->valid_to?->toIso8601String());

        return self::SUCCESS;
    }
}
