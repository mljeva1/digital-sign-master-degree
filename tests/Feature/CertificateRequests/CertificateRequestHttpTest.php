<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Models\CertificateRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * M14 — HTTP edge: authorization, payload boundary, Blade rendering and
 * redirect/flash behaviour. The authorization assertions are the point; the Phase
 * B Blade layer only changes how success/refusal is rendered.
 */
final class CertificateRequestHttpTest extends CertificateRequestTestCase
{
    // --- subject surface --------------------------------------------------

    public function test_guest_is_redirected_from_every_certificate_request_route(): void
    {
        $this->get('/certificate-requests')->assertRedirect();
        $this->post('/certificate-requests')->assertRedirect();
        $this->get('/certificate-operator/requests')->assertRedirect();
    }

    public function test_index_renders_the_blade_view_for_the_owner(): void
    {
        $user = $this->userWithRole();
        $this->pendingRequestFor($user);

        $this->actingAs($user)->get('/certificate-requests')
            ->assertOk()
            ->assertViewIs('certificate-requests.index')
            ->assertViewHas('canCreate', false); // an active request blocks a new one
    }

    public function test_user_submits_a_request_for_themselves(): void
    {
        $user = $this->userWithRole();

        $this->actingAs($user)
            ->post('/certificate-requests', ['request_note' => 'molim izdavanje'])
            ->assertRedirect('/certificate-requests')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('certificate_requests', ['user_id' => $user->id, 'status' => Status::PENDING]);
    }

    public function test_foreign_user_id_in_the_payload_is_ignored(): void
    {
        $user = $this->userWithRole();
        $victim = $this->userWithRole();

        $this->actingAs($user)
            ->post('/certificate-requests', [
                'user_id' => $victim->id,
                'status' => Status::ISSUED,
                'certificate_id' => 999,
                'issuance_attempt_id' => '00000000-0000-4000-8000-000000000000',
                'reviewed_by_user_id' => $victim->id,
            ])
            ->assertRedirect('/certificate-requests')
            ->assertSessionHas('success');

        // The subject is the authenticated user; every authoritative field is
        // assigned by the workflow, never by the payload.
        $created = CertificateRequest::query()->firstOrFail();
        $this->assertSame($user->id, (int) $created->user_id);
        $this->assertSame(Status::PENDING, $created->status);
        $this->assertNull($created->certificate_id);
        $this->assertNull($created->issuance_attempt_id);
        $this->assertNull($created->reviewed_by_user_id);
        $this->assertSame(0, CertificateRequest::query()->where('user_id', $victim->id)->count());
    }

    public function test_duplicate_active_request_is_refused_with_a_flash_error(): void
    {
        $user = $this->userWithRole();
        $this->actingAs($user)->post('/certificate-requests')->assertSessionHas('success');

        $this->actingAs($user)->post('/certificate-requests')
            ->assertRedirect('/certificate-requests')
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertSame(1, CertificateRequest::query()->count());
    }

    public function test_active_certificate_blocks_submission(): void
    {
        $user = $this->userWithRole();
        $this->activeCertificateFor($user);

        $this->actingAs($user)->post('/certificate-requests')
            ->assertRedirect('/certificate-requests')
            ->assertSessionHas('error');

        $this->assertSame(0, CertificateRequest::query()->count());
    }

    public function test_owner_views_own_request_but_not_a_foreign_one(): void
    {
        $owner = $this->userWithRole();
        $stranger = $this->userWithRole();
        $request = $this->pendingRequestFor($owner);

        $this->actingAs($owner)->get("/certificate-requests/{$request->id}")
            ->assertOk()->assertViewIs('certificate-requests.show');
        $this->actingAs($stranger)->get("/certificate-requests/{$request->id}")->assertForbidden();
    }

    public function test_owner_cancels_own_pending_request_and_stranger_cannot(): void
    {
        $owner = $this->userWithRole();
        $stranger = $this->userWithRole();
        $request = $this->pendingRequestFor($owner);

        $this->actingAs($stranger)->patch("/certificate-requests/{$request->id}/cancel")->assertForbidden();
        $this->assertSame(Status::PENDING, $request->fresh()->status);

        $this->actingAs($owner)->patch("/certificate-requests/{$request->id}/cancel")
            ->assertRedirect('/certificate-requests')
            ->assertSessionHas('success');
        $this->assertSame(Status::CANCELLED, $request->fresh()->status);
    }

    public function test_cancel_is_forbidden_once_the_request_is_no_longer_pending(): void
    {
        $owner = $this->userWithRole();
        $operator = $this->operator();
        $request = CertificateRequest::factory()->approved($operator)->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->patch("/certificate-requests/{$request->id}/cancel")->assertForbidden();
    }

    public function test_navigation_gates_the_operator_link_on_the_exact_role(): void
    {
        // Every authenticated user sees the subject certificate link.
        $user = $this->userWithRole();
        $html = $this->actingAs($user)->get('/certificate-requests')->assertOk()->getContent();
        $this->assertStringContainsString(route('certificate-requests.index'), $html);
        $this->assertStringNotContainsString(route('certificate-operator.requests.index'), $html);

        // Only an exact certificate_operator additionally sees the inbox link.
        $operator = $this->operator();
        $opHtml = $this->actingAs($operator)->get('/certificate-requests')->assertOk()->getContent();
        $this->assertStringContainsString(route('certificate-operator.requests.index'), $opHtml);
    }

    // --- operator surface -------------------------------------------------

