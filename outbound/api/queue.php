<?php
/**
 * Outreach queue endpoint: the human review gate for every message.
 *   GET  queue.php?client_id=N[&status=pending]
 *        Messages awaiting review, with the match score and the
 *        signal evidence (source URL included) that justifies them.
 *   POST queue.php?action=generate {client_id, match_id, channel,
 *        tone, campaign_id?, confirmed}
 *        Estimate first, generate after confirmation. The drafting
 *        prompt is built only from stored data and forbids inventing
 *        connections, events, outcomes, or signals.
 *   POST queue.php?action=compose  {client_id, match_id, channel,
 *        subject, body}  Manual message through the same save path.
 *   POST queue.php?action=approve|reject|edit|regenerate {id, ...}
 *   POST queue.php?action=bulk_approve {ids[]}
 *   POST queue.php?action=mark_sent {id, external_id?}
 *        Duplicate send guard + per-client daily sending limit.
 *   POST queue.php?action=gmail_draft {id}
 *        Creates a Gmail draft when a Gmail token is stored for the
 *        client; otherwise returns setup instructions. Nothing sends
 *        without approval, ever.
 */
require __DIR__ . '/bootstrap.php';

/** Everything the drafting prompt is allowed to know. */
function queue_context(PDO $pdo, int $clientId, int $matchId): ?array {
    $stmt = $pdo->prepare(
        'SELECT m.*, co.name AS company_name, co.domain AS company_domain, '
        . 'co.website AS company_website, ct.id AS ct_id, '
        . 'ct.first_name, ct.last_name, ct.title AS contact_title, '
        . 'ct.email AS contact_email, ct.linkedin_url '
        . 'FROM obe_prospect_matches m '
        . 'JOIN obe_companies co ON co.id = m.company_id '
        . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
        . 'WHERE m.id = ? AND m.client_id = ?');
    $stmt->execute([$matchId, $clientId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($match === false) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_client_profiles WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare(
        'SELECT o.*, s.name AS signal_name FROM obe_signal_observations o '
        . 'JOIN obe_intent_signals s ON s.id = o.signal_id '
        . 'WHERE o.company_id = ? ORDER BY o.observed_at DESC, o.id DESC '
        . 'LIMIT 1');
    $stmt->execute([(int)$match['company_id']]);
    $observation = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'match' => $match,
        'profile' => $profile === false ? null : $profile,
        'observation' => $observation === false ? null : $observation,
        'settings' => obe_client_settings($pdo, $clientId),
    ];
}

/** Prompt context, assembled strictly from stored rows. */
function queue_context_text(array $ctx): string {
    $m = $ctx['match'];
    $p = $ctx['profile'];
    $lines = [];
    $lines[] = 'Client product: ' . ($p !== null
        ? (string)($p['product'] ?? '') : '');
    $lines[] = 'Client value proposition: ' . ($p !== null
        ? (string)($p['value_prop_summary'] ?? '') : '');
    if ($p !== null && !empty($p['value_props'])) {
        $props = obe_json_field($p['value_props']);
        if (count($props) > 0) {
            $lines[] = 'Client value points: ' . implode('; ', $props);
        }
    }
    $name = trim((string)($m['first_name'] ?? '') . ' '
                 . (string)($m['last_name'] ?? ''));
    $lines[] = 'Contact: ' . ($name !== '' ? $name : 'Unknown name')
        . ', ' . (string)($m['contact_title'] ?? 'role unknown');
    $lines[] = 'Company: ' . (string)$m['company_name'];
    $lines[] = 'Prospect score: ' . (int)$m['score'] . ' of 100';
    if ($ctx['observation'] !== null) {
        $o = $ctx['observation'];
        $lines[] = 'Detected buying signal: ' . (string)$o['signal_name'];
        $lines[] = 'Signal evidence: ' . (string)$o['evidence'];
        $lines[] = 'Signal source: ' . (string)$o['source_url']
            . ' (observed ' . (string)$o['observed_at'] . ')';
    } else {
        $lines[] = 'Detected buying signal: none recorded. Write a '
            . 'plain introduction that does not claim any signal.';
    }
    $guidelines = '';
    if ($p !== null && !empty($p['writing_guidelines'])) {
        $guidelines = (string)$p['writing_guidelines'];
    } elseif ($ctx['settings']['writing_guidelines'] !== '') {
        $guidelines = $ctx['settings']['writing_guidelines'];
    }
    if ($guidelines !== '') {
        $lines[] = 'Client writing guidelines: ' . $guidelines;
    }
    return implode("\n", $lines);
}

/** Next version number for a match + channel. */
function queue_next_version(PDO $pdo, int $matchId, string $channel): int {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(version), 0) AS v FROM obe_messages '
        . 'WHERE match_id = ? AND channel = ?');
    $stmt->execute([$matchId, $channel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row === false ? 0 : (int)$row['v']) + 1;
}

