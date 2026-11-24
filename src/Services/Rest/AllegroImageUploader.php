<?php

declare(strict_types=1);

namespace App\Services\Rest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

class AllegroImageUploader
{
    private ClientInterface $client;

    public function __construct(
        private string $uploadUrl,
        private string $accessToken,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client(['timeout' => 60]);
    }

    /**
     * Uploads an image as binary data and returns its Allegro URL and expiration time.
     *
     * @return array{location: string, expiresAt: ?string}
     */
    public function upload(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Brak pliku lub brak prawa odczytu: ' . $filePath);
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException(sprintf('Nieobsługiwany typ obrazu %s: %s', $mimeType, $filePath));
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new RuntimeException('Nie można odczytać pliku: ' . $filePath);
        }

        try {
            $response = $this->client->request(
                'POST',
                rtrim($this->uploadUrl, '/') . '/sale/images',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Accept' => 'application/vnd.allegro.public.v1+json',
                        'Accept-Language' => 'pl-PL',
                        'Content-Type' => $mimeType,
                    ],
                    'body' => $contents,
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
                sprintf('Nie udało się wysłać zdjęcia %s: %s', $filePath, $e->getMessage()),
                0,
                $e
            );
        }

        if (!is_array($data) || !is_string($data['location'] ?? null) || $data['location'] === '') {
            throw new RuntimeException('Allegro nie zwróciło adresu location dla zdjęcia: ' . $filePath);
        }

        return [
            'location' => $data['location'],
            'expiresAt' => is_string($data['expiresAt'] ?? null) ? $data['expiresAt'] : null,
        ];
    }
}
