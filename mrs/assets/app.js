/* MRS – Management Report System (vanilla JS SPA, hash routing) */
"use strict";

// ───────────────────────── 기본 유틸 ─────────────────────────
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const S = { me: null, catalog: null, customers: [] };
const STATUS = { critical: "위험", warning: "요주의", normal: "정상" };
const COLOR = { critical: "var(--red)", warning: "var(--amber)", normal: "var(--green)" };

// PHP 웹호스팅용: 모든 요청을 api/index.php?p=경로 로 보내고, PUT/DELETE/PATCH 는 POST + 헤더로 바꿉니다.
const apiUrl = (path) => { const [p, q] = path.split("?"); return "api/index.php?p=" + p.split("/").map(encodeURIComponent).join("/") + (q ? "&" + q : ""); };
async function api(path, opt = {}) {
  const m = opt.method || "GET";
  const init = { method: m === "GET" ? "GET" : "POST", headers: {}, credentials: "same-origin" };
  if (m !== "GET") { init.headers["X-MRS"] = "1"; if (m !== "POST") init.headers["X-HTTP-Method-Override"] = m; }
  if (opt.form) init.body = opt.form;
  else if (opt.body !== undefined) { init.body = "b64:" + btoa(unescape(encodeURIComponent(JSON.stringify(opt.body)))); init.headers["Content-Type"] = "text/plain"; }
  const res = await fetch(apiUrl(path), init);
  if (res.status === 401 && !path.startsWith("auth/")) { S.me = null; render(); throw new Error("로그인이 필요합니다."); }
  const ct = res.headers.get("content-type") || "";
  const data = ct.includes("json") ? await res.json() : await res.text();
  if (!ct.includes("json")) throw new Error(`서버 응답 오류 (${res.status}). 호스팅이 요청을 막았거나 서버 설정 문제일 수 있습니다.`);
  if (!res.ok) throw new Error((data && data.detail) ? (typeof data.detail === "string" ? data.detail : JSON.stringify(data.detail)) : res.statusText);
  return data;
}
const can = (p) => S.me && S.me.permissions.includes(p);

function toast(msg, kind = "") {
  const t = document.createElement("div");
  t.className = "toast " + kind;
  t.innerHTML = `<span>${kind === "err" ? "⚠️" : kind === "ok" ? "✅" : "ℹ️"}</span><span>${esc(msg)}</span>`;
  $("#toasts").appendChild(t);
  setTimeout(() => { t.style.transition = "opacity .4s"; t.style.opacity = 0; setTimeout(() => t.remove(), 400); }, 3800);
}
async function busy(btn, fn) {
  const html = btn.innerHTML; btn.disabled = true; btn.innerHTML = `<span class="spin"></span> 처리 중…`;
  try { return await fn(); } catch (e) { toast(e.message, "err"); } finally { btn.disabled = false; btn.innerHTML = html; }
}
function modal(html, cls = "") {
  const bg = document.createElement("div");
  bg.className = "modal-bg";
  bg.innerHTML = `<div class="modal ${cls}">${html}</div>`;
  bg.addEventListener("mousedown", (e) => { if (e.target === bg) bg.remove(); });
  $("#modal-root").appendChild(bg);
  const onKey = (e) => { if (e.key === "Escape" && bg.isConnected) bg.remove(); if (!bg.isConnected) removeEventListener("keydown", onKey); };
  addEventListener("keydown", onKey);
  $$("[data-close]", bg).forEach((b) => b.onclick = () => bg.remove());
  return bg;
}
function confirmBox(msg) {
  return new Promise((ok) => {
    const m = modal(`<h3>확인</h3><p>${esc(msg)}</p><div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="cf-ok">확인</button></div>`);
    $("#cf-ok", m).onclick = () => { m.remove(); ok(true); };
  });
}
function countUp(el) {
  const end = +el.dataset.count; const t0 = performance.now();
  const step = (t) => { const p = Math.min(1, (t - t0) / 900); el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))).toLocaleString(); if (p < 1) requestAnimationFrame(step); };
  requestAnimationFrame(step);
}
function animate(root) {
  $$("[data-count]", root).forEach(countUp);
  requestAnimationFrame(() => $$(".bar > i[data-w]", root).forEach((b) => b.style.width = b.dataset.w + "%"));
  $$(".ring[data-p]", root).forEach((r) => {
    const end = +r.dataset.p; const t0 = performance.now();
    const f = (t) => { const p = Math.min(1, (t - t0) / 1000); const v = end * (1 - Math.pow(1 - p, 3)); r.style.setProperty("--p", v); $("b", r).textContent = Math.round(v); if (p < 1) requestAnimationFrame(f); };
    requestAnimationFrame(f);
  });
}
function confetti() {
  const c = $("#confetti"), x = c.getContext("2d"); c.width = innerWidth; c.height = innerHeight;
  const cols = ["#1969a5", "#2b86c9", "#5fb2ee", "#ffd36e", "#2e9e6a", "#ffffff"];
  const ps = Array.from({ length: 160 }, () => ({ x: innerWidth / 2, y: innerHeight / 3, vx: (Math.random() - .5) * 16, vy: Math.random() * -14 - 4, s: Math.random() * 6 + 4, c: cols[Math.floor(Math.random() * cols.length)], r: Math.random() * 6 }));
  let n = 0;
  const loop = () => {
    x.clearRect(0, 0, c.width, c.height);
    ps.forEach((p) => { p.vy += .45; p.x += p.vx; p.y += p.vy; p.r += .1; x.save(); x.translate(p.x, p.y); x.rotate(p.r); x.fillStyle = p.c; x.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2); x.restore(); });
    if (++n < 160) requestAnimationFrame(loop); else x.clearRect(0, 0, c.width, c.height);
  };
  loop();
}
const chip = (st, label) => `<span class="chip ${esc(st)}"><span class="dot"></span>${esc(label || STATUS[st] || st)}</span>`;
const ring = (score, st, size = 84) => `<div class="ring" data-p="${score}" style="--c:${COLOR[st] || "var(--blue)"};width:${size}px;height:${size}px"><b>0</b></div>`;
const fmtBytes = (n) => n > 1048576 ? (n / 1048576).toFixed(1) + " MB" : (n / 1024).toFixed(0) + " KB";
const gb = (v) => v >= 1024 ? (v / 1024).toFixed(1) + " TB" : (v || 0).toFixed(0) + " GB";

// ───────────────────────── 아이콘 ─────────────────────────
const I = {
  dash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>',
  doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h6"/></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6M17 11a3 3 0 1 0 0-6M22 21c0-3-2-5-5-5.5"/></svg>',
  server: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>',
  tpl: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',
  gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>',
  shield: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 4v5c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/></svg>',
};

// ───────────────────────── 라우팅 ─────────────────────────
const ROUTES = [
  ["dashboard", "대시보드", I.dash, "report.view"],
  ["new", "보고서 생성", I.plus, "report.create"],
  ["reports", "보고서 관리", I.doc, "report.view"],
  ["customers", "고객 · 전산담당자", I.users, "customer.view"],
  ["logs", "장비 · 로그 보관함", I.server, "customer.view"],
  ["templates", "고객 양식", I.tpl, "report.view"],
  ["users", "사용자 · 권한", I.shield, "report.view"],
  ["settings", "시스템 설정", I.gear, "settings.manage"],
];
let deferredInstall = null;
addEventListener("beforeinstallprompt", (e) => { e.preventDefault(); deferredInstall = e; const b = $("#install-btn"); if (b) b.hidden = false; });
addEventListener("hashchange", () => route());

async function boot() {
  try { document.documentElement.dataset.theme = localStorage.getItem("mrs-theme") || "light"; } catch (_) {}
  if ("serviceWorker" in navigator) navigator.serviceWorker.register("sw.js").catch(() => {});
  try { S.me = await api("auth/me"); } catch (_) { S.me = null; }
  render();
}

function render() {
  if (!S.me) return renderLogin();
  $("#app").innerHTML = `
  <div class="shell">
    <aside class="side" id="side">
      <div class="brand"><img src="assets/icons/mrs-192.png" alt=""><div><b>MRS</b><span>Management Report System</span></div></div>
      <nav class="nav">${ROUTES.filter((r) => can(r[3])).map((r) => `<a href="#/${r[0]}" data-r="${r[0]}">${r[2]}<span>${r[1]}</span></a>`).join("")}</nav>
      <div class="foot"><img src="assets/img/datanex_color.png" alt="DATANEX">v1.0 · ${esc(S.me.role_label)}</div>
    </aside>
    <div class="main">
      <header class="top">
        <button class="btn ghost burger" id="burger">☰</button>
        <h2 id="page-title">MRS</h2>
        <div class="right row">
          <button class="btn sm install" id="install-btn" ${deferredInstall ? "" : "hidden"}>⬇ 내 PC에 MRS 설치</button>
          <button class="btn sm ghost" id="theme-btn" title="테마">🌓</button>
          <span class="chip gray">${esc(S.me.full_name || S.me.username)} · ${esc(S.me.role_label)}</span>
          <button class="btn sm" id="pw-btn">비밀번호</button>
          <button class="btn sm" id="logout-btn">로그아웃</button>
        </div>
      </header>
      <section class="content" id="view"></section>
    </div>
  </div>`;
  $("#burger").onclick = () => $("#side").classList.toggle("open");
  $("#logout-btn").onclick = async () => { await api("auth/logout", { method: "POST" }); S.me = null; render(); };
  $("#theme-btn").onclick = () => { const t = document.documentElement.dataset.theme === "dark" ? "light" : "dark"; document.documentElement.dataset.theme = t; try { localStorage.setItem("mrs-theme", t); } catch (_) {} };
  $("#install-btn").onclick = async () => { if (!deferredInstall) return toast("브라우저 메뉴 › '앱 설치'를 이용하세요."); deferredInstall.prompt(); await deferredInstall.userChoice; deferredInstall = null; $("#install-btn").hidden = true; };
  $("#pw-btn").onclick = changePassword;
  route();
  if (S.me.must_change_pw) setTimeout(() => { toast("임시 비밀번호로 로그인했습니다. 비밀번호를 바꿔 주세요."); changePassword(); }, 400);
  if (!localStorage.getItem("mrs-install-hint") && !matchMedia("(display-mode: standalone)").matches) {
    setTimeout(() => { toast("처음 오셨군요! 상단 '내 PC에 MRS 설치'로 바탕화면에 MRS 아이콘을 만들 수 있습니다."); }, 1200);
    try { localStorage.setItem("mrs-install-hint", "1"); } catch (_) {}
  }
}

