<?php

declare(strict_types=1);

use App\Services\GetProductsData;
use App\AllegroOAuth;
use App\Services\Rest\AllegroImageUploader;
use App\Services\Rest\AllegroProductFinder;
use App\Services\Rest\AllegroProductProposal;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;    
    $service = new GetProductsData();
    $data = $service->getData();
    $products = $data['products'] ?? [];

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $productFinder = new AllegroProductFinder($config['api_url'], $accessToken);
    $imageUploader = new AllegroImageUploader($config['upload_url'], $accessToken);
    $productProposal = new AllegroProductProposal($config['api_url'], $accessToken);
    $imagesDirectory = dirname(__DIR__, 2) . '/produkty';
    $catalogDirectory = dirname(__DIR__, 2) . '/dane';
    $imagesManifestPath = dirname(__DIR__, 2) . '/dane/allegro_uploaded_images.json';
    $uploadedImages = [];

    if (is_file($imagesManifestPath)) {
        $savedManifest = json_decode((string) file_get_contents($imagesManifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($savedManifest) || !is_array($savedManifest['images'] ?? null)) {
            throw new RuntimeException('Nieprawidłowy plik rejestru zdjęć: ' . $imagesManifestPath);
        }
        $uploadedImages = $savedManifest['images'];
    }

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        echo "\n";        

        $ean = trim((string) ($product['ean'] ?? ''));
        $productName = (string) ($product['nazwa'] ?? 'bez nazwy');

        if ($ean === '') {
            echo sprintf("POMINIĘTO | %s | brak EAN\n", $productName);
            continue;
        }

        echo sprintf("Sprawdzam EAN %s | %s...\n", $ean, $productName);
        echo "API: GET /sale/products?phrase=EAN&mode=GTIN	Wyszukiwanie produktu po EAN\n";
        $matches = $productFinder->findByEan($ean);

        if ($matches === []) {
            echo sprintf("NIE ZNALEZIONO produktu w katalogu Allegro dla EAN %s.\n", $ean);

            $nameWordCount = preg_match_all('/\S+/u', trim($productName));
            if ($nameWordCount === false || $nameWordCount < 3) {
                echo sprintf(
                    "POMINIĘTO | %s | nazwa produktu musi mieć co najmniej 3 wyrazy (obecnie: %d). Uzupełnij pole \"nazwa\" w produkty/dane.json.\n",
                    $productName,
                    max(0, $nameWordCount ?: 0)
                );
                continue;
            }

            $images = $product['zdjecia'] ?? [];
            if (!is_array($images) || $images === []) {
                echo "  Brak zdjęć do wysłania.\n";
                continue;
            }

            echo "API: POST /sale/images	Upload zdjęć";
            $imageLocations = [];
            foreach ($images as $image) {
                $imageName = is_array($image) ? trim((string) ($image['name'] ?? '')) : '';
                if ($imageName === '') {
                    echo "  Pomijam wpis zdjęcia bez nazwy.\n";
                    continue;
                }

                $reusableLocation = null;
                foreach (array_reverse($uploadedImages) as $savedImage) {
                    if (!is_array($savedImage)
                        || (string) ($savedImage['ean'] ?? '') !== $ean
                        || basename((string) ($savedImage['original_name'] ?? '')) !== basename($imageName)
                    ) {
                        continue;
                    }

                    $expiresAt = strtotime((string) ($savedImage['expires_at'] ?? ''));
                    $savedLocation = trim((string) ($savedImage['allegro_location'] ?? ''));
                    if ($expiresAt !== false && $expiresAt > time() && $savedLocation !== '') {
                        $reusableLocation = $savedLocation;
                        break;
                    }
                }

                if ($reusableLocation !== null) {
                    $imageLocations[] = $reusableLocation;
                    echo sprintf("  Używam wcześniej wysłanego, nadal ważnego zdjęcia: %s\n", $reusableLocation);
                    continue;
                }

                $imagePath = $imagesDirectory . '/' . basename($imageName);
                echo sprintf("  Wysyłam zdjęcie %s...\n", basename($imageName));
                $uploadedImage = $imageUploader->upload($imagePath);
                $imageLocations[] = $uploadedImage['location'];
                $uploadedImages[] = [
                    'ean' => $ean,
                    'product_name' => $productName,
                    'original_name' => $imageName,
                    'local_path' => 'produkty/' . basename($imageName),
                    'allegro_location' => $uploadedImage['location'],
                    'uploaded_at' => date(DATE_ATOM),
                    'expires_at' => $uploadedImage['expiresAt'],
                ];

                (new CategoryDetailsJsonWriter())->write($imagesManifestPath, [
                    'generated_at' => date(DATE_ATOM),
                    'count' => count($uploadedImages),
                    'images' => $uploadedImages,
                ]);

                echo sprintf("  Wysłano: %s\n", $uploadedImage['location']);
                echo sprintf("  Ważne do: %s\n", $uploadedImage['expiresAt'] ?? 'brak informacji');
                echo sprintf("  Zapisano rejestr: %s\n", $imagesManifestPath);
            }

            if ($imageLocations !== []) {
                echo "API: POST /sale/product-proposals\tPropozycja produktu\n";
                $proposalResult = $productProposal->proposeFromCatalog($product, $imageLocations, $catalogDirectory);
                echo "Odpowiedź POST /sale/product-proposals:\n";
                echo json_encode($proposalResult['proposal'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

                if ($proposalResult['product'] !== null) {
                    echo "Odpowiedź GET /sale/products/{$proposalResult['proposal']['id']}:\n";
                    echo json_encode($proposalResult['product'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                } else {
                    echo "Nie udało się od razu pobrać produktu przez GET: {$proposalResult['product_error']}\n";
                }
            }

            continue;
        }

        echo sprintf("ZNALEZIONO produkt w katalogu Allegro dla EAN %s:\n", $ean);
        foreach ($matches as $match) {
            echo sprintf(
                "  ID: %s | Nazwa: %s\n",
                $match['id'] ?? 'brak',
                $match['name'] ?? 'brak'
            );
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}


