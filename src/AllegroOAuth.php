<?php

declare(strict_types=1);

namespace App;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;
use Throwable;

class AllegroOAuth
{
    private GenericProvider $provider;
    private string $tokenFile;

    public function __construct(array $config)
    {
        $this->tokenFile = $config['token_file'];

        $this->provider = new GenericProvider([
            'clientId'                => $config['client_id'],
            'clientSecret'            => $config['client_secret'],
            //'redirectUri'             => $config['redirect_uri'],
            'urlAuthorize'            => $config['auth_url'],
            'urlAccessToken'          => $config['token_url'],
            'urlResourceOwnerDetails' => rtrim($config['api_url'], '/') . '/me',
        ]);
    }

    /**
     * Zwraca aktualny access_token.
     */
    public function getAccessToken(): string
    {
        $token = $this->loadToken();

        // Odśwież token 60 sekund przed wygaśnięciem.
        if (
            empty($token['access_token']) ||
            empty($token['refresh_token'])
        ) {
            throw new RuntimeException(
                'Brak access_token lub refresh_token w pliku token.json'
            );
        }

        $expiresAt = (int) ($token['expires_at'] ?? 0);

        if ($expiresAt > time() + 60) {
            return $token['access_token'];
        }

        return $this->refreshToken($token);
    }

    /**
     * Odświeża access_token przy użyciu refresh_token.
     */
    private function refreshToken(array $oldToken): string
    {
        try {
            $newToken = $this->provider->getAccessToken(
                'refresh_token',
                [
                    'refresh_token' => $oldToken['refresh_token']
                ]
            );

            $this->saveToken($newToken);

            return $newToken->getToken();

        } catch (Throwable $e) {
            throw new RuntimeException(
                'Nie udało się odświeżyć tokena Allegro: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Odczytuje token z pliku JSON.
     */
    private function loadToken(): array
    {
        if (!file_exists($this->tokenFile)) {
            throw new RuntimeException(
                'Nie znaleziono pliku tokena: ' . $this->tokenFile
            );
        }

        $content = file_get_contents($this->tokenFile);

        if ($content === false) {
            throw new RuntimeException('Nie można odczytać pliku tokena.');
        }

        $token = json_decode($content, true);

        if (!is_array($token)) {
            throw new RuntimeException('Nieprawidłowy plik token.json');
        }

        return $token;
    }

    /**
     * Zapisuje nowe tokeny do pliku JSON.
     */
    private function saveToken(AccessToken $token): void
    {
        $data = [
            'access_token'  => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'expires_at'    => $token->getExpires(),
            'token_type'    => $token->getValues()['token_type'] ?? 'bearer',
        ];

        $directory = dirname($this->tokenFile);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException(
                    'Nie można utworzyć katalogu tokenów.'
                );
            }
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('Nie można zakodować tokena JSON.');
        }

        if (file_put_contents($this->tokenFile, $json, LOCK_EX) === false) {
            throw new RuntimeException('Nie można zapisać tokena.');
        }

        chmod($this->tokenFile, 0600);
    }
}
