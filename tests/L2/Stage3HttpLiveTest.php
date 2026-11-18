<?php
// tests/L2/Stage3HttpLiveTest.php
// PR #31 review 第1ラウンド（BLOCKING-1 の実HTTP検証要求）: PHP 組込みサーバをエフェメラル
// ポートに起動し、public/index.php を実HTTP（ルーティング・ヘッダ・body バイト）で両経路と
// L1 303 非回帰を検証する。Service 層（Stage3EndpointTest）はアプリケーションサービスの
// status/body 型を主張するが、このテストは socket 層の応答を主張する。
//
// Conformance mapping (docs/specs/resolver-l2-conformance-0.1.md):
// L2-SRV-001: active-200 statement verbatim, suspended-404, retired-410, unknown-404,
//   malformed-400, method-405+Allow, no-suspended-retired-served;
// L2-SRV-002: {"records":[Record_0..Record_G]} JSON object 要素（二重エンコード禁止）、
//   verbatim wire バイト、gap / 連鎖受理に失敗する連続番号列は 500（P1-2 live 負例）;
// L2-SRV-003: GET-only、no Set-Cookie;
// L2-SRV-004: p+l=2 → 400、authority 経路の l 引数 → 501;
// L2-SRV-005: 200 の Cache-Control は deployment bound と対象 expiresAt の min、エラーは no-store;
// §6.1 statement 行: VERIFIED の statement のみ公開（非 VERIFIED → 500、live 負例）。
//
// 時刻基準はサーバプロセスの time()（T = now）。live fixture は Record 0 と statement の
// window をテスト時刻周りに re-stamp する（§11.4 W=600 fresh snapshot、§14.2 bounded cache）。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SqliteL2Repository;
use Relink\Resolver\Adapters\SqliteMigrator;
use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\KeyId;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\StatementPayload;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;

