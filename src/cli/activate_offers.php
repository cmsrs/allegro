<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroProductOffer;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;

    $apiUrl = rtrim((string) $config['api_url'], '/');
    if (!str_contains($apiUrl, 'allegrosandbox.pl')) {
        throw new RuntimeException('Ta komenda aktywuje oferty wyłącznie w Allegro Sandbox. Skonfigurowany host: ' . $apiUrl);
    }

    $manifestPath = dirname(__DIR__, 2) . '/dane/allegro_created_offers.json';
    if (!is_readable($manifestPath)) {
        throw new RuntimeException('Nie można odczytać rejestru ofert: ' . $manifestPath);
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_array($manifest['offers'] ?? null) || $manifest['offers'] === []) {
        throw new RuntimeException('Rejestr ofert nie zawiera ofert do aktywacji.');
    }

    $accessToken = (new AllegroOAuth($config))->getAccessToken();
    $productOffer = new AllegroProductOffer($apiUrl, $accessToken);
    $updatedOffers = $manifest['offers'];

    foreach ($updatedOffers as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $offerId = trim((string) ($entry['offer_id'] ?? ''));
        if ($offerId === '') {
            echo "POMINIĘTO | wpis bez ID oferty\n";
            continue;
        }

        $productName = (string) ($entry['product_name'] ?? $offerId);
        echo sprintf("Aktywuję %s | oferta %s...\n", $productName, $offerId);
        echo sprintf("API: PATCH /sale/product-offers/%s publication.status=ACTIVE\n", $offerId);
        echo "Wysłano PATCH. Czekam na zakończenie asynchronicznej aktywacji...\n";

        $offer = $productOffer->activate($offerId, 120, static function (string $status, int $elapsedSeconds) use ($offerId): void {
            echo sprintf("  Oferta %s: %s, trwa %d s...\n", $offerId, $status, $elapsedSeconds);
        });
        $status = (string) ($offer['publication']['status'] ?? 'nieznany');
        if ($status !== 'ACTIVE') {
            throw new RuntimeException(sprintf('Oferta %s nie osiągnęła statusu ACTIVE (status: %s).', $offerId, $status));
        }

        $updatedOffers[$index]['publication_status'] = $status;
        $updatedOffers[$index]['activated_at'] = date(DATE_ATOM);
        (new CategoryDetailsJsonWriter())->write($manifestPath, [
            'generated_at' => date(DATE_ATOM),
            'count' => count($updatedOffers),
            'offers' => $updatedOffers,
        ]);

        echo sprintf("AKTYWNA | %s | ID oferty: %s\n", $productName, $offerId);
        echo "API: GET /sale/product-offers/{$offerId}\n";
        echo json_encode([
            'id' => $offerId,
            'publication' => $offer['publication'] ?? null,
            'validation' => $offer['validation'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
