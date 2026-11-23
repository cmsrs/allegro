<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rest\AllegroCategoryMatcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AllegroCategoryMatcherTest extends TestCase
{
    public function testReturnsSuggestedCategoriesWithoutCallingLiveAllegroApi(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'matchingCategories' => [
                    [
                        'id' => '12345',
                        'name' => 'Przykładowa kategoria',
                        'parent' => ['id' => '1', 'name' => 'Przykłady'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $matcher = new AllegroCategoryMatcher(
            'https://api.example.test',
            'test-token',
            $client,
            0
        );

        $results = $matcher->matchCategories(['Przykładowa kategoria']);

        $this->assertSame('Przykładowa kategoria', $results[0]['source_category']);
        $this->assertSame('12345', $results[0]['matching_categories'][0]['id']);
        $this->assertSame(0, $mock->count());
    }
}
