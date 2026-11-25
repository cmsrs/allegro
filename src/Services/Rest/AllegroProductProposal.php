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
        $offerParametersCatalog = $this->readJson($catalogDirectory . '/parametry_kategorii_allegro.json');

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

        $offerParameterOptions = [];
        foreach ($offerParametersCatalog['categories'] ?? [] as $category) {
            if (!is_array($category) || (string) ($category['id'] ?? '') !== $categoryId) {
                continue;
            }

            foreach ($category['parameters'] ?? [] as $parameter) {
                if (is_array($parameter) && isset($parameter['id'])) {
                    $offerParameterOptions[(string) $parameter['id']] = $parameter['options'] ?? [];
                }
            }
            break;
        }

        $parameters = $this->normalizeProductParameters($product['parametry'] ?? $product['parameters'] ?? []);
        $parameters = $this->appendHumanReadableParameters($parameters, $product, $categoryParameters, $offerParameterOptions);
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

        $providedParameterIds = [];
        foreach ($parameters as $parameter) {
            $values = $parameter['values'] ?? $parameter['valuesIds'] ?? $parameter['rangeValue'] ?? null;
            if ($values !== null && $values !== '' && $values !== []) {
                $providedParameterIds[(string) ($parameter['id'] ?? '')] = true;
            }
        }

        $missingRequiredParameters = [];
        foreach ($categoryParameters as $parameter) {
            if (!is_array($parameter) || empty($parameter['required'])) {
                continue;
            }

            $parameterId = trim((string) ($parameter['id'] ?? ''));
            if ($parameterId !== '' && !isset($providedParameterIds[$parameterId])) {
                $missingRequiredParameters[] = (string) ($parameter['name'] ?? $parameterId);
            }
        }

        if ($missingRequiredParameters !== []) {
            throw new RuntimeException(sprintf(
                'Brakuje wymaganych parametrów produktu (kategoria %s): %s. Uzupełnij czytelne pola produktu, np. "marka" i "pojemnosc"; pozostałe parametry możesz podać w polu "parametry".',
                $categoryId,
                implode(', ', $missingRequiredParameters)
            ));
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
        $nameWordCount = preg_match_all('/\S+/u', $name);
        if ($nameWordCount === false || $nameWordCount < 3) {
            throw new RuntimeException(sprintf(
                'Nazwa produktu musi zawierać co najmniej 3 wyrazy (obecnie: %d): %s',
                max(0, $nameWordCount ?: 0),
                $name
            ));
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
     * Maps readable product fields such as "marka" and "pojemnosc" to the
     * parameter IDs and dictionary value IDs required by Allegro.
     *
     * @param list<array<string, mixed>> $parameters
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $categoryParameters
     * @param array<string, array<string, mixed>> $offerParameterOptions
     * @return list<array<string, mixed>>
     */
    private function appendHumanReadableParameters(array $parameters, array $product, array $categoryParameters, array $offerParameterOptions): array
    {
        $existingIds = [];
        foreach ($parameters as $parameter) {
            $existingIds[(string) ($parameter['id'] ?? '')] = true;
        }

        $brand = trim((string) ($product['marka'] ?? ''));
        $capacity = trim((string) ($product['pojemnosc'] ?? $product['pojemonsc'] ?? ''));
        $kind = trim((string) ($product['rodzaj'] ?? ''));
        $tradeName = trim((string) ($product['nazwa_handlowa'] ?? ''));

        $selectedBrandValueId = null;
        if ($brand !== '') {
            foreach ($categoryParameters as $categoryParameter) {
                if (!is_array($categoryParameter) || $this->normalizeText((string) ($categoryParameter['name'] ?? '')) !== 'marka') {
                    continue;
                }

                $selectedBrandValueId = $this->findDictionaryValue($categoryParameter['dictionary'] ?? [], $brand);
                if ($selectedBrandValueId === null) {
                    $selectedBrandValueId = $this->findDictionaryValue($categoryParameter['dictionary'] ?? [], 'bez marki');
                }
                break;
            }
        }

        foreach ($categoryParameters as $categoryParameter) {
            if (!is_array($categoryParameter)) {
                continue;
            }

            $parameterId = trim((string) ($categoryParameter['id'] ?? ''));
            $parameterName = $this->normalizeText((string) ($categoryParameter['name'] ?? ''));
            if ($parameterId === '' || isset($existingIds[$parameterId])) {
                continue;
            }

            if ($brand !== '' && $parameterName === 'marka') {
                if ($selectedBrandValueId === null) {
                    throw new RuntimeException(sprintf(
                        'Nie znaleziono marki "%s" ani wartości zastępczej "bez marki" w słowniku Allegro dla parametru Marka (ID %s).',
                        $brand,
                        $parameterId
                    ));
                }

                $parameters[] = ['id' => $parameterId, 'valuesIds' => [$selectedBrandValueId]];
                $existingIds[$parameterId] = true;
                continue;
            }

            if ($kind !== '' && $parameterName === 'rodzaj') {
                $kindValue = $this->findDictionaryValue($categoryParameter['dictionary'] ?? [], $kind);
                if ($kindValue === null) {
                    throw new RuntimeException(sprintf('Nie znaleziono rodzaju "%s" w słowniku Allegro dla kategorii produktu.', $kind));
                }

                $parameters[] = ['id' => $parameterId, 'valuesIds' => [$kindValue]];
                $existingIds[$parameterId] = true;
                continue;
            }

            if ($tradeName !== '' && $parameterName === 'nazwahandlowa') {
                $options = $offerParameterOptions[$parameterId] ?? [];
                $ambiguousValueId = trim((string) ($options['ambiguousValueId'] ?? ''));
                if (!empty($options['customValuesEnabled']) && $ambiguousValueId !== '') {
                    $availableAmbiguousValueId = $this->findDictionaryValue(
                        $categoryParameter['dictionary'] ?? [],
                        'inna',
                        $selectedBrandValueId
                    );
                    if ($availableAmbiguousValueId !== $ambiguousValueId) {
                        throw new RuntimeException(sprintf(
                            'Wartość niejednoznaczna dla parametru "%s" nie jest dostępna dla wybranej marki.',
                            $categoryParameter['name'] ?? $parameterId
                        ));
                    }

                    $parameters[] = [
                        'id' => $parameterId,
                        'valuesIds' => [$ambiguousValueId],
                        'values' => [$tradeName],
                    ];
                } else {
                    $tradeNameValue = $this->findDictionaryValue(
                        $categoryParameter['dictionary'] ?? [],
                        $tradeName,
                        $selectedBrandValueId
                    );
                    if ($tradeNameValue === null) {
                        throw new RuntimeException(sprintf('Nie znaleziono nazwy handlowej "%s" pasującej do marki w słowniku Allegro.', $tradeName));
                    }

                    $parameters[] = ['id' => $parameterId, 'valuesIds' => [$tradeNameValue]];
                }

                $existingIds[$parameterId] = true;
                continue;
            }

            if ($capacity !== '' && str_starts_with($parameterName, 'pojemnosc')) {
                $numericCapacity = preg_replace('/\s*(ml)?\s*$/iu', '', str_replace(',', '.', $capacity));
                if ($numericCapacity === null || !is_numeric(trim($numericCapacity))) {
                    throw new RuntimeException(sprintf('Nieprawidłowa pojemność "%s". Podaj liczbę z opcjonalną jednostką ml.', $capacity));
                }

                $parameters[] = ['id' => $parameterId, 'values' => [rtrim(rtrim(number_format((float) trim($numericCapacity), 2, '.', ''), '0'), '.')]];
                $existingIds[$parameterId] = true;
            }
        }

        return $parameters;
    }

    /**
     * @param mixed $dictionary
     */
    private function findDictionaryValue(mixed $dictionary, string $label, ?string $dependsOnValueId = null): ?string
    {
        if (!is_array($dictionary)) {
            return null;
        }

        $normalizedLabel = $this->normalizeText($label);
        foreach ($dictionary as $option) {
            if (!is_array($option) || $this->normalizeText((string) ($option['value'] ?? '')) !== $normalizedLabel) {
                continue;
            }

            $dependencies = array_map('strval', $option['dependsOnValueIds'] ?? []);
            if ($dependsOnValueId !== null && $dependencies !== [] && !in_array($dependsOnValueId, $dependencies, true)) {
                continue;
            }

            $valueId = trim((string) ($option['id'] ?? ''));
            if ($valueId !== '') {
                return $valueId;
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
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
