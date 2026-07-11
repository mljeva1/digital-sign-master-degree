<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\PublicVerificationQrCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ContractSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('files');
        Schema::dropIfExists('user_contract_profiles');
        Schema::dropIfExists('users');

        Storage::fake('local');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // The builder route resolves the authenticated user's contract profile
        // (M7.3 party autofill); this table must exist even though these tests
        // never populate it.
        Schema::create('user_contract_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('oib', 11)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();

            $table->index('oib');
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_number')->unique();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('salesperson_user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('place');
            $table->date('contract_date');
            $table->decimal('price_amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->json('filled_data_snapshot');
            $table->unsignedBigInteger('draft_pdf_file_id')->nullable();
            $table->string('draft_pdf_sha256', 64)->nullable();
            $table->unsignedBigInteger('signed_pdf_file_id')->nullable();
            $table->string('signed_pdf_sha256', 64)->nullable();
            $table->unsignedBigInteger('final_pdf_file_id')->nullable();
            $table->string('final_pdf_sha256', 64)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('finalized_snapshot_sha256', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('public_verification_token', 64)->nullable()->unique();
            $table->timestamp('public_verification_enabled_at')->nullable();
            $table->timestamp('public_verification_revoked_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose');
            $table->string('storage_disk');
            $table->string('storage_path')->unique();
            $table->string('original_filename')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_customer_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->boolean('success')->default(true);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('signatures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('contract_party_id')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();
            $table->unsignedBigInteger('signed_user_id')->nullable();
            $table->unsignedBigInteger('signed_customer_id')->nullable();
            $table->string('type', 30)->default('digital');
            $table->string('status', 20);
            $table->timestamp('signed_at')->nullable();
            $table->string('document_hash_before', 64);
            $table->string('document_hash_after', 64)->nullable();
            $table->string('signature_reason')->nullable();
            $table->string('signature_location')->nullable();
            $table->unsignedBigInteger('signature_file_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('files');
        Schema::dropIfExists('user_contract_profiles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_authorized_user_can_save_snapshot_and_receives_json_without_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload()
        );

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'status' => Contract::STATUS_DRAFT,
                'message' => 'Snapshot ugovora je spremljen.',
            ])
            ->assertJsonStructure([
                'contract_id',
                'updated_at',
            ]);

        $this->assertNotSame(302, $response->getStatusCode());
        $this->assertDatabaseHas('contracts', [
            'created_by_user_id' => $user->id,
            'place' => 'Zagreb',
            'status' => Contract::STATUS_DRAFT,
        ]);
    }

    public function test_authenticated_user_can_view_contracts_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertViewIs('contracts.index')
            ->assertViewHas('contracts');
    }

    public function test_index_contains_only_current_users_contracts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownContract = $this->createDraftFor($user);
        $otherContract = $this->createDraftFor($otherUser);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response->assertOk();

        $contracts = $response->viewData('contracts');

        $this->assertTrue($contracts->contains($ownContract));
        $this->assertFalse($contracts->contains($otherContract));
        $response->assertDontSee('Ugovor #'.$otherContract->id);
    }

    public function test_editable_own_draft_shows_continue_editing_link(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertSee('Nastavi uređivanje')
            ->assertSee(route('contracts.builder.edit', $contract), false);
    }

    public function test_locked_or_finalized_contract_does_not_show_continue_editing_link(): void
    {
        $user = User::factory()->create();
        $lockedContract = $this->createDraftFor($user);
        $lockedContract->update(['locked_at' => now()]);

        $finalizedContract = $this->createDraftFor($user);
        $finalizedContract->update(['status' => Contract::STATUS_FULLY_SIGNED]);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertDontSee(route('contracts.builder.edit', $lockedContract), false)
            ->assertDontSee(route('contracts.builder.edit', $finalizedContract), false);
    }

    public function test_index_shows_empty_message_when_user_has_no_contracts(): void
    {
        $user = User::factory()->create();
        $finalizedContract = $this->createDraftFor($user);
        $finalizedContract->update(['status' => Contract::STATUS_FULLY_SIGNED]);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Nemate spremljenih ugovora.');
    }

    public function test_new_contract_button_links_to_create_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Novi ugovor')
            ->assertSee(route('contracts.create'), false);
    }

    public function test_user_can_archive_own_unlocked_draft(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $response = $this->actingAs($user)->patch(
            route('contracts.archive', $contract)
        );

        $response
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHas('success', 'Draft ugovora je arhiviran.');

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => Contract::STATUS_ARCHIVED,
        ]);
    }

    public function test_archived_draft_is_not_shown_on_contracts_index(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee('Ugovor #'.$contract->id)
            ->assertSee('Nemate spremljenih ugovora.');
    }

    public function test_archived_draft_cannot_be_opened_in_builder(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($user)
            ->get(route('contracts.builder.edit', $contract))
            ->assertForbidden();
    }

    public function test_user_cannot_archive_another_users_draft(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->actingAs($otherUser)
            ->patch(route('contracts.archive', $contract))
            ->assertForbidden();

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => Contract::STATUS_DRAFT,
        ]);
    }

    public function test_user_cannot_archive_locked_draft(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['locked_at' => now()]);

        $this->actingAs($user)
            ->patch(route('contracts.archive', $contract))
            ->assertForbidden();

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => Contract::STATUS_DRAFT,
        ]);
    }

    public function test_user_cannot_archive_finalized_contract(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_FULLY_SIGNED]);

        $this->actingAs($user)
            ->patch(route('contracts.archive', $contract))
            ->assertForbidden();

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => Contract::STATUS_FULLY_SIGNED,
        ]);
    }

    public function test_guest_cannot_archive_draft(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->patch(route('contracts.archive', $contract))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => Contract::STATUS_DRAFT,
        ]);
    }

    public function test_archiving_draft_creates_audit_event(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->patch(route('contracts.archive', $contract))
            ->assertRedirect(route('contracts.index'));

        $event = AuditEvent::query()
            ->where('action', 'contract.draft_archived')
            ->firstOrFail();

        $this->assertSame($contract->id, $event->entity_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame(Contract::STATUS_DRAFT, $event->metadata['previous_status']);
        $this->assertSame(Contract::STATUS_ARCHIVED, $event->metadata['new_status']);
    }

    public function test_archive_button_is_shown_only_for_own_unlocked_drafts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $editableContract = $this->createDraftFor($user);
        $lockedContract = $this->createDraftFor($user);
        $lockedContract->update(['locked_at' => now()]);
        $otherContract = $this->createDraftFor($otherUser);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertSee('Arhiviraj')
            ->assertSee(route('contracts.archive', $editableContract), false)
            ->assertDontSee(route('contracts.archive', $lockedContract), false)
            ->assertDontSee(route('contracts.archive', $otherContract), false);
    }

    public function test_archive_redirect_does_not_change_snapshot_json_response(): void
    {
        $user = User::factory()->create();

        $archiveResponse = $this->actingAs($user)->patch(
            route('contracts.archive', $this->createDraftFor($user))
        );

        $snapshotResponse = $this->actingAs($user)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload()
        );

        $archiveResponse->assertRedirect(route('contracts.index'));
        $snapshotResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotSame(302, $snapshotResponse->getStatusCode());
    }

    public function test_user_can_generate_draft_pdf_for_own_unlocked_draft(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $response = $this->actingAs($user)->post(
            route('contracts.draft-pdf.store', $contract)
        );

        $response
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHas('success', 'Probni PDF je generiran.');

        $contract->refresh();

        $this->assertNotNull($contract->draft_pdf_file_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract->draft_pdf_sha256);
    }

    public function test_generated_draft_pdf_is_stored_on_private_local_disk_with_hash(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $contract->refresh();
        $storedFile = StoredFile::query()->findOrFail($contract->draft_pdf_file_id);

        Storage::disk('local')->assertExists($storedFile->storage_path);

        $content = Storage::disk('local')->get($storedFile->storage_path);

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame(hash('sha256', $content), $storedFile->sha256);
        $this->assertSame($storedFile->sha256, $contract->draft_pdf_sha256);
        $this->assertSame(StoredFile::PURPOSE_DRAFT_PDF, $storedFile->purpose);
        $this->assertSame(StoredFile::DISK_LOCAL, $storedFile->storage_disk);
        $this->assertSame("contracts/{$contract->id}/draft-preview.pdf", $storedFile->storage_path);
        $this->assertNotNull($storedFile->updated_at);
    }

    public function test_generating_draft_pdf_creates_audit_event(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $event = AuditEvent::query()
            ->where('action', 'contract.draft_pdf_generated')
            ->firstOrFail();

        $this->assertSame($contract->id, $event->entity_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertArrayNotHasKey('draft_pdf_path', $event->metadata);
        $this->assertStringNotContainsString(
            "contracts/{$contract->id}",
            json_encode($event->metadata, JSON_THROW_ON_ERROR)
        );
        $this->assertSame($contract->fresh()->draft_pdf_file_id, $event->metadata['file_id']);
        $this->assertSame(StoredFile::PURPOSE_DRAFT_PDF, $event->metadata['purpose']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->metadata['draft_pdf_sha256']);
        $this->assertNotEmpty($event->metadata['generated_at']);
    }

    public function test_user_cannot_generate_draft_pdf_for_another_users_contract(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->actingAs($otherUser)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertForbidden();

        $this->assertNull($contract->fresh()->draft_pdf_file_id);
    }

    public function test_locked_draft_cannot_generate_pdf(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['locked_at' => now()]);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertForbidden();
    }

    public function test_finalized_contract_cannot_generate_draft_pdf(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_FULLY_SIGNED]);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertForbidden();
    }

    public function test_archived_contract_cannot_generate_draft_pdf(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertForbidden();
    }

    public function test_draft_without_snapshot_cannot_generate_pdf(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['filled_data_snapshot' => []]);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertUnprocessable();

        $this->assertNull($contract->fresh()->draft_pdf_file_id);
    }

    public function test_draft_pdf_button_is_shown_only_for_own_unlocked_drafts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $editableContract = $this->createDraftFor($user);
        $lockedContract = $this->createDraftFor($user);
        $lockedContract->update(['locked_at' => now()]);
        $otherContract = $this->createDraftFor($otherUser);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertSee('Generiraj probni PDF')
            ->assertSee(route('contracts.draft-pdf.store', $editableContract), false)
            ->assertDontSee(route('contracts.draft-pdf.store', $lockedContract), false)
            ->assertDontSee(route('contracts.draft-pdf.store', $otherContract), false);
    }

    public function test_index_shows_generated_draft_pdf_information(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $contract->refresh();

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Probni PDF generiran')
            ->assertSee(substr($contract->draft_pdf_sha256, 0, 12));
    }

    public function test_draft_pdf_generation_does_not_change_snapshot_json_response(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $snapshotResponse = $this->actingAs($user)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload(['contract_id' => $contract->id])
        );

        $snapshotResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('contract_id', $contract->id);

        $this->assertNotSame(302, $snapshotResponse->getStatusCode());
    }

    public function test_owner_can_view_own_draft_pdf_from_private_storage(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $content = '%PDF-1.4 private draft content';
        $storedFile = $this->attachDraftPdf($contract, $user, $content);

        $response = $this->actingAs($user)->get(
            route('contracts.draft-pdf.show', $contract)
        );

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="'.$storedFile->original_filename.'"');

        $this->assertSame($content, $response->getContent());
    }

    public function test_other_user_cannot_view_draft_pdf(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);
        $this->attachDraftPdf($contract, $owner);

        $this->actingAs($otherUser)
            ->get(route('contracts.draft-pdf.show', $contract))
            ->assertForbidden();
    }

    public function test_guest_cannot_view_draft_pdf(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);
        $this->attachDraftPdf($contract, $owner);

        $this->get(route('contracts.draft-pdf.show', $contract))
            ->assertRedirect(route('login'));
    }

    public function test_draft_without_pdf_file_reference_returns_not_found(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.show', $contract))
            ->assertNotFound();
    }

    public function test_missing_private_pdf_file_returns_not_found(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $this->attachDraftPdf($contract, $user, storeContent: false);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.show', $contract))
            ->assertNotFound();
    }

    public function test_owner_can_verify_matching_draft_pdf_sha256(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $content = '%PDF-1.4 verified draft';
        $storedFile = $this->attachDraftPdf($contract, $user, $content);
        $expectedSha256 = hash('sha256', $content);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertOk()
            ->assertViewIs('contracts.draft-pdf-verify')
            ->assertViewHas('valid', true)
            ->assertViewHas('expectedSha256', $expectedSha256)
            ->assertViewHas('actualSha256', $expectedSha256)
            ->assertSee('Integritet probnog PDF-a je potvrđen.')
            ->assertSee($storedFile->original_filename)
            ->assertSee($storedFile->storage_path);
    }

    public function test_draft_pdf_verification_reports_hash_mismatch(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $storedFile = $this->attachDraftPdf($contract, $user, '%PDF-1.4 original');
        $changedContent = '%PDF-1.4 changed after generation';

        Storage::disk('local')->put($storedFile->storage_path, $changedContent);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertOk()
            ->assertViewHas('valid', false)
            ->assertViewHas('actualSha256', hash('sha256', $changedContent))
            ->assertSee('Integritet probnog PDF-a nije potvrđen.');
    }

    public function test_other_user_cannot_verify_draft_pdf(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);
        $this->attachDraftPdf($contract, $owner);

        $this->actingAs($otherUser)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertForbidden();
    }

    public function test_archived_contract_cannot_view_or_verify_draft_pdf(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $this->attachDraftPdf($contract, $user);
        $contract->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.show', $contract))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertForbidden();
    }

    public function test_locked_draft_cannot_view_or_verify_draft_pdf(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $this->attachDraftPdf($contract, $user);
        $contract->update(['locked_at' => now()]);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.show', $contract))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertForbidden();
    }

    public function test_verifying_draft_pdf_creates_audit_event_with_hash_result(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $content = '%PDF-1.4 audit verification';
        $this->attachDraftPdf($contract, $user, $content);
        $expectedSha256 = hash('sha256', $content);

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertOk();

        $event = AuditEvent::query()
            ->where('action', 'contract.draft_pdf_verified')
            ->firstOrFail();

        $this->assertSame($contract->id, $event->entity_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($expectedSha256, $event->metadata['expected_sha256']);
        $this->assertSame($expectedSha256, $event->metadata['actual_sha256']);
        $this->assertTrue($event->metadata['valid']);
    }

    public function test_index_shows_download_and_verify_links_only_for_available_draft_pdf(): void
    {
        $user = User::factory()->create();
        $contractWithPdf = $this->createDraftFor($user);
        $this->attachDraftPdf($contractWithPdf, $user);
        $contractWithoutPdf = $this->createDraftFor($user);
        $lockedContract = $this->createDraftFor($user);
        $this->attachDraftPdf($lockedContract, $user);
        $lockedContract->update(['locked_at' => now()]);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertSee('Otvori probni PDF')
            ->assertSee('Provjeri SHA-256')
            ->assertSee('href="'.route('contracts.draft-pdf.show', $contractWithPdf).'"', false)
            ->assertSee('href="'.route('contracts.draft-pdf.verify', $contractWithPdf).'"', false)
            ->assertDontSee('href="'.route('contracts.draft-pdf.show', $contractWithoutPdf).'"', false)
            ->assertDontSee('href="'.route('contracts.draft-pdf.verify', $contractWithoutPdf).'"', false)
            ->assertDontSee('href="'.route('contracts.draft-pdf.show', $lockedContract).'"', false)
            ->assertDontSee('href="'.route('contracts.draft-pdf.verify', $lockedContract).'"', false);
    }

    public function test_index_does_not_show_verify_link_when_saved_hash_is_missing(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $this->attachDraftPdf($contract, $user);
        $contract->update(['draft_pdf_sha256' => null]);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('href="'.route('contracts.draft-pdf.show', $contract).'"', false)
            ->assertDontSee('href="'.route('contracts.draft-pdf.verify', $contract).'"', false);
    }

    public function test_owner_can_open_existing_unlocked_draft_in_builder(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $response = $this->actingAs($owner)->get(
            route('contracts.builder.edit', $contract)
        );

        $response
            ->assertOk()
            ->assertViewIs('contracts.builder')
            ->assertSee('Nastavljate uređivanje spremljenog drafta.');
    }

    public function test_builder_receives_data_needed_to_resume_editing(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $response = $this->actingAs($owner)->get(
            route('contracts.builder.edit', $contract)
        );

        $response
            ->assertViewHas('contractId', $contract->id)
            ->assertViewHas('snapshot', $contract->filled_data_snapshot)
            ->assertViewHas('contractStatus', Contract::STATUS_DRAFT)
            ->assertViewHas('contractUpdatedAt');
    }

    public function test_user_cannot_open_another_users_draft(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $response = $this->actingAs($otherUser)->get(
            route('contracts.builder.edit', $contract)
        );

        $response->assertForbidden();
    }

    public function test_locked_or_finalized_contract_cannot_be_opened_for_editing(): void
    {
        $owner = User::factory()->create();
        $lockedContract = $this->createDraftFor($owner);
        $lockedContract->update(['locked_at' => now()]);

        $finalizedContract = $this->createDraftFor($owner);
        $finalizedContract->update([
            'status' => Contract::STATUS_FULLY_SIGNED,
        ]);

        $this->actingAs($owner)
            ->get(route('contracts.builder.edit', $lockedContract))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('contracts.builder.edit', $finalizedContract))
            ->assertForbidden();
    }

    public function test_empty_create_builder_still_works_without_contract_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('contracts.create'));

        $response
            ->assertOk()
            ->assertViewIs('contracts.builder')
            ->assertViewHas('contractId', null)
            ->assertViewHas('snapshot', [])
            ->assertDontSee('Nastavljate uređivanje spremljenog drafta.');
    }

    public function test_snapshot_save_after_reopening_updates_same_contract(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->actingAs($owner)
            ->get(route('contracts.builder.edit', $contract))
            ->assertOk();

        $response = $this->actingAs($owner)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload([
                'contract_id' => $contract->id,
                'place' => 'Split',
            ])
        );

        $response
            ->assertOk()
            ->assertJsonPath('contract_id', $contract->id);

        $this->assertSame(1, Contract::query()->count());
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'place' => 'Split',
        ]);
    }

    public function test_user_cannot_update_another_users_snapshot(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $response = $this->actingAs($otherUser)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload(['contract_id' => $contract->id])
        );

        $response->assertForbidden();
    }

    public function test_validation_rejects_invalid_snapshot_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload([
                'place' => '',
                'contract_date' => 'not-a-date',
                'price_amount' => -1,
                'seller_oib' => '123',
            ])
        );

        $response->assertUnprocessable();

        $errors = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors'];

        $this->assertArrayHasKey('place', $errors);
        $this->assertArrayHasKey('contract_date', $errors);
        $this->assertArrayHasKey('price_amount', $errors);
        $this->assertArrayHasKey('seller_oib', $errors);
    }

    public function test_owner_can_validate_complete_draft_snapshot(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertOk()
            ->assertExactJson([
                'valid' => true,
                'missing_fields' => [],
                'invalid_fields' => [],
            ]);

        $contract->refresh();

        $this->assertSame(Contract::STATUS_DRAFT, $contract->status);
        $this->assertNull($contract->locked_at);
    }

    public function test_empty_snapshot_returns_all_required_fields_as_missing(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['filled_data_snapshot' => []]);

        $response = $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonCount(9, 'missing_fields')
            ->assertJsonCount(0, 'invalid_fields');

        $this->assertSame([
            'seller_name',
            'seller_oib',
            'buyer_name',
            'buyer_oib',
            'vehicle_brand',
            'price_amount',
            'contract_date',
            'place',
            'vin',
        ], array_column($response->json('missing_fields'), 'key'));
    }

    public function test_snapshot_without_required_fields_returns_missing_fields(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $snapshot = $this->validPayload();
        unset($snapshot['seller_name'], $snapshot['buyer_oib']);
        $contract->update(['filled_data_snapshot' => $snapshot]);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonFragment([
                'key' => 'seller_name',
                'label' => 'Prodavatelj',
            ])
            ->assertJsonFragment([
                'key' => 'buyer_oib',
                'label' => 'OIB kupca',
            ])
            ->assertJsonCount(2, 'missing_fields')
            ->assertJsonCount(0, 'invalid_fields');
    }

    public function test_oib_without_eleven_digits_is_invalid(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update([
            'filled_data_snapshot' => $this->validPayload([
                'seller_oib' => '123',
                'buyer_oib' => '1234567890A',
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonCount(0, 'missing_fields')
            ->assertJsonCount(2, 'invalid_fields')
            ->assertJsonFragment([
                'key' => 'seller_oib',
                'label' => 'OIB prodavatelja',
                'reason' => 'OIB prodavatelja mora imati 11 znamenki.',
            ])
            ->assertJsonFragment([
                'key' => 'buyer_oib',
                'label' => 'OIB kupca',
                'reason' => 'OIB kupca mora imati 11 znamenki.',
            ]);
    }

    public function test_zero_negative_and_non_numeric_prices_are_invalid(): void
    {
        $user = User::factory()->create();

        foreach ([0, -1, 'nije-broj'] as $price) {
            $contract = $this->createDraftFor($user);
            $contract->update([
                'filled_data_snapshot' => $this->validPayload(['price_amount' => $price]),
            ]);

            $this->actingAs($user)
                ->getJson(route('contracts.required-fields.validate', $contract))
                ->assertOk()
                ->assertJsonPath('valid', false)
                ->assertJsonCount(1, 'invalid_fields')
                ->assertJsonFragment([
                    'key' => 'price_amount',
                    'label' => 'Cijena',
                    'reason' => 'Cijena mora biti numerička vrijednost veća od 0.',
                ]);
        }
    }

    public function test_invalid_contract_date_is_reported(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update([
            'filled_data_snapshot' => $this->validPayload([
                'contract_date' => '2026-02-30',
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonCount(1, 'invalid_fields')
            ->assertJsonFragment([
                'key' => 'contract_date',
                'label' => 'Datum ugovora',
                'reason' => 'Datum ugovora mora biti valjan datum.',
            ]);
    }

    public function test_other_user_cannot_validate_required_fields(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->actingAs($otherUser)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertForbidden();
    }

    public function test_archived_draft_cannot_validate_required_fields(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertForbidden();
    }

    public function test_locked_draft_cannot_validate_required_fields(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['locked_at' => now()]);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertForbidden();
    }

    public function test_required_fields_validation_audit_contains_only_field_names_and_result(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update([
            'filled_data_snapshot' => $this->validPayload([
                'seller_name' => '',
                'buyer_oib' => '123',
            ]),
        ]);

        $this->actingAs($user)
            ->getJson(route('contracts.required-fields.validate', $contract))
            ->assertOk();

        $event = AuditEvent::query()
            ->where('action', 'contract.required_fields_validated')
            ->firstOrFail();

        $this->assertFalse($event->metadata['valid']);
        $this->assertSame(['seller_name'], $event->metadata['missing_fields']);
        $this->assertSame(['buyer_oib'], $event->metadata['invalid_fields']);
        $this->assertStringNotContainsString(
            '12345678901',
            json_encode($event->metadata, JSON_THROW_ON_ERROR)
        );
    }

    public function test_owner_can_finalize_valid_active_draft_as_locked_immutable_snapshot(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $snapshot = $contract->filled_data_snapshot;
        $expectedSha256 = hash(
            'sha256',
            json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertOk()
            ->assertExactJson([
                'message' => 'Ugovor je finaliziran i zaključan.',
                'contract_id' => $contract->id,
                'status' => Contract::STATUS_FINALIZED,
                'locked' => true,
                'snapshot_sha256' => $expectedSha256,
                'redirect_url' => route('contracts.index'),
            ]);

        $contract->refresh();

        $this->assertSame(Contract::STATUS_FINALIZED, $contract->status);
        $this->assertTrue($contract->isLocked());
        $this->assertNotNull($contract->finalized_at);
        $this->assertSame($expectedSha256, $contract->finalized_snapshot_sha256);
        $this->assertSame($snapshot, $contract->filled_data_snapshot);
    }

    public function test_builder_for_unlocked_draft_shows_finalize_button_and_confirmation_modal(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->get(route('contracts.builder.edit', $contract))
            ->assertOk()
            ->assertSee('Finaliziraj i zaključaj')
            ->assertSee('Finalizirati ugovor?')
            ->assertSee('Nakon finalizacije ugovor više neće biti moguće uređivati.')
            ->assertSee('Ova akcija nije digitalni potpis.')
            ->assertSee('const finalizeUrl =', false);
    }

    public function test_builder_does_not_expose_finalize_ui_for_locked_finalized_or_archived_contract(): void
    {
        $user = User::factory()->create();
        $locked = $this->createDraftFor($user);
        $locked->update(['locked_at' => now()]);
        $finalized = $this->createFinalizedContractFor($user);
        $archived = $this->createDraftFor($user);
        $archived->update(['status' => Contract::STATUS_ARCHIVED]);

        foreach ([$locked, $finalized, $archived] as $contract) {
            $this->actingAs($user)
                ->get(route('contracts.builder.edit', $contract))
                ->assertForbidden()
                ->assertDontSee('Finaliziraj i zaključaj');
        }
    }

    public function test_builder_finalize_flow_redirects_to_index_with_success_flash(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $response = $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract));

        $response
            ->assertOk()
            ->assertJsonPath('redirect_url', route('contracts.index'))
            ->assertJsonPath('message', 'Ugovor je finaliziran i zaključan.')
            ->assertSessionHas('success', 'Ugovor je finaliziran i zaključan.');

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Ugovor je finaliziran i zaključan.');
    }

    public function test_builder_finalize_script_handles_unsaved_changes_and_validation_fields(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->get(route('contracts.builder.edit', $contract))
            ->assertOk()
            ->assertSee('Imate nespremljene promjene. Prvo spremite promjene prije finalizacije.')
            ->assertSee('missing_fields', false)
            ->assertSee('invalid_fields', false)
            ->assertSee('Ugovor nije spreman za finalizaciju.')
            ->assertSee('window.location.assign(data.redirect_url)', false);
    }

    public function test_finalization_creates_audit_event_without_sensitive_snapshot_values(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $snapshot = $contract->filled_data_snapshot;

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertOk();

        $event = AuditEvent::query()
            ->where('action', 'contract.finalized')
            ->firstOrFail();
        $metadataJson = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        $this->assertSame($contract->id, $event->entity_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame(Contract::STATUS_DRAFT, $event->metadata['status_before']);
        $this->assertSame(Contract::STATUS_FINALIZED, $event->metadata['status_after']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $event->metadata['snapshot_sha256']
        );
        $this->assertNotEmpty($event->metadata['finalized_at']);
        $this->assertNotEmpty($event->metadata['locked_at']);

        foreach ([
            $snapshot['seller_name'],
            $snapshot['seller_oib'],
            $snapshot['buyer_name'],
            $snapshot['buyer_oib'],
            $snapshot['vehicle_brand'],
            (string) $snapshot['price_amount'],
            $snapshot['vin'],
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $metadataJson);
        }
    }

    public function test_draft_without_snapshot_cannot_be_finalized(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['filled_data_snapshot' => []]);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ugovor nije spreman za finalizaciju.')
            ->assertJsonPath('valid', false)
            ->assertJsonCount(9, 'missing_fields')
            ->assertJsonCount(0, 'invalid_fields');

        $contract->refresh();

        $this->assertSame(Contract::STATUS_DRAFT, $contract->status);
        $this->assertFalse($contract->isLocked());
        $this->assertNull($contract->finalized_at);
        $this->assertNull($contract->finalized_snapshot_sha256);
    }

    public function test_draft_with_missing_required_fields_cannot_be_finalized(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $snapshot = $contract->filled_data_snapshot;
        unset($snapshot['seller_name'], $snapshot['vin']);
        $contract->update(['filled_data_snapshot' => $snapshot]);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertUnprocessable()
            ->assertJsonPath('valid', false)
            ->assertJsonCount(2, 'missing_fields')
            ->assertJsonFragment([
                'key' => 'seller_name',
                'label' => 'Prodavatelj',
            ])
            ->assertJsonFragment([
                'key' => 'vin',
                'label' => 'VIN / broj šasije',
            ]);
    }

    public function test_draft_with_invalid_oib_price_and_date_cannot_be_finalized(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update([
            'filled_data_snapshot' => $this->validPayload([
                'seller_oib' => '123',
                'price_amount' => 0,
                'contract_date' => '2026-02-30',
            ]),
        ]);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertUnprocessable()
            ->assertJsonPath('valid', false)
            ->assertJsonCount(0, 'missing_fields')
            ->assertJsonCount(3, 'invalid_fields')
            ->assertJsonFragment(['key' => 'seller_oib'])
            ->assertJsonFragment(['key' => 'price_amount'])
            ->assertJsonFragment(['key' => 'contract_date']);

        $this->assertSame(Contract::STATUS_DRAFT, $contract->fresh()->status);
    }

    public function test_other_user_cannot_finalize_draft(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->actingAs($otherUser)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertForbidden();

        $this->assertSame(Contract::STATUS_DRAFT, $contract->fresh()->status);
    }

    public function test_archived_contract_cannot_be_finalized(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertForbidden();
    }

    public function test_locked_or_already_finalized_contract_cannot_be_finalized_again(): void
    {
        $user = User::factory()->create();
        $lockedDraft = $this->createDraftFor($user);
        $lockedDraft->update(['locked_at' => now()]);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $lockedDraft))
            ->assertForbidden();

        $finalizedContract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $finalizedContract))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $finalizedContract))
            ->assertForbidden();

        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'contract.finalized')
                ->where('entity_id', $finalizedContract->id)
                ->count()
        );
    }

    public function test_finalized_contract_cannot_be_opened_in_builder(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('contracts.builder.edit', $contract))
            ->assertForbidden();
    }

    public function test_snapshot_save_cannot_modify_finalized_contract(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $originalSnapshot = $contract->filled_data_snapshot;

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(
                route('contracts.snapshot.store'),
                $this->validPayload([
                    'contract_id' => $contract->id,
                    'place' => 'Split',
                ])
            )
            ->assertUnprocessable();

        $contract->refresh();

        $this->assertSame(Contract::STATUS_FINALIZED, $contract->status);
        $this->assertSame('Zagreb', $contract->place);
        $this->assertSame($originalSnapshot, $contract->filled_data_snapshot);
    }

    public function test_finalized_contract_cannot_use_draft_pdf_routes_or_archive(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $this->attachDraftPdf($contract, $user);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('contracts.draft-pdf.store', $contract))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.show', $contract))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('contracts.draft-pdf.verify', $contract))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('contracts.archive', $contract))
            ->assertForbidden();
    }

    public function test_audit_logger_records_standard_contract_event_and_sanitizes_metadata(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $snapshotSha256 = hash('sha256', 'safe-hash-source');

        $event = $this->actingAs($user)
            ->app
            ->make(AuditLogger::class)
            ->record('contract.test_event', $contract, [
                'status_before' => Contract::STATUS_DRAFT,
                'snapshot_sha256' => $snapshotSha256,
                'seller_name' => 'Ivan Horvat',
                'nested' => [
                    'buyer_oib' => '10987654321',
                    'valid' => true,
                ],
            ]);

        $this->assertSame('contract.test_event', $event->action);
        $this->assertSame('Contract', $event->entity_type);
        $this->assertSame($contract->id, $event->entity_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame(Contract::STATUS_DRAFT, $event->metadata['status_before']);
        $this->assertSame($snapshotSha256, $event->metadata['snapshot_sha256']);
        $this->assertSame('[REDACTED]', $event->metadata['seller_name']);
        $this->assertSame('[REDACTED]', $event->metadata['nested']['buyer_oib']);
        $this->assertTrue($event->metadata['nested']['valid']);
    }

    public function test_owner_can_view_only_own_contract_audit_timeline(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);
        $otherContract = $this->createDraftFor($owner);
        $logger = $this->actingAs($owner)->app->make(AuditLogger::class);

        $logger->record('contract.timeline_expected', $contract, [
            'operation' => 'expected-operation',
        ]);
        $logger->record('contract.timeline_other', $otherContract, [
            'operation' => 'other-operation',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('contracts.audit.index', $contract));

        $response
            ->assertOk()
            ->assertViewIs('contracts.audit.index')
            ->assertViewHas('contract', fn (Contract $viewContract): bool => $viewContract->is($contract))
            ->assertSee('Audit trag ugovora #'.$contract->id)
            ->assertSee('contract.timeline_expected')
            ->assertSee('expected-operation')
            ->assertDontSee('contract.timeline_other')
            ->assertDontSee('other-operation');

        $events = $response->viewData('events');

        $this->assertTrue($events->every(
            fn (AuditEvent $event): bool => $event->entity_id === $contract->id
        ));
        $auditViewedEvent = AuditEvent::query()->latest('id')->firstOrFail();

        $this->assertSame('contract.audit_viewed', $auditViewedEvent->action);
        $this->assertSame(
            'contracts.audit.index',
            $auditViewedEvent->metadata['route_name']
        );
    }

    public function test_other_user_cannot_view_contract_audit_timeline(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        $this->actingAs($otherUser)
            ->get(route('contracts.audit.index', $contract))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'contract.audit_viewed',
            'entity_id' => $contract->id,
        ]);
    }

    public function test_audit_timeline_redacts_sensitive_metadata_from_legacy_event(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);

        AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_user_id' => $owner->id,
            'action' => 'contract.legacy_event',
            'entity_type' => 'Contract',
            'entity_id' => $contract->id,
            'success' => true,
            'metadata' => [
                'seller_name' => 'Osjetljivo Ime',
                'buyer_oib' => '10987654321',
                'price' => 4500,
                'status_after' => Contract::STATUS_DRAFT,
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('contracts.audit.index', $contract))
            ->assertOk()
            ->assertSee('contract.legacy_event')
            ->assertSee('[REDACTED]')
            ->assertSee(Contract::STATUS_DRAFT)
            ->assertDontSee('Osjetljivo Ime')
            ->assertDontSee('10987654321')
            ->assertDontSee('4500');
    }

    public function test_audit_sanitizer_redacts_storage_path_keys_including_nested(): void
    {
        $logger = app(AuditLogger::class);

        $sanitized = $logger->sanitizeMetadata([
            'storage_path' => 'contracts/1/final-contract.pdf',
            'draft_pdf_path' => 'contracts/1/draft-preview.pdf',
            'final_pdf_path' => 'contracts/1/final-contract.pdf',
            'file_path' => 'contracts/1/final-contract.pdf',
            'path' => 'contracts/1/final-contract.pdf',
            'file_id' => 42,
            'purpose' => StoredFile::PURPOSE_FINAL_PDF,
            'draft_pdf_sha256' => str_repeat('a', 64),
            'nested' => [
                'storage_path' => 'contracts/1/final-contract.pdf',
                'inner' => [
                    'file_path' => 'contracts/1/final-contract.pdf',
                    'size_bytes' => 1024,
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['storage_path']);
        $this->assertSame('[REDACTED]', $sanitized['draft_pdf_path']);
        $this->assertSame('[REDACTED]', $sanitized['final_pdf_path']);
        $this->assertSame('[REDACTED]', $sanitized['file_path']);
        $this->assertSame('[REDACTED]', $sanitized['path']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['storage_path']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['inner']['file_path']);

        // Safe technical data must survive sanitization.
        $this->assertSame(42, $sanitized['file_id']);
        $this->assertSame(StoredFile::PURPOSE_FINAL_PDF, $sanitized['purpose']);
        $this->assertSame(str_repeat('a', 64), $sanitized['draft_pdf_sha256']);
        $this->assertSame(1024, $sanitized['nested']['inner']['size_bytes']);

        $this->assertStringNotContainsString(
            'contracts/1',
            json_encode($sanitized, JSON_THROW_ON_ERROR)
        );
    }

    public function test_audit_timeline_redacts_storage_path_from_legacy_event(): void
    {
        $owner = User::factory()->create();
        $contract = $this->createDraftFor($owner);
        $secretPath = "contracts/{$contract->id}/final-contract.pdf";

        AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_user_id' => $owner->id,
            'action' => 'contract.legacy_path_event',
            'entity_type' => 'Contract',
            'entity_id' => $contract->id,
            'success' => true,
            'metadata' => [
                'storage_path' => $secretPath,
                'draft_pdf_path' => $secretPath,
                'final_pdf_sha256' => str_repeat('b', 64),
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('contracts.audit.index', $contract))
            ->assertOk()
            ->assertSee('contract.legacy_path_event')
            ->assertSee('[REDACTED]')
            ->assertSee(str_repeat('b', 64))
            ->assertDontSee($secretPath);
    }

    public function test_final_pdf_viewed_audit_event_contains_no_private_path(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $storedFile = $this->attachFinalPdf($contract, $user, '%PDF-1.4 final view audit');

        $this->actingAs($user)
            ->get(route('contracts.final-pdf.show', $contract))
            ->assertOk();

        $event = AuditEvent::query()
            ->where('action', 'contract.final_pdf_viewed')
            ->firstOrFail();

        $this->assertArrayNotHasKey('storage_path', $event->metadata);
        $this->assertStringNotContainsString(
            "contracts/{$contract->id}",
            json_encode($event->metadata, JSON_THROW_ON_ERROR)
        );
        $this->assertSame($storedFile->id, $event->metadata['file_id']);
        $this->assertSame(StoredFile::PURPOSE_FINAL_PDF, $event->metadata['purpose']);
    }

    public function test_contracts_index_contains_audit_timeline_link(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Audit trag')
            ->assertSee(route('contracts.audit.index', $contract), false);
    }

    public function test_failed_finalization_creates_safe_audit_event(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $contract->update([
            'filled_data_snapshot' => $this->validPayload([
                'seller_name' => '',
                'buyer_oib' => '123',
            ]),
        ]);

        $this->actingAs($user)
            ->postJson(route('contracts.finalize.store', $contract))
            ->assertUnprocessable();

        $event = AuditEvent::query()
            ->where('action', 'contract.finalization_failed')
            ->firstOrFail();

        $this->assertFalse($event->metadata['valid']);
        $this->assertSame(['seller_name'], $event->metadata['missing_fields']);
        $this->assertSame(['buyer_oib'], $event->metadata['invalid_fields']);
        $this->assertStringNotContainsString(
            '123',
            json_encode($event->metadata, JSON_THROW_ON_ERROR)
        );
    }

    public function test_audit_events_are_append_only_through_eloquent(): void
    {
        $user = User::factory()->create();
        $contract = $this->createDraftFor($user);
        $event = $this->actingAs($user)
            ->app
            ->make(AuditLogger::class)
            ->record('contract.append_only_test', $contract);

        try {
            $event->update(['action' => 'contract.changed']);
            $this->fail('Audit event update mora biti odbijen.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit zapisi su nepromjenjivi.', $exception->getMessage());
        }

        $event->refresh();

        try {
            $event->delete();
            $this->fail('Audit event delete mora biti odbijen.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit zapisi se ne mogu brisati.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'action' => 'contract.append_only_test',
        ]);
    }

    public function test_owner_can_generate_final_pdf_for_finalized_locked_contract(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHas('success', 'Finalni PDF je generiran.');

        $contract->refresh();

        $this->assertNotNull($contract->final_pdf_file_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract->final_pdf_sha256);
        $this->assertSame(Contract::STATUS_FINALIZED, $contract->status);
    }

    public function test_final_pdf_generation_requires_finalized_locked_contract(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraftFor($user);
        $archived = $this->createDraftFor($user);
        $archived->update(['status' => Contract::STATUS_ARCHIVED]);
        $unlocked = $this->createFinalizedContractFor($user);
        $unlocked->update(['locked_at' => null]);

        foreach ([$draft, $archived, $unlocked] as $contract) {
            $this->actingAs($user)
                ->post(route('contracts.final-pdf.store', $contract))
                ->assertForbidden();

            $this->assertNull($contract->fresh()->final_pdf_file_id);
        }
    }

    public function test_final_pdf_generation_requires_finalization_metadata(): void
    {
        $user = User::factory()->create();
        $withoutFinalizedAt = $this->createFinalizedContractFor($user);
        $withoutFinalizedAt->update(['finalized_at' => null]);

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $withoutFinalizedAt))
            ->assertUnprocessable();

        $withoutSnapshotHash = $this->createFinalizedContractFor($user);
        $withoutSnapshotHash->update(['finalized_snapshot_sha256' => null]);

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $withoutSnapshotHash))
            ->assertUnprocessable();

        $withoutSnapshot = $this->createFinalizedContractFor($user);
        $withoutSnapshot->update(['filled_data_snapshot' => []]);

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $withoutSnapshot))
            ->assertUnprocessable();
    }

    public function test_final_pdf_generation_rejects_changed_finalized_snapshot(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $changedSnapshot = $contract->filled_data_snapshot;
        $changedSnapshot['place'] = 'Split';
        $contract->update(['filled_data_snapshot' => $changedSnapshot]);

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $contract))
            ->assertUnprocessable();

        $this->assertNull($contract->fresh()->final_pdf_file_id);
    }

    public function test_other_user_cannot_generate_final_pdf(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createFinalizedContractFor($owner);

        $this->actingAs($otherUser)
            ->post(route('contracts.final-pdf.store', $contract))
            ->assertForbidden();
    }

    public function test_generated_final_pdf_is_stored_privately_with_file_metadata_and_hash(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $draftFile = $this->attachDraftPdf($contract, $user);

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $contract->refresh();
        $storedFile = StoredFile::query()->findOrFail($contract->final_pdf_file_id);
        $expectedPath = "contracts/{$contract->id}/final-contract.pdf";

        Storage::disk('local')->assertExists($expectedPath);

        $content = Storage::disk('local')->get($expectedPath);

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame(hash('sha256', $content), $contract->final_pdf_sha256);
        $this->assertSame($contract->final_pdf_sha256, $storedFile->sha256);
        $this->assertSame(StoredFile::PURPOSE_FINAL_PDF, $storedFile->purpose);
        $this->assertSame(StoredFile::DISK_LOCAL, $storedFile->storage_disk);
        $this->assertSame($expectedPath, $storedFile->storage_path);
        $this->assertSame('application/pdf', $storedFile->mime_type);
        $this->assertSame($draftFile->id, $contract->draft_pdf_file_id);
        $this->assertNotSame($draftFile->id, $contract->final_pdf_file_id);
    }

    public function test_final_pdf_template_contains_professional_contract_sections_and_notice(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        $html = view('contracts.pdf.final', [
            'contract' => $contract,
            'snapshot' => $contract->filled_data_snapshot,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('UGOVOR O KUPOPRODAJI MOTORNOG VOZILA', $html);
        $this->assertStringContainsString('FINALIZIRANI UGOVOR', $html);
        $this->assertStringContainsString('PRODAVATELJ', $html);
        $this->assertStringContainsString('KUPAC', $html);
        $this->assertStringContainsString('zaključili su u (mjesto)', $html);
        $this->assertStringContainsString('Prodavatelj prodaje kupcu motorno vozilo:', $html);
        $this->assertStringContainsString('Registarska oznaka', $html);
        $this->assertStringContainsString('Broj šasije (VIN)', $html);
        $this->assertStringContainsString('Prodajna cijena ugovorena je u iznosu', $html);
        $this->assertStringContainsString('Prodavatelj jamči da je vozilo njegovo vlasništvo', $html);
        $this->assertStringContainsString('Napomena:', $html);
        $this->assertStringContainsString('OIB:', $html);
        $this->assertStringContainsString('Dokument nije kriptografski digitalno potpisan.', $html);
        $this->assertStringContainsString($contract->finalized_snapshot_sha256, $html);
        $this->assertStringContainsString('Stranica {PAGE_NUM} / {PAGE_COUNT}', $html);
    }

    public function test_final_pdf_generation_does_not_change_finalized_snapshot(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $snapshotBeforeGeneration = $contract->filled_data_snapshot;
        $statusBeforeGeneration = $contract->status;
        $lockedAtBeforeGeneration = $contract->locked_at?->toIso8601String();

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $contract->refresh();

        $this->assertSame($snapshotBeforeGeneration, $contract->filled_data_snapshot);
        $this->assertSame($statusBeforeGeneration, $contract->status);
        $this->assertSame($lockedAtBeforeGeneration, $contract->locked_at?->toIso8601String());
    }

    public function test_final_pdf_generation_creates_safe_audit_event(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $snapshot = $contract->filled_data_snapshot;

        $this->actingAs($user)
            ->post(route('contracts.final-pdf.store', $contract))
            ->assertRedirect(route('contracts.index'));

        $event = AuditEvent::query()
            ->where('action', 'contract.final_pdf_generated')
            ->firstOrFail();
        $metadataJson = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        $this->assertSame($contract->fresh()->final_pdf_sha256, $event->metadata['final_pdf_sha256']);
        $this->assertSame(
            $contract->finalized_snapshot_sha256,
            $event->metadata['finalized_snapshot_sha256']
        );
        $this->assertArrayNotHasKey('storage_path', $event->metadata);
        $this->assertStringNotContainsString("contracts/{$contract->id}", $metadataJson);
        $this->assertSame($contract->fresh()->final_pdf_file_id, $event->metadata['file_id']);
        $this->assertSame(StoredFile::PURPOSE_FINAL_PDF, $event->metadata['purpose']);
        $this->assertSame(Contract::STATUS_FINALIZED, $event->metadata['status']);

        foreach ([
            $snapshot['seller_name'],
            $snapshot['seller_oib'],
            $snapshot['buyer_name'],
            $snapshot['buyer_oib'],
            $snapshot['vehicle_brand'],
            $snapshot['vin'],
            (string) $snapshot['price_amount'],
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $metadataJson);
        }
    }

    public function test_owner_can_view_final_pdf_inline_and_view_is_audited(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $content = '%PDF-1.4 final contract';
        $storedFile = $this->attachFinalPdf($contract, $user, $content);

        $response = $this->actingAs($user)
            ->get(route('contracts.final-pdf.show', $contract));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader(
                'content-disposition',
                'inline; filename="'.$storedFile->original_filename.'"'
            );

        $this->assertSame($content, $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'contract.final_pdf_viewed',
            'entity_id' => $contract->id,
        ]);
    }

    public function test_show_final_pdf_serves_existing_private_file_without_regeneration(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $content = '%PDF-1.4 existing final content';
        $storedFile = $this->attachFinalPdf($contract, $user, $content);
        $fileCountBefore = StoredFile::query()->count();
        $storedFileUpdatedAt = $storedFile->updated_at?->toIso8601String();
        $contractHashBefore = $contract->fresh()->final_pdf_sha256;

        $this->actingAs($user)
            ->get(route('contracts.final-pdf.show', $contract))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame($content, Storage::disk('local')->get($storedFile->storage_path));
        $this->assertSame($fileCountBefore, StoredFile::query()->count());
        $this->assertSame(
            $storedFileUpdatedAt,
            $storedFile->fresh()->updated_at?->toIso8601String()
        );
        $this->assertSame($contractHashBefore, $contract->fresh()->final_pdf_sha256);
    }

    public function test_other_user_cannot_view_final_pdf(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $contract = $this->createFinalizedContractFor($owner);
        $this->attachFinalPdf($contract, $owner);

        $this->actingAs($otherUser)
            ->get(route('contracts.final-pdf.show', $contract))
            ->assertForbidden();
    }

    public function test_final_pdf_verify_reports_matching_and_mismatching_hashes(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $content = '%PDF-1.4 verified final';
        $storedFile = $this->attachFinalPdf($contract, $user, $content);
        $expectedSha256 = hash('sha256', $content);

        $this->actingAs($user)
            ->getJson(route('contracts.final-pdf.verify', $contract))
            ->assertOk()
            ->assertExactJson([
                'valid' => true,
                'stored_sha256' => $expectedSha256,
                'actual_sha256' => $expectedSha256,
            ]);

        $changedContent = '%PDF-1.4 changed final';
        Storage::disk('local')->put($storedFile->storage_path, $changedContent);

        $this->actingAs($user)
            ->getJson(route('contracts.final-pdf.verify', $contract))
            ->assertOk()
            ->assertExactJson([
                'valid' => false,
                'stored_sha256' => $expectedSha256,
                'actual_sha256' => hash('sha256', $changedContent),
            ]);
    }

    public function test_final_pdf_verification_creates_audit_event(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $content = '%PDF-1.4 final audit';
        $this->attachFinalPdf($contract, $user, $content);
        $expectedSha256 = hash('sha256', $content);

        $this->actingAs($user)
            ->getJson(route('contracts.final-pdf.verify', $contract))
            ->assertOk();

        $event = AuditEvent::query()
            ->where('action', 'contract.final_pdf_verified')
            ->firstOrFail();

        $this->assertTrue($event->metadata['valid']);
        $this->assertSame($expectedSha256, $event->metadata['stored_sha256']);
        $this->assertSame($expectedSha256, $event->metadata['actual_sha256']);
        $this->assertNotEmpty($event->metadata['verified_at']);
    }

    public function test_finalized_contract_index_shows_final_pdf_actions_only_when_applicable(): void
    {
        $user = User::factory()->create();
        $draft = $this->createDraftFor($user);
        $finalizedWithoutPdf = $this->createFinalizedContractFor($user);
        $finalizedWithPdf = $this->createFinalizedContractFor($user);
        $this->attachFinalPdf($finalizedWithPdf, $user);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response
            ->assertOk()
            ->assertSee(route('contracts.final-pdf.store', $finalizedWithoutPdf), false)
            ->assertSee(route('contracts.final-pdf.store', $finalizedWithPdf), false)
            ->assertSee(route('contracts.final-pdf.show', $finalizedWithPdf), false)
            ->assertSee(route('contracts.final-pdf.verify', $finalizedWithPdf), false)
            ->assertDontSee(route('contracts.final-pdf.store', $draft), false)
            ->assertSee('Generiraj finalni PDF')
            ->assertSee('Prikaži finalni PDF')
            ->assertSee('Provjeri finalni PDF');
    }

    public function test_owner_can_enable_public_verification_for_finalized_locked_contract(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $snapshotBefore = $contract->filled_data_snapshot;
        $finalizedAtBefore = $contract->finalized_at?->toIso8601String();

        $this->actingAs($user)
            ->post(route('contracts.public-verification.enable', $contract))
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHas('success', 'Javna provjera dokumenta je omogućena.');

        $contract->refresh();

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9]{64}$/',
            $contract->public_verification_token
        );
        $this->assertNotNull($contract->public_verification_enabled_at);
        $this->assertNull($contract->public_verification_revoked_at);
        $this->assertNotNull($contract->final_pdf_file_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract->final_pdf_sha256);
        $this->assertSame(Contract::STATUS_FINALIZED, $contract->status);
        $this->assertTrue($contract->isLocked());
        $this->assertSame($snapshotBefore, $contract->filled_data_snapshot);
        $this->assertSame($finalizedAtBefore, $contract->finalized_at?->toIso8601String());
    }

    public function test_public_verification_cannot_be_enabled_by_other_user_or_for_non_finalized_contract(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $finalized = $this->createFinalizedContractFor($owner);
        $draft = $this->createDraftFor($owner);
        $archived = $this->createDraftFor($owner);
        $archived->update(['status' => Contract::STATUS_ARCHIVED]);

        $this->actingAs($otherUser)
            ->post(route('contracts.public-verification.enable', $finalized))
            ->assertForbidden();

        foreach ([$draft, $archived] as $contract) {
            $this->actingAs($owner)
                ->post(route('contracts.public-verification.enable', $contract))
                ->assertForbidden();
        }
    }

    public function test_public_verification_tokens_are_unique(): void
    {
        $user = User::factory()->create();
        $first = $this->createFinalizedContractFor($user);
        $second = $this->createFinalizedContractFor($user);

        $this->actingAs($user)
            ->post(route('contracts.public-verification.enable', $first))
            ->assertRedirect(route('contracts.index'));
        $this->actingAs($user)
            ->post(route('contracts.public-verification.enable', $second))
            ->assertRedirect(route('contracts.index'));

        $this->assertNotSame(
            $first->fresh()->public_verification_token,
            $second->fresh()->public_verification_token
        );
    }

    public function test_enabling_public_verification_regenerates_existing_final_pdf_and_audits_safely(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $snapshot = $contract->filled_data_snapshot;
        $this->attachFinalPdf($contract, $user, '%PDF-1.4 without qr');
        $oldHash = $contract->fresh()->final_pdf_sha256;

        $this->actingAs($user)
            ->post(route('contracts.public-verification.enable', $contract))
            ->assertRedirect(route('contracts.index'));

        $contract->refresh();
        $event = AuditEvent::query()
            ->where('action', 'contract.public_verification_enabled')
            ->firstOrFail();
        $metadataJson = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        $this->assertNotSame($oldHash, $contract->final_pdf_sha256);
        $this->assertSame(
            hash(
                'sha256',
                Storage::disk('local')->get("contracts/{$contract->id}/final-contract.pdf")
            ),
            $contract->final_pdf_sha256
        );
        $this->assertTrue($event->metadata['public_verification_token_created']);
        $this->assertTrue($event->metadata['final_pdf_regenerated']);
        $this->assertSame(
            'contracts.public-verification.enable',
            $event->metadata['route_name']
        );
        $this->assertNotEmpty($event->metadata['enabled_at']);

        foreach ([
            $snapshot['seller_name'],
            $snapshot['seller_oib'],
            $snapshot['buyer_name'],
            $snapshot['buyer_oib'],
            $snapshot['vehicle_brand'],
            $snapshot['vin'],
            (string) $snapshot['price_amount'],
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $metadataJson);
        }
    }

    public function test_public_verify_route_is_guest_accessible_and_shows_only_safe_hash_data(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $this->attachFinalPdf($contract, $user);
        $contract->update([
            'public_verification_token' => Str::random(64),
            'public_verification_enabled_at' => now(),
        ]);
        $snapshot = $contract->filled_data_snapshot;

        $response = $this->get(
            route('public.contracts.verify.show', $contract->public_verification_token)
        );

        $response
            ->assertOk()
            ->assertViewIs('public.contracts.verify.show')
            ->assertSee('Javna provjera dokumenta')
            ->assertSee('Dokument je evidentiran kao finaliziran')
            ->assertSee('Provjera PDF datoteke')
            ->assertSee('Provjeri datoteku')
            ->assertSee($contract->final_pdf_sha256)
            ->assertSee($contract->finalized_snapshot_sha256)
            ->assertSee('Ovo nije kriptografski digitalni potpis.')
            ->assertSee('Datoteka odgovara službenom hash zapisu.')
            ->assertSee('Datoteka ne odgovara službenom hash zapisu.')
            ->assertSee('Odaberite PDF datoteku.')
            ->assertSee('crypto.subtle.digest', false)
            ->assertSee('type="file"', false)
            ->assertSee('accept=".pdf,application/pdf"', false)
            ->assertSee('Izračunati SHA-256')
            ->assertSee('Službeni SHA-256')
            ->assertDontSee('<form', false)
            ->assertDontSee('fetch(', false)
            ->assertDontSee('FormData', false)
            ->assertDontSee('XMLHttpRequest', false)
            ->assertDontSee('cdn.jsdelivr.net', false)
            ->assertDontSee($snapshot['seller_name'])
            ->assertDontSee($snapshot['seller_oib'])
            ->assertDontSee($snapshot['buyer_name'])
            ->assertDontSee($snapshot['buyer_oib'])
            ->assertDontSee($snapshot['vehicle_brand'])
            ->assertDontSee($snapshot['vin'])
            ->assertDontSee("contracts/{$contract->id}/final-contract.pdf")
            ->assertDontSee(route('contracts.final-pdf.show', $contract), false);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'contract.public_verification_viewed',
            'entity_id' => $contract->id,
        ]);

        $viewedEvent = AuditEvent::query()
            ->where('action', 'contract.public_verification_viewed')
            ->firstOrFail();

        $this->assertSame('public.contracts.verify.show', $viewedEvent->metadata['route_name']);
        $this->assertTrue($viewedEvent->metadata['public']);
        $this->assertNotEmpty($viewedEvent->metadata['viewed_at']);
    }

    public function test_public_verify_route_returns_not_found_for_unknown_or_disabled_token(): void
    {
        $user = User::factory()->create();
        $disabled = $this->createFinalizedContractFor($user);
        $disabled->update(['public_verification_token' => Str::random(64)]);

        $this->get(route('public.contracts.verify.show', Str::random(64)))
            ->assertNotFound();
        $this->get(route('public.contracts.verify.show', $disabled->public_verification_token))
            ->assertNotFound();
    }

    public function test_public_verify_view_contains_client_side_pdf_hash_checker_without_upload(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $this->attachFinalPdf($contract, $user);
        $contract->update([
            'public_verification_token' => Str::random(64),
            'public_verification_enabled_at' => now(),
        ]);

        $response = $this->get(
            route('public.contracts.verify.show', $contract->public_verification_token)
        );

        $response
            ->assertOk()
            ->assertSee('Provjera PDF datoteke')
            ->assertSee('type="file"', false)
            ->assertSee('accept=".pdf,application/pdf"', false)
            ->assertSee($contract->final_pdf_sha256)
            ->assertSee('crypto.subtle.digest', false)
            ->assertSee('Odaberite PDF datoteku.')
            ->assertSee('Izračunati SHA-256')
            ->assertDontSee('<form', false)
            ->assertDontSee('fetch(', false)
            ->assertDontSee('FormData', false)
            ->assertDontSee('XMLHttpRequest', false);
    }

    public function test_final_pdf_template_shows_qr_section_without_embedding_final_pdf_hash(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);
        $contract->update([
            'public_verification_token' => Str::random(64),
            'public_verification_enabled_at' => now(),
            'final_pdf_sha256' => str_repeat('a', 64),
        ]);
        $verificationUrl = route(
            'public.contracts.verify.show',
            $contract->public_verification_token
        );
        $qrCodeDataUri = app(PublicVerificationQrCode::class)->dataUri($verificationUrl);

        $html = view('contracts.pdf.final', [
            'contract' => $contract,
            'snapshot' => $contract->filled_data_snapshot,
            'generatedAt' => now(),
            'verificationUrl' => $verificationUrl,
            'qrCodeDataUri' => $qrCodeDataUri,
        ])->render();

        $this->assertStringContainsString('Skenirajte QR code', $html);
        $this->assertStringContainsString($verificationUrl, $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString(
            'SHA-256 finalnog PDF-a dostupan je na javnoj stranici',
            $html
        );
        $this->assertStringNotContainsString(str_repeat('a', 64), $html);
        $this->assertStringContainsString($contract->finalized_snapshot_sha256, $html);
    }

    public function test_contract_index_shows_public_verification_actions_for_finalized_contract(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Omogući javnu provjeru')
            ->assertSee(route('contracts.public-verification.enable', $contract), false)
            ->assertDontSee('Otvori javnu provjeru');

        $contract->update([
            'public_verification_token' => Str::random(64),
            'public_verification_enabled_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Otvori javnu provjeru')
            ->assertSee(
                route('public.contracts.verify.show', $contract->public_verification_token),
                false
            );
    }

    public function test_saving_snapshot_creates_audit_event_with_sha256_hash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            route('contracts.snapshot.store'),
            $this->validPayload()
        );

        $response->assertOk();

        $event = AuditEvent::query()->firstOrFail();

        $this->assertSame('contract.snapshot_saved', $event->action);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($response->json('contract_id'), $event->entity_id);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $event->metadata['snapshot_sha256']
        );
    }

    public function test_audit_logger_redacts_certificate_subject_issuer_serial_metadata_including_nested(): void
    {
        $logger = app(AuditLogger::class);

        $sanitized = $logger->sanitizeMetadata([
            'subject_dn' => 'CN=Test Seller,O=Test',
            'issuer_dn' => 'CN=Local Test CA',
            'serial_number' => '1A2B3C',
            'certificate_subject' => 'CN=Test Seller,O=Test',
            'certificate_issuer' => 'CN=Local Test CA',
            'certificate_serial' => '1A2B3C',
            'thumbprint_sha256' => str_repeat('b', 64),
            'signature_file_id' => 7,
            'status' => Signature::STATUS_COMPLETED,
            'nested' => [
                'subject_dn' => 'CN=Nested Seller',
                'inner' => [
                    'certificate_issuer' => 'CN=Nested CA',
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['subject_dn']);
        $this->assertSame('[REDACTED]', $sanitized['issuer_dn']);
        $this->assertSame('[REDACTED]', $sanitized['serial_number']);
        $this->assertSame('[REDACTED]', $sanitized['certificate_subject']);
        $this->assertSame('[REDACTED]', $sanitized['certificate_issuer']);
        $this->assertSame('[REDACTED]', $sanitized['certificate_serial']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['subject_dn']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['inner']['certificate_issuer']);

        // Safe technical data must survive sanitization.
        $this->assertSame(str_repeat('b', 64), $sanitized['thumbprint_sha256']);
        $this->assertSame(7, $sanitized['signature_file_id']);
        $this->assertSame(Signature::STATUS_COMPLETED, $sanitized['status']);

        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Test Seller', $encoded);
        $this->assertStringNotContainsString('Local Test CA', $encoded);
        $this->assertStringNotContainsString('1A2B3C', $encoded);
    }

    public function test_signature_belongs_to_signature_file(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        $signatureFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => "contracts/{$contract->id}/final-contract.pdf.p7s",
            'original_filename' => "contract-{$contract->id}-final.pdf.p7s",
            'mime_type' => 'application/pkcs7-signature',
            'size_bytes' => 512,
            'sha256' => str_repeat('c', 64),
            'created_by_user_id' => $user->id,
        ]);

        $signature = Signature::query()->create([
            'contract_id' => $contract->id,
            'contract_party_id' => null,
            'certificate_id' => null,
            'signed_user_id' => $user->id,
            'type' => 'digital',
            'status' => Signature::STATUS_PENDING,
            'document_hash_before' => str_repeat('d', 64),
            'signature_file_id' => $signatureFile->id,
        ]);

        $this->assertTrue($signature->signatureFile->is($signatureFile));
        $this->assertSame(StoredFile::PURPOSE_CMS_SIGNATURE, $signature->signatureFile->purpose);
    }

    public function test_contract_cryptographic_signatures_relation_only_includes_signatures_with_artefact(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        Signature::query()->create([
            'contract_id' => $contract->id,
            'signed_user_id' => $user->id,
            'type' => 'digital',
            'status' => Signature::STATUS_PENDING,
            'document_hash_before' => str_repeat('e', 64),
            'signature_file_id' => null,
        ]);

        $signatureFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => "contracts/{$contract->id}/final-contract.pdf.p7s",
            'mime_type' => 'application/pkcs7-signature',
            'sha256' => str_repeat('f', 64),
            'created_by_user_id' => $user->id,
        ]);

        $withArtefact = Signature::query()->create([
            'contract_id' => $contract->id,
            'signed_user_id' => $user->id,
            'type' => 'digital',
            'status' => Signature::STATUS_PENDING,
            'document_hash_before' => str_repeat('e', 64),
            'signature_file_id' => $signatureFile->id,
        ]);

        $signatures = $contract->cryptographicSignatures;

        $this->assertCount(1, $signatures);
        $this->assertTrue($signatures->first()->is($withArtefact));
    }

    public function test_completed_signature_without_cms_purpose_artefact_is_not_recognized(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        // A completed signature whose artefact is a non-CMS file (e.g. final_pdf)
        // must NOT count as a cryptographic signature.
        $nonCmsFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_FINAL_PDF,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => "contracts/{$contract->id}/final-contract.pdf",
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('3', 64),
            'created_by_user_id' => $user->id,
        ]);

        Signature::query()->create([
            'contract_id' => $contract->id,
            'signed_user_id' => $user->id,
            'certificate_id' => null,
            'type' => 'digital',
            'status' => Signature::STATUS_COMPLETED,
            'signed_at' => now(),
            'document_hash_before' => str_repeat('4', 64),
            'document_hash_after' => str_repeat('4', 64),
            'signature_file_id' => $nonCmsFile->id,
        ]);

        $this->assertCount(0, $contract->fresh()->cryptographicSignatures);
        $this->assertFalse($contract->fresh()->hasCryptographicSignature());
    }

    public function test_completed_signature_with_cms_purpose_artefact_is_recognized(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        $cmsFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => "contracts/{$contract->id}/final-contract.pdf.p7s",
            'mime_type' => 'application/pkcs7-signature',
            'sha256' => str_repeat('5', 64),
            'created_by_user_id' => $user->id,
        ]);

        Signature::query()->create([
            'contract_id' => $contract->id,
            'signed_user_id' => $user->id,
            'certificate_id' => null,
            'type' => 'digital',
            'status' => Signature::STATUS_COMPLETED,
            'signed_at' => now(),
            'document_hash_before' => str_repeat('6', 64),
            'document_hash_after' => str_repeat('6', 64),
            'signature_file_id' => $cmsFile->id,
        ]);

        $this->assertCount(1, $contract->fresh()->cryptographicSignatures);
        $this->assertTrue($contract->fresh()->hasCryptographicSignature());
    }

    public function test_has_cryptographic_signature_distinguishes_pending_from_completed(): void
    {
        $user = User::factory()->create();
        $contract = $this->createFinalizedContractFor($user);

        $signatureFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_CMS_SIGNATURE,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => "contracts/{$contract->id}/final-contract.pdf.p7s",
            'mime_type' => 'application/pkcs7-signature',
            'sha256' => str_repeat('1', 64),
            'created_by_user_id' => $user->id,
        ]);

        $signature = Signature::query()->create([
            'contract_id' => $contract->id,
            'signed_user_id' => $user->id,
            'type' => 'digital',
            'status' => Signature::STATUS_PENDING,
            'document_hash_before' => str_repeat('2', 64),
            'signature_file_id' => $signatureFile->id,
        ]);

        $this->assertFalse($contract->fresh()->hasCryptographicSignature());

        $signature->update([
            'status' => Signature::STATUS_COMPLETED,
            'signed_at' => now(),
            'document_hash_after' => str_repeat('2', 64),
        ]);

        $this->assertTrue($contract->fresh()->hasCryptographicSignature());
    }

    private function createDraftFor(User $user): Contract
    {
        return Contract::query()->create([
            'contract_number' => 'DRAFT-TEST-'.strtoupper(fake()->unique()->bothify('########')),
            'status' => Contract::STATUS_DRAFT,
            'created_by_user_id' => $user->id,
            'salesperson_user_id' => $user->id,
            'place' => 'Zagreb',
            'contract_date' => '2026-06-18',
            'price_amount' => 4500,
            'currency' => 'EUR',
            'filled_data_snapshot' => $this->validPayload(),
        ]);
    }

    private function createFinalizedContractFor(User $user): Contract
    {
        $contract = $this->createDraftFor($user);
        $snapshotSha256 = hash(
            'sha256',
            json_encode(
                $contract->filled_data_snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
        $finalizedAt = now();

        $contract->update([
            'status' => Contract::STATUS_FINALIZED,
            'locked_at' => $finalizedAt,
            'finalized_at' => $finalizedAt,
            'finalized_snapshot_sha256' => $snapshotSha256,
        ]);

        return $contract->fresh();
    }

    private function attachDraftPdf(
        Contract $contract,
        User $user,
        string $content = '%PDF-1.4 draft test content',
        bool $storeContent = true
    ): StoredFile {
        $path = "contracts/{$contract->id}/draft-preview.pdf";
        $sha256 = hash('sha256', $content);

        if ($storeContent) {
            Storage::disk('local')->put($path, $content);
        }

        $storedFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_DRAFT_PDF,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => $path,
            'original_filename' => "contract-{$contract->id}-draft-preview.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'sha256' => $sha256,
            'created_by_user_id' => $user->id,
        ]);

        $contract->update([
            'draft_pdf_file_id' => $storedFile->id,
            'draft_pdf_sha256' => $sha256,
        ]);

        return $storedFile;
    }

    private function attachFinalPdf(
        Contract $contract,
        User $user,
        string $content = '%PDF-1.4 final test content',
        bool $storeContent = true
    ): StoredFile {
        $path = "contracts/{$contract->id}/final-contract.pdf";
        $sha256 = hash('sha256', $content);

        if ($storeContent) {
            Storage::disk('local')->put($path, $content);
        }

        $storedFile = StoredFile::query()->create([
            'purpose' => StoredFile::PURPOSE_FINAL_PDF,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => $path,
            'original_filename' => "contract-{$contract->id}-final.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'sha256' => $sha256,
            'created_by_user_id' => $user->id,
        ]);

        $contract->update([
            'final_pdf_file_id' => $storedFile->id,
            'final_pdf_sha256' => $sha256,
        ]);

        return $storedFile;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'place' => 'Zagreb',
            'contract_date' => '2026-06-18',
            'seller_name' => 'Ivan Horvat',
            'seller_oib' => '12345678901',
            'buyer_name' => 'Ana Kovač',
            'buyer_oib' => '10987654321',
            'vehicle_brand' => 'Volkswagen',
            'vin' => 'WVWZZZ6RZCY000000',
            'price_amount' => 4500,
            'remaining_amount' => 0,
            'remaining_words' => 'nula',
        ], $overrides);
    }
}
