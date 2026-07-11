<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $documents = $this->ownUserUploadsQuery($request)
            ->orderByDesc('id')
            ->paginate(10);

        return view('documents.index', [
            'documents' => $documents,
        ]);
    }

    public function create(): View
    {
        return view('documents.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        $uploadedFile = $request->file('document');

        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $sizeBytes = $uploadedFile->getSize();

        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = 'documents/'.now()->format('Y/m');

        $storagePath = $uploadedFile->storeAs(
            $directory,
            $storedName,
            StoredFile::DISK_LOCAL
        );

        try {
            $absolutePath = Storage::disk(StoredFile::DISK_LOCAL)->path($storagePath);

            $sha256 = hash_file('sha256', $absolutePath);

            // Server-side content detection (Symfony's finfo-backed guesser),
            // never the client-declared Content-Type header.
            $mimeType = $uploadedFile->getMimeType();

            if ($mimeType === null || $mimeType === '' || strlen($mimeType) > 120) {
                throw new RuntimeException('Poslužiteljska MIME detekcija dokumenta nije uspjela.');
            }

            StoredFile::create([
                'purpose' => StoredFile::PURPOSE_USER_UPLOAD,
                'storage_disk' => StoredFile::DISK_LOCAL,
                'storage_path' => $storagePath,
                'original_filename' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'sha256' => $sha256,
                'created_by_user_id' => $request->user()->id,
            ]);
        } catch (Throwable $e) {
            // Persisting the metadata row failed after the bytes were written;
            // remove the now-orphaned private file before surfacing the error.
            Storage::disk(StoredFile::DISK_LOCAL)->delete($storagePath);

            throw $e;
        }

        return redirect()
            ->route('documents.index')
            ->with('success', 'Dokument je uspješno spremljen u private storage i SHA-256 hash je izračunat.');
    }

    public function show(Request $request, int $file): View
    {
        $document = $this->findOwnUserUpload($request, $file);

        return view('documents.show', [
            'document' => $document,
        ]);
    }

    public function download(Request $request, int $file): StreamedResponse
    {
        $document = $this->findOwnUserUpload($request, $file);

        abort_if(
            ! Storage::disk($document->storage_disk)->exists($document->storage_path),
            404
        );

        return Storage::disk($document->storage_disk)->download(
            $document->storage_path,
            $document->original_filename,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function verify(Request $request, int $file): RedirectResponse
    {
        $document = $this->findOwnUserUpload($request, $file);

        abort_if(
            ! Storage::disk($document->storage_disk)->exists($document->storage_path),
            404
        );

        $currentHash = hash_file(
            'sha256',
            Storage::disk($document->storage_disk)->path($document->storage_path)
        );

        if (! hash_equals($document->sha256, $currentHash)) {
            return back()->withErrors([
                'document' => 'Hash se ne podudara. Dokument je možda izmijenjen.',
            ]);
        }

        return back()->with('success', 'SHA-256 provjera je uspješna. Dokument nije promijenjen.');
    }

    /**
     * Base query scoped to the authenticated user's own user_upload documents.
     * Both conditions (owner + purpose) are always applied together so the
     * Documents module can never reach another user's row or a row belonging to
     * a different purpose (draft_pdf, final_pdf, certificate, identity_capture,
     * cms_signature, ...).
     */
    private function ownUserUploadsQuery(Request $request)
    {
        return StoredFile::query()
            ->where('created_by_user_id', $request->user()->id)
            ->where('purpose', StoredFile::PURPOSE_USER_UPLOAD);
    }

    /**
     * Resolve a single own user_upload document or abort 404. A missing id, a
     * foreign owner, or any non-user_upload purpose all resolve to 404 without
     * disclosing whether the underlying file row exists.
     */
    private function findOwnUserUpload(Request $request, int $file): StoredFile
    {
        $document = $this->ownUserUploadsQuery($request)
            ->where('id', $file)
            ->first();

        abort_if($document === null, 404);

        return $document;
    }
}
