# Interview Studio v0.3 — Gemini transcription + PHP

A private, standalone voice-interview-to-LinkedIn-post tool for **standard PHP hosting**, including Hostinger shared hosting. No Node.js, npm, ElevenLabs, OpenAI API, or database.

## What's fixed

The previous version used Chrome's `webkitSpeechRecognition`. That service can fail even when site microphone permission is **allowed** (as shown in the screenshot). **v0.3 removes that dependency entirely**:

1. Browser `getUserMedia` opens your microphone.
2. `MediaRecorder` saves the current answer locally while a live input-level indicator confirms sound is arriving.
3. Press **Finish answer** (up to 90 seconds). The browser uploads the audio through a password-protected PHP endpoint.
4. PHP securely sends the audio to Gemini with the **existing API key** and returns a transcript.
5. Your words appear as an editable turn. Gemini then generates a follow-up interview question.

The browser still speaks the interviewer's questions using its installed voices. Voice selection/preview remains available. This is **turn-based**, not full-duplex realtime. The transcript only appears after the recording is finished, not while you're still talking.

## Upgrade an existing installation

1. **Back up your existing `private/config.php`** (contains your Gemini API key and studio password).
2. Unzip the download. Replace `index.php`, `api.php`, `private/bootstrap.php`, `assets/app.js`, `assets/app.css`, and `README.md` on your server. Keep your **existing** `private/config.php` if already configured.
3. Hard-refresh at `https://builtbylt.com/interview-studio/` (`Cmd+Shift+R` on Mac, `Ctrl+Shift+R` on Windows). This release also changes the asset version from `v=4` to `v=5`.
4. Click **Start talking**, answer the first question, and press **Finish answer**. Watch the input level meter while talking. Your transcript should then appear on the right.

All the existing saved sessions use the same localStorage key and will remain available in the same browser. Export important sessions before upgrading.

## Fresh installation

1. Obtain a free API key at <https://aistudio.google.com/api-keys> and keep the project on the **free tier** if you want to avoid API charges.
2. Upload the **contents** of this ZIP to your PHP hosting folder, e.g. `public_html/interview-studio/`.
3. Edit `private/config.php`:

   ```php
   'gemini_api_key' => 'YOUR_FREE_GEMINI_KEY',
   'studio_password' => 'YOUR_STRONG_PRIVATE_PASSWORD',
   'gemini_model' => 'gemini-3.8-flash',
   ```

4. Run PHP 8.1+, use HTTPS, and allow outbound PHP HTTPS requests to `generativelanguage.googleapis.com`.
5. Ensure your `private` directory is blocked from web access. `private/.htaccess` is supplied for compatible Apache/LiteSpeed hosting. Verify the server does not serve files from that folder; move it above the public web root if the server ignores `.htaccess`.

## Important troubleshooting

- **Microphone allowed but no transcript:** Ensure the meter moves when you speak; press **Finish answer** and read the on-screen status. The browser no longer uses Chrome's speech-recognition service.
- **Mic meter flat:** Check Chrome's selected input device, operating-system mic access, or another app using the microphone. The meter is a best-effort indicator, not definitive proof of intelligible voice audio.
- **Server rejects audio / HTTP 413 / PHP upload error:** Increase PHP `upload_max_filesize` and `post_max_size` in Hostinger PHP settings to at least **8MB and 10MB** respectively. Keep individual answers under 90 seconds.
- **Gemini transcription fails:** The UI retains the last recording and exposes **Retry transcription**. You can also type your answer. Confirm your API key, free-tier quota, model access, and outbound PHP HTTPS requests.
- **Sound of interviewer isn't natural:** The voice selector controls system/browser TTS voices, not Gemini voice generation. This release fixes **input reliability**; higher-quality output voices can be added later.
- **No transcript while talking:** Expected. The app records first and transcribes when you press **Finish answer**.

## Data handling and limitations

- You speak into the browser. Each answer is uploaded temporarily to PHP, passed to Gemini, and not written to persistent server storage by this app.
- Your transcript is sent to Gemini again for follow-up questions, editorial evaluation, and drafting.
- Free-tier Gemini content may be used by Google to improve its products. Avoid confidential customer information and sensitive personal data. Free-tier usage has limits and can change.
- Saved interviews are kept in **your browser** when you click Save. Export JSON backups for important interviews.
- This is intentionally a one-person app protected with a password, not a public SaaS service.
- Live Gemini speech-to-text cannot be tested without a valid API key. All syntax checks and local unauthenticated/login checks can be performed offline.

## Files

- `index.php` – app and password login
- `api.php` – interview, evaluate, draft, and **transcribe** routes
- `private/bootstrap.php` – API, session, and Gemini helpers
- `private/config.php` – key/password (never share)
- `assets/app.js` – MediaRecorder, microphone meter, transcript and editor
- `assets/app.css` – responsive UI

Official reference: <https://ai.google.dev/gemini-api/docs/audio>
