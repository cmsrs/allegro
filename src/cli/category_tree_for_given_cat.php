<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/AllegroApi.php';

use App\AllegroApi;

/**
 * Pobiera kategorie podrzędne wskazanej kategorii.
 *
 * @return array<int, array<string, mixed>>
 */
function getChildCategories(
    string $token,
    string $apiUrl,
    string $parentId
): array {
    $url = rtrim($apiUrl, '/')
        . '/sale/categories?'
        . http_build_query([
            'parent.id' => $parentId,
        ]);

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Nie można uruchomić cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.allegro.public.v1+json',
            'Accept-Language: pl-PL',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Błąd cURL: ' . $curlError);
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Nieprawidłowy JSON z Allegro.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            "Allegro HTTP {$httpCode}: "
            . json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }

    return $data['categories'] ?? [];
}

/**
 * Buduje całe drzewo kategorii rekurencyjnie.
 *
 * @return array<string, mixed>
 */
function buildCategoryTree(
    string $token,
    string $apiUrl,
    array $category
): array {
    $categoryId = (string) $category['id'];
    $isLeaf = (bool) ($category['leaf'] ?? false);

    $node = [
        'id' => $categoryId,
        'name' => $category['name'] ?? '',
        'leaf' => $isLeaf,
        'children' => [],
    ];

    // Jeśli kategoria końcowa, nie pobieramy już dzieci.
    if ($isLeaf) {
        return $node;
    }

    // Pobieramy dzieci aktualnej kategorii.
    $children = getChildCategories(
        $token,
        $apiUrl,
        $categoryId
    );

    foreach ($children as $child) {
        $node['children'][] = buildCategoryTree(
            $token,
            $apiUrl,
            $child
        );
    }

    return $node;
}

/**
 * Wyświetla drzewo kategorii w terminalu.
 */
function printCategoryTree(
    array $node,
    string $prefix = '',
    bool $isLast = true,
    bool $isRoot = true
): void {
    if ($isRoot) {
        echo $node['name'] . ' (ID: ' . $node['id'] . ')';
    } else {
        echo $prefix . ($isLast ? '└── ' : '├── ');
        echo $node['name'] . ' (ID: ' . $node['id'] . ')';
    }

    if (!empty($node['leaf'])) {
        echo ' [LEAF]';
    }

    echo "\n";

    $children = $node['children'] ?? [];
    $count = count($children);

    foreach ($children as $index => $child) {
        $last = ($index === $count - 1);

        $newPrefix = $isRoot
            ? ''
            : $prefix . ($isLast ? '    ' : '│   ');

        printCategoryTree(
            $child,
            $newPrefix,
            $last,
            false
        );
    }
}

/**
 * Zlicza wszystkie kategorie w drzewie.
 */
function countTreeCategories(array $node): int
{
    $count = 1;

    foreach ($node['children'] ?? [] as $child) {
        $count += countTreeCategories($child);
    }

    return $count;
}

/**
 * Zlicza kategorie końcowe (leaf).
 */
function countLeafCategories(array $node): int
{
    if (!empty($node['leaf'])) {
        return 1;
    }

    $count = 0;

    foreach ($node['children'] ?? [] as $child) {
        $count += countLeafCategories($child);
    }

    return $count;
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'Brak rozszerzenia PHP cURL.'
        );
    }

    // ID kategorii głównej Uroda.
    $rootCategoryId = '1429';
    $rootCategoryName = 'Uroda';

    echo "Pobieram token Allegro...\n";

    $allegro = new AllegroApi($config);
    $token = $allegro->getAccessToken();

    echo "Token pobrany poprawnie.\n\n";

    echo "Buduję drzewo kategorii: {$rootCategoryName}\n";
    echo "ID kategorii: {$rootCategoryId}\n\n";

    $rootCategory = [
        'id' => $rootCategoryId,
        'name' => $rootCategoryName,
        'leaf' => false,
    ];

    $tree = buildCategoryTree(
        $token,
        $config['api_url'],
        $rootCategory
    );

    echo "\n";
    echo str_repeat('=', 80) . "\n";
    printCategoryTree($tree);
    echo str_repeat('=', 80) . "\n";

    echo "\nPodsumowanie:\n";
    echo "Wszystkie kategorie: "
        . countTreeCategories($tree)
        . "\n";

    echo "Kategorie końcowe (leaf): "
        . countLeafCategories($tree)
        . "\n";

    echo "\nSUKCES! Drzewo kategorii pobrane.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "\nBŁĄD: " . $e->getMessage() . "\n"
    );

    exit(1);
}
