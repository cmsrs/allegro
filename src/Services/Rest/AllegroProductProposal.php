<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroProductProposal
{
    private ClientInterface $client;

    public function __construct(
        private string $apiUrl,
        private string $accessToken,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client(['timeout' => 60]);
    }

    /**
     * Builds a product proposal from the source product and Allegro dictionaries,
     * submits it, then fetches the proposed product by the returned product ID.
     *
     * @param array<string, mixed> $product
     * @param list<string> $imageLocations
     * @return array{proposal: array<string, mixed>, product: array<string, mixed>|null, product_error: string|null}
     */
    public function proposeFromCatalog(array $product, array $imageLocations, string $catalogDirectory): array
    {
        $categoriesCatalog = $this->readJson($catalogDirectory . '/kategorie_allegro.json');
        $parametersCatalog = $this->readJson($catalogDirectory . '/parametry_produktowe_kategorii_allegro.json');

        $sourceCategory = trim((string) ($product['kategoria'] ?? ''));
        $categoryId = trim((string) ($product['allegro_category_id'] ?? ''));
        if ($categoryId === '') {
            foreach ($categoriesCatalog['categories'] ?? [] as $category) {
                if (!is_array($category) || trim((string) ($category['source_category'] ?? '')) !== $sourceCategory) {
                    continue;
                }

                $matchingCategories = $category['matching_categories'] ?? [];
                if (is_array($matchingCategories) && isset($matchingCategories[0]['id'])) {
                    $categoryId = (string) $matchingCategories[0]['id'];
                    break;
                }
            }
        }

        if ($categoryId === '') {
            throw new RuntimeException('Nie znaleziono kategorii Allegro dla produktu: ' . ($product['nazwa'] ?? 'bez nazwy'));
        }

        $categoryParameters = [];
        foreach ($parametersCatalog['categories'] ?? [] as $category) {
            if (is_array($category) && (string) ($category['id'] ?? '') === $categoryId) {
                $categoryParameters = $category['product_parameters'] ?? [];
                break;
            }
        }
        if (!is_array($categoryParameters)) {
            $categoryParameters = [];
        }

        $parameters = $this->normalizeProductParameters($product['parametry'] ?? $product['parameters'] ?? []);
        $ean = trim((string) ($product['ean'] ?? ''));
        if ($ean !== '') {
            $gtinParameterId = '225693';
            foreach ($categoryParameters as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                $parameterName = strtolower((string) ($parameter['name'] ?? ''));
                if (str_contains($parameterName, 'ean') || str_contains($parameterName, 'gtin')) {
                    $gtinParameterId = (string) ($parameter['id'] ?? $gtinParameterId);
                    break;
                }
            }

            $hasGtin = false;
            foreach ($parameters as $parameter) {
                if ((string) ($parameter['id'] ?? '') === $gtinParameterId) {
                    $hasGtin = true;
                    break;
                }
            }
            if (!$hasGtin) {
                $parameters[] = ['id' => $gtinParameterId, 'values' => [$ean]];
            }
        }

        $images = [];
        foreach ($imageLocations as $location) {
            $location = trim($location);
            if ($location !== '') {
                $images[] = ['url' => $location];
            }
        }
        if ($images === []) {
            throw new RuntimeException('Propozycja produktu wymaga co najmniej jednego zdjęcia Allegro.');
        }

        $name = trim((string) ($product['nazwa'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nazwa produktu jest wymagana do utworzenia propozycji.');
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLength > 75) {
            throw new RuntimeException('Nazwa produktu przekracza limit Allegro wynoszący 75 znaków: ' . $name);
        }

        $payload = [
            'name' => $name,
            'category' => ['id' => $categoryId],
            'images' => $images,
            'parameters' => $parameters,
            'language' => 'pl-PL',
        ];
        if (is_array($product['opis'] ?? null)) {
            $payload['description'] = $product['opis'];
        }

        $proposal = $this->requestJson('POST', '/sale/product-proposals', $payload);
        $productId = trim((string) ($proposal['id'] ?? ''));
        if ($productId === '') {
            throw new RuntimeException('POST /sale/product-proposals nie zwrócił ID produktu.');
        }

        try {
            $createdProduct = $this->requestJson(
                'GET',
                '/sale/products/' . rawurlencode($productId),
                null,
                ['language' => 'pl-PL']
            );
            $productError = null;
        } catch (RuntimeException $e) {
            $createdProduct = null;
            $productError = $e->getMessage();
        }

        return [
            'proposal' => $proposal,
            'product' => $createdProduct,
            'product_error' => $productError,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeProductParameters(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            return [];
        }

        $normalized = [];
        foreach ($parameters as $key => $parameter) {
            if (is_array($parameter)) {
                $id = trim((string) ($parameter['id'] ?? (is_string($key) ? $key : '')));
                if ($id === '') {
                    continue;
                }

                $entry = ['id' => $id];
                foreach (['values', 'valuesIds', 'rangeValue'] as $valueKey) {
                    if (array_key_exists($valueKey, $parameter)) {
                        $entry[$valueKey] = $parameter[$valueKey];
                    }
                }
                $normalized[] = $entry;
            } elseif (is_string($key) && $key !== '' && (is_string($parameter) || is_numeric($parameter))) {
                $normalized[] = ['id' => $key, 'values' => [(string) $parameter]];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $options = [
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/vnd.allegro.public.v1+json',
                'Accept-Language' => 'pl-PL',
            ],
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/vnd.allegro.public.v1+json';
            $options['json'] = $body;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }

        try {
            $response = $this->client->request($method, rtrim($this->apiUrl, '/') . $path, $options);
            $responseBody = $response->getBody()->getContents();
            $data = $responseBody === '' ? [] : json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException | JsonException $e) {
            throw new RuntimeException(sprintf('Błąd %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź JSON z %s %s.', $method, $path));
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                '%s %s zwrócił HTTP %d: %s',
                $method,
                $path,
                $statusCode,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '(brak treści)'
            ));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('Nie można odczytać pliku katalogu: ' . $filePath);
        }

        try {
            $data = json_decode((string) file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Nieprawidłowy JSON w pliku: ' . $filePath, 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Nieprawidłowa struktura pliku: ' . $filePath);
        }

        return $data;
    }
}
