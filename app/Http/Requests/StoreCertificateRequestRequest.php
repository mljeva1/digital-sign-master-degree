<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The ONLY value a submitter may supply is their own free-text note.
 *
 * user_id, status, reviewer, attempt id and certificate_id are never accepted
 * from the browser: the subject is always the authenticated user and every
 * lifecycle field is assigned by the workflow service.
 */
class StoreCertificateRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function requestNote(): ?string
    {
        $note = $this->input('request_note');

        return is_string($note) ? $note : null;
    }
}
