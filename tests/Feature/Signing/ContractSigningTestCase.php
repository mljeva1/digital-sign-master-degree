<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Models\Certificate;
use App\Models\Contract;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Signing\FinalPdfVerificationBindingVerifier;
use App\Services\Signing\SignerCertificateRegistrar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Shared base for the M11 contract-signing / final-PDF suites.
 *
 * Extends the M10 SigningTestCase (ephemeral PKI, temp-rooted local disk,
 * users/files/certificates schema) and adds the contracts/signatures/audit_events
 * tables plus the real M10 partial unique index, so the suites exercise real
 * filesystem bytes, real DB transactions and a real SQLite partial-unique race.
 *
 * These hand-built SQLite tables prove APPLICATION behaviour only — never a
 * PostgreSQL CHECK/FK/concurrency guarantee (those live behind the opt-in
 * SignatureSourceBindingSchemaTest gate).
 */
abstract class ContractSigningTestCase extends SigningTestCase
{
    protected const PASSPHRASE = 'cms-passphrase-123';

    protected function setUp(): void
    {
        parent::setUp();
        // Drop any 'local' disk left cached or faked by a prior test so it
        // re-resolves against the tempDir root SigningTestCase pinned.
        Storage::forgetDisk(StoredFile::DISK_LOCAL);
        $this->buildContractSchema();
        // Eloquent statically caches each model's "guardable columns" for the whole
        // process; a prior test that hand-built a narrower schema for the same model
        // would otherwise make columns be silently dropped on mass assignment here.
        // Pure test isolation — the production schema is stable and never varies.
        $this->flushEloquentColumnCache();
    }

    protected function tearDown(): void
    {
        try {
            Schema::dropIfExists('signatures');
            Schema::dropIfExists('audit_events');
            Schema::dropIfExists('contracts');
            // Also flush on the way OUT: this suite's (narrower) column sets must
            // never leak into a later suite that hand-builds a wider schema for the
            // same models, which would silently drop its columns on mass assignment.
            $this->flushEloquentColumnCache();
        } finally {
            parent::tearDown();
        }
    }

