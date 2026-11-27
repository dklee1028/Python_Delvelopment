"""스토리지(NetApp ONTAP 7-Mode / Cluster-Mode 중심) CLI 로그 파서.

엔지니어가 수집한 콘솔 출력(.txt/.log/.out), 또는 이를 묶은 .zip/.gz 파일을 받아
모델/시리얼/버전, Aggregate·Volume 사용률, 디스크·HA·포트 상태, 이벤트 로그를 추출합니다.
알 수 없는 형식이라도 오류 키워드 스캔은 항상 수행합니다.
"""
from __future__ import annotations

import gzip
import io
import re
import tarfile
import zipfile
from collections import Counter

UNITS = {"B": 1 / 1024**3, "KB": 1 / 1024**2, "MB": 1 / 1024, "GB": 1.0, "TB": 1024.0, "PB": 1024.0**2}
SIZE = r"\d+(?:\.\d+)?\s?[KMGTP]?B"
TEXT_EXT = (".txt", ".log", ".out", ".cfg", ".conf", ".csv", ".xml", ".json", "")


def to_gb(s: str) -> float:
    m = re.match(r"^\s*(\d+(?:\.\d+)?)\s?([KMGTP]?B)\s*$", s.strip(), re.I)
    if not m:
        return 0.0
    return float(m.group(1)) * UNITS[m.group(2).upper()]


def human(gb: float) -> str:
    if gb >= 1024:
        return f"{gb / 1024:,.1f} TB"
    return f"{gb:,.0f} GB" if gb >= 10 else f"{gb:,.1f} GB"


# ───────────────────────── 파일 → 텍스트 ─────────────────────────
def extract_text(name: str, data: bytes, _depth: int = 0) -> str:
    lname = name.lower()
    if _depth > 2:
        return ""
    try:
        if lname.endswith(".zip"):
            out = []
            with zipfile.ZipFile(io.BytesIO(data)) as z:
                for info in z.infolist()[:500]:
                    if info.is_dir() or info.file_size > 50 * 1024 * 1024:
                        continue
                    out.append(f"\n### FILE {info.filename}\n" + extract_text(info.filename, z.read(info), _depth + 1))
            return "\n".join(out)
        if lname.endswith((".tar.gz", ".tgz", ".tar")):
            out = []
            with tarfile.open(fileobj=io.BytesIO(data)) as t:
                for m in t.getmembers()[:500]:
                    if m.isfile() and m.size < 50 * 1024 * 1024:
                        f = t.extractfile(m)
                        if f:
                            out.append(f"\n### FILE {m.name}\n" + extract_text(m.name, f.read(), _depth + 1))
            return "\n".join(out)
        if lname.endswith(".gz"):
            return extract_text(name[:-3], gzip.decompress(data), _depth + 1)
    except Exception as e:  # 손상된 압축 파일
        return f"[압축 해제 실패: {e}]"
    if not lname.endswith(TEXT_EXT) and b"\x00" in data[:4096]:
        return ""  # 바이너리
    for enc in ("utf-8", "cp949", "latin-1"):
        try:
            return data.decode(enc)
        except UnicodeDecodeError:
            continue
    return ""


# ───────────────────────── 파서 ─────────────────────────
RE_VERSION = re.compile(r"NetApp Release\s+([0-9][\w.]*)(\s+7-Mode)?", re.I)
RE_MODEL = re.compile(r"^\s*(?:System\s+)?Model(?:\s+Name)?\s*[:=]\s*(\S+)", re.I | re.M)
RE_SERIAL = re.compile(r"^\s*(?:System\s+)?Serial\s+Number\s*[:=]\s*(\S+)", re.I | re.M)
RE_HOST = re.compile(r"^\s*(?:Hostname|Node(?:\s+Name)?)\s*[:=]\s*(\S+)", re.I | re.M)
RE_NODE_SHOW = re.compile(
    r"^(\S+)\s+(true|false)\s+(true|false)\s+.*?\s((?:FAS|AFF|ASA)[-\w]*\d\w*|[AC]\d{3}\w*)\b", re.I
)
RE_CL_AGGR = re.compile(
    rf"^(\S+)\s+({SIZE})\s+({SIZE})\s+(\d+)%\s+(\S+)\s+(\d+)\s+(\S+)", re.I
)
RE_7_DF = re.compile(rf"^(\S+)\s+({SIZE})\s+({SIZE})\s+({SIZE})\s+(\d+)%", re.I)
RE_7_DF_KB = re.compile(r"^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%")
RE_CL_VOL = re.compile(
    rf"^(\S+)\s+(\S+)\s+(\S+)\s+(online|offline|restricted)\s+(RW|DP|LS|TMP)\s+({SIZE})\s+({SIZE})\s+(\d+)%",
    re.I,
)
RE_INODE = re.compile(r"^(/vol/[^/\s]+/?)\s+\d+\s+\d+\s+(\d+)%", re.M)
RE_PROMPT = re.compile(r"^\s*([\w.\-]+)(?:::?\*?>|\*?>)\s*(.+)$")
RE_DISK_ID = r"(?:\d+\.\d+\.\d+|\d[a-z]\.\d+(?:\.\d+)?|[\w-]+:\d[a-z]\.\d+)"
RE_FAILED_DISK = re.compile(rf"({RE_DISK_ID}).*\b(broken|failed|fail|prefailed)\b", re.I)
RE_SPARE = re.compile(rf"^\s*spare\s+({RE_DISK_ID})|^\s*({RE_DISK_ID})\s+.*\bspare\b", re.I)
RE_FAILOVER = re.compile(r"^(\S+)\s+(\S+)\s+(true|false)\s+(.+)$", re.I)
RE_PORT_DOWN = re.compile(r"^\s*(?:\S+\s+)?((?:e|a)\d+[a-z](?:-\d+)?)\s+\S+\s+\S+\s+down\b", re.I)
RE_EVENT_CL = re.compile(r"\b(EMERGENCY|ALERT|ERROR)\s+([\w.]+)\s*:?\s*(.*)$")
RE_EVENT_7 = re.compile(r"\[([\w-]+):([\w.]+):(emergency|alert|critical|error)\]:?\s*(.*)$", re.I)
RE_LINK_DOWN = re.compile(r"[Ll]ink (?:is )?down on (?:port )?((?:e|a)\d+[a-z](?:-\d+)?)")
RE_PANIC = re.compile(r"\b(PANIC|panic string|Kernel panic)\b")
RE_ASUP = re.compile(r"^\s*(?:State|autosupport\.enable)\s*[:=]?\s*(enable|on|disable|off)", re.I | re.M)


