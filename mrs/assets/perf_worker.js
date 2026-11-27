/* MRS – 성능 아카이브(perf_archive.7z) 분석 워커
   웹호스팅 서버(PHP)에는 7z 해제 기능이 없으므로, 엔지니어 PC의 브라우저에서 7-Zip(WASM)으로 풀고
   텍스트/CSV/JSON/XML 형태의 성능 카운터를 찾아 통계(평균·95%·최대)로 요약합니다. 원본 데이터는 서버로 보내지 않고 요약만 보냅니다. */
"use strict";
importScripts("vendor/7z/7zz.umd.js");

const post = (type, data) => self.postMessage(Object.assign({ type }, data));
const MAX_TEXT = 64 * 1024 * 1024;      // 파일 하나에서 읽는 최대 텍스트
const MAX_SAMPLES = 20000;              // 카운터 하나당 보관할 값 수
const ARCH = /\.(7z|zip|gz|tgz|tar|bz2|xz|tbz2|txz)$/i;

let SZ = null;
async function sevenZip() {
  if (!SZ) SZ = await SevenZip({ locateFile: (p) => "vendor/7z/" + p, print: () => {}, printErr: () => {} });
  return SZ;
}
function walk(fs, dir, out) {
  for (const n of fs.readdir(dir)) {
    if (n === "." || n === "..") continue;
    const p = dir + "/" + n; const st = fs.stat(p);
    if (fs.isDir(st.mode)) walk(fs, p, out); else out.push({ path: p, size: st.size });
  }
  return out;
}

// ───── 지표 모으기 ─────
const M = new Map();   // key → {object, counter, unit, vals[], n, sum, max, min, inst: Map}
const files = []; let textFiles = 0, bytesTotal = 0; const formats = new Set(); const nodes = new Set();
let pFrom = "", pTo = ""; let samples = ""; let seen = 0;

