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

echo "\n======================================\n";
echo "Успішно: {$passed} | Провалено: {$failures}\n";

if ($failures > 0) {
    echo "Є провалені тести.\n";
    exit(1);
}

echo "Усі тести пройдено.\n";
exit(0);
