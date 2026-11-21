<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class AllegroApi
{
    private Client $client;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->client = new Client([
            'timeout' => 30,
            'http_errors' => true,
        ]);
    }

    /**
     * Pobieranie tokena OAuth client_credentials
     */
    public function getAccessToken(): string
    {
        echo "Pobieram token Allegro...\n";

        try {
            $response = $this->client->post(
                $this->config['token_url'],
                [
                    'auth' => [
                        $this->config['client_id'],
                        $this->config['client_secret']
                    ],
                    'form_params' => [
                        'grant_type' => 'client_credentials'
                    ],
                    'headers' => [
                        'User-Agent' => $this->config['user_agent']
                    ]
                ]
            );

            $data = json_decode(
                $response->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (empty($data['access_token'])) {
                throw new RuntimeException('Brak access_token w odpowiedzi Allegro.');
            }

            echo "Token pobrany poprawnie!\n";

            return $data['access_token'];

        } catch (GuzzleException | \JsonException $e) {
            throw new RuntimeException(
                'Błąd pobierania tokena: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Pobieranie kategorii Allegro
     */
    public function getCategories(string $token): array
    {
        echo "\nPobieram kategorie Allegro...\n\n";

        try {
            $response = $this->client->get(
                rtrim($this->config['api_url'], '/') . '/sale/categories',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/vnd.allegro.public.v1+json',
                        'User-Agent' => $this->config['user_agent']
                    ]
                ]
            );

            $data = json_decode(
                $response->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $categories = $data['categories'] ?? [];

            foreach ($categories as $category) {
                echo "ID: {$category['id']} | Nazwa: {$category['name']}\n";
            }

            echo "\nPobrano: " . count($categories) . " kategorii\n";

            return $categories;

        } catch (GuzzleException | \JsonException $e) {
            throw new RuntimeException(
                'Błąd pobierania kategorii: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
