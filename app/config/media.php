<?php

return [
    'fake_engine' => (bool) env('FAKE_ENGINE', false),
    'url_provider' => env('MEDIA_URL_PROVIDER', 'presigned'),
    'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 600),
    'max_upload_kb' => 20480,
    'max_request_bytes' => 96 * 1024 * 1024,
    'max_result_bytes' => 64 * 1024 * 1024,
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'cloudfront' => [
        'domain' => env('CLOUDFRONT_DOMAIN'),
        'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID'),
        'private_key_path' => env('CLOUDFRONT_PRIVATE_KEY_PATH'),
        'private_key_base64' => env('CLOUDFRONT_PRIVATE_KEY_BASE64'),
    ],
    'krea' => [
        'base_url' => env('KREA_BASE_URL', 'https://api.krea.ai'),
        'key' => env('KREA_API_KEY'),
        'test_apps' => [
            'generator' => env('KREA_TEST_APP_ID_GENERATOR'),
            'editor' => env('KREA_TEST_APP_ID_EDITOR'),
            'upscaler' => env('KREA_TEST_APP_ID_UPSCALER'),
            'skechers' => env('KREA_TEST_APP_ID_SKECHERS'),
            'invierno' => env('KREA_TEST_APP_ID_INVIERNO'),
        ],
        'fixture_path' => base_path('tests/Fixtures/krea'),
    ],
];
