/* Creative Testing System — control panel UI logic.
   Carried over from the Python v2 build; only the transport changed:
   endpoints are api/*.php files, creative actions go through
   api/verify.php, and image upload is base64 JSON (api/creatives.php
   action=image) instead of multipart. Thumbnails come from
   api/image.php (session-gated) via each creative's image_url. */
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"]/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const money = n => n == null ? "—" : "$" + Number(n).toFixed(2);
// Provider costs are often sub-cent (Jev verify ≈ $0.00004/creative) —
// show real digits instead of a misleading $0.00.
const cost = n => { if (n == null) return "—"; const v = Number(n);
  if (v === 0) return "$0.00"; if (Math.abs(v) < 0.009) return "$" + v.toFixed(6);
  if (Math.abs(v) < 0.1) return "$" + v.toFixed(4); return "$" + v.toFixed(2); };
const API = "api/";
const SCOPED = ["experiments.php", "creatives.php", "performance.php", "learnings.php", "generate.php", "settings.php", "intake_analyze.php", "posthog_import.php", "fix_creative.php"];

let WS = null, workspaces = [], experiments = [], creatives = [],
    perfBoard = [], learnings = [], tokens = null, rules = [],
    guideline = null, keys = [], routing = [], ledger = [],
    batchRows = [], panelSettings = null;

function api(path, opts){
  let url = API + path;
  if (WS && SCOPED.some(p => path.startsWith(p)))
    url += (path.includes("?") ? "&" : "?") + "workspace_id=" + WS;
  return fetch(url, opts).then(r => r.json());
}
const jsonOpts = body => ({method: "POST", headers: {"Content-Type": "application/json"},
                           body: JSON.stringify(body)});

async function loadWorkspaces(){
  workspaces = await fetch(API + "workspaces.php").then(r => r.json());
  if (!WS && workspaces.length){
    // Update 6: CodeRabbit is the home workspace when it exists.
    const home = workspaces.find(w => w.name === "CodeRabbit");
    WS = home ? home.id : workspaces[0].id;
  }
  const sel = $("#ws-select");
  sel.innerHTML = workspaces.map(w =>
    `<option value="${w.id}" ${w.id === WS ? "selected" : ""}>${esc(w.name)}</option>`).join("");
}
$("#ws-select").addEventListener("change", e => { WS = Number(e.target.value); loadAll(); });

let bootedOnce = false;
async function loadAll(){
  await loadWorkspaces();
  if (!WS) return;
  [experiments, creatives, perfBoard, learnings, tokens, rules] = await Promise.all([
    api("experiments.php"), api("creatives.php"), api("performance.php"),
    api("learnings.php"),
    fetch(API + "tokens.php?workspace_id=" + WS).then(r => r.json()),
    fetch(API + "rules.php?workspace_id=" + WS).then(r => r.json())
  ]);
  const g = await fetch(API + "guidelines.php?workspace_id=" + WS).then(r => r.json());
  guideline = g.guideline;
  // Batch rows drive the Queue's batch headers (title/meta/created).
  // Before migration-002 the endpoint returns an error object instead
  // of a list — treat that as "no batches" rather than breaking load.
  const bo = await api("generate.php", jsonOpts({action: "batches"})).catch(() => null);
  batchRows = bo && Array.isArray(bo.batches) ? bo.batches : [];
  [keys, routing, ledger] = await Promise.all([
    fetch(API + "keys.php").then(r => r.json()),
    fetch(API + "routing.php").then(r => r.json()),
    fetch(API + "ledger.php?limit=50").then(r => r.json())
  ]);
  // Update 4 settings (notifications, learnings loop, cron token).
  // Before migration-003 the endpoint still answers, with
  // settings_ready:false and defaults — the Settings cards show the
  // migration hint instead of breaking.
  panelSettings = await api("settings.php").catch(() => null);
  const st = await fetch(API + "status.php").then(r => r.json());
  $("#verifier-badge").textContent = "verifier: " + st.verifier;
  applyWorkspaceTheme();
  renderAll();
  // Update 10.10: land on section 1 (Experiments) on first load —
  // the intake stage above it starts minimized.
  if (!bootedOnce){
    bootedOnce = true;
    const t = document.getElementById("stage-experiments");
    if (t) t.scrollIntoView({block: "start"});
  }
}
function renderAll(){
  renderIntake();
  renderExperiments(); renderQueue(); renderApprovals();
  renderLaunch(); renderPerformance(); renderLearnings(); renderSettings();
  refreshStageStates();
}

// ---------- stages (Update 6: one vertical flow, no tab switching) ----------
// Every stage is on the page in pipeline order. A stage the work has
// not reached yet renders greyed and inert; finishing a stage scrolls
// to the next one. gotoTab keeps its name so every existing call site
// ("after generate, go to the queue") now means "scroll to that stage".
const STAGE_ORDER = ["intake", "experiments", "queue", "approvals", "launch", "performance", "learnings", "settings"];
const STAGE_LOCK_HINTS = {
  queue: "add or generate a creative first",
  approvals: "verify a creative in the Queue first",
  launch: "approve a creative first",
  performance: "needs launched creatives with performance rows",
  learnings: "needs a computed verdict or a recorded learning"
};
function stageStates(){
  const verified = creatives.some(c => c.verdicts && c.verdicts.length);
  const approved = creatives.some(c => ["approved", "launched", "live", "scale_proposed"].includes(c.status));
  const perf = perfBoard.some(p => p.days && p.days.length);
  const unlocked = {
    intake: true,
    experiments: true,
    queue: creatives.length > 0,
    approvals: verified,
    launch: approved,
    performance: perf,
    learnings: learnings.length > 0 || perf,
    settings: true
  };
  const done = {
    intake: perf,
    experiments: creatives.length > 0,
    queue: verified,
    approvals: approved,
    launch: perf,
    performance: perfBoard.some(p => ["pass", "fail", "kill"].includes(p.perf_status)),
    learnings: learnings.length > 0,
    settings: false
  };
  return { unlocked, done };
}
function refreshStageStates(){
  const { unlocked, done } = stageStates();
  STAGE_ORDER.forEach(name => {
    const stage = $("#stage-" + name);
    if (stage){
      stage.classList.toggle("locked", !unlocked[name]);
      stage.classList.toggle("done", !!done[name]);
      const st = stage.querySelector(".stage-state");
      if (st) st.textContent = !unlocked[name]
        ? "Locked — " + (STAGE_LOCK_HINTS[name] || "not reached yet")
        : done[name] ? "Done ✓" : "";
    }
    const nav = document.querySelector(`#tabs button[data-tab="${name}"]`);
    if (nav){
      nav.classList.toggle("locked", !unlocked[name]);
      nav.classList.toggle("done", !!done[name]);
    }
    document.querySelectorAll(`.stage-next[data-next="${name}"]`).forEach(btn => {
      btn.disabled = !unlocked[name];
    });
  });
}
function toggleStage(name){
  const s = $("#stage-" + name);
  if (!s) return;
  s.classList.toggle("minimized");
  const b = s.querySelector(".stage-toggle");
  if (b) b.textContent = s.classList.contains("minimized") ? "Show" : "Hide";
}
function gotoStage(name){
  const stage = $("#stage-" + name);
  if (!stage) return;
  let target = stage;
  if (name === "queue" && highlightBatchId){
    const bg = document.getElementById("batch-group-" + highlightBatchId);
    if (bg) target = bg;
  }
  target.scrollIntoView({ behavior: "smooth", block: "start" });
  stage.classList.remove("flash");
  requestAnimationFrame(() => stage.classList.add("flash"));
}
function gotoTab(name){ gotoStage(name); }
document.querySelectorAll("#tabs button").forEach(b => {
  b.onclick = () => { if (!b.classList.contains("locked")) gotoStage(b.dataset.tab); };
});
document.querySelectorAll(".stage-next").forEach(b => {
  b.onclick = () => gotoStage(b.dataset.next);
});
// Scroll-spy: the nav marks the stage currently in view.
const spy = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (!e.isIntersecting) return;
    const name = e.target.id.replace("stage-", "");
    document.querySelectorAll("#tabs button").forEach(x =>
      x.classList.toggle("active", x.dataset.tab === name));
  });
}, { rootMargin: "-30% 0px -60% 0px" });
document.querySelectorAll(".stage").forEach(s => spy.observe(s));

// ---------- intake (Update 8: optional Step 0) ----------
// Batch-first intake: download the CSV template, fill it in, upload
// it back together with the creative files themselves, and the batch
// lands in the Queue + Performance. Then one AI read over the batch
// (estimate-then-confirm, like everything paid) writes pattern
// learnings. Feedback lives in module state, not just the DOM:
// intake actions end with loadAll(), which re-renders this stage.
let intakeNotice = null;
let lastImportIds = [];
let analyzeEst = null, analyzeResult = null;
function setIntakeNotice(cls, text, report){
  intakeNotice = { cls, text, report: report || "" };
  const m = $("#intake-msg");
  if (m){ m.className = "msg " + cls; m.textContent = text; }
  const r = $("#intake-report");
  if (r) r.innerHTML = intakeNotice.report;
}

const INTAKE_COLUMNS = ["headline", "primary_text", "description", "cta",
  "offer_text", "entity", "format", "template_id", "image_file",
  "spend", "impressions", "clicks", "signups", "customers"];

function downloadIntakeTemplate(){
  const head = INTAKE_COLUMNS.join(",");
  const ex1 = 'EXAMPLE Headline — replace or delete this row,"Primary text goes here, quoted because it has a comma",Description text,Try it for free,14-day free trial,Review,1:1,,review-hero.png,1250.00,48200,1930,210,64,5120.50';
  const ex2 = 'EXAMPLE Second creative,Second primary text,,Contact Sales,,Triage,4:5,,triage-card.jpg,640.25,20110,905,96,21,1180.00';
  const blob = new Blob([head + "\n" + ex1 + "\n" + ex2 + "\n"], { type: "text/csv" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "creative-intake-template.csv";
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
}

function analyzeScopeIds(){
  if (lastImportIds.length) return lastImportIds;
  return perfBoard.filter(p => p.days && p.days.length).map(p => p.creative.id);
}

function renderIntake(){
  const el = $("#tab-intake");
  const expOpts = experiments.map(e => `<option value="${e.id}">${esc(e.name)}</option>`).join("");
  const withStats = perfBoard.filter(p => p.days && p.days.length);
  const totalSpend = withStats.reduce((s, p) => s + (p.total_spend || 0), 0);
  const ranked = withStats.filter(p => p.total_customers > 0 && p.cac != null)
    .map(p => ({ name: p.creative.headline || p.creative.game_name || ("#" + p.creative.id),
                 v: p.cac }))
    .sort((a, b) => a.v - b.v);
  const scope = analyzeScopeIds();
  const scopeText = lastImportIds.length
    ? `your last intake batch (${scope.length} creative(s))`
    : `every creative with stats in this workspace (${scope.length})`;
  const analysisResult = analyzeResult ? `
    <div class="copy"><b>Summary.</b> ${esc(analyzeResult.summary)}</div>
    ${analyzeResult.learnings.map(l => `<div class="copy"><span class="chip src">${esc(l.verdict)}</span> ${esc(l.reason)}</div>`).join("")}
    <div class="formnote">Saved to Learnings — the newest learnings ride into the next generation prompt. Actual cost: ${cost(analyzeResult.cost_usd)} via ${esc(analyzeResult.model)}${analyzeResult.images_read ? ` · read ${analyzeResult.images_read} image(s)` : ""}.</div>` : "";
  const statsCard = withStats.length ? `
  <div class="card"><h3>What the intake says so far</h3>
    <div class="copy">${withStats.length} creative(s) carry stats · ${money(totalSpend)} total spend on record.${ranked.length ? ` Lowest CAC: ${esc(ranked[0].name)} (${money(ranked[0].v)}). Highest CAC: ${esc(ranked[ranked.length - 1].name)} (${money(ranked[ranked.length - 1].v)}).` : " No customers recorded yet, so there is no CAC ranking."} Verdicts are computed in Performance against each experiment's thresholds and transitions write learnings of their own.</div></div>` : "";
  el.innerHTML = `
  ${phCardHtml()}
  <div class="card"><h3>Intake a batch of previous creatives</h3>
    <div class="formnote">One flow: download the template, fill it in (one row per creative — copy and stats together), then upload the CSV <em>and</em> the creative files themselves. The <b>image_file</b> column names which uploaded file belongs to which row (png, jpg, webp). Rows whose headline starts with EXAMPLE are skipped.</div>
    <div class="rowline"><button class="btn secondary" onclick="downloadIntakeTemplate()">1 · Download CSV template</button></div>
    <label style="display:block;margin:10px 0">2 · Filled-in CSV file<br><input type="file" id="intake-csv-file" accept=".csv,text/csv"></label>
    <label style="display:block;margin:10px 0">3 · Creative files (images — select all of them at once)<br><input type="file" id="intake-assets" accept="image/png,image/jpeg,image/webp" multiple></label>
    <div class="rowline"><label style="font-size:12px;color:var(--muted)">Attach the batch to experiment <select id="intake-exp">${expOpts}</select></label></div>
    <div class="rowline"><button class="btn" onclick="submitIntakeBatch()">Intake batch</button><span class="msg${intakeNotice ? " " + intakeNotice.cls : ""}" id="intake-msg">${intakeNotice ? esc(intakeNotice.text) : ""}</span></div>
    <div id="intake-report">${intakeNotice ? intakeNotice.report : ""}</div>
  </div>
  <div class="card"><h3>AI analysis — it reads the batch</h3>
    <div class="copy">One routed copy-model call reads ${scopeText}: every headline, primary text, format and result — and the images themselves, up to 12 per run at low detail — then writes the patterns worth repeating (and avoiding) into Learnings, where they brief the next generation. Estimate first — nothing runs or charges until you confirm, as always.</div>
    <div class="rowline"><button class="btn secondary" onclick="analyzeEstimate()">Estimate analysis</button>
    <button class="btn" id="analyze-confirm" onclick="analyzeRun()" disabled>Confirm &amp; analyze</button></div>
    <div id="analyze-est" class="copy"></div>
    <div id="analyze-result">${analysisResult}</div>
  </div>
  ${statsCard}`;
}

function fileToBase64(file){
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result).split(",")[1] || "");
    r.onerror = reject;
    r.readAsDataURL(file);
  });
}

