<?php
declare(strict_types=1);

$serverConfigPath = dirname(__DIR__, 2) . '/private-config.interview-studio.php';
$serverConfig = is_file($serverConfigPath) ? require $serverConfigPath : null;
$config = is_array($serverConfig) ? $serverConfig : require __DIR__ . '/config.php';
if (!is_array($config)) { http_response_code(500); exit('Invalid configuration'); }
$apiKey = trim((string)(getenv('GEMINI_API_KEY') ?: ($config['gemini_api_key'] ?? '')));
$setupReady = $apiKey !== '' && !str_starts_with($apiKey, 'PASTE_')
    && !empty($config['studio_password']) && !str_starts_with((string)$config['studio_password'], 'CHOOSE_');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('bb_studio');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

function json_out(array $value, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}
function is_authed(): bool { return !empty($_SESSION['authed']); }
function require_authed(): void {
    if (!is_authed()) json_out(['error'=>'Session expired. Reload and sign in.'],401);
}
function check_csrf(): void {
    $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token==='' || !hash_equals((string)($_SESSION['csrf'] ?? ''),$token)) json_out(['error'=>'Invalid session token. Reload the page.'],403);
}
function throttle_api(): void {
    $now = time();
    $recent = array_filter((array)($_SESSION['requests'] ?? []), static fn($t)=>is_int($t)&&$t>$now-60);
    if (count($recent)>=18) json_out(['error'=>'Too many requests in one minute. Give it a moment before trying again.'],429);
    $recent[]=$now; $_SESSION['requests']=array_values($recent);
}

const BRAND = 'Leave It to Bum Bum (leaveittobumbum.com) offers lightweight microtools for freelancers, solopreneurs and small businesses, including Day Log, Invoice Generator and Invoice Reminder. Our LinkedIn objective is visits from people who actually use the tools. Never invent features, numbers, outcomes, personal experiences, or direct links. A product should only appear naturally near the end, after a useful takeaway.';
const INTERVIEWER = 'You are a perceptive, conversational interviewer for a marketer and builder behind Leave It to Bum Bum. Your job is to uncover TRUE experiences, decisions, mistakes, experiments and lessons for possible LinkedIn posts. DO NOT write posts. Ask precisely ONE short, specific follow-up question based on the most recent answer and what remains unexplored. If an answer is vague, ask about a real example, what happened or what changed. Avoid generic coaching questions, flattery, preambles and marketing jargon. Never manufacture significance. Keep your questions simple enough to say aloud (under 45 words, ideally under 25). Favor specific observations, conflicts, unexpected results, practical workflows, and things readers could replicate. A story may not be good enough; it is OK to skip it. After 3–7 substantive exchanges, if there is useful material, ask if they want to evaluate the idea. If the idea is weak and another question will not help, say it could be skipped. No forced questions to meet a quota. Context: '.BRAND;
const EDITOR = 'You are a tough, fair editor assessing an INTERVIEW, not a polished LinkedIn post. Evaluate real substance: firsthand specifics, an interesting observation, usefulness without clicking a product link, and relevance to people like solopreneurs, freelancers and marketers. A generic build announcement is not sufficient. Return one of post (genuinely worth drafting), dig (one high-value follow-up could improve it), skip (no strong post here). Score 0–100; 80+ should be rare. Explain in plain language why. State exactly what readers could learn, and what concrete experience supports it. No invented facts or data. If dig, give ONE specific follow-up question; otherwise next_question is empty. Context: '.BRAND;
const WRITER = 'You are an exceptional human editor writing ONE first-person LinkedIn post from a REAL transcript, not an ad. Preserve the author\'s expressions, specific details and naturally conversational voice. Their successful Day Log post began: "Sharing an unorthodox needle mover as a marketer using AI." It gave the actual five-minute daily reflection workflow and a month-and-a-half of personal results before introducing the tool casually at the end. The author cares about personal experiments over skewed research and dislikes padded AI prose, contrived conclusions, metaphors like "the shape of", forced "not X, but Y" structures, exaggerated hooks, em dashes, FUD and early promotion. Make the first 70–85% of the post independently useful or genuinely interesting; introduce a relevant tool briefly at the end, ONLY if directly supported by the interview. Give a reader a practical lesson or way to replicate the experience when the transcript supports it. Do not invent details to make a weak transcript useful. Avoid quotes, statistics, specifics or URLs not provided. Aim for roughly 100–210 words or shorter if the story demands. The draft should read as if the author actually typed it. If evidence is too weak, reflect this in editing_notes rather than pretending. Context: '.BRAND;

