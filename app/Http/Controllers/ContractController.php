<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\StoredFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function index(Request $request): View
    {
        $contracts = Contract::query()
            ->with('draftPdfFile')
            ->where('created_by_user_id', $request->user()->id)
            ->where('status', Contract::STATUS_DRAFT)
            ->orderByDesc('updated_at')
            ->get();

        return view('contracts.index', [
            'contracts' => $contracts,
        ]);
    }

    public function create(): View
    {
        return view('contracts.builder', [
            'contractId' => null,
            'snapshot' => [],
            'contractStatus' => null,
            'contractUpdatedAt' => null,
        ]);
    }

    public function editBuilder(Request $request, Contract $contract): View
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->canBeEdited(), 403);

        return view('contracts.builder', [
            'contractId' => $contract->id,
            'snapshot' => $contract->filled_data_snapshot ?? [],
            'contractStatus' => $contract->status,
            'contractUpdatedAt' => $contract->updated_at?->toIso8601String(),
        ]);
    }

    public function archive(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->canBeEdited(), 403);

        DB::transaction(function () use ($request, $contract): void {
            $previousStatus = $contract->status;

            $contract->status = Contract::STATUS_ARCHIVED;
            $contract->save();

            AuditEvent::query()->create([
                'occurred_at' => now(),
                'actor_user_id' => $request->user()->id,
                'action' => 'contract.draft_archived',
                'entity_type' => 'Contract',
                'entity_id' => $contract->id,
                'success' => true,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'new_status' => Contract::STATUS_ARCHIVED,
                ],
            ]);
        });

        return redirect()
            ->route('contracts.index')
            ->with('success', 'Draft ugovora je arhiviran.');
    }

    public function generateDraftPdf(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->canBeEdited(), 403);
        abort_if(empty($contract->filled_data_snapshot), 422, 'Draft nema spremljeni snapshot.');

        $generatedAt = now();
        $pdfContent = Pdf::loadView('contracts.pdf.draft', [
            'contract' => $contract,
            'snapshot' => $contract->filled_data_snapshot,
            'generatedAt' => $generatedAt,
        ])->setPaper('a4')->output();

        $path = "contracts/{$contract->id}/draft-preview.pdf";
        $sha256 = hash('sha256', $pdfContent);

        abort_unless(
            Storage::disk(StoredFile::DISK_LOCAL)->put($path, $pdfContent),
            500,
            'Probni PDF nije moguće spremiti.'
        );

        DB::transaction(function () use (
            $request,
            $contract,
            $generatedAt,
            $path,
            $pdfContent,
            $sha256
        ): void {
            $storedFile = $contract->draftPdfFile ?? new StoredFile;
            $storedFile->purpose = StoredFile::PURPOSE_DRAFT_PDF;
            $storedFile->storage_disk = StoredFile::DISK_LOCAL;
            $storedFile->storage_path = $path;
            $storedFile->original_filename = "contract-{$contract->id}-draft-preview.pdf";
            $storedFile->mime_type = 'application/pdf';
            $storedFile->size_bytes = strlen($pdfContent);
            $storedFile->sha256 = $sha256;
            $storedFile->created_by_user_id = $request->user()->id;
            $storedFile->save();

            $contract->draft_pdf_file_id = $storedFile->id;
            $contract->draft_pdf_sha256 = $sha256;
            $contract->save();

            AuditEvent::query()->create([
                'occurred_at' => $generatedAt,
                'actor_user_id' => $request->user()->id,
                'action' => 'contract.draft_pdf_generated',
                'entity_type' => 'Contract',
                'entity_id' => $contract->id,
                'success' => true,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'draft_pdf_path' => $path,
                    'draft_pdf_sha256' => $sha256,
                    'generated_at' => $generatedAt->toIso8601String(),
                ],
            ]);
        });

        return redirect()
            ->route('contracts.index')
            ->with('success', 'Probni PDF je generiran.');
    }

    public function showDraftPdf(Request $request, Contract $contract): Response
    {
        $storedFile = $this->resolveAccessibleDraftPdf($request, $contract);
        $content = Storage::disk(StoredFile::DISK_LOCAL)->get($storedFile->storage_path);
        $filename = basename(
            $storedFile->original_filename ?: "contract-{$contract->id}-draft-preview.pdf"
        );
        $filename = str_replace(["\r", "\n", '"'], '', $filename);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verifyDraftPdf(Request $request, Contract $contract): View
    {
        $storedFile = $this->resolveAccessibleDraftPdf($request, $contract);
        abort_if(blank($contract->draft_pdf_sha256), 404);

        $expectedSha256 = strtolower($contract->draft_pdf_sha256);
        $actualSha256 = hash(
            'sha256',
            Storage::disk(StoredFile::DISK_LOCAL)->get($storedFile->storage_path)
        );
        $valid = hash_equals($expectedSha256, $actualSha256);

        AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_user_id' => $request->user()->id,
            'action' => 'contract.draft_pdf_verified',
            'entity_type' => 'Contract',
            'entity_id' => $contract->id,
            'success' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'expected_sha256' => $expectedSha256,
                'actual_sha256' => $actualSha256,
                'valid' => $valid,
            ],
        ]);

        return view('contracts.draft-pdf-verify', [
            'contract' => $contract,
            'storedFile' => $storedFile,
            'expectedSha256' => $expectedSha256,
            'actualSha256' => $actualSha256,
            'valid' => $valid,
        ]);
    }

    public function storeSnapshot(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
            'place' => ['required', 'string', 'max:120'],
            'contract_date' => ['required', 'date'],
            'court_place' => ['nullable', 'string', 'max:120'],
            'seller_name' => ['nullable', 'string', 'max:250'],
            'seller_address' => ['nullable', 'string', 'max:300'],
            'seller_oib' => ['nullable', 'regex:/^[0-9]{11}$/'],
            'buyer_name' => ['nullable', 'string', 'max:250'],
            'buyer_address' => ['nullable', 'string', 'max:300'],
            'buyer_oib' => ['nullable', 'regex:/^[0-9]{11}$/'],
            'registration_number' => ['nullable', 'string', 'max:20'],
            'vehicle_type' => ['nullable', 'string', 'max:120'],
            'vehicle_brand' => ['nullable', 'string', 'max:80'],
            'vehicle_model' => ['nullable', 'string', 'max:120'],
            'vehicle_tip' => ['nullable', 'string', 'max:120'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'vin' => ['nullable', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'body_shape' => ['nullable', 'string', 'max:120'],
            'manufacturer_country' => ['nullable', 'string', 'max:200'],
            'production_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle_purpose' => ['nullable', 'string', 'max:200'],
            'first_registration_date' => ['nullable', 'date'],
            'engine_type' => ['nullable', 'string', 'max:80'],
            'engine_power_kw' => ['nullable', 'integer', 'min:0'],
            'engine_displacement_cc' => ['nullable', 'integer', 'min:0'],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'price_words' => ['nullable', 'string', 'max:250'],
            'paid_date' => ['nullable', 'date'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_words' => ['nullable', 'string', 'max:250'],
            'remaining_amount' => ['nullable', 'numeric', 'min:0'],
            'remaining_words' => ['nullable', 'string', 'max:250'],
            'remaining_due_date' => ['nullable', 'date'],
            'included_items' => ['nullable', 'string', 'max:500'],
            'costs_paid_by' => ['nullable', 'in:kupac,prodavatelj,kupac i prodavatelj'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Podaci ugovora nisu ispravni.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $contract = null;

        if (isset($validated['contract_id'])) {
            $contract = Contract::query()->findOrFail($validated['contract_id']);

            abort_unless($contract->created_by_user_id === $request->user()->id, 403);

            if (! $contract->canBeEdited()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Samo otključani draft ugovor može se uređivati.',
                    'errors' => [
                        'contract_id' => ['Samo otključani draft ugovor može se uređivati.'],
                    ],
                ], 422);
            }
        }

        unset($validated['contract_id']);

        $snapshot = $validated;
        $snapshotHash = hash(
            'sha256',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $contract = DB::transaction(function () use ($request, $contract, $snapshot, $snapshotHash): Contract {
            $contract ??= new Contract([
                'contract_number' => 'DRAFT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'status' => Contract::STATUS_DRAFT,
                'created_by_user_id' => $request->user()->id,
                'salesperson_user_id' => $request->user()->id,
                'currency' => 'EUR',
            ]);

            $contract->place = $snapshot['place'];
            $contract->contract_date = $snapshot['contract_date'];
            $contract->price_amount = $snapshot['price_amount'];
            $contract->filled_data_snapshot = $snapshot;
            $contract->save();

            AuditEvent::query()->create([
                'occurred_at' => now(),
                'actor_user_id' => $request->user()->id,
                'action' => 'contract.snapshot_saved',
                'entity_type' => 'Contract',
                'entity_id' => $contract->id,
                'success' => true,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'status' => $contract->status,
                    'snapshot_sha256' => $snapshotHash,
                ],
            ]);

            return $contract->refresh();
        });

        return response()->json([
            'success' => true,
            'contract_id' => $contract->id,
            'status' => $contract->status,
            'updated_at' => $contract->updated_at?->toIso8601String(),
            'message' => 'Snapshot ugovora je spremljen.',
        ]);
    }

    public function vehicleSalesPreview(): View
    {
        return view('contracts.preview.vehicle-sales-contract');
    }

    private function resolveAccessibleDraftPdf(Request $request, Contract $contract): StoredFile
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->canBeEdited(), 403);

        $storedFile = $contract->draftPdfFile;

        abort_unless(
            $storedFile
                && $storedFile->purpose === StoredFile::PURPOSE_DRAFT_PDF
                && $storedFile->storage_disk === StoredFile::DISK_LOCAL
                && filled($storedFile->storage_path),
            404
        );
        abort_unless(
            Storage::disk(StoredFile::DISK_LOCAL)->exists($storedFile->storage_path),
            404
        );

        return $storedFile;
    }
}
