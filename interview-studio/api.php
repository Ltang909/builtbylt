<?php
declare(strict_types=1);
require __DIR__.'/private/bootstrap.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(['error'=>'Method not allowed'],405);
if (!$setupReady) json_out(['error'=>'Configure your free Gemini key and a studio password in private/config.php first.'],503);
require_authed(); check_csrf(); throttle_api();
// Audio uploads use multipart/form-data, separate from the JSON interview actions.
$action=(string)($_GET['action']??'');
if ($action==='transcribe') {
    try {
        if (!isset($_FILES['audio']) || !is_array($_FILES['audio'])) json_out(['error'=>'No audio recording was uploaded.'],422);
        $file=$_FILES['audio'];
        $uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if ($uploadError!==UPLOAD_ERR_OK) {
            $message=$uploadError===UPLOAD_ERR_INI_SIZE||$uploadError===UPLOAD_ERR_FORM_SIZE
                ? 'PHP rejected the recording because it exceeds the server upload limit. Increase upload_max_filesize and post_max_size.'
                : 'Audio upload failed (PHP code '.$uploadError.').';
            json_out(['error'=>$message],413);
        }
        $size=(int)($file['size']??0);
        if ($size<800 || $size>7*1024*1024) json_out(['error'=>'Audio recording must be between 800 bytes and 7 MB.'],413);
        $path=(string)($file['tmp_name']??'');
        if (!$path || !is_uploaded_file($path)) json_out(['error'=>'Invalid audio upload.'],400);
        $clientMime=(string)($file['type']??'');
        $mime=strtolower(trim(explode(';',$clientMime)[0]));
        $allowed=['audio/webm','audio/mp4','audio/ogg','audio/wav','audio/mpeg','audio/aac','audio/x-m4a'];
        if (!in_array($mime,$allowed,true)) json_out(['error'=>'Unsupported recording format: '.$mime],415);
        $bytes=file_get_contents($path);
        if ($bytes===false || strlen($bytes)!==$size) throw new RuntimeException('Could not read the uploaded audio.');
        $result=gemini_transcribe_audio($bytes,$mime);
        json_out(['transcript'=>$result]);
    } catch (Throwable $e) {
        error_log('Interview Studio Gemini transcribe: '.$e->getMessage());
        json_out(['error'=>$e->getMessage()],502);
    }
}
$raw=file_get_contents('php://input',false,null,0,160000);
$input=json_decode((string)$raw,true);
if (!is_array($input)) json_out(['error'=>'Expected a JSON request'],400);
$turns=clean_turns($input['turns']??[]);
$transcript=mb_safe_substr(conversation($turns),35000);
$hasAnswer=(bool)array_filter($turns,static fn($t)=>$t['role']==='user');
$context=mb_safe_substr(trim((string)($input['context']??'')),2000);
try {
    if ($action==='ask') {
        if (!$hasAnswer) json_out(['error'=>'Provide a response before asking the next question.'],422);
        $schema=['type'=>'OBJECT','properties'=>[
            'question'=>['type'=>'STRING'],
            'interview_status'=>['type'=>'STRING','enum'=>['continue','ready','weak']]
        ],'required'=>['question','interview_status']];
        $result=gemini_response(INTERVIEWER."\nIn this turn, respond with only the next question and status. If ready, question should ask if they want to evaluate. If weak, use a targeted last question if worthwhile.","CONTEXT FROM USER:\n$context\n\nCONVERSATION:\n$transcript\n\nAsk the NEXT specific question.",$schema,500,.6);
        if (empty($result['question']) || !in_array(($result['interview_status']??''),['continue','ready','weak'],true)) throw new RuntimeException('Gemini returned an incomplete question. Please retry.');
        json_out($result);
    }
    if ($action==='evaluate') {
        if (!$hasAnswer) json_out(['error'=>'The interview needs at least one answer.'],422);
        $props=[];
        foreach (['decision','working_angle','rationale','reader_takeaway','strongest_evidence','missing','next_question'] as $p) $props[$p]=['type'=>'STRING'];
        $props['decision']['enum']=['post','dig','skip'];
        $props['score']=['type'=>'INTEGER'];
        $schema=['type'=>'OBJECT','properties'=>$props,'required'=>array_keys($props)];
        $result=gemini_response(EDITOR,"ADDITIONAL CONTEXT: $context\n\nINTERVIEW TRANSCRIPT:\n$transcript",$schema,1400,.25);
        $result['decision']=in_array(($result['decision']??''),['post','dig','skip'],true)?$result['decision']:'dig';
        $result['score']=max(0,min(100,(int)($result['score']??0)));
        json_out($result);
    }
    if ($action==='draft') {
        if (!$hasAnswer) json_out(['error'=>'The interview needs at least one answer.'],422);
        $schema=['type'=>'OBJECT','properties'=>[
            'post'=>['type'=>'STRING'], 'angle'=>['type'=>'STRING'],
            'editing_notes'=>['type'=>'STRING'], 'factual_checks'=>['type'=>'STRING']
        ],'required'=>['post','angle','editing_notes','factual_checks']];
        $evaluation=mb_safe_substr((string)json_encode($input['evaluation']??new stdClass(),JSON_INVALID_UTF8_SUBSTITUTE),5000);
        $feedback=mb_safe_substr(trim((string)($input['feedback']??'')),2000);
        $result=gemini_response(WRITER,"ADDITIONAL CONTEXT: $context\nEDITORIAL ASSESSMENT: $evaluation\nAUTHOR FEEDBACK: $feedback\n\nRAW INTERVIEW:\n$transcript",$schema,2100,.65);
        if (!isset($result['post']) || !is_string($result['post'])) throw new RuntimeException('Gemini did not return a draft. Try again.');
        json_out($result);
    }
    json_out(['error'=>'Unknown action'],404);
} catch (Throwable $e) {
    error_log('Interview Studio Gemini: '.$e->getMessage());
    json_out(['error'=>$e->getMessage()],502);
}
