<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroCategoryProductParameters
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
     * Fetches the product parameters available for each Allegro category ID.
     *
     * @param list<string|int> $categoryIds
     * @return list<array{id: string, parameters: array<int, mixed>}>
     */
    public function getByCategoryIds(array $categoryIds): array
    {
        $results = [];
        $requestMade = false;

        foreach ($categoryIds as $categoryId) {
            $categoryId = trim((string) $categoryId);
            if ($categoryId === '') {
                continue;
            }

            if ($requestMade && $this->delayMicroseconds > 0) {
                usleep($this->delayMicroseconds);
            }

            try {
                $response = $this->client->request(
                    'GET',
                    rtrim($this->apiUrl, '/') . '/sale/categories/'
                        . rawurlencode($categoryId) . '/product-parameters',
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
                    sprintf('Nie udało się pobrać parametrów produktowych kategorii %s: %s', $categoryId, $e->getMessage()),
                    0,
                    $e
                );
            }

            if (!is_array($data)) {
                throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź Allegro dla kategorii %s.', $categoryId));
            }

            $parameters = $data['parameters'] ?? [];
            if (!is_array($parameters)) {
                $parameters = [];
            }

            $results[] = [
                'id' => $categoryId,
                'parameters' => $parameters,
            ];
            $requestMade = true;
        }

        return $results;
    }
}
