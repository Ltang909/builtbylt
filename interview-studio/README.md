# Interview Studio — Gemini (PHP edition)

**A no-build PHP + vanilla JavaScript app for one user. This studio is brand-independent; it does not assume you are writing about Leave It to Bum Bum.** Interview by talking or typing, evaluate whether your real experience is worth a LinkedIn post, and draft in your own words. Adapted for standard Hostinger shared PHP hosting.

**No OpenAI API key, Node, npm, Composer, ElevenLabs, or database needed.**

## 1. Create a free Gemini key

1. Visit [Google AI Studio — API keys](https://aistudio.google.com/api-keys).
2. Create or select a **Free Tier** project and create an API key. Do **not** upgrade the project to paid billing if avoiding charges is your priority.
3. Check that your project has access to `gemini-3.8-flash` under AI Studio model access / rate limits. Free quotas can change; if this model isn't available, try `gemini-3.5-flash-lite` in the configuration.

Google says some free-tier prompts and responses may be used to improve its products. Avoid discussing confidential customer details, private event attendee information, passwords, or other sensitive data in interviews. Browser speech recognition can also use your browser vendor's servers to transcribe audio.

## 2. Upload to Hostinger

1. Extract this ZIP.
2. Upload the **contents** to `public_html/interview-studio/` (or whatever folder you want).
3. Edit `private/config.php`:

   ```php
   'gemini_api_key' => 'YOUR_KEY_FROM_GOOGLE_AI_STUDIO',
   'studio_password' => 'A_LONG_UNIQUE_PASSWORD',
   'gemini_model' => 'gemini-3.8-flash',
   ```

   Alternatively you can set the server-side environment variable `GEMINI_API_KEY`, if your hosting supports it.
4. Enable PHP 8.1 or newer and HTTPS. The host must allow outbound requests to `generativelanguage.googleapis.com`. PHP needs OpenSSL and should have cURL or HTTPS URL wrappers enabled.
5. Go to `https://yourdomain.com/interview-studio/`, sign in, and try **Start talking** in Chrome on a desktop.

**Security warning:** `private/config.php` holds your API key and password. The `private/.htaccess` blocks direct web access on Apache / LiteSpeed servers. Verify that `https://yourdomain.com/interview-studio/private/config.php` is blocked. If your server ignores `.htaccess`, move the private directory outside the web root and update the `require` paths before using the app. Never upload API keys to a public Git repository.

## 3. Use it

- Choose **Start talking**. The interviewer speaks its opening question, the browser listens for your answer, and Gemini selects a specific follow-up.
- The default **Automatically listen after each question** provides a near-hands-free experience. You can turn this off if your speakers feed back into the microphone. Use **Finish answer** to end a turn early.
- If speech recognition is unsupported or mishears you, switch to **Use a text interview**. You can edit the transcript in the right-hand panel.
- When the story has substance, click **Evaluate this interview**. The editorial check can say **Post**, **Dig deeper**, or **Skip**.
- Generate a draft, edit it, rate it 1–5, add specific feedback, regenerate, and save/export.

### Limitations and costs

- This is a **turn-based conversation**, not the low-latency full-duplex Gemini Live API. It speaks one question, listens for one response, then thinks. Interruptions during its speech are not yet natural.
- Chrome desktop tends to have the best `SpeechRecognition` support. Other browsers may require you to type instead. Transcription and synthetic voice depend on the browser and OS; voice quality and availability will vary.
- Browser speech recognition may send your audio to a browser vendor's speech service; it is **not guaranteed to be local**.
- The Gemini API has a **free tier with rate limits**, not unlimited free usage. Access and per-project quota vary. An API key is still required. Keep your Google AI Studio project on the free tier if you want to avoid usage billing.
- Your transcripts and drafts remain in your browser **only if you explicitly save them**. The text of your interview is sent to Gemini when it requests follow-ups, grades, or drafts. Export a JSON backup to avoid losing work.
- Your AI-produced writing and its insight score still require your judgment. This system does not predict engagement or automatically publish posts.

## 4. Test locally

From the extracted directory, if PHP is installed:

```bash
php -S localhost:8080
```

Visit `http://localhost:8080/`. Browsers consider `localhost` secure for the microphone, but normal hosting requires HTTPS.

## Files

- `index.php` — password login and interface
- `api.php` — authenticated Gemini actions: `ask`, `evaluate`, `draft`
- `private/config.php` — your key and password (server-only)
- `private/bootstrap.php` — secure session, prompts, Gemini REST integration
- `assets/app.js` — speech recognition, speech synthesis, transcript, client workflow
- `assets/app.css` — styling

## API documentation

- Gemini free-tier pricing: https://ai.google.dev/gemini-api/docs/pricing
- Gemini rate limits: https://ai.google.dev/gemini-api/docs/rate-limits
- API keys: https://ai.google.dev/gemini-api/docs/api-key
- REST text generation: https://ai.google.dev/gemini-api/docs/generate-content/text-generation

**Verification note:** PHP lint, JavaScript syntax, and local page/login/security smoke tests were completed during development. Live Gemini requests with your personal API key and live browser microphone conversations require testing after deployment.

## Voice reliability notes (v0.2)

The status chip shows **Mic off**, **Listening**, **Recognizing your words**, **Speaking**, **Thinking**, or **Voice error**. Live speech is shown on-screen before submission. Permission to use the microphone is not the same as permission for the Chrome speech recognition service. If Chrome reports `network` or `service-not-allowed`, browser speech recognition may be blocked by network, browser policy, or a provider service even though the microphone itself works. Text mode remains available. The interviewer voice is chosen from the voices installed in your browser/operating system, so quality varies; the Preview button lets you compare them. This is still free browser synthesis, not premium neural voice generation.

To update an existing install, back up `private/config.php` and replace **index.php**, **api.php**, **private/bootstrap.php**, **assets/app.js**, and **assets/app.css**. Retain your original config key and password.
