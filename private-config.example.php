<?php
// Copy to: domains/builtbylt.com/private/builtbylt.php
// Generate with: php -r "echo password_hash('YOUR PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
return [
    'password_hash' => 'PASTE_GENERATED_PASSWORD_HASH_HERE',
    'timezone' => 'America/Toronto',
    'posthog' => [
        // Server-side query access. Create a Personal API key with Query Read only.
        'personal_api_key' => 'phx_PASTE_QUERY_READ_KEY_HERE',
        'project_id' => 'PASTE_NUMERIC_PROJECT_ID_HERE',
        'api_host' => 'https://us.posthog.com',

        // Event ingestion. This is the normal PostHog project key.
        'project_api_key' => 'phc_PASTE_PROJECT_KEY_HERE',
        'capture_host' => 'https://us.i.posthog.com',
    ],
];

