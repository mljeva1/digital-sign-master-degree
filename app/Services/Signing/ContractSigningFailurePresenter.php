<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Exceptions\Signing\ContractSigningException;

/**
 * Maps every stable ContractSigningException error code to a fixed, safe
 * Croatian user message for the signing HTTP flow.
 *
 * Only the STABLE CODE is ever consulted — never the exception message, a
 * path, SQL text, or an OpenSSL detail. Unknown codes fall back to a neutral
 * sentence, so a future code can never leak through unmapped. A test proves
 * every declared code constant resolves to a non-fallback message.
 */
final class ContractSigningFailurePresenter
{
    private const FALLBACK = 'Potpisivanje nije uspjelo. Pokušajte ponovno kasnije.';

    public static function message(string $errorCode): string
    {
        return match ($errorCode) {
            ContractSigningException::CONTRACT_NOT_FOUND => 'Ugovor za potpisivanje nije pronađen.',
            ContractSigningException::CONTRACT_NOT_SIGNABLE => 'Ugovor nije u stanju u kojem se može potpisati.',
            ContractSigningException::SIGNING_NOT_AUTHORIZED => 'Nemate ovlasti za potpisivanje ovog ugovora.',
            ContractSigningException::FINAL_PDF_MISSING => 'Ugovor nema finalni PDF koji bi se potpisao.',
            ContractSigningException::FINAL_PDF_INVALID => 'Finalni PDF nije prošao provjeru integriteta pa potpisivanje nije moguće.',
            ContractSigningException::SIGNER_CERTIFICATE_MISSING => 'Nemate aktivan potpisni certifikat pa potpisivanje nije moguće.',
            ContractSigningException::SIGNER_CERTIFICATE_AMBIGUOUS => 'Pronađeno je više aktivnih potpisnih certifikata; potpisivanje je zaustavljeno.',
            ContractSigningException::SIGNER_CERTIFICATE_INVALID => 'Potpisni certifikat nije valjan za potpisivanje.',
            ContractSigningException::CONTRACT_STATE_CHANGED => 'Ugovor ili njegov finalni PDF promijenjen je tijekom potpisivanja. Pokušajte ponovno.',
            ContractSigningException::SIGNING_FAILED => 'Digitalni potpis nije moguće izraditi.',
            ContractSigningException::CMS_STORAGE_FAILED => 'Potpisni artefakt nije moguće spremiti.',
            ContractSigningException::PERSISTENCE_FAILED => 'Potpis nije moguće trajno zabilježiti.',
            ContractSigningException::PERSISTED_SIGNATURE_INVALID => 'Postojeći potpis nije prošao provjeru integriteta.',
            ContractSigningException::PUBLIC_VERIFICATION_NOT_READY => 'Prije potpisivanja mora biti omogućena javna provjera dokumenta s ugrađenim QR kodom u finalnom PDF-u.',
            default => self::FALLBACK,
        };
    }

    public static function fallbackMessage(): string
    {
        return self::FALLBACK;
    }
}