/** Save a message as pending review; returns the stored row. */
function queue_save_message(PDO $pdo, int $clientId, array $match,
                            ?int $campaignId, string $channel,
                            ?string $subject, string $body,
                            array $inputs): array {
    $version = queue_next_version($pdo, (int)$match['id'], $channel);
    $stmt = $pdo->prepare(
        'INSERT INTO obe_messages (client_id, match_id, campaign_id, '
        . 'contact_id, channel, subject, body, version, status, '
        . 'inputs_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $clientId, (int)$match['id'], $campaignId,
        !empty($match['contact_id']) ? (int)$match['contact_id'] : null,
        $channel, $subject, $body, $version, 'pending',
        json_encode($inputs),
    ]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM obe_messages WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row['id'] = (int)$row['id'];
    $row['version'] = (int)$row['version'];
    return $row;
}

/**
 * Generate one message with the LLM and save it. Assumes the caller
 * already ran the estimate and got the user's confirmation. Throws
 * Exception on failure.
 */
function queue_do_generate(PDO $pdo, int $clientId, int $matchId,
                           string $channel, string $tone,
                           ?int $campaignId, string $provider): array {
    $ctx = queue_context($pdo, $clientId, $matchId);
    if ($ctx === null) {
        throw new Exception('Unknown match_id');
    }
    $contextText = queue_context_text($ctx);
    $wordLimit = max(30, (int)$ctx['settings']['message_length']);
    $system = 'You write outbound prospecting messages for a B2B '
        . 'service. Hard rules: use ONLY the facts in the context. '
        . 'Never invent personal connections, shared events, mutual '
        . 'contacts, customer names, outcomes, metrics, or buying '
        . 'signals. Reference the detected signal and its evidence '
        . 'exactly as given. Tone: ' . $tone . '. Keep the body under '
        . $wordLimit . ' words. One clear call to action: a short '
        . 'introductory call. Return ONLY a JSON object.';
    if ($channel === 'email') {
        $system .= ' Format: {"subject": "...", "body": "..."}.';
    } else {
        $system .= ' This is a LinkedIn message, so no subject. '
            . 'Format: {"body": "..."}.';
    }
    list($text, $tokens) = obe_llm_call($pdo, $provider, $system,
                                        $contextText);
    $parsed = obe_parse_json($text);
    $subject = null;
    $body = '';
    if ($parsed !== null && isset($parsed['body'])) {
        $body = (string)$parsed['body'];
        $subject = isset($parsed['subject'])
            ? (string)$parsed['subject'] : null;
    } else {
        $body = trim($text);
    }
    if ($body === '') {
        throw new Exception('The model returned an empty message');
    }
    obe_usage($pdo, $clientId, $provider, 1, 0.0,
              obe_llm_cost($provider, $tokens),
              'Outreach generation (' . $channel . ')');
    $inputs = [
        'tone' => $tone,
        'channel' => $channel,
        'provider' => $provider,
        'signal_id' => $ctx['observation'] !== null
            ? (int)$ctx['observation']['signal_id'] : null,
        'source_url' => $ctx['observation'] !== null
            ? (string)$ctx['observation']['source_url'] : null,
    ];
    return queue_save_message($pdo, $clientId, $ctx['match'], $campaignId,
                              $channel, $subject, $body, $inputs);
}

