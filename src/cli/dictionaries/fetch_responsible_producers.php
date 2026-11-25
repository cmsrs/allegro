<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroDictionaryEndpoint;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET /sale/responsible-producers\n";

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $dictionary = new AllegroDictionaryEndpoint($config['api_url'], $accessToken);

    $responsibleProducers = [];
    $offset = 0;
    $limit = 1000;
    do {
        $data = $dictionary->get(sprintf(
            '/sale/responsible-producers?offset=%d&limit=%d',
            $offset,
            $limit
        ));
        $page = $data['responsibleProducers'] ?? [];
        if (!is_array($page)) {
            throw new RuntimeException('Nieprawidłowa lista producentów odpowiedzialnych w odpowiedzi Allegro.');
        }

        $responsibleProducers = array_merge($responsibleProducers, $page);
        $offset += count($page);
        $totalCount = (int) ($data['totalCount'] ?? $data['count'] ?? count($responsibleProducers));
    } while ($page !== [] && $offset < $totalCount);

    $outputPath = dirname(__DIR__, 3) . '/dane/producenci_odpowiedzialni_allegro.json';
    (new CategoryDetailsJsonWriter())->write($outputPath, [
        'generated_at' => date(DATE_ATOM),
        'count' => count($responsibleProducers),
        'responsibleProducers' => $responsibleProducers,
    ]);

    echo sprintf("Pobrano producentów odpowiedzialnych: %d\n", count($responsibleProducers));
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
