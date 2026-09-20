<?php

declare(strict_types=1);

namespace Tests;

use EnvCheck\EnvChecker;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private EnvChecker $checker;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->checker = new EnvChecker();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    private function tempEnvFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'envcheck_');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCli(array $args): array
    {
        // shell_exec() + "echo $?" залежить від POSIX-шелу - на Windows
        // shell_exec йде через cmd.exe, де $? не існує. proc_open дає
        // реальний exit-код крос-платформно через proc_close().
        $command = array_merge([PHP_BINARY, __DIR__ . '/../bin/envcheck'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return [$exitCode, trim((string) $stdout)];
    }

    public function testJsonOutputReportsMissingKeysAndExitsNonZero(): void
    {
        $env = $this->tempEnvFile("APP_NAME=Test\nDB_HOST=localhost\n");
        $example = $this->tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");

        [$exitCode, $stdout] = $this->runCli([$env, $example, '--json']);
        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['ok']);
        $this->assertContains('DB_PORT', $decoded['missing']);
        $this->assertSame(1, $exitCode);
    }

    public function testExtraKeyOnlyFailsUnderStrictNotByDefault(): void
    {
        $envWithExtra = $this->tempEnvFile("APP_NAME=Test\nDB_HOST=localhost\nDB_PORT=5432\nEXTRA_KEY=1\n");
        $exampleNoExtra = $this->tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");

        [$exitCodeNoStrict] = $this->runCli([$envWithExtra, $exampleNoExtra]);
        $this->assertSame(0, $exitCodeNoStrict, 'an extra key alone should not fail the check without --strict');

        [$exitCodeStrict] = $this->runCli([$envWithExtra, $exampleNoExtra, '--strict']);
        $this->assertSame(1, $exitCodeStrict, 'the same extra key should fail the check with --strict');
    }

    public function testFixFlagAppendsMissingKeysThroughTheRealCliProcess(): void
    {
        $envForFix = $this->tempEnvFile("APP_NAME=Test\n");
        $example = $this->tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");

        $this->runCli([$envForFix, $example, '--fix']);

        $afterFixParsed = $this->checker->parse($envForFix);
        $this->assertArrayHasKey('DB_HOST', $afterFixParsed);
        $this->assertArrayHasKey('DB_PORT', $afterFixParsed);
    }
}
