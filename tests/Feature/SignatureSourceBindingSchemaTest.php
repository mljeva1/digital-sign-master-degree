<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Signature;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Testing\PostgresGuardResult;
use App\Support\Testing\PostgresTestConnectionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * M10 source-binding schema proof against a REAL PostgreSQL database.
 *
 * These assertions target physical PostgreSQL CHECK / FK / partial-unique
 * behaviour that SQLite cannot represent, so they run ONLY against an
 * explicitly configured, isolated PostgreSQL test database. The isolation gate
 * (see setUp) refuses to run unless ALL of the following hold, and it decides
 * BEFORE opening any transaction or issuing any write SQL:
 *
 *   - DB_PG_TEST_ENABLED is truthy (explicit opt-in);
 *   - DB_PG_TEST_CONNECTION names a connection present in config;
 *   - that name is neither the development connection nor 'pgsql';
 *   - that connection's driver is pgsql;
 *   - the DEVELOPMENT side is an explicit, configured pgsql connection too
 *     (self::DEVELOPMENT_CONNECTION = 'pgsql_development'), never
 *     config('database.default') — under phpunit.xml the default is SQLite
 *     :memory:, which could never prove isolation between two real PostgreSQL
 *     databases;
 *   - SELECT current_database() resolved over BOTH real connections returns a
 *     test database that (a) differs from the development database and
 *     (b) ends with a _test or _testing marker.
 *
 * It therefore never runs against the development database. Each test runs
 * inside a transaction rolled back in tearDown, so no row persists even on the
 * test database.
 *
 * SQLSTATE reference: FK insert = 23503, ON DELETE RESTRICT = 23001,
 * CHECK = 23514, NOT NULL = 23502, unique violation = 23505.
 *
 * PostgreSQL distinguishes the two foreign-key failure directions: inserting a
 * child row that references a missing parent raises foreign_key_violation
 * (23503), whereas deleting a still-referenced parent under an explicit
 * ON DELETE RESTRICT action raises restrict_violation (23001) — RESTRICT is
 * checked immediately, unlike NO ACTION which would defer to 23503. The FK here
 * is declared restrictOnDelete(), so the delete case asserts 23001. (Verified
 * on the first real run against an isolated PostgreSQL 18.4 test database,
 * M13 — before this the suite had never actually executed against real PG.)
 *
 * Deviation note (physical schema outranks the audit request): a pending row
 * missing document_hash_before is rejected by the column-level NOT NULL
 * constraint (23502), which fires before — and shadows — the redundant
 * document_hash_before clause inside signatures_pending_required_fields_check.
 * There is no insert that reaches 23514 for that column, so the corresponding
 * test asserts the real 23502 behaviour rather than a 23514 that cannot occur.
 */
final class SignatureSourceBindingSchemaTest extends TestCase
{
    private bool $transacting = false;

    private int $userId;

    private int $contractId;

    private int $certificateId;

    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Explicit PostgreSQL development identity for the isolation check.
     *
     * NEVER `config('database.default')`: under phpunit.xml the default is
     * SQLite :memory:, which cannot prove isolation between two real PostgreSQL
     * databases. This connection resolves its name from PG_DEVELOPMENT_* with a
     * literal default and is only ever read (SELECT current_database()).
     */
    public const DEVELOPMENT_CONNECTION = 'pgsql_development';

    private const SQLSTATE_NOT_NULL = '23502';

    private const SQLSTATE_FK = '23503';

    private const SQLSTATE_RESTRICT = '23001';

    private const SQLSTATE_CHECK = '23514';

    private const SQLSTATE_UNIQUE = '23505';

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardIsolatedPostgresConnection();

        DB::beginTransaction();
        $this->transacting = true;