function mb_safe_substr(string $s,int $length): string {
    return function_exists('mb_substr')?mb_substr($s,0,$length):substr($s,0,$length);
}
function clean_turns($turns): array {
    if (!is_array($turns) || count($turns)>120) json_out(['error'=>'Transcript is too long.'],422);
    $out=[];
    foreach ($turns as $t) {
        if (!is_array($t)) continue;
        $role=$t['role']??''; $value=trim((string)($t['text']??''));
        if (!in_array($role,['user','assistant'],true) || $value==='') continue;
        $out[]=['role'=>$role,'text'=>mb_safe_substr($value,5000)];
    }
    return $out;
}
function conversation(array $turns): string {
    return implode("\n\n",array_map(static fn($t)=>($t['role']==='user'?'INTERVIEWEE: ':'INTERVIEWER: ').$t['text'],$turns));
}
function gemini_http_post(string $url, string $body): array {
    $headers=['Content-Type: application/json','x-goog-api-key: '.$GLOBALS['apiKey']];
    if (function_exists('curl_init')) {
        $c=curl_init($url);
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>65,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
        $response=curl_exec($c); $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE); $err=curl_error($c); curl_close($c);
        if ($response===false) throw new RuntimeException('Could not connect to Gemini. Confirm outgoing HTTPS access.');
        return [$status,(string)$response];
    }
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>65,'ignore_errors'=>true]]);
    $response=@file_get_contents($url,false,$ctx);
    $status=0;
    foreach (($http_response_header??[]) as $line) if (preg_match('/^HTTP\/\S+\s+(\d{3})/',$line,$m)) $status=(int)$m[1];
    if ($response===false) throw new RuntimeException('Could not connect to Gemini. Confirm PHP HTTPS requests work.');
    return [$status,(string)$response];
}
function gemini_response(string $instructions,string $input,array $schema,int $maxTokens=2000,float $temperature=.5): array {
    $model=(string)($GLOBALS['config']['gemini_model']??'gemini-3.8-flash');
    if (!preg_match('/^[a-zA-Z0-9.-]+$/',$model)) throw new RuntimeException('Invalid model name in config.php.');
    $payload=[
        'systemInstruction'=>['parts'=>[['text'=>$instructions]]],
        'contents'=>[['role'=>'user','parts'=>[['text'=>$input]]]],
        'generationConfig'=>[
            'responseMimeType'=>'application/json',
            'responseSchema'=>$schema,
            'temperature'=>$temperature,
            'maxOutputTokens'=>$maxTokens,
        ],
    ];
    $url='https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';
    [$status,$body]=gemini_http_post($url,(string)json_encode($payload,JSON_INVALID_UTF8_SUBSTITUTE));
    $response=json_decode($body,true);
    if ($status===429) throw new RuntimeException('Gemini free-tier limit reached. Check your limits in Google AI Studio and try again after the quota resets.');
    if ($status===401||$status===403) throw new RuntimeException('Gemini declined this key or project. Check key permissions and free-tier access in Google AI Studio.');
    if ($status===404) throw new RuntimeException('This Gemini model is unavailable to your project. Try gemini-3.5-flash-lite in private/config.php.');
    if ($status<200 || $status>=300) {
        $reason=mb_safe_substr((string)($response['error']['message']??('HTTP '.$status)),250);
        throw new RuntimeException('Gemini request failed: '.$reason);
    }
    $chunks=[];
    foreach (($response['candidates'][0]['content']['parts']??[]) as $part) {
        if (isset($part['text']) && is_string($part['text'])) $chunks[]=$part['text'];
    }
    $raw=trim(implode('',$chunks));
    if (str_starts_with($raw,'```')) $raw=trim((string)preg_replace('/^```(?:json)?\s*|\s*```$/','',$raw));
    $parsed=json_decode($raw,true);
    if (!is_array($parsed)) throw new RuntimeException('Gemini did not return valid structured content. Please retry.');
    return $parsed;
}
