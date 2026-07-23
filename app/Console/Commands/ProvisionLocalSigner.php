<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CertificateRequests\IssuanceFailureCode;
use App\Exceptions\CertificateRequests\IssuanceException;
use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Signing\LocalSignerCertificateIssuanceService;
use App\Services\Signing\LocalSigningRoot;
use App\Services\Signing\SignerCertificateRegistrar;
use Illuminate\Console\Command;
use Throwable;

/**
 * LOCAL-ONLY provisioning of a complete test signing identity for manual QA:
 * a self-signed test Root CA, a signer certificate, an encrypted private key
 * and a passphrase file — written into an EXTERNAL directory the operator
 * chooses — followed by registration of the signer certificate for a user.
 *
 * BOOTSTRAP / RECOVERY ROLE: this command owns the ONLY bootstrap capability of
 * the local issuance plane. The shared Root CA, its private key, the shared
 * signer key and the passphrase are created once here (or recovered), and every
 * later run — including the dedicated worker — REUSES them. The per-user leaf
 * issuance itself is delegated to the shared
 * {@see LocalSignerCertificateIssuanceService}, so the command and the worker run
 * the identical OpenSSL/serial/profile logic and register through the same
 * {@see SignerCertificateRegistrar}.
 *
 * Single-key model (matches SigningConfig, which configures exactly ONE private
 * key): a later run for a different user REUSES the shared key and only issues
 * that user's own signer certificate (own serial => own fingerprint), so no
 * per-user multi-key architecture is introduced.
 *
 * Safety contract:
 *  - refuses to run outside APP_ENV=local/testing (production always refused);
 *  - writes ONLY inside the canonical project-local signing root, whose identity
 *    is proven STRUCTURALLY by LocalSigningRoot rather than taken from realpath();
 *  - never overwrites: a pre-existing per-user certificate file, or an
 *    already-active signer certificate for the user, aborts before any write;
 *  - the passphrase is generated randomly, written 0600, and NEVER printed;
 *  - on a failed registration only the files THIS run created are deleted
 *    (shared CA/key reused from an earlier run are never removed);
 *  - output contains no private key content, passphrase, or absolute path.
 */
final class ProvisionLocalSigner extends Command
{
    protected $signature = 'signing:provision-local-signer
        {user : Existing numeric user ID (never auto-created)}
        {--directory= : Optional RELATIVE subpath that must ALREADY exist inside the project-local signing root (defaults to the root)}';

    protected $description = 'LOCAL-ONLY: provision a test Root CA + signer identity for a user in the project-local signing root (never in production).';

    public function __construct(
        private readonly LocalSigningRoot $boundary,
        private readonly LocalSignerCertificateIssuanceService $issuance,
    ) {
        parent::__construct();
    }

    public function handle(SignerCertificateRegistrar $registrar): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: this command runs only in the local or testing environment.');

