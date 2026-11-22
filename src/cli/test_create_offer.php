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


function getCategoryParameters(
    string $token,
    string $apiUrl,
    string $categoryId
): array {
    return allegroRequest(
        'GET',
        rtrim($apiUrl, '/') . '/sale/categories/' . $categoryId . '/parameters',
        $token
    );
}



function getProductParameters(
    string $token,
    string $apiUrl,
    string $categoryId
): array {
    return allegroRequest(
        'GET',
        rtrim($apiUrl, '/') . '/sale/categories/' . $categoryId . '/product-parameters',
        $token
    );
}


/**
 * Wyświetla dostępne wartości słownikowe dla wskazanych parametrów.
 */
function showDictionaryValues(
    string $token,
    string $apiUrl,
    string $categoryId
): void {
    $response = getCategoryParameters($token, $apiUrl, $categoryId);

    $wantedParameters = [
        '248811' => 'Marka',
        '250583' => 'Linia',
    ];

    echo "\n========================================\n";
    echo "DOSTĘPNE WARTOŚCI: MARKA I LINIA\n";
    echo "Kategoria: {$categoryId}\n";
    echo "========================================\n";

    foreach ($response['parameters'] ?? [] as $parameter) {
        $id = (string) ($parameter['id'] ?? '');

        if (!isset($wantedParameters[$id])) {
            continue;
        }

        echo "\n{$wantedParameters[$id]} (ID parametru: {$id})\n";
        echo str_repeat('-', 50) . "\n";

        $dictionary = $parameter['dictionary'] ?? [];

        if (!is_array($dictionary) || $dictionary === []) {
            echo "Brak wartości słownikowych w odpowiedzi API.\n";

            $ambiguousId = $parameter['options']['ambiguousValueId'] ?? null;
            if ($ambiguousId) {
                echo "Wartość niejednoznaczna: {$ambiguousId}\n";
            }

            continue;
        }

        foreach ($dictionary as $value) {
            if (!is_array($value)) {
                continue;
            }

            printf(
                "ID: %-25s Wartość: %s\n",
                $value['id'] ?? '?',
                $value['value'] ?? '?'
            );
        }

        $ambiguousId = $parameter['options']['ambiguousValueId'] ?? null;

        if ($ambiguousId) {
            echo "ID wartości niejednoznacznej: {$ambiguousId}\n";
        }
    }

    echo "\n========================================\n";
}


/**
 * Zgłasza nowy produkt do katalogu Allegro.
 *
 * UWAGA:
 * BRAND_VALUE_ID i LINE_VALUE_ID muszą być prawidłowymi
 * identyfikatorami wartości słownikowych z kategorii 17752.
 */