    protected function flushEloquentColumnCache(): void
    {
        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);
    }

    private function buildContractSchema(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('status');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->json('filled_data_snapshot')->nullable();
            $table->string('finalized_snapshot_sha256')->nullable();
            $table->unsignedBigInteger('final_pdf_file_id')->nullable();
            $table->string('final_pdf_sha256')->nullable();
            $table->string('public_verification_token')->nullable();
            $table->timestamp('public_verification_enabled_at')->nullable();
            $table->timestamp('public_verification_revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('signatures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('contract_party_id')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();
            $table->unsignedBigInteger('signed_user_id')->nullable();
            $table->unsignedBigInteger('signed_customer_id')->nullable();
            $table->unsignedBigInteger('source_file_id')->nullable();
            $table->unsignedBigInteger('signature_file_id')->nullable();
            $table->string('type', 30)->default('digital');
            $table->string('status', 20);
            $table->timestamp('signed_at')->nullable();
            $table->char('document_hash_before', 64);
            $table->char('document_hash_after', 64)->nullable();
            $table->timestamps();
        });

        // Mirror the real M10 partial unique index so the SQLite race tests
        // exercise a genuine DB-level unique violation (not only an app check).
        DB::statement(
            'CREATE UNIQUE INDEX signatures_contract_user_source_active_unique '
            ."ON signatures (contract_id, signed_user_id, source_file_id) WHERE status IN ('pending', 'completed')"
        );

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_customer_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->boolean('success')->default(true);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    // --- shared fixtures ----------------------------------------------------

    protected function pdfBytes(string $marker = 'contract'): string
    {
        return "%PDF-1.4\n% ".$marker." vehicle sale\n1 0 obj<<>>endobj\n%%EOF\n";
    }

    /**
     * Register a real, active signer certificate for a fresh user and point the
     * signing config at its ephemeral key/CA.
     *
     * @return array{user: User, certificate: Certificate, ca: array, signer: array}
     */
    protected function registerValidSigner(string $profile = 'v3_signer', int $days = 825): array
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, $profile, $days);
        $this->configureSigning($signer['key'], self::PASSPHRASE, $ca['pem']);
        $user = User::factory()->create();
        $certificate = app(SignerCertificateRegistrar::class)
            ->register($user, $this->writeCertificateInput($signer['pem']));

        return ['user' => $user, 'certificate' => $certificate, 'ca' => $ca, 'signer' => $signer];
    }

    /** A finalized + locked contract with a valid, renderable snapshot. */
    protected function seedContract(User $user, string $status = Contract::STATUS_FINALIZED, bool $locked = true): Contract
    {
        $snapshot = ['seller_name' => 'Prodavatelj Test', 'buyer_name' => 'Kupac Test', 'place' => 'Zagreb'];

        return Contract::create([
            'created_by_user_id' => $user->id,
            'status' => $status,
            'locked_at' => $locked ? now() : null,
            'finalized_at' => now(),
            'filled_data_snapshot' => $snapshot,
            'finalized_snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * Attach a synthetic final-PDF artefact (fast path for signing tests that do
     * not need real DomPDF rendering). Mirrors the create-only path convention.
     */
    protected function attachFinalPdf(Contract $contract, User $user, string $bytes): StoredFile
    {
        $path = 'contracts/'.$contract->id.'/final-pdfs/final-'.bin2hex(random_bytes(8)).'.pdf';
        Storage::disk(StoredFile::DISK_LOCAL)->put($path, $bytes);
        $sha = hash('sha256', $bytes);

        $file = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_FINAL_PDF, 'storage_disk' => StoredFile::DISK_LOCAL, 'storage_path' => $path,
            'original_filename' => "contract-{$contract->id}-final.pdf", 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes), 'sha256' => $sha, 'created_by_user_id' => $user->id,
        ]);

        $contract->final_pdf_file_id = $file->id;
        $contract->final_pdf_sha256 = $sha;
        $contract->save();

        return $file;
    }

    /**
     * Seed a second, independent ACTIVE signer certificate for a user.
     *
     * @param  array{cert: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, pem: string}  $material
     */
    protected function seedActiveCertificate(User $user, array $material): Certificate
    {
        $pem = $material['pem'];
        $path = 'signing/certificates/user-'.$user->id.'/seed-'.bin2hex(random_bytes(6)).'.pem';
        $this->putFileChecked($this->certificateFilesystem(), $path, $pem);
        $parsed = openssl_x509_parse($material['cert']);

        $file = StoredFile::create([
            'purpose' => StoredFile::PURPOSE_CERTIFICATE, 'storage_disk' => 'local', 'storage_path' => $path,
            'original_filename' => 'signer-certificate.pem', 'mime_type' => 'application/x-pem-file',
            'size_bytes' => strlen($pem), 'sha256' => hash('sha256', $pem), 'created_by_user_id' => $user->id,
        ]);

        return Certificate::create([
            'owner_type' => Certificate::OWNER_TYPE_USER, 'owner_user_id' => $user->id, 'owner_customer_id' => null,
            'label' => 'seed', 'subject_dn' => '/CN=seed', 'issuer_dn' => '/CN=seed', 'serial_number' => '2',
            'valid_from' => Carbon::createFromTimestampUTC($parsed['validFrom_time_t']),
            'valid_to' => Carbon::createFromTimestampUTC($parsed['validTo_time_t']),
            'thumbprint_sha256' => $this->fingerprint($material['cert']), 'file_id' => $file->id, 'is_active' => true,
        ]);
    }

    /**
     * Activate public verification with a real generation proof, exactly as the
     * generator's own transaction records it (used by the M12 HTTP suites).
     */
    protected function activatePublicVerification(Contract $contract, StoredFile $finalPdf): string
    {
        $token = bin2hex(random_bytes(32));
        $contract->public_verification_token = $token;
        $contract->public_verification_enabled_at = now();
        $contract->public_verification_revoked_at = null;
        $contract->save();

        $binding = app(FinalPdfVerificationBindingVerifier::class);
        app(AuditLogger::class)->record('contract.final_pdf_generated', $contract, [
            'contract_id' => (int) $contract->id,
            'file_id' => (int) $finalPdf->id,
            'final_pdf_file_id' => (int) $finalPdf->id,
            'final_pdf_sha256' => (string) $finalPdf->sha256,
            'generation_reason' => FinalPdfVerificationBindingVerifier::GENERATION_REASON,
            'public_verification_token_sha256' => $binding->tokenHash($token),
            'verification_url_sha256' => $binding->urlHash($binding->canonicalVerificationUrl($token)),
        ], null, (int) $contract->created_by_user_id);

        return $token;
    }

    /** Count physical .p7s artefacts under the contracts tree. */
    protected function p7sCount(): int
    {
        $files = Storage::disk(StoredFile::DISK_LOCAL)->allFiles('contracts');

        return count(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.p7s')));
    }

    /** Count physical final-PDF artefacts under the contracts tree. */
    protected function finalPdfCount(): int
    {
        $files = Storage::disk(StoredFile::DISK_LOCAL)->allFiles('contracts');

        return count(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.pdf')));
    }
}
