<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Exceptions\Signing\ContractSigningException;
use App\Services\Signing\ContractSigningFailurePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the COMPLETE, safe mapping of every stable signing failure code to a
 * fixed Croatian message: no declared code may fall through to the fallback,
 * unknown codes always do, and no message can carry technical/sensitive text.
 */
final class ContractSigningFailurePresenterTest extends TestCase
{
    /** @return list<string> every public string constant on the exception */
    private function allDeclaredCodes(): array
    {
        $codes = [];
        foreach ((new ReflectionClass(ContractSigningException::class))->getConstants() as $value) {
            if (is_string($value)) {
                $codes[] = $value;
            }
        }

        $this->assertNotEmpty($codes);

        return $codes;
    }

    public function test_every_declared_code_maps_to_a_specific_message(): void
    {
        $fallback = ContractSigningFailurePresenter::fallbackMessage();

        foreach ($this->allDeclaredCodes() as $code) {
            $message = ContractSigningFailurePresenter::message($code);

            $this->assertNotSame('', $message, $code);
            $this->assertNotSame($fallback, $message, "Code {$code} must not fall through to the fallback.");
        }
    }

    public function test_unknown_code_uses_the_neutral_fallback(): void
    {
        $this->assertSame(
            ContractSigningFailurePresenter::fallbackMessage(),
            ContractSigningFailurePresenter::message('SOME_FUTURE_UNKNOWN_CODE')
        );
    }

    public function test_no_message_contains_technical_or_sensitive_fragments(): void
    {
        $messages = array_map(
            static fn (string $code): string => ContractSigningFailurePresenter::message($code),
            [...self::codesForLeakScan(), 'UNKNOWN']
        );

        foreach ($messages as $message) {
            foreach (['SQLSTATE', 'Exception', 'openssl', 'OpenSSL', '.p7s', '.pem', 'C:\\', '/home/', 'storage/', 'contracts/', 'token'] as $fragment) {
                $this->assertStringNotContainsString($fragment, $message);
            }
        }
    }

    /** @return list<string> */
    private static function codesForLeakScan(): array
    {
        $codes = [];
        foreach ((new ReflectionClass(ContractSigningException::class))->getConstants() as $value) {
            if (is_string($value)) {
                $codes[] = $value;
            }
        }

        return $codes;
    }
}
