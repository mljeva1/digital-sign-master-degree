<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Exceptions\Signing\DetachedCmsException;
use App\Models\Certificate;
use App\Services\Signing\DetachedCmsSignRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * The request constructor validates the certificate primary key against the
 * model's real key configuration using the RAW (uncast) attribute — never the
 * cast getKey() value, which silently turns invalid raw values into a bogus
 * integer (e.g. 'abc'/'  ' → 0, '12.5' → 12). Every invalid case fails closed
 * with the existing neutral CMS contract (never a raw TypeError).
 */
final class DetachedCmsSignRequestTest extends TestCase
{
    private function build(Certificate $certificate): DetachedCmsSignRequest
    {
        return new DetachedCmsSignRequest('/tmp/source.pdf', str_repeat('a', 64), $certificate, 42);
    }

    private function assertRejected(Certificate $certificate): void
    {
        try {
            $this->build($certificate);
            $this->fail('Expected the certificate key to be rejected.');
        } catch (TypeError $e) {
            $this->fail('Constructor leaked a raw TypeError: '.$e->getMessage());
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_CERTIFICATE_INVALID, $e->errorCode());
        }
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: mixed}>
     */
    public static function invalidIntegerKeyProvider(): array
    {
        return [
            'exists=false, no key' => [false, false, null],
            'exists=true, null key' => [true, false, null],
            'raw empty string' => [true, true, ''],
            'raw whitespace string' => [true, true, '   '],
            'raw arbitrary string' => [true, true, 'abc'],
            'raw array' => [true, true, ['x']],
            'raw object' => [true, true, new \stdClass],
            'raw zero (int)' => [true, true, 0],
            'raw zero (string)' => [true, true, '0'],
            'raw negative (int)' => [true, true, -5],
            'raw negative (string)' => [true, true, '-5'],
            'raw float' => [true, true, 12.5],
            'raw float string' => [true, true, '12.5'],
            'raw bool' => [true, true, true],
            'raw leading zero' => [true, true, '007'],
            'raw overflow string' => [true, true, '99999999999999999999'],
        ];
    }

    #[DataProvider('invalidIntegerKeyProvider')]
    public function test_invalid_integer_key_is_rejected(bool $exists, bool $setKey, mixed $raw): void
    {
        $certificate = new Certificate;
        $certificate->exists = $exists;
        if ($setKey) {
            $certificate->setAttribute('id', $raw);
        }

        $this->assertRejected($certificate);
    }

    public function test_unsaved_certificate_message_is_neutral(): void
    {
        try {
            $this->build(new Certificate);
            $this->fail('Expected rejection.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_CERTIFICATE_INVALID, $e->errorCode());
            $this->assertSame('The signer certificate is not valid for signing.', $e->getMessage());
            // Neutral: no model class name, no SQL, no raw key value.
            $this->assertStringNotContainsString('App\\Models', $e->getMessage());
            $this->assertStringNotContainsString('select', strtolower($e->getMessage()));
        }
    }

    public function test_object_key_is_rejected_with_the_neutral_contract(): void
    {
        $certificate = new Certificate;
        $certificate->exists = true;
        $certificate->setAttribute('id', new \stdClass);

        try {
            $this->build($certificate);
            $this->fail('Expected an object key to be rejected.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_CERTIFICATE_INVALID, $e->errorCode());
            $this->assertSame('The signer certificate is not valid for signing.', $e->getMessage());
        }
    }

    public function test_valid_saved_positive_integer_is_accepted(): void
    {
        $certificate = new Certificate;
        $certificate->setAttribute('id', 7);
        $certificate->exists = true;

        $request = $this->build($certificate);

        $this->assertSame(7, $request->certificateId());
        $this->assertIsInt($request->certificateId());
    }

    public function test_numeric_string_integer_key_is_normalized_to_positive_int(): void
    {
        $certificate = new Certificate;
        $certificate->setAttribute('id', '7'); // e.g. a PDO string from the driver
        $certificate->exists = true;

        $request = $this->build($certificate);

        $this->assertSame(7, $request->certificateId());
        $this->assertIsInt($request->certificateId());
    }

    /**
     * M10 supports the concrete integer-key Certificate model only. A runtime
     * Certificate::setKeyType('string') mutation is publicly reachable (the model
     * being `final` does not prevent it), so this proves the fail-closed contract:
     * an arbitrary non-integer identifier under a string key type is rejected as
     * CMS_CERTIFICATE_INVALID BEFORE normalization can hand it on, so it can never
     * later reach the database and surface a PostgreSQL "invalid input syntax for
     * integer" error. A generic string-key model is deliberately NOT introduced.
     */
    public function test_runtime_string_key_type_mutation_fails_closed(): void
    {
        $certificate = new Certificate;
        $certificate->exists = true;
        $certificate->setAttribute('id', 'not-an-int'); // an arbitrary string
        $certificate->setKeyType('string'); // runtime mutation away from the integer key

        $this->assertSame('string', $certificate->getKeyType()); // the mutation took effect

        try {
            $this->build($certificate);
            $this->fail('Expected a runtime string key type to fail closed.');
        } catch (DetachedCmsException $e) {
            $this->assertSame(DetachedCmsException::CMS_CERTIFICATE_INVALID, $e->errorCode());
            $this->assertSame('The signer certificate is not valid for signing.', $e->getMessage());
        }
    }
}
