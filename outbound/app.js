/* Outbound Engine single page app. Vanilla JS, no build step.
   State rule: the active client id is never kept in a cookie or the
   session; it rides along explicitly on every API request. */
'use strict';

const state = {
  clients: [],
  activeClientId: null,
  view: 'dashboard',
  settings: null,
  queueMatchId: null,
  lastReport: null,
};

const VIEWS = ['dashboard', 'clients', 'icp', 'discovery', 'queue',
               'campaigns', 'reporting', 'settings'];

// --- small helpers -----------------------------------------------------------

function esc(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function $(sel, root) {
  return (root || document).querySelector(sel);
}

function toast(msg) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.remove('hidden');
  setTimeout(() => t.classList.add('hidden'), 4200);
}

function viewEl() { return $('#view'); }

function errBox(err) {
  return '<div class="err">' + esc(err.message || String(err)) + '</div>';
}

// --- API ---------------------------------------------------------------------

async function apiFetch(url, options) {
  const res = await fetch(url, options || {});
  let data = null;
  try { data = await res.json(); } catch (e) { data = null; }
  if (!res.ok || (data && data.error)) {
    const msg = (data && (data.message || data.hint)) || (data && data.error)
      || ('Request failed (HTTP ' + res.status + ')');
    const err = new Error(msg);
    err.data = data;
    err.status = res.status;
    throw err;
  }
  return data;
}

function apiGet(path, params) {
  const q = new URLSearchParams(params || {});
  if (state.activeClientId && !q.has('client_id')) {
    q.set('client_id', state.activeClientId);
  }
  const qs = q.toString();
  return apiFetch('api/' + path + (qs ? '?' + qs : ''));
}

function apiPost(path, body) {
  const payload = Object.assign({}, body || {});
  if (state.activeClientId && payload.client_id === undefined
      && !payload.omit_client) {
    payload.client_id = state.activeClientId;
  }
  delete payload.omit_client;
  return apiFetch('api/' + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
}

// --- modal + estimate confirmation ---------------------------------------------

function showModal(title, bodyHtml, confirmLabel) {
  return new Promise((resolve) => {
    $('#modalTitle').textContent = title;
    $('#modalBody').innerHTML = bodyHtml;
    $('#modalConfirm').textContent = confirmLabel || 'Confirm';
    const modal = $('#modal');
    modal.classList.remove('hidden');
    const done = (val) => {
      modal.classList.add('hidden');
      $('#modalConfirm').onclick = null;
      $('#modalCancel').onclick = null;
      resolve(val);
    };
    $('#modalConfirm').onclick = () => done(true);
    $('#modalCancel').onclick = () => done(false);
  });
}

function confirmDialog(title, text) {
  return showModal(title, '<p>' + esc(text) + '</p>', 'Yes, continue');
}

/**
 * The estimate gate for anything that can cost money. The first call
 * goes out unconfirmed; when the server answers needs_confirm, this
 * shows exactly what will run (provider, model, estimated tokens and
 * cost, or Apollo credits) and only then fires the confirmed call.
 */
async function confirmEstimate(title, resp) {
  const est = resp.estimate;
  if (!est) {
    return showModal(title,
      '<p>No LLM key is available, so a rule based fallback will run '
      + 'instead. It costs nothing and its output is labeled heuristic '
      + 'so you can review it before saving.</p>', 'Run fallback');
  }
  if (est.est_cost !== undefined) {
    return showModal(title,
      '<dl class="kv">'
      + '<dt>Provider</dt><dd>' + esc(est.provider) + '</dd>'
      + '<dt>Model</dt><dd>' + esc(est.model) + '</dd>'
      + '<dt>Estimated input tokens</dt><dd>' + esc(est.est_input_tokens) + '</dd>'
      + '<dt>Estimated output tokens</dt><dd>' + esc(est.est_output_tokens) + '</dd>'
      + '<dt>Estimated cost</dt><dd>$' + esc(est.est_cost) + ' (estimate)</dd>'
      + '</dl><p class="muted small">' + esc(est.note || '') + '</p>',
      'Run it');
  }
  let rows = '<dl class="kv">'
    + '<dt>Provider</dt><dd>' + esc(est.provider || '') + '</dd>';
  if (est.calls !== undefined) {
    rows += '<dt>API calls</dt><dd>' + esc(est.calls) + '</dd>';
  }
  if (est.credits !== undefined) {
    rows += '<dt>Credits</dt><dd>' + esc(est.credits) + '</dd>';
  }
  if (est.credits_used_this_month !== undefined) {
    rows += '<dt>Credits used this month</dt><dd>'
      + esc(est.credits_used_this_month) + ' of '
      + esc(est.budget_credits) + ' budget</dd>';
  }
  if (est.results) {
    rows += '<dt>Results</dt><dd>' + esc(est.results) + '</dd>';
  }
  rows += '</dl>';
  if (est.note) {
    rows += '<p class="muted small">' + esc(est.note) + '</p>';
  }
  return showModal(title, rows, 'Run it');
}

async function confirmedCall(title, fn) {
  let resp = await fn(false);
  if (resp && resp.needs_confirm) {
    const ok = await confirmEstimate(title, resp);
    if (!ok) {
      toast('Cancelled. Nothing was run and nothing was charged.');
      return null;
    }
    resp = await fn(true);
  }
  return resp;
}

// --- CSV helpers -----------------------------------------------------------------

function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;
  const s = String(text).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (inQuotes) {
      if (ch === '"') {
        if (s[i + 1] === '"') { field += '"'; i++; }
        else { inQuotes = false; }
      } else { field += ch; }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      row.push(field); field = '';
    } else if (ch === '\n') {
      row.push(field); field = '';
      rows.push(row); row = [];
    } else { field += ch; }
  }
  row.push(field);
  rows.push(row);
  const nonEmpty = rows.filter((r) => r.some((c) => String(c).trim() !== ''));
  if (nonEmpty.length === 0) { return []; }
  const headers = nonEmpty[0].map((h) => String(h).trim().toLowerCase());
  return nonEmpty.slice(1).map((r) => {
    const obj = {};
    headers.forEach((h, idx) => { obj[h] = (r[idx] || '').trim(); });
    return obj;
  });
}