async function submitIntakeBatch(){
  const csvInput = document.getElementById("intake-csv-file");
  const file = csvInput && csvInput.files && csvInput.files[0];
  if (!file){ setIntakeNotice("err", "Pick the filled-in CSV file first (step 2)."); return; }
  const { rows, errors } = parseIntakeCsv(await file.text());
  const good = rows.filter(r => !(r.headline || "").toUpperCase().startsWith("EXAMPLE"));
  const skippedExamples = rows.length - good.length;
  if (!good.length){
    setIntakeNotice("err", errors[0] || "No importable rows in that CSV — remember rows need a headline or a primary text, and EXAMPLE rows are skipped.");
    return;
  }
  const assets = {};
  const assetInput = document.getElementById("intake-assets");
  if (assetInput && assetInput.files)
    for (const f of assetInput.files) assets[f.name.toLowerCase()] = f;
  const expSel = document.getElementById("intake-exp");
  const expId = expSel ? expSel.value : "";
  const fails = [...errors];
  if (skippedExamples) fails.push(`${skippedExamples} EXAMPLE row(s) skipped.`);
  let made = 0, stated = 0, imaged = 0;
  lastImportIds = [];
  for (const r of good){
    setIntakeNotice("", `Intaking ${made + 1} of ${good.length}…`);
    const created = await api("creatives.php", jsonOpts({
      experiment_id: expId || null, kind: "static",
      game_name: r.game_name || null, headline: r.headline || null,
      primary_text: r.primary_text || null, description: r.description || null,
      cta: r.cta || null, offer_text: r.offer_text || null,
      template_id: r.template_id || null, format: r.format || null }));
    if (created.error){ fails.push(`"${r.headline || r.primary_text}": ${created.error}`); continue; }
    made++;
    lastImportIds.push(created.id);
    if (r.image_file){
      const base = String(r.image_file).split(/[\\/]/).pop().toLowerCase();
      const f = assets[base];
      if (!f) fails.push(`Creative #${created.id}: "${r.image_file}" was not among the uploaded files.`);
      else {
        const up = await api("creatives.php", jsonOpts({
          action: "image", creative_id: created.id,
          image_base64: await fileToBase64(f), filename: f.name }));
        if (up.error) fails.push(`Creative #${created.id} image: ${up.error}`);
        else imaged++;
      }
    }
    const hasStats = ["spend", "impressions", "clicks", "signups", "purchasers"]
      .some(k => r[k] != null);
    if (hasStats){
      const out = await api("performance.php", jsonOpts({
        creative_id: created.id,
        spend: r.spend || 0, impressions: r.impressions || 0,
        clicks: r.clicks || 0, signups: r.signups || 0,
        purchasers: r.purchasers || 0 }));
      if (out.error) fails.push(`Creative #${created.id} stats: ${out.error}`);
      else stated++;
    }
  }
  analyzeEst = null;
  analyzeResult = null;
  setIntakeNotice("ok",
    `Intaken ${made} creative(s): ${imaged} with their image, ${stated} with stats. Run the AI analysis below to have it read the batch.`,
    fails.length ? `<div class="msg err">${fails.map(esc).join("<br>")}</div>` : "");
  await loadAll();
}

async function analyzeEstimate(){
  const ids = analyzeScopeIds();
  const box = document.getElementById("analyze-est");
  if (!ids.length){
    box.innerHTML = '<span class="msg err">Nothing to analyze yet — intake a batch first.</span>';
    return;
  }
  box.textContent = "Estimating…";
  const out = await api("intake_analyze.php",
    jsonOpts({ action: "estimate", creative_ids: ids }));
  if (out.error){ box.innerHTML = `<span class="msg err">${esc(out.error)}</span>`; return; }
  analyzeEst = { key: ids.join(","), est: out };
  box.innerHTML = `Reading ${out.creatives} creative(s)${out.images ? ` and ${out.images} image(s)` : ""} with ${esc(out.model)}: <b>${cost(out.total_usd)}</b> estimate. Nothing has run, nothing charged.`;
  document.getElementById("analyze-confirm").disabled = false;
}

