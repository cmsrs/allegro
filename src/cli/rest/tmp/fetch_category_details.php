<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroCategoryProductParameters;
use App\Services\Rest\CategoryDetailsJsonWriter;
use App\Services\Rest\CategoryDetailsJsonRead;

require_once dirname(__DIR__, 4) . '/config.php';

$categories = (new CategoryDetailsJsonRead)->getFirstFindingCategory(dirname(__DIR__, 4) . '/dane/kategorie_allegro.json');

try {
    echo "Pobieram token Allegro...\n";
    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();

    $categoryParameters = new AllegroCategoryProductParameters($config['api_url'], $accessToken);
    $parametersById = $categoryParameters->getByCategoryIds(array_column($categories, 'id'));
    $parametersById = array_column($parametersById, 'parameters', 'id');

    foreach ($categories as &$category) {
        $category['product_parameters'] = $parametersById[$category['id']] ?? [];
    }
    unset($category);

    $outputDirectory = dirname(__DIR__, 2) . '/dane';
    $outputPath = $outputDirectory . '/parametry_produktowe_kategorii_allegro.json';
    $output = [
        'generated_at' => date(DATE_ATOM),
        'count' => count($categories),
        'categories' => $categories,
    ];

    (new CategoryDetailsJsonWriter())->write($outputPath, $output);

    echo "\nPobrane parametry produktowe:\n";
    foreach ($categories as $category) {
        echo sprintf(
            "ID %s | %s | parametrów: %d\n",
            $category['id'],
            $category['name'],
            count($category['product_parameters'])
        );
    }
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
