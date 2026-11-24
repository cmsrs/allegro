<?php

declare(strict_types=1);

/**
 * Wyszukuje pasujące kategorie Allegro na podstawie nazwy produktu.
 *
 * Użycie:
 * php src/cli/find_category.php "sekator akumulatorowy"
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../src/AllegroOAuth.php';

use App\AllegroOAuth;

/**
 * Wykonuje zapytanie GET do Allegro API.
 *
 * @return array<string, mixed>
 */
function allegroGet(string $url, string $token): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Nie można uruchomić cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.allegro.public.v1+json',
            'User-Agent: allegro-category-search',
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

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Allegro zwróciło nieprawidłowy JSON.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            "Allegro HTTP {$httpCode}: "
            . json_encode(
                $decoded,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }

    return $decoded;
}

/**
 * Wyszukuje kategorie dopasowane do nazwy produktu.
 *
 * @return array<string, mixed>
 */
function findCategories(
    string $token,
    string $apiUrl,
    string $productName
): array {
    $url = rtrim($apiUrl, '/') . '/sale/matching-categories?'
        . http_build_query([
            'name' => $productName,
        ]);

    return allegroGet($url, $token);
}

/**
 * Wyświetla znalezione kategorie.
 */
function displayCategories(array $data): void
{
    $categories = $data['categories'] ?? [];

    if (!is_array($categories) || $categories === []) {
        echo "Nie znaleziono pasujących kategorii.\n";
        return;
    }

    echo "\nZnalezione kategorie:\n";
    echo str_repeat('=', 90) . "\n";

    foreach ($categories as $index => $category) {
        if (!is_array($category)) {
            continue;
        }

        $id = $category['id'] ?? 'brak ID';
        $name = $category['name'] ?? 'brak nazwy';
        $leaf = !empty($category['leaf']);

        echo sprintf(
            "%d. %s\n",
            $index + 1,
            $name
        );

        echo "   ID: {$id}\n";
        echo "   Kategoria końcowa (leaf): "
            . ($leaf ? 'TAK' : 'NIE')
            . "\n";

        if (isset($category['parent']['id'])) {
            echo "   ID kategorii nadrzędnej: "
                . $category['parent']['id']
                . "\n";
        }

        echo str_repeat('-', 90) . "\n";
    }

    echo "\nLiczba wyników: " . count($categories) . "\n";
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'Brak rozszerzenia PHP cURL. Zainstaluj php-curl.'
        );
    }

    // Pobranie nazwy produktu z argumentów CLI.
    $productName = trim(implode(' ', array_slice($argv, 1)));

    if ($productName === '') {
        echo "Użycie:\n";
        echo "php src/cli/find_category.php \"nazwa produktu\"\n\n";
        echo "Przykład:\n";
        echo "php src/cli/find_category.php \"sekator akumulatorowy\"\n";
        exit(1);
    }

    echo "Produkt: {$productName}\n";
    echo "Pobieram token Allegro...\n";

    $oauth = new AllegroOAuth($config);
    $token = $oauth->getAccessToken();

    echo "Token pobrany poprawnie.\n";
    echo "Szukam kategorii w Allegro...\n";

    $data = findCategories(
        $token,
        $config['api_url'],
        $productName
    );

    displayCategories($data);

    echo "\nSUKCES! Wyszukiwanie zakończone.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD: {$e->getMessage()}\n");
    exit(1);
}
