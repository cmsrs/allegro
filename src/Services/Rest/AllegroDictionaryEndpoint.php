<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroDictionaryEndpoint
{
    private ClientInterface $client;

    public function __construct(
        private string $apiUrl,
        private string $accessToken,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client(['timeout' => 30]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->client->request(
                'GET',
                rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/'),
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Accept' => 'application/vnd.allegro.public.v1+json',
                        'Accept-Language' => 'pl-PL',
                    ],
                ]
            );

            $data = json_decode(
                $response->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (GuzzleException | JsonException $e) {
            throw new RuntimeException(
                sprintf('Nie udało się pobrać danych z GET /%s: %s', ltrim($endpoint, '/'), $e->getMessage()),
                0,
                $e
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź Allegro dla GET /%s.', ltrim($endpoint, '/')));
        }

        return $data;
    }
}
