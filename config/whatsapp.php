<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for WhatsApp Business API integration for sending
    | violation notifications and disciplinary actions to employees.
    |
    */

    'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v18.0'),
    
    'api_key' => env('WHATSAPP_API_KEY'),
    
    'sender_number' => env('WHATSAPP_SENDER_NUMBER'),
    
    /*
    |--------------------------------------------------------------------------
    | Message Settings
    |--------------------------------------------------------------------------
    */
    
    'enabled' => env('WHATSAPP_ENABLED', true),
    
    'default_country_code' => '964', // Iraq
    
    'timeout' => env('WHATSAPP_TIMEOUT', 30),
    
    /*
    |--------------------------------------------------------------------------
    | Message Templates
    |--------------------------------------------------------------------------
    */
    
    'templates' => [
        'violation' => [
            'ar' => 'violation_notification_ar',
            'en' => 'violation_notification_en',
        ],
        'disciplinary' => [
            'ar' => 'disciplinary_action_ar',
            'en' => 'disciplinary_action_en',
        ],
    ],
];