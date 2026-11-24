<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroCategoryParameters;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 3) . '/config.php';

//$categories = require dirname(__DIR__, 3) . '/dane/allegro_categories.php';

$outputPath = dirname(__DIR__, 3) . '/dane/kategorie_allegro.json';


die('_______________________');

try {
    echo "Pobieram token Allegro...\n";
    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();

    $categoryParameters = new AllegroCategoryParameters($config['api_url'], $accessToken);
    $parametersById = $categoryParameters->getByCategoryIds(array_column($categories, 'id'));
    $parametersById = array_column($parametersById, 'parameters', 'id');

    foreach ($categories as &$category) {
        $category['parameters'] = $parametersById[$category['id']] ?? [];
    }
    unset($category);

    $outputPath = dirname(__DIR__, 3) . '/dane/parametry_kategorii_allegro.json';
    $output = [
        'generated_at' => date(DATE_ATOM),
        'count' => count($categories),
        'categories' => $categories,
    ];

    (new CategoryDetailsJsonWriter())->write($outputPath, $output);

    echo "\nPobrane parametry kategorii/ofert:\n";
    foreach ($categories as $category) {
        echo sprintf(
            "ID %s | %s | parametrów: %d\n",
            $category['id'],
            $category['name'],
            count($category['parameters'])
        );
    }
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