    public function test_operator_reads_inbox_and_detail(): void
    {
        $operator = $this->operator();
        $user = $this->userWithRole();
        $request = $this->pendingRequestFor($user);

        $this->actingAs($operator)->get('/certificate-operator/requests')
            ->assertOk()
            ->assertViewIs('certificate-operator.requests.index')
            ->assertViewHas('requests', fn (LengthAwarePaginator $p): bool => $p->total() === 1);

        $this->actingAs($operator)->get("/certificate-operator/requests/{$request->id}")
            ->assertOk()
            ->assertViewIs('certificate-operator.requests.show')
            ->assertViewHas('request', fn (CertificateRequest $r): bool => (int) $r->user_id === $user->id);
    }

    public function test_operator_inbox_detail_exposes_no_note_text_or_pii(): void
    {
        $operator = $this->operator();
        $user = $this->userWithRole();
        $request = CertificateRequest::factory()->create([
            'user_id' => $user->id,
            'request_note' => 'PRIVATE-REQUEST-NOTE',
        ]);

        $body = $this->actingAs($operator)->get("/certificate-operator/requests/{$request->id}")->getContent();

        $this->assertStringNotContainsString('PRIVATE-REQUEST-NOTE', $body);
        $this->assertStringNotContainsString($user->email, $body);
        $this->assertStringNotContainsString($user->name, $body);
    }

    public function test_plain_employee_is_forbidden_from_the_operator_surface(): void
    {
        $employee = $this->userWithRole('employee');
        $user = $this->userWithRole();
        $request = $this->pendingRequestFor($user);

        $this->actingAs($employee)->get('/certificate-operator/requests')->assertForbidden();
        $this->actingAs($employee)->post("/certificate-operator/requests/{$request->id}/approve")->assertForbidden();
    }

    public function test_admin_without_operator_role_is_forbidden(): void
    {
        $admin = $this->userWithRole('admin');
        $user = $this->userWithRole();
        $request = $this->pendingRequestFor($user);

        $this->actingAs($admin)->get('/certificate-operator/requests')->assertForbidden();
        $this->actingAs($admin)->post("/certificate-operator/requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($admin)->post("/certificate-operator/requests/{$request->id}/reject", ['operator_note' => 'no'])->assertForbidden();
    }

    public function test_operator_cannot_review_their_own_request(): void
    {
        $operator = $this->operator();
        $own = $this->pendingRequestFor($operator);

        $this->actingAs($operator)->post("/certificate-operator/requests/{$own->id}/approve")->assertForbidden();
        $this->actingAs($operator)->post("/certificate-operator/requests/{$own->id}/reject", ['operator_note' => 'self'])->assertForbidden();

        $this->assertSame(Status::PENDING, $own->fresh()->status);
    }

    public function test_reject_requires_a_reason(): void
    {
        $operator = $this->operator();
        $request = $this->pendingRequestFor($this->userWithRole());

        $this->actingAs($operator)
            ->from("/certificate-operator/requests/{$request->id}")
            ->post("/certificate-operator/requests/{$request->id}/reject", ['operator_note' => ''])
            ->assertRedirect("/certificate-operator/requests/{$request->id}")
            ->assertSessionHasErrors('operator_note');

        $this->assertSame(Status::PENDING, $request->fresh()->status);
    }

    public function test_operator_rejects_and_approves(): void
    {
        $operator = $this->operator();

        $rejected = $this->pendingRequestFor($this->userWithRole());
        $this->actingAs($operator)
            ->post("/certificate-operator/requests/{$rejected->id}/reject", ['operator_note' => 'Nepotpuni podaci.'])
            ->assertRedirect("/certificate-operator/requests/{$rejected->id}")
            ->assertSessionHas('success');
        $this->assertSame(Status::REJECTED, $rejected->fresh()->status);

        $this->useAtomicDatabaseQueue();
        $approved = $this->pendingRequestFor($this->userWithRole());
        $this->actingAs($operator)
            ->post("/certificate-operator/requests/{$approved->id}/approve")
            ->assertRedirect("/certificate-operator/requests/{$approved->id}")
            ->assertSessionHas('success');
        $this->assertSame(Status::APPROVED, $approved->fresh()->status);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_reviewing_a_non_pending_request_is_forbidden(): void
    {
        $operator = $this->operator();
        $user = $this->userWithRole();
        $cancelled = CertificateRequest::factory()->cancelled()->create(['user_id' => $user->id]);

        $this->actingAs($operator)->post("/certificate-operator/requests/{$cancelled->id}/approve")->assertForbidden();
        $this->actingAs($operator)->post("/certificate-operator/requests/{$cancelled->id}/reject", ['operator_note' => 'late'])->assertForbidden();
    }

    public function test_operator_inbox_status_filter_is_allow_listed(): void
    {
        $operator = $this->operator();
        $this->pendingRequestFor($this->userWithRole());

        $this->actingAs($operator)->get('/certificate-operator/requests?status=pending')
            ->assertOk()->assertViewHas('requests', fn (LengthAwarePaginator $p): bool => $p->total() === 1);

        $this->actingAs($operator)->get('/certificate-operator/requests?status=issued')
            ->assertOk()->assertViewHas('requests', fn (LengthAwarePaginator $p): bool => $p->total() === 0);

        // An unknown value is ignored rather than interpolated into the query.
        $this->actingAs($operator)->get('/certificate-operator/requests?'.http_build_query(['status' => "' OR 1=1 --"]))
            ->assertOk()->assertViewHas('requests', fn (LengthAwarePaginator $p): bool => $p->total() === 1);
    }
}
