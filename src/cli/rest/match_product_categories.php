<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\GetProductsData;
use App\Services\Rest\AllegroCategoryMatcher;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    $productsData = new GetProductsData();
    $categoryNames = $productsData->getUniqueCategories();

    if ($categoryNames === []) {
        throw new RuntimeException('W pliku dane.json nie znaleziono kategorii produktów.');
    }

    echo sprintf("Kategorie do dopasowania: %d\n", count($categoryNames));
    echo "Pobieram token Allegro...\n";

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();

    $matcher = new AllegroCategoryMatcher($config['api_url'], $accessToken);

    echo "API: GET /sale/matching-categories". "\n";
    $results = $matcher->matchCategories($categoryNames);

    $outputDirectory = dirname(__DIR__, 3) . '/dane';
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $outputDirectory);
    }

    $outputPath = $outputDirectory . '/kategorie_allegro.json';
    $json = json_encode(
        [
            'generated_at' => date(DATE_ATOM),
            'source' => 'produkty/dane.json',
            'count' => count($results),
            'categories' => $results,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($outputPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Nie udało się zapisać pliku: ' . $outputPath);
    }

    echo sprintf("Zapisano dopasowania dla %d kategorii do %s\n", count($results), $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