            return self::FAILURE;
        }

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

        if ($this->userHasActiveCertificate((int) $user->id)) {
            $this->error('Refused: the user already has an active signer certificate. Nothing was generated or overwritten.');

            return self::FAILURE;
        }

        $directory = $this->resolveTargetDirectory((string) ($this->option('directory') ?? ''));
        if ($directory === null) {
            return self::FAILURE;
        }

        // This user's own certificate file is never overwritten.
        $userCertName = $this->userCertificateFilename((int) $user->id);
        if (file_exists($directory.DIRECTORY_SEPARATOR.$userCertName)) {
            $this->error('Refused: '.$userCertName.' already exists in the signing root. Existing material is never overwritten.');

            return self::FAILURE;
        }

        $createdFiles = [];

        try {
            $material = $this->resolveMaterial($directory, (int) $user->id, $createdFiles);
        } catch (Throwable) {
            $this->compensate($createdFiles);
            $this->error('Provisioning failed while preparing the local test identity. Files created by this run were removed.');

            return self::FAILURE;
        }

        // Point the RUNTIME config at the just-written material so the existing
        // registrar validates the certificate against this exact key + trust
        // anchor. The .env file itself is never modified by this command.
        config([
            'signing.private_key_path' => $material['keyPath'],
            'signing.passphrase_file_path' => $material['passphrasePath'],
            'signing.root_ca_path' => $material['rootCaPath'],
        ]);

        try {
            $certificate = $registrar->register($user, $material['signerCertPath']);
        } catch (RegistrationException $e) {
            $this->compensate($createdFiles);
            $this->error('Certificate registration failed: '.$e->errorCode().'. Generated files were removed.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->compensate($createdFiles);
            $this->error('Certificate registration failed: UNEXPECTED_ERROR. Generated files were removed.');

            return self::FAILURE;
        }

        $this->info('Result: PROVISIONED');
        $this->line('Certificate ID: '.$certificate->id);
        $this->line('Owner user ID: '.$certificate->owner_user_id);
        $this->line('Fingerprint (SHA-256): '.$certificate->thumbprint_sha256);
        $this->line('Shared Root CA / key: '.($material['reusedSharedMaterial'] ? 'REUSED (existing)' : 'CREATED (first run)'));
        $this->newLine();
        $this->line('Material lives in the project-local signing root (gitignored):');
        $this->line('  '.LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY.', '
            .LocalSignerCertificateIssuanceService::FILE_PASSPHRASE.', '
            .LocalSignerCertificateIssuanceService::FILE_ROOT_CA.', '.$userCertName);
        $this->line('config/signing.php already resolves these by default — no .env change is needed locally.');
        $this->line('The passphrase file content is secret: never commit, print, or share it.');

        return self::SUCCESS;
    }

    private function userCertificateFilename(int $userId): string
    {
        return 'test-signer-cert-user-'.$userId.'.pem';
    }

    private function userHasActiveCertificate(int $userId): bool
    {
        return Certificate::query()
            ->where('owner_type', Certificate::OWNER_TYPE_USER)
            ->where('owner_user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Resolve the write target, which defaults to the canonical project-local
     * signing root.
     *
     * The root itself is NOT trusted from its own realpath(): LocalSigningRoot
     * proves it is the real direct child `local` of the real
     * `<storage>/app/private/signing` directory. Only the canonical root is ever
     * created. --directory is a RELATIVE subpath that must ALREADY exist in full.
     */
    private function resolveTargetDirectory(string $raw): ?string
    {
        if (! $this->boundary->matchesExpectedPath(config('signing.local_material_path'))) {
            $this->error('Refused: the project-local signing root is not configured to its expected location.');

            return null;
        }

        $raw = trim($raw);

        // Checked before anything is created, so a refused run never writes.
        if ($raw !== '' && ! $this->isSafeRelativeSubpath($raw)) {
            return null;
        }

        $root = $this->boundary->ensure();
        if ($root === null) {
            $this->error('Refused: the project-local signing root must be inside the project-local signing root and must not be a symlink, junction, or reparse point.');

            return null;
        }

        if (! is_writable($root)) {
            $this->error('Refused: the project-local signing root is not usable.');

            return null;
        }

        if ($raw === '') {
            return $root;
        }

        return $this->resolveExistingSubdirectory($root, $raw);
    }

    /**
     * Walk the requested subpath one segment at a time, creating NOTHING.
     */
    private function resolveExistingSubdirectory(string $root, string $raw): ?string
    {
        $current = $root;

        foreach (explode('/', str_replace('\\', '/', $raw)) as $segment) {
            $candidate = $current.DIRECTORY_SEPARATOR.$segment;

            if (! file_exists($candidate)) {
                $this->error('Refused: the target directory must already exist inside the project-local signing root; --directory never creates it.');

                return null;
            }

            if (! is_dir($candidate)) {
                $this->error('Refused: the target directory must be an existing directory.');

                return null;
            }

            $verified = $this->boundary->verifyDirectChild($candidate, $current, $segment);
            if ($verified === null || ! $this->boundary->isWithin($verified, $root)) {
                $this->error('Refused: the target directory must be inside the project-local signing root.');

                return null;
            }

            $current = $verified;
        }

        if (! is_writable($current)) {
            $this->error('Refused: the target directory is not usable.');

            return null;
        }

        return $current;
    }

    /**
     * --directory must be a plain relative subpath: no absolute or UNC path, no
     * traversal, no empty segment. Purely lexical — it touches no filesystem.
     */
    private function isSafeRelativeSubpath(string $raw): bool
    {
        if ($this->isAbsolutePath($raw)) {
            $this->error('Refused: the target directory must be inside the project-local signing root, given as a relative subpath.');

            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $raw)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $this->error('Refused: the target directory must not contain traversal segments.');

                return false;
            }
        }

        return true;
    }

    /**
     * Delegate the shared material (bootstrap or reuse) and the per-user leaf
     * generation to {@see LocalSignerCertificateIssuanceService} — the very same
     * engine the worker uses — then write THIS user's certificate file. Only
     * files this run creates enter $createdFiles.
     *
     * @param  list<string>  $createdFiles
     * @return array{rootCaPath: string, signerCertPath: string, keyPath: string, passphrasePath: string, reusedSharedMaterial: bool}
     */
    private function resolveMaterial(string $directory, int $userId, array &$createdFiles): array
    {
        $rootCaPath = $directory.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_ROOT_CA;
        $keyPath = $directory.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_SIGNER_KEY;
        $passphrasePath = $directory.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_PASSPHRASE;
        $userCertPath = $directory.DIRECTORY_SEPARATOR.$this->userCertificateFilename($userId);
        $genericPath = $directory.DIRECTORY_SEPARATOR.LocalSignerCertificateIssuanceService::FILE_SIGNER_CERT;

        $material = $this->issuance->bootstrapOrLoadMaterial($directory, $createdFiles);

        $signerPem = $this->issuance->generateLeafPem($material);

        $this->writeChecked($userCertPath, $signerPem, $createdFiles);

        // Keep the generic name populated on the very first run only; it is never
        // overwritten afterwards.
        if (! file_exists($genericPath)) {
            $this->writeChecked($genericPath, $signerPem, $createdFiles);
        }

        return [
            'rootCaPath' => $rootCaPath,
            'signerCertPath' => $userCertPath,
            'keyPath' => $keyPath,
            'passphrasePath' => $passphrasePath,
            'reusedSharedMaterial' => ! $material->freshlyCreated,
        ];
    }

    /**
     * @param  list<string>  $createdFiles
     */
    private function writeChecked(string $path, string $contents, array &$createdFiles): void
    {
        // Exclusive create: never overwrite a file this run did not create.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        $createdFiles[] = $path;
        $written = fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents)) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }
    }

    /**
     * Delete ONLY the files this run itself created (exclusive-create proven).
     *
     * @param  list<string>  $createdFiles
     */
    private function compensate(array $createdFiles): void
    {
        foreach ($createdFiles as $path) {
            @unlink($path);
            if (file_exists($path)) {
                $this->error('Cleanup: INCOMPLETE');
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/') {
            return true;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return true;
        }

        return str_starts_with($path, '\\\\') || str_starts_with($path, '//');
    }
}