function createTestProduct(
    string $token,
    string $apiUrl,
    array $images,
    string $brandValueId,
    string $lineValueId
): array {

    $product = [
        'name' => 'Narcisa Rodriguen for her 120 ml EDP damskie',

        'category' => [
            'id' => '17752',
        ],

        'parameters' => [
            [
                'id' => '248811', // Marka
                'valuesIds' => [$brandValueId],
            ],
            [
                'id' => '250583', // Linia
                'valuesIds' => [$lineValueId],
            ],
            [
                'id' => '219893', // Pojemność opakowania
                'values' => ['100'],
            ],
            [
                'id' => '247944', // Rodzaj
                'valuesIds' => ['247944_838294'],
            ],
            [
                'id' => '225693', // EAN
                'values' => ['6291108524084'],
            ],
        ],

        // Endpoint product-proposals oczekuje obiektów {url: ...}
        'images' => array_map(
            static fn(string $url): array => ['url' => $url],
            $images
        ),

        'description' => [
            'sections' => [
                [
                    'items' => [
                        [
                            'type' => 'TEXT',
                            'content' => '<p>Testowy produkt Narcisa Rodriguen for her 120 ml EDP.</p>',
                        ],
                    ],
                ],
            ],
        ],
    ];

    echo "Zgłaszam produkt do katalogu Allegro...\n";

    return allegroRequest(
        'POST',
        rtrim($apiUrl, '/') . '/sale/product-proposals',
        $token,
        json_encode(
            $product,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
    );
}

/**
 * Wyszukuje produkt w katalogu Allegro po kodzie EAN/GTIN.
 *
 * @return array<string, mixed>
 */
function findProductByEan(
    string $token,
    string $apiUrl,
    string $ean
): array {
    $url = rtrim($apiUrl, '/') . '/sale/products?' . http_build_query([
        'phrase' => $ean,
        'mode' => 'GTIN',
        'language' => 'pl-PL',
    ]);

    return allegroRequest('GET', $url, $token);
}

/**
 * Wyświetla informację, czy produkt o podanym EAN istnieje w katalogu.
 */
function checkProductByEan(
    string $token,
    string $apiUrl,
    string $ean
): bool {
    echo "\nSprawdzam, czy EAN {$ean} istnieje w katalogu Allegro...\n";

    $response = findProductByEan($token, $apiUrl, $ean);
    $products = $response['products'] ?? [];

    if (!is_array($products) || count($products) === 0) {
        echo "NIE ZNALEZIONO produktu o EAN: {$ean}\n";
        return false;
    }

    echo "ZNALEZIONO produkt(y) o EAN: {$ean}\n";

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        printf(
            "  ID produktu: %s\n  Nazwa: %s\n",
            $product['id'] ?? 'brak',
            $product['name'] ?? 'brak'
        );
    }

    return true;
}


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

        // Allegro oczekuje listy URL-i (stringów)
        'images' => $images,

        'sellingMode' => [
            'format' => 'BUY_NOW',
            'price' => ['amount' => '19.99', 'currency' => 'PLN'],
        ],

        'stock' => ['available' => 1],

        'description' => [
            'sections' => [
                [
                    'items' => [
                        [
                            'type' => 'TEXT',
                            'content' => '<p>To jest przykładowa oferta utworzona w Allegro Sandbox.</p><p>Opis testowy produktu z dwoma obrazkami.</p>',
                        ],
                    ],
                ],
            ],
        ],

        'publication' => ['status' => $publish ? 'ACTIVE' : 'INACTIVE'],
    ];

    /*
    $offer = [
        'name' => 'Produkt testowy Allegro Sandbox',
        'category' => ['id' => $categoryId],
        //'images' => array_map(static fn (string $url): array => ['url' => $url], $images),
        'images' => $images,
        'sellingMode' => [
            'format' => 'BUY_NOW',
            'price' => ['amount' => '19.99', 'currency' => 'PLN'],
        ],
        'stock' => ['available' => 1],

'description' => [
    'sections' => [
        [
            'items' => [
                [
                    'type' => 'TEXT',
                    'content' => "To jest przykładowa oferta utworzona w Allegro Sandbox.\n\nOpis testowy produktu z dwoma obrazkami.",
                ],
            ],
        ],
    ],
],        


        'publication' => ['status' => $publish ? 'ACTIVE' : 'INACTIVE'],
    ];
     */

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

    $options = getopt('', [
        'publish',
        'category:',
        'brand-value:',
        'line-value:',
        'list-values',
    ]);
    $publish = array_key_exists('publish', $options);
    $categoryId = (string) ($options['category'] ?? $_ENV['ALLEGRO_TEST_CATEGORY_ID'] ?? DEFAULT_CATEGORY_ID);

    $imageFiles = [
        __DIR__ . '/../../data/zdjecia/test1.jpg',
        __DIR__ . '/../../data/zdjecia/test2.jpg',
    ];

    $oauth = new AllegroOAuth($config);
    $token = $oauth->getAccessToken();



    // $brandId = '248811_1981839';

    // foreach ($response['parameters'] ?? [] as $parameter) {
    //     if ((string)($parameter['id'] ?? '') !== '250583') {
    //         continue;
    //     }

    //     echo "\nLinie dostępne dla Narciso Rodriguez:\n";

    //     foreach ($parameter['dictionary'] ?? [] as $value) {
    //         if (in_array($brandId, $value['dependsOnValueIds'] ?? [], true)) {
    //             printf(
    //                 "%s => %s\n",
    //                 $value['id'] ?? '',
    //                 $value['value'] ?? ''
    //             );
    //         }
    //     }
    // }

    // die('_+___');





    // Wyświetlenie słownikowych wartości Marka i Linia.
    showDictionaryValues(
        $token,
        $config['api_url'],
        $categoryId
    );

    // Jeśli podano --list-values, kończymy bez tworzenia produktu/oferty.
    if (array_key_exists('list-values', $options)) {
        exit(0);
    }    

    echo "Tryb: " . ($publish ? "ACTIVE (publikacja)" : "INACTIVE (szkic)") . "\n";
    echo "Kategoria: {$categoryId}\n";

    $imageUrls = [];
    foreach ($imageFiles as $imageFile) {
        echo "Wysyłam obraz: {$imageFile}\n";
        $imageUrls[] = uploadImage($token, $config['upload_url'], $imageFile);
    }

    // Pobieramy parametry wymagane do utworzenia produktu.
    $productParameters = getProductParameters(
        $token,
        $config['api_url'],
        '17752'
    );

    echo "\nParametry produktu w kategorii 17752:\n";

    foreach ($productParameters['parameters'] ?? [] as $parameter) {
        printf(
            "%s | %s | wymagany dla produktu: %s\n",
            $parameter['id'] ?? '?',
            $parameter['name'] ?? '?',
            !empty($parameter['requiredForProduct']) ? 'TAK' : 'NIE'
        );
    }


    $shippingRateId = $publish
        ? getFirstShippingRateId($token, $config['api_url'])
        : null;


    $categoryParameters = getCategoryParameters(
        $token,
        $config['api_url'],
        $categoryId
    );


