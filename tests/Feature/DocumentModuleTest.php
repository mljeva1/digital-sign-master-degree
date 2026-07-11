<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M8.1 — generic Documents module (files.purpose = user_upload).
 *
 * Follows the project convention of hand-building a simplified schema on the
 * in-memory SQLite connection. This proves application behaviour (owner +
 * purpose access boundary, upload path, hash verification, orphan cleanup); it
 * does NOT prove the PostgreSQL files_purpose_check constraint, which is
 * verified separately via a direct physical-schema read.
 */
class DocumentModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('files');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /**
     * Create a stored file row + its physical private bytes for a given owner
     * and purpose, so access-boundary tests can target real records.
     */
    private function makeStoredFile(
        User $owner,
        string $purpose,
        string $originalFilename = 'dokument.pdf',
        string $content = '%PDF-1.4 stored content',
    ): StoredFile {
        $storagePath = 'documents/2026/07/'.Str::uuid()->toString().'.pdf';

        Storage::disk(StoredFile::DISK_LOCAL)->put($storagePath, $content);

        return StoredFile::create([
            'purpose' => $purpose,
            'storage_disk' => StoredFile::DISK_LOCAL,
            'storage_path' => $storagePath,
            'original_filename' => $originalFilename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'created_by_user_id' => $owner->id,
        ]);
    }

    // 1-3: upload creates the correct row + physical private file.
    public function test_upload_creates_user_upload_row_and_private_file(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('ugovor.pdf', 120, 'application/pdf');

        $this->actingAs($user)
            ->post(route('documents.store'), ['document' => $file])
            ->assertRedirect(route('documents.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, StoredFile::query()->count());

        $document = StoredFile::query()->firstOrFail();

        $this->assertSame(StoredFile::PURPOSE_USER_UPLOAD, $document->purpose);
        $this->assertSame($user->id, $document->created_by_user_id);
        $this->assertSame('ugovor.pdf', $document->original_filename);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(StoredFile::DISK_LOCAL, $document->storage_disk);

        Storage::disk(StoredFile::DISK_LOCAL)->assertExists($document->storage_path);

        $content = Storage::disk(StoredFile::DISK_LOCAL)->get($document->storage_path);
        $this->assertSame(hash('sha256', $content), $document->sha256);
        // The fake upload reports 120 KB; the controller records the upload's
        // own reported size (real uploads report their actual byte length).
        $this->assertSame(120 * 1024, $document->size_bytes);
    }

    // Stored mime_type must reflect server-side content detection, never the
    // client-declared Content-Type header.
    public function test_stored_mime_type_reflects_server_content_detection_not_client_header(): void
    {
        $user = User::factory()->create();

        // Minimal but real PDF byte structure: satisfies the existing
        // `mimes:pdf,doc,docx` rule (which itself guesses from content) and is
        // detectable by finfo as application/pdf.
        $pdfContent = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

        $tmpPath = tempnam(sys_get_temp_dir(), 'doc_mime_test_');
        file_put_contents($tmpPath, $pdfContent);

        // A real Illuminate\Http\UploadedFile (not Testing\File, whose
        // getMimeType() is overridden to just echo the declared type) so
        // getMimeType() performs genuine finfo-based content detection, while
        // the client-declared header is deliberately wrong.
        $uploadedFile = new UploadedFile($tmpPath, 'ugovor.pdf', 'image/png', null, true);

        $response = $this->actingAs($user)->post(route('documents.store'), [
            'document' => $uploadedFile,
        ]);

        $response
            ->assertRedirect(route('documents.index'))
            ->assertSessionHas('success');

        $document = StoredFile::query()->firstOrFail();

        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertNotSame('image/png', $document->mime_type);
    }

    // 4: invalid type does not create a DB row.
    public function test_invalid_file_type_is_rejected_and_stores_nothing(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('note.txt', 10, 'text/plain');

        $this->actingAs($user)
            ->post(route('documents.store'), ['document' => $file])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, StoredFile::query()->count());
        $this->assertEmpty(Storage::disk(StoredFile::DISK_LOCAL)->allFiles());
    }

    // 4: oversized file does not create a DB row.
    public function test_oversized_file_is_rejected_and_stores_nothing(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('veliki.pdf', 11000, 'application/pdf');

        $this->actingAs($user)
            ->post(route('documents.store'), ['document' => $file])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, StoredFile::query()->count());
        $this->assertEmpty(Storage::disk(StoredFile::DISK_LOCAL)->allFiles());
    }

    // 4: missing upload does not create a DB row.
    public function test_missing_upload_is_rejected_and_stores_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('documents.store'), [])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, StoredFile::query()->count());
    }

    // 5-7: index shows only own user_upload rows.
    public function test_index_shows_only_own_user_upload_documents(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownUpload = $this->makeStoredFile($user, StoredFile::PURPOSE_USER_UPLOAD, 'moj-upload.pdf');
        $otherUpload = $this->makeStoredFile($otherUser, StoredFile::PURPOSE_USER_UPLOAD, 'tudji-upload.pdf');
        $ownDraft = $this->makeStoredFile($user, StoredFile::PURPOSE_DRAFT_PDF, 'moj-draft.pdf');
        $ownFinal = $this->makeStoredFile($user, StoredFile::PURPOSE_FINAL_PDF, 'moj-final.pdf');

        $response = $this->actingAs($user)->get(route('documents.index'));

        $response
            ->assertOk()
            ->assertSee($ownUpload->original_filename)
            ->assertDontSee($otherUpload->original_filename)
            ->assertDontSee($ownDraft->original_filename)
            ->assertDontSee($ownFinal->original_filename);
    }

    // 8: show/download/verify for another user's user_upload return 404.
    public function test_other_users_user_upload_is_not_accessible(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreign = $this->makeStoredFile($otherUser, StoredFile::PURPOSE_USER_UPLOAD);

        $this->actingAs($user)->get(route('documents.show', $foreign->id))->assertNotFound();
        $this->actingAs($user)->get(route('documents.download', $foreign->id))->assertNotFound();
        $this->actingAs($user)->post(route('documents.verify', $foreign->id))->assertNotFound();
    }

    // 9: own rows of a different purpose are not reachable through Documents.
    public function test_own_non_user_upload_purposes_are_not_accessible(): void
    {
        $user = User::factory()->create();

        foreach ([
            StoredFile::PURPOSE_DRAFT_PDF,
            StoredFile::PURPOSE_FINAL_PDF,
            StoredFile::PURPOSE_IDENTITY_CAPTURE,
            StoredFile::PURPOSE_CERTIFICATE,
            StoredFile::PURPOSE_SIGNED_PDF,
            StoredFile::PURPOSE_CMS_SIGNATURE,
            StoredFile::PURPOSE_TEMPLATE,
        ] as $purpose) {
            $document = $this->makeStoredFile($user, $purpose);

            $this->actingAs($user)->get(route('documents.show', $document->id))->assertNotFound();
            $this->actingAs($user)->get(route('documents.download', $document->id))->assertNotFound();
            $this->actingAs($user)->post(route('documents.verify', $document->id))->assertNotFound();
        }
    }

    // 10: own user_upload show + download work.
    public function test_owner_can_show_and_download_own_user_upload(): void
    {
        $user = User::factory()->create();
        $content = '%PDF-1.4 vlastiti sadrzaj';
        $document = $this->makeStoredFile($user, StoredFile::PURPOSE_USER_UPLOAD, 'racun.pdf', $content);

        $this->actingAs($user)
            ->get(route('documents.show', $document->id))
            ->assertOk()
            ->assertViewIs('documents.show')
            ->assertSee('racun.pdf');

        $download = $this->actingAs($user)->get(route('documents.download', $document->id));

        $download
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame($content, $download->streamedContent());
    }

    // 11: verify reports success for an untouched file and mismatch after change.
    public function test_verify_reports_success_then_mismatch_after_modification(): void
    {
        $user = User::factory()->create();
        $document = $this->makeStoredFile($user, StoredFile::PURPOSE_USER_UPLOAD, 'ugovor.pdf', '%PDF-1.4 original');

        $this->actingAs($user)
            ->post(route('documents.verify', $document->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk(StoredFile::DISK_LOCAL)->put($document->storage_path, '%PDF-1.4 promijenjeno');

        $this->actingAs($user)
            ->post(route('documents.verify', $document->id))
            ->assertRedirect()
            ->assertSessionHasErrors('document');
    }

    // 12: non-existent record resolves to a controlled 404.
    public function test_non_existent_document_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('documents.show', 999999))->assertNotFound();
        $this->actingAs($user)->get(route('documents.download', 999999))->assertNotFound();
        $this->actingAs($user)->post(route('documents.verify', 999999))->assertNotFound();
    }

    // 12: a missing physical file ends in a controlled 404, not an uncontrolled 500.
    public function test_missing_physical_file_returns_controlled_not_found(): void
    {
        $user = User::factory()->create();
        $document = $this->makeStoredFile($user, StoredFile::PURPOSE_USER_UPLOAD);

        Storage::disk(StoredFile::DISK_LOCAL)->delete($document->storage_path);

        // Metadata still resolves, so show renders.
        $this->actingAs($user)->get(route('documents.show', $document->id))->assertOk();

        // Bytes are gone, so download/verify degrade to 404 rather than 500.
        $this->actingAs($user)->get(route('documents.download', $document->id))->assertNotFound();
        $this->actingAs($user)->post(route('documents.verify', $document->id))->assertNotFound();
    }

    // 13: HTML never discloses storage_path or storage_disk.
    public function test_show_html_does_not_disclose_storage_path_or_disk(): void
    {
        $user = User::factory()->create();
        $document = $this->makeStoredFile($user, StoredFile::PURPOSE_USER_UPLOAD);

        // Assert the sensitive storage_path is absent, plus the labels of the
        // removed rows. (A raw assertDontSee('local') would collide with the
        // 'localhost' host in route URLs, so it is not a meaningful check.)
        $this->actingAs($user)
            ->get(route('documents.show', $document->id))
            ->assertOk()
            ->assertDontSee($document->storage_path)
            ->assertDontSee('documents/2026/07')
            ->assertDontSee('Private path')
            ->assertDontSee('Spremljeni naziv')
            ->assertDontSee('>Disk<', false);
    }

    // 14: a DB failure after the bytes are written must not leave an orphan file.
    public function test_db_failure_after_storage_removes_orphan_file(): void
    {
        $user = User::factory()->create();

        // Force the metadata insert to fail deterministically (no fragile mock):
        // the bytes are written first, then StoredFile::create() hits a missing
        // table and throws a concrete QueryException, which must propagate out
        // of the controller (not be swallowed) after triggering orphan cleanup.
        Schema::drop('files');

        $file = UploadedFile::fake()->create('ugovor.pdf', 120, 'application/pdf');

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->actingAs($user)->post(route('documents.store'), ['document' => $file]);
        } finally {
            $this->assertEmpty(Storage::disk(StoredFile::DISK_LOCAL)->allFiles());
        }
    }

    public function test_guest_cannot_reach_documents_routes(): void
    {
        $this->get(route('documents.index'))->assertRedirect(route('login'));
        $this->get(route('documents.create'))->assertRedirect(route('login'));
        $this->post(route('documents.store'), [])->assertRedirect(route('login'));
    }
}
