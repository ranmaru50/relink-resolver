<?php
// tests/RestoreScriptTest.php
// 空のデータディレクトリへ復元した場合の所有者・モードを検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SqliteMigrator;

final class RestoreScriptTest extends TestCase
{
    private string $sourcePath = '';
    private string $targetDirectory = '';

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || trim((string) shell_exec('command -v sqlite3')) === '') {
            $this->markTestSkipped('POSIX環境とsqlite3 CLIが必要です。');
        }
        $source = tempnam(sys_get_temp_dir(), 'relink-restore-source-');
        $this->assertNotFalse($source);
        $this->sourcePath = $source;
        unlink($this->sourcePath);
        SqliteMigrator::migrate($this->sourcePath);

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'relink-restore-target-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0770, true));
        $this->targetDirectory = $directory;
    }

    protected function tearDown(): void
    {
        if ($this->sourcePath !== '') {
            @unlink($this->sourcePath);
        }
        if ($this->targetDirectory === '') {
            return;
        }
        $targetPath = $this->targetDirectory . DIRECTORY_SEPARATOR . 'resolver.sqlite';
        @unlink($targetPath);
        @unlink($targetPath . '-wal');
        @unlink($targetPath . '-shm');
        @rmdir($this->targetDirectory);
    }

    /** 空volumeへの復元後もwww-dataがDBとディレクトリへ書き込めることを確認する。 */
    public function testEmptyDataDirectoryRestoreUsesServiceOwnership(): void
    {
        $targetPath = $this->targetDirectory . DIRECTORY_SEPARATOR . 'resolver.sqlite';
        $serviceUid = fileowner($this->targetDirectory);
        $serviceGid = filegroup($this->targetDirectory);
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'restore.sh';
        $command = sprintf(
            'RELINK_DB_PATH=%s RELINK_SERVICE_UID=%d RELINK_SERVICE_GID=%d sh %s %s',
            escapeshellarg($targetPath),
            $serviceUid,
            $serviceGid,
            escapeshellarg($script),
            escapeshellarg($this->sourcePath),
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame($serviceUid, fileowner($targetPath));
        $this->assertSame(0660, fileperms($targetPath) & 0777);
        $this->assertSame($serviceUid, fileowner($this->targetDirectory));
        $this->assertSame(0770, fileperms($this->targetDirectory) & 0777);

        // 実行ユーザーとしてトランザクションとWAL/SHM作成まで確認する。
        $database = new PDO('sqlite:' . $targetPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('wal', strtolower((string) $database->query('PRAGMA journal_mode = WAL')->fetchColumn()));
        $database->beginTransaction();
        $database->exec("INSERT INTO resolver_records (anchor_uuid, state, description_location, entity_id, version, created_at, updated_at) VALUES ('550e8400-e29b-41d4-a716-446655440032', 'ACTIVE', 'https://entity.example/32.xml', 'urn:relink:entity:32', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $database->commit();
        $this->assertSame(1, (int) $database->query("SELECT COUNT(*) FROM resolver_records WHERE anchor_uuid = '550e8400-e29b-41d4-a716-446655440032'")->fetchColumn());
        $this->assertFileExists($targetPath . '-wal');
        $this->assertFileExists($targetPath . '-shm');
        $database = null;
    }

    /** schema v1 のバックアップも復元前に現行スキーマへ更新する。 */
    public function testRestoreMigratesVersionOneBackup(): void
    {
        $legacyPath = $this->targetDirectory . DIRECTORY_SEPARATOR . 'legacy.sqlite';
        $schema = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '001_initial.sql');
        $this->assertNotFalse($schema);
        $legacy = new PDO('sqlite:' . $legacyPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec('CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
        $legacy->exec($schema);
        $legacy->exec("INSERT INTO schema_migrations (version, applied_at) VALUES (1, CURRENT_TIMESTAMP)");
        $legacy = null;

        $targetPath = $this->targetDirectory . DIRECTORY_SEPARATOR . 'resolver.sqlite';
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'restore.sh';
        $command = sprintf('RELINK_DB_PATH=%s RELINK_SERVICE_UID=%d RELINK_SERVICE_GID=%d sh %s %s', escapeshellarg($targetPath), fileowner($this->targetDirectory), filegroup($this->targetDirectory), escapeshellarg($script), escapeshellarg($legacyPath));
        $this->assertSame(0, system($command, $exitCode) === false ? 1 : $exitCode);

        $database = new PDO('sqlite:' . $targetPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame(1, (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'admin_login_throttles'")->fetchColumn());
    }
}
