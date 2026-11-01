#!/usr/bin/env python3
"""Build docs/API_SUMMARY.json from the live route list plus hand-written metadata.

Method, path, auth, actor, roles and rate limits come from
`php artisan route:list --path=api --json`, so they always match the code.
Purpose, request fields, responses and errors come from
tools/api_summary_meta.json, keyed by controller action (e.g.
"Teller\\TransactionController@withdraw").

The script fails if a route has no metadata or metadata has no route, so a new
endpoint cannot be forgotten.

Usage (from the repository root):
    python3 tools/build_api_summary.py           # write docs/API_SUMMARY.json
    python3 tools/build_api_summary.py --check   # exit 1 if the file is out of date
"""

import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
META = ROOT / "tools" / "api_summary_meta.json"
OUTPUT = ROOT / "docs" / "API_SUMMARY.json"

CONTROLLER_PREFIX = "App\\Http\\Controllers\\API\\"
STAFF_ROLES = ["admin", "supervisor", "teller"]


def run(*command: str) -> str:
    return subprocess.run(command, cwd=ROOT, check=True, capture_output=True, text=True).stdout


def access(middleware: list[str]) -> dict:
    """auth / actor / roles from the route's middleware."""
    if not any(m.startswith("Illuminate\\Auth\\Middleware\\Authenticate") for m in middleware):
        return {"auth": "none", "actor": "public", "roles": []}

    if "App\\Http\\Middleware\\EnsureCustomer" in middleware:
        return {"auth": "bearer", "actor": "customer", "roles": ["customer"]}

    roles = STAFF_ROLES
    for m in middleware:
        if m.startswith("App\\Http\\Middleware\\EnsureStaffRole:"):
            roles = m.split(":", 1)[1].split(",")

    return {"auth": "bearer", "actor": "staff", "roles": roles}


def rate_limits(middleware: list[str], limiters: dict[str, str]) -> list[str]:
    limits = []
    for m in middleware:
        if not m.startswith("Illuminate\\Routing\\Middleware\\ThrottleRequests:"):
            continue
        spec = m.split(":", 1)[1]
        if spec in limiters:
            limits.append(limiters[spec])
        else:
            attempts, minutes = (spec.split(",") + ["1"])[:2]
            limits.append(f"{attempts} per {minutes} minute(s) per user")
    return limits


def build() -> dict:
    meta = json.loads(META.read_text())
    header, actions = meta["header"], meta["actions"]
    limiters = header.pop("rate_limiters")

    routes = json.loads(run("php", "artisan", "route:list", "--path=api", "--json"))
    commit = run("git", "describe", "--always", "--dirty").strip()

    endpoints, missing = [], []
    for route in routes:
        action = route["action"].removeprefix(CONTROLLER_PREFIX)
        if action not in actions:
            missing.append(f"{route['method']} /{route['uri']} ({action})")
            continue

        methods = route["method"].replace("GET|HEAD", "GET").split("|")
        endpoint = {
            "method": methods[0] if len(methods) == 1 else methods,
            "path": "/" + route["uri"],
            **access(route["middleware"]),
            "purpose": actions[action]["purpose"],
        }
        limits = rate_limits(route["middleware"], limiters)
        if limits:
            endpoint["rate_limit"] = limits
        endpoint.update({k: v for k, v in actions[action].items() if k != "purpose"})
        endpoints.append(endpoint)

    used = {route["action"].removeprefix(CONTROLLER_PREFIX) for route in routes}
    unused = sorted(set(actions) - used)

    if missing or unused:
        for line in missing:
            print(f"No metadata for route: {line}", file=sys.stderr)
        for action in unused:
            print(f"Metadata without a route: {action}", file=sys.stderr)
        sys.exit(1)

    return {
        "name": header["name"],
        "source_of_truth": header["source_of_truth"],
        "generated_from_commit": commit,
        **{k: v for k, v in header.items() if k not in ("name", "source_of_truth")},
        "route_count": len(endpoints),
        "endpoints": endpoints,
    }


def main() -> None:
    summary = build()
    text = json.dumps(summary, indent=2, ensure_ascii=False) + "\n"

    if "--check" in sys.argv:
        current = json.loads(OUTPUT.read_text())
        current.pop("generated_from_commit", None)
        summary.pop("generated_from_commit", None)
        if current != summary:
            print("docs/API_SUMMARY.json is out of date; run tools/build_api_summary.py", file=sys.stderr)
            sys.exit(1)
        print("docs/API_SUMMARY.json is up to date.")
        return

    OUTPUT.write_text(text)
    print(f"Wrote {OUTPUT.relative_to(ROOT)} ({summary['route_count']} endpoints).")


if __name__ == "__main__":
    main()
