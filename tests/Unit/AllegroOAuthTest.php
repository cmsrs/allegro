<?php

namespace Tests\Unit;

use App\AllegroOAuth;
use PHPUnit\Framework\TestCase;

class AllegroOAuthTest extends TestCase
{
    public function testCanCreateOAuthObject(): void
    {
        $config = [
            'client_id'     => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'user_agent'    => 'PHPUnit Test',
            'auth_url'      => 'https://example.com/auth',
            'token_url'     => 'https://example.com/token',
            'api_url'       => 'https://example.com/api',
            'upload_url'    => 'https://example.com/upload',
            'token_file'    => sys_get_temp_dir() . '/phpunit_test_token.json',
        ];

        $oauth = new AllegroOAuth($config);

        $this->assertInstanceOf(AllegroOAuth::class, $oauth);
    }
}