require_once __DIR__ . '/L2Fixtures.php';
require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage3HttpLiveTest extends TestCase
{
    /** A valid, never-seeded anchor UUID proving the unknown-anchor 404 path. */
    private const UNKNOWN_ANCHOR = '550e8400-e29b-41d4-a716-446655440999';

    /** @var array{0: resource, 1: array<int, resource>}|null */
    private ?array $server = null;

    private string $path = '';

    private int $T0 = 0;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            [$process, $pipes] = $this->server;
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
            $this->server = null;
        }
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            $file = $this->path . $suffix;
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Seed the L1 record, the Trust Anchor Binding, a live-stamped Record 0 (window covering
     * the server clock, keys key1+key2 current) and the live-stamped statement S5.
     * Re-stamping uses Stage2TestSupport::wireWithChanges, which re-canonicalizes the payload
     * and re-signs with the seed key, keeping every pinned structural field byte-consistent.
     *
     * @return array{0: string, 1: string} statement wire, Record 0 wire (empty when skipped)
     */
    private function seed(LifecycleState $state = LifecycleState::ACTIVE, bool $withBinding = true, bool $withRecord0 = true, bool $withStatement = true): array
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'relink-l2-http-');
        SqliteMigrator::migrate($this->path);
        $this->T0 = time();

        $resolver = new SqliteResolverRepository($this->path);
        $resolver->insert(new ResolverRecord(new AnchorUuid(Stage2TestSupport::ANCHOR_ID), $state, new DescriptionLocation(Stage2TestSupport::DESCRIPTION_LOCATION), Stage2TestSupport::ENTITY_ID, null, null, null, 1));

        $l2 = new SqliteL2Repository($this->path);
        if ($withBinding) {
            $l2->storeBinding(Stage2TestSupport::binding());
        }

        $recordWire = '';
        if ($withRecord0) {
            $recordWire = Stage2TestSupport::wireWithChanges('Record0', [
                'issuedAt' => $this->T0 - 300,
                'expiresAt' => $this->T0 + 3600,
                'keys' => [
                    // §9.6 item 1: Record 0 keys carries exactly the binding key entry, and
                    // keys[0].validFrom equals Record 0 issuedAt (epoch start = Record 0 issuance).
                    self::keyEntry(1, $this->T0 - 300, $this->T0 + 3600),
                ],
            ], 1);
            $l2->appendRecord(Record::fromWire($recordWire), $recordWire);
        }

        $statementWire = '';
        if ($withStatement) {
            // Live chain prefix is Record 0 only (binding bootstrap, §9.6 item 1), so the
            // accepted statement must be signed by the binding key (key1, seed 1), stamped
            // inside the live key window.
            $statementWire = Stage2TestSupport::wireWithChanges('S5', ['keyId' => 'ECMERdvpV0k', 'issuedAt' => $this->T0 - 10, 'expiresAt' => $this->T0 + 3600], 1);
            $l2->appendStatement(StatementPayload::fromWire($statementWire), $statementWire);
        }

        return [$statementWire, $recordWire];
    }

    /** @return array<string, int|string> */
    private static function keyEntry(int $n, int $validFrom, int $validUntil): array
    {
        $publicKey = Stage2TestSupport::publicKeyOf($n);

        return [
            'algorithm' => 'ed25519',
            'keyId' => KeyId::compute(Base64Url::decode($publicKey)),
            'publicKey' => $publicKey,
            'status' => 'current',
            'validFrom' => $validFrom,
            'validUntil' => $validUntil,
        ];
    }

    /** Start php -S on an ephemeral loopback port with this test's DB wired via env. */
    private function startServer(): string
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($socket, 'could not reserve an ephemeral loopback port');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $env = [
            'RELINK_DB_PATH' => $this->path,
            'RELINK_SERVICE_PREFIX' => '/relink',
            'RELINK_L2_MAX_AGE' => '600',
            'RELINK_L2_SNAPSHOT_MAX_AGE' => '600',
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', $address, 'public/index.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        self::assertNotFalse($process, 'php -S server did not start');
        $this->server = [$process, $pipes];

        $base = 'http://' . $address;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 2]]);
            if (@file_get_contents($base . '/relink/ping', false, $ctx) !== false) {
                return $base;
            }
            usleep(50000);
        }

        self::fail('php -S server did not become ready within 10s');
    }

    /** @return array{0: int, 1: array<string, string>, 2: string} status, lowercase headers, body */
    private function request(string $base, string $path, string $method = 'GET'): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 5,
            // The L1 303 answer must be observed at the socket as a redirect; the stream
            // wrapper must not follow Location to the external description location.
            'follow_location' => 0,
            'max_redirects' => 1,
        ]]);
        $body = @file_get_contents($base . $path, false, $ctx);
        self::assertIsString($body, 'live HTTP response body must be readable (§6.1/§6.3)');

        $status = 0;
        $headers = [];
        foreach ($http_response_header as $line) {
            $line = (string) $line;
            if (preg_match('#^HTTP/1\.[01] (\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return [$status, $headers, $body];
    }

    /** Parse the max-age directive of a `public, max-age=N` Cache-Control header. */
    private static function maxAgeOf(string $cacheControl): int
    {
        self::assertMatchesRegularExpression('/^public, max-age=(\d+)$/', $cacheControl, '§14.2 canonical phrase form on 200');
        preg_match('/max-age=(\d+)/', $cacheControl, $match);

        return (int) $match[1];
    }

    /**
     * L2-SRV-001/live + §6.1: the statement route answers 200 with the accepted latest
     * statement wire byte-for-byte, Content-Type application/json, Cache-Control bounded by
     * the statement's own expiresAt and the deployment bound, no ambient credential, not a
     * redirect.
     */
    public function testLiveStatementRouteAnswers200WithVerbatimSignedStatementWire(): void
    {
        [$statementWire] = $this->seed();
        $base = $this->startServer();

        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2');

        self::assertSame(200, $status, 'L2-SRV-001/active-200 over real HTTP (§6.1)');
        self::assertSame('application/json; charset=utf-8', $headers['content-type'], '§6.1: conforming L2 Trust Statement served as JSON');
        self::assertSame($statementWire, $body, '§6.1: the accepted latest statement wire is served verbatim byte-for-byte');
        $maxAge = self::maxAgeOf($headers['cache-control']);
        self::assertLessThan(601, $maxAge, 'L2-SRV-005: max-age never exceeds the deployment bound (600)');
        self::assertLessThanOrEqual($this->T0 + 3600 - time(), $maxAge, '§14.2 statement row: cache lifetime never outlives the served statement\'s remaining validity');
        self::assertArrayNotHasKey('set-cookie', $headers, 'L2-SRV-003/anonymous: no ambient credential on the public read');
        self::assertArrayNotHasKey('location', $headers, '§6.1: the statement answer is not a redirect (the L1 303 path is separate)');
    }

    /**
     * L2-SRV-002/live + BLOCKING-2: the authority route answers 200 with
     * {"records":[Record_0]} where the element is a JSON object carrying the stored wire
     * bytes byte-for-byte (never a double-encoded JSON string), bounded Cache-Control.
     */
    public function testLiveAuthorityRouteAnswers200WithRecordsJsonObjectsByteExact(): void
    {
        [, $recordWire] = $this->seed();
        $base = $this->startServer();

        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '/authority');

        self::assertSame(200, $status, 'L2-SRV-001/active-200 on the /authority route over real HTTP (§6.3)');
        self::assertSame('application/json; charset=utf-8', $headers['content-type'], '§6.3 transport framing is JSON');

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, '§6.3: body is one JSON object with a single records key');
        self::assertSame(['records'], array_keys($decoded), '§6.3: no extra framing members in the transport object');
        $records = $decoded['records'];
        self::assertIsArray($records, '§6.3: records is an array of Record elements');
        self::assertCount(1, $records, '§6.3: the live chain prefix 0..0 is served complete');

        $element = $records[0];
        self::assertIsArray($element, 'BLOCKING-2/§6.3: Record element is a JSON object, not a JSON string');
        self::assertSame(['payload', 'signature'], array_keys($element), '§9.5 envelope framing of the Record element');
        $reencoded = json_encode($element, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertSame($recordWire, $reencoded, 'L2-SRV-002/verbatim: the element carries the stored canonical wire bytes byte-for-byte');

        $maxAge = self::maxAgeOf($headers['cache-control']);
        self::assertLessThanOrEqual($this->T0 + 3600 - time(), $maxAge, 'L2-SRV-005: max-age bounded by expiresAt(G) - now at response time');
        self::assertArrayNotHasKey('set-cookie', $headers, 'L2-SRV-003/anonymous: no ambient credential on the authority read');
    }

    /**
     * BLOCKING-1 non-regression: the L1 ACTIVE path (no l parameter) keeps the ordinary
     * 303 redirect to the description location, untouched by the L2 statement route.
     */
    public function testLiveL1ActivePathKeeps303RedirectNotRegressedByL2Routing(): void
    {
        $this->seed();
        $base = $this->startServer();

        [$status, $headers] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID);

        self::assertSame(303, $status, '§6.1 routing separation: L1 ACTIVE resolution keeps its Core 303 over real HTTP');
        self::assertSame(Stage2TestSupport::DESCRIPTION_LOCATION, $headers['location'] ?? '', 'L1 303 carries the Location header, never replaced by L2 statement transport');
    }

    /** L2-SRV-001/malformed-400 on both routes, answered before any material is read. */
    public function testLiveMalformedUuidAnswers400OnBothRoutes(): void
    {
        $this->seed();
        $base = $this->startServer();

        [$statementStatus, $statementHeaders] = $this->request($base, '/relink/not-a-uuid?l=2');
        self::assertSame(400, $statementStatus, 'L2-SRV-001/malformed-400 on the statement route (§6.1)');
        self::assertSame('no-store', $statementHeaders['cache-control'] ?? '', '§14.2: the 400 answer is no-store');

        [$authorityStatus, $authorityHeaders] = $this->request($base, '/relink/not-a-uuid/authority');
        self::assertSame(400, $authorityStatus, 'L2-SRV-001/malformed-400 on the authority route (§6.3)');
        self::assertSame('no-store', $authorityHeaders['cache-control'] ?? '', '§14.2: the 400 answer is no-store on the authority route');
    }

    /** L2-SRV-001/method-405 + Allow: GET on both routes (GET-only public surface, §6.4). */
    public function testLiveUnsupportedMethodAnswers405WithAllowOnBothRoutes(): void
    {
        $this->seed();
        $base = $this->startServer();

        [$status, $headers] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2', 'POST');
        self::assertSame(405, $status, 'L2-SRV-003/§6.4: no public mutation on the statement route');
        self::assertSame('GET', $headers['allow'] ?? '', '§6.1: the 405 answer declares Allow: GET on the statement route');

        [$authorityStatus, $authorityHeaders] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '/authority', 'POST');
        self::assertSame(405, $authorityStatus, 'L2-SRV-003/§6.4: no public Record submission endpoint exists on the authority route');
        self::assertSame('GET', $authorityHeaders['allow'] ?? '', '§6.4: the 405 answer declares Allow: GET on the authority route');
    }

    /**
     * L2-SRV-001 lifecycle statuses over live HTTP: unknown → 404, SUSPENDED → 404 with the
     * stored statement material never served, RETIRED → 410 (MAY-cache convention).
     */
    public function testLiveLifecycleStatusesAnswer404And410AndNeverServeSuspendedMaterial(): void
    {
        [$statementWire] = $this->seed(LifecycleState::SUSPENDED);
        $base = $this->startServer();

        [$unknownStatus, $unknownHeaders] = $this->request($base, '/relink/' . self::UNKNOWN_ANCHOR . '?l=2');
        self::assertSame(404, $unknownStatus, 'L2-SRV-001/unknown-404 over real HTTP (§6.1)');
        self::assertSame('no-store', $unknownHeaders['cache-control'] ?? '', '§14.2: the 404 answer is no-store');

        [$suspendedStatus, $suspendedHeaders, $suspendedBody] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2');
        self::assertSame(404, $suspendedStatus, 'L2-SRV-001/suspended-404 over real HTTP (§6.1)');
        self::assertStringNotContainsString($statementWire, $suspendedBody, 'L2-SRV-001/no-suspended-retired-served: SUSPENDED statement content is not served publicly');

        $this->seed(LifecycleState::RETIRED);
        $base = $this->startServer();
        [$retiredStatus, $retiredHeaders] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2');
        self::assertSame(410, $retiredStatus, 'L2-SRV-001/retired-410 over real HTTP (§6.1)');
        self::assertSame('public, max-age=300', $retiredHeaders['cache-control'] ?? '', '§14.2 MAY-cache reference convention on 410 (documented deviation)');
    }

    /** L2-SRV-004/§6.3+§6.5: the /authority path carries no l parameter; l there is an unsupported combination, 501 no-store. */
    public function testLiveAuthorityRouteRejectsLParameterWith501(): void
    {
        $this->seed();
        $base = $this->startServer();

        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '/authority?l=2');

        self::assertSame(501, $status, 'L2-SRV-004/§6.5: l=2 on the authority path is never accepted as L2 semantics (§6.3 framing)');
        self::assertSame('no-store', $headers['cache-control'] ?? '', '§14.2: the 501 answer is no-store');
        self::assertSame('', $body, '§6.5: no records transport assembled for the unsupported combination');
    }

    /** L2-SRV-004/§6.2: p with l=2 is fail-closed 400 before any material is read. */
    public function testLivePWithL2Answers400FailClosed(): void
    {
        $this->seed();
        $base = $this->startServer();

        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2&p=https%3A%2F%2Fsensors.example%2Fthermal%2F42%2Far-xml');

        self::assertSame(400, $status, 'L2-SRV-004/§6.2: p fail-closed 400 on the statement route over live HTTP');
        self::assertSame('no-store', $headers['cache-control'] ?? '', '§14.2: the 400 answer is no-store');
        self::assertSame('', $body, '§6.2: no L2 material served for the p+l combination');
    }

    /** DEFENSIBLE-BUT-UNPINNED documented deviation (ii): ACTIVE anchor without a binding answers 501, storage never read. */
    public function testLiveAnchorWithoutBindingAnswers501OnStatementRoute(): void
    {
        $this->seed(withBinding: false);
        $base = $this->startServer();

        [$status, $headers] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2');

        self::assertSame(501, $status, 'documented deviation (ii): no Trust Anchor Binding, no L2 support, 501 no-store (§9.3 precondition)');
        self::assertSame('no-store', $headers['cache-control'] ?? '', '§14.2: the 501 answer is no-store');
    }

    /**
     * P1-2 live negative: numbering 0..1 is contiguous in storage, but the Record 1 wire does
     * not bind to the live-stamped Record 0 (prevRecordDigest is computed over the pinned
     * Record 0 payload). The chain walk rejects at Record 1, so the authority route answers
     * 500 no-store, not 200 (numbering contiguity alone is not chain acceptance).
     */
    public function testLiveContiguousStoreFailingChainWalkIsNotServedAtAuthority(): void
    {
        [, $recordWire] = $this->seed();
        self::assertNotSame('', $recordWire, 'seed precondition: live-stamped Record 0 stored');

        $l2 = new SqliteL2Repository($this->path);
        $pinnedWire = Stage2TestSupport::wire('Record1');
        $l2->appendRecord(Record::fromWire($pinnedWire), $pinnedWire);

        $base = $this->startServer();
        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '/authority');

        self::assertSame(500, $status, 'P1-2/§6.1 internal-failure row: contiguous numbering that fails the chain walk is never public transport (§6.3/§9.6)');
        self::assertSame('no-store', $headers['cache-control'] ?? '', '§14.2: the 500 answer is no-store');
        self::assertStringNotContainsString($pinnedWire, $body, '§6.3: the rejected Record 1 wire is not assembled into records transport');
    }

    /**
     * P1-1 (PR #31 review round 2, §7.2/§11.3/§11.4), through the real SQLite repository and
     * the public HTTP route: generations are intra-epoch sequences. The live prefix is Record 0
     * only, so the epoch accepted at the live T is epoch-0. A future-epoch (epoch-1) statement
     * stored with a tied generation — key id ECYh7yJFAn8 sorts AFTER the current epoch's key
     * ECMERdvpV0k in the global ascending order — must NOT preempt the epoch-0 statement, and
     * its signer key is not even part of the accepted chain. The statement route serves the
     * current-epoch wire verbatim with 200, never the epoch-1 wire.
     */
    public function testLiveFutureEpochStatementIsNotPreemptedBeforeTransferTakesEffect(): void
    {
        [$statementWire, $recordWire] = $this->seed();
        self::assertNotSame('', $statementWire, 'seed precondition: live epoch-0 statement stored');
        self::assertNotSame('', $recordWire, 'seed precondition: live Record 0 stored (epoch-0 is the current accepted epoch)');

        $l2 = new SqliteL2Repository($this->path);
        $futureEpochWire = Stage2TestSupport::wire('S9v1');
        $l2->appendStatement(StatementPayload::fromWire($futureEpochWire), $futureEpochWire);

        $base = $this->startServer();
        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2');

        self::assertSame(200, $status, 'P1-1/§11.4: before the epoch-1 transfer takes effect, the current epoch (epoch-0) statement is served over HTTP');
        self::assertSame($statementWire, $body, 'P1-1/§6.1: the served body is the epoch-0 wire verbatim, never the future-epoch wire');
        self::assertStringNotContainsString($futureEpochWire, $body, 'P1-1: the epoch-1 wire is excluded from the epoch-scoped selection at the storage boundary');
        self::assertSame(600, self::maxAgeOf((string) ($headers['cache-control'] ?? '')), '§14.2: live-stamped statement validity (T0+3600 − live T) clamps to the deployment bound 600');
    }

    /** §6.1 fail-closed: a stored statement that is not VERIFIED at T (EXPIRED at the live clock) is never served; 500 no-store. */
    public function testLiveNonVerifiedStatementIsNeverServed500(): void
    {
        $this->seed(withStatement: false);
        $l2 = new SqliteL2Repository($this->path);
        $pinnedStatementWire = Stage2TestSupport::wire('S5');
        $l2->appendStatement(StatementPayload::fromWire($pinnedStatementWire), $pinnedStatementWire);

        $base = $this->startServer();
        [$status, $headers, $body] = $this->request($base, '/relink/' . Stage2TestSupport::ANCHOR_ID . '?l=2');

        self::assertSame(500, $status, '§6.1 fail-closed: EXPIRED statement at the live clock is an internal failure, never public transport (§15.2 phase 10)');
        self::assertSame('no-store', $headers['cache-control'] ?? '', '§14.2: the 500 answer is no-store');
        self::assertStringNotContainsString($pinnedStatementWire, $body, '§6.1: a non-VERIFIED statement is never served publicly');
    }
}
