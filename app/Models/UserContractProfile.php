<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserContractProfile extends Model
{
    use HasFactory;

    /**
     * Only optional personal-data columns are mass assignable.
     * `user_id` is intentionally excluded so it can never be set from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * First + last name, collapsed to a single trimmed string.
     * No fallback to the auth user's name; empty when both parts are blank.
     */
    public function displayName(): string
    {
        return trim((string) $this->first_name.' '.(string) $this->last_name);
    }

    /**
     * Conservatively join the available structured address parts.
     * The country code is only shown when it is set and not HR,
     * and no empty segments or stray separators are produced.
     */
    public function fullAddress(): string
    {
        $cityLine = trim((string) $this->postal_code.' '.(string) $this->city);

        $segments = [
            $this->address_line1,
            $this->address_line2,
            $cityLine !== '' ? $cityLine : null,
        ];

        if (filled($this->country_code) && strtoupper((string) $this->country_code) !== 'HR') {
            $segments[] = strtoupper((string) $this->country_code);
        }

        return implode(', ', array_filter($segments, static fn ($segment): bool => filled($segment)));
    }

    /**
     * Names of the fields still needed before this profile can autofill a contract party.
     *
     * @return list<string>
     */
    public function missingContractAutofillFields(): array
    {
        $required = [
            'first_name',
            'last_name',
            'oib',
            'address_line1',
            'postal_code',
            'city',
        ];

        return array_values(array_filter(
            $required,
            fn (string $field): bool => blank($this->{$field})
        ));
    }

    public function isCompleteForContractAutofill(): bool
    {
        return $this->missingContractAutofillFields() === [];
    }
}
