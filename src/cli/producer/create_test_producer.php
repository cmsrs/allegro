<?php

declare(strict_types=1);

use App\AllegroOAuth;
use GuzzleHttp\Client;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/config.php';

const TEST_PRODUCER_NAME = 'TESTOWY producent Smatex';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET/POST /sale/responsible-producers\n";

    $apiUrl = rtrim((string) $config['api_url'], '/');
    if (!str_contains($apiUrl, 'allegrosandbox.pl')) {
        throw new RuntimeException('Ten skrypt może działać wyłącznie na Allegro Sandbox. Skonfigurowany host: ' . $apiUrl);
    }

    $accessToken = (new AllegroOAuth($config))->getAccessToken();
    $client = new Client(['timeout' => 30]);
    $headers = [
        'Authorization' => 'Bearer ' . $accessToken,
        'Accept' => 'application/vnd.allegro.public.v1+json',
        'Accept-Language' => 'pl-PL',
    ];

    $response = $client->request('GET', $apiUrl . '/sale/responsible-producers?limit=1000', [
        'headers' => $headers,
        'http_errors' => false,
    ]);
    $statusCode = $response->getStatusCode();
    $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($data)) {
        throw new RuntimeException(sprintf('GET /sale/responsible-producers zwrócił HTTP %d.', $statusCode));
    }

    foreach ($data['responsibleProducers'] ?? [] as $producer) {
        if (is_array($producer) && ($producer['name'] ?? null) === TEST_PRODUCER_NAME) {
            echo sprintf("Testowy producent już istnieje. ID: %s\n", $producer['id'] ?? 'brak');
            exit(0);
        }
    }

    $payload = [
        'name' => TEST_PRODUCER_NAME,
        'producerData' => [
            'tradeName' => 'SMAtex',
            'address' => [
                'countryCode' => 'PL',
                'street' => 'Testowa 1',
                'postalCode' => '00-001',
                'city' => 'Warszawa',
            ],
            'contact' => [
                'email' => 'producent@example.com',
                'phoneNumber' => '+48123123123',
                'formUrl' => 'https://example.com/kontakt',
            ],
        ],
    ];

    $response = $client->request('POST', $apiUrl . '/sale/responsible-producers', [
        'headers' => $headers + ['Content-Type' => 'application/vnd.allegro.public.v1+json'],
        'json' => $payload,
        'http_errors' => false,
    ]);
    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    $createdProducer = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($createdProducer)) {
        throw new RuntimeException(sprintf(
            'POST /sale/responsible-producers zwrócił HTTP %d: %s',
            $statusCode,
            $body
        ));
    }

    echo sprintf("Utworzono testowego producenta SMAtex. ID: %s\n", $createdProducer['id'] ?? 'brak');
    echo json_encode($createdProducer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
