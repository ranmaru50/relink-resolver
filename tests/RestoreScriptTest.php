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
        $serviceUid = function_exists('posix_getpwnam') ? posix_getpwnam('www-data')['uid'] : 33;
        $serviceGid = function_exists('posix_getpwnam') ? posix_getpwnam('www-data')['gid'] : 33;
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
    }
}
