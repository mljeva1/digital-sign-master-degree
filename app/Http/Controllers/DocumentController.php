<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(): View
    {
        $documents = DB::table('files')
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
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        $uploadedFile = $request->file('document');

        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();

        $storedName = Str::uuid()->toString() . '.' . $extension;

        $directory = 'documents/' . now()->format('Y/m');

        $storedPath = $uploadedFile->storeAs(
            $directory, 
            $storedName, 
            'local'
        );

        $absolutePath = Storage::disk('local')->path($storedPath);

        $sha256Hash = hash_file('sha256', $absolutePath);

        DB::table('files')->insert([
            'original_name' => $validated['title'] ?: $originalName,
            'stored_name' => $storedName,
            'disk' => 'local',
            'path' => $storedPath,
            'mime_type' => $uploadedFile->getMimeType(),
            'size_bytes' => $uploadedFile->getSize(),
            'sha256_hash' => $sha256Hash,
            'uploaded_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('documents.index')
            ->with('success', 'Dokument je uspješno spremljen u private storage i SHA-256 hash je izračunat.');
    }

    public function show(int $file): View
    {
        $document = DB::table('files')
            ->where('id', $file)
            ->first();

        abort_if(! $document, 404);

        return view('documents.show', [
            'document' => $document,
        ]);
    }

    public function download(int $file): StreamedResponse
    {
        $document = DB::table('files')
            ->where('id', $file)
            ->first();

        abort_if(! $document, 404);

        abort_if(! Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->original_name
        );
    }

    public function verify(int $file): RedirectResponse
    {
        $document = DB::table('files')
            ->where('id', $file)
            ->first();

        abort_if(! $document, 404);

        abort_if(! Storage::disk($document->disk)->exists($document->path), 404);

        $currentHash = hash_file(
            'sha256',
            Storage::disk($document->disk)->path($document->path)
        );

        if (! hash_equals($document->sha256_hash, $currentHash)) {
            return back()->withErrors([
                'document' => 'Hash se ne podudara. Dokument je možda izmijenjen.',
            ]);
        }

        return back()->with('success', 'SHA-256 provjera je uspješna. Dokument nije promijenjen.');
    }
}