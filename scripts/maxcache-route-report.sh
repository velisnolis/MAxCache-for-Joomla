#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat >&2 <<'USAGE'
Usage:
  scripts/maxcache-route-report.sh --base-url URL --routes FILE [options]

Options:
  --cache-root DIR       Static cache root. Default: ./maxcache
  --purge-cmd COMMAND    Optional explicit purge command to run between pre/post checks.
  --token-regex REGEX    Optional Perl regex with one capture group for CSRF tokens.
  --curl-extra ARGS      Extra curl args as one shell word string, e.g. '--resolve host:443:IP'.

The script is read-only unless --purge-cmd is provided. It reports whether each
route returns HTTP successfully, whether MAxCache debug headers are visible,
whether matching static files exist, and whether Joomla-style form token names
change across two uncached fetches. The verdict column is a per-phase hint; compare
pre and post rows before declaring a migrated route healthy.
USAGE
}

base_url=''
routes_file=''
cache_root='./maxcache'
purge_cmd=''
token_regex='name=["'\'']([a-f0-9]{32})["'\''][^>]*value=["'\'']1["'\'']'
curl_extra=''

while (($#)); do
  case "$1" in
    --base-url)
      base_url="${2:-}"
      shift 2
      ;;
    --routes)
      routes_file="${2:-}"
      shift 2
      ;;
    --cache-root)
      cache_root="${2:-}"
      shift 2
      ;;
    --purge-cmd)
      purge_cmd="${2:-}"
      shift 2
      ;;
    --token-regex)
      token_regex="${2:-}"
      shift 2
      ;;
    --curl-extra)
      curl_extra="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 2
      ;;
  esac
done

if [[ -z "$base_url" || -z "$routes_file" ]]; then
  usage
  exit 2
fi

if [[ ! -f "$routes_file" ]]; then
  echo "Routes file not found: $routes_file" >&2
  exit 2
fi

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 2
  fi
}

require_cmd curl
require_cmd perl

base_url="${base_url%/}"
host="$(printf '%s\n' "$base_url" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://([^/:]+).*#\1#')"

fetch_headers() {
  local url="$1"
  # shellcheck disable=SC2086
  curl -ksS -D - -o /dev/null --max-time 20 $curl_extra "$url"
}

fetch_body() {
  local url="$1"
  local sep='?'

  [[ "$url" == *\?* ]] && sep='&'

  # shellcheck disable=SC2086
  curl -ksS --max-time 20 $curl_extra "${url}${sep}maxcache_probe=$(date +%s%N)"
}

status_from_headers() {
  awk 'tolower($0) ~ /^http\// {code=$2} END {print code+0}'
}

header_value() {
    local name="$1"
    local lower_name

    lower_name="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"
    awk -v n="$lower_name" 'index(tolower($0), n ":") == 1 {sub("^[^:]+:[ \t]*", ""); sub("\r$", ""); print; exit}'
}

token_hash() {
  TOKEN_REGEX="$token_regex" perl -0777 -ne 'BEGIN { $re = $ENV{TOKEN_REGEX} } if (/$re/i) { print $1 }'
}

route_verdict() {
  local phase="$1"
  local status="$2"
  local state="$3"
  local reason="$4"
  local files="$5"
  local token_changed="$6"

  if [[ "$token_changed" == "no" ]]; then
    echo "csrf-token-stale"
    return
  fi

  if [[ "$token_changed" == "yes" && "$files" == "0" ]]; then
    echo "csrf-token-fresh"
    return
  fi

  if [[ "$state" == "bypass" && "$reason" =~ ^(excluded-url|excluded-menu-item|pagecache-plugin|bypass-cookie)$ && "$files" == "0" ]]; then
    echo "excluded-ok"
    return
  fi

  if [[ "$status" == "404" && "$files" == "0" ]]; then
    if [[ "$phase" == "post" ]]; then
      echo "live-route-missing-after-purge"
    else
      echo "live-route-missing"
    fi
    return
  fi

  if [[ "$status" =~ ^2[0-9][0-9]$ && "$files" != "0" ]]; then
    echo "cache-present-verify-post-purge"
    return
  fi

  if [[ "$status" =~ ^2[0-9][0-9]$ && "$files" == "0" ]]; then
    echo "live-ok"
    return
  fi

  if [[ "$status" =~ ^3[0-9][0-9]$ ]]; then
    echo "redirect-check-target"
    return
  fi

  echo "review"
}

cache_file_count() {
  local route="$1"
  local clean="${route%%\?*}"
  local dir

  clean="/${clean#/}"
  clean="${clean%/}"

  if [[ "$clean" == "/" || -z "$clean" ]]; then
    dir="${cache_root%/}/${host}"
  else
    dir="${cache_root%/}/${host}${clean}"
  fi

  if [[ ! -d "$dir" ]]; then
    echo 0
    return
  fi

  find "$dir" -maxdepth 1 -type f -name 'index*.html*' | wc -l | tr -d ' '
}

report_phase() {
  local phase="$1"

  while IFS= read -r route || [[ -n "$route" ]]; do
    route="${route%%#*}"
    route="$(printf '%s' "$route" | xargs)"
    [[ -z "$route" ]] && continue

    [[ "$route" == /* ]] || route="/$route"

    local url="${base_url}${route}"
    local headers status state reason files body1 body2 token1 token2 changed verdict

    headers="$(fetch_headers "$url" || true)"
    status="$(printf '%s\n' "$headers" | status_from_headers)"
    state="$(printf '%s\n' "$headers" | header_value 'X-MAxCache-State')"
    reason="$(printf '%s\n' "$headers" | header_value 'X-MAxCache-Reason')"
    files="$(cache_file_count "$route")"

    body1="$(fetch_body "$url" || true)"
    body2="$(fetch_body "$url" || true)"
    token1="$(printf '%s' "$body1" | token_hash || true)"
    token2="$(printf '%s' "$body2" | token_hash || true)"
    changed=''

    if [[ -n "$token1" || -n "$token2" ]]; then
      [[ "$token1" != "$token2" ]] && changed='yes' || changed='no'
    fi

    verdict="$(route_verdict "$phase" "$status" "$state" "$reason" "$files" "$changed")"

    printf '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' \
      "$phase" "$route" "$status" "$state" "$reason" "$files" "$token1" "$token2" "$changed" "$verdict"
  done < "$routes_file"
}

printf 'phase,route,status,maxcache_state,maxcache_reason,cache_files,token_1,token_2,token_changed,verdict\n'
report_phase pre

if [[ -n "$purge_cmd" ]]; then
  echo "# running purge command: $purge_cmd" >&2
  eval "$purge_cmd"
  report_phase post
else
  echo "# no purge command provided; post-purge phase skipped" >&2
fi
