<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