async function analyzeRun(){
  const ids = analyzeScopeIds();
  if (!analyzeEst || analyzeEst.key !== ids.join(",")){
    alert("The batch changed since the estimate — estimate again first.");
    return;
  }
  const box = document.getElementById("analyze-result");
  box.innerHTML = "Analyzing… one model call over the batch.";
  document.getElementById("analyze-confirm").disabled = true;
  const out = await api("intake_analyze.php",
    jsonOpts({ action: "run", confirm: true, creative_ids: ids }));
  if (!out.ok){
    box.innerHTML = `<span class="msg err">${esc(out.message || out.error || "Analysis failed.")}</span>`;
    return;
  }
  analyzeResult = out;
  analyzeEst = null;
  await loadAll();
}
function parseIntakeCsv(text){
  const grid = [];
  let cur = [""], inQ = false;
  const t = String(text || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  for (let i = 0; i < t.length; i++){
    const ch = t[i];
    if (inQ){
      if (ch === '"'){
        if (t[i + 1] === '"'){ cur[cur.length - 1] += '"'; i++; }
        else inQ = false;
      } else cur[cur.length - 1] += ch;
    }
    else if (ch === '"') inQ = true;
    else if (ch === ",") cur.push("");
    else if (ch === "\n"){ grid.push(cur); cur = [""]; }
    else cur[cur.length - 1] += ch;
  }
  if (cur.length > 1 || cur[0] !== "") grid.push(cur);
  const clean = grid.filter(r => r.some(c => String(c).trim() !== ""));
  if (!clean.length) return { rows: [], errors: ["Nothing to import — the box is empty."] };
  const alias = { entity: "game_name", game: "game_name",
                  customers: "purchasers" };
  const keys = clean[0].map(h => {
    const k = String(h).trim().toLowerCase().replace(/\s+/g, "_");
    return alias[k] || k;
  });
  const num = v => {
    const n = parseFloat(String(v ?? "").replace(/[$,]/g, ""));
    return isNaN(n) ? null : n;
  };
  const rows = [], errors = [];
  clean.slice(1).forEach((r, idx) => {
    const o = {};
    keys.forEach((k, j) => { o[k] = r[j] != null ? String(r[j]).trim() : ""; });
    if (!o.headline && !o.primary_text){
      errors.push(`Row ${idx + 2}: skipped (no headline or primary text).`);
      return;
    }
    ["spend", "impressions", "clicks", "signups", "purchasers"]
      .forEach(k => { o[k] = num(o[k]); });
    rows.push(o);
  });
  return { rows, errors };
}

// ---------- PostHog native import (Update 9) ----------
// The client connects Meta Ads inside their own PostHog; the
// warehouse sync carries ads / ad_stats / ad_creatives. This card
// discovers those tables, previews the joined report, and imports
// it — dedupe by Meta ad id, re-import refreshes. State lives in
// module vars for the same reason as the intake notices.
let phStatus = null, phStatusWs = null, phFound = null,
    phPreview = null, phNotice = null, phPlatforms = null,
    phSheets = [], phChannel = "meta", phFrom = "", phTo = "";
const PH_LABELS = { meta: "Meta Ads", google: "Google Ads", linkedin: "LinkedIn Ads", reddit: "Reddit Ads" };

function phChannelParams(){
  if (phChannel.indexOf("sheet|") === 0)
    return { platform: "sheet", table: phChannel.slice(6) };
  const p = { platform: phChannel };
  if (phFrom) p.date_from = phFrom;
  if (phTo) p.date_to = phTo;
  return p;
}

function setPhNotice(cls, text){
  phNotice = { cls, text };
  const m = $("#ph-msg");
  if (m){ m.className = "msg " + cls; m.textContent = text; }
}

async function refreshPhStatus(){
  const wsAtCall = WS;
  const out = await api("posthog_import.php", jsonOpts({ action: "status" }));
  if (wsAtCall !== WS) return;
  if (!out.error) phStatus = out;
  renderIntake();
}

function phCardHtml(){
  if (phStatusWs !== WS){
    // Only clear on a real workspace switch — refreshPhStatus()
    // renders too, and clearing there would eat the status it
    // just loaded.
    if (phStatusWs !== null){ phStatus = null; phFound = null; phPreview = null; phPlatforms = null; phSheets = []; phChannel = "meta"; }
    phStatusWs = WS;
    setTimeout(refreshPhStatus, 0);
  }
  const st = phStatus || {};
  const hostOpts = ["us.posthog.com", "eu.posthog.com", "app.posthog.com"]
    .map(h => `<option value="${h}"${st.host === h ? " selected" : ""}>${h}</option>`).join("");
  const keyLine = st.has_key
    ? "Personal API key: saved in the vault."
    : "Personal API key: NOT saved yet — add it in Settings → API keys, provider posthog.";
  const foundHtml = (phFound && phChannel === "meta") ? `<div class="copy">Meta Ads source tables:<br>${Object.entries(phFound)
    .map(([role, t]) => `${esc(role)}: ${t ? esc(t.table) + ` (${t.columns} columns)` : "not found"}`)
    .join("<br>")}</div>` : "";
  const channelOpts = [];
  if (phPlatforms){
    for (const k of Object.keys(PH_LABELS))
      if (phPlatforms[k]) channelOpts.push(`<option value="${k}"${phChannel === k ? " selected" : ""}>${PH_LABELS[k]}</option>`);
    for (const s of phSheets)
      channelOpts.push(`<option value="sheet|${esc(s.table)}"${phChannel === "sheet|" + s.table ? " selected" : ""}>Google Sheet: ${esc(s.table)}</option>`);
  }
  const channelHtml = channelOpts.length ? `<div class="rowline"><label style="font-size:12px;color:var(--muted)">Channel <select id="ph-channel" onchange="phChannel = this.value; phPreview = null; renderIntake();">${channelOpts.join("")}</select></label></div>
  <div class="rowline"><label style="font-size:12px;color:var(--muted)">Active from <input type="date" id="ph-from" value="${esc(phFrom)}" onchange="phFrom = this.value; phPreview = null; renderIntake();"></label><label style="font-size:12px;color:var(--muted)">to <input type="date" id="ph-to" value="${esc(phTo)}" onchange="phTo = this.value; phPreview = null; renderIntake();"></label><span style="font-size:12px;color:var(--muted)">blank = all time; only creatives with activity in range are pulled</span></div>` : "";
  const previewHtml = phPreview ? `
    <style>.ph-tablewrap{max-height:420px;overflow:auto;max-width:100%;border:1px solid var(--line);border-radius:8px}.ph-tablewrap table{border-collapse:collapse;width:100%}.ph-tablewrap td,.ph-tablewrap th{max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left}.ph-tablewrap td.num,.ph-tablewrap th.num{max-width:none;text-align:right}</style>
    <div class="ph-tablewrap"><table><tr><th>Creative</th><th>Campaign / account</th><th class="num">Spend</th><th class="num">Impr.</th><th class="num">Clicks</th><th class="num">Customers</th></tr>
    ${phPreview.rows.map(r => `<tr><td title="${esc(r.headline || r.creative_name || "")}">${esc(r.headline || r.creative_name || "—")}</td><td title="${esc(r.campaign || r.account || "")}">${esc(r.campaign || r.account || "—")}</td><td class="num">${money(r.spend)}</td><td class="num">${r.impressions}</td><td class="num">${r.clicks}</td><td class="num">${r.purchasers == null ? "—" : r.purchasers}</td></tr>`).join("")}</table></div>
    ${phPreview.notes.map(n => `<div class="formnote">${esc(n)}</div>`).join("")}` : "";
  return `
  <div class="card"><h3>Import from PostHog — ad platforms + sheets</h3>
    <div class="formnote">For a workspace whose PostHog has an ad source connected (Data pipelines): Meta, Google, LinkedIn or Reddit Ads, with their creative-level tables enabled — or a Google Sheets source whose worksheet uses the CSV template headers. The panel discovers what is there, joins it into the creative report, and imports it — dedupe by ad, so re-importing refreshes instead of duplicating. ${keyLine}</div>
    <div class="rowline">
      <label style="font-size:12px;color:var(--muted)">Host <select id="ph-host">${hostOpts}</select></label>
      <label style="font-size:12px;color:var(--muted)">Project ID <input id="ph-project" value="${esc(st.project_id || "")}" style="width:120px"></label>
      <button class="btn secondary" onclick="savePhConfig()">Save connection</button>
      <button class="btn secondary" onclick="discoverPh()">Discover tables</button>
      <button class="btn secondary" onclick="previewPh()">Preview report</button>
    </div>
    <span class="msg${phNotice ? " " + phNotice.cls : ""}" id="ph-msg">${phNotice ? esc(phNotice.text) : ""}</span>
    ${channelHtml}
    ${foundHtml}
    ${previewHtml}
    ${phPreview && phPreview.rows.length ? `<div class="rowline"><label style="font-size:12px;color:var(--muted)">Import into experiment <select id="ph-exp">${experiments.map(e => `<option value="${e.id}">${esc(e.name)}</option>`).join("")}</select></label><button class="btn" onclick="importPh()">Import ${phPreview.rows.length} creative(s)</button></div>` : ""}
  </div>`;
}

async function savePhConfig(){
  const out = await api("posthog_import.php", jsonOpts({
    action: "save_config",
    host: document.getElementById("ph-host").value,
    project_id: document.getElementById("ph-project").value }));
  if (out.error){ setPhNotice("err", out.error); return; }
  phStatus = out;
  setPhNotice("ok", out.configured
    ? "Connection saved and complete."
    : "Saved. Still missing: " + (out.has_key ? "the project ID." : "the API key (Settings → API keys, provider posthog)."));
  renderIntake();
}

async function discoverPh(){
  setPhNotice("", "Discovering warehouse tables…");
  const out = await api("posthog_import.php", jsonOpts({ action: "discover" }));
  if (out.error){ setPhNotice("err", out.error); return; }
  phFound = out.found;
  phPlatforms = out.platforms || {};
  phSheets = out.sheets || [];
  const channels = Object.keys(PH_LABELS).filter(k => phPlatforms[k])
    .map(k => PH_LABELS[k]);
  if (phSheets.length) channels.push(phSheets.length + " sheet(s)");
  if (!(phPlatforms[phChannel]) && phChannel.indexOf("sheet|") !== 0){
    const first = Object.keys(PH_LABELS).find(k => phPlatforms[k]);
    phChannel = first || (phSheets.length ? "sheet|" + phSheets[0].table : "meta");
  }
  setPhNotice(channels.length ? "ok" : "err", channels.length
    ? `Found ${out.tables.length} warehouse table(s). Channels ready: ${channels.join(", ")}. Pick a channel, then preview.`
    : `Found ${out.tables.length} warehouse table(s), but no channel is complete. In PostHog, connect an ad source under Data pipelines and enable its stats + creative tables (or link a Google Sheet using the CSV template headers), wait for the first sync, then discover again.`);
  renderIntake();
}

async function previewPh(){
  setPhNotice("", "Running the report query…");
  const out = await api("posthog_import.php", jsonOpts({ action: "preview", ...phChannelParams() }));
  if (out.error){ setPhNotice("err", out.error); phPreview = null; renderIntake(); return; }
  phPreview = out;
  setPhNotice("ok", `Report returned ${out.count} creative(s). Nothing imported yet — check the rows, then import.`);
  renderIntake();
}

async function importPh(){
  const expSel = document.getElementById("ph-exp");
  setPhNotice("", "Importing…");
  const out = await api("posthog_import.php", jsonOpts({
    action: "import", experiment_id: expSel ? expSel.value : "", ...phChannelParams() }));
  if (out.error){ setPhNotice("err", out.error); return; }
  lastImportIds = out.ids || [];
  analyzeEst = null;
  analyzeResult = null;
  setPhNotice("ok", `Imported: ${out.created} new, ${out.updated} refreshed, ${out.imaged} image(s) fetched${out.skipped ? `, ${out.skipped} skipped (no ad id)` : ""}. The AI analysis below can read this set now.`);
  phPreview = null;
  await loadAll();
}

// ---------- shared creative card ----------
function verdictChips(c){
  if (!c.verdicts || !c.verdicts.length)
    return '<div class="verdicts"><span class="chip src">not verified yet</span></div>';
  return '<div class="verdicts">' + c.verdicts.map(v =>
    `<span class="chip ${v.verdict}" title="${esc(v.note)}">${v.rule_id} ${v.verdict === "pass" ? "✓" : "✗"} ${Math.round(v.confidence*100)}%</span><span class="chip src">${esc(v.source)}</span>`
  ).join("") + c.verdicts.filter(v => v.verdict === "fail").map(v =>
    `<div class="faildetail"><strong>${v.rule_id}${v.rule_name ? " · " + esc(v.rule_name) : ""}</strong> — ${esc(v.note)}`
    + (v.local_note ? `<br>Panel's own check: ${esc(v.local_verdict || "")} — ${esc(v.local_note)}` : "")
    + `</div>`).join("") + "</div>";
}
function lintChips(c){
  if (!c.lint_flags || !c.lint_flags.length) return "";
  return c.lint_flags.map(f =>
    `<span class="chip fail" title="${esc(f.message)}">⚠ ${esc(f.short)}</span>`).join(" ");
}
function creativeCard(c, extraButtons){
  const exp = experiments.find(e => e.id === c.experiment_id);
  const img = c.image_url
    ? `<img class="thumb" src="${esc(c.image_url)}" alt="creative image">` : "";
  return `<div class="card">
    <h3>#${c.id} · ${esc(c.game_name || "—")} <span class="chip status">${esc(c.status)}</span>
        <span class="chip src">${esc(c.kind)}</span>${c.batch_id ? ` <span class="chip src">batch #${c.batch_id}</span>` : ""} ${lintChips(c)}</h3>
    <div class="meta">${esc(exp ? exp.name : "")} · format ${esc(c.format || "—")} · template ${esc(c.template_id || "—")}</div>
    ${img}
    <div class="copy"><span class="lbl">Headline</span>\n${esc(c.headline)}</div>
    <div class="copy"><span class="lbl">Primary text</span>\n${esc(c.primary_text)}</div>
    <div class="copy"><span class="lbl">Description</span>\n${esc(c.description)}</div>
    <div class="copy"><span class="lbl">CTA</span> ${esc(c.cta)} &nbsp; <span class="lbl">Offer</span> ${esc(c.offer_text)}</div>
    ${c.art_direction ? `<div class="copy"><span class="lbl">Art direction</span>\n${esc(c.art_direction)}</div>` : ""}
    ${verdictChips(c)}
    <div style="margin-top:10px">${extraButtons || ""}</div>
  </div>`;
}

// ---------- structured hypothesis (Update 3) ----------
// The per-experiment hypothesis is a small structured brief, stored
// as a JSON string in the existing experiments.hypothesis TEXT
// column (no schema change): {angle, hook_type, audience, game,
// offer, tone}. 'offer' holds the offer_table entry id, or 'none'
// for an explicit no-offer brief. A stored value that is not such
// JSON is a legacy plain-text hypothesis: it shows in the Angle
// field with the rest unset. The server parses the same way
// (hypothesis_parse in lib/openai.php).
const HOOK_TYPES = ["Bold claim", "Question", "Curiosity gap",
                    "Offer-led", "Gameplay moment", "Social proof"];
const HYP_KEYS = ["angle", "hook_type", "audience", "game", "offer", "tone"];
function parseHypothesis(raw){
  const out = {structured: false, angle: String(raw ?? ""),
    hook_type: "", audience: "", game: "", offer: "", tone: ""};
  const t = String(raw ?? "").trim();
  if (t.startsWith("{")){
    try {
      const d = JSON.parse(t);
      if (d && typeof d === "object" && !Array.isArray(d)
          && HYP_KEYS.some(k => k in d)){
        out.structured = true;
        HYP_KEYS.forEach(k => out[k] = d[k] == null ? "" : String(d[k]));
      }
    } catch (e) { /* not JSON: plain-text hypothesis */ }
  }
  return out;
}
function knownGames(){
  const r8 = rules.find(r => r.rule_id === "R8");
  const list = r8 && r8.config && r8.config.known_entities;
  return Array.isArray(list) ? list.map(String) : [];
}
function offerOptions(){
  const table = tokens && Array.isArray(tokens.offer_table) ? tokens.offer_table : [];
  return table.filter(o => o && o.text).map(o => ({
    value: String(o.id != null && o.id !== "" ? o.id : o.text),
    label: String(o.text)}));
}
function offerShortLabel(value){
  if (!value) return "";
  if (value === "none") return "No offer";
  const hit = offerOptions().find(o => o.value === value || o.label === value);
  const text = hit ? hit.label : value;
  return text.length > 30 ? text.slice(0, 30) + "…" : text;
}
function collectHypothesis(expId){
  const g = p => (document.getElementById(p + expId) || {}).value || "";
  const obj = {
    angle: g("hyp-angle-").trim(),
    hook_type: g("hyp-hook-"),
    audience: g("hyp-audience-").trim(),
    game: g("hyp-game-"),
    offer: g("hyp-offer-"),
    tone: g("hyp-tone-").trim(),
  };
  return Object.values(obj).every(v => v === "") ? "" : JSON.stringify(obj);
}
// The six structured-hypothesis fields, shared verbatim by the
// per-experiment form (suffix = experiment id, edits invalidate the
// generation estimate) and the New experiment form (suffix = "new",
// no saved experiment yet). Field ids are "hyp-<field>-<suffix>",
// which is exactly what collectHypothesis(suffix) reads back, so
// both forms serialize to the identical JSON shape.
function hypothesisFieldsHtml(suffix, h, expId){
  const sel = (cur, val) => cur === val ? " selected" : "";
  const txt = expId != null ? ` oninput="genInvalidate(${expId})"` : "";
  const chg = expId != null ? ` onchange="genInvalidate(${expId})"` : "";
  return `
        <label>Angle — the creative idea, one line<input type="text" id="hyp-angle-${suffix}" value="${esc(h.angle)}"${txt} placeholder="e.g. The daily claim streak is the real jackpot"></label>
        <label>Hook type<select id="hyp-hook-${suffix}"${chg}>
          <option value=""${sel(h.hook_type, "")}>Any hook</option>
          ${HOOK_TYPES.map(t => `<option value="${esc(t)}"${sel(h.hook_type, t)}>${esc(t)}</option>`).join("")}
        </select></label>
        <label>Audience<input type="text" id="hyp-audience-${suffix}" value="${esc(h.audience)}"${txt} placeholder="e.g. lapsed players, 30–45"></label>
        <label>Product<select id="hyp-game-${suffix}"${chg}>
          <option value=""${sel(h.game, "")}>Any product</option>
          ${knownGames().map(gm => `<option value="${esc(gm)}"${sel(h.game, gm)}>${esc(gm)}</option>`).join("")}
        </select></label>
        <label>Offer<select id="hyp-offer-${suffix}"${chg}>
          <option value=""${sel(h.offer, "")}>Any approved offer</option>
          <option value="none"${sel(h.offer, "none")}>No offer</option>
          ${offerOptions().map(o => `<option value="${esc(o.value)}"${sel(h.offer, o.value)}>${esc(o.label)}</option>`).join("")}
        </select></label>
        <label>Tone note (optional)<input type="text" id="hyp-tone-${suffix}" value="${esc(h.tone)}"${txt} placeholder="e.g. playful, zero hype"></label>`;
}
function hypothesisFormHtml(e){
  const h = parseHypothesis(e.hypothesis || "");
  return `<div class="hypform">
      <div class="formnote">Structured hypothesis — the brief every generated creative must execute. Angle is the idea; hook type directs every headline; product and offer narrow the run. Saving stores it as JSON in the hypothesis field (a legacy plain-text hypothesis lands in Angle).</div>
      <form onsubmit="return false">${hypothesisFieldsHtml(e.id, h, e.id)}
      </form>
      <div class="rowline"><button class="btn secondary" onclick="saveHypothesis(${e.id})">Save hypothesis</button>
      <button class="btn secondary" onclick="clearHypothesis(${e.id})">Clear hypothesis</button>
      <span class="msg" id="hyp-msg-${e.id}"></span></div>
    </div>`;
}
// The same structured block for a not-yet-saved experiment
// (Update 4.1 form parity): identical fields, ids pattern with the
// "new" suffix; submitExperiment() reads them back through
// collectHypothesis("new").
function newHypothesisFormHtml(){
  const h = {angle: "", hook_type: "", audience: "",
             game: "", offer: "", tone: ""};
  return `<div class="hypform">
      <div class="formnote">Structured hypothesis — the brief every generated creative must execute. Angle is the idea; hook type directs every headline; product and offer narrow the run. Stored as JSON in the hypothesis field, exactly like the form on a saved experiment.</div>
      <form onsubmit="return false">${hypothesisFieldsHtml("new", h, null)}
      </form>
    </div>`;
}

// ---------- experiments + generation ----------
const genState = {};   // expId -> {key, est} for the estimate gate
// The current workspace's ad templates, for the generate form's
// picker (Update 10.9). Server data: workspaces.php attaches
// image_templates (key + display name) to each workspace.
function genTemplateHtml(expId){
  const ws = workspaces.find(w => w.id === WS) || {};
  const tpls = ws.image_templates || [];
  if (!tpls.length) return "";
  const opts = tpls.length > 1
    ? `<option value="alternate">Alternate all templates</option>`
      + tpls.map(t => `<option value="${esc(t.key)}">${esc(t.name)} only</option>`).join("")
    : tpls.map(t => `<option value="${esc(t.key)}">${esc(t.name)}</option>`).join("");
  return `<label>Template <select id="gen-template-${expId}" onchange="genInvalidate(${expId})">`
    + opts + `</select></label>`;
}
function genInputs(expId){
  return {
    hypothesis: collectHypothesis(expId),
    variants: Number((document.getElementById("gen-variants-" + expId) || {}).value || 6),
    images: Number((document.getElementById("gen-images-" + expId) || {}).value || 0),
    image_quality: (document.getElementById("gen-quality-" + expId) || {}).value || "medium",
    image_text: "headline", // text-free art option removed (Update 10.6); the server forces this too
    template_key: (document.getElementById("gen-template-" + expId) || {}).value || ""
  };
}
function genKey(expId){
  const i = genInputs(expId);
  return JSON.stringify([i.hypothesis, i.variants, i.images, i.image_quality, i.image_text, i.template_key]);
}
function genInvalidate(expId){
  delete genState[expId];
  const box = document.getElementById("gen-est-" + expId);
  if (box) box.innerHTML = "";
  const btn = document.getElementById("gen-confirm-" + expId);
  if (btn) btn.disabled = true;
}
async function saveHypothesis(expId){
  const v = collectHypothesis(expId);
  await api("experiments.php", jsonOpts({id: expId, workspace_id: WS, hypothesis: v}));
  const m = document.getElementById("hyp-msg-" + expId);
  if (m){ m.className = "msg ok"; m.textContent = v ? "Hypothesis saved." : "Hypothesis cleared."; }
  const e = experiments.find(x => x.id === expId); if (e) e.hypothesis = v;
}
async function clearHypothesis(expId){
  ["hyp-angle-", "hyp-audience-", "hyp-tone-"].forEach(p => {
    const el = document.getElementById(p + expId); if (el) el.value = "";
  });
  ["hyp-hook-", "hyp-game-", "hyp-offer-"].forEach(p => {
    const el = document.getElementById(p + expId); if (el) el.selectedIndex = 0;
  });
  await saveHypothesis(expId);
  genInvalidate(expId);
}
async function genEstimate(expId){
  const i = genInputs(expId);
  const box = document.getElementById("gen-est-" + expId);
  box.innerHTML = '<span class="msg">Estimating…</span>';
  const out = await api("generate.php", jsonOpts({action: "estimate",
    experiment_id: expId, hypothesis: i.hypothesis, variants: i.variants,
    images: i.images, image_quality: i.image_quality,
    image_text: i.image_text}));
  if (out.error){ box.innerHTML = `<span class="msg err">${esc(out.error)}</span>`; return; }
  genState[expId] = {key: genKey(expId), est: out};
  box.innerHTML = `<div class="formnote"><strong>Cost estimate</strong> (estimate — nothing has run, nothing charged):<br>`
    + out.lines.map(l => `${esc(l.item)}: ${cost(l.cost_usd)} <span class="chip src">estimate</span> — ${esc(l.detail)}`).join("<br>")
    + `<br><strong>Total estimate: ${cost(out.total_usd)}</strong> · ${esc(out.note)}`
    + (out.learnings_count
        ? `<div class="meta" style="margin-top:4px">${out.learnings_count} workspace learnings will be included in this brief.</div>` : "")
    + `</div>`;
  document.getElementById("gen-confirm-" + expId).disabled = false;
}
async function genRun(expId){
  const st = genState[expId];
  if (!st || st.key !== genKey(expId)){
    alert("Inputs changed since the estimate — run Estimate again first.");
    return;
  }
  const i = genInputs(expId);
  const res = document.getElementById("gen-result-" + expId);
  // Staged progress (Update 10.4): the run is one request (copy call,
  // then one image call per image), so the client cannot measure real
  // progress — the bar advances on elapsed time through the phases
  // and only completes when the server answers. No percentage is
  // shown: a number would be invented, the stages are the honest part.
  res.innerHTML = `<div style="margin:8px 0">`
    + `<div style="height:8px;background:#23264d;border-radius:4px;overflow:hidden">`
    + `<div id="gen-fill-${expId}" style="height:100%;width:4%;background:#5B62F9;border-radius:4px;transition:width .6s ease"></div></div>`
    + `<div class="meta" id="gen-stage-${expId}" style="margin-top:6px">Contacting the copy model…</div></div>`;
  document.getElementById("gen-confirm-" + expId).disabled = true;
  const stages = [[0, "Contacting the copy model…"],
    [5, "Writing copy variants…"],
    [20, "Still writing copy…"]];
  const t0 = Date.now();
  const progTimer = setInterval(() => {
    const s = (Date.now() - t0) / 1000;
    const fill = document.getElementById("gen-fill-" + expId);
    if (fill) fill.style.width = Math.min(94, 4 + 90 * (1 - Math.exp(-s / 50))) + "%";
    const label = document.getElementById("gen-stage-" + expId);
    if (label){
      let txt = stages[0][1];
      for (const [at, t] of stages) if (s >= at) txt = t;
      label.textContent = txt;
    }
  }, 500);
  let out;
  try {
    out = await api("generate.php", jsonOpts({action: "run", confirm: true,
      experiment_id: expId, hypothesis: i.hypothesis, variants: i.variants,
      images: i.images, image_quality: i.image_quality,
      image_text: i.image_text}));
  } catch (e) {
    clearInterval(progTimer);
    res.innerHTML = `<span class="msg err">${esc("Generation request failed: " + (e && e.message ? e.message : e))}</span>`;
    return;
  }
  clearInterval(progTimer);
  if (!out.ok){
    res.innerHTML = `<span class="msg err">${esc(out.message || out.error || "Generation failed.")}</span>`;
    return;
  }
  const b = out.batch;
  // Update 10.5: images are generated one request per creative, so
  // progress from here is REAL — each bar step is a finished image,
  // not a timer. A failed image is reported and skipped, never
  // auto-retried (the provider may have completed it server-side).
  const pending = Array.isArray(out.pending_images) ? out.pending_images : [];
  // Template interchange (Update 10.10): "alternate" deals the
  // workspace's templates round-robin across the batch's images —
  // templates are interchangeable, never an either/or choice.
  const wsTplKeys = ((workspaces.find(w => w.id === WS) || {}).image_templates || []).map(t => t.key);
  const tplForIndex = k => i.template_key === "alternate" && wsTplKeys.length > 1
    ? wsTplKeys[k % wsTplKeys.length] : i.template_key;
  let imagesMade = 0, imageCost = 0;
  const imageFails = [];
  if (pending.length){
    const fill = document.getElementById("gen-fill-" + expId);
    const label = document.getElementById("gen-stage-" + expId);
    for (let k = 0; k < pending.length; k++){
      if (label) label.textContent = `Generating image ${k + 1} of ${pending.length}…`;
      if (fill) fill.style.width = Math.round(((1 + k) / (pending.length + 1)) * 100) + "%";
      let r;
      try {
        r = await api("generate_image.php", jsonOpts({creative_id: pending[k],
          image_text: i.image_text, template: tplForIndex(k), confirm: true}));
      } catch (e) {
        imageFails.push(`creative #${pending[k]}: the image request failed (${e && e.message ? e.message : e}) — not retried, in case the provider completed it`);
        continue;
      }
      if (r && r.ok){ imagesMade++; imageCost += Number(r.cost_usd || 0); }
      else imageFails.push(`creative #${pending[k]}: ${(r && r.error) || "image failed"}`);
    }
  }
  const totalActual = (Number(out.actual_cost_usd) || 0) + imageCost;
  res.innerHTML = `<div class="formnote"><strong>Batch #${b.id} ${esc(b.status)}</strong> — `
    + `${out.created}${out.requested ? ` of ${out.requested} requested` : ""} creatives created (drafts), ${imagesMade} image(s) generated. `
    + `Actual cost: ${cost(totalActual)} (estimate was ${cost(b.est_cost_usd)}).`
    + (b.error ? `<br>Notes: ${esc(b.error)}` : "")
    + (imageFails.length ? `<br>Image problems: ${esc(imageFails.join("; "))}` : "")
    + `<br><button class="btn" onclick="gotoQueueBatch(${b.id})">Batch #${b.id} created — review and verify in the Creative Queue →</button></div>`;
  creatives = await api("creatives.php");
  renderQueue(); renderApprovals();
  refreshStageStates();
}
function gotoQueueBatch(batchId){
  highlightBatchId = batchId;
  gotoTab("queue");
  renderQueue();
}
function renderExperiments(){
  const el = $("#tab-experiments");
  el.innerHTML = experiments.map(e => `<div class="card">
      <h3>${esc(e.name)} <span class="chip status">${esc(e.status)}</span></h3>
      <div class="meta">${esc(e.start_date)} → ${esc(e.end_date)}</div>
      ${hypothesisFormHtml(e)}
      <table><tr><th>Pass</th><th>Fail</th><th>Kill</th><th>Spend floor</th></tr>
      <tr><td>${esc(e.pass_metric)}</td><td>${esc(e.fail_metric)}</td>
          <td>${esc(e.kill_metric)}</td><td>${money(e.spend_floor)}</td></tr></table>
      <h4>Generate creatives (Step 2)</h4>
      <div class="formnote">Copy via the routed copy model (default gpt-4o-mini), images via the routed art model. Nothing runs — and nothing is charged — until you see an estimate and confirm it. That gate is the rule, not a courtesy.</div>
      <div class="rowline">
        <label>Variants <input type="number" id="gen-variants-${e.id}" value="6" min="1" max="24" style="width:70px" onchange="genInvalidate(${e.id})"></label>
        <label>Images <input type="number" id="gen-images-${e.id}" value="2" min="0" max="24" style="width:70px" onchange="genInvalidate(${e.id})"></label>
        <label>Image quality <select id="gen-quality-${e.id}" onchange="genInvalidate(${e.id})">
          <option value="low">low</option><option value="medium" selected>medium</option><option value="high">high</option></select></label>
        ${genTemplateHtml(e.id)}
        <button class="btn secondary" onclick="genEstimate(${e.id})">Estimate</button>
        <button class="btn" id="gen-confirm-${e.id}" disabled onclick="genRun(${e.id})">Confirm &amp; generate</button>
      </div>
      <div id="gen-est-${e.id}"></div>
      <div id="gen-result-${e.id}"></div>
    </div>`).join("")
    + `<div class="card"><h3>New experiment</h3>
       <form id="exp-form">
         <label>Name<input name="name" required></label>
         <label>Start date<input name="start_date" placeholder="2026-11-01"></label>
         <label>End date<input name="end_date" placeholder="2026-11-14"></label>
         <label>Pass: CAC ≤ $<input name="pass_value" type="number" step="0.01" required></label>
         <label>Fail: CAC &gt; $<input name="fail_value" type="number" step="0.01" required></label>
         <label>Kill: CAC &gt; $<input name="kill_cac" type="number" step="0.01" required></label>
         <label>Spend floor $<input name="spend_floor" type="number" step="0.01" required></label>
       </form>
       ${newHypothesisFormHtml()}
       <div class="rowline"><button class="btn" onclick="submitExperiment()">Create experiment</button>
       <span class="msg" id="exp-msg"></span></div></div>`;
}
async function submitExperiment(){
  const f = new FormData($("#exp-form"));
  const body = Object.fromEntries(f.entries());
  const msg = document.getElementById("exp-msg");
  // Form parity (Update 4.1): a new experiment needs the same
  // structure as a saved one — all four thresholds are required, so
  // the stored metric strings never carry "?" placeholders again.
  const missing = ["pass_value", "fail_value", "kill_cac", "spend_floor"]
    .filter(k => body[k] === "" || body[k] == null);
  if (!body.name || !body.name.trim() || missing.length){
    if (msg){ msg.className = "msg err";
      msg.textContent = "Name and all four thresholds (pass, fail, "
        + "kill CAC, spend floor) are required — nothing was created."; }
    return;
  }
  body.workspace_id = WS;
  body.hypothesis = collectHypothesis("new");
  body.pass_metric = "CAC <= $" + body.pass_value;
  body.fail_metric = "CAC > $" + body.fail_value;
  body.kill_metric = "CAC > $" + body.kill_cac + " after $" + body.spend_floor + " spend floor";
  ["pass_value","fail_value","kill_cac","spend_floor"].forEach(k => body[k] = Number(body[k]));
  await api("experiments.php", jsonOpts(body));
  loadAll();
}

