<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Local X.509 signer material (M10 foundation)
    |--------------------------------------------------------------------------
    |
    | These are EXTERNAL, out-of-repository filesystem paths. The private key
    | and its passphrase file must live outside the repository and outside any
    | public directory/disk. The passphrase is ALWAYS read from a separate file
    | at runtime — it is never expressed as a config string. The Root CA entry
    | is the local trust anchor CERTIFICATE only; the Root CA private key is not
    | a runtime dependency and must never be configured or requested.
    |
    | No PEM content, private key, or passphrase value is ever placed into the
    | Laravel config cache, the database, logs, exception messages, or command
    | output — only these path references live here.
    |
    */

    'private_key_path' => env('SIGNING_PRIVATE_KEY_PATH'),

    'passphrase_file_path' => env('SIGNING_PASSPHRASE_FILE_PATH'),

    'root_ca_path' => env('SIGNING_ROOT_CA_PATH'),

    /*
    | Private storage disk that holds the public signer certificate artefact
    | (files.purpose = certificate). Must be a private (non-public) disk.
    */

    'certificate_disk' => env('SIGNING_CERTIFICATE_DISK', 'local'),

];
