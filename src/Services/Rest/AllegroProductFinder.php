<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroProductFinder
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
     * Searches Allegro's product catalog by EAN/GTIN.
     *
     * @return list<array<string, mixed>>
     */
    public function findByEan(string $ean): array
    {
        $ean = trim($ean);
        if ($ean === '') {
            throw new RuntimeException('EAN nie może być pusty.');
        }

        try {
            $response = $this->client->request(
                'GET',
                rtrim($this->apiUrl, '/') . '/sale/products',
                [
                    'query' => [
                        'phrase' => $ean,
                        'mode' => 'GTIN',
                        'language' => 'pl-PL',
                    ],
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
                sprintf('Nie udało się wyszukać produktu dla EAN %s: %s', $ean, $e->getMessage()),
                0,
                $e
            );
        }

        if (!is_array($data) || !is_array($data['products'] ?? null)) {
            throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź Allegro dla EAN %s.', $ean));
        }

        return array_values(array_filter($data['products'], 'is_array'));
    }
}
