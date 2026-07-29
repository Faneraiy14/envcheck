<?php

declare(strict_types=1);

require __DIR__ . '/../src/EnvChecker.php';

use EnvCheck\EnvChecker;

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        echo "  ✅ {$label}\n";
        $passed++;
    } else {
        echo "  ❌ {$label}\n";
        $failures++;
    }
}

function tempEnvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'envcheck_');
    file_put_contents($path, $contents);
    return $path;
}

$checker = new EnvChecker();

// --- Тест 1: усе збігається ---
echo "1. .env повністю відповідає .env.example\n";
$env = tempEnvFile("APP_NAME=Test\nDB_HOST=localhost\n");
$example = tempEnvFile("APP_NAME=\nDB_HOST=\n");
$result = $checker->check($env, $example);
check('немає відсутніх', $result['missing'] === []);
check('немає порожніх', $result['empty'] === []);
check('немає зайвих', $result['extra'] === []);
unlink($env);
unlink($example);

// --- Тест 2: відсутній ключ ---
echo "2. Відсутній обов'язковий ключ\n";
$env = tempEnvFile("APP_NAME=Test\n");
$example = tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");
$result = $checker->check($env, $example);
check('знайдено 2 відсутніх', count($result['missing']) === 2);
check('DB_HOST у списку відсутніх', in_array('DB_HOST', $result['missing'], true));
check('DB_PORT у списку відсутніх', in_array('DB_PORT', $result['missing'], true));
unlink($env);
unlink($example);

// --- Тест 3: порожнє значення обов'язкового ключа ---
echo "3. Ключ є, але значення порожнє\n";
$env = tempEnvFile("APP_NAME=Test\nAPI_KEY=\n");
$example = tempEnvFile("APP_NAME=\nAPI_KEY=\n");
$result = $checker->check($env, $example);
check('API_KEY позначено як порожній', in_array('API_KEY', $result['empty'], true));
check('APP_NAME НЕ позначено (не порожній)', !in_array('APP_NAME', $result['empty'], true));
unlink($env);
unlink($example);

// --- Тест 4: зайвий ключ — це інфо, не помилка ---
echo "4. Зайвий ключ не в прикладі\n";
$env = tempEnvFile("APP_NAME=Test\nLOCAL_DEBUG_FLAG=1\n");
$example = tempEnvFile("APP_NAME=\n");
$result = $checker->check($env, $example);
check('LOCAL_DEBUG_FLAG у списку зайвих', in_array('LOCAL_DEBUG_FLAG', $result['extra'], true));
check('немає відсутніх/порожніх', $result['missing'] === [] && $result['empty'] === []);
unlink($env);
unlink($example);

// --- Тест 4б: appendMissing() дописує без псування наявного вмісту ---
echo "4б. appendMissing() дописує відсутні ключі в кінець файлу\n";
$env = tempEnvFile("APP_NAME=Test\n# коментар лишається на місці\n");
$checker->appendMissing($env, ['DB_HOST', 'DB_PORT']);
$afterFix = file_get_contents($env);
check('APP_NAME не зачеплено', str_contains($afterFix, 'APP_NAME=Test'));
check('коментар не зачеплено', str_contains($afterFix, '# коментар лишається на місці'));
check('DB_HOST дописано порожнім', str_contains($afterFix, 'DB_HOST=' . "\n") || str_ends_with(trim($afterFix), 'DB_PORT='));
$parsedAfterFix = $checker->parse($env);
check('DB_HOST тепер читається парсером', array_key_exists('DB_HOST', $parsedAfterFix));
check('DB_PORT тепер читається парсером', array_key_exists('DB_PORT', $parsedAfterFix));
check('appendMissing з порожнім списком нічого не робить', (function () use ($checker): bool {
    $f = tempEnvFile("X=1\n");
    $before = file_get_contents($f);
    $checker->appendMissing($f, []);
    $after = file_get_contents($f);
    unlink($f);
    return $before === $after;
})());
unlink($env);

