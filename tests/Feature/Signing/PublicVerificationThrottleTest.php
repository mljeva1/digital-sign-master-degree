<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The public verification page runs a real detached-CMS verification on every
 * GET, so the route is rate-limited. These tests prove the limit exists, that
 * normal use stays practical, and that the bearer-style token never becomes a
 * rate-limit key or leaks into the response.
 */
final class PublicVerificationThrottleTest extends ContractSigningTestCase
{
    private const LIMIT = 20;

    private function publiclyVerifiableToken(): string
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $contract = $this->seedContract($user);
        $finalPdf = $this->attachFinalPdf($contract, $user, $this->pdfBytes('throttle'));
        $token = $this->activatePublicVerification($contract, $finalPdf);
        auth()->logout();

        return $token;
    }

    public function test_route_declares_the_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('public.contracts.verify.show');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('throttle:'.self::LIMIT.',1', $route->gatherMiddleware());
    }

    public function test_normal_repeated_viewing_stays_within_the_limit(): void
    {
        $token = $this->publiclyVerifiableToken();

        // A handful of views — well inside real human usage — must all succeed.
        for ($i = 0; $i < 5; $i++) {
            $this->get(route('public.contracts.verify.show', $token))->assertOk();
        }
    }

    public function test_request_above_the_limit_returns_429(): void
    {
        $token = $this->publiclyVerifiableToken();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->get(route('public.contracts.verify.show', $token))->assertOk();
        }

        $this->get(route('public.contracts.verify.show', $token))->assertStatus(429);
    }

    public function test_throttle_is_shared_across_tokens_so_it_cannot_be_bypassed_by_rotating_tokens(): void
    {
        $first = $this->publiclyVerifiableToken();
        $second = $this->publiclyVerifiableToken();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->get(route('public.contracts.verify.show', $first))->assertOk();
        }

        // A different valid token from the same client is still limited: the key
        // is the IP, not the token, so crypto work cannot be amplified.
        $this->get(route('public.contracts.verify.show', $second))->assertStatus(429);
    }

    public function test_plain_token_never_appears_in_a_rate_limit_key_or_the_response(): void
    {
        $token = $this->publiclyVerifiableToken();

        $response = $this->get(route('public.contracts.verify.show', $token))->assertOk();
        $html = $response->getContent();

        // The token is a bearer credential: it must not be echoed into the page
        // body (it legitimately appears only in the URL the visitor already has).
        $this->assertStringNotContainsString($token, $html);

        // And it must not be reachable as a cache key.
        $this->assertNull(cache()->get($token));
        $this->assertNull(cache()->get(sha1($token)));
        $this->assertNull(cache()->get(hash('sha256', $token)));
    }

    public function test_invalid_token_still_returns_404_under_throttling(): void
    {
        $this->publiclyVerifiableToken();

        $this->get(route('public.contracts.verify.show', str_repeat('z', 64)))->assertNotFound();
    }

    public function test_throttled_response_exposes_no_sensitive_detail(): void
    {
        $token = $this->publiclyVerifiableToken();
        $cms = StoredFile::query()->where('purpose', StoredFile::PURPOSE_CMS_SIGNATURE)->first();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->get(route('public.contracts.verify.show', $token));
        }

        $body = $this->get(route('public.contracts.verify.show', $token))->assertStatus(429)->getContent();

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString($this->tempDir, $body);
        $this->assertStringNotContainsString('openssl', strtolower($body));
        if ($cms !== null) {
            $this->assertStringNotContainsString((string) $cms->storage_path, $body);
        }
    }
}
