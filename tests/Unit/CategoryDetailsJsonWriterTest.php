<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rest\CategoryDetailsJsonWriter;
use PHPUnit\Framework\TestCase;

class CategoryDetailsJsonWriterTest extends TestCase
{
    public function testWritesCategoryDetailsAsJsonFile(): void
    {
        $directory = sys_get_temp_dir() . '/category-details-' . bin2hex(random_bytes(8));
        $filePath = $directory . '/categories.json';
        $data = [
            'count' => 1,
            'categories' => [
                ['id' => '253354', 'name' => 'Odświeżacze powietrza'],
            ],
        ];

        try {
            (new CategoryDetailsJsonWriter())->write($filePath, $data);

            $contents = file_get_contents($filePath);
            $this->assertNotFalse($contents);
            $this->assertSame($data, json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            if (is_file($filePath)) {
                unlink($filePath);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