// ---------- creative queue (review + verify stage, Step 3a) ----------
// This tab is where generated work is SEEN and verified: drafts are
// grouped by batch with full previews, the batch header carries the
// Jev verify estimate, and Confirm & verify batch lives in the header.
// The estimate-then-confirm discipline is unchanged: the confirm
// button stays disabled until the estimate has been fetched and
// rendered, and the server still refuses verify_batch without
// confirm:true (that server check is the real enforcement).
const batchVerifyState = {};  // batchId -> estimate shown in its header
let highlightBatchId = null;  // batch to flash + scroll to after render

// Batch identity (Update 3): every generated batch gets a
// deterministic accent color from this fixed palette, indexed by
// batch id, so work from different hypotheses is unmistakable and
// generated work is never mistaken for seeded/manual creatives.
// MUST mirror BATCH_PALETTE in panel/lib/common.php (the PHP tests
// cross-check that both lists are identical).
const BATCH_PALETTE = ["#5B62F9", "#2fbf71", "#e8b84b", "#ef476f",
                       "#38b6ff", "#b388ff", "#ff8a5c", "#3fd0c9"];
function batchColor(id){ return BATCH_PALETTE[Math.abs(Number(id)) % BATCH_PALETTE.length]; }
function batchInfo(batchId){
  return batchRows.find(b => Number(b.id) === Number(batchId)) || null;
}

