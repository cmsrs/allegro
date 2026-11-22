<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/AllegroOAuth.php';

use App\AllegroOAuth;

const CATEGORY_ID = '17752';
const PRODUCT_EAN = '6291108524084';

function requestApi(string $method, string $url, string $token, array|string|null $body = null, string $contentType = 'application/vnd.allegro.public.v1+json'): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Nie można uruchomić cURL.');
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.allegro.public.v1+json',
        'Accept-Language: pl-PL',
        'User-Agent: allegro-sandbox-narciso-draft',
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

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Błąd cURL: ' . $error);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = ['raw' => $raw];
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Allegro HTTP {$status}:\n" .
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return $data;
}

function uploadImage(string $token, string $uploadUrl, string $file): string
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Brak pliku lub brak prawa odczytu: ' . $file);
    }
    $mime = mime_content_type($file) ?: 'application/octet-stream';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException("Nieobsługiwany typ obrazu {$mime}: {$file}");
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Nie można odczytać pliku: ' . $file);
    }
    $response = requestApi('POST', rtrim($uploadUrl, '/') . '/sale/images', $token, $contents, $mime);
    if (empty($response['location']) || !is_string($response['location'])) {
        throw new RuntimeException('Allegro nie zwróciło adresu location dla zdjęcia.');
    }
    return $response['location'];
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Brak rozszerzenia PHP cURL (php-curl).');
    }

    $options = getopt('', ['brand-value:', 'line-value:']);
    $brandValueId = (string)($options['brand-value'] ?? '');
    $lineValueId = (string)($options['line-value'] ?? '');

    if (!preg_match('/^248811_.+$/', $brandValueId)) {
        throw new RuntimeException('Podaj ID wartości marki z parametru 248811: --brand-value=248811_ID');
    }
    if (!preg_match('/^250583_.+$/', $lineValueId)) {
        throw new RuntimeException('Podaj ID wartości linii z parametru 250583: --line-value=250583_ID');
    }

    $oauth = new AllegroOAuth($config);
    $token = $oauth->getAccessToken();

    $files = [
        __DIR__ . '/../../data/zdjecia/test1.jpg',
        __DIR__ . '/../../data/zdjecia/test2.jpg',
    ];

    echo "Wysyłam zdjęcia do Sandbox...\n";
    $images = [];
    foreach ($files as $file) {
        $images[] = uploadImage($token, $config['upload_url'], $file);
        echo "OK: {$file}\n";
    }

    $name = 'Narciso Rodriguez For Her EDP 120 ml';

    $payload = [
        'productSet' => [[
            'product' => [
                'name' => $name,
                'category' => ['id' => CATEGORY_ID],
                'parameters' => [
                    ['id' => '248811', 'valuesIds' => [$brandValueId]], // Marka
                    ['id' => '250583', 'valuesIds' => [$lineValueId]], // Linia
                    ['id' => '219893', 'values' => ['120']], // Pojemność w ml
                    ['id' => '247944', 'valuesIds' => ['247944_838294']], // Rodzaj
                    ['id' => '225693', 'values' => [PRODUCT_EAN]], // EAN
                ],
                'images' => $images,
            ],
            'quantity' => ['value' => 1],
        ]],
        'category' => ['id' => CATEGORY_ID],
        'name' => $name,
        'images' => $images,
        'description' => [
            'sections' => [[
                'items' => [[
                    'type' => 'TEXT',
                    'content' => '<p>Narciso Rodriguez For Her EDP 120 ml.</p>',
                ]],
            ]],
        ],
        'sellingMode' => [
            'format' => 'BUY_NOW',
            'price' => ['amount' => '19.99', 'currency' => 'PLN'],
        ],
        'stock' => ['available' => 1],
        'publication' => ['status' => 'INACTIVE'],
        'language' => 'pl-PL',
    ];

    echo "\nTworzę szkic oferty i przekazuję dane produktu w jednym żądaniu...\n";
    $result = requestApi(
        'POST',
        rtrim($config['api_url'], '/') . '/sale/product-offers',
        $token,
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    echo "\nOdpowiedź Allegro:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $id = (string)($result['id'] ?? '');
    if ($id !== '') {
        echo "\nUTWORZONO SZKIC (INACTIVE).\nID: {$id}\n";
        echo "Panel Sandbox: https://allegro.pl.allegrosandbox.pl/moje-allegro/sprzedaz/oferty\n";
        echo "Podgląd: https://allegro.pl.allegrosandbox.pl/oferta/{$id}\n";
    } else {
        echo "\nAPI przyjęło żądanie, ale nie zwróciło ID oferty. Sprawdź odpowiedź powyżej.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nBŁĄD:\n" . $e->getMessage() . "\n");
    exit(1);
}