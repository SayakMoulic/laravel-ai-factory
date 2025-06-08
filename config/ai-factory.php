<?php

/**
 * AI Factory Configuration
 *
 * This file defines the configuration for the AI Factory system, which supports
 * multiple AI providers like OpenAI or a local deployment.
 * 
 * The default driver can be set via the AI_FACTORY_DRIVER environment variable.
 * Supported drivers: 'openai', 'local'
 */

return [

    // Determines which AI driver to use by default.
    'driver' => env('AI_FACTORY_DRIVER', 'openai'),

    // Configuration for the OpenAI driver.
    'openai' => [
        // OpenAI API key used for authentication.
        'api_key' => env('AI_FACTORY_OPENAI_API_KEY'),

        // Model to be used for completions (e.g., gpt-4o, gpt-4o-mini, gpt-4.5-turbo).
        'model' => env('AI_FACTORY_OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    // Configuration for a local AI service or self-hosted API.
    'local' => [
        // Base URL of the local AI service.
        'url' => env('AI_FACTORY_LOCAL_URL', 'http://localhost:8080/v1/chat/completions'),

        // API key for the local AI service (if required).
        'api_key' => env('AI_FACTORY_LOCAL_API_KEY', ""),

        // Model name to be used by the local AI backend.
        'model' => env('AI_FACTORY_LOCAL_MODEL', "Default"),
    ],
];
