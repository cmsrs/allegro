<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroCategoryLeafChecker;
use App\Services\Rest\CategoryDetailsJsonRead;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET /sale/categories/{categoryId}\n";

    $categories = (new CategoryDetailsJsonRead())->getFirstFindingCategory(
        dirname(__DIR__, 3) . '/dane/kategorie_allegro.json'
    );
    if ($categories === []) {
        throw new RuntimeException('Nie znaleziono kategorii do sprawdzenia w pliku kategorie_allegro.json.');
    }

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $checker = new AllegroCategoryLeafChecker($config['api_url'], $accessToken);
    $results = $checker->check($categories);

    $nonLeafCategories = array_values(array_filter(
        $results,
        static fn (array $category): bool => !$category['leaf']
    ));

    if ($nonLeafCategories === []) {
        echo "Wszystkie sprawdzone kategorie są liśćmi.\n";
    } else {
        echo "!!!!!!!!UWAGA: znaleziono kategorie, które nie są liśćmi:\n";
        foreach ($nonLeafCategories as $category) {
            echo sprintf("- ID %s | %s\n", $category['id'], $category['name']);
        }
    }

    echo sprintf(
        "Sprawdzono: %d | liście: %d | nie-liście: %d\n",
        count($results),
        count($results) - count($nonLeafCategories),
        count($nonLeafCategories)
    );

    if ($nonLeafCategories !== []) {
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
