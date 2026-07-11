<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class UserContractProfileController extends Controller
{
    /**
     * The optional personal-data fields, in a fixed order.
     * `user_id` is deliberately absent — the owner is always the authenticated user.
     *
     * @var list<string>
     */
    private const PROFILE_FIELDS = [
        'first_name',
        'last_name',
        'oib',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'country_code',
        'phone',
    ];

    /**
     * Show the authenticated user's own profile form.
     * A plain GET never creates a profile row; the profile may be null.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'profile' => $request->user()->contractProfile,
        ]);
    }

    /**
     * Persist the authenticated user's own profile.
     * The profile is always resolved from the authenticated user, never from an ID
     * or from a `user_id` in the request payload.
     */
    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        // Normalize the country code to uppercase before validation; null stays null.
        if (is_string($request->input('country_code'))) {
            $request->merge([
                'country_code' => strtoupper($request->input('country_code')),
            ]);
        }

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'oib' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        // Resolve the profile from the authenticated user only; firstOrNew sets user_id
        // for a new record and never trusts request input for ownership.
        $profile = $request->user()->contractProfile()->firstOrNew();
        $existedBefore = $profile->exists;

        $attributes = [];
        $changedFields = [];

        foreach (self::PROFILE_FIELDS as $field) {
            $newValue = $validated[$field] ?? null;
            $attributes[$field] = $newValue;

            if ($profile->{$field} !== $newValue) {
                $changedFields[] = $field;
            }
        }

        DB::transaction(function () use ($profile, $attributes, $existedBefore, $changedFields, $auditLogger): void {
            $profile->fill($attributes);
            $profile->save();

            // Audit only a real change; an unchanged save (incl. an empty new profile)
            // produces no audit event. Metadata carries structure only, never values.
            if ($changedFields === []) {
                return;
            }

            $auditLogger->record(
                $existedBefore ? 'user_contract_profile.updated' : 'user_contract_profile.created',
                null,
                [
                    'operation_name' => $existedBefore ? 'profile.update' : 'profile.create',
                    'updated_fields' => $changedFields,
                ],
                $profile,
            );
        });

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Profil je spremljen.');
    }
}
