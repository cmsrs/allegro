<?php

declare(strict_types=1);

namespace App\Services\Rest;

use RuntimeException;

class CategoryDetailsJsonWriter
{
    /**
     * Writes category data as formatted JSON, creating the parent directory if needed.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $filePath, array $data): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $directory);
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($filePath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać pliku: ' . $filePath);
        }
    }
}