async function route() {
  if (!S.me) return;
  const [name, arg] = location.hash.replace(/^#\/?/, "").split("/");
  const r = name || "dashboard";
  $$(".nav a").forEach((a) => a.classList.toggle("on", a.dataset.r === r || (r === "report" && a.dataset.r === "reports")));
  $("#side")?.classList.remove("open");
  const view = $("#view");
  view.innerHTML = '<div class="skeleton" style="width:40%"></div><div class="skeleton"></div><div class="skeleton" style="width:70%"></div>';
  view.style.animation = "none"; void view.offsetWidth; view.style.animation = "";
  if (!S.catalog) S.catalog = await api("catalog").catch(() => null);
  const pages = { dashboard: pageDashboard, new: pageNew, reports: pageReports, report: pageReport, customers: pageCustomers, logs: pageLogs, templates: pageTemplates, users: pageUsers, settings: pageSettings };
  const title = { report: "보고서 상세" }[r] || (ROUTES.find((x) => x[0] === r) || [, "MRS"])[1];
  $("#page-title").textContent = title;
  try { await (pages[r] || pageDashboard)(view, arg); animate(view); } catch (e) { view.innerHTML = `<div class="missing">${esc(e.message)}</div>`; }
}

// ───────────────────────── 로그인 ─────────────────────────
function renderLogin() {
  $("#app").innerHTML = `
  <div class="login"><div class="orb a"></div><div class="orb b"></div>
    <form class="login-card" id="login-form">
      <div class="row"><img class="mrs" src="assets/icons/mrs-192.png" alt="MRS"><img class="logo right" src="assets/img/datanex_color.png" alt="DATANEX"></div>
      <h1>Management Report System</h1>
      <p class="muted" style="margin:4px 0 18px">로그만 넣으면, 컨설턴트 수준의 보고서가 완성됩니다.</p>
      <label class="f">아이디</label><input type="text" name="username" autocomplete="username" required autofocus>
      <label class="f">비밀번호</label><input type="password" name="password" autocomplete="current-password" required>
      <button class="btn primary" style="width:100%;margin-top:20px;padding:12px">로그인</button>
    </form></div>`;
  $("#login-form").onsubmit = async (e) => {
    e.preventDefault(); const f = new FormData(e.target);
    try {
      await api("auth/login", { method: "POST", body: { username: String(f.get("username")).trim(), password: f.get("password") } });
      try { S.me = await api("auth/me"); } catch (_) { throw new Error("로그인은 되었지만 로그인 상태가 유지되지 않습니다. 주소를 https://www.datanex.io.kr/mrs/ 로 열었는지 확인하고, 계속되면 관리자에게 알려 주세요."); }
      S.catalog = null; render();
    }
    catch (err) { toast(err.message, "err"); e.target.animate([{ transform: "translateX(0)" }, { transform: "translateX(-8px)" }, { transform: "translateX(8px)" }, { transform: "translateX(0)" }], { duration: 300 }); }
  };
}
function changePassword() {
  const m = modal(`<h3>비밀번호 변경</h3><label class="f">현재 비밀번호</label><input type="password" id="pw-c"><label class="f">새 비밀번호 (8자 이상, 영문+숫자)</label><input type="password" id="pw-n">
    <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="pw-save">변경</button></div>`);
  $("#pw-save", m).onclick = (e) => busy(e.target, async () => { await api("auth/password", { method: "POST", body: { current: $("#pw-c", m).value, new: $("#pw-n", m).value } }); m.remove(); S.me.must_change_pw = false; toast("변경되었습니다.", "ok"); });
}

// ───────────────────────── 대시보드 ─────────────────────────
async function pageDashboard(v) {
  const d = await api("dashboard");
  const hour = new Date().getHours();
  const hi = hour < 12 ? "좋은 아침입니다" : hour < 18 ? "좋은 오후입니다" : "수고 많으셨습니다";
  const st = d.by_status || {};
  const total = d.reports || 1;
  v.innerHTML = `
  <div class="hero"><div class="wave"></div><h1>${hi}, ${esc(S.me.full_name || S.me.username)}님 👋</h1>
    <p>이번 달 ${d.this_month}건의 보고서를 작성했습니다. 로그를 끌어다 놓으면 분석부터 보고서까지 한 번에 완성됩니다.</p>
    <div class="row" style="margin-top:16px">${can("report.create") ? '<a class="btn primary" href="#/new" style="background:#fff;color:var(--navy)">＋ 새 보고서 만들기</a>' : ""}<a class="btn" href="#/reports" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.4)">보고서 관리</a></div></div>
  <div class="grid g4" style="margin-top:18px">
    ${[["고객", d.customers, "👥"], ["관리 장비", d.equipment, "🗄️"], ["보관 로그", d.logs, "📄"], ["전체 보고서", d.reports, "📊"]].map(([l, n, e]) =>
      `<div class="card kpi"><div class="ico"></div><div class="l">${e} ${l}</div><div class="v" data-count="${n}">0</div></div>`).join("")}
  </div>
  <div class="grid g3" style="margin-top:18px">
    <div class="card"><h3>진행 현황</h3>
      ${["draft", "review", "completed", "sent"].map((k) => `<div style="margin:12px 0"><div class="row"><span>${S.catalog.status[k]}</span><b class="right">${st[k] || 0}</b></div>
        <div class="bar"><i data-w="${Math.round((st[k] || 0) / total * 100)}" style="background:${{ draft: "#93a1b3", review: "var(--amber)", completed: "var(--green)", sent: "var(--blue)" }[k]}"></i></div></div>`).join("")}
    </div>
    <div class="card" style="grid-column:span 2"><h3>최근 보고서</h3>${reportTable(d.recent, true)}</div>
  </div>
  ${d.attention.length ? `<div class="card" style="margin-top:18px"><h3>🚨 위험 판정 보고서</h3>${reportTable(d.attention, true)}</div>` : ""}`;
  bindReportRows(v);
}
function reportTable(rows, compact = false) {
  if (!rows.length) return `<div class="empty"><div class="big">🗂️</div>아직 보고서가 없습니다.</div>`;
  return `<table class="t"><thead><tr><th>보고서</th><th>고객</th><th>유형</th>${compact ? "" : "<th>장비</th>"}<th>판정</th><th>상태</th><th>수정일</th>${compact ? "" : "<th></th>"}</tr></thead><tbody>
  ${rows.map((r) => `<tr class="click" data-open="${r.id}"><td><b>${esc(r.title)}</b><div class="small muted mono">${esc(r.report_no)}</div></td><td>${esc(r.customer_name)}</td>
    <td>${esc(r.report_type_label)}${r.work_subtype_label ? `<div class="small muted">${esc(r.work_subtype_label)}</div>` : ""}</td>
    ${compact ? "" : `<td>${r.equipment_count}대 <span class="small muted">(${esc(r.variant_label)})</span></td>`}
    <td>${chip(r.overall_status || "normal")}</td><td>${chip(r.status, r.status_label)}</td><td class="small muted">${esc(r.updated_at)}</td>
    ${compact ? "" : `<td style="white-space:nowrap">${can("report.delete") ? `<button class="btn sm danger" data-del="${r.id}">삭제</button>` : ""}</td>`}</tr>`).join("")}
  </tbody></table>`;
}
function bindReportRows(v, reload) {
  $$("[data-open]", v).forEach((tr) => tr.onclick = (e) => { if (!e.target.closest("button")) location.hash = "#/report/" + tr.dataset.open; });
  $$("[data-del]", v).forEach((b) => b.onclick = async (e) => {
    e.stopPropagation();
    if (!(await confirmBox("이 보고서와 생성된 파일을 삭제할까요?"))) return;
    await api("reports/" + b.dataset.del, { method: "DELETE" }).then(() => { toast("삭제되었습니다.", "ok"); reload && reload(); }).catch((er) => toast(er.message, "err"));
  });
}

// ───────────────────────── 보고서 생성 마법사 ─────────────────────────
async function pageNew(v) {
  const W = { step: 1, customer: null, type: "regular", subtype: "os_upgrade", template_id: null, eq: [], logs: [], pending: [], plan: {}, date: new Date().toISOString().slice(0, 10), codes: ["PM"] };
  S.customers = await api("customers?scope=mine"); W.allCust = false;
  if (!S.customers.length) { S.customers = await api("customers?scope=all"); W.allCust = true; }
  const C = S.catalog;
  const steps = ["고객 선택", "보고서 종류", "장비 · 로그", "세부 정보"];
  const draw = async () => {
    v.innerHTML = `<div class="steps">${steps.map((s, i) => `<div class="s ${i + 1 === W.step ? "on" : i + 1 < W.step ? "done" : ""}"><i>${i + 1 < W.step ? "✓" : i + 1}</i>${s}</div>`).join("")}</div><div id="wz"></div>
      <div class="row" style="margin-top:20px"><button class="btn" id="wz-prev" ${W.step === 1 ? "disabled" : ""}>← 이전</button><span class="right"></span>
      <button class="btn primary" id="wz-next">${W.step === 4 ? "✨ 분석 시작 & 보고서 초안 만들기" : "다음 →"}</button></div>`;
    const z = $("#wz");
    if (W.step === 1) {
      z.innerHTML = `<div class="card"><div class="row"><input type="search" id="cq" placeholder="고객명/주소 검색" style="max-width:360px"><label class="small row" style="gap:6px"><input type="checkbox" id="call" ${W.allCust ? "checked" : ""}> 다른 담당자 고객도 보기</label>
        ${can("customer.edit") ? '<a class="btn right" href="#/customers">＋ 고객 등록/기존 DB 연결</a>' : ""}</div><div class="pick" id="cl" style="margin-top:14px"></div></div>`;
      const list = () => {
        const q = $("#cq").value.trim();
        const rows = S.customers.filter((c) => !q || c.name.includes(q) || (c.address || "").includes(q));
        $("#cl").innerHTML = rows.map((c) => `<div class="opt ${W.customer?.id === c.id ? "on" : ""}" data-c="${c.id}"><div class="em">🏢</div><b>${esc(c.name)}</b>
          <small>${esc(c.address || c.site || "")}</small><div class="row small muted" style="margin-top:8px">${c.my_role ? roleChip(c.my_role) : ""} 장비 ${c.equipment_count} · 보고서 ${c.report_count} ${c.health ? healthCell(c.health) : ""}</div></div>`).join("")
          || '<div class="empty">등록된 고객이 없습니다. 고객 메뉴에서 등록하세요.</div>';
        $$("[data-c]", z).forEach((o) => o.onclick = async () => { W.customer = await api("customers/" + o.dataset.c); W.eq = []; W.logs = []; list(); });
      };
      $("#cq").oninput = list; list();
      $("#call").onchange = async () => { W.allCust = $("#call").checked; S.customers = await api("customers?scope=" + (W.allCust ? "all" : "mine")); list(); };
    } else if (W.step === 2) {
      const types = [["regular", "🗓️", "정기점검 보고서", "정기 예방 점검 결과"], ["special", "🔍", "특별점검 보고서", "장애·이상 징후 집중 점검"], ["workplan", "🛠️", "작업계획서", "OS·컨트롤러·디스크·장애처리 등"], ["custom", "🧩", "고객 양식 보고서", "고객이 요청한 별도 양식"]];
      const tpls = await api("templates?customer_id=" + W.customer.id);
      z.innerHTML = `<div class="card"><h3>보고서 종류</h3><div class="pick">${types.map(([k, e, t, d]) => `<div class="opt ${W.type === k ? "on" : ""}" data-t="${k}"><div class="em">${e}</div><b>${t}</b><small>${d}</small></div>`).join("")}</div>
        <div id="sub" style="margin-top:18px"></div></div>`;
      const sub = () => {
        let h = "";
        if (W.type === "workplan") h += `<h3>작업 유형</h3><div class="pick">${Object.entries(C.work_subtypes).map(([k, l]) => `<div class="opt ${W.subtype === k ? "on" : ""}" data-s="${k}"><b>${esc(l)}</b></div>`).join("")}</div>`;
        h += `<h3 style="margin-top:16px">고객 양식 ${W.type === "custom" ? '<span class="chip critical">필수</span>' : '<span class="chip gray">선택</span>'}</h3>
          ${tpls.length ? `<div class="pick">${tpls.map((t) => `<div class="opt ${W.template_id === t.id ? "on" : ""}" data-tp="${t.id}"><div class="em">${t.file_format === "pptx" ? "📽️" : "📗"}</div><b>${esc(t.name)}</b><small>${esc(t.original_name)}</small></div>`).join("")}</div>`
            : '<div class="muted small">등록된 고객 양식이 없습니다. (고객 양식 메뉴에서 등록)</div>'}
          <p class="small muted">※ 어떤 종류든 표준 PPT와 엑셀 두 가지 버전은 항상 함께 생성됩니다.</p>`;
        $("#sub").innerHTML = h;
        $$("[data-s]", z).forEach((o) => o.onclick = () => { W.subtype = o.dataset.s; W.plan = {}; sub(); });
        $$("[data-tp]", z).forEach((o) => o.onclick = () => { W.template_id = W.template_id === +o.dataset.tp ? null : +o.dataset.tp; sub(); });
      };
      $$("[data-t]", z).forEach((o) => o.onclick = () => { W.type = o.dataset.t; $$("[data-t]", z).forEach((x) => x.classList.toggle("on", x === o)); sub(); });
      sub();
    } else if (W.step === 3) {
      const eqs = W.customer.equipment.filter((e) => e.is_active);
      const logs = await api(`customers/${W.customer.id}/logs`);
      const variantOf = (n) => n <= 1 ? "1대용 상세 보고서" : n <= 3 ? "2~3대용 비교 보고서" : "4대 이상 Fleet 대시보드 보고서";
      z.innerHTML = `<div class="grid g2">
        <div class="card"><h3>점검 장비 선택</h3><div class="row" style="margin-bottom:10px"><span id="vt"></span>
          ${can("equipment.edit") ? '<button class="btn sm right" id="add-eq">＋ 장비 추가</button>' : ""}</div>
          <div class="pick" style="grid-template-columns:1fr">${eqs.map((e) => `<div class="opt ${W.eq.includes(e.id) ? "on" : ""}" data-e="${e.id}"><b>🗄️ ${esc(e.name)} · ${esc(e.model || "모델 미상")}</b><small>S/N ${esc(e.serial || "-")} · OS ${esc(e.os_version || "-")}</small></div>`).join("") || '<div class="empty">장비가 없습니다. 장비를 추가하세요.</div>'}</div></div>
        <div class="card"><h3>로그 넣기</h3>
          <div class="drop" id="drop"><div class="big">📥</div><b>로그 파일을 여기로 끌어다 놓으세요</b><div class="small muted">.txt .log .out .zip .gz · 여러 개 가능 · 장비/시점별로 자동 보관</div><input type="file" id="fi" multiple hidden></div>
          <div class="row" style="margin-top:10px"><label class="small muted">수집 시점</label><input type="datetime-local" id="ct" style="max-width:220px" value="${new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 16)}">
            <select id="fe" style="max-width:200px"><option value="">장비 자동 매칭</option>${eqs.map((e) => `<option value="${e.id}">${esc(e.name)}</option>`).join("")}</select></div>
          <div id="uplist"></div>
          <h3 style="margin-top:18px">보관된 로그에서 선택</h3>
          <div style="max-height:260px;overflow:auto">${logs.map((l) => `<label class="file-row"><input type="checkbox" data-l="${l.id}" ${W.logs.includes(l.id) ? "checked" : ""}>
            <div class="grow"><b>${esc(l.original_name)}</b><div class="small muted">${esc(l.equipment_name || "장비 미지정")} · 수집 ${esc(l.collected_at)} · ${fmtBytes(l.size_bytes)}</div></div>${chip(l.summary.status)}</label>`).join("") || '<div class="muted small">보관된 로그가 없습니다.</div>'}</div>
        </div></div>`;
      const vt = () => { $("#vt").innerHTML = W.eq.length ? `<span class="variant">📐 ${W.eq.length}대 선택 → ${variantOf(W.eq.length)}</span>` : '<span class="muted small">장비 수에 따라 1대 / 2~3대 / 4대+ 양식이 자동 선택됩니다.</span>'; };
      vt();
      $$("[data-e]", z).forEach((o) => o.onclick = () => { const id = +o.dataset.e; W.eq = W.eq.includes(id) ? W.eq.filter((x) => x !== id) : [...W.eq, id]; o.classList.toggle("on"); vt(); });
      $$("[data-l]", z).forEach((c) => c.onchange = () => { const id = +c.dataset.l; W.logs = c.checked ? [...new Set([...W.logs, id])] : W.logs.filter((x) => x !== id); });
      $("#add-eq") && ($("#add-eq").onclick = () => equipmentForm(W.customer.id, null, async () => { W.customer = await api("customers/" + W.customer.id); draw(); }));
      setupDrop($("#drop"), $("#fi"), async (files) => {
        const fd = new FormData(); files.forEach((f) => fd.append("files", f));
        if ($("#fe").value) fd.append("equipment_id", $("#fe").value);
        fd.append("collected_at", $("#ct").value);
        $("#uplist").innerHTML = files.map((f) => `<div class="file-row"><span class="spin" style="width:14px;height:14px;border:2px solid var(--blue);border-right-color:transparent;border-radius:50%;animation:rot .7s linear infinite"></span><b class="grow">${esc(f.name)}</b><span class="small muted">분석 중…</span></div>`).join("");
        try {
          const res = await api(`customers/${W.customer.id}/logs`, { method: "POST", form: fd });
          res.forEach((l) => { W.logs.push(l.id); if (l.equipment_id && !W.eq.includes(l.equipment_id)) W.eq.push(l.equipment_id); });
          toast(`${res.length}개 로그를 분석·보관했습니다.`, "ok"); draw();
        } catch (e) { toast(e.message, "err"); $("#uplist").innerHTML = ""; }
      });
    } else if (W.step === 4) {
      if (W.type === "workplan" && !W.plan.steps) W.plan = { steps: JSON.parse(JSON.stringify(C.default_steps[W.subtype] || C.default_steps.etc)), rollback: C.default_rollback[W.subtype] || "", schedule: "", purpose: "", target: "", workers: [{ company: "", name: S.me.full_name, role: "작업 책임", phone: S.me.phone, email: S.me.email }] };
      z.innerHTML = `<div class="card"><h3>기본 정보</h3><div class="grid g3">
        <div><label class="f">${W.type === "workplan" ? "작성일" : "점검일"}</label><input type="date" id="dt" value="${W.date}"></div>
        <div style="grid-column:span 2"><label class="f">보고서 제목 (비우면 자동)</label><input type="text" id="ti" placeholder="${esc(W.customer.name)} ${W.type === "workplan" ? esc(C.work_subtypes[W.subtype]) + " 작업계획서" : esc(C.report_types[W.type])}"></div></div>
        ${W.type !== "workplan" ? `<label class="f">Service Code</label><div class="row">${C.service_codes.map((s) => `<label class="chip ${W.codes.includes(s) ? "" : "gray"}" style="cursor:pointer"><input type="checkbox" data-sc="${s}" ${W.codes.includes(s) ? "checked" : ""} hidden>${s}</label>`).join("")}</div>` : ""}
        </div>${W.type === "workplan" ? `<div class="card" style="margin-top:16px" id="planbox"></div>` : ""}`;
      $$("[data-sc]", z).forEach((c) => c.parentElement.onclick = (e) => { e.preventDefault(); c.checked = !c.checked; W.codes = c.checked ? [...W.codes, c.dataset.sc] : W.codes.filter((x) => x !== c.dataset.sc); c.parentElement.classList.toggle("gray", !c.checked); });
      if (W.type === "workplan") planEditor($("#planbox"), W.plan);
    }
    $("#wz-prev").onclick = () => { W.step--; draw(); };
    $("#wz-next").onclick = async (e) => {
      if (W.step === 1 && !W.customer) return toast("고객을 선택하세요.", "err");
      if (W.step === 2 && W.type === "custom" && !W.template_id) return toast("고객 양식을 선택하세요.", "err");
      if (W.step === 3 && !W.eq.length) return toast("장비를 1대 이상 선택하세요.", "err");
      if (W.step === 4) {
        W.date = $("#dt").value;
        const body = { customer_id: W.customer.id, report_type: W.type, work_subtype: W.type === "workplan" ? W.subtype : "", template_id: W.template_id, title: $("#ti").value.trim(),
          inspection_date: W.date, equipment_ids: W.eq, log_ids: W.logs, service_codes: W.codes, plan: W.type === "workplan" ? W.plan : {} };
        return busy(e.target, async () => { const r = await api("reports", { method: "POST", body }); toast("로그 분석 완료! 이제 AI 진단 조언을 받아 보세요.", "ok"); location.hash = "#/report/" + r.id; });
      }
      W.step++; draw();
    };
  };
  draw();
}

function setupDrop(drop, input, onFiles) {
  drop.onclick = () => input.click();
  input.onchange = () => input.files.length && onFiles([...input.files]);
  ["dragenter", "dragover"].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add("over"); }));
  ["dragleave", "drop"].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove("over"); }));
  drop.addEventListener("drop", (e) => e.dataTransfer.files.length && onFiles([...e.dataTransfer.files]));
}

// 단계 번호: 단계 이름이 바뀌는 곳마다 1, 2, 3… 같은 단계 안에서는 1-1, 1-2 … (순서를 옮기면 바로 다시 매김)
const phaseName = (p) => String(p || "").replace(/^\s*\d+(\s*[.\-)]\s*\d+)*\s*[.)]?\s*/, "").trim();
function stepNumbers(steps) { let pi = 0, si = 0, last = null; return steps.map((s) => { const n = phaseName(s.phase); if (last === null || n !== last) { pi++; si = 0; last = n; } si++; return { no: `${pi}-${si}`, pn: pi, first: si === 1 }; }); }
function planEditor(box, plan) {
  const C = S.catalog;
  plan.steps = (plan.steps || []).map((s) => ({ ...s, phase: phaseName(s.phase) }));
  const draw = () => {
    const nums = stepNumbers(plan.steps);
    const total = plan.steps.reduce((a, s) => a + (+s.minutes || 0), 0);
    box.innerHTML = `<h3>작업 계획</h3><div class="grid g2">
      <div><label class="f">작업 일정</label><input type="text" data-p="schedule" value="${esc(plan.schedule || "")}" placeholder="예) 2026-12-05(토) 22:00 ~ 02:00"></div>
      <div><label class="f">작업 장소</label><input type="text" data-p="location" value="${esc(plan.location || "")}"></div>
      <div><label class="f">작업 목적 / 원인</label><input type="text" data-p="purpose" value="${esc(plan.purpose || "")}" placeholder="예) EOS 대응, 디스크 Fail 교체"></div>
      <div><label class="f">목표 버전 / 교체 부품</label><input type="text" data-p="target" value="${esc(plan.target || "")}" placeholder="예) ONTAP 9.14.1P5"></div></div>
      <label class="f">상세 작업 절차 <span class="chip">총 ${total}분 · ${Math.floor(total / 60)}시간 ${total % 60}분</span></label>
      <div style="overflow:auto"><table class="t steps-table"><thead><tr><th style="width:52px">No</th><th>단계</th><th>작업 항목</th><th style="width:40%">작업 상세</th><th style="width:80px">분</th><th style="width:130px">서비스 영향</th><th></th></tr></thead><tbody>
      ${plan.steps.map((s, i) => `<tr style="${nums[i].first && i ? "border-top:2px solid var(--line)" : ""}"><td class="mono"><b>${nums[i].no}</b></td><td><div class="row" style="gap:4px;flex-wrap:nowrap"><span class="small muted">${nums[i].pn}.</span><input type="text" data-s="${i}" data-k="phase" value="${esc(s.phase)}" placeholder="단계 이름"></div></td><td><input type="text" data-s="${i}" data-k="activity" value="${esc(s.activity)}"></td>
        <td><input type="text" data-s="${i}" data-k="detail" value="${esc(s.detail)}"></td><td><input type="number" data-s="${i}" data-k="minutes" value="${esc(s.minutes)}"></td>
        <td><input type="text" data-s="${i}" data-k="impact" value="${esc(s.impact)}"></td>
        <td style="white-space:nowrap"><button class="btn sm ghost" data-up="${i}" title="위로" ${i ? "" : "disabled"}>↑</button><button class="btn sm ghost" data-dn="${i}" title="아래로" ${i < plan.steps.length - 1 ? "" : "disabled"}>↓</button><button class="btn sm ghost" data-ins="${i}" title="아래에 단계 추가">＋</button><button class="btn sm ghost danger" data-rm="${i}">✕</button></td></tr>`).join("")}
      </tbody></table></div><button class="btn sm" id="add-step" style="margin-top:8px">＋ 단계 추가</button>
      <label class="f">롤백 계획</label><textarea data-p="rollback">${esc(plan.rollback || "")}</textarea>
      <label class="f">작업자</label>${(plan.workers || []).map((w, i) => `<div class="grid g4" style="margin-bottom:6px">${["company", "name", "role", "phone"].map((k) => `<input type="text" data-w="${i}" data-k="${k}" value="${esc(w[k] || "")}" placeholder="${{ company: "회사", name: "이름", role: "역할", phone: "연락처" }[k]}">`).join("")}</div>`).join("")}
      <button class="btn sm" id="add-w">＋ 작업자 추가</button>`;
    $$("[data-p]", box).forEach((i) => i.oninput = () => plan[i.dataset.p] = i.value);
    $$("[data-s][data-k=phase]", box).forEach((i) => i.onchange = () => draw());
    $$("[data-s]", box).forEach((i) => i.oninput = () => { plan.steps[+i.dataset.s][i.dataset.k] = i.dataset.k === "minutes" ? +i.value : i.value; if (i.dataset.k === "minutes") { const t = plan.steps.reduce((a, s) => a + (+s.minutes || 0), 0); $(".chip", box).textContent = `총 ${t}분 · ${Math.floor(t / 60)}시간 ${t % 60}분`; } });
    $$("[data-w]", box).forEach((i) => i.oninput = () => plan.workers[+i.dataset.w][i.dataset.k] = i.value);
    $$("[data-rm]", box).forEach((b) => b.onclick = () => { plan.steps.splice(+b.dataset.rm, 1); draw(); });
    $$("[data-up]", box).forEach((b) => b.onclick = () => { const i = +b.dataset.up; if (i > 0) { [plan.steps[i - 1], plan.steps[i]] = [plan.steps[i], plan.steps[i - 1]]; draw(); } });
    $$("[data-dn]", box).forEach((b) => b.onclick = () => { const i = +b.dataset.dn; if (i < plan.steps.length - 1) { [plan.steps[i + 1], plan.steps[i]] = [plan.steps[i], plan.steps[i + 1]]; draw(); } });
    $$("[data-ins]", box).forEach((b) => b.onclick = () => { const i = +b.dataset.ins; plan.steps.splice(i + 1, 0, { phase: plan.steps[i].phase, activity: "", detail: "", minutes: 10, impact: "없음" }); draw(); });
    $("#add-step", box).onclick = () => { plan.steps.push({ phase: plan.steps.length ? plan.steps[plan.steps.length - 1].phase : "", activity: "", detail: "", minutes: 10, impact: "없음" }); draw(); };
    $("#add-w", box).onclick = () => { (plan.workers = plan.workers || []).push({}); draw(); };
  };
  draw();
}

// ───────────────────────── 보고서 상세(편집 워크플로) ─────────────────────────
async function pageReport(v, id) {
  let R = await api("reports/" + id);
  const C = S.catalog;
  const vis = JSON.parse(JSON.stringify(R.visual_inspection || {}));
  const draw = () => {
    const stage = R.status === "sent" ? 6 : R.status === "completed" ? 5 : R.status === "review" ? 4 : R.diagnosis_reviewed ? 3 : R.ai_advice ? 2 : 1;
    const flow = ["로그 분석", "AI 진단 조언", "엔지니어 검토", "보고서 생성", "육안점검·최종의견", "메일 발송"];
    const editable = can("report.edit");
    v.innerHTML = `
    <div class="card"><div class="row"><div class="grow"><div class="small muted mono">${esc(R.report_no)}</div><h2 style="font-size:20px">${esc(R.title)}</h2>
      <div class="row small" style="margin-top:6px">${chip(R.status, R.status_label)} ${chip(R.overall_status || "normal", R.overall_label)} <span class="chip">${esc(R.report_type_label)}${R.work_subtype_label ? " · " + esc(R.work_subtype_label) : ""}</span>
      <span class="chip">📐 ${R.equipment_count}대 · ${esc(R.variant_label)}</span><span class="muted">${esc(R.customer_name)} · ${esc(R.inspection_date)}</span></div></div>
      ${editable ? '<button class="btn" id="edit-meta">✏️ 정보 수정</button>' : ""}${can("report.delete") ? '<button class="btn danger" id="del-r">삭제</button>' : ""}</div>
      <div class="flow" style="margin-top:18px">${flow.map((f, i) => `<div class="n ${i < stage ? "done" : i === stage ? "cur" : ""}"><i>${i < stage ? "✓" : i + 1}</i>${f}</div>`).join("")}</div></div>

    <h3 style="margin:22px 0 10px">① 로그 분석 결과</h3>
    <div class="grid ${R.equipments.length === 1 ? "" : R.equipments.length <= 3 ? "g3" : "g4"}">${R.equipments.map((e) => eqCard(e, R.equipments.length === 1)).join("")}</div>

    <div class="grid g2" style="margin-top:22px">
      <div class="card"><h3>② Claude AI 진단 조언 <span class="chip critical">필수</span></h3>
        ${R.ai_advice ? `<div class="ai-badge"><span class="sparkle">✦</span> ${esc(R.ai_model)} · ${esc(R.ai_generated_at)}</div><div class="ai-box" style="margin-top:8px">${esc(R.ai_advice)}</div>`
          : `<div class="empty"><div class="big">✨</div>로그 분석 결과를 바탕으로 전문 컨설턴트 관점의 장애·진단 조언을 받습니다.</div>`}
        ${editable ? `<label class="f">추가로 AI에게 알려줄 현장 메모 (선택)</label><textarea id="ai-notes" style="min-height:60px" placeholder="예) 고객이 최근 성능 저하를 호소, 증설 예산 검토 중"></textarea>
        <button class="btn primary" id="ai-btn" style="margin-top:10px">✨ ${R.ai_advice ? "AI 조언 다시 받기" : "AI 진단 조언 받기"}</button>
        ${C.ai_enabled ? "" : '<div class="small muted" style="margin-top:6px">※ ANTHROPIC_API_KEY 미설정 – 규칙 기반 초안이 생성됩니다.</div>'}` : ""}
      </div>
      <div class="card"><h3>③ 엔지니어 검토 · 진단 의견 확정 <span class="chip critical">필수</span></h3>
        <textarea id="diag" style="min-height:300px" ${editable ? "" : "readonly"} placeholder="AI 조언을 받으면 여기에 초안이 채워집니다. 검토 후 수정하세요.">${esc(R.diagnosis)}</textarea>
        ${editable ? `<label class="row" style="margin-top:10px"><input type="checkbox" id="diag-ok" ${R.diagnosis_reviewed ? "checked" : ""}> <b>AI 조언을 검토했으며, 위 내용으로 진단 의견을 확정합니다.</b></label>
        <div class="row" style="margin-top:10px"><button class="btn" id="diag-save">저장</button><button class="btn primary right" id="gen-btn" ${R.ai_advice ? "" : "disabled"}>📄 ④ 보고서 생성 (PPT + 엑셀)</button></div>` : ""}
      </div>
    </div>

    ${R.report_type === "workplan" && editable ? '<div class="card" style="margin-top:22px" id="planbox"></div><div class="row" style="margin-top:8px"><button class="btn right" id="plan-save">작업 계획 저장</button></div>' : ""}

    <div class="card" style="margin-top:22px"><h3>⑤ 육안 점검 최종 선택 · 엔지니어 최종 Comment <span class="chip critical">필수</span></h3>
      ${R.status === "draft" ? '<div class="muted">보고서(검토본)를 먼저 생성하면 최종 확정 단계가 열립니다.</div>' : `
      <div style="overflow:auto"><table class="vis"><thead><tr><th>점검 항목</th>${R.equipments.map((e) => `<th>#${e.seq} ${esc(e.name)}${editable ? `<div><button class="btn sm ghost" data-allok="${e.id}">전체 정상</button></div>` : ""}</th>`).join("")}</tr></thead>
      <tbody>${C.visual_items.map(([k, l]) => `<tr><td>${esc(l)}</td>${R.equipments.map((e) => `<td><div class="seg">${C.visual_choices.map((c) => `<button data-eq="${e.id}" data-k="${k}" data-v="${c}" class="${(vis[e.id] || {})[k] === c ? "on" : ""}" ${editable ? "" : "disabled"}>${c}</button>`).join("")}</div></td>`).join("")}</tr>`).join("")}</tbody></table></div>
      <label class="f">엔지니어 최종 Comment <span class="req">*</span></label><textarea id="final" placeholder="점검/작업 결과를 정리하는 최종 의견을 입력하세요.">${esc(R.final_comment)}</textarea>
      ${R.missing.length ? `<div class="missing" style="margin-top:10px">완료 전 필요한 항목: ${R.missing.map(esc).join(" · ")}</div>` : '<div class="ok-box" style="margin-top:10px">모든 필수 항목이 입력되었습니다.</div>'}
      ${editable ? '<div class="row" style="margin-top:12px"><button class="btn primary right" id="fin-btn">🏁 최종 확정 · 최종본 생성</button></div>' : ""}`}
    </div>

    <div class="grid g2" style="margin-top:22px">
      <div class="card"><h3>생성된 문서</h3>${R.files.length ? `<div class="grid">${R.files.map((f) => `<a class="file-card" href="${apiUrl(`reports/${R.id}/files/${f.id}`)}"><div class="file-ico ${f.format}">${f.format.toUpperCase()}</div>
        <div class="grow"><b>${esc(f.filename)}</b><div class="small muted">${f.kind === "custom" ? "고객 양식 · " : ""}${fmtBytes(f.size)} · ${esc(f.created_at)}</div></div><span>⬇</span></a>`).join("")}</div>` : '<div class="empty"><div class="big">📄</div>아직 생성된 문서가 없습니다.</div>'}</div>
      <div class="card"><h3>⑥ 고객 담당자 메일 발송</h3>
        ${["completed", "sent"].includes(R.status) && can("report.send") ? `<button class="btn primary" id="send-btn">✉️ 메일로 보내기</button>` : '<div class="muted">최종 확정된 보고서만 발송할 수 있습니다.</div>'}
        ${R.mails.length ? `<div class="timeline" style="margin-top:14px">${R.mails.map((m) => `<div class="tl"><b>${m.status === "sent" ? "발송 완료" : "발송 실패"}</b> <span class="small muted">${esc(m.at)}</span><div class="small">${esc(m.to.join(", "))}</div>${m.error ? `<div class="small" style="color:var(--red)">${esc(m.error)}</div>` : ""}</div>`).join("")}</div>` : ""}
      </div>
    </div>`;
    bind();
  };
  const reload = async (msg) => { R = await api("reports/" + id); Object.assign(vis, R.visual_inspection || {}); draw(); animate(v); if (msg) toast(msg, "ok"); };
  const bind = () => {
    $("#del-r") && ($("#del-r").onclick = async () => { if (await confirmBox("보고서를 삭제할까요?")) { await api("reports/" + id, { method: "DELETE" }); toast("삭제되었습니다.", "ok"); location.hash = "#/reports"; } });
    $("#edit-meta") && ($("#edit-meta").onclick = () => editReportMeta(R, reload));
    $("#ai-btn") && ($("#ai-btn").onclick = (e) => busy(e.target, async () => {
      const r = await api(`reports/${id}/ai-advice`, { method: "POST", body: { notes: $("#ai-notes").value, replace_diagnosis: true } });
      if (r.ai_note) toast(r.ai_note); await reload("AI 조언이 도착했습니다. 검토 후 확정해 주세요.");
    }));
    const saveDiag = () => api(`reports/${id}/diagnosis`, { method: "PUT", body: { diagnosis: $("#diag").value, reviewed: $("#diag-ok").checked } });
    $("#diag-save") && ($("#diag-save").onclick = (e) => busy(e.target, async () => { await saveDiag(); await reload("저장되었습니다."); }));
    $("#gen-btn") && ($("#gen-btn").onclick = (e) => busy(e.target, async () => {
      if (!$("#diag-ok").checked) throw new Error("진단 의견 검토 확인란을 체크하세요.");
      await saveDiag(); await api(`reports/${id}/generate`, { method: "POST" }); await reload("검토본 PPT·엑셀이 생성되었습니다. 육안점검과 최종 Comment로 마무리하세요.");
    }));
    if ($("#planbox")) { planEditor($("#planbox"), R.plan); $("#plan-save").onclick = (e) => busy(e.target, async () => { await api(`reports/${id}/plan`, { method: "PUT", body: { plan: R.plan } }); toast("저장되었습니다. 보고서를 다시 생성하면 반영됩니다.", "ok"); }); }
    $$(".seg button[data-eq]", v).forEach((b) => b.onclick = () => {
      (vis[b.dataset.eq] = vis[b.dataset.eq] || {})[b.dataset.k] = b.dataset.v;
      $$(`.seg button[data-eq="${b.dataset.eq}"][data-k="${b.dataset.k}"]`, v).forEach((x) => x.classList.toggle("on", x === b));
    });
    $$("[data-allok]", v).forEach((b) => b.onclick = () => { const e = b.dataset.allok; vis[e] = vis[e] || {}; C.visual_items.forEach(([k]) => { if (!vis[e][k]) vis[e][k] = "정상"; });
      $$(`.seg button[data-eq="${e}"]`, v).forEach((x) => x.classList.toggle("on", vis[e][x.dataset.k] === x.dataset.v)); });
    $("#fin-btn") && ($("#fin-btn").onclick = (e) => busy(e.target, async () => {
      await api(`reports/${id}/finalize`, { method: "PUT", body: { visual_inspection: vis, final_comment: $("#final").value } });
      await reload("최종본이 완성되었습니다! 🎉"); confetti();
    }));
    $("#send-btn") && ($("#send-btn").onclick = () => sendMail(R, reload));
  };
  draw();
}

function eqCard(e, wide) {
  const a = e.analysis || { score: 0, status: "normal", findings: [], capacity: {} };
  const cap = a.capacity || {};
  return `<div class="card"><div class="row">${ring(a.score, a.status)}<div class="grow"><div class="small muted">System ${e.seq}</div><b style="font-size:15px">${esc(e.name)} · ${esc(e.model || "-")}</b>
    <div class="small muted">S/N ${esc(e.serial || "-")} · OS ${esc(e.os_version || "-")}</div><div style="margin-top:4px">${chip(a.status, a.status_label)}</div></div></div>
    ${cap.total_gb ? `<div style="margin-top:12px"><div class="row small"><span>Data 용량</span><b class="right">${cap.pct}% · ${gb(cap.used_gb)} / ${gb(cap.total_gb)}</b></div><div class="bar"><i data-w="${cap.pct}" style="background:${cap.pct >= 95 ? "var(--red)" : cap.pct >= 85 ? "var(--amber)" : "var(--blue)"}"></i></div></div>` : '<div class="small muted" style="margin-top:12px">용량 데이터 없음 (로그 미연결)</div>'}
    ${wide && e.aggregates.length ? `<div class="grid g2" style="margin-top:12px">${e.aggregates.slice(0, 8).map((g) => `<div><div class="row small"><span class="mono">${esc(g.name)}</span><span class="right">${g.pct}%</span></div><div class="bar"><i data-w="${g.pct}" style="background:${g.pct >= 95 ? "var(--red)" : g.pct >= 85 ? "var(--amber)" : "var(--blue)"}"></i></div></div>`).join("")}</div>` : ""}
    <div style="margin-top:12px">${a.findings.length ? a.findings.slice(0, wide ? 10 : 4).map((f) => `<div class="finding"><span class="sev ${f.severity}"></span><div><b class="small">${esc(f.title)}</b><div class="small muted">${esc(f.recommendation)}</div></div></div>`).join("")
      + (a.findings.length > (wide ? 10 : 4) ? `<div class="small muted">외 ${a.findings.length - (wide ? 10 : 4)}건</div>` : "") : '<div class="ok-box">주요 이상 징후가 없습니다.</div>'}</div></div>`;
}

async function editReportMeta(R, reload) {
  const cust = await api("customers/" + R.customer_id);
  const logs = await api(`customers/${R.customer_id}/logs`);
  const tpls = await api("templates?customer_id=" + R.customer_id);
  const C = S.catalog;
  const m = modal(`<h3>보고서 정보 수정</h3>
    <div class="grid g2"><div><label class="f">제목</label><input type="text" id="m-ti" value="${esc(R.title)}"></div><div><label class="f">점검/작성일</label><input type="date" id="m-dt" value="${esc(R.inspection_date)}"></div>
    <div><label class="f">보고서 종류</label><select id="m-ty">${Object.entries(C.report_types).map(([k, l]) => `<option value="${k}" ${k === R.report_type ? "selected" : ""}>${l}</option>`).join("")}</select></div>
    <div><label class="f">작업 유형</label><select id="m-st"><option value="">-</option>${Object.entries(C.work_subtypes).map(([k, l]) => `<option value="${k}" ${k === R.work_subtype ? "selected" : ""}>${l}</option>`).join("")}</select></div>
    <div><label class="f">담당 엔지니어 <span class="small muted">(보고서 연락처)</span></label><select id="m-en">${(C.engineers || []).map((u) => `<option value="${u.id}" ${u.id === R.engineer_id ? "selected" : ""}>${esc(u.name)}</option>`).join("")}</select></div>
    <div><label class="f">고객 양식</label><select id="m-tp"><option value="">(없음)</option>${tpls.map((t) => `<option value="${t.id}" ${t.id === R.template_id ? "selected" : ""}>${esc(t.name)}</option>`).join("")}</select></div></div>
    <label class="f">장비</label><div class="row">${cust.equipment.map((e) => `<label class="chip gray"><input type="checkbox" class="m-eq" value="${e.id}" ${R.equipment_ids.includes(e.id) ? "checked" : ""}> ${esc(e.name)}</label>`).join("")}</div>
    <label class="f">로그</label><div style="max-height:200px;overflow:auto">${logs.map((l) => `<label class="file-row"><input type="checkbox" class="m-lg" value="${l.id}" ${R.log_ids.includes(l.id) ? "checked" : ""}><span class="grow">${esc(l.original_name)} <span class="small muted">${esc(l.equipment_name)} · ${esc(l.collected_at)}</span></span></label>`).join("")}</div>
    <p class="small muted">장비/로그를 바꾸면 분석이 다시 실행되고, 완료된 보고서는 '검토 중'으로 돌아가 최종 확정을 다시 받습니다.</p>
    <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="m-save">저장 · 재분석</button></div>`, "wide");
  $("#m-save", m).onclick = (e) => busy(e.target, async () => {
    await api("reports/" + R.id, { method: "PUT", body: { customer_id: R.customer_id, report_type: $("#m-ty", m).value, work_subtype: $("#m-st", m).value, template_id: +$("#m-tp", m).value || null,
      title: $("#m-ti", m).value, inspection_date: $("#m-dt", m).value, equipment_ids: $$(".m-eq:checked", m).map((x) => +x.value), log_ids: $$(".m-lg:checked", m).map((x) => +x.value),
      service_codes: R.service_codes, plan: R.plan, engineer_id: +$("#m-en", m).value || null } });
    m.remove(); await reload("수정되었습니다.");
  });
}

function sendMail(R, reload) {
  const m = modal(`<h3>✉️ 보고서 메일 발송</h3>
    <label class="f">받는 사람 (고객 담당자)</label><div class="row">${R.recipients.map((r) => `<label class="chip ${r.default ? "" : "gray"}"><input type="checkbox" class="rc" value="${esc(r.email)}" ${r.default ? "checked" : ""}> ${esc(r.name)} &lt;${esc(r.email)}&gt;</label>`).join("") || '<span class="muted small">등록된 담당자 이메일이 없습니다.</span>'}</div>
    <label class="f">추가 받는 사람 (쉼표로 구분)</label><input type="text" id="to-x" placeholder="name@example.com">
    <label class="f">참조(CC)</label><input type="text" id="cc" value="${esc(S.me.email || "")}">
    <label class="f">제목</label><input type="text" id="sj" value="[${esc(S.catalog?.company_name || "DATANEX")}] ${esc(R.title)} (${esc(R.inspection_date)})">
    <label class="f">본문</label><textarea id="bd">안녕하세요, ${esc(R.customer_name)} 담당자님.\n\n${esc(R.title)}를 첨부와 같이 송부드립니다.\n검토 부탁드립니다.\n\n감사합니다.\n${esc(S.me.full_name || "")} ${esc(S.me.title || "")} 드림</textarea>
    <label class="f">첨부</label>${R.files.map((f) => `<label class="file-row"><input type="checkbox" class="af" value="${f.id}" checked><div class="file-ico ${f.format}" style="width:30px;height:30px;font-size:9px">${f.format.toUpperCase()}</div><span class="grow">${esc(f.filename)}</span></label>`).join("")}
    <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="go">보내기</button></div>`, "wide");
  $("#go", m).onclick = (e) => busy(e.target, async () => {
    const to = [...$$(".rc:checked", m).map((x) => x.value), ...$("#to-x", m).value.split(",").map((s) => s.trim()).filter(Boolean)];
    await api(`reports/${R.id}/send`, { method: "POST", body: { to, cc: $("#cc", m).value.split(",").map((s) => s.trim()).filter(Boolean), subject: $("#sj", m).value, message: $("#bd", m).value, file_ids: $$(".af:checked", m).map((x) => +x.value) } });
    m.remove(); await reload("메일이 발송되었습니다."); confetti();
  });
}

// ───────────────────────── 보고서 관리 ─────────────────────────
async function pageReports(v) {
  const C = S.catalog;
  v.innerHTML = `<div class="card"><div class="row"><input type="search" id="q" placeholder="제목·번호·고객 검색" style="max-width:280px">
    <select id="fs" style="max-width:150px"><option value="">전체 상태</option>${Object.entries(C.status).map(([k, l]) => `<option value="${k}">${l}</option>`).join("")}</select>
    <select id="ft" style="max-width:170px"><option value="">전체 종류</option>${Object.entries(C.report_types).map(([k, l]) => `<option value="${k}">${l}</option>`).join("")}</select>
    ${can("report.create") ? '<a class="btn primary right" href="#/new">＋ 새 보고서</a>' : ""}</div><div id="rl" style="margin-top:14px"></div></div>`;
  const load = async () => {
    const qs = new URLSearchParams({ q: $("#q").value, status: $("#fs").value, report_type: $("#ft").value });
    const rows = await api("reports?" + qs); $("#rl").innerHTML = reportTable(rows); bindReportRows($("#rl"), load);
  };
  let t; $("#q").oninput = () => { clearTimeout(t); t = setTimeout(load, 250); }; $("#fs").onchange = load; $("#ft").onchange = load;
  await load();
}

// ───────────────────────── 고객 · 전산담당자 · 장비 ─────────────────────────
// 고객 현황표: 평소에는 내가 정/부로 맡은 고객만, 필요하면 전체·미배정·다른 담당자 고객을 봅니다.
const roleChip = (r) => r === "main" ? '<span class="chip sent">정</span>' : r === "sub" ? '<span class="chip gray">부</span>' : "";
const healthCell = (h) => {
  if (!h) return '<span class="small muted">로그 없음</span>';
  const parts = [];
  if (h.crit) parts.push(`<span class="chip critical">위험 ${h.crit}</span>`);
  if (h.warn) parts.push(`<span class="chip warning">주의 ${h.warn}</span>`);
  if (!parts.length) parts.push('<span class="chip normal">정상</span>');
  return parts.join(" ");
};
async function pageCustomers(v, id) {
  if (id) return customerDetail(v, +id);
  const users = can("customer.edit") ? await api("users").catch(() => []) : [];
  S.custScope = S.custScope || "mine";
  v.innerHTML = `<div class="card"><div class="row" style="flex-wrap:wrap;gap:8px">
      <div class="tabs" style="margin:0;border:0" id="sc">${[["mine", "내 고객 (정·부)"], ["all", "전체"], ["unassigned", "정 담당 미지정"]].map(([k, l]) => `<button data-sc="${k}" class="${S.custScope === k ? "on" : ""}">${l}</button>`).join("")}</div>
      ${users.length ? `<select id="su" style="max-width:180px"><option value="">담당자별 보기…</option>${users.filter((u) => u.is_active).map((u) => `<option value="user:${u.id}" ${S.custScope === "user:" + u.id ? "selected" : ""}>${esc(u.full_name || u.username)} (정 ${u.assigned.main} · 부 ${u.assigned.sub})</option>`).join("")}</select>` : ""}
      <input type="search" id="cq" placeholder="고객명·주소 검색" style="max-width:240px">
      <span class="right"></span>${can("customer.edit") ? '<button class="btn sm" id="legacy">🔗 PMS 고객 불러오기</button><button class="btn sm primary" id="new-c">＋ 신규 고객</button>' : ""}</div>
    <div id="sum" class="row small muted" style="margin-top:10px;gap:14px"></div>
    <div style="overflow:auto;margin-top:8px"><table class="t cust-t"><thead><tr><th>고객</th><th>담당 (정 / 부)</th><th style="text-align:center">운영 장비</th><th>현재 상태 (최신 로그)</th><th>최근 로그</th><th>최근 보고서</th><th style="text-align:center">내 담당</th></tr></thead><tbody id="cl"></tbody></table></div></div>`;
  let rows = [];
  const load = async () => { $("#cl").innerHTML = '<tr><td colspan="7" class="muted">불러오는 중…</td></tr>'; rows = await api("customers?scope=" + encodeURIComponent(S.custScope)); draw(); };
  const draw = () => {
    const q = $("#cq").value.trim();
    const list = rows.filter((c) => !q || c.name.includes(q) || (c.address || "").includes(q) || (c.site || "").includes(q));
    const eqs = list.reduce((a, c) => a + c.equipment_count, 0), bad = list.filter((c) => c.health && (c.health.crit || c.health.warn)).length;
    $("#sum").innerHTML = `<span>고객 <b>${list.length}</b>곳</span><span>운영 장비 <b>${eqs}</b>대</span><span>문제 있는 고객 <b style="color:var(--red)">${bad}</b>곳</span>`;
    $("#cl").innerHTML = list.map((c) => `<tr data-c="${c.id}" style="cursor:pointer">
      <td><b>${esc(c.name)}</b> ${c.linked ? '<span class="chip gray" style="font-size:10px">PMS</span>' : ""}<div class="small muted">${esc(c.site || c.address || "")}</div></td>
      <td class="small">${c.main ? `<b>${esc(c.main.name)}</b>` : '<span class="muted">미지정</span>'}${c.subs.length ? `<div class="muted">부: ${c.subs.map((s) => esc(s.name)).join(", ")}</div>` : ""}</td>
      <td style="text-align:center"><b>${c.equipment_count}</b>대${c.disposed_count ? `<div class="small muted">파기 ${c.disposed_count}</div>` : ""}</td>
      <td>${c.equipment_count ? healthCell(c.health) : '<span class="small muted">장비 없음</span>'}</td>
      <td class="small">${c.health?.last_log ? esc(c.health.last_log) : "-"}</td>
      <td class="small">${c.last_report ? `${esc(c.last_report.date || "")} ${esc(c.last_report.type)}<div>${chip(c.last_report.status, (S.catalog?.status || {})[c.last_report.status])}</div>` : '<span class="muted">-</span>'}</td>
      <td style="text-align:center;white-space:nowrap">${c.my_role ? roleChip(c.my_role) : ""}
        <select class="small" data-as="${c.id}" style="width:auto"><option value="">${c.my_role ? "변경" : "담당하기"}</option><option value="main">정</option><option value="sub">부</option>${c.my_role ? '<option value="none">해제</option>' : ""}</select></td></tr>`).join("")
      || `<tr><td colspan="7" class="empty">${S.custScope === "mine" ? "내가 맡은 고객이 없습니다. [전체] 탭이나 [PMS 고객 불러오기]에서 고객을 정(주 담당) 또는 부(보조)로 선택하세요." : "고객이 없습니다."}</td></tr>`;
    $$("[data-c]", v).forEach((r) => r.onclick = (e) => { if (!e.target.closest("select")) location.hash = "#/customers/" + r.dataset.c; });
    $$("[data-as]", v).forEach((sel) => sel.onchange = async () => {
      if (!sel.value) return;
      try { const r = await api(`customers/${sel.dataset.as}/assign`, { method: "POST", body: { kind: sel.value } }); toast(sel.value === "none" ? "담당을 해제했습니다." : sel.value === "main" && r.my_role === "sub" ? `이미 ${r.main ? r.main.name : "다른"} 님이 정 담당이라 부 담당으로 지정했습니다. (정 변경은 관리자·팀장)` : `${sel.value === "main" ? "정" : "부"} 담당으로 지정했습니다.`, "ok"); load(); }
      catch (e) { toast(e.message, "err"); sel.value = ""; }
    });
  };
  $$("[data-sc]", v).forEach((b) => b.onclick = () => { S.custScope = b.dataset.sc; $$("[data-sc]", v).forEach((x) => x.classList.toggle("on", x === b)); if ($("#su")) $("#su").value = ""; load(); });
  $("#su") && ($("#su").onchange = () => { if (!$("#su").value) return; S.custScope = $("#su").value; $$("[data-sc]", v).forEach((x) => x.classList.remove("on")); load(); });
  $("#cq").oninput = draw;
  $("#new-c") && ($("#new-c").onclick = () => customerForm(null, async (c) => { await api(`customers/${c.id}/assign`, { method: "POST", body: { kind: "main" } }).catch(() => {}); location.hash = "#/customers/" + c.id; }));
  $("#legacy") && ($("#legacy").onclick = () => legacyDialog(load));
  await load();
}

async function customerDetail(box, id) {
  S.showDisposed = !!S.showDisposed;
  const c = await api(`customers/${id}${S.showDisposed ? "?disposed=1" : ""}`);
  const ed = can("customer.edit"), eqEd = can("equipment.edit");
  const mgr = can("user.manage") || ["admin", "manager"].includes(S.me.role);
  const users = mgr ? await api("users").catch(() => []) : [];
  box.innerHTML = `<div class="row" style="margin-bottom:12px"><a class="btn sm" href="#/customers">← 고객 현황</a></div>
    <div class="card"><div class="row"><div class="grow"><h2 style="font-size:20px">${esc(c.name)} ${c.my_role ? roleChip(c.my_role) : ""}</h2><div class="muted">${esc(c.address || "")} ${c.site ? "· " + esc(c.site) : ""}</div>
      ${c.linked ? `<span class="chip gray" style="margin-top:6px">PMS 고객 연결 · ID ${esc(c.legacy_customer_id)}</span>` : '<span class="chip gray" style="margin-top:6px">MRS 단독 등록</span>'}</div>
      ${ed ? '<button class="btn" id="ed-c">수정</button><button class="btn danger" id="rm-c">삭제</button>' : ""}</div>${c.memo ? `<p class="small muted">${esc(c.memo)}</p>` : ""}
      <div class="row" style="margin-top:12px;gap:10px;flex-wrap:wrap"><span class="small"><b>정 담당</b> ${c.main ? esc(c.main.name) : '<span class="muted">미지정</span>'}</span>
        <span class="small"><b>부 담당</b> ${c.subs.length ? c.subs.map((s) => esc(s.name) + (mgr ? ` <a href="#" data-unsub="${s.id}" title="해제">✕</a>` : "")).join(", ") : '<span class="muted">없음</span>'}</span>
        <span class="right"></span>
        <select id="my-as" style="width:auto"><option value="">내 담당 지정…</option><option value="main">정으로</option><option value="sub">부로</option>${c.my_role ? '<option value="none">해제</option>' : ""}</select>
        ${mgr && users.length ? `<select id="ot-u" style="width:auto"><option value="">다른 담당자 지정…</option>${users.filter((u) => u.is_active).map((u) => `<option value="${u.id}">${esc(u.full_name || u.username)}</option>`).join("")}</select>
          <select id="ot-k" style="width:auto"><option value="main">정</option><option value="sub">부</option></select><button class="btn sm" id="ot-go">지정</button>` : ""}</div>
      <p class="small muted" style="margin:8px 0 0">담당을 바꾸거나 다른 사람에게 옮겨도 이 고객의 장비·로그·보고서는 그대로 유지됩니다.</p></div>
    <div class="card" style="margin-top:16px"><div class="row"><h3 style="margin:0">전산 담당자</h3>${ed ? '<button class="btn sm right" id="add-ct">＋ 담당자 추가</button>' : ""}</div>
      <table class="t" style="margin-top:12px"><thead><tr><th>이름</th><th>부서/직책</th><th>이메일</th><th>연락처</th><th>보고서 수신</th><th></th></tr></thead><tbody>
      ${c.contacts.map((x) => `<tr><td><b>${esc(x.name)}</b> ${x.is_primary ? '<span class="chip">대표</span>' : ""}</td><td>${esc(x.department)} ${esc(x.position)}</td><td>${esc(x.email)}</td><td>${esc(x.phone)}</td><td>${x.receive_reports ? "✅" : "—"}</td>
        <td style="white-space:nowrap">${ed ? `<button class="btn sm" data-ect="${x.id}">수정</button><button class="btn sm danger" data-rct="${x.id}">삭제</button>` : ""}</td></tr>`).join("") || '<tr><td colspan="6" class="muted">등록된 담당자가 없습니다.</td></tr>'}</tbody></table></div>
    <div class="card" style="margin-top:16px"><div class="row"><h3 style="margin:0">장비</h3><span class="small muted">운영 ${c.equipment_count}대${c.disposed_count ? ` · 파기 ${c.disposed_count}대` : ""}</span>
      <label class="small row right" style="gap:6px"><input type="checkbox" id="shw-d" ${S.showDisposed ? "checked" : ""}> 파기장비 포함</label>${eqEd ? '<button class="btn sm" id="add-eq">＋ 장비 추가</button>' : ""}</div>
      <table class="t" style="margin-top:12px"><thead><tr><th>시스템</th><th>모델</th><th>S/N</th><th>OS</th><th>HA</th><th>최신 로그 상태</th><th></th></tr></thead><tbody>
      ${c.equipment.map((e) => `<tr style="${e.disposed ? "opacity:.55" : ""}"><td><b>${esc(e.name)}</b>${e.disposed ? `<div class="small"><span class="chip gray">파기 ${esc(e.disposed_at)}</span> ${esc(e.dispose_note)}</div>` : ""}</td><td>${esc(e.vendor)} ${esc(e.model)}</td><td class="mono small">${esc(e.serial)}</td><td>${esc(e.os_version)}</td><td>${esc(e.ha_type)}</td>
        <td class="small">${e.last_log ? `${healthCell({ crit: e.last_log.crit, warn: e.last_log.warn })} <span class="muted">${esc(e.last_log.at)}</span>` : '<span class="muted">로그 없음</span>'}</td>
        <td style="white-space:nowrap"><a class="btn sm" href="#/logs/${c.id}">로그</a>${eqEd ? (e.disposed ? `<button class="btn sm" data-rst="${e.id}">복원</button><button class="btn sm danger" data-req="${e.id}">완전 삭제</button>`
          : `<button class="btn sm" data-eeq="${e.id}">수정</button><button class="btn sm danger" data-dsp="${e.id}">파기</button>`) : ""}</td></tr>`).join("") || '<tr><td colspan="7" class="muted">등록된 장비가 없습니다.</td></tr>'}</tbody></table></div>`;
  const refresh = () => route();
  const assign = async (body) => { try { const r = await api(`customers/${id}/assign`, { method: "POST", body }); toast(!body.user_id && body.kind === "main" && r.my_role === "sub" ? `이미 ${r.main ? r.main.name : "다른"} 님이 정 담당이라 부 담당으로 지정했습니다. (정 변경은 관리자·팀장)` : "담당을 바꿨습니다.", "ok"); refresh(); } catch (e) { toast(e.message, "err"); } };
  $("#my-as").onchange = () => $("#my-as").value && assign({ kind: $("#my-as").value });
  $("#ot-go") && ($("#ot-go").onclick = () => $("#ot-u").value ? assign({ user_id: +$("#ot-u").value, kind: $("#ot-k").value }) : toast("담당자를 고르세요.", "err"));
  $$("[data-unsub]", box).forEach((a) => a.onclick = (e) => { e.preventDefault(); assign({ user_id: +a.dataset.unsub, kind: "none" }); });
  $("#shw-d").onchange = () => { S.showDisposed = $("#shw-d").checked; refresh(); };
  $("#ed-c") && ($("#ed-c").onclick = () => customerForm(c, refresh));
  $("#rm-c") && ($("#rm-c").onclick = async () => { if (await confirmBox(`${c.name} 고객을 삭제할까요? (담당자·장비·로그 포함)`)) api("customers/" + id, { method: "DELETE" }).then(() => { toast("삭제되었습니다.", "ok"); location.hash = "#/customers"; }).catch((e) => toast(e.message, "err")); });
  $("#add-ct") && ($("#add-ct").onclick = () => contactForm(id, null, refresh));
  $$("[data-ect]", box).forEach((b) => b.onclick = () => contactForm(id, c.contacts.find((x) => x.id === +b.dataset.ect), refresh));
  $$("[data-rct]", box).forEach((b) => b.onclick = async () => { if (await confirmBox("담당자를 삭제할까요?")) { await api("contacts/" + b.dataset.rct, { method: "DELETE" }); refresh(); } });
  $("#add-eq") && ($("#add-eq").onclick = () => equipmentForm(id, null, refresh));
  $$("[data-eeq]", box).forEach((b) => b.onclick = () => equipmentForm(id, c.equipment.find((x) => x.id === +b.dataset.eeq), refresh));
  $$("[data-dsp]", box).forEach((b) => b.onclick = () => {
    const e = c.equipment.find((x) => x.id === +b.dataset.dsp);
    const m = modal(`<h3>장비 파기 처리</h3><p><b>${esc(e.name)}</b> (${esc(e.model)})를 파기 처리합니다. 현황과 새 보고서 선택에서는 빠지지만, 로그와 보고서 이력은 그대로 남아 [파기장비 포함]을 켜면 다시 볼 수 있습니다.</p>
      <label class="f">파기 사유 / 메모</label><input type="text" id="dn" placeholder="예) 2026-11 장비 교체로 철수">
      <div class="actions"><button class="btn" data-close>취소</button><button class="btn danger" id="dgo">파기 처리</button></div>`);
    $("#dgo", m).onclick = (ev) => busy(ev.target, async () => { await api(`equipment/${e.id}/dispose`, { method: "POST", body: { note: $("#dn", m).value } }); m.remove(); toast("파기 처리했습니다.", "ok"); refresh(); });
  });
  $$("[data-rst]", box).forEach((b) => b.onclick = async () => { await api(`equipment/${b.dataset.rst}/restore`, { method: "POST" }); toast("복원했습니다.", "ok"); refresh(); });
  $$("[data-req]", box).forEach((b) => b.onclick = async () => { if (await confirmBox("장비를 완전히 삭제할까요? (로그·보고서 이력이 있으면 삭제되지 않습니다)")) { try { await api("equipment/" + b.dataset.req, { method: "DELETE" }); toast("삭제되었습니다.", "ok"); refresh(); } catch (e) { toast(e.message, "err"); } } });
}

function formModal(title, fields, data, onSave) {
  const m = modal(`<h3>${title}</h3><div class="grid g2">${fields.map(([k, l, type, req, full]) => `<div ${full ? 'style="grid-column:span 2"' : ""}>
    ${type === "check" ? `<label class="row" style="margin-top:28px"><input type="checkbox" data-k="${k}" ${data[k] ? "checked" : ""}> ${l}</label>`
      : `<label class="f">${l}${req ? '<span class="req">*</span>' : ""}</label>${type === "textarea" ? `<textarea data-k="${k}">${esc(data[k] || "")}</textarea>` : `<input type="${type || "text"}" data-k="${k}" value="${esc(data[k] ?? "")}">`}`}</div>`).join("")}</div>
    <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="fm-save">저장</button></div>`);
  $("#fm-save", m).onclick = (e) => busy(e.target, async () => {
    const out = {}; $$("[data-k]", m).forEach((i) => out[i.dataset.k] = i.type === "checkbox" ? i.checked : i.value.trim());
    for (const [k, l, , req] of fields) if (req && !out[k]) throw new Error(l + " 을(를) 입력하세요.");
    const r = await onSave(out); m.remove(); toast("저장되었습니다.", "ok"); return r;
  });
}
const customerForm = (c, done) => formModal(c ? "고객 수정" : "고객 신규 등록", [["name", "고객명", "text", true], ["site", "설치 위치 (전산실/IDC)"], ["address", "주소", "text", false, true], ["memo", "메모", "textarea", false, true]], c || {},
  async (d) => { const r = await api(c ? "customers/" + c.id : "customers", { method: c ? "PUT" : "POST", body: { ...d, legacy_customer_id: c?.legacy_customer_id || null } }); done(r); });
const contactForm = (cid, ct, done) => formModal(ct ? "담당자 수정" : "전산 담당자 등록", [["name", "이름", "text", true], ["department", "부서"], ["position", "직책"], ["phone", "연락처"], ["email", "이메일", "email", false, true], ["receive_reports", "보고서 메일 수신", "check"], ["is_primary", "대표 담당자", "check"]], ct || { receive_reports: true },
  async (d) => { await api(ct ? "contacts/" + ct.id : `customers/${cid}/contacts`, { method: ct ? "PUT" : "POST", body: d }); done(); });
const equipmentForm = (cid, e, done) => formModal(e ? "장비 수정" : "장비 등록", [["name", "시스템명/호스트명", "text", true], ["vendor", "제조사"], ["model", "모델 (로그에서 자동 인식)"], ["serial", "System S/N"], ["os_version", "OS 버전"], ["ha_type", "HA 구성 (예: HA Pair)"], ["disk_info", "디스크 (예: SAS 24EA)"], ["location", "위치"], ["contract_type", "계약 유형"], ["memo", "메모", "textarea", false, true]], e || { vendor: "NetApp" },
  async (d) => { await api(e ? "equipment/" + e.id : `customers/${cid}/equipment`, { method: e ? "PUT" : "POST", body: { ...d, is_active: true } }); done(); });

// PMS 고객 전체 목록 (개수 제한 없음). 여기서 바로 정/부 담당으로 연결합니다.
async function legacyDialog(onDone) {
  const m = modal(`<h3>🔗 PMS 고객 불러오기</h3><p class="small muted">PMS 고객 테이블을 읽기 전용으로 불러옵니다. 맡을 고객을 <b>정</b>(주 담당) 또는 <b>부</b>(보조)로 연결하세요. PMS 쪽 데이터는 바꾸지 않습니다.</p>
    <div class="row"><input type="search" id="lq" placeholder="고객명·코드·주소로 거르기"><label class="small row" style="gap:6px;white-space:nowrap"><input type="checkbox" id="lonly"> 미연결만</label><button class="btn" id="lsync" title="연결된 고객명·주소를 PMS 기준으로 갱신">동기화</button></div>
    <div class="small muted" id="lcnt" style="margin-top:8px"></div><div id="lr" style="margin-top:8px;max-height:56vh;overflow:auto"></div>
    <div class="actions"><button class="btn" data-close>닫기</button></div>`, "wide");
  const r = await api("legacy/customers");
  if (!r.enabled) { $("#lr", m).innerHTML = '<div class="missing">PMS 고객 테이블을 찾지 못했습니다. [시스템 설정] → 기존 고객 DB 연동에서 테이블 이름을 확인하세요.</div>'; return; }
  let changed = false;
  const draw = () => {
    const q = $("#lq", m).value.trim(), only = $("#lonly", m).checked;
    const list = r.items.filter((x) => (!q || x.name.includes(q) || (x.code || "").includes(q) || (x.address || "").includes(q)) && (!only || !x.mrs_customer_id));
    $("#lcnt", m).textContent = `PMS 고객 ${r.total}곳 중 ${list.length}곳 표시 · 연결됨 ${r.items.filter((x) => x.mrs_customer_id).length}곳`;
    $("#lr", m).innerHTML = `<table class="t"><thead><tr><th>코드</th><th>고객명</th><th>주소</th><th>정 담당</th><th></th></tr></thead><tbody>${list.map((x) => `<tr><td class="mono small">${esc(x.code || x.id)}</td><td><b>${esc(x.name)}</b></td><td class="small">${esc(x.address)}</td>
      <td class="small">${x.mrs_customer_id ? esc(x.main_name || "미지정") : '<span class="muted">-</span>'}</td>
      <td style="white-space:nowrap"><button class="btn sm primary" data-link="${esc(x.id)}" data-k="main">정</button><button class="btn sm" data-link="${esc(x.id)}" data-k="sub">부</button>${x.mrs_customer_id ? `<a class="btn sm ghost" href="#/customers/${x.mrs_customer_id}">열기</a>` : ""}</td></tr>`).join("")}</tbody></table>`;
    $$("[data-link]", m).forEach((b) => b.onclick = (e) => busy(e.target, async () => {
      const c = await api(`legacy/customers/${encodeURIComponent(b.dataset.link)}/link`, { method: "POST", body: { kind: b.dataset.k } });
      const it = r.items.find((x) => x.id === b.dataset.link); it.mrs_customer_id = c.id; it.main_name = c.main ? c.main.name : ""; changed = true;
      toast(`${c.name} → ${c.my_role === "main" ? "정" : "부"} 담당으로 연결`, "ok"); draw();
    }));
  };
  $("#lq", m).oninput = draw; $("#lonly", m).onchange = draw;
  $("#lsync", m).onclick = (e) => busy(e.target, async () => { const x = await api("legacy/sync", { method: "POST" }); toast(`${x.updated}건 갱신`, "ok"); });
  m.addEventListener("mousedown", () => {}, { once: true });
  const obs = new MutationObserver(() => { if (!document.body.contains(m) && changed && onDone) { obs.disconnect(); onDone(); } });
  obs.observe($("#modal-root"), { childList: true });
  draw();
}

// ───────────────────────── 장비 · 로그 보관함 ─────────────────────────
async function pageLogs(v, cid) {
  const mine = await api("customers?scope=mine"); const all_ = await api("customers?scope=all");
  const custs = [...mine, ...all_.filter((x) => !mine.some((m) => m.id === x.id))];
  cid = +cid || custs[0]?.id;
  if (!cid) { v.innerHTML = '<div class="card empty"><div class="big">🗄️</div>고객을 먼저 등록하세요.</div>'; return; }
  const c = await api(`customers/${cid}${S.showDisposed ? "?disposed=1" : ""}`);
  v.innerHTML = `<div class="card"><div class="row"><select id="lc" style="max-width:280px">${custs.map((x) => `<option value="${x.id}" ${x.id === cid ? "selected" : ""}>${x.my_role ? (x.my_role === "main" ? "[정] " : "[부] ") : ""}${esc(x.name)}</option>`).join("")}</select>
    <select id="le" style="max-width:220px"><option value="">전체 장비</option>${c.equipment.map((e) => `<option value="${e.id}">${esc(e.name)} (${esc(e.model)})</option>`).join("")}</select>
    <input type="date" id="df" style="max-width:160px"><span class="muted">~</span><input type="date" id="dt" style="max-width:160px"><label class="small row right" style="gap:6px"><input type="checkbox" id="shw-d" ${S.showDisposed ? "checked" : ""}> 파기장비 포함 (로그 포함)</label></div></div>
    ${can("log.upload") ? `<div class="drop" id="drop" style="margin-top:16px"><div class="big">📥</div><b>로그 파일을 끌어다 놓으면 ${esc(c.name)} 고객에 장비별 · 시점별로 보관됩니다</b><div class="small muted">장비를 위에서 선택하면 해당 장비로, 아니면 시리얼/호스트명으로 자동 매칭</div><input type="file" id="fi" multiple hidden></div>` : ""}
    <div class="card" style="margin-top:16px"><h3>로그 타임라인</h3><div id="tl"></div></div>`;
  $("#lc").onchange = () => location.hash = "#/logs/" + $("#lc").value;
  const load = async () => {
    const qs = new URLSearchParams(); if (S.showDisposed) qs.set("disposed", "1"); if ($("#le").value) qs.set("equipment_id", $("#le").value); if ($("#df").value) qs.set("date_from", $("#df").value); if ($("#dt").value) qs.set("date_to", $("#dt").value);
    const logs = await api(`customers/${cid}/logs?` + qs);
    const byDay = {}; logs.forEach((l) => (byDay[l.collected_at.slice(0, 10)] = byDay[l.collected_at.slice(0, 10)] || []).push(l));
    $("#tl").innerHTML = Object.keys(byDay).length ? `<div class="timeline">${Object.entries(byDay).map(([d, ls]) => `<div class="tl"><b>${d}</b> <span class="small muted">${ls.length}건</span>
      ${ls.map((l) => `<div class="file-row"><div class="grow"><b>${esc(l.original_name)}</b><div class="small muted">🗄️ ${esc(l.equipment_name || "장비 미지정")} · ${esc(l.collected_at.slice(11))} · ${fmtBytes(l.size_bytes)}
        · Aggr ${l.summary.aggregates} · Vol ${l.summary.volumes} ${l.summary.failed_disks ? "· ❗Failed Disk " + l.summary.failed_disks : ""}</div></div>${chip(l.summary.status)}
        <button class="btn sm" data-view="${l.id}">상세</button>${can("log.upload") ? `<select class="small" data-as="${l.id}" style="width:auto"><option value="">장비 지정…</option>${c.equipment.map((e) => `<option value="${e.id}" ${e.id === l.equipment_id ? "selected" : ""}>${esc(e.name)}</option>`).join("")}</select>` : ""}
        <a class="btn sm" href="${apiUrl(`logs/${l.id}/download`)}">⬇</a>${can("log.delete") ? `<button class="btn sm danger" data-rm="${l.id}">삭제</button>` : ""}</div>`).join("")}</div>`).join("")}</div>`
      : '<div class="empty"><div class="big">📭</div>조건에 맞는 로그가 없습니다.</div>';
    $$("[data-view]", v).forEach((b) => b.onclick = () => logDetail(+b.dataset.view));
    $$("[data-as]", v).forEach((s) => s.onchange = async () => { await api(`logs/${s.dataset.as}?equipment_id=${s.value}`, { method: "PATCH" }); toast("장비가 지정되었습니다.", "ok"); load(); });
    $$("[data-rm]", v).forEach((b) => b.onclick = async () => { if (await confirmBox("로그를 삭제할까요?")) { await api("logs/" + b.dataset.rm, { method: "DELETE" }); load(); } });
  };
  ["le", "df", "dt"].forEach((k) => $("#" + k).onchange = load);
  $("#shw-d").onchange = () => { S.showDisposed = $("#shw-d").checked; route(); };
  if ($("#drop")) setupDrop($("#drop"), $("#fi"), async (files) => {
    const fd = new FormData(); files.forEach((f) => fd.append("files", f)); if ($("#le").value) fd.append("equipment_id", $("#le").value);
    try { const r = await api(`customers/${cid}/logs`, { method: "POST", form: fd }); toast(`${r.length}개 로그 보관 완료`, "ok"); load(); } catch (e) { toast(e.message, "err"); }
  });
  await load();
}

async function logDetail(id) {
  const l = await api("logs/" + id);
  const p = l.parsed || {}; const a = l.analysis;
  const m = modal(`<h3>📄 ${esc(l.original_name)}</h3><div class="row">${ring(a.score, a.status, 70)}<div><div>${chip(a.status, a.status_label)}</div>
    <div class="small muted" style="margin-top:6px">모델 ${esc(p.system?.model || "-")} · OS ${esc(p.system?.os_version || "-")} · S/N ${esc(p.system?.serial || "-")}</div></div></div>
    <div class="tabs" style="margin-top:14px"><button class="on" data-tab="f">진단</button><button data-tab="a">Aggregate</button><button data-tab="v">Volume</button><button data-tab="e">이벤트</button></div>
    <div data-pane="f">${a.findings.map((f) => `<div class="finding"><span class="sev ${f.severity}"></span><div><b>${esc(f.title)}</b><div class="small muted">${esc(f.detail)}</div><div class="small">→ ${esc(f.recommendation)}</div></div></div>`).join("") || '<div class="ok-box">이상 없음</div>'}</div>
    <div data-pane="a" hidden><table class="t"><thead><tr><th>Aggr</th><th>Node</th><th>Total</th><th>Used%</th></tr></thead><tbody>${(p.aggregates || []).map((g) => `<tr><td>${esc(g.name)}</td><td>${esc(g.node)}</td><td>${gb(g.total_gb)}</td><td>${g.pct}%</td></tr>`).join("")}</tbody></table></div>
    <div data-pane="v" hidden><table class="t"><thead><tr><th>Volume</th><th>Total</th><th>Used%</th><th>Inode%</th></tr></thead><tbody>${(p.volumes || []).map((g) => `<tr><td>${esc(g.name)}</td><td>${gb(g.total_gb)}</td><td>${g.pct}%</td><td>${g.inode_pct ?? "-"}</td></tr>`).join("")}</tbody></table></div>
    <div data-pane="e" hidden><table class="t"><thead><tr><th>Severity</th><th>Event</th><th>Count</th></tr></thead><tbody>${(p.events?.top || []).map((x) => `<tr><td>${esc(x.severity)}</td><td class="mono small">${esc(x.event)}</td><td>${x.count}</td></tr>`).join("")}</tbody></table></div>
    <div class="actions"><button class="btn" data-close>닫기</button></div>`, "wide");
  $$("[data-tab]", m).forEach((b) => b.onclick = () => { $$("[data-tab]", m).forEach((x) => x.classList.toggle("on", x === b)); $$("[data-pane]", m).forEach((pn) => pn.hidden = pn.dataset.pane !== b.dataset.tab); });
  animate(m);
}

// ───────────────────────── 고객 양식 ─────────────────────────
async function pageTemplates(v) {
  const [tpls, ph, custs] = await Promise.all([api("templates"), api("templates/placeholders"), api("customers")]);
  const cname = Object.fromEntries(custs.map((c) => [c.id, c.name]));
  const ed = can("template.manage");
  v.innerHTML = `<div class="grid" style="grid-template-columns:1fr minmax(280px,380px);align-items:start" id="tg">
    <div class="card"><div class="row"><h3 style="margin:0">등록된 고객 양식</h3>${ed ? '<button class="btn primary sm right" id="new-t">＋ 양식 등록</button>' : ""}</div>
      <table class="t" style="margin-top:12px"><thead><tr><th>양식</th><th>고객</th><th>종류</th><th>치환자</th><th></th></tr></thead><tbody>
      ${tpls.map((t) => `<tr><td><b>${t.file_format === "pptx" ? "📽️" : "📗"} ${esc(t.name)}</b><div class="small muted">${esc(t.original_name)} · ${esc(t.updated_at)}</div></td><td>${esc(cname[t.customer_id] || "공용")}</td>
        <td>${esc(S.catalog.report_types[t.report_type] || t.report_type)}</td><td class="small">${t.placeholders.length}개</td>
        <td style="white-space:nowrap"><a class="btn sm" href="${apiUrl(`templates/${t.id}/download`)}">⬇</a>${ed ? `<button class="btn sm" data-et="${t.id}">수정</button><button class="btn sm danger" data-rt="${t.id}">삭제</button>` : ""}</td></tr>`).join("") || '<tr><td colspan="5" class="muted">등록된 양식이 없습니다.</td></tr>'}</tbody></table></div>
    <div class="card"><h3>양식 작성 방법</h3><p class="small muted">고객 양식(PPT/엑셀)의 원하는 위치에 <code>{{치환자}}</code>를 입력해 등록하면, 보고서 생성 시 자동으로 값이 채워집니다. 표준 PPT·엑셀도 항상 함께 생성됩니다.</p>
      <table class="t">${Object.entries(ph).map(([k, d]) => `<tr><td class="mono small">{{${esc(k)}}}</td><td class="small">${esc(d)}</td></tr>`).join("")}</table></div></div>`;
  if (innerWidth < 1000) $("#tg").style.gridTemplateColumns = "1fr";
  const form = (t) => {
    const m = modal(`<h3>${t ? "양식 수정" : "고객 양식 등록"}</h3><label class="f">양식 이름 <span class="req">*</span></label><input type="text" id="tn" value="${esc(t?.name || "")}">
      <div class="grid g2"><div><label class="f">고객 (비우면 공용)</label><select id="tc"><option value="">공용</option>${custs.map((c) => `<option value="${c.id}" ${t?.customer_id === c.id ? "selected" : ""}>${esc(c.name)}</option>`).join("")}</select></div>
      <div><label class="f">보고서 종류</label><select id="tr">${Object.entries(S.catalog.report_types).map(([k, l]) => `<option value="${k}" ${t?.report_type === k ? "selected" : ""}>${l}</option>`).join("")}</select></div></div>
      <label class="f">설명</label><input type="text" id="td" value="${esc(t?.description || "")}">
      <label class="f">파일 (.pptx / .xlsx) ${t ? "(교체 시에만)" : '<span class="req">*</span>'}</label><div class="drop" id="tdrop"><b id="tfn">파일을 끌어다 놓거나 클릭</b><input type="file" id="tf" accept=".pptx,.xlsx" hidden></div>
      <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="ts">저장</button></div>`);
    let file = null;
    setupDrop($("#tdrop", m), $("#tf", m), (fs) => { file = fs[0]; $("#tfn", m).textContent = "📎 " + file.name; });
    $("#ts", m).onclick = (e) => busy(e.target, async () => {
      const fd = new FormData(); fd.append("name", $("#tn", m).value); fd.append("report_type", $("#tr", m).value); if ($("#tc", m).value) fd.append("customer_id", $("#tc", m).value);
      fd.append("description", $("#td", m).value); if (file) fd.append("file", file); else if (!t) throw new Error("파일을 선택하세요.");
      const r = await api(t ? "templates/" + t.id : "templates", { method: t ? "PUT" : "POST", form: fd });
      m.remove(); toast(`저장되었습니다. 치환자 ${r.placeholders.length}개 인식`, "ok"); route();
    });
  };
  $("#new-t") && ($("#new-t").onclick = () => form(null));
  $$("[data-et]", v).forEach((b) => b.onclick = () => form(tpls.find((t) => t.id === +b.dataset.et)));
  $$("[data-rt]", v).forEach((b) => b.onclick = async () => { if (await confirmBox("양식을 삭제할까요?")) { await api("templates/" + b.dataset.rt, { method: "DELETE" }); route(); } });
}

// ───────────────────────── 사용자 · 권한 ─────────────────────────
async function pageUsers(v) {
  const [users, cat] = await Promise.all([api("users"), api("permissions")]);
  const admin = can("user.manage");
  v.innerHTML = `<div class="card"><div class="row"><h3 style="margin:0">사용자</h3>${admin ? '<button class="btn primary sm right" id="new-u">＋ 사용자 등록</button>' : ""}</div>
    <table class="t" style="margin-top:12px"><thead><tr><th>아이디</th><th>이름</th><th>역할</th><th>연락처</th><th>담당 고객</th><th>권한</th><th>최근 로그인</th><th></th></tr></thead><tbody>
    ${users.map((u) => `<tr style="${u.is_active ? "" : "opacity:.5"}"><td class="mono">${esc(u.username)}</td><td><b>${esc(u.full_name)}</b> <span class="small muted">${esc(u.title)}</span></td><td>${chip(u.role === "admin" ? "critical" : u.role === "manager" ? "warning" : u.role === "engineer" ? "sent" : "gray", u.role_label)}</td>
      <td class="small">${esc(u.email)}<br>${esc(u.phone)}</td><td class="small"><a href="#/customers" data-uc="${u.id}">정 ${u.assigned.main} · 부 ${u.assigned.sub}</a></td><td class="small">${u.permissions.length}/${Object.keys(cat.permissions).length}</td><td class="small muted">${esc((u.last_login_at || "").replace("T", " ").slice(0, 16))}</td>
      <td style="white-space:nowrap">${admin ? `<button class="btn sm" data-eu="${u.id}">수정</button>${u.assigned.main + u.assigned.sub ? `<button class="btn sm" data-tr="${u.id}">담당 이관</button>` : ""}${u.id !== S.me.id ? `<button class="btn sm danger" data-ru="${u.id}">삭제</button>` : ""}` : ""}</td></tr>`).join("")}</tbody></table></div>
    ${admin ? '<div class="card" style="margin-top:16px"><h3>감사 로그</h3><div id="audit"></div></div>' : ""}`;
  const form = (u) => {
    const ov = { ...(u?.overrides || {}) };
    const m = modal(`<h3>${u ? "사용자 수정" : "사용자 등록"}</h3><div class="grid g2">
      <div><label class="f">아이디 <span class="req">*</span></label><input type="text" id="un" value="${esc(u?.username || "")}" ${u ? "readonly" : ""}></div>
      <div><label class="f">비밀번호 ${u ? "(변경 시에만)" : '<span class="req">*</span>'}</label><input type="password" id="up"></div>
      <div><label class="f">이름</label><input type="text" id="uf" value="${esc(u?.full_name || "")}"></div><div><label class="f">직급</label><input type="text" id="ut" value="${esc(u?.title || "")}"></div>
      <div><label class="f">이메일</label><input type="email" id="ue" value="${esc(u?.email || "")}"></div><div><label class="f">연락처</label><input type="text" id="uph" value="${esc(u?.phone || "")}"></div>
      <p class="small muted" style="grid-column:1/-1;margin:0">이 사용자가 담당 엔지니어인 보고서에는 위 연락처와 이메일이 회사 연락처로 표시됩니다.</p>
      <div><label class="f">역할</label><select id="ur">${Object.entries(cat.roles).map(([k, l]) => `<option value="${k}" ${(u?.role || "engineer") === k ? "selected" : ""}>${l}</option>`).join("")}</select></div>
      <div><label class="row" style="margin-top:28px"><input type="checkbox" id="ua" ${u?.is_active !== false ? "checked" : ""}> 활성</label></div></div>
      <label class="f">세부 권한 (역할 기본값에서 개별 조정)</label><div class="perm-grid" id="pg"></div>
      <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="us">저장</button></div>`, "wide");
    const drawPerm = () => {
      const base = new Set(cat.role_defaults[$("#ur", m).value]);
      $("#pg", m).innerHTML = Object.entries(cat.permissions).map(([k, l]) => { const on = k in ov ? ov[k] : base.has(k); return `<label><input type="checkbox" data-pm="${k}" ${on ? "checked" : ""}> ${esc(l)} ${k in ov ? '<span class="chip warning" style="font-size:10px">개별</span>' : ""}</label>`; }).join("");
      $$("[data-pm]", m).forEach((c) => c.onchange = () => { const b = base.has(c.dataset.pm); if (c.checked === b) delete ov[c.dataset.pm]; else ov[c.dataset.pm] = c.checked; drawPerm(); });
    };
    $("#ur", m).onchange = () => { for (const k in ov) delete ov[k]; drawPerm(); }; drawPerm();
    $("#us", m).onclick = (e) => busy(e.target, async () => {
      const body = { username: $("#un", m).value.trim(), password: $("#up", m).value || null, full_name: $("#uf", m).value, title: $("#ut", m).value, email: $("#ue", m).value, phone: $("#uph", m).value, role: $("#ur", m).value, is_active: $("#ua", m).checked, overrides: ov };
      await api(u ? "users/" + u.id : "users", { method: u ? "PUT" : "POST", body }); m.remove(); toast("저장되었습니다.", "ok"); route();
    });
  };
  $("#new-u") && ($("#new-u").onclick = () => form(null));
  $$("[data-uc]", v).forEach((a) => a.onclick = () => { S.custScope = "user:" + a.dataset.uc; });
  // 담당 이관: 정/부 배정만 옮기고, 고객의 장비·로그·보고서는 그대로 둡니다.
  $$("[data-tr]", v).forEach((b) => b.onclick = () => {
    const u = users.find((x) => x.id === +b.dataset.tr);
    const m = modal(`<h3>담당 고객 이관</h3><p><b>${esc(u.full_name || u.username)}</b>님이 맡은 고객 (정 ${u.assigned.main} · 부 ${u.assigned.sub})을 다른 사용자에게 옮깁니다. 고객의 장비·로그·보고서는 그대로 유지됩니다.</p>
      <label class="f">받을 사용자</label><select id="tto">${users.filter((x) => x.is_active && x.id !== u.id).map((x) => `<option value="${x.id}">${esc(x.full_name || x.username)}</option>`).join("")}</select>
      <label class="row small" style="margin-top:10px;gap:6px"><input type="checkbox" id="trep" checked> 진행 중(미완료) 보고서의 담당 엔지니어도 함께 변경</label>
      <div class="actions"><button class="btn" data-close>취소</button><button class="btn primary" id="tgo">이관</button></div>`);
    $("#tgo", m).onclick = (e) => busy(e.target, async () => { const r = await api(`users/${u.id}/transfer`, { method: "POST", body: { to_user_id: +$("#tto", m).value, reports: $("#trep", m).checked } }); m.remove(); toast(`${r.moved}개 고객을 옮겼습니다.`, "ok"); route(); });
  });
  $$("[data-eu]", v).forEach((b) => b.onclick = () => form(users.find((u) => u.id === +b.dataset.eu)));
  $$("[data-ru]", v).forEach((b) => b.onclick = async () => { if (await confirmBox("사용자를 삭제할까요?")) { await api("users/" + b.dataset.ru, { method: "DELETE" }).catch((e) => toast(e.message, "err")); route(); } });
  if (admin) {
    const logs = await api("audit?limit=50");
    $("#audit").innerHTML = `<table class="t"><thead><tr><th>시각</th><th>사용자</th><th>작업</th><th>대상</th></tr></thead><tbody>${logs.map((l) => `<tr><td class="small muted">${esc(l.at.replace("T", " ").slice(0, 16))}</td><td>${esc(l.user)}</td><td class="mono small">${esc(l.action)}</td><td class="small">${esc(l.target)} ${esc(l.detail).slice(0, 80)}</td></tr>`).join("")}</tbody></table>`;
  }
}

// ───────────────────────── 시스템 설정 ─────────────────────────
async function pageSettings(v) {
  const d = await api("settings");
  const groups = [
    ["회사 정보", ["company_name", "company_address"]],
    ["메일 발송", ["mail_method", "mail_from", "mail_from_name"]],
    ["일반 SMTP", ["smtp_host", "smtp_port", "smtp_security", "smtp_user", "smtp_password"]],
    ["Microsoft 365 (OAuth)", ["m365_tenant", "m365_client_id", "m365_client_secret"]],
    ["Claude AI", ["anthropic_key", "ai_model"]],
    ["기존 고객 DB 연동", ["legacy_table"]],
  ];
  const by = Object.fromEntries(d.items.map((x) => [x.key, x]));
  const field = (x) => {
    if (x.kind === "select") return `<div><label class="f">${esc(x.label)}</label><select data-k="${x.key}">${Object.entries(x.options || {}).map(([k, l]) => `<option value="${esc(k)}" ${x.value === k ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></div>`;
    const ph = x.secret ? (x.is_set ? `저장됨 ${x.masked} (바꿀 때만 입력)` : "미설정") : "";
    const type = x.kind === "password" ? "password" : x.kind === "number" ? "number" : "text";
    return `<div><label class="f">${esc(x.label)}</label><input type="${type}" data-k="${x.key}" value="${x.secret ? "" : esc(x.value)}" placeholder="${esc(ph)}" autocomplete="new-password">
      ${x.secret && x.is_set && x.source === "db" ? `<label class="small row" style="margin-top:4px"><input type="checkbox" data-clear="${x.key}"> 저장된 값 지우기</label>` : ""}</div>`;
  };
  const note = {
    "회사 정보": "보고서의 연락처와 이메일은 담당 엔지니어의 사용자 정보(사용자 · 권한)에서 가져옵니다.",
    "메일 발송": "회사 Microsoft 365에서 \"보안 기본값\" 때문에 SMTP 로그인이 막히면(535 5.7.139) 발송 방식을 Microsoft 365로 바꾸세요. 이때 보내는 사람 주소는 실제 회사 메일함 주소여야 합니다.",
    "일반 SMTP": "보내는 사람 주소를 비우면 SMTP 계정 주소로 보냅니다. Gmail·네이버웍스 등은 앱 비밀번호가 필요할 수 있습니다.",
    "Microsoft 365 (OAuth)": "Azure 포털 → Microsoft Entra ID → 앱 등록에서 만든 앱의 값을 넣습니다. API 권한에 Microsoft Graph의 Mail.Send(애플리케이션 권한)를 추가하고 \"관리자 동의\"를 눌러야 합니다. 자세한 순서는 설치 안내서(README_설치안내.md)를 보세요.",
    "Claude AI": "API 키는 Claude Console(platform.claude.com) → API Keys에서 발급합니다. 사용한 만큼 과금되며 결제 수단 등록이 필요합니다. 키가 없으면 규칙 기반 초안으로 대신 작성합니다.",
    "기존 고객 DB 연동": `PMS 파트관리시스템의 고객 테이블(기본 pms_customers)을 읽기 전용으로 불러옵니다. 현재 상태: ${d.legacy_ok ? '<b style="color:var(--green)">연결됨</b>' : '<b style="color:var(--red)">테이블 없음</b>'}`,
  };
  const chk = (ok, label) => `<span class="chip ${ok ? "" : "warning"}">${ok ? "✔" : "!"} ${label}</span>`;
  v.innerHTML = groups.map(([t, ks]) => `<div class="card" data-grp="${esc(t)}" style="margin-bottom:16px"><h3>${t}</h3><div class="grid g2">${ks.map((k) => field(by[k])).join("")}</div>
    <p class="small muted" style="margin-top:10px">${note[t]}</p>
    ${t === "메일 발송" ? `<div class="row" style="margin-top:8px"><input type="email" id="tm-to" placeholder="테스트 받을 주소" value="${esc(S.me.email || "")}" style="max-width:280px"><button class="btn sm" id="tm">테스트 메일 보내기</button></div>` : ""}</div>`).join("")
    + `<div class="card" style="margin-bottom:16px"><h3>서버 점검</h3><div class="row">${chk(d.zip, "zip (PPT·엑셀)")}${chk(d.curl, "cURL (Claude)")}${chk(d.openssl, "openssl (암호화·메일)")}<span class="chip gray">PHP ${esc(d.php)}</span><span class="chip gray">업로드 한도 ${esc(d.upload_max)} / ${esc(d.post_max)}</span></div>
      <p class="small muted" style="margin-top:8px">대용량 로그(수십 MB)를 올리려면 호스팅 관리 화면에서 upload_max_filesize·post_max_size 를 늘려 주세요.</p></div>`
    + `<div class="row"><span class="small muted">SMTP 비밀번호, Microsoft 365 클라이언트 암호, API 키는 암호화해 DB(mrs_setting)에 저장합니다.</span><button class="btn primary right" id="st-save">저장</button></div>`;
  // 발송 방식에 맞는 입력 칸만 보이기
  const sync = () => { const m = $("[data-k=mail_method]", v).value; $("[data-grp='일반 SMTP']", v).style.display = m === "smtp" ? "" : "none"; $("[data-grp='Microsoft 365 (OAuth)']", v).style.display = m === "m365" ? "" : "none"; };
  $("[data-k=mail_method]", v).onchange = sync; sync();
  $("#st-save").onclick = (e) => busy(e.target, async () => {
    const values = {};
    $$("[data-k]", v).forEach((i) => { values[i.dataset.k] = i.value; });
    const clear = $$("[data-clear]:checked", v).map((c) => c.dataset.clear);
    await api("settings", { method: "PUT", body: { values, clear } });
    S.catalog = null; toast("설정을 저장했습니다.", "ok"); route();
  });
  $("#tm").onclick = (e) => busy(e.target, async () => {
    if ($$("[data-k]", v).some((i) => !i.dataset.k.endsWith("secret") && !i.dataset.k.endsWith("password") && i.type !== "password" && i.value !== (by[i.dataset.k] || {}).value)) return toast("바뀐 설정을 먼저 [저장]한 뒤 테스트해 주세요.", "err");
    await api("settings/test-mail", { method: "POST", body: { to: $("#tm-to").value } });
    toast("테스트 메일을 보냈습니다.", "ok");
  });
}

boot();
