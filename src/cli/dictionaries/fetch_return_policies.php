<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroDictionaryEndpoint;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET /after-sales-service-conditions/return-policies\n";

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $dictionary = new AllegroDictionaryEndpoint($config['api_url'], $accessToken);
    $data = $dictionary->get('/after-sales-service-conditions/return-policies');

    $outputPath = dirname(__DIR__, 3) . '/dane/warunki_zwrotow_allegro.json';
    $returnPolicies = $data['returnPolicies'] ?? [];
    (new CategoryDetailsJsonWriter())->write($outputPath, [
        'generated_at' => date(DATE_ATOM),
        'count' => count($returnPolicies),
        'returnPolicies' => $returnPolicies,
    ]);

    echo sprintf("Pobrano warunków zwrotu: %d\n", count($returnPolicies));
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
