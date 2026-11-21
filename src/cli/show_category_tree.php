<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/AllegroOAuth.php';

use App\AllegroOAuth;

function apiGet(string $url, string $token): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.allegro.public.v1+json',
            'Accept-Language: pl-PL',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException($error);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException(
            "HTTP {$httpCode}: {$response}"
        );
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Nieprawidłowy JSON.');
    }

    return $data;
}

/**
 * Pobiera dzieci kategorii.
 */
function getChildren(
    string $apiUrl,
    string $token,
    ?string $parentId = null
): array {
    $url = rtrim($apiUrl, '/') . '/sale/categories';

    if ($parentId !== null) {
        $url .= '?' . http_build_query([
            'parent.id' => $parentId,
        ]);
    }

    $data = apiGet($url, $token);

    return $data['categories'] ?? [];
}

/**
 * Wyświetla drzewo rekurencyjnie.
 */
function printTree(
    string $apiUrl,
    string $token,
    ?string $parentId = null,
    int $level = 0
): void {
    $categories = getChildren(
        $apiUrl,
        $token,
        $parentId
    );

    foreach ($categories as $category) {
        $indent = str_repeat('    ', $level);

        $leaf = !empty($category['leaf']);

        echo $indent
            . ($leaf ? '[LIŚĆ]' : '[+]')
            . ' '
            . $category['name']
            . ' | ID: '
            . $category['id']
            . PHP_EOL;

        if (!$leaf) {
            printTree(
                $apiUrl,
                $token,
                (string) $category['id'],
                $level + 1
            );
        }
    }
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Brak PHP cURL.');
    }

    echo "Pobieram token Allegro...\n";

    $oauth = new AllegroOAuth($config);
    $token = $oauth->getAccessToken();

    echo "Token OK.\n\n";
    echo "Pobieram drzewo kategorii...\n\n";

    printTree(
        $config['api_url'],
        $token
    );

    echo "\nSUKCES! Drzewo kategorii pobrane.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD: {$e->getMessage()}\n");
    exit(1);
}
