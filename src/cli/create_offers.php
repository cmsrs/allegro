<?php

declare(strict_types=1);

use App\Services\GetProductsData;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

$service = new GetProductsData();
$data = $service->getData();

foreach($data['products'] ?? [] as $offer ){
    $ean = $offer['ean'] ?? null;
}