$brandId = '248811_1981839';
$foundLines = false;

echo "\n========================================\n";
echo "LINIE DOSTĘPNE DLA MARKI NARCISO RODRIGUEZ\n";
echo "========================================\n";

foreach ($categoryParameters['parameters'] ?? [] as $parameter) {
    if ((string)($parameter['id'] ?? '') !== '250583') {
        continue;
    }

    foreach ($parameter['dictionary'] ?? [] as $value) {
        $dependencies = array_map(
            'strval',
            $value['dependsOnValueIds'] ?? []
        );

        if (in_array($brandId, $dependencies, true)) {
            printf(
                "ID: %-25s Linia: %s\n",
                $value['id'] ?? '?',
                $value['value'] ?? '?'
            );

            $foundLines = true;
        }
    }
}

if (!$foundLines) {
    echo "Nie znaleziono linii z bezpośrednią zależnością od tej marki.\n";
    echo "Sprawdźmy strukturę odpowiedzi API.\n";

    foreach ($categoryParameters['parameters'] ?? [] as $parameter) {
        if ((string)($parameter['id'] ?? '') !== '250583') {
            continue;
        }

        foreach ($parameter['dictionary'] ?? [] as $value) {
            printf(
                "ID: %s | Nazwa: %s | Zależności: %s\n",
                $value['id'] ?? '?',
                $value['value'] ?? '?',
                json_encode(
                    $value['dependsOnValueIds'] ?? [],
                    JSON_UNESCAPED_UNICODE
                )
            );
        }
    }
}

echo "\nKoniec sprawdzania.\n";
exit(0);    

    echo "Parametry kategorii:\n";

    foreach ($categoryParameters['parameters'] ?? [] as $parameter) {
        if (!is_array($parameter)) {
            continue;
        }

        printf(
            "%s | %s | wymagany: %s | produkt: %s\n",
            $parameter['id'] ?? '?',
            $parameter['name'] ?? '?',
            !empty($parameter['required']) ? 'TAK' : 'NIE',
            !empty($parameter['options']['describesProduct']) ? 'TAK' : 'NIE'
        );
    }   
    
    $ean = '6291108524084';

    $productExists = checkProductByEan(
        $token,
        $config['api_url'],
        $ean
    );

        
    if ($productExists) {
        echo "\nProdukt już istnieje w katalogu Allegro.\n";
        echo "Nie tworzę kolejnego produktu.\n";
    } else {
        echo "\nProdukt nie istnieje. Przygotowuję zgłoszenie nowego produktu...\n";

        // Wartości słownikowe Allegro dla parametrów Marka i Linia.
        // Przekazujemy je jako argumenty CLI lub zmienne środowiskowe.
        $brandValueId = (string) (
            $options['brand-value']
            ?? $_ENV['ALLEGRO_TEST_BRAND_VALUE_ID']
            ?? ''
        );

        $lineValueId = (string) (
            $options['line-value']
            ?? $_ENV['ALLEGRO_TEST_LINE_VALUE_ID']
            ?? ''
        );

        if ($brandValueId === '' || $lineValueId === '') {
            throw new RuntimeException(
                "Brakuje wartości słownikowych Marka lub Linia.\n" .
                "Uruchom skrypt z parametrami:\n" .
                "--brand-value=ID_WARTOSCI_MARKI " .
                "--line-value=ID_WARTOSCI_LINII"
            );
        }

        echo "\nTworzę propozycję produktu w katalogu Allegro...\n";
        echo "Zdjęcia: test1.jpg oraz test2.jpg\n";

        $product = createTestProduct(
            $token,
            $config['api_url'],
            $imageUrls,
            $brandValueId,
            $lineValueId
        );

        echo "\nOdpowiedź Allegro:\n";
        echo json_encode(
            $product,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) . "\n";

        if (!empty($product['id'])) {
            echo "\nSUKCES! Zgłoszono produkt do katalogu Allegro.\n";
            echo "ID produktu: {$product['id']}\n";
        } else {
            echo "\nAllegro przyjęło odpowiedź, ale nie zwróciło ID produktu.\n";
        }
    }    


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
