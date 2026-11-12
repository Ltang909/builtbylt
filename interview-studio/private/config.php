<?php
// Edit these two settings before uploading the site.
// Create a free Gemini API key at https://aistudio.google.com/api-keys
// The key stays server-side. Do not place it in HTML or browser JavaScript.
return [
    'gemini_api_key' => 'PASTE_GEMINI_API_KEY_HERE',
    'studio_password' => 'CHOOSE_A_LONG_PRIVATE_PASSWORD',
    // Gemini 3.8 Flash has a free tier (subject to Google's limits).
    // If unavailable for your project, try 'gemini-3.5-flash-lite'.
    'gemini_model' => 'gemini-3.8-flash',
];
