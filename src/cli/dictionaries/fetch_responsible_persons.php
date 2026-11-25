<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroDictionaryEndpoint;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET /sale/responsible-persons\n";

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $dictionary = new AllegroDictionaryEndpoint($config['api_url'], $accessToken);

    $responsiblePersons = [];
    $offset = 0;
    $limit = 1000;
    do {
        $data = $dictionary->get(sprintf(
            '/sale/responsible-persons?offset=%d&limit=%d',
            $offset,
            $limit
        ));
        $page = $data['responsiblePersons'] ?? [];
        if (!is_array($page)) {
            throw new RuntimeException('Nieprawidłowa lista osób odpowiedzialnych w odpowiedzi Allegro.');
        }

        $responsiblePersons = array_merge($responsiblePersons, $page);
        $offset += count($page);
        $totalCount = (int) ($data['totalCount'] ?? $data['count'] ?? count($responsiblePersons));
    } while ($page !== [] && $offset < $totalCount);

    $outputPath = dirname(__DIR__, 3) . '/dane/osoby_odpowiedzialne_allegro.json';
    (new CategoryDetailsJsonWriter())->write($outputPath, [
        'generated_at' => date(DATE_ATOM),
        'count' => count($responsiblePersons),
        'responsiblePersons' => $responsiblePersons,
    ]);

    echo sprintf("Pobrano osób odpowiedzialnych: %d\n", count($responsiblePersons));
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
