<?php

namespace App\Http\Controllers;

use App\Exceptions\Signing\FinalPdfException;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\StoredFile;
use App\Models\UserContractProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\ContractRequiredFieldsValidator;
use App\Services\Contracts\FinalPdfGenerator;
use App\Services\Signing\FinalPdfIntegrityVerifier;
use App\Services\Signing\FinalPdfVerificationBindingVerifier;
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
    /**
     * Labels for the profile fields required before a builder party autofill
     * is offered. Order matches `UserContractProfile::missingContractAutofillFields()`.
     *
     * @var array<string, string>
     */
    private const PARTY_PROFILE_REQUIRED_FIELD_LABELS = [
        'first_name' => 'Ime',
        'last_name' => 'Prezime',
        'oib' => 'OIB',
        'address_line1' => 'Adresa (ulica i kućni broj)',
        'postal_code' => 'Poštanski broj',
        'city' => 'Grad',
    ];

    /**
     * Matches the `seller_name`/`buyer_name` max length in storeSnapshot().
     */
    private const PARTY_PROFILE_NAME_MAX_LENGTH = 250;

    /**
     * Matches the `seller_address`/`buyer_address` max length in storeSnapshot().
     */
    private const PARTY_PROFILE_ADDRESS_MAX_LENGTH = 300;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly FinalPdfGenerator $finalPdfGenerator,
        private readonly FinalPdfIntegrityVerifier $finalPdfVerifier,
        private readonly FinalPdfVerificationBindingVerifier $bindingVerifier
    ) {}

    public function index(Request $request): View
    {
        $contracts = Contract::query()
            ->with(['draftPdfFile', 'finalPdfFile'])
            ->where('created_by_user_id', $request->user()->id)
            ->whereIn('status', [
                Contract::STATUS_DRAFT,
                Contract::STATUS_FINALIZED,
            ])
            ->orderByDesc('updated_at')
            ->get();

        return view('contracts.index', [
            'contracts' => $contracts,
        ]);
    }

    public function audit(Request $request, Contract $contract): View
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);

        $this->auditLogger->record('contract.audit_viewed', $contract, [
            'route_name' => 'contracts.audit.index',
            'viewed_at' => now()->toIso8601String(),
        ]);

        $events = AuditEvent::query()
            ->with('actorUser')
            ->where('entity_type', class_basename(Contract::class))
            ->where('entity_id', $contract->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $events->each(function (AuditEvent $event): void {
            $event->setAttribute(
                'metadata',
                $this->auditLogger->sanitizeMetadata($event->metadata ?? [])
            );
        });

        return view('contracts.audit.index', [
            'contract' => $contract,
            'events' => $events,
        ]);
    }

    public function create(Request $request): View
    {
        return view('contracts.builder', [
            'contractId' => null,
            'snapshot' => [],
            'contractStatus' => null,
            'contractUpdatedAt' => null,
            'partyProfileAutofill' => $this->partyProfileAutofillPayload($request->user()->contractProfile),
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
            'partyProfileAutofill' => $this->partyProfileAutofillPayload($request->user()->contractProfile),
        ]);
    }

    public function archive(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->canBeEdited(), 403);

        DB::transaction(function () use ($contract): void {
            $previousStatus = $contract->status;

            $contract->status = Contract::STATUS_ARCHIVED;
            $contract->save();

            $this->auditLogger->record('contract.draft_archived', $contract, [
                'previous_status' => $previousStatus,
                'new_status' => Contract::STATUS_ARCHIVED,
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

            $this->auditLogger->record('contract.draft_pdf_generated', $contract, [
                'file_id' => $storedFile->id,
                'purpose' => $storedFile->purpose,
                'draft_pdf_sha256' => $sha256,
                'generated_at' => $generatedAt->toIso8601String(),
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

        $this->auditLogger->record('contract.draft_pdf_verified', $contract, [
            'expected_sha256' => $expectedSha256,
            'actual_sha256' => $actualSha256,
            'valid' => $valid,
        ]);

        return view('contracts.draft-pdf-verify', [
            'contract' => $contract,
            'storedFile' => $storedFile,
            'expectedSha256' => $expectedSha256,
            'actualSha256' => $actualSha256,
            'valid' => $valid,
        ]);
    }

    public function generateFinalPdf(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);

        try {
            $this->finalPdfGenerator->generate($contract, $request->user()->id);
        } catch (FinalPdfException $e) {
            return redirect()
                ->route('contracts.index')
                ->with('error', $e->errorCode() === FinalPdfException::FINAL_PDF_ACTIVELY_SIGNED
                    // The final PDF is already signed and immutable — never regenerate it.
                    ? 'Finalni PDF je potpisan i više se ne može ponovno generirati.'
                    : 'Finalni PDF nije moguće generirati.');
        }

        return redirect()
            ->route('contracts.index')
            ->with('success', 'Finalni PDF je generiran.');
    }

    public function enablePublicVerification(
        Request $request,
        Contract $contract
    ): RedirectResponse {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->isFinalized(), 403);
        abort_unless($contract->isLocked(), 403);

        try {
            $fresh = Contract::query()->whereKey($contract->id)->firstOrFail();

            if ($this->finalPdfVerifier->hasActiveSignature($fresh)) {
                // No PDF generation is allowed for signed bytes. This branch owns
                // its own short top-level transaction and only re-enables a token
                // already proven to be embedded in the signed PDF.
                DB::transaction(function () use ($request, $contract): void {
                    $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
                    if (! $this->finalPdfVerifier->hasActiveSignature($locked)) {
                        throw FinalPdfException::of(FinalPdfException::FINAL_PDF_PERSISTENCE_FAILED);
                    }

                    $this->enableVerificationOnSignedContract($locked, $request->user()->id);
                }, 1);
            } else {
                // FinalPdfGenerator is the ONE owner of the real top-level
                // transaction. Activation, PDF/binding/proof and enable audit are
                // therefore committed or rolled back as one unit with one
                // filesystem compensation boundary.
                $this->finalPdfGenerator->generateForPublicVerification(
                    $fresh,
                    $request->user()->id,
                );
            }
        } catch (\Throwable) {
            // Rolled back: token/enabled state and the existing PDF are unchanged.
            return redirect()
                ->route('contracts.index')
                ->with('error', 'Javnu provjeru nije moguće omogućiti jer finalni PDF nije moguće pripremiti.');
        }

        return redirect()
            ->route('contracts.index')
            ->with('success', 'Javna provjera dokumenta je omogućena.');
    }

    /**
     * Enable public verification on a contract whose final PDF is already signed.
     *
     * The signed bytes are immutable, so a token that was NOT embedded before
     * signing can never be honoured — that fails closed and rolls the transaction
     * back. A token that WAS already embedded (the PDF was generated at/after its
     * activation) may be re-enabled idempotently: no PDF, StoredFile, binding,
     * Signature or CMS is touched.
     */
    private function enableVerificationOnSignedContract(Contract $locked, int $actorUserId): void
    {
        $enabledAt = $locked->public_verification_enabled_at;
        $token = (string) ($locked->public_verification_token ?? '');
        $finalPdf = $locked->finalPdfFile;

        // EXACT proof (not a timestamp): the signed PDF must carry a generation
        // record binding this same token + canonical URL + file id + PDF hash.
        $alreadyEmbedded = $token !== ''
            && $enabledAt !== null
            && $finalPdf !== null
            && $this->bindingVerifier->hasGenerationProof(
                $locked,
                (int) $finalPdf->id,
                (string) $finalPdf->sha256,
                $token,
            );

        if (! $alreadyEmbedded) {
            throw FinalPdfException::of(FinalPdfException::FINAL_PDF_ACTIVELY_SIGNED);
        }

        // Idempotent re-enable: only the revocation flag may change.
        $locked->public_verification_revoked_at = null;
        $locked->save();

        $this->auditLogger->record('contract.public_verification_enabled', $locked, [
            'public_verification_token_created' => false,
            'enabled_at' => $enabledAt->toIso8601String(),
            'route_name' => 'contracts.public-verification.enable',
            'final_pdf_regenerated' => false,
            'final_pdf_file_id' => (int) $finalPdf->id,
            'final_pdf_sha256' => (string) $finalPdf->sha256,
            'public_verification_token_sha256' => $this->bindingVerifier->tokenHash($token),
        ], null, $actorUserId);
    }

    public function showFinalPdf(Request $request, Contract $contract): Response
    {
        $storedFile = $this->resolveAccessibleFinalPdf($request, $contract);
        $content = Storage::disk(StoredFile::DISK_LOCAL)->get($storedFile->storage_path);
        $filename = basename(
            $storedFile->original_filename ?: "contract-{$contract->id}-final.pdf"
        );
        $filename = str_replace(["\r", "\n", '"'], '', $filename);

        $this->auditLogger->record('contract.final_pdf_viewed', $contract, [
            'file_id' => $storedFile->id,
            'purpose' => $storedFile->purpose,
            'viewed_at' => now()->toIso8601String(),
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verifyFinalPdf(Request $request, Contract $contract): JsonResponse
    {
        $storedFile = $this->resolveAccessibleFinalPdf($request, $contract);
        abort_if(blank($contract->final_pdf_sha256), 404);

        $storedSha256 = strtolower($contract->final_pdf_sha256);
        $actualSha256 = hash(
            'sha256',
            Storage::disk(StoredFile::DISK_LOCAL)->get($storedFile->storage_path)
        );
        $valid = hash_equals($storedSha256, $actualSha256);

        $this->auditLogger->record('contract.final_pdf_verified', $contract, [
            'valid' => $valid,
            'stored_sha256' => $storedSha256,
            'actual_sha256' => $actualSha256,
            'verified_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'valid' => $valid,
            'stored_sha256' => $storedSha256,
            'actual_sha256' => $actualSha256,
        ]);
    }

    public function validateRequiredFields(
        Request $request,
        Contract $contract,
        ContractRequiredFieldsValidator $requiredFieldsValidator
    ): JsonResponse {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->canBeEdited(), 403);

        $result = $requiredFieldsValidator->validate($contract->filled_data_snapshot ?? []);

        $this->auditLogger->record('contract.required_fields_validated', $contract, [
            'valid' => $result['valid'],
            'missing_fields' => array_column($result['missing_fields'], 'key'),
            'invalid_fields' => array_column($result['invalid_fields'], 'key'),
        ]);

        return response()->json($result);
    }

    public function finalize(
        Request $request,
        Contract $contract,
        ContractRequiredFieldsValidator $requiredFieldsValidator
    ): JsonResponse {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);

        $result = DB::transaction(function () use (
            $request,
            $contract,
            $requiredFieldsValidator
        ): array {
            $lockedContract = Contract::query()
                ->lockForUpdate()
                ->findOrFail($contract->id);

            abort_unless($lockedContract->created_by_user_id === $request->user()->id, 403);
            abort_unless($lockedContract->canBeEdited(), 403);

            $snapshot = $lockedContract->filled_data_snapshot ?? [];
            $validation = $requiredFieldsValidator->validate($snapshot);

            if (! $validation['valid']) {
                $this->auditLogger->record('contract.finalization_failed', $lockedContract, [
                    'valid' => false,
                    'missing_fields' => array_column($validation['missing_fields'], 'key'),
                    'invalid_fields' => array_column($validation['invalid_fields'], 'key'),
                ]);

                return [
                    'message' => 'Ugovor nije spreman za finalizaciju.',
                    ...$validation,
                ];
            }

            $finalizedAt = now();
            $snapshotSha256 = hash(
                'sha256',
                json_encode(
                    $snapshot,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            $statusBefore = $lockedContract->status;

            $lockedContract->status = Contract::STATUS_FINALIZED;
            $lockedContract->locked_at = $finalizedAt;
            $lockedContract->finalized_at = $finalizedAt;
            $lockedContract->finalized_snapshot_sha256 = $snapshotSha256;
            $lockedContract->save();

            $this->auditLogger->record('contract.finalized', $lockedContract, [
                'snapshot_sha256' => $snapshotSha256,
                'status_before' => $statusBefore,
                'status_after' => Contract::STATUS_FINALIZED,
                'finalized_at' => $finalizedAt->toIso8601String(),
                'locked_at' => $finalizedAt->toIso8601String(),
            ]);

            return [
                'message' => 'Ugovor je finaliziran i zaključan.',
                'contract_id' => $lockedContract->id,
                'status' => $lockedContract->status,
                'locked' => true,
                'snapshot_sha256' => $snapshotSha256,
                'redirect_url' => route('contracts.index'),
            ];
        });

        if (($result['valid'] ?? true) === false) {
            return response()->json($result, 422);
        }

        session()->flash('success', $result['message']);

        return response()->json($result);
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

            $this->auditLogger->record('contract.snapshot_saved', $contract, [
                'status' => $contract->status,
                'snapshot_sha256' => $snapshotHash,
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

    /**
     * Build the builder-only, transient party-autofill payload for the authenticated
     * user's profile. Never includes `phone`/`country_code`, and only includes the
     * composed `name`/`address`/`oib` values when the profile has every required
     * field AND the composed name/address are within the snapshot's own field
     * length limits — an incomplete or overlong profile yields `available: false`
     * with `missing_labels`/`invalid_labels` instead of any partial value, so the
     * Blade/JS layer can never assemble a partial autofill from this payload.
     * The address is built by `composePartyProfileAddress()` below, a deliberately
     * separate, narrower composition than `UserContractProfile::fullAddress()` —
     * it never appends `country_code`, by key or by value.
     *
     * @return array{
     *     available: bool,
     *     name?: string,
     *     address?: string,
     *     oib?: string,
     *     missing_labels: list<string>,
     *     invalid_labels: list<string>,
     *     profile_edit_url: string,
     * }
     */
    private function partyProfileAutofillPayload(?UserContractProfile $profile): array
    {
        $profileEditUrl = route('profile.edit');

        if ($profile === null) {
            return [
                'available' => false,
                'missing_labels' => array_values(self::PARTY_PROFILE_REQUIRED_FIELD_LABELS),
                'invalid_labels' => [],
                'profile_edit_url' => $profileEditUrl,
            ];
        }

        $missingFields = $profile->missingContractAutofillFields();
        $missingLabels = array_map(
            static fn (string $field): string => self::PARTY_PROFILE_REQUIRED_FIELD_LABELS[$field],
            $missingFields
        );

        $nameFieldsMissing = array_intersect(['first_name', 'last_name'], $missingFields) !== [];
        $addressFieldsMissing = array_intersect(['address_line1', 'postal_code', 'city'], $missingFields) !== [];

        $invalidLabels = [];
        $name = null;
        $address = null;

        if (! $nameFieldsMissing) {
            $name = $profile->displayName();

            if (mb_strlen($name) > self::PARTY_PROFILE_NAME_MAX_LENGTH) {
                $invalidLabels[] = 'Ime i prezime (predugačko za automatsko popunjavanje ugovora)';
                $name = null;
            }
        }

        if (! $addressFieldsMissing) {
            $address = $this->composePartyProfileAddress($profile);

            if (mb_strlen($address) > self::PARTY_PROFILE_ADDRESS_MAX_LENGTH) {
                $invalidLabels[] = 'Adresa (predugačka za automatsko popunjavanje ugovora)';
                $address = null;
            }
        }

        if ($missingFields !== [] || $invalidLabels !== []) {
            return [
                'available' => false,
                'missing_labels' => $missingLabels,
                'invalid_labels' => $invalidLabels,
                'profile_edit_url' => $profileEditUrl,
            ];
        }

        return [
            'available' => true,
            'name' => $name,
            'address' => $address,
            'oib' => (string) $profile->oib,
            'missing_labels' => [],
            'invalid_labels' => [],
            'profile_edit_url' => $profileEditUrl,
        ];
    }

    /**
     * Compose the M7.3 builder address from exactly `address_line1` + `address_line2`
     * + `postal_code` + `city` — deliberately narrower than
     * `UserContractProfile::fullAddress()`, which also appends a non-HR
     * `country_code`. This method never reads `country_code` at all, so neither
     * its key nor its value can ever reach the builder autofill address.
     *
     * Does not modify or call `fullAddress()` itself, since other features
     * (e.g. the profile edit view) rely on its country-code-aware behavior.
     */
    private function composePartyProfileAddress(UserContractProfile $profile): string
    {
        $cityLine = trim((string) $profile->postal_code.' '.(string) $profile->city);

        $segments = [
            $profile->address_line1,
            $profile->address_line2,
            $cityLine !== '' ? $cityLine : null,
        ];

        return implode(', ', array_filter($segments, static fn ($segment): bool => filled($segment)));
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

    private function resolveAccessibleFinalPdf(Request $request, Contract $contract): StoredFile
    {
        abort_unless($contract->created_by_user_id === $request->user()->id, 403);
        abort_unless($contract->isFinalized(), 403);

        $storedFile = $contract->finalPdfFile;

        abort_unless(
            $storedFile
                && $storedFile->purpose === StoredFile::PURPOSE_FINAL_PDF
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
