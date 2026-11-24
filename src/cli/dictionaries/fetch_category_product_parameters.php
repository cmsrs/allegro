<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroCategoryProductParameters;
use App\Services\Rest\CategoryDetailsJsonWriter;
use App\Services\Rest\CategoryDetailsJsonRead;

require_once dirname(__DIR__, 3) . '/config.php';

$categories = (new CategoryDetailsJsonRead)->getFirstFindingCategory(dirname(__DIR__, 3) . '/dane/kategorie_allegro.json');

try {
    echo "\n" . __FILE__ . PHP_EOL;    
    echo "API: GET /sale/categories/{id}/product-parameters". "\n";    
    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();

    $categoryParameters = new AllegroCategoryProductParameters($config['api_url'], $accessToken);
    $parametersById = $categoryParameters->getByCategoryIds(array_column($categories, 'id'));
    $parametersById = array_column($parametersById, 'parameters', 'id');

    foreach ($categories as $key => $category) {
        $categories[$key]['product_parameters'] = $parametersById[$category['id']] ?? [];
    }

    $outputDirectory = dirname(__DIR__, 3) . '/dane';
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $outputDirectory);
    }

    $outputPath = $outputDirectory . '/parametry_produktowe_kategorii_allegro.json';
    $output = [
        'generated_at' => date(DATE_ATOM),
        'count' => count($categories),
        'categories' => $categories,
    ];

    (new CategoryDetailsJsonWriter())->write($outputPath, $output);

    echo "Pobrane parametry produktowe:\n";
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