        try {
            $this->userId = User::factory()->create()->id;
            $certFileId = $this->makeFileId('certificate');
            $this->contractId = DB::table('contracts')->insertGetId([
                'contract_number' => 'M10-'.uniqid(), 'status' => 'finalized', 'place' => 'Zagreb',
                'contract_date' => now()->toDateString(), 'price_amount' => 0, 'currency' => 'EUR',
                'filled_data_snapshot' => json_encode(['x' => 1]), 'created_by_user_id' => $this->userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->certificateId = DB::table('certificates')->insertGetId([
                'owner_type' => 'user', 'owner_user_id' => $this->userId, 'label' => 't',
                'subject_dn' => 'CN=t', 'issuer_dn' => 'CN=t', 'serial_number' => '1',
                'valid_from' => now()->subDay(), 'valid_to' => now()->addYear(),
                'thumbprint_sha256' => str_repeat('b', 64), 'file_id' => $certFileId, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Fallback rollback so a failed fixture setup never leaks an open
            // transaction, then re-throw the original error unchanged.
            if ($this->transacting) {
                DB::rollBack();
                $this->transacting = false;
            }

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        if ($this->transacting) {
            DB::rollBack();
            $this->transacting = false;
        }

        parent::tearDown();
    }

    /**
     * Refuse to run unless pointed at an explicitly opted-in, isolated,
     * clearly-marked PostgreSQL test database.
     *
     * Skip/fail semantics (fail-closed, no false green):
     *  - opt-in OFF (DB_PG_TEST_ENABLED not truthy) → precise skip;
     *  - opt-in ON + safe, isolated config          → run;
     *  - opt-in ON + unsafe/unresolvable config      → FAIL, never skip.
     *
     * An operator who set DB_PG_TEST_ENABLED=true but mis-configured the
     * connection (e.g. a PG_TEST_URL pointing at the development database) must
     * see a failure, not a green skip. The rule is shared with the
     * testing:prepare-postgres command via {@see PostgresTestConnectionGuard},
     * so the two cannot drift.
     */
    private function guardIsolatedPostgresConnection(): void
    {
        $decision = self::postgresGateDecisionUsing(app(PostgresTestConnectionGuard::class));

        if ($decision['action'] === PostgresTestConnectionGuard::ACTION_SKIP) {
            $this->markTestSkipped((string) $decision['reason']);
        }

        if ($decision['action'] === PostgresTestConnectionGuard::ACTION_FAIL) {
            $this->fail('DB_PG_TEST_ENABLED=true but the isolated PostgreSQL test connection is unsafe or unresolvable: '.$decision['reason']);
        }

        // Safe: route the suite at the isolated test DB for the duration of the test.
        config(['database.default' => self::postgresConnectionPair()['test']]);
    }

    /**
     * The caller's connection pair.
     *
     * The development side is an EXPLICIT PostgreSQL connection, never
     * `config('database.default')`: phpunit.xml forces DB_DATABASE=:memory:, so
     * the default connection during a test run is SQLite and would never let the
     * guard compare two real PostgreSQL databases. Exposed so a caller-level
     * regression test can assert this contract without a real database.
     *
     * @return array{development:string, test:string}
     */
    public static function postgresConnectionPair(): array
    {
        return [
            'development' => self::DEVELOPMENT_CONNECTION,
            'test' => (string) env('DB_PG_TEST_CONNECTION'),
        ];
    }

    /**
     * Run the real caller contract through a supplied guard. Kept public+static
     * so the caller-level regression test exercises exactly this code path
     * (real connection pair, real gateDecision call) with a scripted resolver,
     * instead of re-testing guard internals.
     *
     * @return array{action:string, reason:?string, result:?PostgresGuardResult}
     */
    public static function postgresGateDecisionUsing(PostgresTestConnectionGuard $guard): array
    {
        $pair = self::postgresConnectionPair();

        return $guard->gateDecision(
            filter_var(env('DB_PG_TEST_ENABLED'), FILTER_VALIDATE_BOOLEAN),
            $pair['test'],
            $pair['development'],
        );
    }

    private function makeFileId(string $purpose): int
    {
        return DB::table('files')->insertGetId([
            'purpose' => $purpose, 'storage_disk' => 'local',
            'storage_path' => 'm10test/'.$purpose.'-'.uniqid('', true),
            'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10, 'sha256' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function signatureRow(array $overrides): array
    {
        return array_merge([
            'contract_id' => $this->contractId, 'type' => 'digital',
            'document_hash_before' => self::HASH, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function insertSignature(array $overrides): void
    {
        DB::table('signatures')->insert($this->signatureRow($overrides));
    }

    /**
     * Assert that $fn raises exactly the expected SQLSTATE naming the expected
     * database object. Runs inside a nested transaction (SAVEPOINT) so the
     * outer test transaction survives the expected violation and stays usable.
     * An unrelated SQLSTATE or a missing object name fails the test rather than
     * passing on any QueryException.
     */
    private function assertViolation(string $expectedSqlState, string $expectedObject, callable $fn): void
    {
        try {
            DB::transaction($fn);
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? (string) $e->getCode();

            $this->assertSame(
                $expectedSqlState,
                $sqlState,
                "Expected SQLSTATE {$expectedSqlState} for {$expectedObject}, got {$sqlState}: ".$e->getMessage()
            );
            $this->assertStringContainsString(
                $expectedObject,
                $e->getMessage(),
                "Violation did not name the expected object {$expectedObject}."
            );

            return;
        }

        $this->fail("Expected a {$expectedSqlState} violation naming {$expectedObject}, but the write succeeded.");
    }

    private function normalizeConstraintDef(string $def): string
    {
        $s = strtolower($def);
        $s = str_replace(['::text[]', '::character varying', '::text', '::bpchar'], '', $s);
        $s = str_replace(['(', ')', '"'], [' ', ' ', ''], $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    private function constraintDef(string $name): string
    {
        $row = DB::selectOne(
            "select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'signatures'::regclass and conname = ?",
            [$name]
        );

        $this->assertNotNull($row, "Constraint {$name} is missing.");

        return $row->def;
    }

    public function test_nonexistent_source_file_id_violates_foreign_key(): void
    {
        $this->assertViolation(self::SQLSTATE_FK, 'signatures_source_file_id_foreign', fn () => $this->insertSignature([
            'status' => 'pending', 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'source_file_id' => 999999999,
        ]));
    }

    public function test_deleting_referenced_source_file_is_restricted(): void
    {
        $source = $this->makeFileId('final_pdf');
        $this->insertSignature([
            'status' => 'pending', 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'source_file_id' => $source,
        ]);

        // ON DELETE RESTRICT raises restrict_violation (23001), not the
        // insert-direction foreign_key_violation (23503) asserted above.
        $this->assertViolation(self::SQLSTATE_RESTRICT, 'signatures_source_file_id_foreign', fn () => DB::table('files')->where('id', $source)->delete());
    }

    public function test_completed_signature_requires_source_file_id(): void
    {
        $sig = $this->makeFileId('cms_signature');

        $this->assertViolation(self::SQLSTATE_CHECK, 'signatures_completed_required_fields_check', fn () => $this->insertSignature([
            'status' => 'completed', 'signed_at' => now(), 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'signature_file_id' => $sig,
            'source_file_id' => null, 'document_hash_after' => self::HASH,
        ]));
    }

    public function test_pending_signature_requires_signer_certificate_and_source(): void
    {
        $source = $this->makeFileId('final_pdf');

        // missing signed_user_id
        $this->assertViolation(self::SQLSTATE_CHECK, 'signatures_pending_required_fields_check', fn () => $this->insertSignature([
            'status' => 'pending', 'certificate_id' => $this->certificateId, 'source_file_id' => $source,
        ]));
        // missing certificate_id
        $this->assertViolation(self::SQLSTATE_CHECK, 'signatures_pending_required_fields_check', fn () => $this->insertSignature([
            'status' => 'pending', 'signed_user_id' => $this->userId, 'source_file_id' => $source,
        ]));
        // missing source_file_id
        $this->assertViolation(self::SQLSTATE_CHECK, 'signatures_pending_required_fields_check', fn () => $this->insertSignature([
            'status' => 'pending', 'signed_user_id' => $this->userId, 'certificate_id' => $this->certificateId,
        ]));
    }

    public function test_pending_without_document_hash_before_is_rejected_by_column_not_null(): void
    {
        // Physical reality: document_hash_before is column-level NOT NULL, so
        // its absence raises 23502 naming the column, before/instead of the
        // redundant document_hash_before clause in the pending CHECK (which
        // could only ever raise 23514). This proves the column is enforced;
        // it deliberately does NOT claim a 23514 that cannot be produced.
        $source = $this->makeFileId('final_pdf');

        $this->assertViolation(self::SQLSTATE_NOT_NULL, 'document_hash_before', fn () => DB::table('signatures')->insert([
            'contract_id' => $this->contractId, 'type' => 'digital', 'status' => 'pending',
            'signed_user_id' => $this->userId, 'certificate_id' => $this->certificateId,
            'source_file_id' => $source, 'document_hash_before' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_partial_unique_blocks_second_pending_for_same_tuple(): void
    {
        $source = $this->makeFileId('final_pdf');
        $row = [
            'status' => 'pending', 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'source_file_id' => $source,
        ];

        $this->insertSignature($row);
        $this->assertViolation(self::SQLSTATE_UNIQUE, 'signatures_contract_user_source_active_unique', fn () => $this->insertSignature($row));
    }

    public function test_partial_unique_blocks_completed_when_pending_exists(): void
    {
        $source = $this->makeFileId('final_pdf');
        $sig = $this->makeFileId('cms_signature');

        $this->insertSignature([
            'status' => 'pending', 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'source_file_id' => $source,
        ]);

        $this->assertViolation(self::SQLSTATE_UNIQUE, 'signatures_contract_user_source_active_unique', fn () => $this->insertSignature([
            'status' => 'completed', 'signed_at' => now(), 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'signature_file_id' => $sig,
            'source_file_id' => $source, 'document_hash_after' => self::HASH,
        ]));
    }

    public function test_partial_unique_blocks_second_completed_for_same_tuple(): void
    {
        $source = $this->makeFileId('final_pdf');

        $this->insertSignature([
            'status' => 'completed', 'signed_at' => now(), 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'signature_file_id' => $this->makeFileId('cms_signature'),
            'source_file_id' => $source, 'document_hash_after' => self::HASH,
        ]);

        $this->assertViolation(self::SQLSTATE_UNIQUE, 'signatures_contract_user_source_active_unique', fn () => $this->insertSignature([
            'status' => 'completed', 'signed_at' => now(), 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'signature_file_id' => $this->makeFileId('cms_signature'),
            'source_file_id' => $source, 'document_hash_after' => self::HASH,
        ]));
    }

    public function test_rejected_and_expired_signatures_are_not_covered_by_partial_index(): void
    {
        $source = $this->makeFileId('final_pdf');
        $tuple = [
            'signed_user_id' => $this->userId, 'certificate_id' => $this->certificateId,
            'source_file_id' => $source,
        ];

        $this->insertSignature(array_merge($tuple, ['status' => 'pending']));

        // Same (contract, user, source) tuple but non-active statuses: allowed.
        $this->insertSignature(array_merge($tuple, ['status' => 'rejected']));
        $this->insertSignature(array_merge($tuple, ['status' => 'expired']));

        $count = DB::table('signatures')
            ->where('contract_id', $this->contractId)
            ->where('signed_user_id', $this->userId)
            ->where('source_file_id', $source)
            ->count();

        $this->assertSame(3, $count);
    }

    public function test_source_file_id_mirrors_contracts_final_pdf_file_id_locked_orchestration_contract(): void
    {
        // The locked orchestration decision: source_file_id references the
        // SAME StoredFile that contracts.final_pdf_file_id already points to
        // (no redundant PDF copy). This is an application-level contract, NOT
        // a database constraint — PostgreSQL does not force the equality.
        $finalPdf = $this->makeFileId('final_pdf');

        DB::table('contracts')->where('id', $this->contractId)->update([
            'final_pdf_file_id' => $finalPdf,
            'final_pdf_sha256' => self::HASH,
        ]);

        $this->insertSignature([
            'status' => 'pending', 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'source_file_id' => $finalPdf,
        ]);

        $contractFinalPdfId = (int) DB::table('contracts')->where('id', $this->contractId)->value('final_pdf_file_id');
        $signatureSourceId = (int) DB::table('signatures')->where('source_file_id', $finalPdf)->value('source_file_id');

        $this->assertSame($finalPdf, $contractFinalPdfId);
        $this->assertSame($finalPdf, $signatureSourceId);
        $this->assertSame(
            $contractFinalPdfId,
            $signatureSourceId,
            'Orchestration contract: source_file_id mirrors contracts.final_pdf_file_id (not DB-enforced).'
        );
    }

    public function test_source_file_relation_resolves_on_completed_signature(): void
    {
        $source = $this->makeFileId('final_pdf');
        $this->insertSignature([
            'status' => 'completed', 'signed_at' => now(), 'signed_user_id' => $this->userId,
            'certificate_id' => $this->certificateId, 'signature_file_id' => $this->makeFileId('cms_signature'),
            'source_file_id' => $source, 'document_hash_after' => self::HASH,
        ]);

        $signature = Signature::query()->where('source_file_id', $source)->firstOrFail();

        $this->assertInstanceOf(StoredFile::class, $signature->sourceFile);
        $this->assertSame($source, $signature->sourceFile->id);
        $this->assertSame(StoredFile::PURPOSE_FINAL_PDF, $signature->sourceFile->purpose);
    }

    public function test_source_binding_constraints_and_index_match_expected_definitions(): void
    {
        $this->assertSame(
            'foreign key source_file_id references files id on delete restrict',
            $this->normalizeConstraintDef($this->constraintDef('signatures_source_file_id_foreign')),
        );

        $this->assertSame(
            "check status <> 'completed' or signed_at is not null and signed_user_id is not null "
            .'and certificate_id is not null and signature_file_id is not null and source_file_id is not null '
            .'and document_hash_before is not null and document_hash_after is not null '
            .'and document_hash_before = document_hash_after',
            $this->normalizeConstraintDef($this->constraintDef('signatures_completed_required_fields_check')),
        );

        $this->assertSame(
            "check status <> 'pending' or signed_user_id is not null and certificate_id is not null "
            .'and source_file_id is not null and document_hash_before is not null',
            $this->normalizeConstraintDef($this->constraintDef('signatures_pending_required_fields_check')),
        );

        // Partial unique index: exact column order and predicate scope.
        $indexDef = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'signatures' and indexname = ?",
            ['signatures_contract_user_source_active_unique']
        );
        $this->assertNotNull($indexDef, 'Partial unique index is missing.');

        $this->assertSame(1, preg_match('/btree\s*\(([^)]+)\)/i', $indexDef->indexdef, $cols));
        $this->assertSame(
            'contract_id,signed_user_id,source_file_id',
            preg_replace('/\s+/', '', $cols[1]),
        );

        $wherePos = stripos($indexDef->indexdef, 'where');
        $this->assertNotFalse($wherePos, 'Index has no partial predicate.');
        $whereClause = substr($indexDef->indexdef, $wherePos);
        $this->assertMatchesRegularExpression('/status/i', $whereClause);

        preg_match_all("/'([a-z_]+)'/i", $whereClause, $statusMatches);
        $statuses = array_map('strtolower', $statusMatches[1]);
        sort($statuses);
        $this->assertSame(['completed', 'pending'], $statuses, 'Partial predicate must cover exactly pending and completed.');
    }
}
