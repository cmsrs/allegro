<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroCategoryMatcher
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
     * Gets Allegro's suggested categories for each supplied category name.
     *
     * @param list<string> $categoryNames
     * @return list<array{source_category: string, matching_categories: array}>
     */
    public function matchCategories(array $categoryNames): array
    {
        $results = [];
        $requestMade = false;
        $ids = [];

        foreach ($categoryNames as $categoryName) {
            $categoryName = trim($categoryName);
            if ($categoryName === '') {
                continue;
            }

            if ($requestMade && $this->delayMicroseconds > 0) {
                usleep($this->delayMicroseconds);
            }

            try {
                $response = $this->client->request(
                    'GET',
                    rtrim($this->apiUrl, '/') . '/sale/matching-categories',
                    [
                        'query' => ['name' => $categoryName],
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
                    sprintf('Nie udało się pobrać kategorii Allegro dla "%s": %s', $categoryName, $e->getMessage()),
                    0,
                    $e
                );
            }

            if (!is_array($data)) {
                throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź Allegro dla kategorii "%s".', $categoryName));
            }


            $matchingCategories = $data['matchingCategories'] ?? [];
            if (!is_array($matchingCategories)) {
                $matchingCategories = [];
            }

            $results[] = [
                'source_category' => $categoryName,
                'first_id' => $matchingCategories[0]['id'] ?? null,                
                'matching_categories' => $matchingCategories,
            ];
            $requestMade = true;
        }

        return $results;
    }
}