function downloadCsv(filename, rows) {
  const csv = rows.map((r) => r.map((c) => {
    const v = String(c === null || c === undefined ? '' : c);
    return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

// --- routing -----------------------------------------------------------------------

function parseHash() {
  const h = location.hash.replace(/^#\/?/, '');
  const parts = h.split('/');
  const view = VIEWS.indexOf(parts[0]) >= 0 ? parts[0] : 'dashboard';
  const cid = parts[1] && /^\d+$/.test(parts[1]) ? parseInt(parts[1], 10) : null;
  return { view, cid };
}

function setHash() {
  const target = '#/' + state.view
    + (state.activeClientId ? '/' + state.activeClientId : '');
  if (location.hash !== target) {
    history.replaceState(null, '', target);
  }
}

function activeClient() {
  return state.clients.find((c) => c.id === state.activeClientId) || null;
}

function navigate(view) {
  state.view = view;
  document.querySelectorAll('#sidenav button').forEach((b) => {
    b.classList.toggle('active', b.dataset.view === view);
  });
  setHash();
  const renderers = {
    dashboard: renderDashboard, clients: renderClients, icp: renderIcp,
    discovery: renderDiscovery, queue: renderQueue,
    campaigns: renderCampaigns, reporting: renderReporting,
    settings: renderSettings,
  };
  (renderers[view] || renderDashboard)().catch((err) => {
    viewEl().innerHTML = '<h2>' + esc(view) + '</h2>' + errBox(err);
  });
}

function switcherHtml() {
  const sel = $('#clientSwitch');
  sel.innerHTML = state.clients.map((c) =>
    '<option value="' + c.id + '"' + (c.id === state.activeClientId
      ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('');
}

async function loadClients() {
  state.clients = await apiGet('clients.php');
  if (!state.activeClientId
      || !state.clients.some((c) => c.id === state.activeClientId)) {
    state.activeClientId = state.clients.length
      ? state.clients[0].id : null;
  }
  switcherHtml();
}

function noClientNotice() {
  return '<div class="note">No client workspace exists yet. Create one '
    + 'in the Clients section to start.</div>';
}

function scoreBadge(score) {
  const cls = score >= 75 ? 'high' : (score >= 45 ? 'mid' : 'low');
  return '<span class="score ' + cls + '">' + esc(score) + '</span>';
}

function statusBadge(status) {
  const good = ['enriched', 'Active', 'approved', 'sent', 'confirmed'];
  const warn = ['queued_enrich', 'Draft', 'pending', 'draft'];
  const cls = good.indexOf(status) >= 0 ? 'good'
    : (warn.indexOf(status) >= 0 ? 'warn' : '');
  return '<span class="badge ' + cls + '">' + esc(status) + '</span>';
}

// --- Dashboard ----------------------------------------------------------------------

async function renderDashboard() {
  const el = viewEl();
  if (!state.activeClientId) {
    el.innerHTML = '<h2>Dashboard</h2>' + noClientNotice();
    return;
  }
  const client = activeClient();
  const report = await apiGet('reports.php');
  const m = report.metrics;
  const s = report.stages || {};
  const go = (view) => "location.hash='#/" + view + "/"
    + state.activeClientId + "'";
  const stageNode = (opts) => {
    let cls = 'loopnode';
    if (opts.moving) { cls += ' moving'; }
    if (opts.pending) { cls += ' pending'; }
    let badge = '';
    if (opts.pending) {
      badge = '<span class="loopbadge pending">'
        + esc(opts.pending) + '</span>';
    } else if (opts.moving) {
      badge = '<span class="loopbadge moving">active</span>';
    }
    return '<button type="button" class="' + cls + '" '
      + 'onclick="' + opts.go + '">'
      + '<span class="loopnum">' + opts.num + '</span>'
      + '<span class="looptitle">' + opts.title + '</span>'
      + '<span class="loopcount">' + opts.count + '</span>'
      + '<span class="loopsub">' + opts.sub + '</span>'
      + badge + '</button>';
  };
  const arrow = '<span class="looparrow" aria-hidden="true">&#8594;</span>';
  const nodes = [
    stageNode({
      num: '1', title: 'Client profile', view: 'icp',
      go: go('icp'),
      count: s.profile_status === 'confirmed' ? 'Confirmed'
        : (s.profile_status === 'draft' ? 'Draft' : 'Not set'),
      sub: 'Grounds every draft',
      pending: s.profile_status === 'draft'
        ? 'draft to confirm' : null,
    }),
    arrow,
    stageNode({
      num: '2', title: 'ICP and signals', view: 'icp',
      go: go('icp'),
      count: (s.icps || 0) + ' ICP / ' + (s.signals_enabled || 0)
        + ' signals on',
      sub: 'Who fits, what to watch',
      pending: !(s.icps > 0) ? 'needs an ICP' : null,
    }),
    arrow,
    stageNode({
      num: '3', title: 'Companies', view: 'discovery',
      go: go('discovery'),
      count: m.companies_discovered,
      sub: 'Apollo, import, manual',
      moving: (s.companies_new_24h || 0) > 0,
    }),
    arrow,
    stageNode({
      num: '4', title: 'Signals seen', view: 'discovery',
      go: go('discovery'),
      count: s.observations || 0,
      sub: 'Jobs and news evidence',
      moving: (s.observations_24h || 0) > 0,
    }),
    arrow,
    stageNode({
      num: '5', title: 'Prospects scored', view: 'discovery',
      go: go('discovery'),
      count: s.matches || 0,
      sub: m.contacts_qualified + ' at 75 or above',
      moving: (s.matches_24h || 0) > 0,
      pending: (s.queued_enrich || 0) > 0
        ? (s.queued_enrich) + ' to enrich' : null,
    }),
    arrow,
    stageNode({
      num: '6', title: 'Enriched', view: 'discovery',
      go: go('discovery'),
      count: m.contacts_enriched,
      sub: m.email_coverage_pct + '% email coverage',
    }),
    arrow,
    stageNode({
      num: '7', title: 'Outreach review', view: 'queue',
      go: go('queue'),
      count: (s.messages_pending || 0) + ' awaiting',
      sub: m.messages_approved + ' approved / ' + m.emails_sent
        + ' sent',
      moving: (s.messages_24h || 0) > 0,
      pending: (s.messages_pending || 0) > 0
        ? (s.messages_pending) + ' to review' : null,
    }),
    arrow,
    stageNode({
      num: '8', title: 'Replies and meetings', view: 'reporting',
      go: go('reporting'),
      count: m.replies + ' replies',
      sub: m.positive_replies + ' positive / ' + m.meetings
        + ' meetings',
    }),
  ];
  const card = (num, lbl) => '<div class="card"><div class="num">'
    + esc(num) + '</div><div class="lbl">' + esc(lbl) + '</div></div>';
  el.innerHTML = '<h2>Dashboard</h2>'
    + '<p class="viewsub">Workspace: <strong>'
    + esc(client ? client.name : '')
    + '</strong>. The loop below is live: click any stage to open it. '
    + 'A pulsing outline means something moved there in the last 24 '
    + 'hours. An amber badge means the stage is waiting on you.</p>'
    + '<div class="loopflow">' + nodes.join('') + '</div>'
    + '<div class="loopback"><span class="looparrow back" '
    + 'aria-hidden="true">&#8626;</span> Outcomes feed the next pass: '
    + 'reporting shows which signals and segments earn replies, and '
    + 'approved changes land back on the ICP and signals in stage 2.'
    + (s.last_run_at
      ? ' Last discovery activity: ' + esc(s.last_run_at) + '.' : '')
    + '</div>'
    + '<div class="cards">'
    + card(m.companies_discovered, 'Companies discovered')
    + card(m.contacts_discovered, 'Contacts discovered')
    + card(m.contacts_qualified, 'Contacts qualified (score 75+)')
    + card(m.contacts_enriched, 'Contacts enriched')
    + card(m.email_coverage_pct + '%', 'Email coverage')
    + card(m.messages_approved, 'Messages approved')
    + card(m.emails_sent, 'Emails sent')
    + card(m.replies, 'Replies received')
    + card(m.positive_replies, 'Positive replies')
    + card(m.meetings, 'Meetings booked')
    + card(m.api_credits, 'API credits used')
    + card('$' + m.est_cost, 'Estimated provider cost')
    + '</div>';
}

// --- Clients --------------------------------------------------------------------------

async function renderClients() {
  const el = viewEl();
  await loadClients();
  const rows = state.clients.map((c) => {
    const n = c.counts || {};
    return '<tr><td><strong>' + esc(c.name) + '</strong>'
      + (c.name.indexOf('Demo') === 0
         ? ' <span class="badge warn">demo seed</span>' : '')
      + (c.id === state.activeClientId
         ? ' <span class="badge primary">active</span>' : '')
      + '<br><span class="muted small">' + esc(c.website || '') + '</span></td>'
      + '<td>' + esc(n.icps || 0) + '</td><td>' + esc(n.signals || 0)
      + '</td><td>' + esc(n.companies || 0) + '</td><td>'
      + esc(n.contacts || 0) + '</td><td>' + esc(n.prospects || 0)
      + '</td><td>' + esc(n.queued_messages || 0) + '</td>'
      + '<td><div class="btnrow">'
      + '<button class="btn small secondary" data-act="switch" data-id="'
      + c.id + '">Open</button>'
      + '<button class="btn small secondary" data-act="edit" data-id="'
      + c.id + '">Edit</button>'
      + '<button class="btn small secondary" data-act="dup" data-id="'
      + c.id + '">Duplicate</button>'
      + '<button class="btn small danger" data-act="del" data-id="'
      + c.id + '">Delete</button></div></td></tr>';
  }).join('');
  el.innerHTML = '<h2>Clients</h2>'
    + '<p class="viewsub">Each client is an isolated workspace: its '
    + 'profile, ICPs, signals, prospects, messages, and settings never '
    + 'mix with another client. On first run the engine seeds two demo '
    + 'workspaces, Quartermaster and a fictional Brightpath Learning, '
    + 'so the isolation is visible immediately. Delete them whenever '
    + 'you like.</p>'
    + '<div class="panel"><h3>Client workspaces</h3>'
    + (state.clients.length
      ? '<table><thead><tr><th>Client</th><th>ICPs</th><th>Signals</th>'
        + '<th>Companies</th><th>Contacts</th><th>Prospects</th>'
        + '<th>In queue</th><th></th></tr></thead><tbody>' + rows
        + '</tbody></table>'
      : '<p class="empty">No clients yet.</p>')
    + '</div>'
    + '<div class="panel"><h3 id="clientFormTitle">New client</h3>'
    + '<input type="hidden" id="cfId">'
    + '<label class="f"><span>Name</span>'
    + '<input type="text" id="cfName" placeholder="Client or brand name"></label>'
    + '<label class="f"><span>Website</span>'
    + '<input type="text" id="cfWebsite" placeholder="https://example.com"></label>'
    + '<div class="btnrow"><button class="btn" id="cfSave">Create client</button>'
    + '<button class="btn secondary" id="cfCancel" style="display:none">Cancel edit</button></div>'
    + '<div id="cfMsg"></div></div>';

  el.querySelectorAll('button[data-act]').forEach((b) => {
    b.onclick = async () => {
      const id = parseInt(b.dataset.id, 10);
      const found = state.clients.find((c) => c.id === id);
      if (b.dataset.act === 'switch') {
        state.activeClientId = id;
        state.settings = null;
        switcherHtml();
        setHash();
        toast('Active workspace: ' + (found ? found.name : id));
        renderClients();
      } else if (b.dataset.act === 'edit' && found) {
        $('#cfId').value = found.id;
        $('#cfName').value = found.name;
        $('#cfWebsite').value = found.website || '';
        $('#clientFormTitle').textContent = 'Edit client';
        $('#cfSave').textContent = 'Save changes';
        $('#cfCancel').style.display = '';
        $('#cfName').focus();
      } else if (b.dataset.act === 'dup') {
        await apiPost('clients.php?action=duplicate', { id, omit_client: true });
        toast('Workspace duplicated.');
        renderClients();
      } else if (b.dataset.act === 'del') {
        const ok = await confirmDialog('Delete client',
          'Delete "' + (found ? found.name : '') + '" and every '
          + 'profile, prospect, message, and setting in this '
          + 'workspace? This cannot be undone.');
        if (!ok) { return; }
        await apiPost('clients.php?action=delete', { id, omit_client: true });
        if (state.activeClientId === id) {
          state.activeClientId = null;
          state.settings = null;
        }
        toast('Client deleted.');
        renderClients();
      }
    };
  });
  $('#cfCancel').onclick = () => renderClients();
  $('#cfSave').onclick = async () => {
    const name = $('#cfName').value.trim();
    const website = $('#cfWebsite').value.trim();
    const id = $('#cfId').value;
    if (!name) {
      $('#cfMsg').innerHTML = '<div class="err">Name is required.</div>';
      return;
    }
    try {
      if (id) {
        await apiPost('clients.php?action=update',
          { id: parseInt(id, 10), name, website, omit_client: true });
        toast('Client saved.');
      } else {
        const created = await apiPost('clients.php',
          { name, website, omit_client: true });
        state.activeClientId = created.id;
        toast('Client created and opened.');
      }
      renderClients();
    } catch (err) {
      $('#cfMsg').innerHTML = errBox(err);
    }
  };
}

// --- Client profile + ICP & signals -------------------------------------------------

function linesToList(text) {
  return String(text || '').split(/[\n,]/).map((s) => s.trim())
    .filter((s) => s !== '');
}
function listToLines(list) {
  return (list || []).join('\n');
}

async function renderIcp() {
  const el = viewEl();
  if (!state.activeClientId) {
    el.innerHTML = '<h2>ICP &amp; Intent Signals</h2>' + noClientNotice();
    return;
  }
  const client = activeClient();
  const profileResp = await apiGet('profile.php');
  const profile = profileResp.profile;
  const icps = await apiGet('icps.php');
  const signals = await apiGet('signals.php');

  const p = profile || {};
  let html = '<h2>Client Profile, ICP &amp; Intent Signals</h2>'
    + '<p class="viewsub">Workspace: <strong>' + esc(client ? client.name : '')
    + '</strong>. The profile grounds every draft the engine writes. '
    + 'Analysis saves as a draft; a confirmed profile is never '
    + 'overwritten silently.</p>';

  // Website analysis + profile editor
  html += '<div class="panel"><h3>Client profile '
    + (profile ? statusBadge(profile.status) : '<span class="badge warn">not set</span>')
    + '</h3>'
    + '<label class="f"><span>Website to analyze</span>'
    + '<input type="text" id="pfUrl" value="'
    + esc(client ? (client.website || '') : '') + '"></label>'
    + '<div class="btnrow"><button class="btn" id="pfAnalyze">Analyze website</button></div>'
    + '<div id="pfAnalyzeMsg"></div>'
    + '<div class="grid2"><div>'
    + '<label class="f"><span>Product or service</span>'
    + '<textarea id="pfProduct">' + esc(p.product || '') + '</textarea></label>'
    + '<label class="f"><span>Target audiences</span>'
    + '<textarea id="pfAudiences">' + esc(p.audiences || '') + '</textarea></label>'
    + '<label class="f"><span>Value proposition summary</span>'
    + '<textarea id="pfSummary">' + esc(p.value_prop_summary || '') + '</textarea></label>'
    + '<label class="f"><span>Writing guidelines</span>'
    + '<textarea id="pfGuidelines">' + esc(p.writing_guidelines || '') + '</textarea></label>'
    + '</div><div>'
    + '<label class="f"><span>Value points (one per line)</span>'
    + '<textarea id="pfProps">' + esc(listToLines(p.value_props)) + '</textarea></label>'
    + '<label class="f"><span>Differentiators (one per line)</span>'
    + '<textarea id="pfDiffs">' + esc(listToLines(p.differentiators)) + '</textarea></label>'
    + '<label class="f"><span>Use cases (one per line)</span>'
    + '<textarea id="pfCases">' + esc(listToLines(p.use_cases)) + '</textarea></label>'
    + '<label class="f"><span>Status</span><select id="pfStatus">'
    + '<option value="draft"' + (p.status !== 'confirmed' ? ' selected' : '') + '>draft</option>'
    + '<option value="confirmed"' + (p.status === 'confirmed' ? ' selected' : '') + '>confirmed</option>'
    + '</select></label>'
    + '</div></div>'
    + '<div class="btnrow"><button class="btn" id="pfSave">Save profile</button></div>'
    + '<div id="pfMsg"></div>'
    + (profile && profile.evidence
      ? '<p class="muted small">Evidence on file: '
        + esc(String(profile.evidence).slice(0, 220)) + '...</p>' : '')
    + '</div>';

  // ICP list
  html += '<div class="panel"><h3>ICP profiles</h3>';
  if (!icps.length) {
    html += '<p class="empty">No ICP yet. Describe the ideal customer below.</p>';
  }
  icps.forEach((icp) => {
    const f = icp.filters || {};
    html += '<div class="note"><strong>' + esc(icp.name) + '</strong> '
      + '<span class="muted small">weights: fit ' + esc(icp.weights.fit)
      + ' / signal ' + esc(icp.weights.signal) + ' / evidence '
      + esc(icp.weights.evidence) + '</span><br>'
      + '<span class="small">' + esc(icp.nl_text || '') + '</span><br>'
      + '<span class="muted small">Titles: '
      + esc((f.titles || []).join(', ') || 'any')
      + ' · Geos: ' + esc((f.geos || []).join(', ') || 'any')
      + ' · Size: ' + esc(f.size_min === null ? 'any' : f.size_min)
      + ' to ' + esc(f.size_max === null ? 'any' : f.size_max)
      + '</span><div class="btnrow">'
      + '<button class="btn small secondary" data-icp-edit="' + icp.id + '">Edit</button>'
      + '<button class="btn small danger" data-icp-del="' + icp.id + '">Delete</button>'
      + '</div></div>';
  });
  html += '</div>';

  // ICP editor
  html += '<div class="panel"><h3 id="icpFormTitle">New ICP</h3>'
    + '<input type="hidden" id="icpId">'
    + '<label class="f"><span>Name</span><input type="text" id="icpName"></label>'
    + '<label class="f"><span>Describe the ideal customer in plain English</span>'
    + '<textarea id="icpNl" placeholder="B2B SaaS companies with 50-500 employees in North America. Decision-makers include CMOs, VP Marketing, Heads of Field Marketing."></textarea></label>'
    + '<div class="btnrow"><button class="btn secondary" id="icpStructure">Structure this description</button>'
    + '<span class="muted small">Fills the filters below for your review. Nothing saves until you press Save ICP.</span></div>'
    + '<div id="icpStructMsg"></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Industries (comma separated)</span><input type="text" id="fIndustries"></label>'
    + '<label class="f"><span>Geographies</span><input type="text" id="fGeos"></label></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Size min (employees)</span><input type="number" id="fSizeMin"></label>'
    + '<label class="f"><span>Size max</span><input type="number" id="fSizeMax"></label>'
    + '<label class="f"><span>Revenue note</span><input type="text" id="fRevenue"></label>'
    + '<label class="f"><span>Funding stages</span><input type="text" id="fFunding"></label></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Job titles</span><input type="text" id="fTitles"></label>'
    + '<label class="f"><span>Seniorities</span><input type="text" id="fSeniorities"></label>'
    + '<label class="f"><span>Technology stack</span><input type="text" id="fTech"></label></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Exclude companies</span><input type="text" id="fExCompanies"></label>'
    + '<label class="f"><span>Exclude industries</span><input type="text" id="fExIndustries"></label>'
    + '<label class="f"><span>Exclude titles</span><input type="text" id="fExTitles"></label></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Weight: ICP fit</span><input type="number" id="wFit" value="40"></label>'
    + '<label class="f"><span>Weight: signal</span><input type="number" id="wSignal" value="40"></label>'
    + '<label class="f"><span>Weight: evidence</span><input type="number" id="wEvidence" value="20"></label></div>'
    + '<div class="btnrow"><button class="btn" id="icpSave">Save ICP</button>'
    + '<button class="btn secondary" id="icpReset">Clear form</button></div>'
    + '<div id="icpMsg"></div></div>';

  // Signals list
  const detBadge = (d) => d === 'observable'
    ? '<span class="badge good">observable</span>'
    : (d === 'inferred' ? '<span class="badge warn">inferred</span>'
       : '<span class="badge bad">cannot detect reliably</span>');
  html += '<div class="panel"><h3>Intent signals</h3>';
  if (!signals.length) {
    html += '<p class="empty">No signals yet.</p>';
  } else {
    html += '<table><thead><tr><th>Signal</th><th>Sources</th>'
      + '<th>Keywords</th><th>Freshness</th><th>Importance</th>'
      + '<th>Detectability</th><th>On</th><th></th></tr></thead><tbody>';
    signals.forEach((s) => {
      html += '<tr><td><strong>' + esc(s.name) + '</strong><br>'
        + '<span class="muted small">' + esc(s.nl_text || '') + '</span></td>'
        + '<td>' + esc((s.source_types || []).join(', ')) + '</td>'
        + '<td>' + esc((s.keywords || []).join(', ')) + '</td>'
        + '<td>' + esc(s.freshness_days) + 'd</td>'
        + '<td>' + esc(s.importance) + ' / 5</td>'
        + '<td>' + detBadge(s.detectability) + '</td>'
        + '<td><input type="checkbox" data-sig-toggle="' + s.id + '"'
        + (s.enabled ? ' checked' : '') + '></td>'
        + '<td><div class="btnrow">'
        + '<button class="btn small secondary" data-sig-edit="' + s.id + '">Edit</button>'
        + '<button class="btn small danger" data-sig-del="' + s.id + '">Delete</button>'
        + '</div></td></tr>';
    });
    html += '</tbody></table>';
  }
  html += '<p class="muted small">Signals marked "cannot detect '
    + 'reliably" have no public source among the current detectors '
    + '(public job boards, careers pages, news). They stay visible so '
    + 'nobody mistakes them for live coverage.</p></div>';

  // Signal editor
  const BOARD_TYPES = ['jobs', 'greenhouse', 'lever', 'ashby',
    'workable', 'smartrecruiters', 'recruitee', 'breezy',
    'teamtailor', 'workday'];
  const srcIncludes = (list, val) => {
    const arr = list || [];
    if (val === 'jobs') {
      return arr.some((x) => BOARD_TYPES.indexOf(x) >= 0);
    }
    return arr.indexOf(val) >= 0;
  };
  const srcBox = (val, label) => '<label class="checkline">'
    + '<input type="checkbox" name="sSrc" value="' + val + '"> ' + label
    + '</label>';
  html += '<div class="panel"><h3 id="sigFormTitle">New signal</h3>'
    + '<input type="hidden" id="sigId">'
    + '<label class="f"><span>Name</span><input type="text" id="sigName"></label>'
    + '<label class="f"><span>Describe the buying signal in plain English</span>'
    + '<textarea id="sigNl" placeholder="The company is hiring field marketing roles"></textarea></label>'
    + '<div class="btnrow"><button class="btn secondary" id="sigStructure">Structure this description</button>'
    + '<span class="muted small">Suggests sources, keywords, and an honest detectability rating for your review.</span></div>'
    + '<div id="sigStructMsg"></div>'
    + '<div class="formrow"><div><span class="muted small">Source types</span>'
    + srcBox('jobs', 'Job boards (Greenhouse, Lever, Ashby, '
      + 'Workable, SmartRecruiters, Recruitee, Breezy, Teamtailor, '
      + 'Workday)')
    + srcBox('careers', 'Careers pages')
    + srcBox('news', 'News and announcements')
    + '</div>'
    + '<label class="f"><span>Keywords (comma separated)</span><input type="text" id="sigKeywords"></label></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Freshness window (days)</span><input type="number" id="sigFresh" value="30"></label>'
    + '<label class="f"><span>Importance (1 to 5)</span><input type="number" id="sigImportance" value="3" min="1" max="5"></label>'
    + '<label class="f"><span>Detectability</span><select id="sigDetect">'
    + '<option value="observable">observable</option>'
    + '<option value="inferred">inferred</option>'
    + '<option value="unreliable">cannot detect reliably</option>'
    + '</select></label>'
    + '<label class="f"><span>Applies to ICP (optional)</span><select id="sigIcp">'
    + '<option value="">All ICPs</option>'
    + icps.map((i) => '<option value="' + i.id + '">' + esc(i.name) + '</option>').join('')
    + '</select></label></div>'
    + '<label class="checkline"><input type="checkbox" id="sigEnabled" checked> Enabled</label>'
    + '<div class="btnrow"><button class="btn" id="sigSave">Save signal</button>'
    + '<button class="btn secondary" id="sigReset">Clear form</button></div>'
    + '<div id="sigMsg"></div></div>';

  el.innerHTML = html;

  // Profile wiring
  $('#pfAnalyze').onclick = async () => {
    const url = $('#pfUrl').value.trim();
    const msg = $('#pfAnalyzeMsg');
    try {
      const resp = await confirmedCall('Analyze website',
        (confirmed) => apiPost('profile.php?action=analyze',
          { url, confirmed }));
      if (resp === null) { return; }
      if (resp.error === 'confirmed_profile_exists') { throw resp; }
      toast('Analysis saved as a draft profile (' + resp.mode + ' mode).');
      renderIcp();
    } catch (err) {
      if (err.data && err.data.error === 'confirmed_profile_exists') {
        const ok = await confirmDialog('Confirmed profile exists',
          'This client already has a confirmed profile. Save this '
          + 'analysis as a new draft instead? The confirmed profile '
          + 'stays in place until you edit it.');
        if (ok) {
          try {
            await apiPost('profile.php?action=analyze',
              { url, confirmed: true, replace_draft: true });
            toast('Saved as a new draft profile.');
            renderIcp();
          } catch (e2) { msg.innerHTML = errBox(e2); }
        }
        return;
      }
      msg.innerHTML = errBox(err);
    }
  };
  $('#pfSave').onclick = async () => {
    try {
      await apiPost('profile.php?action=save', {
        product: $('#pfProduct').value,
        audiences: $('#pfAudiences').value,
        value_prop_summary: $('#pfSummary').value,
        writing_guidelines: $('#pfGuidelines').value,
        value_props: linesToList($('#pfProps').value),
        differentiators: linesToList($('#pfDiffs').value),
        use_cases: linesToList($('#pfCases').value),
        status: $('#pfStatus').value,
      });
      toast('Profile saved.');
      renderIcp();
    } catch (err) {
      $('#pfMsg').innerHTML = errBox(err);
    }
  };

  // ICP wiring
  const fillIcpForm = (icp) => {
    const f = icp ? (icp.filters || {}) : {};
    $('#icpId').value = icp ? icp.id : '';
    $('#icpName').value = icp ? icp.name : '';
    $('#icpNl').value = icp ? (icp.nl_text || '') : '';
    $('#fIndustries').value = (f.industries || []).join(', ');
    $('#fGeos').value = (f.geos || []).join(', ');
    $('#fSizeMin').value = f.size_min === null || f.size_min === undefined ? '' : f.size_min;
    $('#fSizeMax').value = f.size_max === null || f.size_max === undefined ? '' : f.size_max;
    $('#fRevenue').value = f.revenue || '';
    $('#fFunding').value = (f.funding || []).join(', ');
    $('#fTitles').value = (f.titles || []).join(', ');
    $('#fSeniorities').value = (f.seniorities || []).join(', ');
    $('#fTech').value = (f.tech || []).join(', ');
    $('#fExCompanies').value = (f.exclude_companies || []).join(', ');
    $('#fExIndustries').value = (f.exclude_industries || []).join(', ');
    $('#fExTitles').value = (f.exclude_titles || []).join(', ');
    const w = icp ? icp.weights : { fit: 40, signal: 40, evidence: 20 };
    $('#wFit').value = w.fit;
    $('#wSignal').value = w.signal;
    $('#wEvidence').value = w.evidence;
    $('#icpFormTitle').textContent = icp ? 'Edit ICP' : 'New ICP';
  };
  el.querySelectorAll('[data-icp-edit]').forEach((b) => {
    b.onclick = () => {
      const icp = icps.find((i) => i.id === parseInt(b.dataset.icpEdit, 10));
      if (icp) { fillIcpForm(icp); $('#icpName').focus(); }
    };
  });
  el.querySelectorAll('[data-icp-del]').forEach((b) => {
    b.onclick = async () => {
      if (!(await confirmDialog('Delete ICP', 'Delete this ICP profile?'))) { return; }
      await apiPost('icps.php?action=delete',
        { id: parseInt(b.dataset.icpDel, 10) });
      renderIcp();
    };
  });
  $('#icpReset').onclick = () => fillIcpForm(null);
  $('#icpStructure').onclick = async () => {
    const nl = $('#icpNl').value.trim();
    if (!nl) {
      $('#icpStructMsg').innerHTML = '<div class="err">Write the description first.</div>';
      return;
    }
    try {
      const resp = await confirmedCall('Structure ICP',
        (confirmed) => apiPost('icps.php?action=structure',
          { nl_text: nl, confirmed }));
      if (resp === null) { return; }
      const keepId = $('#icpId').value;
      const keepName = $('#icpName').value;
      fillIcpForm({ filters: resp.filters, weights: { fit: 40, signal: 40, evidence: 20 } });
      $('#icpId').value = keepId;
      $('#icpName').value = keepName;
      $('#icpNl').value = nl;
      $('#icpStructMsg').innerHTML = '<div class="ok">Filters drafted in '
        + esc(resp.mode) + ' mode. Review them, then Save ICP.</div>';
    } catch (err) {
      $('#icpStructMsg').innerHTML = errBox(err);
    }
  };
  $('#icpSave').onclick = async () => {
    const body = {
      name: $('#icpName').value.trim(),
      nl_text: $('#icpNl').value,
      filters: {
        industries: linesToList($('#fIndustries').value),
        geos: linesToList($('#fGeos').value),
        size_min: $('#fSizeMin').value === '' ? null : parseInt($('#fSizeMin').value, 10),
        size_max: $('#fSizeMax').value === '' ? null : parseInt($('#fSizeMax').value, 10),
        revenue: $('#fRevenue').value,
        funding: linesToList($('#fFunding').value),
        titles: linesToList($('#fTitles').value),
        seniorities: linesToList($('#fSeniorities').value),
        tech: linesToList($('#fTech').value),
        exclude_companies: linesToList($('#fExCompanies').value),
        exclude_industries: linesToList($('#fExIndustries').value),
        exclude_titles: linesToList($('#fExTitles').value),
      },
      weights: {
        fit: parseInt($('#wFit').value, 10) || 40,
        signal: parseInt($('#wSignal').value, 10) || 40,
        evidence: parseInt($('#wEvidence').value, 10) || 20,
      },
    };
    if ($('#icpId').value) { body.id = parseInt($('#icpId').value, 10); }
    if (!body.name) {
      $('#icpMsg').innerHTML = '<div class="err">Name is required.</div>';
      return;
    }
    try {
      await apiPost('icps.php', body);
      toast('ICP saved.');
      renderIcp();
    } catch (err) {
      $('#icpMsg').innerHTML = errBox(err);
    }
  };

  // Signal wiring
  const fillSigForm = (s) => {
    $('#sigId').value = s ? s.id : '';
    $('#sigName').value = s ? s.name : '';
    $('#sigNl').value = s ? (s.nl_text || '') : '';
    $('#sigKeywords').value = s ? (s.keywords || []).join(', ') : '';
    $('#sigFresh').value = s ? s.freshness_days : 30;
    $('#sigImportance').value = s ? s.importance : 3;
    $('#sigDetect').value = s ? s.detectability : 'observable';
    $('#sigIcp').value = s && s.icp_id ? String(s.icp_id) : '';
    $('#sigEnabled').checked = s ? !!s.enabled : true;
    document.querySelectorAll('input[name="sSrc"]').forEach((cb) => {
      cb.checked = s ? srcIncludes(s.source_types, cb.value) : false;
    });
    $('#sigFormTitle').textContent = s ? 'Edit signal' : 'New signal';
  };
  el.querySelectorAll('[data-sig-edit]').forEach((b) => {
    b.onclick = () => {
      const s = signals.find((x) => x.id === parseInt(b.dataset.sigEdit, 10));
      if (s) { fillSigForm(s); $('#sigName').focus(); }
    };
  });
  el.querySelectorAll('[data-sig-del]').forEach((b) => {
    b.onclick = async () => {
      if (!(await confirmDialog('Delete signal', 'Delete this intent signal?'))) { return; }
      await apiPost('signals.php?action=delete',
        { id: parseInt(b.dataset.sigDel, 10) });
      renderIcp();
    };
  });
  el.querySelectorAll('[data-sig-toggle]').forEach((cb) => {
    cb.onchange = async () => {
      await apiPost('signals.php?action=toggle',
        { id: parseInt(cb.dataset.sigToggle, 10), enabled: cb.checked });
      toast(cb.checked ? 'Signal enabled.' : 'Signal disabled.');
    };
  });
  $('#sigReset').onclick = () => fillSigForm(null);
  $('#sigStructure').onclick = async () => {
    const nl = $('#sigNl').value.trim();
    if (!nl) {
      $('#sigStructMsg').innerHTML = '<div class="err">Write the description first.</div>';
      return;
    }
    try {
      const resp = await confirmedCall('Structure signal',
        (confirmed) => apiPost('signals.php?action=structure',
          { nl_text: nl, confirmed }));
      if (resp === null) { return; }
      document.querySelectorAll('input[name="sSrc"]').forEach((cb) => {
        cb.checked = srcIncludes(resp.source_types, cb.value);
      });
      $('#sigKeywords').value = (resp.keywords || []).join(', ');
      $('#sigDetect').value = resp.detectability || 'inferred';
      $('#sigStructMsg').innerHTML = '<div class="ok">Drafted in '
        + esc(resp.mode) + ' mode. Review, then Save signal.</div>';
    } catch (err) {
      $('#sigStructMsg').innerHTML = errBox(err);
    }
  };
  $('#sigSave').onclick = async () => {
    const sources = [];
    document.querySelectorAll('input[name="sSrc"]').forEach((cb) => {
      if (cb.checked) { sources.push(cb.value); }
    });
    const body = {
      name: $('#sigName').value.trim(),
      nl_text: $('#sigNl').value,
      source_types: sources,
      keywords: linesToList($('#sigKeywords').value),
      freshness_days: parseInt($('#sigFresh').value, 10) || 30,
      importance: parseInt($('#sigImportance').value, 10) || 3,
      detectability: $('#sigDetect').value,
      icp_id: $('#sigIcp').value === '' ? null : parseInt($('#sigIcp').value, 10),
      enabled: $('#sigEnabled').checked,
    };
    if ($('#sigId').value) { body.id = parseInt($('#sigId').value, 10); }
    if (!body.name) {
      $('#sigMsg').innerHTML = '<div class="err">Name is required.</div>';
      return;
    }
    try {
      await apiPost('signals.php', body);
      toast('Signal saved.');
      renderIcp();
    } catch (err) {
      $('#sigMsg').innerHTML = errBox(err);
    }
  };
}

// --- Prospect discovery -----------------------------------------------------------------

function breakdownText(b) {
  if (!b) { return ''; }
  return 'fit ' + (b.fit_points || 0) + ' · signal ' + (b.signal_points || 0)
    + ' · evidence ' + (b.evidence_points || 0);
}

async function renderDiscovery() {
  const el = viewEl();
  if (!state.activeClientId) {
    const ageText = (ts) => {
    const then = new Date(String(ts).replace(' ', 'T')).getTime();
    if (isNaN(then)) { return ''; }
    const days = Math.floor((Date.now() - then) / 86400000);
    if (days <= 0) { return 'today'; }
    if (days === 1) { return 'yesterday'; }
    return days + ' days ago';
  };
  const typeLabel = { greenhouse: 'Greenhouse board', lever: 'Lever board',
    ashby: 'Ashby board', workable: 'Workable board',
    smartrecruiters: 'SmartRecruiters board', recruitee: 'Recruitee board',
    breezy: 'Breezy board', teamtailor: 'Teamtailor board',
    workday: 'Workday board',
    news: 'News', careers: 'Careers page' };
  const feedByCompany = {};
  (feed.observations || []).forEach((o) => {
    if (!feedByCompany[o.company_id]) {
      feedByCompany[o.company_id] = { id: o.company_id,
        name: o.company_name, domain: o.company_domain, live: 0,
        types: {}, obs: [] };
    }
    const g = feedByCompany[o.company_id];
    g.obs.push(o);
    if (o.live) { g.live++; g.types[o.source_type] = true; }
  });
  const feedGroups = Object.keys(feedByCompany)
    .map((k) => feedByCompany[k])
    .sort((a, b) => (b.live - a.live)
      || String(b.obs[0].observed_at).localeCompare(
        String(a.obs[0].observed_at)));
  const feedGroupHtml = (g) => {
    const fm = (feed.matches || {})[g.id] || {};
    const badges = (g.live > 0
      ? '<span class="badge good">' + g.live + ' live signal'
        + (g.live === 1 ? '' : 's') + '</span>'
      : '<span class="badge warn">no live signals</span>')
      + Object.keys(g.types).map((tp) => '<span class="badge">'
        + esc(typeLabel[tp] || tp) + '</span>').join('');
    const scoreInfo = fm.best_score
      ? '<span class="muted small">best score ' + fm.best_score
        + ' / ' + (fm.contacts || 0) + ' contacts'
        + (fm.any_enriched ? ' / enriched' : '') + '</span>'
      : '';
    const actions = '<span class="feedactions">'
      + ((!fm.contacts || fm.contacts === 0) && apolloReady
        ? '<button class="btn small" data-people="' + g.id
          + '">Find people</button>' : '')
      + (fm.queued_match_id
        ? '<button class="btn small secondary" data-enrich="'
          + fm.queued_match_id + '">Enrich top prospect</button>'
        : '')
      + '</span>';
    const obsRows = g.obs.slice(0, 6).map((o) =>
      '<div class="feedobs' + (o.live ? '' : ' stale') + '">'
      + '<span class="feedwhat">' + esc(o.evidence) + '</span>'
      + '<span class="feedmeta">' + esc(o.signal_name) + ' / '
      + esc(typeLabel[o.source_type] || o.source_type) + ' / '
      + ageText(o.observed_at)
      + (o.live ? '' : ' / outside its ' + o.freshness_days
        + ' day window') + '</span>'
      + '<a href="' + esc(o.source_url) + '" target="_blank" '
      + 'rel="noopener">source</a></div>').join('')
      + (g.obs.length > 6
        ? '<div class="feedmeta">plus ' + (g.obs.length - 6)
          + ' older observations</div>' : '');
    return '<div class="feedgroup"><div class="feedhead"><strong>'
      + esc(g.name) + '</strong><span class="muted small">'
      + esc(g.domain) + '</span>' + badges + scoreInfo + actions
      + '</div>' + obsRows + '</div>';
  };
  const feedPanelHtml = '<div class="panel"><h3>Signal evidence</h3>'
    + (feedGroups.length
      ? feedGroups.map(feedGroupHtml).join('')
        + (feed.companies_quiet
          ? '<p class="muted small">' + feed.companies_quiet + ' of '
            + feed.total_companies + ' companies are quiet: no '
            + 'signals recorded for them. They live in the company '
            + 'list under Advanced below.</p>' : '')
      : '<p class="empty">No signal evidence yet. Run discovery or '
        + 'Process all companies, and anything that fires shows up '
        + 'here with its source link.</p>')
    + '</div>';

  el.innerHTML = '<h2>Prospect Discovery</h2>' + noClientNotice();
    return;
  }
  const settings = await apiGet('settings.php');
  state.settings = settings;
  const icps = await apiGet('icps.php');
  const companies = await apiGet('discovery.php', { view: 'companies' });
  const prospects = await apiGet('discovery.php', { view: 'prospects' });
  const watchlist = await apiGet('discovery.php', { view: 'watchlist' });
  const feed = await apiGet('discovery.php', { view: 'feed' });
  const apolloReady = settings.integrations.apollo.present;

  const prospectRows = (list, showEnrich) => list.map((m) =>
    '<tr><td>' + scoreBadge(m.score) + '</td>'
    + '<td><strong>' + esc(m.company_name) + '</strong><br>'
    + '<span class="muted small">' + esc(m.company_domain || '') + '</span></td>'
    + '<td>' + esc(m.contact_name || '(company level, no contact yet)')
    + '<br><span class="muted small">' + esc(m.contact_title || '') + '</span></td>'
    + '<td>' + statusBadge(m.status) + '<br><span class="muted small">'
    + esc(breakdownText(m.breakdown)) + '</span></td>'
    + '<td><div class="btnrow">'
    + (m.contact_id
      ? '<button class="btn small" data-draft="' + m.id + '">Draft message</button>'
      : '')
    + (showEnrich && m.contact_id
      ? '<button class="btn small secondary" data-enrich="' + m.id + '">Enrich</button>'
      : '')
    + '</div></td></tr>').join('');

  el.innerHTML = '<h2>Prospect Discovery</h2>'
    + '<p class="viewsub">What fired, where, and when. Every signal '
    + 'below carries its source link as evidence. Companies, job '
    + 'boards and imports live under Advanced at the bottom.</p>'
    + '<div class="panel"><h3>Run discovery</h3>'
    + '<div class="formrow"><label class="f"><span>ICP</span>'
    + '<select id="dIcp">'
    + icps.map((i) => '<option value="' + i.id + '">' + esc(i.name) + '</option>').join('')
    + '</select></label></div>'
    + '<div class="btnrow"><button class="btn" id="dPipeline">Process all companies</button>'
    + '<button class="btn secondary" id="dRun">Run discovery now</button>'
    + '<button class="btn secondary" id="dCompanies"'
    + (apolloReady ? '' : ' disabled') + '>Find companies on Apollo</button>'
    + '<button class="btn secondary" id="dApollo"'
    + (apolloReady ? '' : ' disabled') + '>Apollo people search</button></div>'
    + (apolloReady
      ? '<p class="muted small">Apollo People Search is credit free '
        + 'and returns up to 25 people per run. It does not return '
        + 'emails or LinkedIn URLs; those come from enrichment.</p>'
      : '<p class="muted small">Apollo is unavailable until an Apollo '
        + 'API key is saved in Settings and Integrations. CSV import '
        + 'below works without it.</p>')
    + '<div id="dRunMsg"></div></div>'
    + feedPanelHtml
    + '<details class="advwrap"><summary>Advanced: company list, '
    + 'boards and import</summary>'
    + '<div class="panel"><h3>Companies</h3>'
    + (companies.length
      ? '<table><thead><tr><th>Company</th><th>Domain</th><th>Careers</th>'
        + '<th>Job board</th><th>Contacts</th><th>Signals seen</th><th></th>'
        + '</tr></thead><tbody>'
        + companies.map((c) => '<tr><td><strong>' + esc(c.name)
          + '</strong></td><td>' + esc(c.domain) + '</td><td>'
          + (c.careers_url
            ? '<a href="' + esc(c.careers_url) + '" target="_blank" rel="noopener">careers page</a>'
            : '<span class="muted">none</span>') + '</td><td>'
          + (c.board_type
            ? '<span class="badge good">' + esc(c.board_type) + ': '
              + esc(c.board_token) + '</span>'
            : '<span class="badge warn">not detected</span>') + '</td><td>'
          + esc(c.contacts) + '</td><td>' + esc(c.observations) + '</td>'
          + '<td><button class="btn small secondary" data-board="'
          + c.id + '">Detect board</button></td></tr>').join('')
        + '</tbody></table>'
      : '<p class="empty">No companies yet. Import a CSV or add one below.</p>')
    + '<div class="formrow">'
    + '<label class="f"><span>Company name</span><input type="text" id="coName"></label>'
    + '<label class="f"><span>Website</span><input type="text" id="coSite"></label>'
    + '<label class="f"><span>Careers URL</span><input type="text" id="coCareers"></label></div>'
    + '<div class="btnrow"><button class="btn secondary" id="coAdd">Add company</button></div>'
    + '<div id="coMsg"></div></div>'
    + '<div class="panel"><h3>Import CSV</h3>'
    + '<p class="muted small">Companies CSV headers: name, website, '
    + 'careers_url. Contacts CSV headers: company, first_name, '
    + 'last_name, title, email, linkedin_url. Duplicates are skipped '
    + 'by domain for companies and by email or name plus title for '
    + 'contacts.</p>'
    + '<div class="formrow"><label class="f"><span>Import kind</span>'
    + '<select id="csvKind"><option value="companies">Companies</option>'
    + '<option value="contacts">Contacts</option></select></label>'
    + '<label class="f"><span>CSV file</span>'
    + '<input type="file" id="csvFile" accept=".csv,text/csv"></label></div>'
    + '<div class="btnrow"><button class="btn secondary" id="csvGo">Import file</button></div>'
    + '<div id="csvMsg"></div></div></details>'
    + '<div class="panel"><h3>Ranked prospects</h3>'
    + (prospects.length
      ? '<table><thead><tr><th>Score</th><th>Company</th><th>Contact</th>'
        + '<th>Status and breakdown</th><th></th></tr></thead><tbody>'
        + prospectRows(prospects, true) + '</tbody></table>'
      : '<p class="empty">No scored prospects yet. Add companies with '
        + 'job boards, then run discovery.</p>')
    + '</div>'
    + '<div class="panel"><h3>Watchlist (scored under 75)</h3>'
    + (watchlist.length
      ? '<table><thead><tr><th>Score</th><th>Company</th><th>Contact</th>'
        + '<th>Status and breakdown</th><th></th></tr></thead><tbody>'
        + prospectRows(watchlist, false) + '</tbody></table>'
      : '<p class="empty">Watchlist is empty.</p>')
    + '</div>';

  $('#dPipeline').onclick = async () => {
    const msg = $('#dRunMsg');
    try {
      const resp = await confirmedCall('Process all companies',
        (confirmed) => apiPost('discovery.php?action=pipeline', {
          icp_id: $('#dIcp').value
            ? parseInt($('#dIcp').value, 10) : undefined,
          confirmed,
        }));
      if (resp === null) { return; }
      msg.innerHTML = '<div class="ok">Pipeline complete: '
        + esc(resp.companies_checked) + ' companies checked, '
        + esc(resp.new_observations) + ' new signal observations, '
        + esc(resp.people_added) + ' people found at signal '
        + 'companies, ' + esc(resp.matches_scored) + ' matches '
        + 'scored, ' + esc(resp.enriched) + ' prospects enriched'
        + (resp.skipped_budget
          ? ', ' + esc(resp.skipped_budget) + ' left unenriched by '
            + 'the monthly budget cap' : '')
        + '. Credits used this month: '
        + esc(resp.credits_used_this_month) + '.'
        + (resp.errors && resp.errors.length
          ? '<br>Errors: ' + esc(resp.errors.join(' | ')) : '')
        + '</div>';
      setTimeout(() => renderDiscovery(), 1200);
    } catch (err) {
      msg.innerHTML = errBox(err);
    }
  };
  $('#dRun').onclick = async () => {
    const msg = $('#dRunMsg');
    msg.innerHTML = '<p class="muted">Running. Fetching boards and scoring...</p>';
    try {
      const icpId = $('#dIcp').value
        ? parseInt($('#dIcp').value, 10) : undefined;
      const s = await apiPost('discovery.php?action=run', { icp_id: icpId });
      msg.innerHTML = '<div class="ok">Discovery complete: '
        + esc(s.companies_checked) + ' companies checked'
        + (s.boards_detected ? ', ' + esc(s.boards_detected)
          + ' boards auto-detected' : '')
        + ', '
        + esc(s.jobs_seen) + ' jobs seen, ' + esc(s.new_observations)
        + ' new signal observations'
        + (s.news_checked ? ' (' + esc(s.news_observations || 0)
          + ' from news across ' + esc(s.news_checked)
          + ' companies)' : '')
        + ', ' + esc(s.matches_scored)
        + ' matches scored, ' + esc(s.queued) + ' queued for '
        + 'enrichment.'
        + (s.errors && s.errors.length
          ? '<br>Board errors: ' + esc(s.errors.join(' | ')) : '')
        + '</div>';
      setTimeout(() => renderDiscovery(), 1200);
    } catch (err) {
      msg.innerHTML = errBox(err);
    }
  };
  if (apolloReady) {
    $('#dCompanies').onclick = async () => {
      try {
        const resp = await confirmedCall('Apollo company search',
          (confirmed) => apiPost('discovery.php?action=apollo_companies', {
            icp_id: $('#dIcp').value
              ? parseInt($('#dIcp').value, 10) : undefined,
            confirmed,
          }));
        if (resp === null) { return; }
        toast('Apollo companies: ' + resp.found + ' found, '
          + resp.inserted + ' added, ' + resp.skipped + ' skipped.'
          + (resp.total_pages
            ? ' Page ' + resp.page + ' of ' + resp.total_pages
              + ' (' + resp.total_entries + ' companies match).'
              + (resp.exhausted
                ? ' That was the last page; the next search starts '
                  + 'over from page 1.'
                : ' Click again for the next page.')
            : '')
          + ' Run discovery to check them for signals.');
        renderDiscovery();
      } catch (err) {
        $('#dRunMsg').innerHTML = errBox(err);
      }
    };
    $('#dApollo').onclick = async () => {
      try {
        const resp = await confirmedCall('Apollo people search',
          (confirmed) => apiPost('discovery.php?action=apollo_search', {
            icp_id: $('#dIcp').value
              ? parseInt($('#dIcp').value, 10) : undefined,
            confirmed,
          }));
        if (resp === null) { return; }
        toast('Apollo search: ' + resp.found + ' found, '
          + resp.inserted + ' added, ' + resp.skipped + ' skipped.');
        renderDiscovery();
      } catch (err) {
        $('#dRunMsg').innerHTML = errBox(err);
      }
    };
  }
  el.querySelectorAll('[data-board]').forEach((b) => {
    b.onclick = async () => {
      try {
        const r = await apiPost('discovery.php?action=detect_boards',
          { company_id: parseInt(b.dataset.board, 10) });
        toast(r.detected
          ? 'Board detected: ' + r.board_type + ' / ' + r.board_token
          : r.message);
        renderDiscovery();
      } catch (err) { toast(err.message); }
    };
  });
  el.querySelectorAll('[data-people]').forEach((b) => {
    b.onclick = async () => {
      b.disabled = true;
      try {
        const r = await apiPost('discovery.php?action=people_for_company', {
          company_id: parseInt(b.dataset.people, 10),
          icp_id: $('#dIcp').value
            ? parseInt($('#dIcp').value, 10) : undefined,
        });
        toast('People search: ' + r.added + ' added for this company.');
        renderDiscovery();
      } catch (err) { toast(err.message); b.disabled = false; }
    };
  });
  el.querySelectorAll('[data-enrich]').forEach((b) => {
    b.onclick = async () => {
      try {
        const resp = await confirmedCall('Enrich prospect',
          (confirmed) => apiPost('discovery.php?action=enrich', {
            match_id: parseInt(b.dataset.enrich, 10), confirmed,
          }));
        if (resp === null) { return; }
        toast('Enriched. Credits remaining this month: '
          + resp.credits_remaining + '.');
        renderDiscovery();
      } catch (err) { toast(err.message); }
    };
  });
  el.querySelectorAll('[data-draft]').forEach((b) => {
    b.onclick = () => {
      state.queueMatchId = parseInt(b.dataset.draft, 10);
      navigate('queue');
    };
  });
  $('#coAdd').onclick = async () => {
    const name = $('#coName').value.trim();
    if (!name) {
      $('#coMsg').innerHTML = '<div class="err">Company name is required.</div>';
      return;
    }
    try {
      const r = await apiPost('discovery.php?action=import_csv', {
        kind: 'companies',
        rows: [{ name, website: $('#coSite').value.trim(),
                 careers_url: $('#coCareers').value.trim() }],
      });
      toast(r.inserted ? 'Company added.' : 'That company already exists.');
      renderDiscovery();
    } catch (err) {
      $('#coMsg').innerHTML = errBox(err);
    }
  };
  $('#csvGo').onclick = async () => {
    const file = $('#csvFile').files[0];
    if (!file) {
      $('#csvMsg').innerHTML = '<div class="err">Choose a CSV file first.</div>';
      return;
    }
    try {
      const text = await file.text();
      const rows = parseCsv(text);
      if (!rows.length) {
        $('#csvMsg').innerHTML = '<div class="err">No data rows found in that file.</div>';
        return;
      }
      const r = await apiPost('discovery.php?action=import_csv', {
        kind: $('#csvKind').value, rows,
      });
      $('#csvMsg').innerHTML = '<div class="ok">Imported: '
        + esc(r.inserted) + ' inserted, ' + esc(r.skipped)
        + ' skipped as duplicates.</div>';
      setTimeout(() => renderDiscovery(), 900);
    } catch (err) {
      $('#csvMsg').innerHTML = errBox(err);
    }
  };
}

// --- Outreach queue ------------------------------------------------------------------------

async function renderQueue() {
  const el = viewEl();
  if (!state.activeClientId) {
    el.innerHTML = '<h2>Outreach Queue</h2>' + noClientNotice();
    return;
  }
  const prospects = await apiGet('discovery.php', { view: 'prospects' });
  const watchlist = await apiGet('discovery.php', { view: 'watchlist' });
  const seen = {};
  const matches = [];
  prospects.concat(watchlist).forEach((m) => {
    if (!seen[m.id]) { seen[m.id] = true; matches.push(m); }
  });
  matches.sort((a, b) => b.score - a.score);
  const campaigns = await apiGet('campaigns.php');
  const pending = await apiGet('queue.php', { status: 'pending' });
  const approved = await apiGet('queue.php', { status: 'approved' });

  const matchOptions = matches.map((m) =>
    '<option value="' + m.id + '"'
    + (state.queueMatchId === m.id ? ' selected' : '') + '>'
    + esc(m.score) + ' · ' + esc(m.company_name) + ' · '
    + esc(m.contact_name || '(no contact)') + ' · ' + esc(m.status)
    + '</option>').join('');

  const evidenceHtml = (m) => {
    if (!m.signal) {
      return '<div class="evidence">No signal observation recorded for '
        + 'this company. The draft must not claim one.</div>';
    }
    return '<div class="evidence"><strong>Signal:</strong> '
      + esc(m.signal.signal_name) + '<br><strong>Evidence:</strong> '
      + esc(m.signal.evidence) + '<br><strong>Source:</strong> '
      + '<a href="' + esc(m.signal.source_url) + '" target="_blank" '
      + 'rel="noopener">' + esc(m.signal.source_url) + '</a>'
      + ' <span class="muted">(observed ' + esc(m.signal.observed_at)
      + ')</span></div>';
  };

  const msgCard = (m, mode) => {
    let buttons = '';
    if (mode === 'pending') {
      buttons = '<label class="checkline"><input type="checkbox" '
        + 'class="bulkBox" value="' + m.id + '"> Select for bulk approve</label>'
        + '<div class="btnrow">'
        + '<button class="btn" data-approve="' + m.id + '">Approve</button>'
        + '<button class="btn secondary" data-saveedit="' + m.id + '">Save edits</button>'
        + '<button class="btn secondary" data-regen="' + m.id + '">Regenerate</button>'
        + '<button class="btn danger" data-reject="' + m.id + '">Reject</button>'
        + '</div>';
    } else {
      buttons = '<div class="btnrow">'
        + '<button class="btn" data-sent="' + m.id + '">Mark sent</button>'
        + '<button class="btn secondary" data-gmail="' + m.id + '">Create Gmail draft</button>'
        + '</div>';
    }
    return '<div class="msgcard" id="msg-' + m.id + '">'
      + '<div class="meta">' + esc(m.contact_name || '(no contact)')
      + ' · ' + esc(m.contact_title || '') + ' · ' + esc(m.company_name)
      + ' · score ' + esc(m.match_score) + ' · ' + esc(m.channel)
      + ' · version ' + esc(m.version) + ' · ' + statusBadge(m.status)
      + '</div>'
      + evidenceHtml(m)
      + (m.channel === 'email'
        ? '<label class="f"><span>Subject</span><input type="text" '
          + 'id="subj-' + m.id + '" value="' + esc(m.subject || '') + '"></label>'
        : '')
      + '<label class="f"><span>Message</span><textarea id="body-'
      + m.id + '">' + esc(m.body || '') + '</textarea></label>'
      + buttons + '<div id="msgMsg-' + m.id + '"></div></div>';
  };

  el.innerHTML = '<h2>Outreach Queue</h2>'
    + '<p class="viewsub">Every draft waits here for a human decision. '
    + 'Drafts are generated only from the stored profile, the contact, '
    + 'and the signal evidence shown on each card. Approval is blocked '
    + 'for suppressed or unsubscribed contacts, and nothing sends '
    + 'without approval.</p>'
    + '<div class="panel"><h3>Draft a new message</h3>'
    + (matches.length
      ? '<div class="formrow">'
        + '<label class="f"><span>Prospect</span><select id="qMatch">'
        + matchOptions + '</select></label>'
        + '<label class="f"><span>Channel</span><select id="qChannel">'
        + '<option value="email">Email</option>'
        + '<option value="linkedin">LinkedIn (copyable, manual send)</option>'
        + '</select></label>'
        + '<label class="f"><span>Tone</span><select id="qTone">'
        + '<option value="concise">Concise</option>'
        + '<option value="conversational">Conversational</option>'
        + '<option value="professional">Professional</option>'
        + '</select></label>'
        + '<label class="f"><span>Campaign (optional)</span>'
        + '<select id="qCampaign"><option value="">None</option>'
        + campaigns.map((c) => '<option value="' + c.id + '">'
          + esc(c.name) + '</option>').join('')
        + '</select></label></div>'
        + '<div class="btnrow"><button class="btn" id="qGenerate">Generate with AI</button>'
        + '<span class="muted small">Shows a cost estimate before anything runs. Needs an LLM key in the panel vault.</span></div>'
        + '<div id="qGenMsg"></div>'
        + '<h3>Or write it yourself</h3>'
        + '<label class="f"><span>Subject (email only)</span>'
        + '<input type="text" id="qSubject"></label>'
        + '<label class="f"><span>Message</span>'
        + '<textarea id="qBody"></textarea></label>'
        + '<div class="btnrow"><button class="btn secondary" id="qCompose">Save manual draft</button></div>'
      : '<p class="empty">No scored prospects yet. Run discovery first.</p>')
    + '</div>'
    + '<div class="panel"><h3>Awaiting review (' + pending.length + ')</h3>'
    + (pending.length
      ? '<div class="btnrow"><button class="btn secondary" id="qBulk">Approve selected</button></div>'
        + pending.map((m) => msgCard(m, 'pending')).join('')
      : '<p class="empty">Nothing waiting for review.</p>')
    + '</div>'
    + '<div class="panel"><h3>Approved, ready to send (' + approved.length + ')</h3>'
    + '<p class="muted small">Mark sent after you send through your '
    + 'own account, or create a Gmail draft when Gmail is connected '
    + 'in Settings. LinkedIn messages are copyable text for manual '
    + 'sending; there is no LinkedIn automation.</p>'
    + (approved.length
      ? approved.map((m) => msgCard(m, 'approved')).join('')
      : '<p class="empty">No approved messages right now.</p>')
    + '</div>';

  state.queueMatchId = null;

  const genParams = () => ({
    match_id: parseInt($('#qMatch').value, 10),
    channel: $('#qChannel').value,
    tone: $('#qTone').value,
    campaign_id: $('#qCampaign').value
      ? parseInt($('#qCampaign').value, 10) : undefined,
  });
  if (matches.length) {
    $('#qGenerate').onclick = async () => {
      try {
        const resp = await confirmedCall('Generate message',
          (confirmed) => apiPost('queue.php?action=generate',
            Object.assign(genParams(), { confirmed })));
        if (resp === null) { return; }
        toast('Draft created and waiting for review below.');
        renderQueue();
      } catch (err) {
        $('#qGenMsg').innerHTML = errBox(err);
        if (err.data && err.data.error === 'no_llm_key') {
          $('#qBody').focus();
        }
      }
    };
    $('#qCompose').onclick = async () => {
      try {
        await apiPost('queue.php?action=compose', {
          match_id: parseInt($('#qMatch').value, 10),
          channel: $('#qChannel').value,
          campaign_id: $('#qCampaign').value
            ? parseInt($('#qCampaign').value, 10) : undefined,
          subject: $('#qSubject').value,
          body: $('#qBody').value,
        });
        toast('Manual draft saved for review.');
        renderQueue();
      } catch (err) {
        $('#qGenMsg').innerHTML = errBox(err);
      }
    };
  }
  const wire = (attr, fn) => {
    el.querySelectorAll('[' + attr + ']').forEach((b) => {
      b.onclick = () => fn(parseInt(b.getAttribute(attr), 10));
    });
  };
  wire('data-approve', async (id) => {
    try {
      await apiPost('queue.php?action=approve', { id });
      toast('Approved.');
      renderQueue();
    } catch (err) {
      $('#msgMsg-' + id).innerHTML = errBox(err);
    }
  });
  wire('data-reject', async (id) => {
    await apiPost('queue.php?action=reject', { id });
    toast('Rejected.');
    renderQueue();
  });
  wire('data-saveedit', async (id) => {
    try {
      await apiPost('queue.php?action=edit', {
        id,
        subject: $('#subj-' + id) ? $('#subj-' + id).value : undefined,
        body: $('#body-' + id).value,
      });
      toast('Edits saved.');
    } catch (err) {
      $('#msgMsg-' + id).innerHTML = errBox(err);
    }
  });
  wire('data-regen', async (id) => {
    try {
      const resp = await confirmedCall('Regenerate message',
        (confirmed) => apiPost('queue.php?action=regenerate',
          { id, confirmed }));
      if (resp === null) { return; }
      toast('New version drafted.');
      renderQueue();
    } catch (err) {
      $('#msgMsg-' + id).innerHTML = errBox(err);
    }
  });
  wire('data-sent', async (id) => {
    try {
      await apiPost('queue.php?action=mark_sent', { id });
      toast('Marked sent.');
      renderQueue();
    } catch (err) {
      $('#msgMsg-' + id).innerHTML = errBox(err);
    }
  });
  wire('data-gmail', async (id) => {
    try {
      const r = await apiPost('queue.php?action=gmail_draft', { id });
      toast(r.message || 'Gmail draft created.');
    } catch (err) {
      const extra = err.data && err.data.setup
        ? '<div class="note">' + esc(err.data.setup) + '</div>' : '';
      $('#msgMsg-' + id).innerHTML = errBox(err) + extra;
    }
  });
  const bulkBtn = $('#qBulk');
  if (bulkBtn) {
    bulkBtn.onclick = async () => {
      const ids = Array.prototype.map.call(
        document.querySelectorAll('.bulkBox:checked'),
        (cb) => parseInt(cb.value, 10));
      if (!ids.length) { toast('Select at least one message.'); return; }
      const r = await apiPost('queue.php?action=bulk_approve', { ids });
      const blocked = r.results.filter((x) => !x.ok);
      toast('Approved ' + (ids.length - blocked.length) + ' of '
        + ids.length + '.'
        + (blocked.length
          ? ' Blocked: ' + blocked.map((x) => x.error).join(' | ') : ''));
      renderQueue();
    };
  }
}

// --- Campaigns -----------------------------------------------------------------------------

async function renderCampaigns() {
  const el = viewEl();
  if (!state.activeClientId) {
    el.innerHTML = '<h2>Campaigns</h2>' + noClientNotice();
    return;
  }
  const campaigns = await apiGet('campaigns.php');
  const icps = await apiGet('icps.php');
  const suppression = await apiGet('campaigns.php', { view: 'suppression' });
  const states = ['Draft', 'Active', 'Paused', 'Completed'];

  el.innerHTML = '<h2>Campaigns</h2>'
    + '<p class="viewsub">A campaign ties a client, an ICP, and a '
    + 'channel together. Active campaigns are what the scheduled '
    + 'runner picks up: it re-runs discovery for the campaign ICP and '
    + 'new qualified leads land in the Outreach Queue. Follow-ups stop '
    + 'when a reply, bounce, or unsubscribe is recorded, and the '
    + 'suppression list always wins.</p>'
    + '<div class="panel"><h3>Campaigns</h3>'
    + (campaigns.length
      ? '<table><thead><tr><th>Name</th><th>ICP</th><th>Channel</th>'
        + '<th>State</th><th>Messages</th><th>Sent</th><th>Pending</th>'
        + '<th>Set state</th></tr></thead><tbody>'
        + campaigns.map((c) => '<tr><td><strong>' + esc(c.name)
          + '</strong><br><span class="muted small">' + esc(c.strategy || '')
          + '</span></td><td>' + esc(c.icp_name || '') + '</td><td>'
          + esc(c.channel || '') + '</td><td>' + statusBadge(c.state)
          + '</td><td>' + esc(c.messages) + '</td><td>' + esc(c.sent)
          + '</td><td>' + esc(c.pending) + '</td><td><div class="btnrow">'
          + states.map((s) => '<button class="btn small secondary" '
            + 'data-state="' + c.id + ':' + s + '"'
            + (c.state === s ? ' disabled' : '') + '>' + s + '</button>').join('')
          + '</div></td></tr>').join('')
        + '</tbody></table>'
      : '<p class="empty">No campaigns yet.</p>')
    + '<h3>New campaign</h3>'
    + '<div class="formrow">'
    + '<label class="f"><span>Name</span><input type="text" id="cpName"></label>'
    + '<label class="f"><span>ICP</span><select id="cpIcp">'
    + '<option value="">First ICP</option>'
    + icps.map((i) => '<option value="' + i.id + '">' + esc(i.name) + '</option>').join('')
    + '</select></label>'
    + '<label class="f"><span>Channel</span><select id="cpChannel">'
    + '<option value="email">Email</option>'
    + '<option value="linkedin">LinkedIn</option></select></label></div>'
    + '<label class="f"><span>Messaging strategy</span>'
    + '<textarea id="cpStrategy" placeholder="What this campaign is trying to start, in plain words"></textarea></label>'
    + '<div class="btnrow"><button class="btn" id="cpCreate">Create campaign</button></div>'
    + '<div id="cpMsg"></div></div>'
    + '<div class="panel"><h3>Suppression list</h3>'
    + '<p class="muted small">Suppressed emails can never be approved '
    + 'or sent to. Global entries apply to every client; client '
    + 'entries apply only here.</p>'
    + (suppression.length
      ? '<table><thead><tr><th>Email</th><th>Scope</th><th>Reason</th>'
        + '<th>Added</th><th></th></tr></thead><tbody>'
        + suppression.map((s) => '<tr><td>' + esc(s.email) + '</td><td>'
          + esc(s.scope) + '</td><td>' + esc(s.reason || '') + '</td><td>'
          + esc(s.created_at) + '</td><td><button class="btn small '
          + 'danger" data-unsuppress="' + s.id + '">Remove</button></td></tr>').join('')
        + '</tbody></table>'
      : '<p class="empty">Suppression list is empty.</p>')
    + '<div class="formrow">'
    + '<label class="f"><span>Email to suppress</span><input type="text" id="supEmail"></label>'
    + '<label class="f"><span>Reason</span><input type="text" id="supReason" placeholder="unsubscribed, bounced, asked not to be contacted"></label>'
    + '<label class="f"><span>Scope</span><select id="supScope">'
    + '<option value="client">This client</option>'
    + '<option value="global">Global (all clients)</option>'
    + '</select></label></div>'
    + '<div class="btnrow"><button class="btn secondary" id="supAdd">Add to suppression</button></div>'
    + '<div id="supMsg"></div></div>';

  el.querySelectorAll('[data-state]').forEach((b) => {
    b.onclick = async () => {
      const parts = b.dataset.state.split(':');
      await apiPost('campaigns.php?action=set_state', {
        id: parseInt(parts[0], 10), state: parts[1],
      });
      toast('Campaign state: ' + parts[1]);
      renderCampaigns();
    };
  });
  el.querySelectorAll('[data-unsuppress]').forEach((b) => {
    b.onclick = async () => {
      await apiPost('campaigns.php?action=unsuppress',
        { id: parseInt(b.dataset.unsuppress, 10), omit_client: true });
      toast('Suppression removed.');
      renderCampaigns();
    };
  });
  $('#cpCreate').onclick = async () => {
    if (!$('#cpName').value.trim()) {
      $('#cpMsg').innerHTML = '<div class="err">Name is required.</div>';
      return;
    }
    try {
      await apiPost('campaigns.php', {
        name: $('#cpName').value.trim(),
        icp_id: $('#cpIcp').value
          ? parseInt($('#cpIcp').value, 10) : undefined,
        channel: $('#cpChannel').value,
        strategy: $('#cpStrategy').value,
      });
      toast('Campaign created as a Draft.');
      renderCampaigns();
    } catch (err) {
      $('#cpMsg').innerHTML = errBox(err);
    }
  };
  $('#supAdd').onclick = async () => {
    const body = {
      email: $('#supEmail').value.trim(),
      reason: $('#supReason').value.trim() || 'manual',
    };
    if ($('#supScope').value === 'global') { body.omit_client = true; }
    try {
      await apiPost('campaigns.php?action=suppress', body);
      toast('Suppressed.');
      renderCampaigns();
    } catch (err) {
      $('#supMsg').innerHTML = errBox(err);
    }
  };
}

// --- Reporting --------------------------------------------------------------------------------

async function renderReporting() {
  const el = viewEl();
  if (!state.activeClientId) {
    el.innerHTML = '<h2>Reporting</h2>' + noClientNotice();
    return;
  }
  const load = async () => {
    const params = {};
    if ($('#rpFrom').value) { params.from = $('#rpFrom').value; }
    if ($('#rpTo').value) { params.to = $('#rpTo').value; }
    const report = await apiGet('reports.php', params);
    state.lastReport = report;
    const m = report.metrics;
    const card = (num, lbl) => '<div class="card"><div class="num">'
      + esc(num) + '</div><div class="lbl">' + esc(lbl) + '</div></div>';
    const table = (title, headers, rows) => '<h3>' + esc(title) + '</h3>'
      + (rows.length
        ? '<table><thead><tr>' + headers.map((h) => '<th>' + esc(h) + '</th>').join('')
          + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>'
        : '<p class="empty">No data yet.</p>');
    $('#rpResults').innerHTML = '<div class="cards">'
      + card(m.companies_discovered, 'Companies discovered')
      + card(m.contacts_discovered, 'Contacts discovered')
      + card(m.contacts_qualified, 'Contacts qualified')
      + card(m.contacts_enriched, 'Contacts enriched')
      + card(m.email_coverage_pct + '%', 'Email coverage')
      + card(m.messages_drafted, 'Messages drafted')
      + card(m.messages_approved, 'Messages approved')
      + card(m.emails_sent, 'Emails sent')
      + card(m.replies, 'Replies')
      + card(m.positive_replies, 'Positive replies')
      + card(m.meetings, 'Meetings booked')
      + card(m.api_credits, 'API credits')
      + card('$' + m.est_cost, 'Estimated cost')
      + card(m.cost_per_positive_reply === null ? 'n/a' : '$' + m.cost_per_positive_reply, 'Cost per positive reply')
      + card(m.cost_per_meeting === null ? 'n/a' : '$' + m.cost_per_meeting, 'Cost per meeting')
      + '</div>'
      + table('Provider usage', ['Provider', 'Calls', 'Credits', 'Est. cost'],
        report.usage.map((u) => '<tr><td>' + esc(u.provider) + '</td><td>'
          + esc(u.calls) + '</td><td>' + esc(u.credits) + '</td><td>$'
          + esc(u.est_cost) + '</td></tr>'))
      + table('By buying signal', ['Signal', 'Observations', 'Matches', 'Replies', 'Positive'],
        report.breakdowns.by_signal.map((r) => '<tr><td>' + esc(r.name)
          + '</td><td>' + esc(r.observations) + '</td><td>'
          + esc(r.matches_count) + '</td><td>' + esc(r.replies)
          + '</td><td>' + esc(r.positive_replies) + '</td></tr>'))
      + table('By job title', ['Title', 'Contacts', 'Qualified'],
        report.breakdowns.by_title.map((r) => '<tr><td>' + esc(r.title)
          + '</td><td>' + esc(r.contacts) + '</td><td>' + esc(r.qualified)
          + '</td></tr>'))
      + table('By channel', ['Channel', 'Drafted', 'Approved', 'Sent', 'Replies', 'Positive'],
        report.breakdowns.by_channel.map((r) => '<tr><td>' + esc(r.channel)
          + '</td><td>' + esc(r.drafted) + '</td><td>' + esc(r.approved)
          + '</td><td>' + esc(r.sent) + '</td><td>' + esc(r.replies)
          + '</td><td>' + esc(r.positive_replies) + '</td></tr>'))
      + table('By ICP', ['ICP', 'Matches', 'Avg score', 'Qualified'],
        report.breakdowns.by_icp.map((r) => '<tr><td>' + esc(r.name)
          + '</td><td>' + esc(r.matches_count) + '</td><td>'
          + esc(r.avg_score) + '</td><td>' + esc(r.qualified)
          + '</td></tr>'));
  };

  el.innerHTML = '<h2>Reporting</h2>'
    + '<p class="viewsub">Every metric traces back to its ICP, signal, '
    + 'contact, and message. Cost per reply and per meeting only '
    + 'appear once the denominator exists; the engine never invents '
    + 'a rate.</p>'
    + '<div class="panel"><div class="formrow">'
    + '<label class="f"><span>From</span><input type="date" id="rpFrom"></label>'
    + '<label class="f"><span>To</span><input type="date" id="rpTo"></label></div>'
    + '<div class="btnrow"><button class="btn" id="rpLoad">Load report</button>'
    + '<button class="btn secondary" id="rpCsv">Export CSV</button></div>'
    + '<div id="rpResults"><p class="muted">Loading...</p></div></div>'
    + '<div class="panel"><h3>Recommendations</h3><div id="rpRecs">'
    + '<p class="muted">Loading...</p></div></div>';

  $('#rpLoad').onclick = () => load().catch((e) => {
    $('#rpResults').innerHTML = errBox(e);
  });
  $('#rpCsv').onclick = () => {
    const r = state.lastReport;
    if (!r) { toast('Load the report first.'); return; }
    const rows = [['section', 'name', 'value1', 'value2', 'value3']];
    Object.keys(r.metrics).forEach((k) => rows.push(['metric', k, r.metrics[k], '', '']));
    r.usage.forEach((u) => rows.push(['usage', u.provider, u.calls, u.credits, u.est_cost]));
    r.breakdowns.by_signal.forEach((x) => rows.push(['by_signal', x.name, x.observations, x.matches_count, x.positive_replies]));
    r.breakdowns.by_title.forEach((x) => rows.push(['by_title', x.title, x.contacts, x.qualified, '']));
    r.breakdowns.by_channel.forEach((x) => rows.push(['by_channel', x.channel, x.drafted, x.sent, x.positive_replies]));
    r.breakdowns.by_icp.forEach((x) => rows.push(['by_icp', x.name, x.matches_count, x.avg_score, x.qualified]));
    downloadCsv('outbound-report.csv', rows);
  };
  load().catch((e) => { $('#rpResults').innerHTML = errBox(e); });

  try {
    const recs = await apiGet('reports.php', { view: 'recommendations' });
    const box = $('#rpRecs');
    if (!recs.recommendations.length) {
      box.innerHTML = '<p class="empty">No recommendations yet. They '
        + 'appear once sent messages start collecting replies.</p>'
        + '<p class="muted small">' + esc(recs.note || '') + '</p>';
    } else {
      box.innerHTML = recs.recommendations.map((r, idx) =>
        '<div class="note"><p>' + esc(r.text) + '</p>'
        + '<p class="muted small">Sample size: ' + esc(r.sample_size)
        + ' · ' + (r.confidence === 'pattern'
          ? '<span class="badge good">pattern</span>'
          : '<span class="badge warn">observation, small sample</span>')
        + '</p>'
        + (r.action
          ? '<button class="btn small" data-rec="' + idx + '">Apply this change</button>'
          : '')
        + '</div>').join('')
        + '<p class="muted small">' + esc(recs.note || '') + '</p>';
      box.querySelectorAll('[data-rec]').forEach((b) => {
        b.onclick = async () => {
          const rec = recs.recommendations[parseInt(b.dataset.rec, 10)];
          const ok = await confirmDialog('Apply recommendation',
            'Apply this change now? It is logged as an experiment so '
            + 'the result can be compared later.');
          if (!ok) { return; }
          await apiPost('reports.php?action=apply_recommendation',
            { action: rec.action });
          toast('Recommendation applied and logged.');
          b.disabled = true;
        };
      });
    }
  } catch (err) {
    $('#rpRecs').innerHTML = errBox(err);
  }
}

// --- Settings -------------------------------------------------------------------------------------

async function renderSettings() {
  const el = viewEl();
  if (!state.activeClientId) {
    el.innerHTML = '<h2>Settings &amp; Integrations</h2>' + noClientNotice();
    return;
  }
  const data = await apiGet('settings.php');
  state.settings = data;
  const ig = data.integrations;
  const st = data.settings;
  const present = (ok, label) => '<span class="badge '
    + (ok ? 'good' : 'warn') + '">' + esc(label) + '</span>';

  el.innerHTML = '<h2>Settings &amp; Integrations</h2>'
    + '<p class="viewsub">Keys are write only: this page shows status '
    + 'and masked tails, never a key value. LLM keys live in the main '
    + 'panel vault and are shared with the engine.</p>'
    + '<div class="panel"><h3>Apollo</h3>'
    + '<p>Status: ' + (ig.apollo.present
      ? present(true, 'key saved ' + (ig.apollo.masked || ''))
      : present(false, 'no key saved')) + '</p>'
    + '<p class="muted small">Without a key, Apollo search and '
    + 'enrichment are unavailable. Discovery still runs on CSV import '
    + 'and the free public job board feeds (Greenhouse, Lever, '
    + 'Ashby, Workable, SmartRecruiters, Recruitee, Breezy, '
    + 'Teamtailor, Workday).</p>'
    + '<label class="f"><span>Apollo API key</span>'
    + '<input type="text" id="setApolloKey" autocomplete="off" placeholder="Paste key to save"></label>'
    + '<div class="btnrow"><button class="btn" id="setApolloSave">Save Apollo key</button>'
    + '<button class="btn danger" id="setApolloRemove">Remove key</button></div>'
    + '<h3>Hunter</h3>'
    + '<p>Status: ' + (ig.hunter.present
      ? present(true, 'key saved ' + (ig.hunter.masked || ''))
      : present(false, 'no key saved')) + '</p>'
    + '<p class="muted small">Stored for email verification use. The '
    + 'current enrichment path runs through Apollo.</p>'
    + '<label class="f"><span>Hunter API key</span>'
    + '<input type="text" id="setHunterKey" autocomplete="off" placeholder="Paste key to save"></label>'
    + '<div class="btnrow"><button class="btn" id="setHunterSave">Save Hunter key</button>'
    + '<button class="btn danger" id="setHunterRemove">Remove key</button></div>'
    + '<h3>AI drafting (panel vault)</h3>'
    + '<dl class="kv">'
    + '<dt>OpenAI</dt><dd>' + (ig.llm.openai ? 'key present' : 'not set') + '</dd>'
    + '<dt>Anthropic</dt><dd>' + (ig.llm.anthropic ? 'key present' : 'not set') + '</dd>'
    + '<dt>TypeSafe</dt><dd>' + (ig.llm.typesafe ? 'key present' : 'not set') + '</dd>'
    + '<dt>Used for drafting</dt><dd>' + esc(ig.llm.active || 'none, AI drafting unavailable; manual compose still works') + '</dd>'
    + '</dl></div>'
    + '<div class="panel"><h3>Gmail sending</h3>'
    + '<p>Status: ' + (ig.gmail.connected
      ? present(true, 'connected' + (ig.gmail.email ? ' as ' + ig.gmail.email : ''))
      : present(false, 'not connected')) + '</p>'
    + '<p class="muted small">Setup: create a Google Cloud OAuth '
    + 'client, save its client id and secret here, then add a refresh '
    + 'token for the sending account. Until then, Gmail draft '
    + 'creation returns these setup steps and sends nothing. Sending '
    + 'always requires approval in the Outreach Queue first.</p>'
    + '<div class="formrow">'
    + '<label class="f"><span>OAuth client id</span><input type="text" id="gmId"></label>'
    + '<label class="f"><span>OAuth client secret</span><input type="text" id="gmSecret" autocomplete="off"></label></div>'
    + '<div class="formrow">'
    + '<label class="f"><span>Refresh token</span><input type="text" id="gmToken" autocomplete="off"></label>'
    + '<label class="f"><span>Account email</span><input type="text" id="gmEmail"></label></div>'
    + '<div class="btnrow"><button class="btn" id="gmSave">Save Gmail connection</button>'
    + '<button class="btn danger" id="gmDisconnect">Disconnect Gmail</button></div>'
    + '<div id="gmMsg"></div></div>'
    + '<div class="panel"><h3>Scheduled runs</h3>'
    + '<p>Status: ' + (ig.cron_secret.set
      ? present(true, 'cron secret set')
      : present(false, 'cron secret not set')) + '</p>'
    + '<p class="muted small">Point a scheduler (GitHub Actions or a '
    + 'cron service) at <code>outbound/api/cron.php?secret=YOUR_SECRET'
    + '&amp;action=run_due</code>. It runs discovery for every Active '
    + 'campaign, at most once per client per 6 hours.</p>'
    + '<label class="f"><span>New cron secret (12+ characters)</span>'
    + '<input type="text" id="cronSecret" autocomplete="off"></label>'
    + '<div class="btnrow"><button class="btn" id="cronSave">Save cron secret</button></div>'
    + '<div id="cronMsg"></div></div>'
    + '<div class="panel"><h3>Client operating settings</h3>'
    + '<div class="formrow">'
    + '<label class="f"><span>Apollo enrichment budget (credits per month)</span>'
    + '<input type="number" id="stBudget" value="' + esc(st.enrichment_budget_credits) + '"></label>'
    + '<label class="f"><span>Sending limit per day</span>'
    + '<input type="number" id="stSendLimit" value="' + esc(st.sending_limit_day) + '"></label>'
    + '<label class="f"><span>Message length (words)</span>'
    + '<input type="number" id="stLen" value="' + esc(st.message_length) + '"></label>'
    + '<label class="f"><span>Default tone</span><select id="stTone">'
    + ['concise', 'conversational', 'professional'].map((t) =>
      '<option value="' + t + '"' + (st.default_tone === t ? ' selected' : '') + '>' + t + '</option>').join('')
    + '</select></label></div>'
    + '<label class="f"><span>Writing guidelines for this client</span>'
    + '<textarea id="stGuidelines">' + esc(st.writing_guidelines || '') + '</textarea></label>'
    + '<div class="btnrow"><button class="btn" id="stSave">Save settings</button></div>'
    + '<div id="stMsg"></div></div>';

  const saveKey = async (provider, inputId) => {
    const key = $(inputId).value.trim();
    if (!key) { toast('Paste a key first, or use Remove.'); return; }
    await apiPost('settings.php?action=save_key', { provider, key, omit_client: true });
    toast(provider + ' key saved.');
    renderSettings();
  };
  const removeKey = async (provider) => {
    await apiPost('settings.php?action=save_key', { provider, key: '', omit_client: true });
    toast(provider + ' key removed.');
    renderSettings();
  };
  $('#setApolloSave').onclick = () => saveKey('apollo', '#setApolloKey');
  $('#setApolloRemove').onclick = () => removeKey('apollo');
  $('#setHunterSave').onclick = () => saveKey('hunter', '#setHunterKey');
  $('#setHunterRemove').onclick = () => removeKey('hunter');
  $('#gmSave').onclick = async () => {
    try {
      await apiPost('settings.php?action=save_settings', {
        gmail_client_id: $('#gmId').value.trim(),
        gmail_client_secret: $('#gmSecret').value.trim(),
        gmail_refresh_token: $('#gmToken').value.trim(),
        gmail_email: $('#gmEmail').value.trim(),
      });
      toast('Gmail settings saved.');
      renderSettings();
    } catch (err) {
      $('#gmMsg').innerHTML = errBox(err);
    }
  };
  $('#gmDisconnect').onclick = async () => {
    await apiPost('settings.php?action=save_settings', { gmail_disconnect: true });
    toast('Gmail disconnected.');
    renderSettings();
  };
  $('#cronSave').onclick = async () => {
    try {
      await apiPost('settings.php?action=save_cron_secret',
        { secret: $('#cronSecret').value, omit_client: true });
      toast('Cron secret saved.');
      renderSettings();
    } catch (err) {
      $('#cronMsg').innerHTML = errBox(err);
    }
  };
  $('#stSave').onclick = async () => {
    try {
      await apiPost('settings.php?action=save_settings', {
        enrichment_budget_credits: parseInt($('#stBudget').value, 10) || 0,
        sending_limit_day: parseInt($('#stSendLimit').value, 10) || 0,
        message_length: parseInt($('#stLen').value, 10) || 120,
        default_tone: $('#stTone').value,
        writing_guidelines: $('#stGuidelines').value,
      });
      toast('Settings saved.');
    } catch (err) {
      $('#stMsg').innerHTML = errBox(err);
    }
  };
}

// --- boot -------------------------------------------------------------------------------------------

document.querySelectorAll('#sidenav button').forEach((b) => {
  b.onclick = () => navigate(b.dataset.view);
});
$('#clientSwitch').onchange = async () => {
  state.activeClientId = parseInt($('#clientSwitch').value, 10) || null;
  state.settings = null;
  setHash();
  navigate(state.view);
};
window.addEventListener('hashchange', () => {
  const parsed = parseHash();
  if (parsed.cid && parsed.cid !== state.activeClientId
      && state.clients.some((c) => c.id === parsed.cid)) {
    state.activeClientId = parsed.cid;
    switcherHtml();
  }
  if (parsed.view !== state.view) {
    navigate(parsed.view);
  }
});

(async function boot() {
  const parsed = parseHash();
  state.view = parsed.view;
  if (parsed.cid) { state.activeClientId = parsed.cid; }
  try {
    await loadClients();
  } catch (err) {
    viewEl().innerHTML = '<h2>Outbound Engine</h2>' + errBox(err);
    return;
  }
  navigate(state.view);
})();
