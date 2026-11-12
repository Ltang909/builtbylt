(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const STORAGE = 'bb-interview-gemini-v1'; // Keep existing key so saved interviews survive upgrades
  const FIRST = "What's something you've built, tried, or learned recently that you keep thinking about?";
  const freshId = () => (globalThis.crypto?.randomUUID?.() || `id-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  const newTurn = (role,text,final=true) => ({ id:freshId(),role,text,final,createdAt:Date.now() });
  let state = {id:freshId(),stage:'interview',mode:'voice',status:'idle',voiceActive:false,turns:[],context:'',evaluation:null,draft:null,draftText:'',rating:0,feedback:'',busy:false,history:[]};
  let utterance=null,timer=null,startedAt=0,voiceGeneration=0;
  let mediaRecorder=null,mediaStream=null,audioContext=null,audioAnalyser=null,audioAnimation=0;
  let recordingTimer=null,recordingTick=null,recordingStarted=0,recordingPeak=0,lastRecordingBlob=null;
  let voiceList=[],voiceError='';

  try { state.history = JSON.parse(localStorage.getItem(STORAGE)||'[]'); if (!Array.isArray(state.history)) state.history=[]; } catch { state.history=[]; }

  function text(el,value) { el.textContent = value==null?'':String(value); }
  function show(el, visible) { el.classList.toggle('hidden',!visible); }
  function error(msg) { const el=$('notice'); text(el,msg); show(el,true); }
  function clearError() { show($('notice'),false); }
  function setBusy(value) { state.busy=value; render(); }
  function turnsForApi() { return state.turns.filter(t=>t.text.trim()).map(({role,text})=>({role,text})); }
  async function api(action,body) {
    const r=await fetch(`api.php?action=${encodeURIComponent(action)}`, {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.STUDIO.csrf},credentials:'same-origin',body:JSON.stringify(body)});
    const raw=await r.text();let result;
    try { result=JSON.parse(raw); } catch { throw new Error(raw.slice(0,250)||'Unexpected server response'); }
    if (!r.ok) throw new Error(result.error || `Server error ${r.status}`);
    return result;
  }
  async function transcribeUpload(blob) {
    const form=new FormData();
    form.append('audio',blob,blob.type.startsWith('audio/mp4')?'answer.mp4':'answer.webm');
    const r=await fetch('api.php?action=transcribe',{
      method:'POST',headers:{'X-CSRF-Token':window.STUDIO.csrf},
      body:form,credentials:'same-origin'
    });
    const raw=await r.text();let result;
    try{result=JSON.parse(raw);}catch{throw new Error('Transcription server did not return JSON. Check PHP upload limits.');}
    if(!r.ok)throw new Error(result.error||`Transcription failed (HTTP ${r.status}).`);
    return result;
  }
  function toBlob(filename,value,mime='application/json') {
    const blob=new Blob([value],{type:mime});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),500);
  }
  function renderStages() {
    for (const name of ['interview','evaluation','draft']) {
      show($(`${name}Stage`),state.stage===name);
      document.querySelector(`[data-stage="${name}"]`)?.classList.toggle('active',state.stage===name);
    }
  }
  function setStage(next) {
    if (next==='evaluation'&&!state.evaluation) return;
    if (next==='draft'&&!state.draft) return;
    state.stage=next;render();
  }
  function renderTranscript() {
    const el=$('transcript');el.replaceChildren();
    const turns=state.turns.filter(t=>t.text.trim());
    text($('turnCount'),`${turns.length} turn${turns.length===1?'':'s'}`);
    if (!turns.length) { const empty=document.createElement('div');empty.className='emptystate';text(empty,'Your conversation will show up here as you talk.');el.appendChild(empty);return; }
    for (const t of turns) {
      const box=document.createElement('div');box.className=`turn ${t.role}`;
      const label=document.createElement('span');label.className='speaker';text(label,t.role==='user'?'YOU':'INTERVIEWER');box.appendChild(label);
      const area=document.createElement('textarea');area.value=t.text;area.rows=Math.max(2,Math.min(12,Math.ceil(t.text.length/55)));area.setAttribute('aria-label',`${t.role} transcript (editable)`);
      area.addEventListener('input',()=>{t.text=area.value;state.evaluation=null;state.draft=null;});box.appendChild(area);
      if (!t.final) { const pending=document.createElement('small');text(pending,'transcribing…');box.appendChild(pending); }
      el.appendChild(box);
    }
    el.scrollTop=el.scrollHeight;
  }
  function micStatus(label,kind='idle') {
    text($('micStatusText'),label);
    $('micStatus').dataset.kind=kind;
  }
  function availableVoices() {
    if (!('speechSynthesis' in window)) return;
    voiceList=window.speechSynthesis.getVoices().filter(v=>/^en[-_]/i.test(v.lang));
    voiceList.sort((a,b)=>Number(b.name.includes('Natural'))-Number(a.name.includes('Natural')) || Number(b.name.includes('Google'))-Number(a.name.includes('Google')) || a.name.localeCompare(b.name));
    const sel=$('voiceSelect'); const previous=sel.value || localStorage.getItem('studio-voice') || '';
    sel.replaceChildren();const defaultOption=document.createElement('option');defaultOption.value='';text(defaultOption,'System default');sel.appendChild(defaultOption);
    for(const v of voiceList){const option=document.createElement('option');option.value=v.voiceURI;text(option,`${v.name} (${v.lang})`);sel.appendChild(option);}
    if([...sel.options].some(o=>o.value===previous))sel.value=previous;
  }
  function selectedVoice(){return voiceList.find(v=>v.voiceURI===$('voiceSelect').value)||null;}
  function renderControls() {
    const voice=state.mode==='voice' && state.voiceActive;
    const st=state.status;
    const latest=[...state.turns].reverse().find(t=>t.role==='assistant');
    let title='Tell me what happened.';
    let description=latest?.text || 'An experiment, a frustrating problem, something you learned. No polished brief needed.';
    if(st==='speaking'){
      title='Your interviewer is asking…';description=latest?.text||FIRST;
      micStatus('Interviewer speaking · recording is off','speaking');
    }else if(st==='recording'){
      title='Recording your answer';
      description='Your microphone is capturing audio. Press Finish answer when you’re done talking.';
      micStatus('Recording audio · finish when done','listening');
    }else if(st==='transcribing'){
      title='Transcribing your answer…';description='Audio recorded. Gemini is converting it into text.';
      micStatus('Sending audio to Gemini · microphone off','thinking');
    }else if(st==='thinking'){
      title='Finding the next question';description='Using your answer to choose a useful follow-up.';
      micStatus('Transcribed · finding the next question','thinking');
    }else if(st==='ready'){
      title='Ready to evaluate';description='You can evaluate your story, or keep interviewing.';
      micStatus('Microphone off · ready to evaluate','idle');
    }else if(st==='paused'&&voiceError){
      title='Couldn’t capture your answer';
      description='Check the message below. Your earlier transcript is still here.';
      micStatus('Recording error · see details below','error');
    }else if(state.turns.some(t=>t.role==='user')){
      title='Ready for your next answer';description=latest?.text||'Pick up where we left off.';
      micStatus('Microphone off · ready','idle');
    }else{
      micStatus('Microphone off · press Start talking','idle');
    }
    text($('callTitle'),title);text($('callDescription'),description);
    show($('voiceBtn'),!voice || !['speaking','recording','transcribing','thinking'].includes(st));
    text($('voiceBtn'),state.turns.some(t=>t.role==='user')?'🎙 Record another answer':'🎙 Start talking');
    show($('muteBtn'),voice && (st==='speaking'||st==='recording'));
    text($('muteBtn'),st==='speaking'?'Skip voice playback':'■ Finish answer');
    show($('stopBtn'),voice);
    show($('callTimer'),voice);
    $('soundMark').classList.toggle('live',st==='recording'||st==='speaking');
    $('soundMark').classList.toggle('recording',st==='recording');
    show($('levelWrap'),st==='recording');
    show($('retryAudioBtn'),!!lastRecordingBlob&&st==='paused'&&!!voiceError);
    show($('replyRow'),state.mode==='text'||voice);
    show($('currentQuestion'),state.mode==='text'&&!!latest);
    text($('currentQuestion'),latest?.text||FIRST);
    $('reply').placeholder=voice?'Or type your answer instead…':'Reply in your own words…';
    $('sendBtn').disabled=state.busy||!$('reply').value.trim();
    $('evaluateBtn').disabled=state.busy||!state.turns.some(t=>t.role==='user'&&t.text.trim());
    text($('evaluateBtn'),state.busy?'Working…':'Evaluate this interview →');
    $('voiceBtn').disabled=state.busy;
    text($('textModeBtn'),state.mode==='text'?'Switch back to voice':'Prefer to type? Use a text interview');
  }
  function renderEvaluation() {
    if (!state.evaluation) return;
    const e=state.evaluation;
    const v=$('verdict');v.className='verdict '+e.decision;v.replaceChildren();
    const h=document.createElement('div');h.className='verdicthead';
    const title=document.createElement('h3');text(title,e.decision==='post'?"There’s a real post here.":e.decision==='dig'?'Interesting, but keep digging.':'I’d skip this for now.');
    const score=document.createElement('strong');text(score,`${e.score}/100`);h.append(title,score);v.appendChild(h);
    const rationale=document.createElement('p');text(rationale,e.rationale);v.appendChild(rationale);
    const a=$('assessment');a.replaceChildren();
    [['Promising angle',e.working_angle],['What the reader gets',e.reader_takeaway],['Strongest firsthand detail',e.strongest_evidence],['What’s missing',e.missing],['Question to ask next',e.decision==='dig'?e.next_question:'']].filter(([,v])=>v).forEach(([label,body])=>{
      const card=document.createElement('div');const sub=document.createElement('span');text(sub,label);const p=document.createElement('p');text(p,body);card.append(sub,p);a.append(card);
    });
    text($('draftBtn'),e.decision==='post'?'Write the post →':'Draft anyway →');
  }
  function updateWords() { const count=($('draftEditor').value.trim().match(/\S+/g)||[]).length;text($('wordCount'),`${count} words · editable`);state.draftText=$('draftEditor').value; }
  function renderDraft() {
    if (!state.draft) return;
    const d=state.draft;const notes=$('draftNotes');notes.replaceChildren();const strong=document.createElement('strong');text(strong,d.angle);const p=document.createElement('p');text(p,d.editing_notes);notes.append(strong,p);
    $('draftEditor').value=state.draftText||d.post; updateWords();
    show($('factcheck'),Boolean(d.factual_checks));text($('factcheck'),d.factual_checks?`CHECK BEFORE PUBLISHING: ${d.factual_checks}`:'');
    $('feedback').value=state.feedback;
    document.querySelectorAll('[data-rate]').forEach(b=>{const n=Number(b.dataset.rate);text(b,n<=state.rating?'★':'☆');b.classList.toggle('active',n<=state.rating);});
  }
  function render() { renderStages();renderControls();renderTranscript();renderEvaluation();renderDraft(); }
  function releaseCapture() {
    clearTimeout(recordingTimer);recordingTimer=null;
    clearInterval(recordingTick);recordingTick=null;
    if(audioAnimation)cancelAnimationFrame(audioAnimation);
    audioAnimation=0;
    if(audioContext){try{audioContext.close();}catch{}audioContext=null;}
    audioAnalyser=null;
    if(mediaStream){mediaStream.getTracks().forEach(t=>t.stop());mediaStream=null;}
    text($('recordDuration'),'00:00');
    $('levelFill').style.width='0%';
  }
  function cancelVoiceMedia() {
    voiceGeneration++;
    const recorder=mediaRecorder;mediaRecorder=null;
    if(recorder&&recorder.state!=='inactive'){
      try{recorder.stop();}catch{}
    }
    releaseCapture();
    if('speechSynthesis' in window){try{window.speechSynthesis.cancel();}catch{}}
    utterance=null;
    show($('liveCaption'),false);text($('liveCaption'),'');
  }
  function stopVoice() {
    cancelVoiceMedia();state.voiceActive=false;
    if(['speaking','recording','thinking','transcribing'].includes(state.status))state.status='ended';
    clearInterval(timer);timer=null;
    renderControls();
  }
  function showCaption(value) {text($('liveCaption'),value);show($('liveCaption'),!!value);}
  function updateAudioLevel() {
    if(!audioAnalyser||!mediaRecorder||mediaRecorder.state!=='recording')return;
    const samples=new Uint8Array(audioAnalyser.fftSize);
    audioAnalyser.getByteTimeDomainData(samples);
    let energy=0;
    for(const n of samples){const x=(n-128)/128;energy+=x*x;}
    const rms=Math.sqrt(energy/samples.length);
    const percent=Math.max(4,Math.min(100,Math.round(rms*400)));
    $('levelFill').style.width=percent+'%';
    if(rms>0.015)recordingPeak=Math.max(recordingPeak,rms);
    text($('signalLabel'),recordingPeak>0.015?'Microphone is capturing sound':'Mic is on · waiting for sound');

    audioAnimation=requestAnimationFrame(updateAudioLevel);
  }
  function mediaMimeType() {
    for(const mime of ['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg;codecs=opus']){
      if(MediaRecorder.isTypeSupported(mime))return mime;
    }
    return '';
  }
  async function listen() {
    if(!state.voiceActive||state.busy||state.stage!=='interview'||mediaRecorder)return;
    if(!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder){
      voiceError='unsupported';state.status='paused';renderControls();
      error('This browser does not support direct audio recording. Try recent Chrome, Safari, or Edge, or use the text field.');return;
    }
    if('speechSynthesis' in window)window.speechSynthesis.cancel();
    const generation=voiceGeneration;
    clearError();voiceError='';lastRecordingBlob=null;
    try{
      // Browsers do not need Google's speech-recognition service for this permission.
      const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true},video:false});
      if(generation!==voiceGeneration||!state.voiceActive){stream.getTracks().forEach(t=>t.stop());return;}
      mediaStream=stream;
      const mime=mediaMimeType();
      const recorder=mime?new MediaRecorder(stream,{mimeType:mime}):new MediaRecorder(stream);
      mediaRecorder=recorder;
      const chunks=[];
      let complete=false;
      recorder.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};
      recorder.onerror=e=>{
        if(mediaRecorder!==recorder)return;
        mediaRecorder=null;releaseCapture();
        voiceError='recording';state.status='paused';renderControls();
        error('Your browser stopped recording unexpectedly. You can retry or type your answer.');
      };
      recorder.onstop=async()=>{
        if(complete)return;complete=true;
        const valid=mediaRecorder===recorder&&generation===voiceGeneration&&state.voiceActive;
        mediaRecorder=null;
        releaseCapture();
        if(!valid)return;
        const type=(recorder.mimeType||mime||'audio/webm').split(';')[0];
        const blob=new Blob(chunks,{type});
        if(blob.size<800){
          state.status='paused';voiceError='empty';renderControls();
          error('The recording was empty. Check your selected input device and try again.');return;
        }
        lastRecordingBlob=blob;
        await processRecordedAnswer(blob);
      };
      const AC=window.AudioContext||window.webkitAudioContext;
      if(AC){try{
        audioContext=new AC();const source=audioContext.createMediaStreamSource(stream);
        audioAnalyser=audioContext.createAnalyser();audioAnalyser.fftSize=1024;
        source.connect(audioAnalyser);
        if(audioContext.state==='suspended') audioContext.resume().catch(()=>{});
      }catch{audioAnalyser=null;}}
      recorder.start(250);
      recordingPeak=0;recordingStarted=Date.now();
      recordingTick=setInterval(()=>{
        const seconds=Math.floor((Date.now()-recordingStarted)/1000);
        text($('recordDuration'),`${String(Math.floor(seconds/60)).padStart(2,'0')}:${String(seconds%60).padStart(2,'0')}`);
      },250);
      state.status='recording';renderControls();
      showCaption('Mic is recording. Talk naturally, then click Finish answer.');
      if(audioAnalyser)updateAudioLevel();
      recordingTimer=setTimeout(()=>{if(mediaRecorder===recorder)finishRecording();},90000);
    }catch(e){
      mediaRecorder=null;releaseCapture();
      voiceError=e?.name||'microphone';state.status='paused';renderControls();
      const msg=e?.name==='NotAllowedError'?'Microphone access was denied by your browser or operating system.':e?.name==='NotFoundError'?'No usable microphone was found. Select an input device in your OS settings.':`Could not open the microphone (${e?.name||'unknown error'}).`;
      error(msg+' You can still type your answer.');
    }
  }
  function finishRecording() {
    if(!mediaRecorder || mediaRecorder.state==='inactive')return;
    state.status='transcribing';renderControls();
    showCaption('Recording finished. Sending audio to Gemini for transcription…');
    try{mediaRecorder.stop();}catch(e){voiceError='stop';state.status='paused';renderControls();error('Could not finish recording. Try again.');}
  }
  async function processRecordedAnswer(blob){
    if(blob.size>7*1024*1024){voiceError='too large';state.status='paused';renderControls();error('Recording was too large. Keep each answer under 90 seconds.');return;}
    const generation=voiceGeneration;
    state.status='transcribing';setBusy(true);
    showCaption('Recording captured. Transcribing with Gemini…');
    try{
      const result=await transcribeUpload(blob);
      if(generation!==voiceGeneration||!state.voiceActive)return;
      const transcript=String(result.transcript||'').trim();
      if(!transcript){
        voiceError='no speech';state.status='paused';setBusy(false);
        error('Gemini could not find speech in this recording. Use Retry transcription or record again.');return;
      }
      lastRecordingBlob=null;setBusy(false);
      showCaption('Transcribed: “'+transcript+'”');
      submitAnswer(transcript,true);
    }catch(e){
      if(generation!==voiceGeneration)return;
      voiceError='transcription';state.status='paused';setBusy(false);
      error((e?.message||'Transcription failed.')+' The recording is available to retry; no answer was lost.');
    }
  }
  function speakQuestion(question,canAutoListen=true) {
    if(!state.voiceActive)return;
    if(!$('speakReplies').checked || !('speechSynthesis' in window)){
      state.status=canAutoListen?'paused':'ready';renderControls();
      if(canAutoListen && $('autoListen').checked)setTimeout(()=>{if(state.voiceActive&&!state.busy&&state.status==='paused')listen();},240);
      return;
    }
    const generation=voiceGeneration;
    try{
      window.speechSynthesis.cancel();
      const u=new SpeechSynthesisUtterance(question);utterance=u;u.lang='en-CA';u.rate=0.97;u.pitch=1;
      const voice=selectedVoice();if(voice)u.voice=voice;
      u.onstart=()=>{if(voiceGeneration!==generation)return;state.status='speaking';renderControls();};
      function finish(){if(utterance!==u||generation!==voiceGeneration)return;utterance=null;state.status=canAutoListen?'paused':'ready';renderControls();if(canAutoListen&&$('autoListen').checked)setTimeout(()=>{if(state.voiceActive&&!state.busy&&state.status==='paused'&&generation===voiceGeneration)listen();},420);}
      u.onend=finish;u.onerror=finish;
      state.status='speaking';renderControls();window.speechSynthesis.speak(u);
    }catch(e){state.status='paused';renderControls();error('Voice playback failed. You can read the question and answer by speaking or typing.');}
  }
  function startVoice() {
    if(state.busy)return;
    clearError();voiceError='';
    if(!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder){startText();error('This browser does not support recording. Use a recent browser, or type instead.');return;}
    state.mode='voice';state.voiceActive=true;
    if(!startedAt)startedAt=Date.now();
    if(!timer)timer=setInterval(()=>{if(!state.voiceActive)return;const s=Math.floor((Date.now()-startedAt)/1000);text($('callTimer'),`${String(Math.floor(s/60)).padStart(2,'0')}:${String(s%60).padStart(2,'0')}`);},1000);
    const last=state.turns[state.turns.length-1];
    if(!last){state.turns.push(newTurn('assistant',FIRST));renderTranscript();speakQuestion(FIRST);return;}
    // If resuming after a user answer, get an appropriate follow-up rather than reasking the old one.
    if(last.role==='user'){state.status='thinking';renderControls();requestFollowUp();return;}
    listen();
  }
  function startText() {
    stopVoice();voiceError='';clearError();state.mode='text';state.status='paused';
    if(!state.turns.length)state.turns.push(newTurn('assistant',FIRST));
    render();
  }
  function switchMode(){if(state.mode==='text')startVoice();else startText();}
  async function requestFollowUp(){
    if(state.busy)return;
    const id=state.id;state.status='thinking';setBusy(true);
    try {
      const result=await api('ask',{turns:turnsForApi(),context:$('context').value});
      if(id!==state.id)return;
      const question=String(result.question||'').trim();if(!question)throw new Error('The interviewer returned an empty question.');
      state.turns.push(newTurn('assistant',question));renderTranscript();
      setBusy(false);
      if(state.voiceActive)speakQuestion(question,result.interview_status==='continue');
      else {state.status=result.interview_status==='ready'?'ready':'paused';renderControls();}
    }catch(e){if(id===state.id){state.status='paused';error(e.message||'Could not generate a follow-up.');setBusy(false);}}
  }
  function submitAnswer(value,fromVoice=false){
    const answer=String(value||'').trim();if(!answer||state.busy)return;
    if(mediaRecorder){const recorder=mediaRecorder;mediaRecorder=null;try{if(recorder.state!=='inactive')recorder.stop();}catch{}releaseCapture();}
    if(utterance){utterance=null;if('speechSynthesis' in window)window.speechSynthesis.cancel();}
    showCaption('');clearError();
    state.turns.push(newTurn('user',answer));state.evaluation=null;state.draft=null;
    state.status='thinking';renderTranscript();renderControls();
    requestFollowUp();
  }
  function submitText(){const value=$('reply').value.trim();if(!value||state.busy)return;$('reply').value='';submitAnswer(value,false);}
  async function evaluate() {
    if(state.busy||!state.turns.some(t=>t.role==='user'&&t.text.trim()))return;
    stopVoice();state.context=$('context').value;clearError();setBusy(true);
    const id=state.id;
    try{const evaluation=await api('evaluate',{turns:turnsForApi(),context:state.context});if(state.id!==id)return;state.evaluation=evaluation;state.stage='evaluation';render();}
    catch(e){if(state.id===id)error(e.message);}finally{if(state.id===id)setBusy(false);}
  }
  async function draftPost() {
    if(state.busy)return;
    stopVoice();clearError();setBusy(true);const id=state.id;
    try{const d=await api('draft',{turns:turnsForApi(),context:state.context,evaluation:state.evaluation,feedback:state.feedback});if(state.id!==id)return;state.draft=d;state.draftText=d.post;state.stage='draft';render();}
    catch(e){if(state.id===id)error(e.message);}finally{if(state.id===id)setBusy(false);}
  }
  function dig() {
    state.stage='interview';state.mode='text';
    if(state.evaluation?.next_question){const q=state.evaluation.next_question;if(!state.turns.some(t=>t.role==='assistant'&&t.text===q))state.turns.push(newTurn('assistant',q));}
    state.evaluation=null;state.draft=null;render();
  }
  function reset() {
    stopVoice();voiceError='';state.id=freshId();state.stage='interview';state.mode='voice';state.status='idle';state.turns=[];state.context='';state.evaluation=null;state.draft=null;state.draftText='';state.feedback='';state.rating=0;state.busy=false;startedAt=0;text($('callTimer'),'00:00');$('context').value='';$('reply').value='';clearError();render();
  }
  function save() {
    state.feedback=$('feedback').value;
    const first=state.turns.find(t=>t.role==='user')?.text||'Untitled interview';
    const record={id:state.id,title:first.slice(0,85),createdAt:Date.now(),turns:state.turns,context:state.context,evaluation:state.evaluation,draft:state.draft,draftText:$('draftEditor').value,rating:state.rating,feedback:state.feedback};
    state.history=[record,...state.history.filter(r=>r.id!==record.id)].slice(0,60);
    try{localStorage.setItem(STORAGE,JSON.stringify(state.history));error('Interview saved in this browser. You can also export a JSON backup.');}
    catch{error('Browser storage failed. Export a JSON backup instead.');}
  }
  function openHistory() {
    const list=$('savedList');list.replaceChildren();
    if(!state.history.length){const x=document.createElement('p');text(x,'No saved interviews yet.');list.append(x);}
    for(const item of state.history){
      const row=document.createElement('div');row.className='savedrow';
      const load=document.createElement('button');const title=document.createElement('strong');text(title,item.title||'Untitled');const small=document.createElement('small');text(small,`${new Date(item.createdAt).toLocaleDateString()} · ${item.turns?.length||0} turns · ${item.rating?item.rating+'/5':'Not rated'}`);load.append(title,small);
      load.addEventListener('click',()=>{stopVoice();state.id=item.id;state.turns=item.turns||[];state.context=item.context||'';state.evaluation=item.evaluation||null;state.draft=item.draft||null;state.draftText=item.draftText||item.draft?.post||'';state.rating=item.rating||0;state.feedback=item.feedback||'';state.stage=state.draft?'draft':state.evaluation?'evaluation':'interview';state.mode='text';$('context').value=state.context;show($('historyModal'),false);clearError();render();});
      const del=document.createElement('button');text(del,'Delete');del.addEventListener('click',()=>{if(confirm('Delete this saved interview?')){state.history=state.history.filter(x=>x.id!==item.id);localStorage.setItem(STORAGE,JSON.stringify(state.history));openHistory();}});
      row.append(load,del);list.append(row);
    }
    show($('historyModal'),true);
  }
  $('voiceBtn').addEventListener('click',startVoice);
  $('voiceSelect').addEventListener('change',()=>localStorage.setItem('studio-voice',$('voiceSelect').value));
  $('previewVoice').addEventListener('click',()=>{
    if(!('speechSynthesis' in window)){error('This browser cannot play synthesized speech.');return;}
    window.speechSynthesis.cancel();const u=new SpeechSynthesisUtterance('Tell me what happened. What did you learn from it?');
    const v=selectedVoice();if(v){u.voice=v;u.lang=v.lang;}else{u.lang='en-CA';}u.rate=0.97;window.speechSynthesis.speak(u);
  });
  if('speechSynthesis' in window){availableVoices();window.speechSynthesis.addEventListener?.('voiceschanged',availableVoices);}

  $('stopBtn').addEventListener('click',stopVoice);
  $('retryAudioBtn').addEventListener('click',()=>{if(lastRecordingBlob&&state.voiceActive)processRecordedAnswer(lastRecordingBlob);});
  $('muteBtn').addEventListener('click',()=>{
    if(state.status==='recording' && mediaRecorder)finishRecording();
    else if(state.status==='speaking'){
      if('speechSynthesis' in window)window.speechSynthesis.cancel();utterance=null;state.status='paused';renderControls();if($('autoListen').checked)listen();
    }
  });
  $('textModeBtn').addEventListener('click',switchMode);
  $('reply').addEventListener('input',renderControls);
  $('reply').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();submitText();}});
  $('sendBtn').addEventListener('click',submitText);
  $('evaluateBtn').addEventListener('click',evaluate);
  $('backInterview').addEventListener('click',()=>setStage('interview'));
  $('digBtn').addEventListener('click',dig);
  $('draftBtn').addEventListener('click',draftPost);
  $('draftEditor').addEventListener('input',updateWords);
  $('copyBtn').addEventListener('click',async()=>{try{await navigator.clipboard.writeText($('draftEditor').value);text($('copyBtn'),'Copied!');setTimeout(()=>text($('copyBtn'),'Copy post'),1200);}catch(e){error('Copy failed. Select the text and copy it manually.');}});
  $('downloadDraft').addEventListener('click',()=>toBlob('linkedin-draft.txt',$('draftEditor').value,'text/plain'));
  $('feedback').addEventListener('input',()=>{state.feedback=$('feedback').value;});
  document.querySelectorAll('[data-rate]').forEach(b=>b.addEventListener('click',()=>{state.rating=Number(b.dataset.rate);renderDraft();}));
  $('regenerateBtn').addEventListener('click',()=>{state.feedback=$('feedback').value;draftPost();});
  $('saveBtn').addEventListener('click',save);
  $('historyBtn').addEventListener('click',openHistory);
  $('closeHistory').addEventListener('click',()=>show($('historyModal'),false));
  $('historyModal').addEventListener('click',e=>{if(e.target===$('historyModal'))show($('historyModal'),false);});
  $('resetBtn').addEventListener('click',()=>{if(!state.turns.length||confirm('Start a new interview? Unsaved changes will be lost.'))reset();});
  $('clearBtn').addEventListener('click',()=>{if(confirm('Clear the current interview?'))reset();});
  $('exportBtn').addEventListener('click',()=>{if(state.turns.length)toBlob('interview-studio-session.json',JSON.stringify({turns:state.turns,context:state.context,evaluation:state.evaluation,draft:state.draft,draftText:state.draftText,rating:state.rating,feedback:state.feedback},null,2));});
  document.querySelectorAll('[data-stage]').forEach(b=>b.addEventListener('click',()=>setStage(b.dataset.stage)));
  window.addEventListener('pagehide',()=>cancelVoiceMedia());
  render();
})();