/** Shared approval gate: suppression and unsubscribe always block. */
function queue_approve_one(PDO $pdo, int $clientId, int $id): array {
    $stmt = $pdo->prepare(
        'SELECT m.*, ct.email AS contact_email, '
        . 'ct.status AS contact_status FROM obe_messages m '
        . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
        . 'WHERE m.id = ? AND m.client_id = ?');
    $stmt->execute([$id, $clientId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($msg === false) {
        return ['ok' => false, 'error' => 'unknown message id'];
    }
    if (!in_array((string)$msg['status'], ['draft', 'pending'], true)) {
        return ['ok' => false,
                'error' => 'message is ' . (string)$msg['status']
                    . ', not awaiting review'];
    }
    if ((string)($msg['contact_status'] ?? '') === 'unsubscribed') {
        return ['ok' => false,
                'error' => 'contact has unsubscribed; approval blocked'];
    }
    $email = (string)($msg['contact_email'] ?? '');
    if ($email !== '' && obe_suppressed($pdo, $clientId, $email)) {
        return ['ok' => false,
                'error' => 'contact email is on the suppression list; '
                    . 'approval blocked'];
    }
    $upd = $pdo->prepare(
        'UPDATE obe_messages SET status = ? WHERE id = ?');
    $upd->execute(['approved', $id]);
    return ['ok' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $status = (string)($_GET['status'] ?? 'pending');
    if ($status === 'pending') {
        $stmt = $pdo->prepare(
            'SELECT m.*, co.name AS company_name, ct.first_name, '
            . 'ct.last_name, ct.title AS contact_title, '
            . 'ct.email AS contact_email, pm.score AS match_score '
            . 'FROM obe_messages m '
            . 'JOIN obe_prospect_matches pm ON pm.id = m.match_id '
            . 'JOIN obe_companies co ON co.id = pm.company_id '
            . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
            . 'WHERE m.client_id = ? AND m.status IN (\'draft\', \'pending\') '
            . 'ORDER BY m.created_at DESC LIMIT 200');
        $stmt->execute([$clientId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT m.*, co.name AS company_name, ct.first_name, '
            . 'ct.last_name, ct.title AS contact_title, '
            . 'ct.email AS contact_email, pm.score AS match_score '
            . 'FROM obe_messages m '
            . 'JOIN obe_prospect_matches pm ON pm.id = m.match_id '
            . 'JOIN obe_companies co ON co.id = pm.company_id '
            . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
            . 'WHERE m.client_id = ? AND m.status = ? '
            . 'ORDER BY m.created_at DESC LIMIT 200');
        $stmt->execute([$clientId, $status]);
    }
    $obsStmt = $pdo->prepare(
        'SELECT o.evidence, o.source_url, o.observed_at, '
        . 's.name AS signal_name FROM obe_signal_observations o '
        . 'JOIN obe_intent_signals s ON s.id = o.signal_id '
        . 'JOIN obe_prospect_matches pm2 ON pm2.company_id = o.company_id '
        . 'WHERE pm2.id = ? ORDER BY o.observed_at DESC LIMIT 1');
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['version'] = (int)$row['version'];
        $row['match_score'] = (int)$row['match_score'];
        $row['contact_name'] = trim((string)($row['first_name'] ?? '')
            . ' ' . (string)($row['last_name'] ?? ''));
        $obsStmt->execute([(int)$row['match_id']]);
        $obs = $obsStmt->fetch(PDO::FETCH_ASSOC);
        $row['signal'] = $obs === false ? null : $obs;
        $out[] = $row;
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');
$client = obe_client($pdo);
$clientId = (int)$client['id'];

if ($action === 'generate' || $action === 'regenerate') {
    $matchId = 0;
    $channel = 'email';
    $tone = '';
    $campaignId = null;
    if ($action === 'regenerate') {
        $oldId = isset($data['id']) ? (int)$data['id'] : 0;
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_messages WHERE id = ? AND client_id = ?');
        $stmt->execute([$oldId, $clientId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($old === false) {
            json_out(['error' => 'unknown message id'], 400);
        }
        $matchId = (int)$old['match_id'];
        $channel = (string)$old['channel'];
        $campaignId = $old['campaign_id'] !== null
            ? (int)$old['campaign_id'] : null;
        $oldInputs = obe_json_field($old['inputs_json'] ?? null);
        $tone = isset($oldInputs['tone']) ? (string)$oldInputs['tone'] : '';
    } else {
        $matchId = isset($data['match_id']) ? (int)$data['match_id'] : 0;
        $channel = (string)($data['channel'] ?? 'email');
        $campaignId = isset($data['campaign_id'])
            && is_numeric($data['campaign_id'])
            ? (int)$data['campaign_id'] : null;
    }
    if (!in_array($channel, ['email', 'linkedin'], true)) {
        $channel = 'email';
    }
    $ctx = queue_context($pdo, $clientId, $matchId);
    if ($ctx === null) {
        json_out(['error' => 'unknown match_id'], 400);
    }
    if ($tone === '') {
        $tone = isset($data['tone']) && (string)$data['tone'] !== ''
            ? (string)$data['tone']
            : (string)$ctx['settings']['default_tone'];
    }
    $provider = obe_llm_provider($pdo);
    if ($provider === null) {
        json_out(['error' => 'no_llm_key',
                  'message' => 'No LLM key is saved in the panel vault '
                      . '(openai or anthropic), so AI drafting is '
                      . 'unavailable. Use manual compose instead.'], 400);
    }
    if (empty($data['confirmed'])) {
        $promptChars = strlen(queue_context_text($ctx)) + 900;
        json_out(['needs_confirm' => true,
                  'mode' => 'llm',
                  'provider' => $provider,
                  'estimate' => obe_llm_estimate($provider, $promptChars)]);
    }
    try {
        $message = queue_do_generate($pdo, $clientId, $matchId, $channel,
                                     $tone, $campaignId, $provider);
    } catch (Exception $e) {
        json_out(['error' => 'generation_failed',
                  'message' => $e->getMessage()], 502);
    }
    json_out(['message' => $message], 201);
}

if ($action === 'compose') {
    $matchId = isset($data['match_id']) ? (int)$data['match_id'] : 0;
    $ctx = queue_context($pdo, $clientId, $matchId);
    if ($ctx === null) {
        json_out(['error' => 'unknown match_id'], 400);
    }
    $channel = (string)($data['channel'] ?? 'email');
    if (!in_array($channel, ['email', 'linkedin'], true)) {
        $channel = 'email';
    }
    $body = trim((string)($data['body'] ?? ''));
    if ($body === '') {
        json_out(['error' => 'body is required'], 400);
    }
    $subject = isset($data['subject']) ? (string)$data['subject'] : null;
    $campaignId = isset($data['campaign_id'])
        && is_numeric($data['campaign_id'])
        ? (int)$data['campaign_id'] : null;
    $message = queue_save_message($pdo, $clientId, $ctx['match'],
                                  $campaignId, $channel, $subject, $body,
                                  ['manual' => true, 'channel' => $channel]);
    json_out(['message' => $message], 201);
}

if ($action === 'approve') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $result = queue_approve_one($pdo, $clientId, $id);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], 409);
    }
    json_out(['approved' => $id]);
}

if ($action === 'bulk_approve') {
    $ids = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
    $results = [];
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $r = queue_approve_one($pdo, $clientId, $id);
        $results[] = ['id' => $id, 'ok' => $r['ok'],
                      'error' => $r['ok'] ? null : $r['error']];
    }
    json_out(['results' => $results]);
}

if ($action === 'reject') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare(
        'UPDATE obe_messages SET status = ? WHERE id = ? AND client_id = ? '
        . 'AND status IN (\'draft\', \'pending\', \'approved\')');
    $stmt->execute(['rejected', $id, $clientId]);
    json_out(['rejected' => $id]);
}

if ($action === 'edit') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_messages WHERE id = ? AND client_id = ?');
    $stmt->execute([$id, $clientId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($msg === false) {
        json_out(['error' => 'unknown message id'], 400);
    }
    if (!in_array((string)$msg['status'], ['draft', 'pending'], true)) {
        json_out(['error' => 'only draft or pending messages can be '
                  . 'edited'], 409);
    }
    $subject = isset($data['subject']) ? (string)$data['subject']
                                       : $msg['subject'];
    $body = isset($data['body']) ? (string)$data['body'] : $msg['body'];
    $upd = $pdo->prepare(
        'UPDATE obe_messages SET subject = ?, body = ? WHERE id = ?');
    $upd->execute([$subject, $body, $id]);
    $stmt = $pdo->prepare('SELECT * FROM obe_messages WHERE id = ?');
    $stmt->execute([$id]);
    json_out(['message' => $stmt->fetch(PDO::FETCH_ASSOC)]);
}

if ($action === 'mark_sent') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_messages WHERE id = ? AND client_id = ?');
    $stmt->execute([$id, $clientId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($msg === false) {
        json_out(['error' => 'unknown message id'], 400);
    }
    if ((string)$msg['status'] !== 'approved') {
        json_out(['error' => 'only approved messages can be marked '
                  . 'sent'], 409);
    }
    if (empty($msg['contact_id'])) {
        json_out(['error' => 'this message has no contact to send to'],
                 409);
    }
    // Duplicate send guard: one sent message per contact per campaign.
    if ($msg['campaign_id'] === null) {
        $dupe = $pdo->prepare(
            'SELECT id FROM obe_messages WHERE contact_id = ? '
            . 'AND status = \'sent\' AND campaign_id IS NULL AND id <> ? '
            . 'LIMIT 1');
        $dupe->execute([(int)$msg['contact_id'], $id]);
    } else {
        $dupe = $pdo->prepare(
            'SELECT id FROM obe_messages WHERE contact_id = ? '
            . 'AND status = \'sent\' AND campaign_id = ? AND id <> ? '
            . 'LIMIT 1');
        $dupe->execute([(int)$msg['contact_id'],
                        (int)$msg['campaign_id'], $id]);
    }
    if ($dupe->fetch(PDO::FETCH_ASSOC) !== false) {
        json_out(['error' => 'a message to this contact has already '
                  . 'been sent for this campaign'], 409);
    }
    $settings = obe_client_settings($pdo, $clientId);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM obe_messages WHERE client_id = ? '
        . 'AND status = \'sent\' AND sent_at >= CURDATE()');
    $stmt->execute([$clientId]);
    $today = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($today !== false
        && (int)$today['n'] >= $settings['sending_limit_day']) {
        json_out(['error' => 'daily sending limit reached for this '
                  . 'client (' . $settings['sending_limit_day'] . ')'],
                 409);
    }
    $externalId = isset($data['external_id'])
        ? (string)$data['external_id'] : $msg['external_id'];
    $upd = $pdo->prepare(
        'UPDATE obe_messages SET status = \'sent\', sent_at = NOW(), '
        . 'external_id = ? WHERE id = ?');
    $upd->execute([$externalId, $id]);
    $evt = $pdo->prepare(
        'INSERT INTO obe_message_events (client_id, message_id, type, '
        . 'detail) VALUES (?, ?, \'sent\', ?)');
    $evt->execute([$clientId, $id,
                   'Marked sent via ' . (string)$msg['channel']
                   . ($externalId ? ' (id ' . $externalId . ')' : '')]);
    json_out(['sent' => $id]);
}

if ($action === 'gmail_draft') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT m.*, ct.email AS contact_email FROM obe_messages m '
        . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
        . 'WHERE m.id = ? AND m.client_id = ?');
    $stmt->execute([$id, $clientId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($msg === false) {
        json_out(['error' => 'unknown message id'], 400);
    }
    $tokenRaw = obe_setting($pdo, $clientId, 'gmail_token', null);
    $token = $tokenRaw !== null ? json_decode((string)$tokenRaw, true)
                                : null;
    if (!is_array($token) || empty($token['refresh_token'])) {
        json_out(['error' => 'gmail_not_connected',
                  'setup' => 'Create a Google Cloud OAuth client, add '
                      . 'its client id and secret plus a refresh token '
                      . 'in Settings and Integrations, then connect.'],
                 400);
    }
    if (empty($msg['contact_email'])) {
        json_out(['error' => 'the contact has no email address'], 400);
    }
    if ((string)$msg['channel'] !== 'email') {
        json_out(['error' => 'Gmail drafts are for email messages only'],
                 400);
    }
    $clientIdSetting = (string)obe_setting($pdo, $clientId,
                                           'gmail_client_id', '');
    $clientSecret = (string)obe_setting($pdo, $clientId,
                                        'gmail_client_secret', '');
    $tokenRes = obe_http('https://oauth2.googleapis.com/token', [
        'method' => 'POST',
        'timeout' => 20,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query([
            'client_id' => $clientIdSetting,
            'client_secret' => $clientSecret,
            'refresh_token' => (string)$token['refresh_token'],
            'grant_type' => 'refresh_token',
        ]),
    ]);
    $tokenData = json_decode($tokenRes['body'], true);
    if ($tokenRes['error'] !== null || $tokenRes['status'] !== 200
        || empty($tokenData['access_token'])) {
        json_out(['error' => 'gmail_token_failed',
                  'message' => 'Refreshing the Gmail access token '
                      . 'failed (HTTP ' . $tokenRes['status'] . ').'],
                 502);
    }
    $raw = 'To: ' . (string)$msg['contact_email'] . "\r\n"
        . 'Subject: ' . (string)($msg['subject'] ?? '') . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . (string)$msg['body'];
    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $draftRes = obe_http(
        'https://gmail.googleapis.com/gmail/v1/users/me/drafts', [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => ['Content-Type: application/json',
                          'Authorization: Bearer '
                              . (string)$tokenData['access_token']],
            'body' => json_encode(['message' => ['raw' => $encoded]]),
        ]);
    $draftData = json_decode($draftRes['body'], true);
    if ($draftRes['error'] !== null || $draftRes['status'] < 200
        || $draftRes['status'] >= 300 || empty($draftData['id'])) {
        json_out(['error' => 'gmail_draft_failed',
                  'message' => 'Gmail draft creation failed (HTTP '
                      . $draftRes['status'] . '). '
                      . substr($draftRes['body'], 0, 300)], 502);
    }
    $upd = $pdo->prepare(
        'UPDATE obe_messages SET external_id = ? WHERE id = ?');
    $upd->execute([(string)$draftData['id'], $id]);
    json_out(['draft_id' => (string)$draftData['id'],
              'message' => 'Draft created in the connected Gmail '
                  . 'account.']);
}

json_out(['error' => 'unknown action'], 400);
