<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rest\AllegroCategoryParameters;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AllegroCategoryParametersTest extends TestCase
{
    public function testRequestsCategoryParametersEndpoint(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'parameters' => [
                    ['id' => '30001', 'name' => 'Marka', 'type' => 'dictionary'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $history = [];
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);
        $service = new AllegroCategoryParameters('https://api.example.test', 'test-token', $client, 0);

        $results = $service->getByCategoryIds(['253354']);

        $this->assertSame('253354', $results[0]['id']);
        $this->assertSame('30001', $results[0]['parameters'][0]['id']);
        $this->assertSame(
            '/sale/categories/253354/parameters',
            $history[0]['request']->getUri()->getPath()
        );
        $this->assertSame(0, $mock->count());
    }
}
