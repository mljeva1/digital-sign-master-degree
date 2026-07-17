<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\Certificate;
use App\Models\User;
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
 * Single-key model (matches M10 SigningConfig, which configures exactly ONE
 * private key): the Root CA, private key and passphrase are SHARED and created
 * once. A later run for a different user REUSES them and only issues that user's
 * own signer certificate (own serial => own fingerprint, so the global unique
 * thumbprint still holds) from the same key. Every issued certificate therefore
 * matches the one configured private key, so no per-user multi-key architecture
 * is introduced.
 *
 * Safety contract:
 *  - refuses to run outside APP_ENV=local/testing (production always refused);
 *  - writes ONLY inside the canonical project-local signing root, whose identity
 *    is proven STRUCTURALLY by LocalSigningRoot (the real direct child `local` of
 *    the real `<storage>/app/private/signing`) rather than taken from its own
 *    realpath(); a junction/symlink AT the root, a repointed
 *    `signing.local_material_path`, an external directory, a traversal segment,
 *    and a symlink/junction escape below the root are all refused;
 *  - never overwrites: a pre-existing per-user certificate file, or an
 *    already-active signer certificate for the user, aborts before any write;
 *  - the passphrase is generated randomly, written 0600, and NEVER printed;
 *  - on a failed registration only the files THIS run created are deleted
 *    (shared CA/key reused from an earlier run are never removed);
 *  - output contains no private key content, passphrase, or absolute path —
 *    only file names and the safe next steps.
 */
final class ProvisionLocalSigner extends Command
{
    protected $signature = 'signing:provision-local-signer
        {user : Existing numeric user ID (never auto-created)}
        {--directory= : Optional RELATIVE subpath that must ALREADY exist inside the project-local signing root (defaults to the root)}';

    protected $description = 'LOCAL-ONLY: provision a test Root CA + signer identity for a user in the project-local signing root (never in production).';

    private const FILE_ROOT_CA = 'test-root-ca.pem';

    /**
     * Provisioning-only local test CA key: required to issue a further user's
     * certificate from the same trust anchor. Never referenced by
     * config/signing.php and never loaded by the signing runtime.
     */
    private const FILE_ROOT_CA_KEY = 'test-root-ca-key.pem';

    private const FILE_SIGNER_CERT = 'test-signer-cert.pem';

    private const FILE_SIGNER_KEY = 'test-signer-key.pem';

    private const FILE_PASSPHRASE = 'test-signer-passphrase.txt';

