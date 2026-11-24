<?php

declare(strict_types=1);

namespace App\Services\Rest;

use RuntimeException;

class CategoryDetailsJsonRead
{

    public function read(string $filePathJson)
    {
        if( !file_exists($filePathJson) ){
            throw new RuntimeException('Nie udało się znalezc pliku: ' . $filePathJson);
        }

        $content = file_get_contents($filePathJson);
        if ($content === false) {
            throw new RuntimeException('Nie udało się odczytać pliku: ' . $filePathJson);
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function getFirstFindingCategory(string $filePathJson)
    {
        $categories = [];
        $data = $this->read($filePathJson);
        foreach( $data['categories'] ?? [] as $category ){
            $item = [];
            $firstItem = $category['matching_categories'][0] ?? null;
            if($firstItem){
                $item['id'] = $firstItem['id'];
                $item['name'] = $firstItem['name'];
            }
            $categories[] = $item;

        }
        return $categories;
    }




}