// --- Тест 5: коментарі, порожні рядки, export, лапки ---
echo "5. Парсер: коментарі, export, лапки, порожні рядки\n";
$env = tempEnvFile(
    "# коментар\n\nexport APP_NAME=\"My App\"\nDB_HOST='localhost'\nDB_PORT=5432\n"
);
$parsed = $checker->parse($env);
check('APP_NAME без лапок', $parsed['APP_NAME'] === 'My App');
check('DB_HOST без лапок', $parsed['DB_HOST'] === 'localhost');
check('DB_PORT як рядок', $parsed['DB_PORT'] === '5432');
check('рівно 3 ключі (коментар і порожній рядок пропущені)', count($parsed) === 3);
unlink($env);

// --- Тест 6: неіснуючий файл кидає виняток ---
echo "6. Неіснуючий файл — RuntimeException, не тихий збій\n";
$threw = false;
try {
    $checker->parse('/шлях/якого/не/існує/.env');
} catch (\RuntimeException) {
    $threw = true;
}
check('кинуто RuntimeException', $threw);

// --- Тест 7: CLI (bin/envcheck) як окремий процес — --json/--strict/--fix ---
echo "7. CLI: --json/--strict/--fix через реальний виклик процесу\n";

function runCli(array $args): array
{
    // shell_exec() + "echo $?" залежить від POSIX-шелу — на Windows
    // shell_exec йде через cmd.exe, де $? не існує. proc_open дає
    // реальний exit-код крос-платформно через proc_close().
    $command = array_merge([PHP_BINARY, __DIR__ . '/../bin/envcheck'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [$exitCode, trim((string) $stdout)];
}

$env = tempEnvFile("APP_NAME=Test\nDB_HOST=localhost\n");
$example = tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");

[$exitCode, $stdout] = runCli([$env, $example, '--json']);
$decoded = json_decode($stdout, true);
check('--json: валідний JSON', $decoded !== null);
check('--json: ok=false, коли є відсутні', $decoded !== null && $decoded['ok'] === false);
check('--json: DB_PORT у missing', $decoded !== null && in_array('DB_PORT', $decoded['missing'], true));
check('--json: exit-код 1 за наявності проблем', $exitCode === 1);

[$exitCode2] = runCli([$env, $example]);
check('без --strict: зайвих ключів тут немає, exit не залежить від --strict у цьому кейсі', $exitCode2 === 1);

$envWithExtra = tempEnvFile("APP_NAME=Test\nDB_HOST=localhost\nDB_PORT=5432\nEXTRA_KEY=1\n");
$exampleNoExtra = tempEnvFile("APP_NAME=\nDB_HOST=\nDB_PORT=\n");
[$exitCodeNoStrict] = runCli([$envWithExtra, $exampleNoExtra]);
check('без --strict: тільки зайвий ключ НЕ провалює перевірку', $exitCodeNoStrict === 0);
[$exitCodeStrict] = runCli([$envWithExtra, $exampleNoExtra, '--strict']);
check('--strict: той самий зайвий ключ ПРОВАЛює перевірку', $exitCodeStrict === 1);
unlink($envWithExtra);
unlink($exampleNoExtra);

$envForFix = tempEnvFile("APP_NAME=Test\n");
runCli([$envForFix, $example, '--fix']);
$afterFixParsed = $checker->parse($envForFix);
check('--fix через CLI: DB_HOST дописано', array_key_exists('DB_HOST', $afterFixParsed));
check('--fix через CLI: DB_PORT дописано', array_key_exists('DB_PORT', $afterFixParsed));
unlink($envForFix);

unlink($env);
unlink($example);

echo "\n======================================\n";
echo "Успішно: {$passed} | Провалено: {$failures}\n";

if ($failures > 0) {
    echo "Є провалені тести.\n";
    exit(1);
}

echo "Усі тести пройдено.\n";
exit(0);
