<?php

namespace App\Services;

use JsonException;
use RuntimeException;

class GetProductsData
{
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? dirname(__DIR__, 2) . '/produkty/dane.json';
    }

    /**
     * Reads the products JSON file and returns its contents as an array.
     *
     * @throws RuntimeException When the file cannot be read or its root is not a JSON object/array.
     * @throws JsonException When the file contains invalid JSON.
     */
    public function getData(): array
    {
        if (!is_readable($this->filePath)) {
            throw new RuntimeException(sprintf('Products data file is not readable: %s', $this->filePath));
        }

        $contents = file_get_contents($this->filePath);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read products data file: %s', $this->filePath));
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Products data file must contain a JSON object or array: %s', $this->filePath));
        }

        return $data;
    }

    /**
     * Finds a product by its ID.
     *
     * @return array<string, mixed>|null
     */
    public function getProductById(string|int $id): ?array
    {
        $data = $this->getData();
        $products = $data['products'] ?? [];

        foreach ($products as $product) {
            if (is_array($product) && isset($product['id']) && (string) $product['id'] === (string) $id) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Returns unique category names in the order they first appear in the file.
     *
     * @return list<string>
     */
    public function getUniqueCategories(): array
    {
        $data = $this->getData();
        $products = $data['products'] ?? [];
        $categories = [];

        foreach ($products as $product) {
            if (!is_array($product) || !isset($product['kategoria']) || !is_string($product['kategoria'])) {
                continue;
            }

            $category = trim($product['kategoria']);
            if ($category !== '') {
                $categories[$category] = true;
            }
        }

        return array_keys($categories);
    }
}
