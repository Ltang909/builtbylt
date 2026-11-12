<?php
declare(strict_types=1);
require __DIR__.'/private/bootstrap.php';
$loginError='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token=(string)($_POST['csrf']??'');
    if (!hash_equals((string)($_SESSION['csrf']??''),$token)) { $loginError='Please refresh and try again.'; }
    elseif (isset($_POST['logout'])) {
        $_SESSION=[];
        session_regenerate_id(true);
        header('Location: ./'); exit;
    } elseif (isset($_POST['password'])) {
        $failed=(int)($_SESSION['login_fail']??0);
        $last=(int)($_SESSION['login_last']??0);
        if ($failed>=6 && time()-$last<600) { $loginError='Too many attempts. Try again in 10 minutes.'; }
        elseif ($setupReady && hash_equals((string)$config['studio_password'],(string)$_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['authed']=true;
            $_SESSION['login_fail']=0;
            $_SESSION['csrf']=bin2hex(random_bytes(24));
            header('Location: ./'); exit;
        } else {
            $_SESSION['login_fail']=$failed+1;
            $_SESSION['login_last']=time();
            $loginError='Incorrect password.';
        }
    }
}
$csrf=htmlspecialchars((string)$_SESSION['csrf'],ENT_QUOTES,'UTF-8');
$setup=false;
if (!$setupReady) $setup=true;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f6f4ef"><title>Interview Studio — Private Writing Lab</title><link rel="stylesheet" href="assets/app.css?v=5"></head>
<body>
<?php if ($setup): ?>
<div class="loginview"><section class="loginbox"><span class="eyebrow">PRIVATE WRITING LAB</span><h1>Almost ready.</h1><p>Open <code>private/config.php</code> and add your free Gemini API key and a private studio password, then refresh this page.</p><p class="hint">Get a free-tier API key from <a href="https://aistudio.google.com/api-keys" target="_blank" rel="noopener noreferrer">Google AI Studio</a>. The key stays on your PHP server.</p></section></div>
<?php elseif (!is_authed()): ?>
<div class="loginview"><form class="loginbox" method="post"><span class="eyebrow">PRIVATE WRITING LAB</span><h1>Find the story worth telling.</h1><p>Sign in to your private interview studio.</p><input type="hidden" name="csrf" value="<?=$csrf?>"><label for="password">Studio password</label><input id="password" name="password" type="password" required autofocus autocomplete="current-password" placeholder="Enter password"><button type="submit" class="btn dark">Open studio <span>→</span></button><?php if ($loginError): ?><p class="error" role="alert"><?=htmlspecialchars($loginError,ENT_QUOTES,'UTF-8')?></p><?php endif; ?></form></div>
<?php else: ?>
<div class="app" id="app">
<header class="topbar"><div class="logo"><div class="logo-mark">IS</div><div><strong>Interview Studio</strong><span>PRIVATE WRITING LAB</span></div></div><div class="topactions"><button id="historyBtn" class="btn pale">Saved sessions</button><button id="resetBtn" class="btn pale">+ New interview</button><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><button class="btn logout" name="logout" value="1">Sign out</button></form></div></header>
<div class="intro"><span class="eyebrow">YOUR PERSONAL WRITING WORKSPACE</span><h1>Find something worth <em>sharing.</em></h1><p>Talk through something you've experienced. The interviewer follows your story, then helps you decide if there's a post worth writing.</p></div>
<div class="steps"><button class="step active" data-stage="interview"><span>01</span> Interview</button><span class="dash"></span><button class="step" data-stage="evaluation"><span>02</span> Editorial check</button><span class="dash"></span><button class="step" data-stage="draft"><span>03</span> LinkedIn draft</button></div>
<main class="workspace">
<section class="panel mainpanel">
<div id="interviewStage" class="stage"><div class="paneltop"><div><div class="eyebrow">01 / INTERVIEW</div><h2>Talk me through it.</h2></div><span class="subtle">VOICE OR TEXT</span></div>
<div class="callbox" id="callBox"><div class="micstatus" id="micStatus" role="status" aria-live="polite"><span class="statusdot"></span><span id="micStatusText">Microphone off · press Start talking</span></div><div class="soundmark" id="soundMark"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div><h3 id="callTitle">Start with a thought.</h3><p id="callDescription">An experiment, a frustrating problem, something you learned. The interviewer will follow up.</p><div class="callbuttons"><button id="voiceBtn" class="btn dark">◉ &nbsp; Start talking</button><button id="muteBtn" class="btn pale hidden">Finish answer</button><button id="stopBtn" class="btn pale hidden">End interview</button><span id="callTimer" class="timer hidden">00:00</span></div><div class="levelwrap hidden" id="levelWrap"><div class="leveltrack"><div class="levelfill" id="levelFill"></div></div><span id="signalLabel">Mic on · waiting for sound</span><strong id="recordDuration">00:00</strong></div><div id="liveCaption" class="livecaption hidden" aria-live="polite"></div><button id="retryAudioBtn" class="btn pale hidden" type="button">Retry transcription</button><p class="speechhint" id="speechHint">We record your answer locally, then Gemini transcribes it after you press Finish answer. No Chrome speech recognition needed.</p><div class="voiceprefs"><label><input type="checkbox" id="autoListen" checked> Automatically listen after each question</label><label><input type="checkbox" id="speakReplies" checked> Read questions aloud</label></div><div class="voiceoptions"><label for="voiceSelect">Interview voice</label><select id="voiceSelect" aria-label="Interview voice"><option value="">System default</option></select><button id="previewVoice" class="btn pale" type="button">▶ Preview</button></div><button id="textModeBtn" class="textlink">Prefer to type? Use a text interview</button></div>
<div class="textquestion hidden" id="currentQuestion"></div>
<div class="replyrow hidden" id="replyRow"><textarea id="reply" rows="2" placeholder="Reply in your own words..."></textarea><button id="sendBtn" class="btn dark">Send →</button></div>
<div class="contextwrap"><label for="context">Context or links <span>(optional)</span></label><textarea id="context" rows="2" placeholder="Tool URL, relevant facts, anything you'd like the editor to know"></textarea></div>
<div class="panelbottom"><span>It's fine if the story isn't worth posting.</span><button id="evaluateBtn" class="btn dark">Evaluate this interview →</button></div></div>
<div id="evaluationStage" class="stage hidden"><div class="paneltop"><div><div class="eyebrow">02 / THE EDITORIAL CHECK</div><h2>Is this worth posting?</h2></div><button class="btn pale" id="backInterview">← Back</button></div><div class="verdict" id="verdict"></div><div class="assessment" id="assessment"></div><div class="panelbottom"><button class="btn pale" id="digBtn">Keep interviewing</button><button class="btn dark" id="draftBtn">Write a draft →</button></div></div>
<div id="draftStage" class="stage hidden"><div class="paneltop"><div><div class="eyebrow">03 / YOUR LINKEDIN POST</div><h2>Your words, edited.</h2></div><span class="subtle">ONE GROUNDED DRAFT</span></div><div id="draftNotes" class="draftnotes"></div><label for="draftEditor" class="sr-only">LinkedIn draft</label><textarea id="draftEditor" class="drafteditor" spellcheck="true"></textarea><div class="drafttools"><span id="wordCount">0 words · editable</span><div class="actions"><button id="copyBtn" class="btn pale">Copy post</button><button id="downloadDraft" class="btn pale">Download .txt</button></div></div><div id="factcheck" class="factcheck hidden"></div><div class="feedback"><h3>Would you actually publish this?</h3><p>Be harsh. Your edits and rejections are more useful than inflated AI scores.</p><div class="rating" id="ratingBtns"><button data-rate="1">☆</button><button data-rate="2">☆</button><button data-rate="3">☆</button><button data-rate="4">☆</button><button data-rate="5">☆</button></div><textarea id="feedback" rows="3" placeholder="What feels wrong or generic? What would you actually say?"></textarea><div class="panelbottom"><button id="regenerateBtn" class="btn pale">Regenerate with feedback</button><button id="saveBtn" class="btn dark">Save interview</button></div></div></div>
<div id="notice" class="notice hidden" role="alert"></div>
</section>
<aside class="panel transcriptpanel"><div class="paneltop"><div><div class="eyebrow">YOUR RAW MATERIAL</div><h2>Transcript</h2></div><span class="count" id="turnCount">0 turns</span></div><p class="transcripthint">The actual words matter. Gemini transcribes each recorded answer. Edit anything it mishears before grading or drafting.</p><div id="transcript" class="transcript"><div class="emptystate">Your words appear here after each recording is transcribed.</div></div><div class="asidefooter"><span>Stored in this browser when you save</span><div><button class="btn pale" id="exportBtn">Export JSON</button><button class="btn pale" id="clearBtn">Clear</button></div></div></aside>
</main><div class="footer">INTERVIEW STUDIO · PERSONAL WRITING LAB. <span>Your experiences lead. Your content follows.</span></div>
<div class="modal hidden" id="historyModal" role="dialog" aria-modal="true" aria-label="Saved interviews"><div class="modalinner"><div class="modalhead"><h2>Saved interviews</h2><button class="btn pale" id="closeHistory">Close</button></div><div id="savedList" class="savedlist"></div><p>Saved locally in this browser. Export important interviews.</p></div></div>

</div>
<script>window.STUDIO={csrf:<?=json_encode($_SESSION['csrf'])?>};</script><script src="assets/app.js?v=5" defer></script>
<?php endif; ?>
</body></html>
