<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Services\Signing\DetachedCmsVerificationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure normalization unit tests for the CMS verification result object: `overall`
 * is the conjunction of all six independent signals, and a single false signal
 * flips it. No OpenSSL, filesystem, or database is involved.
 */
final class DetachedCmsVerificationResultTest extends TestCase
{
    public function test_all_true_signals_produce_a_valid_overall(): void
    {
        $result = new DetachedCmsVerificationResult(true, true, true, true, true, true);

        $this->assertTrue($result->overall);
        $this->assertTrue($result->isValid());
        $this->assertSame([
            'cryptographic_valid' => true,
            'trust_valid' => true,
            'certificate_time_valid' => true,
            'certificate_active' => true,
            'signer_fingerprint_matches' => true,
            'source_hash_matches' => true,
            'overall' => true,
        ], $result->toArray());
    }

    #[DataProvider('singleFalseSignalProvider')]
    public function test_any_single_false_signal_invalidates_overall(int $falseIndex): void
    {
        $signals = array_fill(0, 6, true);
        $signals[$falseIndex] = false;

        $result = new DetachedCmsVerificationResult(...$signals);

        $this->assertFalse($result->overall);
        $this->assertFalse($result->isValid());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function singleFalseSignalProvider(): array
    {
        return [
            'cryptographic_valid' => [0],
            'trust_valid' => [1],
            'certificate_time_valid' => [2],
            'certificate_active' => [3],
            'signer_fingerprint_matches' => [4],
            'source_hash_matches' => [5],
        ];
    }

    public function test_all_false_signals_produce_invalid_overall(): void
    {
        $result = new DetachedCmsVerificationResult(false, false, false, false, false, false);

        $this->assertFalse($result->overall);
        $this->assertFalse($result->isValid());
    }
}
