<?php

declare(strict_types=1);

/**
 * Allegro Sandbox - Device Code OAuth2
 *
 * Uruchomienie:
 * php tests/authorize_device.php
 */

require_once __DIR__ . '/../../config.php';

function request(
    string $url,
    string $clientId,
    string $clientSecret,
    array $postData = []
): array {

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(
                $clientId . ':' . $clientSecret
            ),
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_TIMEOUT        => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Błąd CURL: ' . $curlError);
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Nieprawidłowa odpowiedź Allegro: ' . $response
        );
    }

    return [
        'http_code' => $httpCode,
        'data'      => $data
    ];
}


// ============================================
// KONFIGURACJA
// ============================================

$clientId     = $config['client_id'];
$clientSecret = $config['client_secret'];
$tokenUrl     = $config['token_url'];
$tokenFile    = $config['token_file'];

// Endpoint Device Code wyprowadzamy z token_url.
$deviceUrl = preg_replace(
    '~/token$~',
    '/device',
    $tokenUrl
);

if ($deviceUrl === $tokenUrl) {
    throw new RuntimeException(
        'Nieprawidłowy token_url w config.php'
    );
}

if (!function_exists('curl_init')) {
    throw new RuntimeException(
        'Brak rozszerzenia PHP cURL. Zainstaluj php-curl.'
    );
}


// ============================================
// KROK 1 - POBRANIE DEVICE CODE
// ============================================

echo "\n";
echo "========================================\n";
echo "       ALLEGRO SANDBOX - DEVICE OAUTH\n";
echo "========================================\n\n";

echo "Łączenie z Allegro Sandbox...\n";

try {

    $response = request(
        $deviceUrl . '?client_id=' . urlencode($clientId),
        $clientId,
        $clientSecret
    );

    $data = $response['data'];

    if ($response['http_code'] !== 200) {
        throw new RuntimeException(
            'Błąd pobierania device code: ' .
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );
    }

    $deviceCode = $data['device_code'];
    $userCode   = $data['user_code'];

    $verificationUrl = $data['verification_uri_complete']
        ?? $data['verification_uri'];

    $expiresIn = (int) $data['expires_in'];
    $interval = (int) ($data['interval'] ?? 5);

    // ========================================
    // KROK 2 - WYŚWIETLENIE KODU
    // ========================================

    echo "\n";
    echo "========================================\n";
    echo "       WYMAGANA AUTORYZACJA\n";
    echo "========================================\n\n";

    echo "Otwórz w przeglądarce:\n\n";

    echo $verificationUrl . "\n\n";

    echo "Twój kod autoryzacyjny:\n\n";

    echo strtoupper(
        implode(' ', str_split($userCode, 3))
    ) . "\n\n";

    echo "Kod jest ważny przez: ";
    echo $expiresIn . " sekund.\n\n";

    echo "Zaloguj się do Allegro Sandbox\n";
    echo "i zaakceptuj dostęp aplikacji import801.\n\n";

    echo "Oczekiwanie na autoryzację";

    // ========================================
    // KROK 3 - OCZEKIWANIE NA TOKEN
    // ========================================

    $startTime = time();
    $accessToken = null;

    while (time() - $startTime < $expiresIn) {

        sleep($interval);

        $result = request(
            $tokenUrl,
            $clientId,
            $clientSecret,
            [
                'grant_type' =>
                    'urn:ietf:params:oauth:grant-type:device_code',

                'device_code' => $deviceCode
            ]
        );

        $tokenData = $result['data'];

        // Autoryzacja zakończona.
        if ($result['http_code'] === 200) {

            $accessToken = $tokenData['access_token'];

            echo "\n\nAutoryzacja zakończona pomyślnie!\n";

            break;
        }

        $error = $tokenData['error'] ?? '';

        if ($error === 'authorization_pending') {

            echo ".";

            continue;
        }

        if ($error === 'slow_down') {

            $interval += 5;

            echo "\nZbyt częste zapytania. Zwiększam odstęp.\n";

            continue;
        }

        if ($error === 'access_denied') {

            throw new RuntimeException(
                'Użytkownik odmówił autoryzacji.'
            );
        }

        if (
            $error === 'expired_token' ||
            $error === 'invalid_device_code'
        ) {
            throw new RuntimeException(
                'Kod autoryzacyjny wygasł lub jest nieprawidłowy.'
            );
        }

        throw new RuntimeException(
            'Błąd OAuth: ' .
            json_encode($tokenData, JSON_UNESCAPED_UNICODE)
        );
    }

    if ($accessToken === null) {
        throw new RuntimeException(
            'Przekroczono czas oczekiwania na autoryzację.'
        );
    }

    // ========================================
    // KROK 4 - ZAPIS TOKENÓW
    // ========================================

    $expiresAt = time() + (int) $tokenData['expires_in'];

    $savedToken = [
        'access_token'  => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'],
        'expires_at'    => $expiresAt,
        'token_type'    => $tokenData['token_type'] ?? 'bearer'
    ];

    $directory = dirname($tokenFile);

    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $json = json_encode(
        $savedToken,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Błąd kodowania JSON.');
    }

    if (
        file_put_contents(
            $tokenFile,
            $json,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Nie udało się zapisać tokenów.'
        );
    }

    chmod($tokenFile, 0600);

    // ========================================
    // GOTOWE
    // ========================================

    echo "\n";
    echo "========================================\n";
    echo "          AUTORYZACJA GOTOWA\n";
    echo "========================================\n\n";

    echo "Token zapisany w:\n";
    echo $tokenFile . "\n\n";

    echo "Długość access_token: ";
    echo strlen($savedToken['access_token']) . "\n";

    echo "Ważny do: ";
    echo date('Y-m-d H:i:s', $expiresAt) . "\n\n";

    echo "Uprawnienia pliku: 600\n\n";

    echo "Możesz teraz uruchomić:\n\n";

    echo "php tests/test_oauth.php\n\n";

} catch (Throwable $e) {

    echo "\n\nBŁĄD: " . $e->getMessage() . "\n";

    exit(1);
}
