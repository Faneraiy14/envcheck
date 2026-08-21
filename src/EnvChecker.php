<?php

declare(strict_types=1);

namespace EnvCheck;

final class EnvChecker
{
    /**
     * @return array{missing: string[], extra: string[], empty: string[]}
     */
    public function check(string $envPath, string $examplePath): array
    {
        $env = $this->parse($envPath);
        $example = $this->parse($examplePath);

        $envKeys = array_keys($env);
        $exampleKeys = array_keys($example);

        $missing = array_values(array_diff($exampleKeys, $envKeys));
        $extra = array_values(array_diff($envKeys, $exampleKeys));

        $empty = [];
        foreach ($env as $key => $value) {
            if (array_key_exists($key, $example) && $value === '') {
                $empty[] = $key;
            }
        }

        sort($missing);
        sort($extra);
        sort($empty);

        return [
            'missing' => $missing,
            'extra' => $extra,
            'empty' => $empty,
        ];
    }

    /**
     * Дописує відсутні ключі в кінець .env як `KEY=` (порожнє значення —
     * значення все одно ніхто, крім людини, вгадати не може). Існуючий
     * вміст файлу не чіпає: лише додає рядки в кінець.
     *
     * @param string[] $missingKeys
     */
    public function appendMissing(string $envPath, array $missingKeys): void
    {
        if ($missingKeys === []) {
            return;
        }

        $current = is_file($envPath) ? file_get_contents($envPath) : '';
        $current = $current === false ? '' : $current;

        $needsLeadingNewline = $current !== '' && !str_ends_with($current, "\n");
        $addition = ($needsLeadingNewline ? "\n" : '') . implode("\n", array_map(
            static fn (string $key): string => "{$key}=",
            $missingKeys
        )) . "\n";

        file_put_contents($envPath, $current . $addition);
    }

    /**
     * Мінімальний .env-парсер: KEY=value на рядок, підтримує коментарі
     * (#...), порожні рядки, значення в лапках і export-префікс.
     * Не намагається бути повноцінним — цього достатньо для перевірки
     * НАБОРУ ключів, а не для реального завантаження середовища.
     *
     * @return array<string, string>
     */
    public function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Файл не знайдено: {$path}");
        }

        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        // Файли, збережені деякими Windows-редакторами (напр. Notepad),
        // починаються з UTF-8 BOM (\xEF\xBB\xBF). Без цього перший рядок
        // мав би вигляд "\xEF\xBB\xBFKEY=value" - ключ не проходив би
        // regex нижче й мовчки губився, показуючись як хибно "відсутній".
        if (isset($lines[0])) {
            $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = trim(substr($trimmed, 7));
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $value = trim(substr($trimmed, $eqPos + 1));
            $value = $this->extractValue($value);

            $result[$key] = $value;
        }

        return $result;
    }

    // Раніше просто trim()-ив усе після "=" - "API_KEY= # TODO: встав
    // ключ" (типовий реальний патерн: порожнє значення з поясненням у
    // коментарі поруч) розбирався як значення "# TODO: встав ключ", а не
    // порожній рядок. check() тоді НЕ позначав такий ключ як "empty" -
    // саме та ситуація, яку весь інструмент і покликаний ловити.
    //
    // У лапках "#" - звичайний символ значення (не коментар), тому
    // спершу перевіряємо лапки, і лише для НЕзакавиченого значення
    // шукаємо "#" як початок коментаря.
    private function extractValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quoteChar = $raw[0];
        if ($quoteChar === '"' || $quoteChar === "'") {
            $closingPos = strpos($raw, $quoteChar, 1);
            if ($closingPos !== false) {
                return substr($raw, 1, $closingPos - 1);
            }
            // Незакрита лапка - навмисно не вгадуємо намір, лишаємо як є.
            return $raw;
        }

        $hashPos = strpos($raw, '#');
        if ($hashPos !== false) {
            $raw = substr($raw, 0, $hashPos);
        }
        return trim($raw);
    }
}
