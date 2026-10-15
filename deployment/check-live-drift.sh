#!/usr/bin/env bash
# Refuse a production deploy that would wipe out changes made on live.
#
# A deploy replaces the managed folders whole (see deploy.sh). Anything changed
# on the server since the last deploy - a hot-fix made over SSH, a file edited
# in an emergency - is lost unless it is also in the build. On 14 Oct 2026 the
# live mu-plugins had moved well ahead of main; a routine deploy would have
# taken the lead-tracking fixes off a paid campaign without anyone noticing.
#
# After every deploy, deploy.sh stores a checksum list of what it put on live
# (the manifest, next to the document root, never inside it). Before the next
# one this compares live against it. A live file is fine when it is
#   - unchanged since the last deploy (same as the manifest), or
#   - already in the build exactly as it is on live.
# Anything else - changed, added or deleted on live and not in the build - stops
# the deploy with the list of files. Bring those changes into the repository,
# or re-run with allow_live_drift (ALLOW_LIVE_DRIFT=true) to discard them.
#
# Usage: check-live-drift.sh production   (run from the repository root)

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

configure_environment "${1:?usage: check-live-drift.sh production}"
[[ "$ENVIRONMENT" == "production" ]] || { ok "drift check: production only, skipped for ${ENVIRONMENT}"; exit 0; }

cd "$REPO_ROOT"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"; cleanup_lib_cm' EXIT

log "Checking live for changes made since the last deploy"

build_sums > "$WORK/build"
live_sums > "$WORK/live" || die "Could not read checksums from live."
MANIFEST="$(live_manifest_path)"

if ! remote "cat '${MANIFEST}'" > "$WORK/manifest" 2>/dev/null || [[ ! -s "$WORK/manifest" ]]; then
  # No record of the last deploy: only files identical to the build are known safe.
  warn "no deploy manifest at ${MANIFEST} - comparing live with the build only"
  : > "$WORK/manifest"
fi

# One line per drifted file: "<what>  <path>".
# md5sum lines are "<32 hex>  <path>"; the path starts at column 35 (spaces allowed).
awk '
  { f = substr($0, 35); h = substr($0, 1, 32) }
  FILENAME == ARGV[1] { m[f] = h; next }
  FILENAME == ARGV[2] { b[f] = h; next }
  {
    l[f] = h
    if (f in m && m[f] == h) next             # unchanged since the last deploy
    if (f in b && b[f] == h) next             # live version is already in the build
    print ((f in m) ? "changed on live, not in the build" : "added on live, not in the build") "  " f
  }
  END {
    for (p in m) if (!(p in l) && (p in b)) print "deleted on live, the build would put it back  " p
  }
' "$WORK/manifest" "$WORK/build" "$WORK/live" | LC_ALL=C sort > "$WORK/drift"

if [[ ! -s "$WORK/drift" ]]; then
  ok "live matches the last deploy or the build ($(wc -l < "$WORK/live") files)"
  exit 0
fi

printf '\n%s\n\n' "Live has changes that this deploy would overwrite:" >&2
sed 's/^/    /' "$WORK/drift" >&2
printf '\n' >&2
if [[ "${ALLOW_LIVE_DRIFT:-false}" == "true" ]]; then
  warn "allow_live_drift is set - continuing; the $(wc -l < "$WORK/drift") change(s) above will be replaced"
  exit 0
fi
die "$(wc -l < "$WORK/drift") file(s) on live differ from both the last deploy and this build. Commit the live versions to the repository first (copy them from the server), or re-run with allow_live_drift to discard them."