function queueCard(c){
  const exp = experiments.find(e => e.id === Number(c.experiment_id));
  const img = c.image_url
    ? `<img class="qthumb" src="${esc(c.image_url)}" alt="creative image">` : "";
  const buttons =
      `<button class="btn" onclick="verifyCreative(${c.id})">Run guardrails (Step 3a)</button>`
    + `<span class="rowline" style="display:inline-flex"><input type="file" id="img-${c.id}" accept="image/*" style="font-size:12px">
         <button class="btn secondary" onclick="uploadImage(${c.id})">Upload ${c.kind === "video" ? "keyframe" : "image"}</button></span>`
    + (["killed","approved","launched","live"].includes(c.status)
        ? `<button class="btn secondary" onclick="recordLearning(${c.id})">Record learning</button>` : "")
    + ((c.verdicts || []).some(v => v.verdict === "fail")
        ? `<button class="btn secondary" onclick="fixCreative(${c.id})">Fix failures with OpenAI</button>` : "");
  return `<div class="card">
    <div class="meta">#${c.id} · ${esc(c.game_name || "—")} · ${esc(exp ? exp.name : "")} · template ${esc(c.template_id || "—")}</div>
    <div class="qheadline">${esc(c.headline || "(no headline)")}</div>
    <div class="chiprow"><span class="chip status">${esc(c.status)}</span><span class="chip src">${esc(c.kind)}</span>`
    + (c.batch_id != null
        ? `<span class="chip" style="background:${batchColor(c.batch_id)}">GENERATED · batch #${c.batch_id}</span>`
        : `<span class="chip src">MANUAL / SEEDED</span>`)
    + (c.format ? `<span class="chip src">format ${esc(c.format)}</span>` : "")
    + (c.cta ? `<span class="chip cta">CTA: ${esc(c.cta)}</span>` : "")
    + (c.offer_text ? `<span class="chip offer">Offer: ${esc(c.offer_text)}</span>` : "")
    + lintChips(c)
    + `</div>
    ${img}
    <div class="copy"><span class="lbl">Primary text</span>\n${esc(c.primary_text)}</div>
    ${c.description ? `<div class="copy"><span class="lbl">Description</span>\n${esc(c.description)}</div>` : ""}
    ${c.art_direction ? `<div class="artdir"><span class="lbl">Art direction</span>${esc(c.art_direction)}</div>` : ""}
    ${verdictChips(c)}
    <div style="margin-top:10px">${buttons}</div>
  </div>`;
}

function batchTitle(batchId, members){
  const info = batchInfo(batchId);
  const hyp = parseHypothesis(info ? (info.hypothesis || "") : "");
  if (hyp.angle) return hyp.angle;
  if (info && info.hypothesis) return String(info.hypothesis).slice(0, 60);
  const exp = experiments.find(e => e.id === Number(members[0].experiment_id));
  if (exp && exp.hypothesis){
    const eh = parseHypothesis(exp.hypothesis);
    if (eh.angle) return eh.angle;
    return String(exp.hypothesis).slice(0, 60);
  }
  return "Batch #" + batchId;
}
function batchHeaderHtml(batchId, members){
  const color = batchColor(batchId);
  const info = batchInfo(batchId);
  const hyp = parseHypothesis(info ? (info.hypothesis || "") : "");
  const drafts = members.filter(c => c.status === "generated");
  const verified = members.every(c => c.verdicts && c.verdicts.length);
  const exp = experiments.find(e => e.id === Number(members[0].experiment_id));
  const meta = [];
  if (hyp.hook_type) meta.push("Hook: " + esc(hyp.hook_type));
  if (hyp.game) meta.push("Product: " + esc(hyp.game));
  const ol = offerShortLabel(hyp.offer);
  if (ol) meta.push("Offer: " + esc(ol));
  if (exp) meta.push(esc(exp.name));
  if (info && info.created_at) meta.push(esc(info.created_at));
  meta.push(`${drafts.length} draft${drafts.length === 1 ? "" : "s"} · ${members.length} creative${members.length === 1 ? "" : "s"}`);
  const statusPill = verified
    ? '<span class="chip pass">Verified</span>'
    : '<span class="chip needsverify">Needs verify</span>';
  let controls;
  if (drafts.length){
    const est = batchVerifyState[batchId];
    const estHtml = est
      ? `Verify estimate: ${cost(est.cost_usd)} <span class="chip src">estimate</span> for ${est.creatives} draft creative(s) via ${esc(est.provider)}`
      : "Fetching verify estimate…";
    controls = `<div class="rowline" style="margin-top:8px">
      <span class="msg" id="bv-est-${batchId}">${estHtml}</span>
      <button class="btn" id="bv-confirm-${batchId}" ${est ? "" : "disabled"} onclick="batchVerifyRun(${batchId})">Confirm &amp; verify batch</button>
    </div>`;
  } else {
    controls = `<div class="formnote" style="margin:8px 0 0">No drafts left in this batch — every creative has been verified. Verdicts are on the cards below; passed drafts are waiting in Approvals.</div>`;
  }
  return `<div class="batch-head" style="background:${color}1f;border-bottom:3px solid ${color}">`
    + `<div class="batch-title"><span class="gendot" style="background:${color}"></span><span class="chip genpill" style="background:${color}">GENERATED</span> ${esc(batchTitle(batchId, members))}</div>`
    + `<div class="ctx" style="margin-top:5px">${meta.join(" · ")} <span class="chip" style="background:${color}">batch #${batchId}</span> ${statusPill}</div>`
    + controls
    + `<div class="rowline" style="margin-top:8px"><button class="btn danger" onclick="clearBatch(${batchId})">Clear batch</button>`
    + `<span class="formnote" style="margin:0">Deletes this batch's creatives, their verdicts and performance rows, and its generated image files. Nothing else is touched.</span></div>`
    + `</div>`;
}

async function fillBatchEstimate(batchId){
  // estimate_verify_batch makes NO provider calls server-side (it only
  // prices the run), so fetching it while rendering spends nothing.
  const est = await fetch(API + "verify.php",
    jsonOpts({action: "estimate_verify_batch", batch_id: batchId})).then(r => r.json());
  const slot = document.getElementById("bv-est-" + batchId);
  if (!slot) return;  // queue re-rendered while the estimate was in flight
  if (est.error){ slot.innerHTML = `<span class="msg err">${esc(est.error)}</span>`; return; }
  batchVerifyState[batchId] = est;
  slot.innerHTML = `Verify estimate: ${cost(est.cost_usd)} <span class="chip src">estimate</span> for ${est.creatives} draft creative(s) via ${esc(est.provider)}`;
  const btn = document.getElementById("bv-confirm-" + batchId);
  if (btn) btn.disabled = false;
}

async function batchVerifyRun(batchId){
  if (!batchVerifyState[batchId]){ alert("Wait for the verify estimate in the batch header first."); return; }
  const slot = document.getElementById("bv-est-" + batchId);
  if (slot) slot.innerHTML = "Verifying…";
  const out = await fetch(API + "verify.php",
    jsonOpts({action: "verify_batch", batch_id: batchId, confirm: true})).then(r => r.json());
  if (out.error){ if (slot) slot.innerHTML = `<span class="msg err">${esc(out.error)}</span>`; return; }
  const counts = Object.entries(out.outcomes || {}).map(([k, v]) => `${k}: ${v}`).join(", ");
  alert(`Batch #${batchId} verified ${out.verified} creative(s). Outcomes — ${counts}. Verdicts are on the cards in this batch below; passed drafts are in Approvals.`);
  delete batchVerifyState[batchId];  // draft set changed; header re-estimates
  highlightBatchId = batchId;
  await loadAll();
  gotoTab("approvals");
}

// Clearing (Update 3): destructive, but confirmed twice — the native
// confirm() states exactly what will be deleted, and the server
// refuses without confirm:true. Clearing calls no provider.
async function clearBatch(batchId){
  const members = creatives.filter(c => Number(c.batch_id) === Number(batchId));
  const title = members.length ? batchTitle(batchId, members) : ("Batch #" + batchId);
  const ok = confirm("Delete batch \"" + title + "\" (#" + batchId + ")?\n\n"
    + "This permanently deletes its " + members.length + " creative(s), their guardrail verdicts "
    + "and performance rows, and any generated image files. This cannot be undone.");
  if (!ok) return;
  const out = await api("generate.php", jsonOpts({action: "clear_batch", batch_id: batchId, confirm: true}));
  if (out.error){ alert(out.error); return; }
  delete batchVerifyState[batchId];
  await loadAll();
  gotoTab("queue");
}
async function clearQueue(){
  const batched = creatives.filter(c => c.batch_id != null);
  const nb = new Set(batched.map(c => Number(c.batch_id))).size;
  const unbatched = creatives.length - batched.length;
  const ok = confirm("Clear the generated queue for this workspace?\n\n"
    + "This permanently deletes " + nb + " generated batch(es) and their " + batched.length
    + " creative(s), verdicts, performance rows and generated image files.\n\n"
    + "Manual / seeded creatives (" + unbatched + ") are NOT touched. This cannot be undone.");
  if (!ok) return;
  const out = await api("generate.php", jsonOpts({action: "clear_queue", confirm: true}));
  if (out.error){ alert(out.error); return; }
  Object.keys(batchVerifyState).forEach(k => delete batchVerifyState[k]);
  await loadAll();
  gotoTab("queue");
}

