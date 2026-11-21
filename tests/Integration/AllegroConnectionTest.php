<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class AllegroConnectionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
    }

    public function testOAuthScript(): void
    {
        $output = [];
        $exitCode = 0;

        $command = escapeshellarg(PHP_BINARY) . ' ' .
            escapeshellarg($this->projectRoot . '/src/cli/test_oauth.php');

        exec($command . ' 2>&1', $output, $exitCode);

        $result = implode("\n", $output);

        $this->assertSame(0, $exitCode, $result);
        $this->assertStringContainsString('Token jest aktualny!', $result);
    }

    public function testCategoryScript(): void
    {
        $output = [];
        $exitCode = 0;

        $command = escapeshellarg(PHP_BINARY) . ' ' .
            escapeshellarg($this->projectRoot . '/src/cli/test_category.php');

        exec($command . ' 2>&1', $output, $exitCode);

        $result = implode("\n", $output);

        $this->assertSame(0, $exitCode, $result);
        $this->assertStringContainsString('SUKCES! API ALLEGRO DZIALA.', $result);
        $this->assertStringContainsString('Pobrano:', $result);
    }
}
