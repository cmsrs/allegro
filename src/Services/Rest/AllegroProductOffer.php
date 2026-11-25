<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroProductOffer
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
     * Creates an inactive BUY_NOW offer linked to an existing Allegro catalog product,
     * then fetches the created offer for verification.
     *
     * @param array<string, mixed> $product
     * @return array{offer_id: string, offer: array<string, mixed>, verification: array<string, mixed>|null, verification_error: string|null}
     */
    public function createFromProduct(array $product, string $productId, string $catalogDirectory, array $images = []): array
    {
        $productId = trim($productId);
        if ($productId === '') {
            throw new RuntimeException('ID produktu Allegro jest wymagane do utworzenia oferty.');
        }

        $name = trim((string) ($product['nazwa'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nazwa produktu jest wymagana do utworzenia oferty.');
        }

        $price = $product['cena_sprzedazy'] ?? $product['cena'] ?? null;
        if (!is_numeric($price) || (float) $price <= 0) {
            throw new RuntimeException(sprintf('Brak poprawnej ceny dla produktu "%s".', $name));
        }

        $stock = $product['stan_magazynowy'] ?? 1;
        if (!is_numeric($stock) || (int) $stock < 1) {
            throw new RuntimeException(sprintf('Stan magazynowy produktu "%s" musi wynosić co najmniej 1.', $name));
        }

        $shippingRateId = trim((string) ($product['allegro_shipping_rate_id'] ?? ''));
        $responsibleProducerId = trim((string) ($product['allegro_responsible_producer_id'] ?? ''));
        if ($shippingRateId === '' || $responsibleProducerId === '') {
            $missing = [];
            if ($shippingRateId === '') {
                $missing[] = 'allegro_shipping_rate_id';
            }
            if ($responsibleProducerId === '') {
                $missing[] = 'allegro_responsible_producer_id';
            }
            throw new RuntimeException(sprintf(
                'Brakuje danych wymaganych do kompletnej oferty "%s": %s. Uzupełnij je w produkty/dane.json.',
                $name,
                implode(', ', $missing)
            ));
        }

        $categoryId = $this->resolveCategoryId($product, $catalogDirectory);
        $payload = [
            'name' => $name,
            'category' => ['id' => $categoryId],
            'productSet' => [[
                'product' => ['id' => $productId],
                'responsibleProducer' => ['type' => 'ID', 'id' => $responsibleProducerId],
            ]],
            'sellingMode' => [
                'format' => 'BUY_NOW',
                'price' => [
                    'amount' => number_format((float) $price, 2, '.', ''),
                    'currency' => 'PLN',
                ],
            ],
            'stock' => ['available' => (int) $stock],
            'publication' => ['status' => 'INACTIVE'],
            'delivery' => ['shippingRates' => ['id' => $shippingRateId]],
        ];

        $imageUrls = [];
        foreach ($images as $image) {
            $url = is_array($image) ? trim((string) ($image['url'] ?? '')) : trim((string) $image);
            if ($url !== '') {
                $imageUrls[] = $url;
            }
        }
        if ($imageUrls !== []) {
            $payload['images'] = $imageUrls;
        }

        if (is_array($product['opis'] ?? null)) {
            $payload['description'] = $product['opis'];
        }

        $offer = $this->requestJson('POST', '/sale/product-offers', $payload);
        $offerId = trim((string) ($offer['id'] ?? ''));
        if ($offerId === '') {
            throw new RuntimeException('POST /sale/product-offers nie zwrócił ID oferty.');
        }

        try {
            $verifiedOffer = $this->getById($offerId);
            $verificationError = null;
        } catch (RuntimeException $e) {
            $verifiedOffer = null;
            $verificationError = $e->getMessage();
        }

        return [
            'offer_id' => $offerId,
            'offer' => $offer,
            'verification' => $verifiedOffer,
            'verification_error' => $verificationError,
        ];
    }

    /** @return array<string, mixed> */
    public function getById(string $offerId): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new RuntimeException('ID oferty jest wymagane do pobrania szczegółów.');
        }

        return $this->requestJson('GET', '/sale/product-offers/' . rawurlencode($offerId));
    }

    /**
     * Activates an existing inactive offer and waits for Allegro's async operation.
     *
     * @return array<string, mixed>
     */
    public function activate(string $offerId, int $timeoutSeconds = 120, ?callable $onProgress = null): array
    {
        $offerId = trim($offerId);
        if ($offerId === '') {
            throw new RuntimeException('ID oferty jest wymagane do jej aktywacji.');
        }

        $offer = $this->getById($offerId);
        $status = (string) ($offer['publication']['status'] ?? '');
        if ($status === 'ACTIVE') {
            return $offer;
        }
        if ($status !== 'INACTIVE') {
            throw new RuntimeException(sprintf(
                'Nie można aktywować oferty %s ze statusem %s.',
                $offerId,
                $status !== '' ? $status : 'nieznanym'
            ));
        }

        $validationErrors = $offer['validation']['errors'] ?? [];
        if (is_array($validationErrors) && $validationErrors !== []) {
            throw new RuntimeException(sprintf(
                'Oferta %s ma błędy walidacji i nie może zostać aktywowana: %s',
                $offerId,
                json_encode($validationErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '(nieznane błędy)'
            ));
        }

        $path = '/sale/product-offers/' . rawurlencode($offerId);
        $requestPath = rtrim($this->apiUrl, '/') . $path;
        try {
            $patchResponse = $this->client->request('PATCH', $requestPath, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept' => 'application/vnd.allegro.public.v1+json',
                    'Accept-Language' => 'pl-PL',
                    'Content-Type' => 'application/vnd.allegro.public.v1+json',
                ],
                'json' => ['publication' => ['status' => 'ACTIVE']],
            ]);
            $patchStatusCode = $patchResponse->getStatusCode();
            $patchBody = $patchResponse->getBody()->getContents();
            $patchData = $patchBody === '' ? [] : json_decode($patchBody, true, 512, JSON_THROW_ON_ERROR);
            $location = $patchResponse->getHeaderLine('Location');
        } catch (GuzzleException | JsonException $e) {
            throw new RuntimeException(sprintf('Błąd PATCH %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($patchData)) {
            throw new RuntimeException(sprintf('Nieprawidłowa odpowiedź JSON z PATCH %s.', $path));
        }
        if ($patchStatusCode < 200 || $patchStatusCode >= 300) {
            if ($this->hasInProgressTaskLimitError($patchData)) {
                return $this->waitForActiveStatus($offerId, $timeoutSeconds, $onProgress);
            }

            throw new RuntimeException(sprintf(
                'PATCH %s zwrócił HTTP %d: %s',
                $path,
                $patchStatusCode,
                json_encode($patchData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '(brak treści)'
            ));
        }

        $operationId = trim((string) ($patchData['operation']['id'] ?? ''));
        if ($operationId === '' && $location !== '') {
            $locationPath = parse_url($location, PHP_URL_PATH);
            if (is_string($locationPath)
                && preg_match('~/operations/([^/]+)$~', $locationPath, $matches) === 1
            ) {
                $operationId = rawurldecode($matches[1]);
            }
        }
        if ($operationId === '') {
            $offer = $this->getById($offerId);
            if (($offer['publication']['status'] ?? null) !== 'ACTIVE') {
                return $this->waitForActiveStatus($offerId, $timeoutSeconds, $onProgress);
            }

            return $offer;
        }

        $operationPath = $path . '/operations/' . rawurlencode($operationId);
        $startedAt = time();
        $deadline = $startedAt + max(1, $timeoutSeconds);
        $lastProgressAt = $startedAt;
        do {
            sleep(2);
            try {
                $operation = $this->requestJson('GET', $operationPath);
            } catch (RuntimeException $e) {
                if ($this->exceptionIsInProgressTaskLimit($e)) {
                    return $this->waitForActiveStatus($offerId, max(1, $deadline - $startedAt), $onProgress, $startedAt);
                }

                throw $e;
            }
            $operationStatus = strtoupper((string) ($operation['operation']['status'] ?? ''));
            if (in_array($operationStatus, ['FAILED', 'ERROR'], true)) {
                throw new RuntimeException(sprintf(
                    'Aktywacja oferty %s nie powiodła się: %s',
                    $offerId,
                    json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '(brak szczegółów)'
                ));
            }

            if ($operationStatus === 'IN_PROGRESS') {
                $now = time();
                if ($onProgress !== null && $now - $lastProgressAt >= 10) {
                    $onProgress($operationStatus, $now - $startedAt);
                    $lastProgressAt = $now;
                }
                continue;
            }

            $offer = $this->getById($offerId);
            if (($offer['publication']['status'] ?? null) === 'ACTIVE') {
                return $offer;
            }
        } while (time() < $deadline);

        throw new RuntimeException(sprintf(
            'Przekroczono czas oczekiwania na aktywację oferty %s. Ostatni status: %s.',
            $offerId,
            $offer['publication']['status'] ?? 'nieznany'
        ));
    }

    /**
     * Waits for an earlier activation request to finish without sending another PATCH.
     *
     * @return array<string, mixed>
     */
    private function waitForActiveStatus(
        string $offerId,
        int $timeoutSeconds,
        ?callable $onProgress,
        ?int $startedAt = null
    ): array {
        $startedAt ??= time();
        $deadline = $startedAt + max(1, $timeoutSeconds);
        $lastProgressAt = 0;
        $offer = [];

        do {
            sleep(5);
            $offer = $this->getById($offerId);
            $status = strtoupper((string) ($offer['publication']['status'] ?? ''));
            if ($status === 'ACTIVE') {
                return $offer;
            }
            if (!in_array($status, ['INACTIVE', 'ACTIVATING'], true)) {
                throw new RuntimeException(sprintf(
                    'Oczekiwanie na aktywację oferty %s przerwane. Status oferty: %s.',
                    $offerId,
                    $status !== '' ? $status : 'nieznany'
                ));
            }

            $now = time();
            if ($onProgress !== null && $now - $lastProgressAt >= 10) {
                $onProgress('WAITING_FOR_PREVIOUS_STATUS_CHANGE', $now - $startedAt);
                $lastProgressAt = $now;
            }
        } while (time() < $deadline);

        throw new RuntimeException(sprintf(
            'Przekroczono czas oczekiwania na aktywację oferty %s. Ostatni status: %s.',
            $offerId,
            $offer['publication']['status'] ?? 'nieznany'
        ));
    }

    /** @param array<string, mixed> $response */
    private function hasInProgressTaskLimitError(array $response): bool
    {
        foreach ($response['errors'] ?? [] as $error) {
            if (is_array($error) && ($error['code'] ?? null) === 'InProgressTaskLimitReachedException') {
                return true;
            }
        }

        return false;
    }

    private function exceptionIsInProgressTaskLimit(RuntimeException $exception): bool
    {
        return str_contains($exception->getMessage(), 'InProgressTaskLimitReachedException');
    }

    /**
     * Updates an existing inactive offer with its delivery rate and GPSR producer.
     * The existing productSet is retained and its catalog product ID is re-sent.
     *
     * @param array<string, mixed> $product
     * @return array{offer_id: string, offer: array<string, mixed>, verification: array<string, mixed>|null, verification_error: string|null}
     */
    public function completeExistingDraft(string $offerId, array $product): array
    {
        $offerId = trim($offerId);
        $shippingRateId = trim((string) ($product['allegro_shipping_rate_id'] ?? ''));
        $responsibleProducerId = trim((string) ($product['allegro_responsible_producer_id'] ?? ''));
        if ($offerId === '' || $shippingRateId === '' || $responsibleProducerId === '') {
            throw new RuntimeException('Do uzupełnienia szkicu potrzebne są ID oferty, cennika dostawy i producenta Allegro.');
        }

        $currentOffer = $this->getById($offerId);
        $currentProductSet = $currentOffer['productSet'] ?? [];
        if (!is_array($currentProductSet) || $currentProductSet === []) {
            throw new RuntimeException(sprintf('Oferta %s nie zawiera produktu w polu productSet.', $offerId));
        }

        $productSet = [];
        foreach ($currentProductSet as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $catalogProductId = trim((string) ($item['product']['id'] ?? ''));
            if ($catalogProductId === '') {
                throw new RuntimeException(sprintf('Nie można zachować produktu w zestawie oferty %s.', $offerId));
            }

            $entry = [
                'product' => ['id' => $catalogProductId],
                'quantity' => $item['quantity'] ?? ['value' => 1],
            ];
            if ($index === 0) {
                $entry['responsibleProducer'] = ['type' => 'ID', 'id' => $responsibleProducerId];
            } elseif (is_array($item['responsibleProducer'] ?? null)) {
                $entry['responsibleProducer'] = $item['responsibleProducer'];
            }
            if (is_array($item['responsiblePerson'] ?? null)) {
                $entry['responsiblePerson'] = $item['responsiblePerson'];
            }

            $productSet[] = $entry;
        }

        $payload = [
            'productSet' => $productSet,
            'delivery' => ['shippingRates' => ['id' => $shippingRateId]],
        ];
        if (is_string($currentOffer['delivery']['handlingTime'] ?? null)) {
            $payload['delivery']['handlingTime'] = $currentOffer['delivery']['handlingTime'];
        }

        $updatedOffer = $this->requestJson('PATCH', '/sale/product-offers/' . rawurlencode($offerId), $payload);
        try {
            $verifiedOffer = $this->getById($offerId);
            $verificationError = null;
        } catch (RuntimeException $e) {
            $verifiedOffer = null;
            $verificationError = $e->getMessage();
        }

        return [
            'offer_id' => $offerId,
            'offer' => $updatedOffer,
            'verification' => $verifiedOffer,
            'verification_error' => $verificationError,
        ];
    }

    /** @param array<string, mixed> $product */
    private function resolveCategoryId(array $product, string $catalogDirectory): string
    {
        $categoryId = trim((string) ($product['allegro_category_id'] ?? ''));
        if ($categoryId !== '') {
            return $categoryId;
        }

        $path = rtrim($catalogDirectory, '/') . '/kategorie_allegro.json';
        if (!is_readable($path)) {
            throw new RuntimeException('Nie można odczytać słownika kategorii Allegro: ' . $path);
        }

        try {
            $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Nieprawidłowy JSON słownika kategorii Allegro: ' . $path, 0, $e);
        }

        $sourceCategory = trim((string) ($product['kategoria'] ?? ''));
        foreach ($catalog['categories'] ?? [] as $category) {
            if (!is_array($category) || trim((string) ($category['source_category'] ?? '')) !== $sourceCategory) {
                continue;
            }

            $matchingCategories = $category['matching_categories'] ?? [];
            if (isset($matchingCategories[0]['id'])) {
                return (string) $matchingCategories[0]['id'];
            }
        }

        throw new RuntimeException('Nie znaleziono kategorii Allegro dla produktu: ' . ($product['nazwa'] ?? 'bez nazwy'));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $body = null): array
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
}
