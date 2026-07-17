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

    /*
    | Local development default: the project-local private signing root
    | (storage/app/private/signing/local), resolved through storage_path() so it
    | never depends on the shell working directory or on a developer's home
    | directory. The whole tree is gitignored and is never web-served by project
    | code. A real deployment overrides these with out-of-repository paths via
    | env; SigningConfig only tolerates a project-local path in the local and
    | testing environments — in production the outside-repository rule stays
    | absolute.
    */

    'private_key_path' => env('SIGNING_PRIVATE_KEY_PATH', storage_path('app/private/signing/local/test-signer-key.pem')),

    'passphrase_file_path' => env('SIGNING_PASSPHRASE_FILE_PATH', storage_path('app/private/signing/local/test-signer-passphrase.txt')),

    'root_ca_path' => env('SIGNING_ROOT_CA_PATH', storage_path('app/private/signing/local/test-root-ca.pem')),

    /*
    | The ONLY directory a local provisioning run may write signing material to.
    | Declared here (not hardcoded in the command) so the boundary is one
    | reviewable value shared by the command and its tests.
    */

    'local_material_path' => storage_path('app/private/signing/local'),

    /*
    | Private storage disk that holds the public signer certificate artefact
    | (files.purpose = certificate). Must be a private (non-public) disk.
    */

    'certificate_disk' => env('SIGNING_CERTIFICATE_DISK', 'local'),

];
