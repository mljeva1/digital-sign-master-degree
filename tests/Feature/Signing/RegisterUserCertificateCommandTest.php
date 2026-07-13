<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Exceptions\Signing\CertificateRegistrationException as RegistrationException;
use App\Models\Certificate;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Signing\SignerCertificateRegistrar;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use RuntimeException;

/**
 * Verifies the local Artisan command wiring and, above all, that it never
 * prints a private-key path, passphrase-file path, passphrase value, raw PEM,
 * or raw OpenSSL error — only safe fields and stable failure codes.
 */
final class RegisterUserCertificateCommandTest extends SigningTestCase
{
    private const PASSPHRASE = 'command-passphrase-xyz';

    private string $lastInputPath = '';

    /**
     * @return array{signer: array, input: string}
     */
    private function validCommandSetup(): array
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, 'v3_signer');
        $this->configureSigning($signer['key'], self::PASSPHRASE, $ca['pem']);
        $this->lastInputPath = $this->writeCertificateInput($signer['pem']);

        return ['signer' => $signer, 'input' => $this->lastInputPath];
    }

    private function assertOutputHasNoSecrets(string $output): void
    {
        $this->assertStringNotContainsString(self::PASSPHRASE, $output);
        $this->assertStringNotContainsString((string) config('signing.private_key_path'), $output);
        $this->assertStringNotContainsString((string) config('signing.passphrase_file_path'), $output);
        $this->assertStringNotContainsString((string) config('signing.root_ca_path'), $output);
        if ($this->lastInputPath !== '') {
            $this->assertStringNotContainsString($this->lastInputPath, $output);
        }
        $this->assertStringNotContainsString('BEGIN', $output);
        $this->assertStringNotContainsString('PRIVATE KEY', $output);
        $this->assertStringNotContainsStringIgnoringCase('error:0', $output); // raw OpenSSL error prefix
    }

    public function test_command_registers_and_prints_only_safe_fields(): void
    {
        $setup = $this->validCommandSetup();
        $user = User::factory()->create();

        $exit = Artisan::call('signing:register-user-certificate', [
            'user' => (string) $user->id,
            '--certificate' => $setup['input'],
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Result: REGISTERED', $output);
        $this->assertStringContainsString('Certificate ID:', $output);
        $this->assertStringContainsString('Owner user ID: '.$user->id, $output);
        $this->assertStringContainsString($this->fingerprint($setup['signer']['cert']), $output);
        $this->assertOutputHasNoSecrets($output);
        $this->assertSame(1, Certificate::query()->where('owner_user_id', $user->id)->count());
    }

    public function test_command_rejects_non_numeric_user(): void
    {
        $this->validCommandSetup();

        $exit = Artisan::call('signing:register-user-certificate', [
            'user' => 'abc',
            '--certificate' => 'irrelevant.pem',
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_command_rejects_unknown_user(): void
    {
        $setup = $this->validCommandSetup();

        $exit = Artisan::call('signing:register-user-certificate', [
            'user' => '999999',
            '--certificate' => $setup['input'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_command_requires_certificate_option(): void
    {
        $this->validCommandSetup();
        $user = User::factory()->create();

        $exit = Artisan::call('signing:register-user-certificate', [
            'user' => (string) $user->id,
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_command_reports_stable_failure_code_without_secrets(): void
    {
        $ca = $this->newRootCa();
        $signer = $this->issueCertificate($ca, 'v3_signer');
        $this->configureSigning($signer['key'], self::PASSPHRASE, $ca['pem']);
        $this->writeFileChecked((string) config('signing.passphrase_file_path'), 'the-wrong-passphrase');
        $input = $this->lastInputPath = $this->writeCertificateInput($signer['pem']);
        $user = User::factory()->create();

        $exit = Artisan::call('signing:register-user-certificate', [
            'user' => (string) $user->id,
            '--certificate' => $input,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('PRIVATE_KEY_LOAD_FAILED', $output);
        $this->assertStringNotContainsString('the-wrong-passphrase', $output);
        $this->assertOutputHasNoSecrets($output);
        $this->assertSame(0, Certificate::query()->count());
    }

    public function test_command_reports_cleanup_incomplete_signal_without_secrets(): void
    {
        $setup = $this->validCommandSetup();
        $user = User::factory()->create();

        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$store): bool {
            $store[$path] = $contents;

            return true;
        });
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('get')->once()->andReturnUsing(fn (string $path): string => $store[$path]);
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('build')->once()->andReturn($disk);

        StoredFile::creating(fn () => throw new RuntimeException('injected db failure'));

        try {
            $exit = Artisan::call('signing:register-user-certificate', [
                'user' => (string) $user->id,
                '--certificate' => $setup['input'],
            ]);
            $output = Artisan::output();
        } finally {
            app('events')->forget('eloquent.creating: '.StoredFile::class);
        }

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('CERTIFICATE_PERSISTENCE_FAILED', $output);
        $this->assertStringContainsString('Cleanup: INCOMPLETE', $output);
        $this->assertOutputHasNoSecrets($output);
    }

    public function test_command_reports_cleanup_incomplete_instead_of_successful_unique_recovery(): void
    {
        $setup = $this->validCommandSetup();
        $user = User::factory()->create();
        $winner = app(SignerCertificateRegistrar::class)->register($user, $setup['input']);
        $store = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => array_key_exists($path, $store));
        $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $contents) use (&$store): bool {
            $store[$path] = $contents;

            return true;
        });
        $disk->shouldReceive('get')->once()->andReturnUsing(fn (string $path): string => $store[$path]);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('build')->once()->andReturn($disk);
        $registrar = new class($this->signingConfig()) extends SignerCertificateRegistrar
        {
            protected function existingByFingerprint(string $fingerprint): ?Certificate
            {
                return null;
            }

            protected function insertCertificateRecords(User $owner, string $disk, string $attemptPath, string $physicalPem, string $fingerprint, array $parsed, Carbon $validFrom, Carbon $validTo): Certificate
            {
                $driverMessage = 'UNIQUE constraint failed: certificates.thumbprint_sha256';
                $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 '.$driverMessage, 23000);
                $previous->errorInfo = ['23000', 19, $driverMessage];

                throw new QueryException('sqlite', 'insert into "certificates" ...', [], $previous);
            }
        };
        app()->instance(SignerCertificateRegistrar::class, $registrar);

        $exit = Artisan::call('signing:register-user-certificate', [
            'user' => (string) $user->id,
            '--certificate' => $setup['input'],
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(RegistrationException::CERTIFICATE_PERSISTENCE_FAILED, $output);
        $this->assertStringContainsString('Cleanup: INCOMPLETE', $output);
        $this->assertStringNotContainsString('Result: IDEMPOTENT', $output);
        $this->assertOutputHasNoSecrets($output);
        $this->assertSame($winner->id, Certificate::query()->sole()->id);
        $this->assertCount(1, $store);
    }
}