function addVal(object, counter, inst, v, unit) {
  if (!isFinite(v)) return;
  const key = (object || "") + "|" + counter + "|" + (unit || "");
  let m = M.get(key);
  if (!m) { m = { object: object || "", counter, unit: unit || "", vals: [], n: 0, sum: 0, max: -Infinity, min: Infinity, inst: new Map() }; M.set(key, m); }
  m.n++; m.sum += v; if (v > m.max) m.max = v; if (v < m.min) m.min = v;
  if (m.vals.length < MAX_SAMPLES) m.vals.push(v); else { const j = Math.floor(Math.random() * m.n); if (j < MAX_SAMPLES) m.vals[j] = v; }
  if (inst) { let s = m.inst.get(inst); if (!s) { if (m.inst.size > 2000) return; s = { n: 0, sum: 0, max: -Infinity }; m.inst.set(inst, s); } s.n++; s.sum += v; if (v > s.max) s.max = v; }
}
function seeTime(t) { if (!t) return; if (!pFrom || t < pFrom) pFrom = t; if (!pTo || t > pTo) pTo = t; }
const TS = /\b(20\d\d)[-\/](\d\d)[-\/](\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/g;
function scanTimes(txt) {
  const head = txt.slice(0, 2e6), tail = txt.length > 2e6 ? txt.slice(-2e6) : "";
  for (const part of [head, tail]) { let m; TS.lastIndex = 0; let k = 0; while ((m = TS.exec(part)) && k++ < 50000) seeTime(`${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:${m[6] || "00"}`); }
}
function epochStr(n) { const ms = n > 1e12 ? n : n * 1000; const d = new Date(ms); return isNaN(d) ? "" : new Date(ms + 9 * 3600e3).toISOString().slice(0, 19).replace("T", " "); }
const num = (s) => { if (s === undefined || s === null) return NaN; const t = String(s).trim().replace(/,/g, ""); return t === "" ? NaN : Number(t); };

function parseCsv(txt, name) {
  const lines = txt.split(/\r?\n/); if (lines.length < 3) return 0;
  const d = [",", "\t", ";"].sort((a, b) => lines[0].split(b).length - lines[0].split(a).length)[0];
  const hdr = lines[0].split(d).map((h) => h.replace(/^"|"$/g, "").trim()); if (hdr.length < 2) return 0;
  const tcol = hdr.findIndex((h) => /^(time|timestamp|date|datetime|ts|epoch)$/i.test(h));
  const icol = hdr.findIndex((h) => /^(instance|instance_name|name|node|volume|lun|aggr|aggregate|disk|port)$/i.test(h));
  const obj = name.replace(/^.*\//, "").replace(/\.(csv|tsv|txt)$/i, "");
  let c = 0;
  for (let i = 1; i < lines.length; i++) {
    const r = lines[i].split(d); if (r.length !== hdr.length) continue;
    if (tcol >= 0) { const t = r[tcol].replace(/^"|"$/g, ""); const n = num(t); seeTime(isFinite(n) && n > 1e9 ? epochStr(n) : t.slice(0, 19).replace("T", " ")); }
    const inst = icol >= 0 ? r[icol].replace(/^"|"$/g, "") : "";
    for (let j = 0; j < hdr.length; j++) { if (j === tcol || j === icol) continue; const v = num(r[j].replace(/^"|"$/g, "").replace(/%$/, "")); if (isFinite(v)) { addVal(obj, hdr[j], inst, v, /%/.test(r[j]) ? "%" : ""); c++; } }
  }
  return c;
}
// object:instance:counter:value[unit]  (7-Mode stats / perfstat 형식)
const COLON = /^\s*([A-Za-z_][\w\-]*):([^:\n]*):([A-Za-z_][\w\-.]*):\s*(-?[\d.,]+)\s*([a-z%\/]*)/i;
// statistics show 블록: Object: x / Instance: y / counter value
const OBJ = /^\s*Object:\s*(\S+)/i, INST = /^\s*Instance:\s*(.+?)\s*$/i, CV = /^\s+([a-z_][\w\-]*)\s+(-?[\d.,]+)\s*([a-z%\/]*)\s*$/i;
function parseText(txt, name) {
  let c = 0, obj = "", inst = "";
  const lines = txt.split(/\r?\n/);
  for (const ln of lines) {
    let m = COLON.exec(ln);
    if (m) { const v = num(m[4]); if (isFinite(v)) { addVal(m[1], m[3], m[2], v, m[5]); c++; } continue; }
    if ((m = OBJ.exec(ln))) { obj = m[1]; continue; }
    if ((m = INST.exec(ln))) { inst = m[1]; continue; }
    if (obj && (m = CV.exec(ln))) { const v = num(m[2]); if (isFinite(v)) { addVal(obj, m[1], inst, v, m[3]); c++; } }
    const nm = /^\s*Node:\s*(\S+)/i.exec(ln); if (nm && nodes.size < 8) nodes.add(nm[1]);
  }
  return c;
}
function parseJson(txt, name) {
  let j; try { j = JSON.parse(txt); } catch (e) { return 0; }
  let c = 0; const obj = name.replace(/^.*\//, "").replace(/\.json$/i, "");
  const visit = (o, path, inst, depth) => {
    if (depth > 12 || o === null) return;
    if (Array.isArray(o)) { for (const x of o.slice(0, 200000)) visit(x, path, inst, depth + 1); return; }
    if (typeof o !== "object") return;
    // {name: "counter", value: n} 형식
    if (typeof o.name === "string" && (typeof o.value === "number" || typeof o.value === "string") && isFinite(num(o.value))) { addVal(path || obj, o.name, inst, num(o.value), o.unit || ""); c++; return; }
    const ni = typeof o.instance_name === "string" ? o.instance_name : typeof o.name === "string" ? o.name : inst;
    for (const [k, v] of Object.entries(o)) {
      if (/^(timestamp|time|ts)$/i.test(k)) { const n = num(v); seeTime(isFinite(n) && n > 1e9 ? epochStr(n) : String(v).slice(0, 19).replace("T", " ")); continue; }
      if (typeof v === "number") { addVal(path || obj, k, ni, v, ""); c++; }
      else if (typeof v === "object") visit(v, /^(counters|records|data|values)$/i.test(k) ? path : k, ni, depth + 1);
    }
  };
  visit(j, "", "", 0);
  return c;
}
function parseXml(txt, name) {
  let c = 0; const obj = name.replace(/^.*\//, "").replace(/\.xml$/i, "");
  const re = /<(?:counter|name)[^>]*>\s*([A-Za-z_][\w\-.]*)\s*<\/(?:counter|name)>\s*<value[^>]*>\s*(-?[\d.]+)\s*<\/value>|name="([A-Za-z_][\w\-.]*)"[^>]*value="(-?[\d.]+)"/g;
  let m, k = 0;
  while ((m = re.exec(txt)) && k++ < 2e6) { const n = m[1] || m[3], v = num(m[2] || m[4]); if (isFinite(v)) { addVal(obj, n, "", v, ""); c++; } }
  return c;
}
// ───── AutoSupport 패키지(성능 아카이브와 함께 오는 시스템 정보) ─────
const A = { history: {}, recent_failures: [], spare: null, failed_disks: [], orphaned: 0, raid_bad: [], presets: 0 };
let asupSeen = false, asupPeriod = null;
const us2iso = (s) => { const m = /(\d\d)\/(\d\d)\/(\d{4}) (\d\d:\d\d:\d\d)/.exec(s || ""); return m ? `${m[3]}-${m[1]}-${m[2]} ${m[4]}` : ""; };
function asupFile(base, txt) {
  if (/^X-HEADER-DATA\.TXT$/i.test(base)) {
    asupSeen = true;
    const h = {}; txt.split(/\r?\n/).forEach((l) => { const m = /^X-Netapp-asup-([\w-]+):\s*(.*)$/i.exec(l); if (m) h[m[1].toLowerCase()] = m[2].trim(); });
    Object.assign(A, { hostname: h.hostname || "", model: h["model-name"] || "", serial: h["serial-num"] || "", os: h["os-version"] || "", cluster: h["cluster-name"] || "", partner: h["partner-hostname"] || "", subject: h.subject || "" });
    const m = /(\d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d)\s+to\s+(\d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d)/.exec(h.subject || "");
    if (m) asupPeriod = { from: us2iso(m[1]), to: us2iso(m[2]) };
    if (A.hostname) nodes.add(A.hostname);
    return "asup-header";
  }
  if (/^autosupport_history\.xml$/i.test(base)) {
    asupSeen = true;
    for (const row of txt.split(/<asup:ROW/).slice(1)) {
      const g = (t) => { const m = new RegExp("<" + t + ">([^<]*)</" + t + ">").exec(row); return m ? m[1] : ""; };
      const st = g("status"); if (!st) continue;
      A.history[st] = (A.history[st] || 0) + 1;
      if (/failed/.test(st) && A.recent_failures.length < 5) A.recent_failures.push({ at: g("last-update"), status: st, dest: g("destination"), subject: g("subject") });
    }
    return "asup-history";
  }
  if (/^SYSCONFIG-R\.txt$/i.test(base)) {
    asupSeen = true; let sp = 0;
    for (const l of txt.split(/\r?\n/)) {
      if (/^\s*spare\s/i.test(l)) sp++;
      const f = /^\s*(failed|broken)\s+(\S+)/i.exec(l); if (f) A.failed_disks.push(f[2]);
      if (/^\s*orphaned\s/i.test(l)) A.orphaned++;
      const ag = /^Aggregate (\S+) \(([^)]*)\)/i.exec(l); if (ag && /failed|offline|degraded|reconstruct|partial/i.test(ag[2])) A.raid_bad.push(`Aggregate ${ag[1]} (${ag[2].replace(/,\s*(raid_dp|raid4|raid_tec|fast zeroed|block checksums?)/gi, "")})`);
      const r = /RAID group (\S+) \((?!normal)([^,)]+)/i.exec(l); if (r) A.raid_bad.push(`RAID group ${r[1]} (${r[2]})`);
      const ms = /Raid group is missing (\d+) disks?/i.exec(l); if (ms) A.raid_bad.push(`디스크 ${ms[1]}EA 누락`);
    }
    A.spare = (A.spare || 0) + sp;
    return "sysconfig-r";
  }
  if (/^cm-asup-preset-detail\.xml$/i.test(base)) { A.presets += (txt.match(/<asup:ROW/g) || []).length; return "perf-preset(카운터 정의)"; }
  return null;
}
function handleText(path, bytes) {
  const name = path.replace(/^\/x\d*\//, "");
  const txt = new TextDecoder("utf-8", { fatal: false }).decode(bytes.length > MAX_TEXT ? bytes.subarray(0, MAX_TEXT) : bytes);
  textFiles++;
  const special = asupFile(name.replace(/^.*\//, ""), txt); if (special) return special;
  scanTimes(txt);
  let c = 0, kind = "text";
  const head = txt.slice(0, 200).trim();
  if (/\.json$/i.test(name) || /^[\[{]/.test(head)) { c = parseJson(txt, name); kind = "json"; }
  else if (/\.xml$/i.test(name) || /^<\?xml|^</.test(head)) { c = parseXml(txt, name); kind = "xml"; }
  else if (/\.(csv|tsv)$/i.test(name)) { c = parseCsv(txt, name); kind = "csv"; }
  if (!c) { const c2 = parseText(txt, name); if (c2) { c = c2; kind = "counters"; } }
  if (!c && kind === "text") { const c3 = parseCsv(txt, name); if (c3) { c = c3; kind = "csv"; } }
  if (c) formats.add(kind);
  else if (samples.length < 20000) samples += `\n### ${name}\n` + txt.split(/\r?\n/).slice(0, 40).map((l) => l.slice(0, 200)).join("\n").slice(0, 1500);
  return kind + (c ? "" : "(지표 없음)");
}

// ───── 결과 정리 ─────
function groupOf(m) {
  const c = (m.object + ":" + m.counter).toLowerCase();
  if (/latency/.test(c)) return "latency";
  if (/cpu|processor/.test(c) && /busy|util|cpu$|_cpu|cpu_/.test(c)) return "cpu";
  if (/disk.*(busy|util)|(busy|util).*disk/.test(c)) return "disk";
  if (/(^|[_:])(total_|read_|write_|other_|nfs_|cifs_|fcp_|iscsi_)?ops$|iops|_ops$/.test(c)) return "ops";
  if (/data|throughput|bytes|_kb|_mb|kbps|mbps/.test(c)) return "throughput";
  if (/net|nic|packet|recv|sent|link/.test(c)) return "net";
  return "other";
}
function pct(sorted, p) { if (!sorted.length) return null; const i = Math.min(sorted.length - 1, Math.max(0, Math.ceil(p * sorted.length) - 1)); return sorted[i]; }
function finish(archiveName) {
  const out = [];
  for (const m of M.values()) {
    if (m.n < 1) continue;
    const g = groupOf(m); const s = m.vals.slice().sort((a, b) => a - b);
    let unit = m.unit;
    const med = pct(s, 0.5);
    if (!unit) unit = g === "cpu" || g === "disk" ? (m.max <= 100.5 ? "%" : "") : g === "latency" ? (med >= 100 ? "us" : "ms") : g === "ops" ? "/s" : "";
    const tops = [...m.inst.entries()].map(([name, x]) => ({ name, avg: x.sum / x.n, max: x.max })).sort((a, b) => b.avg - a.avg).slice(0, 5);
    out.push({ group: g, object: m.object, counter: m.counter, unit, n: m.n, avg: m.sum / m.n, p95: pct(s, 0.95), max: m.max, min: m.min, instances: m.inst.size, top: tops });
  }
  const pri = { cpu: 0, latency: 1, ops: 2, throughput: 3, disk: 4, net: 5, other: 6 };
  out.sort((a, b) => (pri[a.group] - pri[b.group]) || (b.n - a.n));
  // 그룹별 상한 (기타는 적게)
  const cnt = {}; const metrics = out.filter((x) => (cnt[x.group] = (cnt[x.group] || 0) + 1) <= (x.group === "other" ? 25 : 30)).slice(0, 150);
  const asup = asupSeen ? Object.assign({}, A, { counter_data: metrics.length > 0 }) : null;
  return { archive: archiveName, files_total: files.length, text_files: textFiles, bytes_total: bytesTotal, formats: [...formats], period: asupPeriod || { from: pFrom, to: pTo }, nodes: [...nodes],
    metrics, files: files.slice(0, 60), samples: metrics.length ? "" : samples, asup,
    note: metrics.length ? "" : asup ? `성능 카운터 값은 없고 카운터 정의(${A.presets}개)와 시스템 정보만 들어 있습니다. 시스템 상태 위주로 분석합니다.` : "압축 안의 파일에서 텍스트/CSV/JSON/XML 형식의 성능 카운터를 찾지 못했습니다. (NetApp 전용 바이너리 형식일 수 있습니다)" };
}

self.onmessage = async (ev) => {
  const { file } = ev.data;
  try {
    post("progress", { stage: "7-Zip 준비", pct: 2 });
    const sz = await sevenZip(); const fs = sz.FS;
    post("progress", { stage: "파일 읽는 중", pct: 5 });
    const buf = new Uint8Array(await file.arrayBuffer());
    const root = "/a" + Date.now(); fs.mkdir(root);
    const arc = root + "/" + file.name.replace(/[^\w.\-]/g, "_"); fs.writeFile(arc, buf);
    let queue = [{ path: arc, depth: 0 }]; let n = 0;
    while (queue.length) {
      const { path, depth } = queue.shift();
      const outDir = root + "/x" + (n++); fs.mkdir(outDir);
      post("progress", { stage: "압축 푸는 중 " + path.replace(/^.*\//, ""), pct: Math.min(40, 8 + n * 2) });
      try { sz.callMain(["x", "-y", "-bso0", "-bsp0", "-o" + outDir, path]); } catch (e) { /* 일부 손상돼도 계속 */ }
      try { fs.unlink(path); } catch (e) {}
      const list = walk(fs, outDir, []);
      for (const f of list) {
        if (ARCH.test(f.path) && depth < 3) { queue.push({ path: f.path, depth: depth + 1 }); continue; }
        files.push({ path: f.path.replace(/^\/a\d+\/x\d+\//, ""), size: f.size, kind: "" });
        bytesTotal += f.size;
      }
      // 이 단계에서 나온 일반 파일을 바로 분석하고 지워 메모리를 아낍니다
      for (const f of list) {
        if (ARCH.test(f.path) && depth < 3) continue;
        const rec = files.find((x) => x.path === f.path.replace(/^\/a\d+\/x\d+\//, "") && !x.kind);
        try {
          const data = fs.readFile(f.path);
          const bin = data.subarray(0, 8192).some((b) => b === 0);
          if (rec) rec.kind = bin ? "binary" : handleText(f.path, data);
          fs.unlink(f.path);
        } catch (e) { if (rec) rec.kind = "error"; }
        if (++seen % 20 === 0) post("progress", { stage: `파일 분석 중 (${seen}개)`, pct: Math.min(95, 40 + seen) });
      }
    }
    post("done", { summary: finish(file.name) });
  } catch (e) {
    post("error", { message: (e && e.message) || String(e) });
  }
};