def parse_text(txt: str) -> dict:
    res: dict = {
        "system": {},
        "aggregates": [],
        "volumes": [],
        "disks": {"failed": [], "spare": 0},
        "ha": {},
        "ports_down": [],
        "events": {"emergency": 0, "alert": 0, "error": 0, "panic": 0, "top": []},
        "autosupport": None,
        "lines": txt.count("\n") + 1,
    }
    sysinfo = res["system"]

    if m := RE_VERSION.search(txt):
        sysinfo["os_version"] = m.group(1) + (" 7-Mode" if m.group(2) else "")
    if m := RE_MODEL.search(txt):
        sysinfo["model"] = m.group(1)
    serials = list(dict.fromkeys(RE_SERIAL.findall(txt)))
    if serials:
        sysinfo["serial"] = " / ".join(serials[:2])
    if m := RE_HOST.search(txt):
        sysinfo["hostname"] = m.group(1)
    if m := RE_ASUP.search(txt):
        res["autosupport"] = m.group(1).lower() in ("enable", "on")

    section = ""
    nodes, aggr_seen, vol_seen, failed_seen = [], set(), set(), set()
    ev_counter: Counter = Counter()
    inode_map = {k.strip("/").split("/")[-1]: int(v) for k, v in RE_INODE.findall(txt)}

    for raw in txt.splitlines():
        line = raw.rstrip()
        if not line.strip():
            continue
        if pm := RE_PROMPT.match(line):
            section = pm.group(2).strip().lower()
            if "::" not in line.split(">")[0]:  # 7-Mode 프롬프트 = 노드명
                sysinfo.setdefault("hostname", pm.group(1))
            continue
        if line.startswith("### FILE"):
            section = line.lower()
            continue

        if m := RE_NODE_SHOW.match(line):
            nodes.append(m.group(1))
            sysinfo.setdefault("model", m.group(4))
            continue

        # ── Aggregate ──
        if m := RE_CL_AGGR.match(line):
            name = m.group(1)
            if name not in aggr_seen and name.lower() != "aggregate":
                aggr_seen.add(name)
                total = to_gb(m.group(2))
                avail = to_gb(m.group(3))
                res["aggregates"].append({
                    "node": m.group(7), "name": name, "total_gb": total, "used_gb": max(total - avail, 0),
                    "avail_gb": avail, "pct": int(m.group(4)), "state": m.group(5),
                })
            continue
        if m := RE_CL_VOL.match(line):
            key = m.group(1) + "/" + m.group(2)
            if key not in vol_seen:
                vol_seen.add(key)
                total, avail = to_gb(m.group(6)), to_gb(m.group(7))
                res["volumes"].append({
                    "node": m.group(1), "name": m.group(2), "aggr": m.group(3), "state": m.group(4),
                    "total_gb": total, "used_gb": max(total - avail, 0), "avail_gb": avail,
                    "pct": int(m.group(8)), "inode_pct": inode_map.get(m.group(2)), "protocol": "",
                })
            continue
        m7 = RE_7_DF.match(line)
        kb = False
        if not m7 and (m7 := RE_7_DF_KB.match(line)):
            kb = True
        if m7:
            name = m7.group(1)
            if ".snapshot" in name or name.lower() in ("filesystem", "aggregate"):
                continue
            conv = (lambda s: int(s) / 1024**2) if kb else to_gb
            total, used, avail, pct = conv(m7.group(2)), conv(m7.group(3)), conv(m7.group(4)), int(m7.group(5))
            if name.startswith("/vol/"):
                vname = name.strip("/").split("/")[-1]
                if vname not in vol_seen:
                    vol_seen.add(vname)
                    res["volumes"].append({
                        "node": sysinfo.get("hostname", ""), "name": vname, "aggr": "", "state": "online",
                        "total_gb": total, "used_gb": used, "avail_gb": avail, "pct": pct,
                        "inode_pct": inode_map.get(vname), "protocol": "",
                    })
            elif ("-a" in section or "aggr" in section or name.startswith("aggr")) and name not in aggr_seen:
                aggr_seen.add(name)
                res["aggregates"].append({
                    "node": sysinfo.get("hostname", ""), "name": name, "total_gb": total, "used_gb": used,
                    "avail_gb": avail, "pct": pct, "state": "online",
                })
            continue

        # ── 디스크 ──
        if ("broken" in section or "-f" in section or "failed" in line.lower() or "broken" in line.lower()) and (
            m := RE_FAILED_DISK.search(line)
        ):
            if m.group(1) not in failed_seen:
                failed_seen.add(m.group(1))
                res["disks"]["failed"].append({"disk": m.group(1), "line": line.strip()[:160]})
            continue
        if RE_SPARE.match(line):
            res["disks"]["spare"] += 1
            continue

        # ── HA ──
        if "failover" in section and (m := RE_FAILOVER.match(line)) and m.group(1).lower() != "node":
            res["ha"].setdefault("nodes", []).append(
                {"node": m.group(1), "partner": m.group(2), "takeover_possible": m.group(3).lower() == "true",
                 "state": m.group(4).strip()[:120]}
            )
            continue
        low = line.lower()
        if "cluster enabled" in low or "takeover" in low and "disabled" in low:
            res["ha"]["cf_status"] = line.strip()[:160]
            if "disabled" in low:
                res["ha"]["takeover_disabled"] = True

        # ── 포트 ──
        if (m := RE_PORT_DOWN.match(line)) and ("port" in section or "ifgrp" in section or "network" in section):
            if m.group(1) not in res["ports_down"]:
                res["ports_down"].append(m.group(1))
            continue

        if (m := RE_LINK_DOWN.search(line)) and m.group(1) not in res["ports_down"]:
            res["ports_down"].append(m.group(1))
        # ── 이벤트 ──
        if m := RE_EVENT_CL.search(line):
            sev = m.group(1).lower()
            res["events"][sev] += 1
            ev_counter[(sev, m.group(2))] += 1
            continue
        if m := RE_EVENT_7.search(line):
            sev = m.group(3).lower()
            sev = "alert" if sev == "critical" else sev
            res["events"][sev] += 1
            ev_counter[(sev, m.group(2))] += 1
            continue
        if RE_PANIC.search(line):
            res["events"]["panic"] += 1

    if nodes:
        sysinfo["nodes"] = nodes
        sysinfo.setdefault("hostname", nodes[0])
    ha_nodes = res["ha"].get("nodes")
    if ha_nodes:
        res["ha"]["takeover_possible"] = all(n["takeover_possible"] for n in ha_nodes)
    elif res["ha"].get("takeover_disabled"):
        res["ha"]["takeover_possible"] = False
    res["events"]["top"] = [
        {"severity": s, "event": e, "count": c} for (s, e), c in ev_counter.most_common(12)
    ]
    return res


