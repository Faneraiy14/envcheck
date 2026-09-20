<?php

declare(strict_types=1);

namespace Tests;

use EnvCheck\EnvChecker;
use PHPUnit\Framework\TestCase;

final class EnvCheckerTest extends TestCase
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

    public function testEverythingMatches(): void
    {
        $env = $this->tempEnvFile("APP_NAME=Test\nDB_HOST=localhost\n");
        $example = $this->tempEnvFile("APP_NAME=\nDB_HOST=\n");
        $result = $this->checker->check($env, $example);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['empty']);
        $this->assertSame([], $result['extra']);
    }

    public function testMissingRequiredKeys(): void
    {
        $env = $this->tempEnvFile("APP_NAME=Test\n");
        $example = $this->tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");
        $result = $this->checker->check($env, $example);
        $this->assertCount(2, $result['missing']);
        $this->assertContains('DB_HOST', $result['missing']);
        $this->assertContains('DB_PORT', $result['missing']);
    }

    public function testEmptyValueForARequiredKey(): void
    {
        $env = $this->tempEnvFile("APP_NAME=Test\nAPI_KEY=\n");
        $example = $this->tempEnvFile("APP_NAME=\nAPI_KEY=\n");
        $result = $this->checker->check($env, $example);
        $this->assertContains('API_KEY', $result['empty']);
        $this->assertNotContains('APP_NAME', $result['empty']);
    }

    public function testExtraKeyNotInExampleIsInfoNotError(): void
    {
        $env = $this->tempEnvFile("APP_NAME=Test\nLOCAL_DEBUG_FLAG=1\n");
        $example = $this->tempEnvFile("APP_NAME=\n");
        $result = $this->checker->check($env, $example);
        $this->assertContains('LOCAL_DEBUG_FLAG', $result['extra']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['empty']);
    }

    public function testAppendMissingAddsKeysWithoutTouchingExistingContent(): void
    {
        $env = $this->tempEnvFile("APP_NAME=Test\n# коментар лишається на місці\n");
        $this->checker->appendMissing($env, ['DB_HOST', 'DB_PORT']);
        $afterFix = (string) file_get_contents($env);

        $this->assertStringContainsString('APP_NAME=Test', $afterFix);
        $this->assertStringContainsString('# коментар лишається на місці', $afterFix);

        $parsed = $this->checker->parse($env);
        $this->assertArrayHasKey('DB_HOST', $parsed);
        $this->assertArrayHasKey('DB_PORT', $parsed);
    }

    public function testAppendMissingWithAnEmptyListDoesNothing(): void
    {
        $file = $this->tempEnvFile("X=1\n");
        $before = file_get_contents($file);
        $this->checker->appendMissing($file, []);
        $after = file_get_contents($file);
        $this->assertSame($before, $after);
    }

    public function testParserHandlesCommentsExportQuotesAndBlankLines(): void
    {
        $env = $this->tempEnvFile(
            "# коментар\n\nexport APP_NAME=\"My App\"\nDB_HOST='localhost'\nDB_PORT=5432\n"
        );
        $parsed = $this->checker->parse($env);
        $this->assertSame('My App', $parsed['APP_NAME']);
        $this->assertSame('localhost', $parsed['DB_HOST']);
        $this->assertSame('5432', $parsed['DB_PORT']);
        $this->assertCount(3, $parsed);
    }

    public function testUtf8BomAtTheStartOfTheFileDoesNotLoseTheFirstKey(): void
    {
        $env = $this->tempEnvFile("\xEF\xBB\xBFAPP_NAME=Test\nAPI_KEY=secret\n");
        $example = $this->tempEnvFile("APP_NAME=\nAPI_KEY=\n");
        $result = $this->checker->check($env, $example);
        $this->assertNotContains('APP_NAME', $result['missing']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['empty']);
    }

    public function testMissingFileThrowsInsteadOfFailingSilently(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->checker->parse('/шлях/якого/не/існує/.env');
    }

    public function testInlineCommentAfterAnEmptyValueDoesNotBreakEmptyDetection(): void
    {
        $example = $this->tempEnvFile("API_KEY=\nDB_HOST=\n");
        $env = $this->tempEnvFile("API_KEY= # TODO: встав свій ключ сюди\nDB_HOST=localhost # так, тут не порожньо\n");
        $result = $this->checker->check($env, $example);
        $this->assertContains('API_KEY', $result['empty']);
        $this->assertNotContains('DB_HOST', $result['empty']);
    }

    public function testHashInsideQuotesDoesNotTruncateTheValue(): void
    {
        $env = $this->tempEnvFile('QUOTED="значення з # усередині лапок"' . "\n");
        $parsed = $this->checker->parse($env);
        $this->assertSame('значення з # усередині лапок', $parsed['QUOTED']);
    }
}