function renderQueue(){
  const el = $("#tab-queue");
  const expOpts = experiments.map(e => `<option value="${e.id}">${esc(e.name)}</option>`).join("");
  let html = `<div class="card"><h3>New creative</h3>
      <div class="formnote">Manual entry form. Generated batches (Step 2, in Experiments above) land HERE, grouped by batch with full image + copy previews: this stage is the review stage. Each batch header shows the Jev verify estimate, and Confirm &amp; verify batch (Step 3a) runs from the header, behind the same estimate-then-confirm gate. Single creatives can still be verified one by one on their cards.</div>
      <form id="creative-form">
        <label>Experiment<select name="experiment_id">${expOpts}</select></label>
        <label>Kind<select name="kind"><option>static</option><option>video</option><option>ugc</option></select></label>
        <label>Product / entity name<input name="game_name"></label>
        <label>Format<select name="format"><option>4:5</option><option>1:1</option><option>9:16</option></select></label>
        <label>Headline<input name="headline"></label>
        <label>CTA<input name="cta" placeholder="Try it free"></label>
        <label>Offer text<input name="offer_text"></label>
        <label>Template ID<input name="template_id" placeholder="tmpl-..."></label>
        <label>Primary text<textarea name="primary_text"></textarea></label>
        <label>Description<textarea name="description"></textarea></label>
      </form>
      <button class="btn" onclick="submitCreative()">Add creative</button>
      <div class="rowline" style="margin-top:14px"><button class="btn danger" onclick="clearQueue()">Clear queue (generated only)</button>
      <span class="formnote" style="margin:0">Deletes ALL generated batches in this workspace — their creatives, verdicts, performance rows and image files. Manual / seeded creatives are NOT touched.</span></div></div>`;
  const batches = new Map();
  const singles = [];
  creatives.forEach(c => {
    if (c.batch_id != null){
      const bid = Number(c.batch_id);
      if (!batches.has(bid)) batches.set(bid, []);
      batches.get(bid).push(c);
    } else singles.push(c);
  });
  const batchIds = [...batches.keys()].sort((a, b) => b - a);
  batchIds.forEach(bid => {
    const members = batches.get(bid);
    html += `<div class="batch-group${highlightBatchId === bid ? " flash" : ""}" id="batch-group-${bid}" style="border-left:6px solid ${batchColor(bid)}">`
      + batchHeaderHtml(bid, members)
      + `<div class="batch-body">` + members.map(c => queueCard(c)).join("") + `</div></div>`;
  });
  if (singles.length){
    html += `<div class="batch-group manual-group"><div class="batch-head manual-head">`
      + `<div class="batch-title"><span class="chip src">MANUAL / SEEDED</span> Manual &amp; seeded creatives</div>`
      + `<div class="ctx" style="margin-top:5px">Entered by hand or seeded with the workspace — never generated, and never touched by Clear batch or Clear queue.</div>`
      + `</div><div class="batch-body">` + singles.map(c => queueCard(c)).join("") + `</div></div>`;
  }
  el.innerHTML = html;
  if (highlightBatchId != null){
    const g = document.getElementById("batch-group-" + highlightBatchId);
    if (g) g.scrollIntoView({block: "start"});
    highlightBatchId = null;
  }
  batchIds.forEach(bid => {
    if (!batchVerifyState[bid]
        && batches.get(bid).some(c => c.status === "generated"))
      fillBatchEstimate(bid);
  });
}
async function submitCreative(){
  const f = new FormData($("#creative-form"));
  const body = Object.fromEntries(f.entries());
  body.workspace_id = WS;
  body.experiment_id = Number(body.experiment_id);
  await api("creatives.php", jsonOpts(body));
  loadAll();
}
async function verifyCreative(id){
  // Estimate-then-confirm gate: verification can spend Jev budget, so
  // the estimate is shown and confirmed before any provider call.
  const est = await fetch(API + "verify.php",
    jsonOpts({creative_id: id, action: "estimate_verify"})).then(r => r.json());
  if (est.error){ alert(est.error); return; }
  const ok = confirm("Verify creative #" + id + " (Step 3a)?\n"
    + "Estimated cost: " + cost(est.cost_usd) + " (estimate) via " + est.provider + ".\n"
    + est.note + "\n\nNothing runs until you confirm.");
  if (!ok) return;
  const out = await fetch(API + "verify.php",
    jsonOpts({creative_id: id, action: "verify", confirm: true})).then(r => r.json());
  if (out.error){ alert(out.error); return; }
  if (out.verifier) alert("Verifier: " + out.verifier.provider + " / " + out.verifier.model + " — " + out.verifier.note);
  loadAll();
}
async function fixCreative(id){
  // Same estimate-then-confirm gate as verifyCreative: the fix
  // rerun spends copy-model budget, so the cost shows first.
  const est = await fetch(API + "fix_creative.php",
    jsonOpts({creative_id: id, action: "estimate_fix"})).then(r => r.json());
  if (est.error || est.ok === false){ alert(est.error || "Nothing to fix."); return; }
  const ok = confirm("Fix creative #" + id + " with OpenAI?\n"
    + "Failed rules: " + (est.failed_rules || []).join(", ") + "\n"
    + "Estimated cost: " + cost(est.cost_usd) + " (estimate) via " + est.model + ".\n"
    + est.note + "\n\nThe rewrite replaces the copy on this creative and clears its verdicts (re-verify after). If the headline changes, its image is detached for regeneration.\n\nNothing runs until you confirm.");
  if (!ok) return;
  const out = await fetch(API + "fix_creative.php",
    jsonOpts({creative_id: id, action: "fix", confirm: true})).then(r => r.json());
  if (out.error || out.ok === false){ alert(out.error || "Fix failed."); return; }
  alert("Creative #" + id + " rewritten. Changed: "
    + (out.changed && out.changed.length ? out.changed.join(", ") : "nothing")
    + ". Actual cost: " + cost(out.cost_usd)
    + (out.image_detached ? ". Its image was detached because the headline changed." : "")
    + "\nVerdicts cleared — run guardrails (Step 3a) to check the new copy.");
  loadAll();
}
async function uploadImage(id){
  const input = document.getElementById("img-" + id);
  if (!input.files.length){ alert("Pick an image file first."); return; }
  const file = input.files[0];
  const buf = await file.arrayBuffer();
  let bin = ""; const bytes = new Uint8Array(buf);
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
  const out = await fetch(API + "creatives.php",
    jsonOpts({action: "image", creative_id: id, filename: file.name,
              image_base64: btoa(bin)})).then(r => r.json());
  if (out.error || out.ok === false) alert(out.error || "Upload failed.");
  loadAll();
}
async function recordLearning(id){
  const final_verdict = prompt("Final verdict for creative #" + id + " (e.g. pass / fail / kill):");
  if (!final_verdict) return;
  const reason = prompt("Reason (what did we learn?):") || "";
  await api("learnings.php", jsonOpts({workspace_id: WS, creative_id: id, final_verdict, reason}));
  loadAll(); gotoTab("learnings");
}

// ---------- approvals ----------
function renderApprovals(){
  const el = $("#tab-approvals");
  const pending = creatives.filter(c => c.status === "claude_review");
  el.innerHTML = (pending.length ? "" : '<div class="empty">Nothing waiting. Creatives land here after passing the enabled guardrails (Step 3a). Human approval is Step 5 and is never automated away.</div>')
    + pending.map(c => creativeCard(c,
        `<button class="btn" onclick="decide(${c.id},'approve')">Approve</button>
         <button class="btn danger" onclick="decide(${c.id},'reject')">Reject → edits</button>`
      )).join("");
}
async function decide(id, action){
  await fetch(API + "verify.php", jsonOpts({creative_id: id, action}));
  loadAll();
}

// ---------- launch board ----------
function renderLaunch(){
  const el = $("#tab-launch");
  const launched = creatives.filter(c => ["approved","launched","live","scale_proposed"].includes(c.status));
  el.innerHTML = `<div class="banner">⚠ Meta connection pending Step 0: gambling permission + app review. Uploads simulated — approved creatives are staged here, nothing is sent to Meta.</div>`
    + (launched.length ? "" : '<div class="empty">No approved creatives yet.</div>')
    + launched.map(c => creativeCard(c,
        `<span class="chip src">upload: simulated</span> <span class="chip src">utm_campaign: exp-${c.experiment_id}-creative-${c.id}</span>`
      )).join("");
}

// ---------- performance (Update 4: computed verdicts only) ----------
// Pass / Fail / Kill is a pure threshold evaluation against each
// experiment's stored thresholds — there is NO manual verdict
// control anywhere. The status chip here is read-only; loading this
// tab re-runs the evaluation server-side, and the cron endpoint
// runs it on a timer. Nothing on this tab can be edited by hand.
function perfChip(p){
  const cls = {pass: "pass", fail: "failstate", kill: "kill",
               monitoring: "src"}[p.perf_status] || "src";
  return `<span class="chip ${cls}">${esc(String(p.perf_status || "monitoring").toUpperCase())}</span>`
    + (p.perf_status_reason
        ? `<div class="meta" style="margin-top:4px">${esc(p.perf_status_reason)}</div>` : "");
}
function renderPerformance(){
  const el = $("#tab-performance");
  const withData = perfBoard.filter(p => p.days && p.days.length);
  if (!withData.length){
    el.innerHTML = '<div class="empty">No performance data yet. When performance rows exist for a creative, its verdict is computed here automatically — pass / fail / kill comes from the experiment\'s thresholds, never from a manual choice.</div>';
    return;
  }
  el.innerHTML = `<table><tr><th>Creative</th><th>Experiment thresholds</th><th>Spend</th>
      <th>Customers</th><th>CAC</th><th>Computed verdict</th></tr>`
    + withData.map(p => `<tr>
        <td>#${p.creative.id} ${esc(p.creative.game_name || "")}<br><span class="chip status">${esc(p.creative.status)}</span></td>
        <td>${esc(p.experiment ? p.experiment.pass_metric : "")}<br>${esc(p.experiment ? p.experiment.fail_metric : "")}<br>${esc(p.experiment ? p.experiment.kill_metric : "")}</td>
        <td>${money(p.total_spend)}</td><td>${p.total_customers}</td>
        <td>${money(p.cac)}</td>
        <td>${perfChip(p)}</td></tr>`).join("")
    + `</table><div class="formnote" style="margin-top:10px">Verdicts are computed, not chosen: KILL when CAC &gt; the kill threshold after the spend floor; FAIL when CAC is above the fail value; PASS when CAC is at or below the pass value; otherwise MONITORING. Precedence: KILL &gt; FAIL &gt; PASS. A PASS / FAIL / KILL outcome records a learning, and a transition into FAIL or KILL emails the address in Settings. Unattributed revenue is a program-level adjustment and is never assigned per-creative.</div>`;
}

// ---------- learnings ----------
function renderLearnings(){
  const el = $("#tab-learnings");
  el.innerHTML = (learnings.length ? "" : '<div class="empty">No learnings recorded yet. Use “Record learning” on a killed or approved creative in the Creative Queue.</div>')
    + learnings.map(l => {
        const c = creatives.find(x => x.id === l.creative_id);
        return `<div class="card"><h3>Creative #${l.creative_id} ${c ? "· " + esc(c.game_name || "") : ""}
          <span class="chip ${l.final_verdict === "pass" ? "pass" : l.final_verdict === "kill" ? "fail" : "status"}">${esc(l.final_verdict)}</span></h3>
          <div class="meta">${esc(l.created_at)}</div><div class="copy">${esc(l.reason)}</div></div>`;
      }).join("");
}

