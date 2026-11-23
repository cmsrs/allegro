<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroCategoryProductParameters;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 2) . '/config.php';

$categories = require dirname(__DIR__, 2) . '/config/allegro_categories.php';

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

    $outputDirectory = dirname(__DIR__, 2) . '/data/dane';
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
