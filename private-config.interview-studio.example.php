<?php
// Interview Studio server-only config. Copy this shape to:
//   domains/builtbylt.com/public_html/private-config.interview-studio.php
// That real file lives ONLY on the server: deploys never touch it, and it
// must never be committed to this repo. The app prefers it over the shipped
// interview-studio/private/config.php when it exists and returns an array.
// Create a free Gemini API key at https://aistudio.google.com/api-keys
return [
    'gemini_api_key' => 'PASTE_GEMINI_API_KEY_HERE',
    'studio_password' => 'CHOOSE_A_LONG_PRIVATE_PASSWORD',
    // Gemini 3.8 Flash has a free tier (subject to Google's limits).
    // If unavailable for your project, try 'gemini-3.5-flash-lite'.
    'gemini_model' => 'gemini-3.8-flash',
];
