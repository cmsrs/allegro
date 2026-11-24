<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroCategoryLeafChecker
{
    private ClientInterface $client;

    public function __construct(
        private string $apiUrl,
        private string $accessToken,
        ?ClientInterface $client = null,
        private int $delayMicroseconds = 250000
    ) {
        $this->client = $client ?? new Client(['timeout' => 30]);
    }

    /**
     * @param list<array{id: string|int, name?: string}> $categories
     * @return list<array{id: string, name: string, leaf: bool}>
     */
    public function check(array $categories): array
    {
        $results = [];
        $requestMade = false;

        foreach ($categories as $category) {
            $categoryId = trim((string) ($category['id'] ?? ''));
            if ($categoryId === '') {
                throw new RuntimeException('Jedna ze znalezionych kategorii nie ma ID.');
            }

            if ($requestMade && $this->delayMicroseconds > 0) {
                usleep($this->delayMicroseconds);
            }

            try {
                $response = $this->client->request(
                    'GET',
                    rtrim($this->apiUrl, '/') . '/sale/categories/' . rawurlencode($categoryId),
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
                    sprintf('Nie udało się sprawdzić kategorii %s: %s', $categoryId, $e->getMessage()),
                    0,
                    $e
                );
            }

            if (!is_array($data) || !is_bool($data['leaf'] ?? null)) {
                throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź Allegro dla kategorii %s.', $categoryId));
            }

            $results[] = [
                'id' => $categoryId,
                'name' => (string) ($category['name'] ?? $data['name'] ?? ''),
                'leaf' => $data['leaf'],
            ];
            $requestMade = true;
        }

        return $results;
    }
}
