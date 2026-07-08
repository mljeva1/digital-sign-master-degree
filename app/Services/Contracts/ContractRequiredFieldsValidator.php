<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use DateTimeImmutable;

final class ContractRequiredFieldsValidator
{
    /**
     * @var array<string, string>
     */
    private const REQUIRED_FIELDS = [
        'seller_name' => 'Prodavatelj',
        'seller_oib' => 'OIB prodavatelja',
        'buyer_name' => 'Kupac',
        'buyer_oib' => 'OIB kupca',
        'vehicle_brand' => 'Vozilo',
        'price_amount' => 'Cijena',
        'contract_date' => 'Datum ugovora',
        'place' => 'Mjesto ugovora',
        'vin' => 'VIN / broj šasije',
    ];

    /**
     * @return array{
     *     valid: bool,
     *     missing_fields: list<array{key: string, label: string}>,
     *     invalid_fields: list<array{key: string, label: string, reason: string}>
     * }
     */
    public function validate(array $snapshot): array
    {
        $missingFields = [];
        $invalidFields = [];

        foreach (self::REQUIRED_FIELDS as $key => $label) {
            if (! array_key_exists($key, $snapshot) || $this->isEmpty($snapshot[$key])) {
                $missingFields[] = [
                    'key' => $key,
                    'label' => $label,
                ];
            }
        }

        $this->validateOib($snapshot, 'seller_oib', 'OIB prodavatelja', $missingFields, $invalidFields);
        $this->validateOib($snapshot, 'buyer_oib', 'OIB kupca', $missingFields, $invalidFields);
        $this->validatePrice($snapshot, $missingFields, $invalidFields);
        $this->validateContractDate($snapshot, $missingFields, $invalidFields);

        return [
            'valid' => $missingFields === [] && $invalidFields === [],
            'missing_fields' => $missingFields,
            'invalid_fields' => $invalidFields,
        ];
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }

    /**
     * @param  list<array{key: string, label: string}>  $missingFields
     * @param  list<array{key: string, label: string, reason: string}>  $invalidFields
     */
    private function validateOib(
        array $snapshot,
        string $key,
        string $label,
        array $missingFields,
        array &$invalidFields
    ): void {
        if ($this->isMissing($missingFields, $key)) {
            return;
        }

        $value = $snapshot[$key];

        if ((! is_string($value) && ! is_int($value))
            || preg_match('/^\d{11}$/', trim((string) $value)) !== 1) {
            $invalidFields[] = [
                'key' => $key,
                'label' => $label,
                'reason' => $label.' mora imati 11 znamenki.',
            ];
        }
    }

    /**
     * @param  list<array{key: string, label: string}>  $missingFields
     * @param  list<array{key: string, label: string, reason: string}>  $invalidFields
     */
    private function validatePrice(
        array $snapshot,
        array $missingFields,
        array &$invalidFields
    ): void {
        if ($this->isMissing($missingFields, 'price_amount')) {
            return;
        }

        $value = $snapshot['price_amount'];

        if (! is_numeric($value) || (float) $value <= 0) {
            $invalidFields[] = [
                'key' => 'price_amount',
                'label' => 'Cijena',
                'reason' => 'Cijena mora biti numerička vrijednost veća od 0.',
            ];
        }
    }

    /**
     * @param  list<array{key: string, label: string}>  $missingFields
     * @param  list<array{key: string, label: string, reason: string}>  $invalidFields
     */
    private function validateContractDate(
        array $snapshot,
        array $missingFields,
        array &$invalidFields
    ): void {
        if ($this->isMissing($missingFields, 'contract_date')) {
            return;
        }

        $value = $snapshot['contract_date'];
        $date = is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', trim($value))
            : false;

        if ($date === false || $date->format('Y-m-d') !== trim((string) $value)) {
            $invalidFields[] = [
                'key' => 'contract_date',
                'label' => 'Datum ugovora',
                'reason' => 'Datum ugovora mora biti valjan datum.',
            ];
        }
    }

    /**
     * @param  list<array{key: string, label: string}>  $missingFields
     */
    private function isMissing(array $missingFields, string $key): bool
    {
        foreach ($missingFields as $field) {
            if ($field['key'] === $key) {
                return true;
            }
        }

        return false;
    }
}