// ---------- settings ----------
// Update 5 (extended): the whole panel wears the active workspace's
// brand. From the token palette: --primary is the first color, --bg
// is the darkest color when it is truly dark, --text is the lightest
// when it is truly light, and --gold (badges, offer chips) is the
// most saturated of what remains. Surfaces (--panel, --panel2,
// --line, --muted) derive from --bg/--text in CSS via color-mix, so
// every workspace repaints the chrome, not just the accent. A
// palette that lacks a dark or a light leaves that slot at the
// panel default; fewer than two valid colors resets everything.
function hexLuminance(hex){
  const n = parseInt(hex.slice(1), 16);
  const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
  return 0.2126 * f((n >> 16) & 255) + 0.7152 * f((n >> 8) & 255) + 0.0722 * f(n & 255);
}
function hexSaturation(hex){
  const n = parseInt(hex.slice(1), 16);
  const r = ((n >> 16) & 255) / 255, g = ((n >> 8) & 255) / 255, b = (n & 255) / 255;
  const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
  if (mx === mn) return 0;
  const l = (mx + mn) / 2;
  return l > 0.5 ? (mx - mn) / (2 - mx - mn) : (mx - mn) / (mx + mn);
}
function applyWorkspaceTheme(){
  const root = document.documentElement.style;
  const props = ["--primary", "--bg", "--text", "--gold"];
  const colors = ((tokens && tokens.colors) || []).map(brandHex).filter(Boolean);
  if (colors.length < 2){ props.forEach(p => root.removeProperty(p)); return; }
  const byLum = [...colors].sort((a, b) => hexLuminance(a) - hexLuminance(b));
  const bgPick = hexLuminance(byLum[0]) < 0.2 ? byLum[0] : null;
  const textPick = hexLuminance(byLum[byLum.length - 1]) > 0.6 ? byLum[byLum.length - 1] : null;
  root.setProperty("--primary", colors[0]);
  if (bgPick) root.setProperty("--bg", bgPick); else root.removeProperty("--bg");
  if (textPick) root.setProperty("--text", textPick); else root.removeProperty("--text");
  const rest = colors.filter(c => c !== colors[0] && c !== bgPick && c !== textPick);
  if (rest.length){
    const accent2 = rest.reduce((a, b) => hexSaturation(b) > hexSaturation(a) ? b : a);
    root.setProperty("--gold", accent2);
  } else root.removeProperty("--gold");
}

// Update 5: the Settings tab wears the active workspace's brand.
// Palette band + swatches, brand fonts on the headings, and a brand
// card summarising the tokens — all derived from the same brand
// tokens the guardrail engine reads. A workspace with no tokens
// keeps the panel's default look.
const loadedBrandFonts = new Set();
function brandHex(c){
  return typeof c === "string" && /^#[0-9a-fA-F]{6}$/.test(c.trim()) ? c.trim() : null;
}
function brandFontName(f){
  return typeof f === "string" && /^[A-Za-z][A-Za-z0-9 ]{0,40}$/.test(f.trim()) ? f.trim() : null;
}
function loadBrandFont(name){
  if (!name || loadedBrandFonts.has(name)) return;
  loadedBrandFonts.add(name);
  const link = document.createElement("link");
  link.rel = "stylesheet";
  link.href = "https://fonts.googleapis.com/css2?family="
    + name.replace(/ /g, "+") + ":wght@400;600;800&display=swap";
  document.head.appendChild(link);
}
function brandTheme(){
  const t = tokens || {};
  const colors = (Array.isArray(t.colors) ? t.colors : []).map(brandHex).filter(Boolean);
  const fonts = (Array.isArray(t.fonts) ? t.fonts : []).map(brandFontName).filter(Boolean);
  return { colors, fonts,
           display: fonts[0] || null,
           body: fonts[1] || fonts[0] || null };
}
function brandCard(theme){
  const ws = workspaces.find(w => w.id === WS);
  const name = ws ? ws.name : "This workspace";
  const t = tokens || {};
  const rawColors = Array.isArray(t.colors) ? t.colors : [];
  const band = theme.colors.length
    ? `<div class="brandband" style="background:linear-gradient(90deg, ${theme.colors.join(", ")})"></div>` : "";
  const swatches = rawColors.length
    ? `<div class="swatches">${rawColors.map(c => {
        const hex = brandHex(c);
        return `<span class="swatch">${hex ? `<span class="sw" style="background:${hex}"></span>` : ""}${esc(c)}</span>`;
      }).join("")}</div>`
    : '<div class="empty">No palette colors yet — add them under Brand tokens below and this card picks them up.</div>';
  const typeLine = theme.fonts.length
    ? `<div class="brandmeta">Type: ${theme.fonts.map(esc).join(" · ")}</div>
       <div class="brandname" ${theme.display ? `style="font-family:'${theme.display}',sans-serif"` : ""}>${esc(name)}</div>
       ${t.tone_text ? `<div class="copy" ${theme.body ? `style="font-family:'${theme.body}',sans-serif"` : ""}>${esc(t.tone_text)}</div>` : ""}`
    : "";
  const ctas = (Array.isArray(t.cta_allowlist) ? t.cta_allowlist : [])
    .map(c => `<span class="chip cta">${esc(c)}</span>`).join("");
  const offers = (Array.isArray(t.offer_table) ? t.offer_table : [])
    .map(o => `<span class="chip offer">${esc(o.text || o.id || "")}</span>`).join("");
  const counts = `${(t.banned_words || []).length} banned terms · `
    + `${(t.compliance_strings || []).length} compliance strings · `
    + `${(t.offer_table || []).length} approved offers`;
  return `<div class="card"><h3>Brand identity — this workspace</h3>
    ${band}
    <div class="formnote">This tab dresses in the workspace's brand: the accent, the headings and this card all come from the brand tokens below — the same tokens the guardrail engine enforces.</div>
    ${swatches}
    ${typeLine}
    ${ctas ? `<div class="chiprow">${ctas}</div>` : ""}
    ${offers ? `<div class="chiprow">${offers}</div>` : ""}
    <div class="brandmeta">${esc(counts)}${guideline ? ` · guideline: ${esc(guideline.source_type)} · ${esc(guideline.source_ref)}` : ""}</div>
  </div>`;
}

