<?php

declare(strict_types=1);

use App\Services\GetProductsData;
use App\AllegroOAuth;
use App\Services\Rest\AllegroProductFinder;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

try {
    $service = new GetProductsData();
    $data = $service->getData();
    $products = $data['products'] ?? [];

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $productFinder = new AllegroProductFinder($config['api_url'], $accessToken);

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $ean = trim((string) ($product['ean'] ?? ''));
        $productName = (string) ($product['nazwa'] ?? 'bez nazwy');

        if ($ean === '') {
            echo sprintf("POMINIĘTO | %s | brak EAN\n", $productName);
            continue;
        }

        echo sprintf("Sprawdzam EAN %s | %s...\n", $ean, $productName);
        $matches = $productFinder->findByEan($ean);

        if ($matches === []) {
            echo sprintf("NIE ZNALEZIONO produktu w katalogu Allegro dla EAN %s.\n", $ean);
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


