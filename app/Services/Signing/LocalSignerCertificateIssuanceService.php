<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Domain\CertificateRequests\IssuanceFailureCode;
use App\Exceptions\CertificateRequests\IssuanceException;
use App\Exceptions\CertificateRequests\TransientIssuanceException;
use App\Exceptions\Signing\DetachedCmsException;
use App\Exceptions\Signing\SignerCertificateProfileException;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single shared per-user X.509 leaf-issuance engine, used identically by the
 * local/testing bootstrap Artisan command and by the dedicated certificate
 * issuance worker. All OpenSSL, serial, certificate-profile and shared-material
 * rules live here exactly once, so the two callers can never drift apart.
 *
 * TWO SEPARATE CAPABILITIES
 *
 *  1. BOOTSTRAP / RECOVERY — {@see bootstrapOrLoadMaterial()}. Allowed ONLY to
 *     the local/testing provisioning command. It may CREATE the shared Root CA
 *     certificate, the Root CA private key, the shared signer private key and the
 *     passphrase file when absent, or load them when present.
 *
 *  2. WORKER ISSUANCE — {@see issueAttemptCertificate()}. The dedicated worker's
 *     only entry point. It NEVER bootstraps a Root CA, never creates or rotates
 *     the shared signer key, never creates or changes the passphrase, and never
 *     overwrites existing shared PKI material. It loads an already-existing,
 *     structurally-confirmed signing root and issues exactly one new per-user
 *     leaf certificate — failing closed if the root is missing/unsafe/incomplete.
 *
 * P2-2 (create-only ownership): the attempt artefact is claimed with an exclusive
 * create. Only the invocation that WON the create may ever clean it up
 * ({@see discardOwnedArtefact()}); a create-race loser re-validates and REUSES the
 * winner's certificate and must never delete it.
 *
 * P2-4 (per-file safety): every shared-material file is individually re-proven
 * (canonical parent inside the verified root, no symlink/junction/reparse, real
 * regular file confirmed via lstat AND post-open fstat) immediately before its
 * bytes are read.
 *
 * ROOT CA PRIVATE-KEY BOUNDARY: the Root CA key is read only inside this service,
 * only from the structurally-verified local signing root, never from any HTTP or
 * job payload. No path, PEM, key, or passphrase ever leaves this class in an
 * exception, return value, log, or audit event — failures surface as neutral
 * allow-listed {@see IssuanceFailureCode} values, and transient races surface as
 * {@see TransientIssuanceException}.
 */
class LocalSignerCertificateIssuanceService
{
    public const FILE_ROOT_CA = 'test-root-ca.pem';

    public const FILE_ROOT_CA_KEY = 'test-root-ca-key.pem';

    public const FILE_SIGNER_KEY = 'test-signer-key.pem';

    public const FILE_PASSPHRASE = 'test-signer-passphrase.txt';

    public const FILE_SIGNER_CERT = 'test-signer-cert.pem';

    /** Worker scratch subdirectory (a direct child of the verified root). */
    public const ISSUANCE_SUBDIR = 'issuance';

    /** @var list<string> the only shared-material basenames that may ever be read */
    private const SHARED_MATERIAL_FILES = [
        self::FILE_ROOT_CA,
        self::FILE_ROOT_CA_KEY,
        self::FILE_SIGNER_KEY,
        self::FILE_PASSPHRASE,
    ];

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

    private readonly SignerCertificateProfileValidator $profileValidator;

    public function __construct(
        private readonly LocalSigningRoot $boundary,
        private readonly SigningTempWorkspace $workspace = new SigningTempWorkspace,
        ?SignerCertificateProfileValidator $profileValidator = null,
    ) {
        $this->profileValidator = $profileValidator ?? new SignerCertificateProfileValidator;
    }

