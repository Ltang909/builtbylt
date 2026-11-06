<?php
/**
 * Ad image templates (Updates 10.8 + 10.9) — templates only, no
 * free-form art.
 *
 * Leon's call, 2026-11-01: "Templates only, 1:1 for now — kill
 * free-form art." Image generation no longer lets the copy model
 * invent a composition per creative. Each workspace has one or more
 * fixed templates (complete image prompts with {{SLOT}} markers);
 * the operator picks one in the generate form, the panel fills the
 * slots from the creative's lint-checked copy fields, and the result
 * is the entire image prompt. The only free text the copy model
 * still writes is the one-line product visual.
 *
 * Template resolution: a per-workspace setting ('image_template')
 * wins when set (a single custom template); otherwise the built-in
 * map below; otherwise the workspace has none — and none is a hard
 * stop in generate_image.php, never a silent fallback to free-form
 * art.
 *
 * Update 10.9 adds the second CodeRabbit template (CR-PRODUCT-001,
 * the product-UI showcase), its extra slots, the real brand logo as
 * a reference image (taken from coderabbit.ai, never invented), and
 * per-workspace slot defaults (logo description, social proof,
 * integrations — settings win, built-ins are the fallback, and an
 * unset social proof renders as an explicit NONE instruction so the
 * image model omits the section instead of inventing a claim).
 */
require_once __DIR__ . '/settings.php';

