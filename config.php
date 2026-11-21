<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Wczytanie zmiennych z pliku .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Konfiguracja Allegro
$config = [
    'client_id'     => $_ENV['ALLEGRO_CLIENT_ID'],
    'client_secret' => $_ENV['ALLEGRO_CLIENT_SECRET'],

    'user_agent'    => $_ENV['ALLEGRO_USER_AGENT'],

    'auth_url'     => $_ENV['ALLEGRO_AUTH_URL'],
    'token_url'    => $_ENV['ALLEGRO_TOKEN_URL'],
    'api_url'      => $_ENV['ALLEGRO_API_URL'],
    'upload_url'   => $_ENV['ALLEGRO_UPLOAD_URL'],

    // Lokalizacja pliku tokenów pozostaje w projekcie
    'token_file'   => __DIR__ . '/tokens/token.json',
];
