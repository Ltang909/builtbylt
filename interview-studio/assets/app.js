(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const STORAGE = 'bb-interview-gemini-v1'; // Keep existing key so saved interviews survive upgrades
  const FIRST = "What's something you've built, tried, or learned recently that you keep thinking about?";
  const freshId = () => (globalThis.crypto?.randomUUID?.() || `id-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  const newTurn = (role,text,final=true) => ({ id:freshId(),role,text,final,createdAt:Date.now() });
  let state = {id:freshId(),stage:'interview',mode:'voice',status:'idle',voiceActive:false,turns:[],context:'',evaluation:null,draft:null,draftText:'',rating:0,feedback:'',busy:false,history:[]};
  let recognition=null,utterance=null,timer=null,startedAt=0,voiceGeneration=0;
  let voiceList=[],voiceError='',micHasHeardWords=false;
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
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
    if(st==='speaking'){title='Interviewer speaking';description=latest?.text||FIRST;micStatus('Interviewer speaking · microphone waiting','speaking');}
    else if(st==='listening'){title=micHasHeardWords?'I can hear your words.':'Your microphone is listening';description=micHasHeardWords?'Keep talking, or press Finish answer.':'Speak now. Words appear below as they are recognized.';micStatus(micHasHeardWords?'Speech recognized · recording':'Listening · waiting for speech','listening');}
    else if(st==='thinking'){title='Finding the next question';description='Working from what you said.';micStatus('Processing answer · microphone off','thinking');}
    else if(st==='ready'){title='Ready to evaluate';description='You can evaluate, or keep talking.';micStatus('Microphone off · ready to evaluate','idle');}
    else if(st==='paused'&&voiceError){title='Speech recognition unavailable';description='The microphone can be allowed while the browser’s transcription service is blocked. Try again, or switch to text.';micStatus('Speech recognition error · see details below','error');}
    else if(state.turns.some(t=>t.role==='user')){title='Keep exploring';description=latest?.text||'Pick up where we left off.';micStatus('Microphone off · ready','idle');}
    else {micStatus('Microphone off · press Start talking','idle');}
    text($('callTitle'),title); text($('callDescription'),description);
    const activeVoice=voice && ['speaking','listening','thinking'].includes(st);
    show($('voiceBtn'),!activeVoice);
    text($('voiceBtn'),state.turns.some(t=>t.role==='user')?'🎙 Continue talking':'🎙 Start talking');
    show($('muteBtn'),voice && (st==='speaking'||st==='listening'));
    text($('muteBtn'),st==='speaking'?'Skip voice playback':'✓ Finish answer');
    show($('stopBtn'),voice);
    show($('callTimer'),voice);
    $('soundMark').classList.toggle('live',st==='listening'||st==='speaking');
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
  function cancelVoiceMedia() {
    voiceGeneration++;
    micHasHeardWords=false;
    const r=recognition; recognition=null;
    if(r){try{r.abort();}catch{}}
    if('speechSynthesis' in window){try{window.speechSynthesis.cancel();}catch{}}
    utterance=null;
    show($('liveCaption'),false); text($('liveCaption'),'');
  }
  function stopVoice() {
    cancelVoiceMedia(); state.voiceActive=false;
    if(['speaking','listening','thinking'].includes(state.status))state.status='ended';
    clearInterval(timer);timer=null;
    renderControls();
  }
  function showCaption(value) {text($('liveCaption'),value);show($('liveCaption'),!!value);}
  function listen() {
    if(!state.voiceActive || state.busy || state.stage!=='interview')return;
    if(!SpeechRecognition){voiceError='unsupported';error('This browser does not provide speech recognition. Use desktop Chrome or choose text interview.');state.status='paused';renderControls();return;}
    if('speechSynthesis' in window)window.speechSynthesis.cancel();
    const generation=voiceGeneration;
    const r=new SpeechRecognition();recognition=r;
    r.lang='en-CA';r.continuous=true;r.interimResults=true;r.maxAlternatives=1;
    let finalText='',latestPartial='',errorCode='', submitted=false;
    micHasHeardWords=false;voiceError='';
    r.onstart=()=>{if(recognition!==r||!state.voiceActive)return;state.status='listening';showCaption('Listening for your words…');renderControls();};
    r.onresult=e=>{
      if(recognition!==r)return;
      let final='',partial='';
      for(let i=0;i<e.results.length;i++){
        const part=e.results[i][0]?.transcript||'';
        if(e.results[i].isFinal)final+=part+' ';else partial+=part+' ';
      }
      finalText=final.trim();latestPartial=partial.trim();
      const heard=(finalText+' '+latestPartial).trim();
      if(heard){micHasHeardWords=true;renderControls();}
      showCaption(heard?`You said: “${heard}”`:'Listening for your words…');
    };
    r.onerror=e=>{
      if(recognition!==r)return;
      errorCode=e.error||'unknown';voiceError=errorCode;
      if(errorCode==='not-allowed')error('Chrome blocked the microphone or speech-recognition permission. Check the lock/settings icon beside the address bar, allow the microphone, then reload.');
      else if(errorCode==='service-not-allowed')error('Your microphone may be permitted, but Chrome’s speech recognition service is blocked by the browser or a policy. Try updating Chrome or use the text interview.');
      else if(errorCode==='network')error('Chrome could not reach its speech-recognition service. Your microphone permission may be fine. Check your connection, VPN, browser extensions, or try text mode.');
      else if(errorCode!=='no-speech'&&errorCode!=='aborted')error(`Speech-recognition error (${errorCode}). Try again or type your answer.`);
    };
    r.onend=()=>{
      if(recognition!==r||generation!==voiceGeneration||submitted)return;
      recognition=null;
      if(!state.voiceActive || state.stage!=='interview')return;
      const captured=(finalText+' '+latestPartial).trim();
      if(captured){showCaption(`Captured: “${captured}”`);submitAnswer(captured,true);return;}
      state.status='paused';renderControls();
      if(!errorCode||errorCode==='no-speech')error('Microphone started, but no words were recognized. Try speaking close to the microphone, or use the text box.');
    };
    try{r.start();state.status='listening';renderControls();}
    catch(e){recognition=null;voiceError=e.name||'start failed';state.status='paused';renderControls();error(`Could not start browser recognition (${voiceError}). Try again or use text mode.`);}
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
    if(!SpeechRecognition){startText();error('This browser does not support speech recognition. Try Chrome on desktop or use the text interview.');return;}
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
    if(recognition){const r=recognition;recognition=null;try{r.abort();}catch{}}
    if(utterance){utterance=null;if('speechSynthesis' in window)window.speechSynthesis.cancel();}
    showCaption('');clearError();
    state.turns.push(newTurn('user',answer));state.evaluation=null;state.draft=null;
    state.status='thinking';micHasHeardWords=false;renderTranscript();renderControls();
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
  $('muteBtn').addEventListener('click',()=>{
    if(state.status==='listening' && recognition){try{recognition.stop();}catch{}}
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
  $('exportBtn').addEventListener('click',()=>{if(state.turns.length)toBlob('bum-bum-interview.json',JSON.stringify({turns:state.turns,context:state.context,evaluation:state.evaluation,draft:state.draft,draftText:state.draftText,rating:state.rating,feedback:state.feedback},null,2));});
  document.querySelectorAll('[data-stage]').forEach(b=>b.addEventListener('click',()=>setStage(b.dataset.stage)));
  window.addEventListener('pagehide',()=>cancelVoiceMedia());
  render();
})();