def parse_file(name: str, data: bytes) -> dict:
    txt = extract_text(name, data)
    parsed = parse_text(txt)
    parsed["file"] = name
    return parsed


def merge(parsed_list: list[dict]) -> dict:
    """같은 장비의 여러 로그 파일을 하나로 합칩니다 (뒤에 온 값이 우선)."""
    out = parse_text("")
    aggr, vols, failed = {}, {}, {}
    for p in parsed_list:
        for k, v in (p.get("system") or {}).items():
            if v:
                out["system"][k] = v
        for a in p.get("aggregates", []):
            aggr[(a.get("node"), a["name"])] = a
        for v in p.get("volumes", []):
            vols[(v.get("node"), v["name"])] = v
        for d in p.get("disks", {}).get("failed", []):
            failed[d["disk"]] = d
        out["disks"]["spare"] = max(out["disks"]["spare"], p.get("disks", {}).get("spare", 0))
        if p.get("ha"):
            out["ha"].update(p["ha"])
        for port in p.get("ports_down", []):
            if port not in out["ports_down"]:
                out["ports_down"].append(port)
        ev = p.get("events", {})
        for k in ("emergency", "alert", "error", "panic"):
            out["events"][k] += ev.get(k, 0)
        out["events"]["top"].extend(ev.get("top", []))
        if p.get("autosupport") is not None:
            out["autosupport"] = p["autosupport"]
        out["lines"] += p.get("lines", 0)
    out["aggregates"] = sorted(aggr.values(), key=lambda a: (-a["pct"], a["name"]))
    out["volumes"] = sorted(vols.values(), key=lambda v: -v["pct"])
    out["disks"]["failed"] = list(failed.values())
    out["events"]["top"] = sorted(out["events"]["top"], key=lambda e: -e["count"])[:12]
    out["files"] = [p.get("file") for p in parsed_list if p.get("file")]
    return out
