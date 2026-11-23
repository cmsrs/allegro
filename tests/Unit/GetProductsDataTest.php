<?php

namespace Tests\Unit;

use App\Services\GetProductsData;
use PHPUnit\Framework\TestCase;

class GetProductsDataTest extends TestCase
{
    public function testReadsProductsDataAsArray(): void
    {
        $service = new GetProductsData();

        $data = $service->getData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('products', $data);
        $this->assertIsArray($data['products']);
        $this->assertNotEmpty($data['products']);
    }

    public function testFindsProductById(): void
    {
        $service = new GetProductsData();

        $product = $service->getProductById('213189280');

        $this->assertNotNull($product);
        $this->assertSame('213189280', $product['id']);
    }

    public function testReturnsNullWhenProductIdDoesNotExist(): void
    {
        $service = new GetProductsData();

        $this->assertNull($service->getProductById('does-not-exist'));
    }

    public function testFindsProductByIdMyTest(): void
    {
        $myIdTest =  '213189981';
        $service = new GetProductsData();

        $product = $service->getProductById($myIdTest);
        $this->assertSame($myIdTest, $product['id']);
    }

    public function testReturnsUniqueCategories(): void
    {
        $service = new GetProductsData();

        $categories = $service->getUniqueCategories();

        $this->assertNotEmpty($categories);
        $this->assertSame(array_values(array_unique($categories)), $categories);
        $this->assertContains('Uroda / Perfumy i wody / Zapachy dla kobiet / Perfumy i wody perfumowane', $categories);
    }
}
