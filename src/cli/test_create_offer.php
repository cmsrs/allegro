<?php

declare(strict_types=1);

/**
 * Tworzy przykładową ofertę w Allegro Sandbox.
 *
 * Domyślnie oferta jest zapisana jako szkic (INACTIVE). Aby utworzyć
 * aktywną ofertę, uruchom: php src/cli/test_create_offer.php --publish --category=ID
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/AllegroOAuth.php';

use App\AllegroOAuth;

const DEFAULT_CATEGORY_ID = '42578';

/** @return array<string, mixed> */
function allegroRequest(
    string $method,
    string $url,
    string $token,
    array|string|null $body = null,
    string $contentType = 'application/vnd.allegro.public.v1+json'
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Nie można uruchomić cURL.');
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.allegro.public.v1+json',
        'User-Agent: allegro-sandbox-test-script',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
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
        $decoded = ['raw' => $response];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        throw new RuntimeException("Allegro HTTP {$httpCode}: {$message}");
    }

    return $decoded;
}

function uploadImage(string $token, string $uploadUrl, string $file): string
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Brak pliku albo brak prawa odczytu: ' . $file);
    }

    $mimeType = mime_content_type($file) ?: 'application/octet-stream';
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException("Nieobsługiwany typ obrazu {$mimeType}: {$file}");
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Nie można odczytać pliku: ' . $file);
    }

    $response = allegroRequest(
        'POST',
        rtrim($uploadUrl, '/') . '/sale/images',
        $token,
        $contents,
        $mimeType
    );

    if (empty($response['location']) || !is_string($response['location'])) {
        throw new RuntimeException('Upload obrazu nie zwrócił pola location.');
    }

    return $response['location'];
}

/** @return array<string, mixed> */
function createOffer(
    string $token,
    string $apiUrl,
    string $categoryId,
    array $images,
    bool $publish,
    ?string $shippingRateId
): array {
    $offer = [
        'name' => 'Produkt testowy Allegro Sandbox',
        'category' => ['id' => $categoryId],
        'images' => array_map(static fn (string $url): array => ['url' => $url], $images),
        'sellingMode' => [
            'format' => 'BUY_NOW',
            'price' => ['amount' => '19.99', 'currency' => 'PLN'],
        ],
        'stock' => ['available' => 1],
        'description' => [[
            'section' => ['items' => [[
                'type' => 'TEXT',
                'content' => "To jest przykładowa oferta utworzona w Allegro Sandbox.\n\nOpis testowy produktu z dwoma obrazkami.",
            ]]],
        ]],
        'publication' => ['status' => $publish ? 'ACTIVE' : 'INACTIVE'],
    ];

    if ($publish) {
        if ($shippingRateId === null) {
            throw new RuntimeException(
                'Nie znaleziono stawki wysyłki Sandbox. Utwórz ją w panelu Allegro lub użyj trybu szkicu.'
            );
        }

        $offer['payments'] = ['invoice' => 'NO_INVOICE'];
        $offer['delivery'] = [
            'shippingRates' => ['id' => $shippingRateId],
            'handlingTime' => 'PT24H',
        ];
        $offer['location'] = [
            'city' => 'Warszawa',
            'countryCode' => 'PL',
            'postCode' => '00-001',
            'province' => 'MAZOWIECKIE',
        ];
    }

    return allegroRequest(
        'POST',
        rtrim($apiUrl, '/') . '/sale/product-offers',
        $token,
        json_encode($offer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function getFirstShippingRateId(string $token, string $apiUrl): ?string
{
    $response = allegroRequest(
        'GET',
        rtrim($apiUrl, '/') . '/sale/shipping-rates',
        $token
    );

    $shippingRates = $response['shippingRates'] ?? [];
    if (!is_array($shippingRates) || $shippingRates === []) {
        return null;
    }

    $firstRate = reset($shippingRates);
    return is_array($firstRate) && isset($firstRate['id'])
        ? (string) $firstRate['id']
        : null;
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Brak rozszerzenia PHP cURL. Zainstaluj php-curl.');
    }

    $options = getopt('', ['publish', 'category:']);
    $publish = array_key_exists('publish', $options);
    $categoryId = (string) ($options['category'] ?? $_ENV['ALLEGRO_TEST_CATEGORY_ID'] ?? DEFAULT_CATEGORY_ID);

    $imageFiles = [
        __DIR__ . '/../../data/zdjecia/test1.jpg',
        __DIR__ . '/../../data/zdjecia/test2.jpg',
    ];

    $oauth = new AllegroOAuth($config);
    $token = $oauth->getAccessToken();
    echo "Tryb: " . ($publish ? "ACTIVE (publikacja)" : "INACTIVE (szkic)") . "\n";
    echo "Kategoria: {$categoryId}\n";

    $imageUrls = [];
    foreach ($imageFiles as $imageFile) {
        echo "Wysyłam obraz: {$imageFile}\n";
        $imageUrls[] = uploadImage($token, $config['upload_url'], $imageFile);
    }

    $shippingRateId = $publish
        ? getFirstShippingRateId($token, $config['api_url'])
        : null;

    echo "Tworzę ofertę...\n";
    $offer = createOffer(
        $token,
        $config['api_url'],
        $categoryId,
        $imageUrls,
        $publish,
        $shippingRateId
    );
    $offerId = (string) ($offer['id'] ?? 'brak');
    echo "SUKCES! Utworzono ofertę {$offerId}.\n";
    echo "Panel Sandbox: https://allegro.pl.allegrosandbox.pl/moje-allegro/sprzedaz/oferty\n";
    if ($publish && $offerId !== 'brak') {
        echo "Oferta: https://allegro.pl.allegrosandbox.pl/oferta/{$offerId}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD: {$e->getMessage()}\n");
    exit(1);
}