    /**
     * WORKER path: resolve the attempt-owned leaf certificate for this exact
     * (requestId, attemptId) and return an ownership-carrying artefact.
     *
     * The invocation that wins the exclusive create writes the leaf and receives
     * `createdByCurrentInvocation = true`. An invocation that loses the create
     * race re-validates the winner's certificate (safe file, leaf profile, shared
     * signer key-match, local Root CA trust) and receives it with
     * `createdByCurrentInvocation = false`; it never overwrites or deletes it. A
     * file that exists but is not yet fully written / not yet parsable surfaces as
     * a transient retry (never a delete, never a terminal failure).
     *
     * @throws IssuanceException permanent, allow-listed failure
     * @throws TransientIssuanceException safely retryable race/IO condition
     */
    public function issueAttemptCertificate(int $requestId, string $attemptId): AttemptCertificateArtifact
    {
        if ($requestId <= 0 || Str::isUuid($attemptId) !== true) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        $root = $this->verifiedExistingRoot();
        $issuanceDir = $this->resolveIssuanceDirectory($root);
        $target = $issuanceDir.DIRECTORY_SEPARATOR.'req-'.$requestId.'-att-'.$attemptId.'.pem';

        // Exclusive-create claim: at most one invocation can win.
        $handle = @fopen($target, 'xb');
        if ($handle !== false) {
            return $this->completeWinnerArtifact($handle, $target, $issuanceDir, $root, $requestId, $attemptId);
        }

        // The create failed. If the file already exists, this invocation LOST the
        // race and must reuse (never delete) the winner's artefact.
        if (file_exists($target)) {
            return $this->resolveExistingArtifact($target, $issuanceDir, $root, $requestId, $attemptId);
        }

        // Create failed but nothing is there — an environment/permission problem.
        throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
    }

    /**
     * Ownership-verified cleanup: delete ONLY an artefact this exact invocation
     * created, and only after re-proving the path is still the same safe,
     * contained, unchanged regular file it wrote.
     *
     * Returns true when there is nothing left to do (deleted, not ours, or already
     * gone/changed) and false ONLY when an artefact WE created could not be
     * confirmed removed — the single case the caller records as compensation
     * incomplete. A foreign artefact is never touched and never sets that flag.
     */
    public function discardOwnedArtefact(AttemptCertificateArtifact $artifact): bool
    {
        if (! $artifact->createdByCurrentInvocation) {
            return true; // never delete an artefact another invocation owns
        }

        $root = $this->boundary->verified();
        if ($root === null) {
            return false; // we created it but cannot prove containment → orphan
        }

        // D1: NEVER trust the stored path on its own. Re-derive the exact expected
        // path from the immutable (requestId, attemptId) and refuse if the stored
        // path, its basename, or its canonical parent disagree — so a tampered
        // path can never target a foreign file.
        $expectedDir = $root.DIRECTORY_SEPARATOR.self::ISSUANCE_SUBDIR;
        $expected = $expectedDir.DIRECTORY_SEPARATOR.$artifact->expectedBasename();

        if (! $this->pathEquals($artifact->internalPath(), $expected)
            || basename($expected) !== $artifact->expectedBasename()) {
            return true; // identity mismatch → not our artefact to delete
        }

        try {
            $this->assertSafeContainedFile($expected, $root);
            if (! $this->pathEquals(dirname((string) realpath($expected)), $expectedDir)) {
                return true; // canonical parent is not the issuance dir
            }
        } catch (Throwable) {
            return true; // no longer a safe, contained file we can prove is ours
        }

        // Re-open and re-prove identity from the OPEN handle immediately before the
        // delete: a regular file whose exact bytes still match what we wrote.
        $handle = @fopen($expected, 'rb');
        if ($handle === false) {
            return true; // already gone / unreadable
        }

        try {
            $stat = @fstat($handle);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                return true; // not a regular file anymore → do not delete
            }

            $current = stream_get_contents($handle);
            if (! is_string($current) || ! hash_equals($artifact->sha256(), hash('sha256', $current))) {
                return true; // content changed → not the artefact we created
            }
        } finally {
            fclose($handle);
        }

        @unlink($expected);

