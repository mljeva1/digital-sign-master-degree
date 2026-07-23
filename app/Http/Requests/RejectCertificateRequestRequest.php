<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting always requires a documented reason. The operator identity is taken
 * from the authenticated user, never from the payload.
 */
class RejectCertificateRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operator_note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function operatorNote(): string
    {
        return (string) $this->input('operator_note');
    }
}
