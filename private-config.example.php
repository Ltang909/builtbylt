<?php
// Copy to: domains/builtbylt.com/private/builtbylt.php
// Generate with: php -r "echo password_hash('YOUR PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
return [
    'password_hash' => 'PASTE_GENERATED_PASSWORD_HASH_HERE',
    'timezone' => 'America/Toronto',
    // Optional: Google Calendar "Secret address in iCal format". Keep this private.
    'calendar_ics_url' => '',
    // Voice notes relay (loom-ish "Send to Notion" -> api/voice-notes.php).
    // Generate with: php -r "echo password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), PHP_EOL;"
    // Paste the RAW token (before hashing) into the voice notes settings page once.
    'voice_notes_token_hash' => '',
    // iPhone voice note transcription (loom-ish -> api/voice-transcribe.php).
    // Free key from https://console.groq.com/keys (no credit card). The key
    // never leaves the server: the browser sends audio to this relay, and
    // the relay calls Groq's Whisper API with the key.
    'groq_api_key' => '',
    'posthog' => [
        // Server-side query access. Create a Personal API key with Query Read only.
        'personal_api_key' => 'phx_PASTE_QUERY_READ_KEY_HERE',
        'project_id' => 'PASTE_NUMERIC_PROJECT_ID_HERE',
        'api_host' => 'https://us.posthog.com',

        // Event ingestion. This is the normal PostHog project key.
        'project_api_key' => 'phc_PASTE_PROJECT_KEY_HERE',
        'capture_host' => 'https://us.i.posthog.com',
    ],
    // Quartermaster buyer dashboard (api/qm-event.php reads Attio live).
    // Create at Attio Workspace settings -> Developers -> new integration
    // (or reuse an existing one) and paste its API key here. The key never
    // leaves the server: the dashboard page calls qm-event.php, which calls
    // Attio server-side. Needs "Read access to the List Entries scope".
    'attio_api_key' => '',
    // Buyer-link signing for qm-event.php (?key=HMAC(record_id, secret)).
    // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Keep empty (and qm_require_signed_keys=false) until buyer links go out.
    'qm_dashboard_secret' => '',
    'qm_require_signed_keys' => false,
    'qm_allowed_origins' => [
        'https://www.quartermaster.studio',
        'https://quartermaster.studio',
        'https://builtbylt.com',
    ],
];