        return ! file_exists($expected);
    }

    /**
     * BOOTSTRAP/RECOVERY path (command only): load the shared material when it
     * exists, otherwise create it. Only files this call creates are appended to
     * $createdFiles.
     *
     * @param  list<string>  $createdFiles
     */
    public function bootstrapOrLoadMaterial(string $directory, array &$createdFiles): SharedIssuanceMaterial
    {
        $rootCaPath = $directory.DIRECTORY_SEPARATOR.self::FILE_ROOT_CA;
        $keyPath = $directory.DIRECTORY_SEPARATOR.self::FILE_SIGNER_KEY;
        $passphrasePath = $directory.DIRECTORY_SEPARATOR.self::FILE_PASSPHRASE;

        $sharedExists = is_file($rootCaPath) && is_file($keyPath) && is_file($passphrasePath);

        if ($sharedExists) {
            return $this->loadSharedMaterialFromDirectory($directory, false);
        }

        return $this->createSharedMaterial(
            $rootCaPath,
            $directory.DIRECTORY_SEPARATOR.self::FILE_ROOT_CA_KEY,
            $keyPath,
            $passphrasePath,
            $createdFiles,
        );
    }

    /**
     * Shared leaf primitive: one X.509 leaf (CA:FALSE, digitalSignature,
     * emailProtection EKU) whose public key is the shared signer key, signed by
     * the local Root CA, exported as PEM. Its own random serial gives it its own
     * fingerprint; the subject carries NO user PII.
     *
     * @throws IssuanceException
     */
    public function generateLeafPem(SharedIssuanceMaterial $material): string
    {
        return $this->withOpenSslConfig(function (string $cnfPath) use ($material): string {
            $csr = @openssl_csr_new(['commonName' => 'DSMD Local Test Signer'], $material->signerKey, [
                'config' => $cnfPath,
                'digest_alg' => 'sha256',
            ]);

            $leaf = $csr === false ? false : @openssl_csr_sign(
                $csr,
                $material->rootCaCertificate,
                $material->rootCaKey,
                825,
                ['config' => $cnfPath, 'x509_extensions' => 'v3_signer', 'digest_alg' => 'sha256'],
                random_int(1, PHP_INT_MAX),
            );

            if ($leaf === false) {
                $this->clearOpenSslErrors();
                throw IssuanceException::of(IssuanceFailureCode::CERTIFICATE_INVALID);
            }

            $pem = '';
            if (@openssl_x509_export($leaf, $pem) !== true || $pem === '') {
                $this->clearOpenSslErrors();
                throw IssuanceException::of(IssuanceFailureCode::CERTIFICATE_INVALID);
            }

            $this->clearOpenSslErrors();

            return $pem;
        });
    }

    // --- attempt artefact resolution ------------------------------------------

    private function completeWinnerArtifact($handle, string $target, string $issuanceDir, string $root, int $requestId, string $attemptId): AttemptCertificateArtifact
    {
        $locked = false;

        try {
            // (1) A winner MUST hold the EXCLUSIVE lock before the first byte. A lock
            // we cannot take is a retryable coordination race — never proceed as if
            // it were held, and never leave an unlocked empty file a loser could read
            // as "stable". Nothing has been written yet.
            if ($this->acquireExclusiveLock($handle) !== true) {
                $this->discardCreatedArtefactUnderLock($handle, $target, false);

                throw TransientIssuanceException::create();
            }
            $locked = true;

            // Only the winner loads the shared material and generates a leaf.
            $material = $this->loadSharedMaterialOrFail($root);

            // Test seam: runs while the empty file exists AND the exclusive lock is
            // held, so a deterministic interleaving can prove a loser sees an
            // actively-written artefact. Inert in production.
            $this->duringExclusiveCreate($target);

            $pem = $this->generateLeafPem($material);

            // (2/3/4) Prove the FULL content is written, tolerating legitimate short
            // writes across multiple calls; a false/zero (no-progress) result fails
            // closed. (5) The flush must then EXPLICITLY succeed. All of this happens
            // while the exclusive lock is still held.
            $this->writeAllOrFail($handle, $pem);
            if ($this->flushHandle($handle) !== true) {
                throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
            }

            // (6) Only after a proven full write + successful flush is the artefact
            // complete, so only now may the exclusive lock be released. (On this and
            // other platforms the bytes cannot be read back while the lock is held,
            // so the re-read below happens on a fresh handle afterwards — by which
            // point the file is already complete and a loser reads a valid leaf.)
            $this->releaseLock($handle);
            $locked = false;
            fclose($handle);
            $handle = null;

            // Re-prove what actually landed (fresh handle) and that it is byte-for-
            // byte what we wrote, then validate the full leaf contract.
            $physical = $this->readContainedRegularFile($target, $issuanceDir, $root);
            if (! hash_equals(hash('sha256', $pem), hash('sha256', $physical))) {
                @unlink($target);

                throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
            }
            $this->assertValidLeaf($physical, $root, $material);

            return new AttemptCertificateArtifact($target, $physical, true, $requestId, $attemptId);
        } catch (IssuanceException|TransientIssuanceException $e) {
            $this->discardCreatedArtefactUnderLock($handle, $target, $locked);

            throw $e;
        } catch (Throwable) {
            $this->discardCreatedArtefactUnderLock($handle, $target, $locked);

            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }
    }

    /**
     * Write the ENTIRE payload, tolerating legitimate short writes by continuing
     * from the next offset. A false or non-positive (no forward progress) result is
     * a failed/stuck write and fails closed — so the loop can never spin forever and
     * a partial artefact is never accepted.
     *
     * @param  resource  $handle
     *
     * @throws IssuanceException
     */
    private function writeAllOrFail($handle, string $pem): void
    {
        $total = strlen($pem);
        $offset = 0;

        while ($offset < $total) {
            $written = $this->writeChunk($handle, substr($pem, $offset));

            if (! is_int($written) || $written <= 0) {
                throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
            }

            $offset += $written;
        }
    }

    /**
     * Remove the invocation-owned, create-only artefact. When the exclusive lock is
     * still held it is zeroed and unlinked BEFORE the lock is released, so a
     * concurrent loser can never take a shared lock and read a partial/empty file as
     * a "stable" artefact. A foreign artefact is never passed here.
     *
     * @param  resource|null  $handle
     */
    private function discardCreatedArtefactUnderLock($handle, string $target, bool $locked): void
    {
        if (is_resource($handle)) {
            @ftruncate($handle, 0); // no partial bytes remain readable, even in a race
            @unlink($target);       // remove under the lock where the platform allows it
            if ($locked) {
                $this->releaseLock($handle);
            }
            @fclose($handle);
        }

        @unlink($target); // fallback removal once the handle is closed
    }

    // --- minimal I/O seams (inert wrappers; overridden only by failure-injection) --

    /**
     * @param  resource  $handle
     */
    protected function acquireExclusiveLock($handle): bool
    {
        return @flock($handle, LOCK_EX);
    }

    /**
     * @param  resource  $handle
     * @return int|false bytes written, or false on failure
     */
    protected function writeChunk($handle, string $bytes): int|false
    {
        return @fwrite($handle, $bytes);
    }

    /**
     * @param  resource  $handle
     */
    protected function flushHandle($handle): bool
    {
        return @fflush($handle);
    }

    /**
     * @param  resource  $handle
     */
    protected function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
        }
    }

    private function resolveExistingArtifact(string $target, string $issuanceDir, string $root, int $requestId, string $attemptId): AttemptCertificateArtifact
    {
        // Stable safety first: a symlink/junction/non-regular pre-existing entry
        // is fail-closed and is NEVER overwritten or deleted (we do not own it).
        $this->assertSafeContainedFile($target, $root);

        // Distinguish an ACTIVE writer from a STABLE file via an advisory lock: if
        // a shared lock cannot be taken, a winner holds LOCK_EX and is still
        // writing → transient retry (never delete, never terminal). Only once the
        // shared lock is granted are the bytes stable and read from this handle.
        $handle = @fopen($target, 'rb');
        if ($handle === false) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        try {
            $wouldBlock = 0;
            if (! @flock($handle, LOCK_SH | LOCK_NB, $wouldBlock)) {
                // A concurrent winner is actively writing this exact artefact.
                throw TransientIssuanceException::create();
            }

            $stat = @fstat($handle);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
            }

            $physical = stream_get_contents($handle);
            @flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if ($physical === false) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        $certificate = @openssl_x509_read($physical);
        if ($certificate === false) {
            $this->clearOpenSslErrors();
            // STABLE, no active writer, yet truncated/unparsable → TERMINAL,
            // fail-closed. Never transient (would loop), never overwritten/deleted.
            throw IssuanceException::of(IssuanceFailureCode::CERTIFICATE_INVALID);
        }
        $this->clearOpenSslErrors();

        // A parsable-but-invalid/untrusted pre-existing artefact is likewise a
        // permanent, fail-closed condition — still never overwritten or deleted.
        $material = $this->loadSharedMaterialOrFail($root);
        $this->assertValidLeaf($physical, $root, $material);

        return new AttemptCertificateArtifact($target, $physical, false, $requestId, $attemptId);
    }

    /**
     * Test seam (inert in production): invoked while the winner holds the empty
     * create-only file open under an exclusive lock. Overridden only by a
     * deterministic interleaving test.
     */
    protected function duringExclusiveCreate(string $target): void
    {
        // no-op
    }

    private function assertValidLeaf(string $pem, string $root, SharedIssuanceMaterial $material): void
    {
        try {
            $this->profileValidator->validateForRegistration(
                $pem,
                $material->signerKey,
                $root.DIRECTORY_SEPARATOR.self::FILE_ROOT_CA,
            );
        } catch (SignerCertificateProfileException) {
            throw IssuanceException::of(IssuanceFailureCode::CERTIFICATE_INVALID);
        }
    }

    // --- root / material resolution -------------------------------------------

    private function verifiedExistingRoot(): string
    {
        $root = $this->boundary->verified();

        if ($root === null || ! $this->boundary->matchesExpectedPath(config('signing.local_material_path'))) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
        }

        return $root;
    }

    private function resolveIssuanceDirectory(string $root): string
    {
        $lexical = $root.DIRECTORY_SEPARATOR.self::ISSUANCE_SUBDIR;

        if (! file_exists($lexical) && ! @mkdir($lexical, 0700) && ! is_dir($lexical)) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
        }

        $verified = $this->boundary->verifyDirectChild($lexical, $root, self::ISSUANCE_SUBDIR);
        if ($verified === null || ! $this->boundary->isWithin($verified, $root) || ! is_writable($verified)) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        return $verified;
    }

    private function loadSharedMaterialOrFail(string $root): SharedIssuanceMaterial
    {
        foreach (self::SHARED_MATERIAL_FILES as $name) {
            if (! is_file($root.DIRECTORY_SEPARATOR.$name)) {
                throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
            }
        }

        return $this->loadSharedMaterialFromDirectory($root, false);
    }

    private function loadSharedMaterialFromDirectory(string $directory, bool $freshlyCreated): SharedIssuanceMaterial
    {
        // P2-4: each file is individually re-proven safe immediately before read.
        $rootCaPem = $this->safeReadSharedFile($directory, self::FILE_ROOT_CA);
        $caKeyPem = $this->safeReadSharedFile($directory, self::FILE_ROOT_CA_KEY);
        $signerKeyPem = $this->safeReadSharedFile($directory, self::FILE_SIGNER_KEY);
        $passphrase = $this->stripSingleTrailingLineEnding($this->safeReadSharedFile($directory, self::FILE_PASSPHRASE));

        if ($passphrase === '') {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }

        $signerKey = @openssl_pkey_get_private($signerKeyPem, $passphrase);
        $caKey = @openssl_pkey_get_private($caKeyPem, $passphrase);
        $caCert = @openssl_x509_read($rootCaPem);

        if ($signerKey === false || $caKey === false || $caCert === false) {
            $this->clearOpenSslErrors();
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }

        $this->clearOpenSslErrors();

        return new SharedIssuanceMaterial($caCert, $caKey, $signerKey, $passphrase, $freshlyCreated);
    }

    /**
     * @param  list<string>  $createdFiles
     */
    private function createSharedMaterial(
        string $rootCaPath,
        string $caKeyPath,
        string $keyPath,
        string $passphrasePath,
        array &$createdFiles,
    ): SharedIssuanceMaterial {
        return $this->withOpenSslConfig(function (string $cnfPath) use ($rootCaPath, $caKeyPath, $keyPath, $passphrasePath, &$createdFiles): SharedIssuanceMaterial {
            $caKey = $this->newKey($cnfPath);
            $caCsr = @openssl_csr_new(['commonName' => 'DSMD Local Test Root CA'], $caKey, [
                'config' => $cnfPath,
                'digest_alg' => 'sha256',
            ]);
            $caCert = $caCsr === false ? false : @openssl_csr_sign($caCsr, null, $caKey, 3650, [
                'config' => $cnfPath, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256',
            ], random_int(1, PHP_INT_MAX));

            if ($caCert === false) {
                $this->clearOpenSslErrors();
                throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
            }

            $signerKey = $this->newKey($cnfPath);

            $caPem = '';
            if (@openssl_x509_export($caCert, $caPem) !== true) {
                $this->clearOpenSslErrors();
                throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
            }

            $passphrase = bin2hex(random_bytes(24));
            $encryptedKeyPem = '';
            $encryptedCaKeyPem = '';
            if (@openssl_pkey_export($signerKey, $encryptedKeyPem, $passphrase, ['config' => $cnfPath]) !== true
                || @openssl_pkey_export($caKey, $encryptedCaKeyPem, $passphrase, ['config' => $cnfPath]) !== true) {
                $this->clearOpenSslErrors();
                throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
            }

            $this->writeCreateOnly($rootCaPath, $caPem, $createdFiles);
            $this->writeCreateOnly($keyPath, $encryptedKeyPem, $createdFiles);
            $this->writeCreateOnly($passphrasePath, $passphrase, $createdFiles);
            $this->writeCreateOnly($caKeyPath, $encryptedCaKeyPem, $createdFiles);
            @chmod($keyPath, 0600);
            @chmod($passphrasePath, 0600);
            @chmod($caKeyPath, 0600);

            $this->clearOpenSslErrors();

            return new SharedIssuanceMaterial($caCert, $caKey, $signerKey, $passphrase, true);
        });
    }

    // --- filesystem safety helpers --------------------------------------------

    /**
     * P2-4: read one shared-material file only after individually re-proving it is
     * a safe, contained, real regular file (allow-listed basename, canonical
     * parent == the given directory, no symlink/junction/reparse, lstat AND
     * post-open fstat both regular).
     */
    private function safeReadSharedFile(string $directory, string $basename): string
    {
        if (! in_array($basename, self::SHARED_MATERIAL_FILES, true)) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }

        try {
            return $this->readContainedRegularFile($directory.DIRECTORY_SEPARATOR.$basename, $directory, $directory);
        } catch (TransientIssuanceException) {
            // Shared material is not part of a create-race; any read problem here
            // is a fail-closed signing-root condition, never a transient retry.
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }
    }

    /**
     * Safe read of a regular file at $path whose canonical parent must be exactly
     * $expectedParent and which must stay inside $containmentRoot. Fails closed on
     * symlink/junction/reparse, canonicalization failure, wrong parent, or a
     * non-regular object — proven both by lstat (pre-open) and fstat (post-open).
     *
     * @throws IssuanceException on any unsafe/missing condition
     */
    private function readContainedRegularFile(string $path, string $expectedParent, string $containmentRoot): string
    {
        $lstat = @lstat($path);
        if ($lstat === false) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
        }

        if (is_link($path)) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }

        $real = realpath($path);
        if ($real === false) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
        }

        if (is_link($real)
            || ! $this->pathEquals(dirname($real), $expectedParent)
            || ! $this->pathEquals(basename($real), basename($path))
            || ! $this->boundary->isWithin($real, $containmentRoot)
            || ! is_file($real)
            || is_dir($real)) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }

        // Test seam (inert in production): a deterministic swap between the
        // pre-open checks and fopen() can prove the post-open identity guard.
        $this->beforeContainedFileOpen($real);

        $handle = @fopen($real, 'rb');
        if ($handle === false) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
        }

        try {
            $fstat = @fstat($handle);
            if (! is_array($fstat) || ($fstat['mode'] & 0170000) !== 0100000) {
                throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
            }

            // D2: bind the OPENED object to the pre-open lstat. If the path was
            // swapped between the checks and fopen(), the opened inode diverges and
            // this fails closed. Where the OS does not expose a stable inode
            // (e.g. Windows reports 0), this degrades to the lstat + fstat-regular
            // checks and the residual stays a documented P3.
            if ($this->inodeIsStable($lstat, $fstat) && ! $this->sameInode($lstat, $fstat)) {
                throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
            }

            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_UNAVAILABLE);
        }

        return $contents;
    }

    /**
     * Test seam (inert in production): invoked between the pre-open safety checks
     * and fopen() of a contained file. Overridden only by a swap test.
     */
    protected function beforeContainedFileOpen(string $real): void
    {
        // no-op
    }

    /**
     * @param  array<string, int>  $lstat
     * @param  array<string, int>  $fstat
     */
    private function inodeIsStable(array $lstat, array $fstat): bool
    {
        return ($lstat['ino'] ?? 0) !== 0 && ($fstat['ino'] ?? 0) !== 0;
    }

    /**
     * @param  array<string, int>  $lstat
     * @param  array<string, int>  $fstat
     */
    private function sameInode(array $lstat, array $fstat): bool
    {
        return ($lstat['dev'] ?? -1) === ($fstat['dev'] ?? -2)
            && ($lstat['ino'] ?? -1) === ($fstat['ino'] ?? -2);
    }

    /**
     * A path is only reusable/deletable when it is a real, contained, regular
     * file — never a symlink, junction, reparse point, directory, or an escape
     * out of the verified root.
     */
    private function assertSafeContainedFile(string $path, string $root): void
    {
        if (is_link($path) || ! is_file($path)) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        $real = realpath($path);
        if ($real === false || is_link($real) || ! is_file($real) || ! $this->boundary->isWithin($real, $root)) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }
    }

    /**
     * Exclusive-create write, used only for shared bootstrap material.
     *
     * @param  list<string>|null  $createdFiles
     */
    private function writeCreateOnly(string $path, string $contents, ?array &$createdFiles = null): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }

        if ($createdFiles !== null) {
            $createdFiles[] = $path;
        }

        $written = fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents)) {
            throw IssuanceException::of(IssuanceFailureCode::ARTEFACT_UNSAFE);
        }
    }

    private function newKey(string $cnfPath): \OpenSSLAsymmetricKey
    {
        $key = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $cnfPath,
        ]);

        if ($key === false) {
            $this->clearOpenSslErrors();
            throw IssuanceException::of(IssuanceFailureCode::SIGNING_ROOT_INVALID);
        }

        return $key;
    }

    /**
     * Run a closure with a throwaway OpenSSL config file in an instance-owned,
     * canonical OS-temp workspace that is always torn down afterwards.
     *
     * @template TReturn
     *
     * @param  callable(string):TReturn  $fn
     * @return TReturn
     */
    private function withOpenSslConfig(callable $fn): mixed
    {
        try {
            $handle = $this->workspace->create();
        } catch (DetachedCmsException) {
            throw IssuanceException::of(IssuanceFailureCode::FAILED);
        }

        try {
            $cnfPath = $handle->path().DIRECTORY_SEPARATOR.'openssl.cnf';
            $this->writeCreateOnly($cnfPath, self::OPENSSL_CNF);

            return $fn($cnfPath);
        } finally {
            $this->workspace->discard($handle);
        }
    }

    private function pathEquals(string $a, string $b): bool
    {
        $normalize = static function (string $p): string {
            $p = rtrim(str_replace('\\', '/', $p), '/');

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
        };

        return $normalize($a) === $normalize($b);
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

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Drain and discard: raw OpenSSL errors are never surfaced.
        }
    }
}