const TOKEN_FIELDS = ["colors","fonts","cta_allowlist","banned_words","compliance_strings","offer_table"];
function renderSettings(){
  const el = $("#tab-settings");
  const theme = brandTheme();
  if (theme.display) loadBrandFont(theme.display);
  if (theme.body && theme.body !== theme.display) loadBrandFont(theme.body);
  el.style.setProperty("--brand-display",
    theme.display ? `'${theme.display}', sans-serif` : "");
  el.innerHTML = `
  ${brandCard(theme)}
  <div class="card"><h3>Workspaces</h3>
    <div class="formnote">Each workspace is one brand: its own tokens, rules, guideline doc, experiments and creatives. Switching the header dropdown switches everything.</div>
    <form id="ws-form">
      <label>Name<input name="name" required placeholder="New brand"></label>
      <label>Template<select name="template">
        <option value="blank">Blank (no tokens, rules disabled)</option>
        <option value="sweeps_casino">Sweeps casino (QuickMilly-derived, rules enabled)</option>
      </select></label>
    </form>
    <button class="btn" onclick="submitWorkspace()">Create workspace</button>
  </div>

  <div class="card"><h3>Brand tokens — this workspace</h3>
    <div class="formnote">The guardrail engine reads ALL of its lists, strings and offers from here. JSON fields must be valid JSON. Changes apply to the next verify run.</div>
    ${TOKEN_FIELDS.map(f => `<label style="font-size:12px;color:var(--muted)">${f}
      <textarea class="code" id="tok-${f}" style="width:100%">${esc(JSON.stringify(tokens ? tokens[f] : [], null, 1))}</textarea></label>`).join("")}
    <label style="font-size:12px;color:var(--muted)">tone_text
      <textarea class="code" id="tok-tone" style="width:100%">${esc(tokens ? tokens.tone_text : "")}</textarea></label>
    <div class="rowline"><button class="btn" onclick="saveTokens()">Save tokens</button>
    <span class="msg" id="tok-msg"></span></div>
  </div>

  <div class="card"><h3>Rules — this workspace</h3>
    <div class="formnote">Only enabled rules produce verdicts. A workspace with no enabled rules verifies nothing.</div>
    <table><tr><th>Rule</th><th>Name</th><th>Type</th><th>Enabled</th></tr>
    ${rules.map(r => `<tr><td>${r.rule_id}</td><td>${esc(r.name)}</td><td>${esc(r.type)}</td>
      <td><input type="checkbox" ${r.enabled ? "checked" : ""} onchange="toggleRule('${r.rule_id}', this.checked)"></td></tr>`).join("")}
    </table>
  </div>

  <div class="card"><h3>Guideline doc — this workspace</h3>
    ${guideline ? `<div class="meta">source: ${esc(guideline.source_type)} · ${esc(guideline.source_ref)} · fetched ${esc(guideline.extracted_at)}</div>
      <pre class="doc">${esc(guideline.raw_markdown)}</pre>`
      : '<div class="empty">No guideline doc stored for this workspace yet.</div>'}
    <h4>Paste</h4>
    <textarea class="code" id="gl-paste" style="width:100%" placeholder="Paste brand guidelines (markdown or plain text)…"></textarea>
    <div class="rowline"><button class="btn" onclick="savePaste()">Save pasted guidelines</button><span class="msg" id="gl-paste-msg"></span></div>
    <h4>Upload file</h4>
    <div class="rowline"><input type="file" id="gl-file" accept=".md,.txt,text/plain,text/markdown">
      <button class="btn secondary" onclick="uploadGuidelineFile()">Upload guideline file</button><span class="msg" id="gl-file-msg"></span></div>
    <h4>Fetch from Notion</h4>
    <div class="formnote">Needs a Notion integration token in the vault below, and the page shared with that integration. Failures are shown as-is; nothing is ever fabricated.</div>
    <div class="rowline"><input type="text" id="gl-notion-url" style="flex:1;min-width:280px" placeholder="https://www.notion.so/…">
      <button class="btn secondary" onclick="fetchNotion()">Fetch from Notion</button></div>
    <div class="msg" id="gl-notion-msg"></div>
    <h4>Extract tokens</h4>
    <div class="rowline"><button class="btn secondary" onclick="extractTokens()">Extract tokens from guideline doc (LLM)</button>
      <span class="msg" id="gl-extract-msg"></span></div>
  </div>

  <div class="card"><h3>API keys (vault)</h3>
    <div class="formnote">Keys are stored server-side only, write-only: they are never rendered back — only a masked tail. A key set in the environment also counts as configured (source shown). Note: vault values live in this panel's MySQL database on your hosting; every vault endpoint is session-gated.</div>
    ${keys.map(k => `<div class="rowline"><strong style="width:90px">${k.provider}</strong>
      <span class="chip ${k.configured ? "pass" : "src"}">${k.configured ? "set" + (k.masked ? " " + esc(k.masked) : "") + (k.source ? " · " + esc(k.source) : "") : "not set"}</span>
      <input type="password" id="key-${k.provider}" placeholder="Paste ${k.provider} API key" style="flex:1;min-width:220px">
      <button class="btn" onclick="saveKey('${k.provider}')">Set / replace</button>
      <button class="btn danger" onclick="removeKey('${k.provider}')">Remove</button></div>`).join("")}
  </div>

  <div class="card"><h3>Provider routing</h3>
    <div class="formnote">Which provider + model runs each task. Generation (copy_gen, art_gen) is real in this build and runs from the Experiments tab, behind the estimate-then-confirm gate; copy_gen must stay routed to openai.</div>
    <table><tr><th>Task</th><th>Provider</th><th>Model</th><th></th></tr>
    ${routing.map(r => `<tr><td>${esc(r.task)}</td>
      <td><input type="text" id="route-p-${esc(r.task)}" value="${esc(r.provider || "")}"></td>
      <td><input type="text" id="route-m-${esc(r.task)}" value="${esc(r.model || "")}"></td>
      <td><button class="btn secondary" onclick="saveRouting('${esc(r.task)}')">Save</button></td></tr>`).join("")}
    </table>
  </div>

  <div class="card"><h3>Call ledger</h3>
    <div class="formnote">Every provider call — verify runs, generation runs, guideline fetches, extraction attempts — is logged with the provider that ACTUALLY ran and, for generation, the tokens used and the actual cost from the API's usage. A Jev failure falls back to the local engine and the ledger says so.</div>
    ${ledger.length ? `<table><tr><th>When</th><th>WS</th><th>Task</th><th>Provider</th><th>Model</th><th>Status</th><th>Note</th></tr>
      ${ledger.map(l => `<tr><td>${esc(l.ts)}</td><td>${l.workspace_id ?? "—"}</td><td>${esc(l.task)}</td>
        <td>${esc(l.provider || "—")}</td><td>${esc(l.model || "—")}</td><td>${esc(l.status)}</td>
        <td>${esc(l.note || "")}</td></tr>`).join("")}</table>`
      : '<div class="empty">No calls logged yet. Run a guardrail verification.</div>'}
  </div>

  <div class="card"><h3>Notifications — fail / kill emails (this workspace)</h3>
    ${settingsHint()}
    <div class="formnote">When a creative's computed verdict TRANSITIONS into FAIL or KILL, one email goes to this address — it never re-sends while the status stays the same. Sends are attempted when evaluation runs: opening the Performance tab, or on the cron timer below. Every attempt is logged to the ledger above (provider: mail).</div>
    <div class="rowline"><label style="font-size:12px;color:var(--muted)">Email<input type="text" id="set-notify-email" value="${esc(panelSettings ? panelSettings.notify_email : "")}" placeholder="you@example.com" style="min-width:260px"></label>
    <label style="font-size:12px;color:var(--muted)"><input type="checkbox" id="set-notify-fail" ${!panelSettings || panelSettings.notify_on_fail ? "checked" : ""}> Email on FAIL</label>
    <label style="font-size:12px;color:var(--muted)"><input type="checkbox" id="set-notify-kill" ${!panelSettings || panelSettings.notify_on_kill ? "checked" : ""}> Email on KILL</label></div>
    <div class="rowline"><button class="btn" onclick="savePanelSettings()">Save notification settings</button>
    <button class="btn secondary" onclick="sendTestEmail()">Send test email</button>
    <span class="msg" id="set-msg"></span></div>
  </div>

  <div class="card"><h3>Learnings loop — Notion doc + generation prompt (this workspace)</h3>
    ${settingsHint()}
    <div class="formnote">OUT: every learning recorded for this workspace (performance verdicts, guardrail failures, manual records) is appended to a Notion experiments doc — one entry per learning, verdict first. Needs a Notion integration token in the vault above AND this page shared with that integration in Notion. Sync failures never block anything; unsynced rows wait here for the retry button.<br>
    IN: the newest 12 learnings are compressed to one line each and fed into the generation prompt, so new creative starts from what previous experiments taught. The Generate panel shows how many will ride along before you confirm.</div>
    <div class="rowline"><label style="font-size:12px;color:var(--muted);flex:1">Notion experiments page (URL or page id)<input type="text" id="set-learnings-page" value="${esc(panelSettings ? panelSettings.learnings_page_id : "")}" placeholder="https://www.notion.so/…experiments…" style="width:100%"></label>
    <label style="font-size:12px;color:var(--muted)"><input type="checkbox" id="set-learn-prompt" ${!panelSettings || panelSettings.use_learnings_in_prompt ? "checked" : ""}> Include learnings in generation prompts</label></div>
    <div class="rowline"><button class="btn" onclick="savePanelSettings()">Save learnings settings</button>
    <button class="btn secondary" onclick="pushLearnings()">Push pending learnings to Notion</button>
    <span class="msg" id="learn-msg"></span></div>
  </div>

  <div class="card"><h3>Evaluation cron — true push (optional)</h3>
    ${settingsHint()}
    <div class="formnote">Evaluation already runs when the Performance tab opens. For push without opening the panel, point an hPanel cron job (every ~15 minutes) at this URL. The token is the whole credential — regenerate it if it leaks.</div>
    <div class="rowline"><input type="text" readonly value="${esc(cronUrl())}" style="flex:1;min-width:320px;font-size:12px">
    <button class="btn secondary" onclick="regenCronToken()">Regenerate token</button>
    <span class="msg" id="cron-msg"></span></div>
  </div>

  <div class="card" style="border-color:var(--fail)"><h3>Danger zone — clear all data (this workspace)</h3>
    <div class="formnote">Deletes ALL of this workspace's work: experiments, creatives and their image files, guardrail verdicts, performance rows, generation batches and learnings. This is also how installs from before Update 4 purge their seeded demo rows.<br>
    <strong>Preserved, always:</strong> the workspace itself, brand tokens, rules, the guideline doc, these settings, API keys, routing, the call ledger and the admin login.</div>
    <div class="rowline"><button class="btn danger" onclick="clearAllData()">Clear all data for this workspace</button>
    <span class="msg" id="clear-msg"></span></div>
  </div>`;
}
function settingsHint(){
  return panelSettings && panelSettings.settings_ready === false
    ? '<div class="banner red">Settings storage is not ready: run sql/migration-003.sql once in phpMyAdmin (see UPDATE.md). Until then these controls show defaults and do not save.</div>' : "";
}
function cronUrl(){
  const base = window.location.origin
    + window.location.pathname.replace(/[^/]*$/, "");
  const tok = panelSettings ? (panelSettings.cron_token || "") : "";
  return base + "api/cron_evaluate.php?token=" + (tok || "<generate one below>");
}
async function savePanelSettings(){
  const body = {action: "save", workspace_id: WS,
    notify_email: $("#set-notify-email").value.trim(),
    notify_on_fail: $("#set-notify-fail").checked,
    notify_on_kill: $("#set-notify-kill").checked,
    learnings_page_id: $("#set-learnings-page").value.trim(),
    use_learnings_in_prompt: $("#set-learn-prompt").checked};
  const out = await api("settings.php", jsonOpts(body));
  const m = $("#set-msg"), m2 = $("#learn-msg");
  if (out.error){
    [m, m2].forEach(x => { if (x){ x.className = "msg err"; x.textContent = out.error + (out.hint ? " " + out.hint : ""); } });
    return;
  }
  panelSettings = await api("settings.php").catch(() => panelSettings);
  [m, m2].forEach(x => { if (x){ x.className = "msg ok"; x.textContent = "Saved."; } });
}
async function sendTestEmail(){
  const m = $("#set-msg");
  m.className = "msg"; m.textContent = "Sending…";
  const out = await api("settings.php", jsonOpts({action: "test_email", workspace_id: WS}));
  if (out.error){ m.className = "msg err"; m.textContent = out.error; return; }
  m.className = "msg " + (out.ok ? "ok" : "err");
  m.textContent = out.ok ? "Test email sent (check the inbox; the attempt is in the ledger)." : (out.error || "Send failed.");
}
async function pushLearnings(){
  const m = $("#learn-msg");
  m.className = "msg"; m.textContent = "Pushing…";
  const out = await api("settings.php", jsonOpts({action: "push_learnings", workspace_id: WS}));
  if (out.error){ m.className = "msg err"; m.textContent = out.error + (out.hint ? " " + out.hint : ""); return; }
  m.className = "msg " + (out.pending === 0 ? "ok" : "");
  m.textContent = `Synced ${out.synced}, still pending ${out.pending}.`
    + (out.notion_configured ? "" : " Notion is not fully configured for this workspace (vault token + page id + page shared with the integration).");
  learnings = await api("learnings.php");
}
async function regenCronToken(){
  const m = $("#cron-msg");
  const out = await api("settings.php", jsonOpts({action: "regenerate_cron_token"}));
  if (out.error){ m.className = "msg err"; m.textContent = out.error + (out.hint ? " " + out.hint : ""); return; }
  if (panelSettings) panelSettings.cron_token = out.cron_token;
  renderSettings();
  const m2 = $("#cron-msg");
  if (m2){ m2.className = "msg ok"; m2.textContent = "New token generated — update the cron URL wherever it is used."; }
}
async function clearAllData(){
  const wsName = (workspaces.find(w => w.id === WS) || {}).name || ("#" + WS);
  const perfRows = perfBoard.reduce((n, p) => n + ((p.days || []).length), 0);
  const ok = confirm("Clear ALL data for workspace \"" + wsName + "\"?\n\n"
    + "This permanently deletes: " + experiments.length + " experiment(s), "
    + creatives.length + " creative(s) and their image files, "
    + learnings.length + " learning(s), all guardrail verdicts, "
    + perfRows + " performance row(s) and all generation batches.\n\n"
    + "PRESERVED: the workspace itself, brand tokens, rules, the guideline doc, settings, API keys, routing, the call ledger and the admin login.\n\n"
    + "This cannot be undone.");
  if (!ok) return;
  const m = $("#clear-msg");
  const out = await api("settings.php", jsonOpts({action: "clear_data", workspace_id: WS, confirm: true}));
  if (out.error){ if (m){ m.className = "msg err"; m.textContent = out.error; } return; }
  const d = out.deleted || {};
  alert("Workspace \"" + wsName + "\" cleared. Deleted: "
    + Object.entries(d).map(([k, v]) => `${k}: ${v}`).join(", ") + ".");
  await loadAll();
  gotoTab("settings");
}
async function submitWorkspace(){
  const f = new FormData($("#ws-form"));
  const body = Object.fromEntries(f.entries());
  const out = await fetch(API + "workspaces.php", jsonOpts(body)).then(r => r.json());
  if (out.id){ WS = out.id; }
  loadAll();
}
async function saveTokens(){
  const body = {workspace_id: WS, tone_text: $("#tok-tone").value};
  try {
    TOKEN_FIELDS.forEach(f => body[f] = JSON.parse($("#tok-" + f).value || "null"));
  } catch (e) {
    $("#tok-msg").className = "msg err"; $("#tok-msg").textContent = "Invalid JSON: " + e.message; return;
  }
  const out = await fetch(API + "tokens.php",
    {method: "POST", headers: {"Content-Type": "application/json"},
     body: JSON.stringify(body)}).then(r => r.json());
  tokens = out;
  $("#tok-msg").className = "msg ok"; $("#tok-msg").textContent = "Saved. Next verify run uses these tokens.";
  renderSettings();
}
async function toggleRule(ruleId, enabled){
  await fetch(API + "rules.php", jsonOpts({workspace_id: WS, rule_id: ruleId, enabled}));
  rules = await fetch(API + "rules.php?workspace_id=" + WS).then(r => r.json());
}
async function savePaste(){
  const text = $("#gl-paste").value;
  const out = await fetch(API + "guidelines.php",
    jsonOpts({workspace_id: WS, action: "paste", text})).then(r => r.json());
  const m = $("#gl-paste-msg");
  if (out.ok){ m.className = "msg ok"; m.textContent = "Stored."; guideline = out.guideline; renderSettings(); }
  else { m.className = "msg err"; m.textContent = out.error || "Failed."; }
}
async function uploadGuidelineFile(){
  const input = $("#gl-file");
  const m = $("#gl-file-msg");
  if (!input.files.length){ m.className = "msg err"; m.textContent = "Pick a file first."; return; }
  const file = input.files[0];
  const buf = await file.arrayBuffer();
  let bin = ""; const bytes = new Uint8Array(buf);
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
  const out = await fetch(API + "guidelines.php",
    jsonOpts({workspace_id: WS, action: "file", filename: file.name,
              content_base64: btoa(bin)})).then(r => r.json());
  if (out.ok){ m.className = "msg ok"; m.textContent = "Stored."; guideline = out.guideline; renderSettings(); }
  else { m.className = "msg err"; m.textContent = out.error || "Failed."; }
}
async function fetchNotion(){
  const url = $("#gl-notion-url").value.trim();
  const m = $("#gl-notion-msg");
  m.className = "msg"; m.textContent = "Fetching…";
  const out = await fetch(API + "guidelines.php",
    jsonOpts({workspace_id: WS, action: "notion", page_url: url})).then(r => r.json());
  if (out.ok){ m.className = "msg ok"; m.textContent = "Fetched and stored."; guideline = out.guideline; renderSettings(); }
  else { m.className = "msg err"; m.textContent = out.error || "Fetch failed."; }
}
async function extractTokens(){
  const m = $("#gl-extract-msg");
  const out = await fetch(API + "guidelines.php",
    jsonOpts({workspace_id: WS, action: "extract"})).then(r => r.json());
  m.className = "msg " + (out.ok ? "ok" : "err");
  m.textContent = out.message || out.error || "";
}
async function saveKey(provider){
  const key = document.getElementById("key-" + provider).value.trim();
  if (!key){ alert("Paste a key first."); return; }
  await fetch(API + "keys.php", jsonOpts({provider, key}));
  loadAll();
}
async function removeKey(provider){
  await fetch(API + "keys.php", jsonOpts({provider, action: "delete"}));
  loadAll();
}
async function saveRouting(task){
  const provider = document.getElementById("route-p-" + task).value.trim();
  const model = document.getElementById("route-m-" + task).value.trim();
  await fetch(API + "routing.php", jsonOpts({task, provider, model}));
  loadAll();
}

loadAll();