    private const OPENSSL_CNF = <<<'CNF'
[req]
distinguished_name = req_dn
prompt = no

[req_dn]
CN = DSMD Local Test

[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash

[v3_signer]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
CNF;

    public function __construct(private readonly LocalSigningRoot $boundary)
    {
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
        $this->line('  '.self::FILE_SIGNER_KEY.', '.self::FILE_PASSPHRASE.', '.self::FILE_ROOT_CA.', '.$userCertName);
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
     * `<storage>/app/private/signing` directory, so a junction/reparse point at
     * the root cannot promote an external target into the allowed boundary. The
     * configured value is additionally cross-checked against that expectation,
     * so repointing the config cannot move the boundary either.
     *
     * Only the canonical root is ever created. --directory is a RELATIVE subpath
     * that must ALREADY exist in full: it is walked segment by segment and never
     * created, so an absolute/UNC path, a traversal segment, a missing segment,
     * and any symlink/junction escape are all refused before a single byte is
     * written. No absolute path is echoed back.
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
     *
     * Every segment must already exist, be a real directory, and be the real
     * direct child of the previous canonical segment. A missing segment fails
     * closed BEFORE any filesystem or DB write — which is the whole point:
     * a recursive mkdir() here would follow a junction segment and materialise
     * a directory outside the canonical root before the containment check could
     * refuse it.
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

            // Same structural rule as the root itself, applied per segment: a
            // junction/symlink/reparse point makes the canonical path diverge
            // from its expected parent, and is refused before anything is used.
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
     * Resolve the shared Root CA + private key (reusing them when a previous run
     * already created them) and issue THIS user's own signer certificate from
     * that same key. Only files this run creates enter $createdFiles, so
     * compensation can never delete shared material an earlier run owns.
     *
     * @param  list<string>  $createdFiles
     * @return array{rootCaPath: string, signerCertPath: string, keyPath: string, passphrasePath: string, reusedSharedMaterial: bool}
     */
    private function resolveMaterial(string $directory, int $userId, array &$createdFiles): array
    {
        $rootCaPath = $directory.DIRECTORY_SEPARATOR.self::FILE_ROOT_CA;
        $caKeyPath = $directory.DIRECTORY_SEPARATOR.self::FILE_ROOT_CA_KEY;
        $keyPath = $directory.DIRECTORY_SEPARATOR.self::FILE_SIGNER_KEY;
        $passphrasePath = $directory.DIRECTORY_SEPARATOR.self::FILE_PASSPHRASE;
        $userCertPath = $directory.DIRECTORY_SEPARATOR.$this->userCertificateFilename($userId);

        $sharedExists = is_file($rootCaPath) && is_file($keyPath) && is_file($passphrasePath);

        // A throwaway OpenSSL config is required for extension profiles; it
        // contains no secret and is removed best-effort at the end.
        $cnfPath = $directory.DIRECTORY_SEPARATOR.'openssl-'.bin2hex(random_bytes(4)).'.cnf';
        $this->writeChecked($cnfPath, self::OPENSSL_CNF, $createdFiles);

        try {
            if ($sharedExists) {
                // REUSE: the single configured key stays authoritative, so every
                // user's certificate keeps matching it.
                [$caCert, $caKey, $signerKey, $passphrase] = $this->loadSharedMaterial($rootCaPath, $caKeyPath, $keyPath, $passphrasePath);
            } else {
                [$caCert, $caKey, $signerKey, $passphrase] = $this->createSharedMaterial($cnfPath, $rootCaPath, $caKeyPath, $keyPath, $passphrasePath, $createdFiles);
            }

            $signerCsr = openssl_csr_new(['commonName' => 'DSMD Local Test Signer'], $signerKey, [
                'config' => $cnfPath, 'digest_alg' => 'sha256',
            ]);
            $signerCert = $signerCsr === false ? false : openssl_csr_sign($signerCsr, $caCert, $caKey, 825, [
                'config' => $cnfPath, 'x509_extensions' => 'v3_signer', 'digest_alg' => 'sha256',
            ], random_int(1, PHP_INT_MAX));
            if ($signerCert === false) {
                throw new \RuntimeException('Signer certificate generation failed.');
            }

            $signerPem = '';
            if (openssl_x509_export($signerCert, $signerPem) !== true) {
                throw new \RuntimeException('Certificate export failed.');
            }

            $this->writeChecked($userCertPath, $signerPem, $createdFiles);

            // Keep the generic name populated on the very first run only; it is
            // never overwritten afterwards.
            $genericPath = $directory.DIRECTORY_SEPARATOR.self::FILE_SIGNER_CERT;
            if (! file_exists($genericPath)) {
                $this->writeChecked($genericPath, $signerPem, $createdFiles);
            }

            return [
                'rootCaPath' => $rootCaPath,
                'signerCertPath' => $userCertPath,
                'keyPath' => $keyPath,
                'passphrasePath' => $passphrasePath,
                'reusedSharedMaterial' => $sharedExists,
            ];
        } finally {
            // The throwaway config carries no secret; drop it from disk and
            // from the compensation list when the delete is confirmed.
            if (@unlink($cnfPath)) {
                $createdFiles = array_values(array_diff($createdFiles, [$cnfPath]));
            }
        }
    }

    /**
     * Load the shared Root CA + signer key an earlier run created, so a second
     * user is issued from the SAME trust anchor and the SAME private key that
     * SigningConfig already points at.
     *
     * Issuing a further certificate requires the local test CA's own key, which
     * this command keeps as a provisioning-only artefact next to the material.
     * It is never referenced by config/signing.php and never loaded by signing —
     * only here, at provisioning time.
     *
     * @return array{0: \OpenSSLCertificate, 1: \OpenSSLAsymmetricKey, 2: \OpenSSLAsymmetricKey, 3: string}
     */
    private function loadSharedMaterial(string $rootCaPath, string $caKeyPath, string $keyPath, string $passphrasePath): array
    {
        if (! is_file($caKeyPath)) {
            throw new \RuntimeException('The local test CA key is missing; this signing root cannot issue another certificate.');
        }

        $passphrase = $this->stripSingleTrailingLineEnding((string) file_get_contents($passphrasePath));

        $signerKey = openssl_pkey_get_private((string) file_get_contents($keyPath), $passphrase);
        if ($signerKey === false) {
            throw new \RuntimeException('Existing signer key could not be loaded.');
        }

        $caKey = openssl_pkey_get_private((string) file_get_contents($caKeyPath), $passphrase);
        if ($caKey === false) {
            throw new \RuntimeException('Existing local test CA key could not be loaded.');
        }

        $caCert = openssl_x509_read((string) file_get_contents($rootCaPath));
        if ($caCert === false) {
            throw new \RuntimeException('Existing Root CA could not be read.');
        }

        return [$caCert, $caKey, $signerKey, $passphrase];
    }

    /**
     * @param  list<string>  $createdFiles
     * @return array{0: \OpenSSLCertificate, 1: \OpenSSLAsymmetricKey, 2: \OpenSSLAsymmetricKey, 3: string}
     */
    private function createSharedMaterial(string $cnfPath, string $rootCaPath, string $caKeyPath, string $keyPath, string $passphrasePath, array &$createdFiles): array
    {
        $caKey = $this->newKey($cnfPath);
        $caCsr = openssl_csr_new(['commonName' => 'DSMD Local Test Root CA'], $caKey, [
            'config' => $cnfPath, 'digest_alg' => 'sha256',
        ]);
        $caCert = $caCsr === false ? false : openssl_csr_sign($caCsr, null, $caKey, 3650, [
            'config' => $cnfPath, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256',
        ], random_int(1, PHP_INT_MAX));
        if ($caCert === false) {
            throw new \RuntimeException('Root CA generation failed.');
        }

        $signerKey = $this->newKey($cnfPath);

        $caPem = '';
        if (openssl_x509_export($caCert, $caPem) !== true) {
            throw new \RuntimeException('Root CA export failed.');
        }

        $passphrase = bin2hex(random_bytes(24));
        $encryptedKeyPem = '';
        $encryptedCaKeyPem = '';
        if (openssl_pkey_export($signerKey, $encryptedKeyPem, $passphrase, ['config' => $cnfPath]) !== true
            || openssl_pkey_export($caKey, $encryptedCaKeyPem, $passphrase, ['config' => $cnfPath]) !== true) {
            throw new \RuntimeException('Private key export failed.');
        }

        $this->writeChecked($rootCaPath, $caPem, $createdFiles);
        $this->writeChecked($keyPath, $encryptedKeyPem, $createdFiles);
        $this->writeChecked($passphrasePath, $passphrase, $createdFiles);
        // Provisioning-only: needed to issue a further user's certificate from
        // the same trust anchor. Never configured, never read by signing.
        $this->writeChecked($caKeyPath, $encryptedCaKeyPem, $createdFiles);
        @chmod($keyPath, 0600);
        @chmod($passphrasePath, 0600);
        @chmod($caKeyPath, 0600);

        return [$caCert, $caKey, $signerKey, $passphrase];
    }

    private function stripSingleTrailingLineEnding(string $value): string
    {
        if (str_ends_with($value, "\r\n")) {
            return substr($value, 0, -2);
        }

        if (str_ends_with($value, "\n") || str_ends_with($value, "\r")) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function newKey(string $cnfPath): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $cnfPath,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Key generation failed.');
        }

        return $key;
    }

    /**
     * @param  list<string>  $createdFiles
     */
    private function writeChecked(string $path, string $contents, array &$createdFiles): void
    {
        // Exclusive create: never overwrite a file this run did not create.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Refusing to overwrite an existing file.');
        }

        $createdFiles[] = $path;
        $written = fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents)) {
            throw new \RuntimeException('Incomplete write of generated material.');
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
