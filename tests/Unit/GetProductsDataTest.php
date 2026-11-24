<?php

namespace Tests\Unit;

use App\Services\GetProductsData;
use PHPUnit\Framework\TestCase;

class GetProductsDataTest extends TestCase
{
    public  $service;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 1) . '/Fixtures/dane.json';
        $this->service = new GetProductsData($path);
    }

    protected function tearDown(): void
    {
        $this->service = null;
        parent::tearDown();
    }    

    public function testReadsProductsDataAsArray(): void
    {
        $service = new GetProductsData();

        $data = $service->getData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('products', $data);
        $this->assertIsArray($data['products']);
        $this->assertNotEmpty($data['products']);
    }

    public function testReturnsNullWhenProductIdDoesNotExist(): void
    {
        $this->assertNull($this->service->getProductById('does-not-exist'));
    }

    public function testFindsProductByIdMyTest(): void
    {
        $myIdTest =  '213189981';
        $product = $this->service->getProductById($myIdTest);
        $this->assertSame($myIdTest, $product['id']);
    }

    public function testReturnsUniqueCategories(): void
    {

        $categories = $this->service->getUniqueCategories();
        $this->assertNotEmpty($categories);
        $this->assertSame(array_values(array_unique($categories)), $categories);
        $this->assertContains("Uroda / Perfumy i wody / Zapachy unisex / Wody perfumowane", $categories);
    }
}
