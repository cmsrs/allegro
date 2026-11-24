<?php
require "config.php";
require "src/AllegroOAuth.php";

$oauth = new App\AllegroOAuth($config);
$token = $oauth->getAccessToken();

$url = rtrim($config["api_url"], "/") .
       // "/sale/product-offers/7782583516";
       "/sale/shipping-rates";

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $token,
        "Accept: application/vnd.allegro.public.v1+json"
    ]
]);

echo curl_exec($ch);
curl_close($ch);