/** Leon's dark statement template, pasted 2026-11-01 (verbatim). */
function ad_template_dark_text(): string {
    return <<<'TPL'
Create a premium, high-impact B2B developer software advertisement in a square 1080 × 1080 pixel format.

**DESIGN SYSTEM**

- Canvas: 1080 × 1080 px, 1:1.
- Background: near-black charcoal (#171717), with a very subtle repeating pattern of tiny X marks and horizontal dashes in #222222 at approximately 10% opacity. Texture should be barely visible.
- Primary text: pure white (#FFFFFF).
- Accent: vivid orange (#FF5A0A).
- Secondary text: light gray (#D1D1D1).
- Primary font: Inter Tight or Helvetica Neue, ExtraBold (800–900), with tight letter spacing (-3%).
- Secondary font: Inter or Helvetica Neue, Regular (400).
- CTA font: bold monospaced, approximately 22 px, uppercase.
- Style: precise, high-contrast, editorial, developer-centric, polished commercial advertising.

**EXACT COMPOSITION**

1. **Brand header (top 7–14%):** Center a small white brand icon alongside a bold white wordmark. The icon and wordmark should form one horizontally aligned group.

2. **Hero headline (19–39%):** Display an enormous, bold, white headline, centered horizontally, approximately 90–105 px. Allow two lines, maximum three. Keep line height tight at 0.95–1.05. This is the strongest visual element on the canvas.

3. **Subheadline (40–47%):** Place a lighter-weight white supporting statement beneath the headline, centered, approximately 48–55 px.

4. **CTA button (51–60%):** Create a large bright-orange pill-shaped button, horizontally centered, approximately 325 × 80 px. Use bold uppercase monospaced text and a small dark right-arrow icon. No shadow, gradient, or 3D treatment.

5. **Product window (65–110%):** A large near-black rectangular developer-console panel with a thin 1.5 px orange border and rounded top corners (16 px). Position it so its bottom extends beyond the canvas and gets cropped naturally. Include a thin orange horizontal divider near the top, three tiny red/yellow/green window controls, and one large central product-related visual or oversized pixel-art typography. Use a restrained orange-to-coral gradient only inside the panel's focal graphic.

**VISUAL RULES**

- Strictly retain the element positions, hierarchy, relative scale, typography, colors, and spacing across all generated variations.
- Maintain clean negative space between major elements.
- Do not introduce clip-art, emoji, generic software illustrations, floating 3D shapes, photography, stock imagery, glossy effects, or decorative gradients.
- The result must look like an expensive campaign created by an experienced software brand design team, not a generic SaaS template.
- Do not redesign the composition to accommodate new text. Adjust type size slightly within its allocated region instead.
- Use only the exact words supplied below, with no invented claims.

**VARIABLE CONTENT**

Brand: {{BRAND}}

Headline: {{HEADLINE}}

Subheadline: {{SUBHEADLINE}}

CTA: {{CTA}}

Product window content: {{PRODUCT_VISUAL}}

**OUTPUT**

Produce one finished, pixel-sharp, 1080 × 1080 ad with accurate text and a consistent, reusable visual identity.
TPL;
}

/** CR-PRODUCT-001: the product-UI showcase, pasted 2026-11-01. */
function ad_template_product_text(): string {
    return <<<'TPL'
Create a premium B2B developer software advertisement in a square 1080 × 1080 pixel format.

The design must resemble a sophisticated, professionally art-directed developer tools campaign. It should combine editorial typography, a dark engineering-grid background, an authentic-looking software interface, and a high-contrast call to action.

**DESIGN SYSTEM**

- Canvas: 1080 × 1080 px, 1:1 aspect ratio.
- Background: deep near-black with a subtle violet undertone (#0D0B12).
- Grid: fine, evenly spaced square grid lines, approximately 12–14 px apart, using dark burnt orange (#51251D) at 35–45% opacity.
- Grid treatment: strongest in the top half, progressively fading toward pure near-black at the bottom. Use a soft radial fade around the edges.
- Primary text: pure white (#FFFFFF).
- Secondary text: muted lavender-gray (#AAA6B3).
- Primary accent: warm coral orange (#FF744F).
- Dark UI panels: #17151C and #100F14.
- UI borders: dark gray-purple (#39333F).
- Typeface: Helvetica Neue, Inter, or Arial, with modern geometric proportions.
- Headline weight: Medium/Semibold (500–600), not ExtraBold.
- Headline tracking: tight (-2%).
- UI typography: Inter Regular and Medium, with monospace for code.
- Supporting text typography: light-weight monospace, generously tracked.
- Overall aesthetic: precise, minimal, technical, mature, and high-end.

**EXACT COMPOSITION**

### 1. Brand Header (Top 4–12%)

Center the brand logo and wordmark near the top of the canvas.

- Logo icon: solid coral-orange circular badge, approximately 65 × 65 px.
- Inside the badge, display a clean black brand symbol.
- Wordmark: bold white sans-serif, approximately 45–50 px.
- Arrange icon and wordmark horizontally with approximately 20 px spacing.
- Center the complete lockup.
- No enclosing card, glow, shadow, or divider.

### 2. Hero Headline (Top 15–31%)

Display a large white headline centered across the canvas.

- Headline font size: approximately 64–72 px.
- Weight: 500–600.
- Tight line height: 1.15.
- Maximum two lines.
- Text must feel large, confident, and editorial.
- Width: approximately 90% of the canvas.
- Allow generous space beneath the brand lockup.
- Avoid bolding individual words or using accent colors within the headline.
- Do not add a subheadline in this section.

### 3. Product Interface Showcase (Top 35–77%)

Create a large, realistic product interface that occupies approximately 82% of the canvas width.

This is the main visual centerpiece of the advertisement.

**Interface container:**

- Dark charcoal application panel (#17151C).
- Subtle 1 px border (#39333F).
- Minimal corner radius: 6–8 px.
- No drop shadows, glossy reflections, or 3D effects.
- Slightly offset to the right, with a small floating square product icon overlapping the upper-left boundary.
- The floating icon has a coral-orange background with a white brand symbol.

**Product interface layout:**

- Top header row with an avatar, username, small metadata labels, and activity timestamp.
- Thin divider beneath the header.
- A compact dark-gray action button with a small coral icon and white text.
- A short status row with a warning indicator and severity label.
- Bold white technical finding or recommendation headline.
- One or two lines of muted gray explanatory text.
- A realistic monospace code-diff panel below.

**Code-diff treatment:**

- Dark background (#17151C).
- Removed code lines on dark muted red (#35191D).
- Added code lines on dark muted green (#142820).
- Code text in gray monospace, with small red/green indicators.
- No excessive syntax colors.
- Accurate alignment and believable developer-interface structure.

The interface should look like a real product screenshot, not an abstract illustration.

Apply a subtle vertical fade toward the bottom of the interface so its lowest elements naturally dissolve into the dark background.

### 4. Social Proof Statement (Top 79–87%)

Position a short trust or adoption statement below the product screenshot.

- Center horizontally.
- White monospace typography.
- Approximately 35–39 px.
- Generous character spacing.
- Single line preferred.
- Examples of visual structure: "Used by 150K+ projects" or "Trusted by engineering teams".
- Only use the exact verified statement provided in the variables.

### 5. Bottom Row: Integrations and CTA (Top 89–97%)

Construct a horizontal footer with two distinct visual zones.

**Left zone:**

- Two integration or platform logos arranged horizontally.
- Approximately 44–50 px icon height.
- White brand names where applicable.
- Natural spacing between logos.
- No containers or borders.
- Use only integrations specified in the variables.

**Right zone:**

- Large rectangular coral-orange CTA button (#FF744F).
- Approximately 430 × 76 px.
- Corner radius: 3–4 px, nearly square.
- Dark near-black text (#100F14).
- Uppercase modern sans-serif, approximately 31–35 px, weight 500.
- No arrow, icon, shadow, gradient, or glow.
- Align vertically with the integration logos.
- Maintain at least 55 px horizontal canvas margins.

**VISUAL RULES**

1. Maintain identical structural proportions, visual hierarchy, grid density, typography, colors, margins, and layout across all variations.

2. The top half should feel typographic and spacious. The middle should be product-focused. The bottom should be compact and conversion-oriented.

3. Keep the orange grid visible through the headline area, gradually fading behind the product window and disappearing near the footer.

4. Do not introduce stock photography, clip-art, cartoon illustrations, 3D graphics, unnecessary icons, floating geometric shapes, neon effects, or gradient text.

5. Do not introduce cards or decorative containers beyond the realistic product interface.

6. Do not add any invented text, metrics, product claims, integration names, or interface labels.

7. All text must be clearly readable and correctly spelled.

8. Do not redesign the composition for different messaging. Adjust font size or line breaks only when necessary to maintain the established layout.

9. This must look like a cohesive commercial advertising campaign, not a generic AI-generated SaaS advertisement.

**VARIABLE CONTENT**

Brand: {{BRAND}}

Brand logo description: {{LOGO_DESCRIPTION}}

Headline: {{HEADLINE}}

Product interface type: {{PRODUCT_INTERFACE_TYPE}}

Product UI content: {{PRODUCT_UI_CONTENT}}

Social proof statement: {{SOCIAL_PROOF}}

Integration logos: {{INTEGRATIONS}}

CTA: {{CTA}}

**OUTPUT**

Generate one finished, pixel-sharp, square 1080 × 1080 advertisement.

Use the exact design system and composition above.

Preserve the premium dark developer-tools aesthetic, orange technical grid, large editorial headline, authentic product UI showcase, and coral rectangular CTA.

The result should feel like another creative from the same established brand campaign, even when all textual content changes.
TPL;
}

/**
 * BB-TOOLBOX-002: the pink tin toolbox, from Leon's reference
 * design (2026-11-06). Brand-level square: both cats peeking
 * over a hot-pink toolbox of tool cards.
 */
function ad_template_bumbum_toolbox_text(): string {
    return <<<'TPL'
Create a polished social ad for Leave It To Bum Bum in a square 1024 x 1024 pixel format (1:1). Photorealistic cats, playful premium pet-brand campaign.

**DESIGN SYSTEM**

- Canvas: 1024 x 1024 px, 1:1 square. Keep everything important at least 50 px from every edge.
- Palette: warm cream backdrop (#FBF3E4) with large soft pink blob shapes (#F7C9D4), hot pink #EC4E8F for the toolbox, black ink #17150F for the headline serif, pale yellow #FCE9A8 highlighter strip, chartreuse green #B5E048 for the CTA pill.
- Type: Fraunces bold serif for the headline, subheadline and CTA.
- Lighting: bright, warm, commercial. A pale marble surface at the bottom.

**THE CATS (character consistency is the point of this template)**

- Two REAL cats, photographed, never illustrated. The main cat is the exact cat in the attached reference image: a cream and light silver-grey British Shorthair with a round face, very large round blue-green eyes and a pink nose. Beside him, a fluffy long-haired white Persian cat with green eyes and a pink nose. The same two cats in every variation, no other animals.

**EXACT COMPOSITION**

1. **Brand header (top 2-9%):** centered: a small circular badge with the main cat's face and a tiny gold crown with little gold rays, beside "LEAVE IT TO" in small black capitals over "Bum Bum" in large bold black Fraunces serif.

2. **Headline (11-30%):** the headline text in enormous black Fraunces serif across two lines; the second line sits on a pale yellow highlighter strip.

3. **Subheadline (30-36%):** the subheadline in black Fraunces serif, one line, centered.

4. **Hero photo (36-82%):** both cats peek over the rim of an open hot-pink metal toolbox, front paws resting on the rim, looking into the camera. The toolbox front reads "Bum Bum's Toolbox" in white friendly script between two white paw prints. Standing inside the box, four cream tool cards with small colourful illustrations, labelled exactly: "Meal Scanner", "Poster to Calendar", "Ghostwriter", "And More!".

5. **CTA (86-95%):** one wide chartreuse pill with a thin black outline, centered, holding the CTA text in bold black Fraunces serif in title case (first letter capitalised, the rest lower case, never all capitals), followed by a simple right arrow, with small hot-pink sparkle bursts at both ends of the pill.

6. **Accents:** one small pink heart outline near the cats. Nothing else decorative.

**VISUAL RULES**

- Keep positions, hierarchy, relative scale, palette and typography identical across all variations; only the supplied headline, subheadline and CTA words change.
- Use only the exact words supplied below, the toolbox lettering and the four fixed card labels. No invented claims, prices or metrics, and no other text anywhere.
- Photorealistic cats matching the descriptions and the reference exactly.

**VARIABLE CONTENT**

Brand: {{BRAND}}

Headline: {{HEADLINE}}

Subheadline: {{SUBHEADLINE}}

CTA: {{CTA}}

**OUTPUT**

Produce one finished, pixel-sharp, 1024 x 1024 square ad.
TPL;
}

/**
 * BB-HEARSAY-001: the lavender chat design, from Leon's
 * reference (2026-11-06), for the AI Mention Tracker. The cat
 * listens at a brass ear trumpet; chat bubbles and a scan card.
 */
function ad_template_bumbum_hearsay_text(): string {
    return <<<'TPL'
Create a polished social ad for Leave It To Bum Bum in a square 1024 x 1024 pixel format (1:1). Photorealistic cat, playful premium pet-brand campaign.

**DESIGN SYSTEM**

- Canvas: 1024 x 1024 px, 1:1 square. Keep everything important at least 50 px from every edge.
- Palette: soft lavender backdrop (#D9CFF5) with lighter lilac blob shapes, ink #17150F for the headline serif, purple #8B5CF6 accents, chartreuse green #B5E048 for the CTA pill, hot pink #EF5DA8 small accents.
- Type: Fraunces bold serif for the headline, subheadline and CTA; clean sans inside the UI card.
- Lighting: bright, soft, commercial.

**THE CAT (character consistency is the point of this template)**

- The hero is a REAL PHOTOGRAPH of the exact cat in the attached reference image: a cream and light silver-grey British Shorthair with a round face, very large round blue-green eyes, a pink nose and plush short fur. Same cat, same face, same markings in every variation. Never an illustration, never a different cat, no other animals.

**EXACT COMPOSITION**

1. **Brand header (top 2-9%):** centered: a small circular badge with the cat's face and a tiny gold crown with little gold rays, beside "LEAVE IT TO" in small black capitals over "Bum Bum" in large bold black Fraunces serif.

2. **Headline (11-26%):** the headline text in enormous black Fraunces serif, one word or a short phrase, left of center.

3. **Subheadline (26-36%):** the subheadline in black Fraunces serif, two lines, with a short yellow hand-drawn underline beneath its last word or two.

4. **Hero photo (36-84%):** the cat fills the left half, close up, holding an ornate brass ear trumpet to his ear with one paw, head tilted, looking up into the camera. Down the right side, three rounded chat bubbles (white and pale lavender) each with a coloured avatar dot (purple, blue, light blue) and two or three soft grey text lines. Beneath the bubbles, a white rounded UI card: the small brand lockup at its top, a bold title taken from the CTA words, a small "Brand name" label, and an input field holding a sample small-business name in grey.

5. **CTA (86-95%):** one wide chartreuse pill with a thin black outline at the bottom left-to-center, holding the CTA text in bold black Fraunces serif in title case (first letter capitalised, the rest lower case, never all capitals), followed by a simple right arrow.

6. **Accents:** small purple sparkle bursts and one pink heart outline, sparingly placed.

**VISUAL RULES**

- Keep positions, hierarchy, relative scale, palette and typography identical across all variations; only the supplied words and the scene prop in the variables change.
- Use only the exact words supplied below, plus the brand wordmark and generic UI labels. No invented claims, prices, metrics or extra text anywhere.
- Photorealistic cat matching the reference exactly; no cartoon version.

**VARIABLE CONTENT**

Brand: {{BRAND}}

Headline: {{HEADLINE}}

Subheadline: {{SUBHEADLINE}}

CTA: {{CTA}}

Scene and prop: {{PRODUCT_VISUAL}}

**OUTPUT**

Produce one finished, pixel-sharp, 1024 x 1024 square ad.
TPL;
}

/**
 * BB-POSTER-001: the sky-blue design, from Leon's reference
 * (2026-11-06), for Poster to Calendar. Both cats, a phone
 * showing an event poster, a calendar card.
 */
function ad_template_bumbum_poster_text(): string {
    return <<<'TPL'
Create a polished social ad for Leave It To Bum Bum in a square 1024 x 1024 pixel format (1:1). Photorealistic cats, playful premium pet-brand campaign.

**DESIGN SYSTEM**

- Canvas: 1024 x 1024 px, 1:1 square. Keep everything important at least 50 px from every edge.
- Palette: sky blue backdrop (#A9D7F2) with soft white cloud blobs, dark navy ink #1B2A4A for the headline serif, pale yellow #FCE9A8 highlighter strip, royal blue #2456C8 accents and button, chartreuse green #B5E048 for the CTA pill.
- Type: Fraunces bold serif for the headline, subheadline and CTA; clean sans inside the phone and the calendar card.
- Lighting: bright, cheerful, commercial. One green leaf sprig in the top right corner.

**THE CATS (character consistency is the point of this template)**

- Two REAL cats, photographed, never illustrated. The main cat is the exact cat in the attached reference image: a cream and light silver-grey British Shorthair with a round face, very large round blue-green eyes and a pink nose. In front of him, a fluffy long-haired white Persian cat with green eyes and a pink nose. The same two cats in every variation, no other animals.

**EXACT COMPOSITION**

1. **Brand header (top 2-9%):** centered: a small circular badge with the main cat's face and a tiny gold crown with little gold rays, beside "LEAVE IT TO" in small black capitals over "Bum Bum" in large bold dark navy Fraunces serif.

2. **Headline (10-29%):** the headline text in enormous dark navy Fraunces serif across two lines.

3. **Subheadline (29-35%):** the subheadline in black Fraunces serif on a pale yellow highlighter strip, one line.

4. **Hero photo (35-84%):** on the left, the main cat sits upright holding a smartphone toward the camera; the phone shows a colourful illustrated poster for a sample local evening market event (string lights, stalls, the event name in bold friendly type, a sample date and place in small type). The white cat lies in front, looking up at the camera. On the right, a white rounded calendar card: a calendar icon, the same sample event name in bold, three rows with small icons (a sample date, a sample time range, a sample place), and a royal blue "Save to Calendar" button with a small calendar-plus icon.

5. **CTA (86-95%):** one wide chartreuse pill with a thin black outline, centered, holding the CTA text in bold black Fraunces serif in title case (first letter capitalised, the rest lower case, never all capitals), followed by a simple right arrow, with small blue sparkle bursts at both ends.

6. **Accents:** short blue dash bursts around the headline and one white heart outline near the cats. Nothing else decorative.

**VISUAL RULES**

- Keep positions, hierarchy, relative scale, palette and typography identical across all variations; only the supplied words change. The event details on the phone and the card are sample UI content: keep them generic, plausible and clearly a demo (a local market, a Saturday evening), never a real claim about the brand.
- Use only the exact words supplied below, plus the brand wordmark, the "Save to Calendar" button label and the sample event details. No invented claims, prices or metrics anywhere else.
- Photorealistic cats matching the descriptions and the reference exactly.

**VARIABLE CONTENT**

Brand: {{BRAND}}

Headline: {{HEADLINE}}

Subheadline: {{SUBHEADLINE}}

CTA: {{CTA}}

**OUTPUT**

Produce one finished, pixel-sharp, 1024 x 1024 square ad.
TPL;
}

/**
 * BB-PURRCODE-001: the pink QR design, from Leon's reference
 * (2026-11-06), for the QR Code Generator. The cat presents a
 * big styled QR board beside a link-and-colors UI card.
 */
function ad_template_bumbum_purrcode_text(): string {
    return <<<'TPL'
Create a polished social ad for Leave It To Bum Bum in a square 1024 x 1024 pixel format (1:1). Photorealistic cat, playful premium pet-brand campaign.

**DESIGN SYSTEM**

- Canvas: 1024 x 1024 px, 1:1 square. Keep everything important at least 50 px from every edge.
- Palette: warm cream backdrop (#FBF3E4) with soft pink blob shapes (#F7C9D4) and a pink desk surface, ink #17150F for the headline serif, warm orange #E85D26 for the QR pattern, hot pink #EF5DA8 accents, chartreuse green #B5E048 for the CTA pill.
- Type: Fraunces bold serif for the headline, subheadline and CTA; clean sans inside the UI card.
- Lighting: bright, warm, commercial. A potted green plant at each side edge.

**THE CAT (character consistency is the point of this template)**

- The hero is a REAL PHOTOGRAPH of the exact cat in the attached reference image: a cream and light silver-grey British Shorthair with a round face, very large round blue-green eyes, a pink nose and plush short fur. Same cat, same face, same markings in every variation. Never an illustration, never a different cat, no other animals.

**EXACT COMPOSITION**

1. **Brand header (top 2-9%):** centered: a small circular badge with the cat's face and a tiny gold crown with little gold rays, beside "LEAVE IT TO" in small black capitals over "Bum Bum" in large bold black Fraunces serif.

2. **Headline (11-30%):** the headline text in enormous black Fraunces serif across two lines at the left; if the headline contains the word "Code", its letter "o" is a pink paw print.

3. **Subheadline (30-38%):** the subheadline in black Fraunces serif across two lines on a torn pale-pink paper strip.

4. **Hero photo (34-84%):** the cat sits on the right at the pink desk, both front paws resting on top of a large cream rounded board he presents to the camera: a stylised QR code in warm orange dots, its three corner squares outlined in orange each holding a pink heart, and at its center the small crowned cat-face badge. On the left, a white rounded UI card: a "Your link" label over a field showing "https://leaveittobumbum.com", a "Colors" label over a row of seven small colour swatches (orange, pink, green, yellow, blue, lavender, black), and "Ink" and "Paper" selector chips. A small stack of pink books at the bottom left.

5. **CTA (86-95%):** one wide chartreuse pill with a thin black outline, centered, holding the CTA text in bold black Fraunces serif in title case (first letter capitalised, the rest lower case, never all capitals), followed by a simple right arrow, with small pink sparkle bursts at both ends.

6. **Accents:** one pink heart outline above the cat. Nothing else decorative.

**VISUAL RULES**

- Keep positions, hierarchy, relative scale, palette and typography identical across all variations; only the supplied words change.
- Use only the exact words supplied below, plus the brand wordmark, the site URL and the generic UI labels named above. No invented claims, prices, metrics or extra text anywhere.
- Photorealistic cat matching the reference exactly; no cartoon version.

**VARIABLE CONTENT**

Brand: {{BRAND}}

Headline: {{HEADLINE}}

Subheadline: {{SUBHEADLINE}}

CTA: {{CTA}}

**OUTPUT**

Produce one finished, pixel-sharp, 1024 x 1024 square ad.
TPL;
}

/** The workspace's default (first) built-in template, or null. */
function ad_template_builtin(int $wsid): ?string {
    if ($wsid === 3) {
        return ad_template_dark_text();
    }
    if ($wsid === 4) {
        return ad_template_bumbum_toolbox_text();
    }
    return null;
}

/**
 * All templates available to a workspace, as an ordered map of
 * key => ['name' => ..., 'text' => ...]. A non-empty
 * 'image_template' setting replaces the built-ins with a single
 * custom template.
 */
function ad_templates_for(PDO $pdo, int $wsid): array {
    $fromSettings = get_setting($pdo, $wsid, 'image_template');
    if ($fromSettings !== null && trim($fromSettings) !== '') {
        return ['custom' => ['name' => 'Workspace template',
                             'text' => $fromSettings]];
    }
    if ($wsid === 3) {
        return [
            'dark' => ['name' => 'Dark — statement',
                       'text' => ad_template_dark_text()],
            'product' => ['name' => 'Product UI showcase (CR-PRODUCT-001)',
                          'text' => ad_template_product_text()],
        ];
    }
    if ($wsid === 4) {
        // Leon's four reference designs (2026-11-06), all native
        // squares (1254x1254 files): no size on any entry, so
        // generation uses the standard square canvas, masters
        // push to Google as true 1:1 files, and the landscape
        // variant flow applies as usual. (These replaced the
        // same-day portrait pair at Leon's word.)
        return [
            'toolbox' => ['name' => 'Toolbox — pink tin (square)',
                          'text' => ad_template_bumbum_toolbox_text()],
            'hearsay' => ['name' => 'Hearsay — lavender chat (square)',
                          'text' => ad_template_bumbum_hearsay_text()],
            'poster' => ['name' => 'Poster to Calendar — sky blue (square)',
                         'text' => ad_template_bumbum_poster_text()],
            'purrcode' => ['name' => 'Purr Code — pink QR (square)',
                           'text' => ad_template_bumbum_purrcode_text()],
        ];
    }
    return [];
}

/**
 * The generation size of the template a key resolves to (same
 * resolution as ad_template_text), or null when the template
 * carries no size — the caller falls back to the square canvas.
 */
function ad_template_size(PDO $pdo, int $wsid, ?string $key): ?string {
    $templates = ad_templates_for($pdo, $wsid);
    if ($templates === []) {
        return null;
    }
    if ($key !== null && isset($templates[$key])) {
        return $templates[$key]['size'] ?? null;
    }
    $first = reset($templates);
    return $first['size'] ?? null;
}

/**
 * Resolve one template's text: the requested key when it exists,
 * otherwise the workspace's first template, otherwise null.
 */
function ad_template_text(PDO $pdo, int $wsid, ?string $key): ?string {
    $templates = ad_templates_for($pdo, $wsid);
    if ($templates === []) {
        return null;
    }
    if ($key !== null && isset($templates[$key])) {
        return $templates[$key]['text'];
    }
    $first = reset($templates);
    return $first['text'];
}

/** The workspace's default template text (Update 10.8 contract). */
function workspace_image_template(PDO $pdo, int $wsid): ?string {
    return ad_template_text($pdo, $wsid, null);
}

/**
 * Real brand-logo files for the workspace (Update 10.9), as absolute
 * paths that exist on disk. The CodeRabbit files in panel/assets/
 * were taken from coderabbit.ai itself (the apple-touch-icon mark
 * and the site's own logo lockup, rasterized) — the image model
 * receives them as reference images instead of inventing a logo.
 */
function workspace_logo_files(PDO $pdo, int $wsid): array {
    $map = [
        3 => ['assets/logo-coderabbit-icon.png',
              'assets/logo-coderabbit-lockup.png'],
        // Leaveittobumbum has no logo lockup file (the site header
        // mark is styled text); the reference is the brand's real
        // cat photo from leaveittobumbum.com's own public assets.
        4 => ['assets/logo-bumbum-photo.png'],
    ];
    $out = [];
    foreach ($map[$wsid] ?? [] as $rel) {
        $path = PANEL_ROOT . '/' . $rel;
        if (is_file($path)) {
            $out[] = $path;
        }
    }
    return $out;
}

/**
 * Standing slot values that are brand facts, not per-creative copy:
 * LOGO_DESCRIPTION, SOCIAL_PROOF, INTEGRATIONS. A workspace setting
 * wins; built-ins are the fallback; null means "not set" — the
 * caller turns a null social proof / integrations value into an
 * explicit NONE instruction so the image model omits the section
 * rather than inventing a claim.
 */
function ad_slot_defaults(PDO $pdo, int $wsid): array {
    $builtins = [
        3 => [
            // Written from the real mark (fetched from
            // coderabbit.ai and inspected): an orange circle with a
            // white rabbit silhouette. Template CR-PRODUCT-001 says
            // "black brand symbol" for its badge; the attached
            // reference images govern — the real mark is white.
            'LOGO_DESCRIPTION' => 'the CodeRabbit logo: a solid '
                . 'orange (#FF570A) circle containing a white rabbit '
                . 'silhouette in profile, sitting and facing right, '
                . 'with two long upright ears; beside it the word '
                . 'CodeRabbit in bold white sans-serif. Use the '
                . 'attached reference images as the exact logo.',
            'SOCIAL_PROOF' => null,
            'INTEGRATIONS' => 'GitHub and GitLab',
        ],
        4 => [
            'LOGO_DESCRIPTION' => 'the Leave It To Bum Bum '
                . 'lockup: a small circular badge with the real '
                . 'cat\'s face (cream British Shorthair, very '
                . 'large round blue-green eyes) wearing a tiny '
                . 'gold crown, beside the words LEAVE IT TO in '
                . 'small black capitals over Bum Bum in large bold '
                . 'black serif. Use the attached reference photo '
                . 'for the cat\'s exact face.',
            'SOCIAL_PROOF' => null,
            'INTEGRATIONS' => null,
        ],
    ];
    $base = $builtins[$wsid] ?? [
        'LOGO_DESCRIPTION' => null,
        'SOCIAL_PROOF' => null,
        'INTEGRATIONS' => null,
    ];
    $keys = ['LOGO_DESCRIPTION' => 'logo_description',
             'SOCIAL_PROOF' => 'social_proof',
             'INTEGRATIONS' => 'integrations'];
    foreach ($keys as $slot => $settingKey) {
        $v = get_setting($pdo, $wsid, $settingKey);
        if ($v !== null && trim($v) !== '') {
            $base[$slot] = trim($v);
        }
    }
    return $base;
}

/** Fill a template's slots from a creative's copy fields. */
function build_template_image_prompt(string $template,
                                     array $slots): string {
    $map = [];
    foreach (['BRAND', 'HEADLINE', 'SUBHEADLINE', 'CTA',
              'PRODUCT_VISUAL', 'LOGO_DESCRIPTION',
              'PRODUCT_INTERFACE_TYPE', 'PRODUCT_UI_CONTENT',
              'SOCIAL_PROOF', 'INTEGRATIONS'] as $key) {
        $map['{{' . $key . '}}'] = (string)($slots[$key] ?? '');
    }
    return strtr($template, $map);
}
