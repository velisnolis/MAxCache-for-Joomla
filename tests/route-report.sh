#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

mkdir -p "$tmp_dir/bin"

cat > "$tmp_dir/bin/curl" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail

headers=0
url=''

for argument in "$@"; do
  [[ "$argument" == "-D" ]] && headers=1
  [[ "$argument" == http://* || "$argument" == https://* ]] && url="$argument"
done

route="${url#*://}"
route="/${route#*/}"
route="${route%%\?maxcache_probe=*}"
route="${route%%&maxcache_probe=*}"

if [[ "$headers" == "1" ]]; then
  case "$route" in
    /excluded)
      printf 'HTTP/1.1 200 OK\r\nX-MAxCache-State: bypass\r\nX-MAxCache-Reason: excluded-url\r\n\r\n'
      ;;
    /redirect)
      printf 'HTTP/1.1 301 Moved Permanently\r\nLocation: https://example.test/canonical\r\n\r\n'
      ;;
    /missing)
      printf 'HTTP/1.1 404 Not Found\r\n\r\n'
      ;;
    /post-missing)
      if [[ -f "$MOCK_PHASE_FILE" ]]; then
        printf 'HTTP/1.1 404 Not Found\r\n\r\n'
      else
        printf 'HTTP/1.1 200 OK\r\n\r\n'
      fi
      ;;
    *)
      printf 'HTTP/1.1 200 OK\r\n\r\n'
      ;;
  esac
  exit 0
fi

case "$route" in
  /token)
    count=0
    [[ -f "$MOCK_COUNTER" ]] && count="$(<"$MOCK_COUNTER")"
    count=$((count + 1))
    printf '%s' "$count" > "$MOCK_COUNTER"

    if ((count % 2)); then
      token='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    else
      token='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    fi

    printf '<input type="hidden" name="%s" value="1">' "$token"
    ;;
  /stale)
    printf '<input type="hidden" name="cccccccccccccccccccccccccccccccc" value="1">'
    ;;
  /missing-first-token|/missing-second-token)
    counter_suffix="${route#/}"
    counter_file="${MOCK_COUNTER}-${counter_suffix}"
    count=0
    [[ -f "$counter_file" ]] && count="$(<"$counter_file")"
    count=$((count + 1))
    printf '%s' "$count" > "$counter_file"

    if [[ "$route" == "/missing-first-token" && "$count" == "2" ]] \
      || [[ "$route" == "/missing-second-token" && "$count" == "1" ]]; then
      printf '<input type="hidden" name="dddddddddddddddddddddddddddddddd" value="1">'
    else
      printf '<html>form token missing</html>'
    fi
    ;;
  *)
    printf '<html>fixture</html>'
    ;;
esac
MOCK

chmod +x "$tmp_dir/bin/curl"
printf '%s\n' '/excluded' '/token' > "$tmp_dir/safe-routes.txt"
printf '%s\n' '/missing' '/stale' '/redirect' > "$tmp_dir/risky-routes.txt"
printf '%s\n' '/missing-first-token' '/missing-second-token' > "$tmp_dir/missing-token-routes.txt"
printf '%s\n' '/cached' > "$tmp_dir/cached-route.txt"
printf '%s\n' '/post-missing' > "$tmp_dir/post-missing-route.txt"

mkdir -p "$tmp_dir/cache/example.test/cached"
printf '<html>cached</html>' > "$tmp_dir/cache/example.test/cached/index-https.html"

cat > "$tmp_dir/purge-fixture.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
rm -rf "$tmp_dir/cache/example.test/cached"
touch "$tmp_dir/post-phase"
EOF
chmod +x "$tmp_dir/purge-fixture.sh"

safe_output="$(
  PATH="$tmp_dir/bin:$PATH" \
  MOCK_COUNTER="$tmp_dir/token-counter" \
  MOCK_PHASE_FILE="$tmp_dir/post-phase" \
  "$root_dir/scripts/maxcache-route-report.sh" \
    --base-url https://example.test \
    --routes "$tmp_dir/safe-routes.txt" \
    --cache-root "$tmp_dir/cache" \
    --fail-on-risk 2>&1
)"

grep -q 'excluded-ok' <<<"$safe_output"
grep -q 'csrf-token-fresh' <<<"$safe_output"

set +e
missing_token_output="$(
  PATH="$tmp_dir/bin:$PATH" \
  MOCK_COUNTER="$tmp_dir/token-counter" \
  MOCK_PHASE_FILE="$tmp_dir/post-phase" \
  "$root_dir/scripts/maxcache-route-report.sh" \
    --base-url https://example.test \
    --routes "$tmp_dir/missing-token-routes.txt" \
    --cache-root "$tmp_dir/cache" \
    --fail-on-risk 2>&1
)"
missing_token_exit=$?
set -e

if [[ "$missing_token_exit" != "1" ]]; then
  echo "Expected a missing token probe to exit 1, got $missing_token_exit" >&2
  echo "$missing_token_output" >&2
  exit 1
fi

if grep -q 'csrf-token-fresh' <<<"$missing_token_output"; then
  echo "A route with one missing token probe must not be reported as csrf-token-fresh" >&2
  echo "$missing_token_output" >&2
  exit 1
fi

if [[ "$(grep -c 'csrf-token-missing' <<<"$missing_token_output")" != "2" ]]; then
  echo "Expected both incomplete token probes to report csrf-token-missing" >&2
  echo "$missing_token_output" >&2
  exit 1
fi

set +e
risky_output="$(
  PATH="$tmp_dir/bin:$PATH" \
  MOCK_COUNTER="$tmp_dir/token-counter" \
  MOCK_PHASE_FILE="$tmp_dir/post-phase" \
  "$root_dir/scripts/maxcache-route-report.sh" \
    --base-url https://example.test \
    --routes "$tmp_dir/risky-routes.txt" \
    --cache-root "$tmp_dir/cache" \
    --fail-on-risk 2>&1
)"
risky_exit=$?
set -e

if [[ "$risky_exit" != "1" ]]; then
  echo "Expected --fail-on-risk to exit 1, got $risky_exit" >&2
  echo "$risky_output" >&2
  exit 1
fi

grep -q 'live-route-missing' <<<"$risky_output"
grep -q 'csrf-token-stale' <<<"$risky_output"
grep -q 'https://example.test/canonical' <<<"$risky_output"
grep -q 'redirect-check-target' <<<"$risky_output"

post_safe_output="$(
  PATH="$tmp_dir/bin:$PATH" \
  MOCK_COUNTER="$tmp_dir/token-counter" \
  MOCK_PHASE_FILE="$tmp_dir/post-phase" \
  "$root_dir/scripts/maxcache-route-report.sh" \
    --base-url https://example.test \
    --routes "$tmp_dir/cached-route.txt" \
    --cache-root "$tmp_dir/cache" \
    --purge-cmd "$tmp_dir/purge-fixture.sh" \
    --fail-on-risk 2>&1
)"

grep -q 'cache-present-verify-post-purge' <<<"$post_safe_output"
grep -q 'post,/cached,200' <<<"$post_safe_output"
grep -q 'live-ok' <<<"$post_safe_output"

rm -f "$tmp_dir/post-phase"

set +e
post_missing_output="$(
  PATH="$tmp_dir/bin:$PATH" \
  MOCK_COUNTER="$tmp_dir/token-counter" \
  MOCK_PHASE_FILE="$tmp_dir/post-phase" \
  "$root_dir/scripts/maxcache-route-report.sh" \
    --base-url https://example.test \
    --routes "$tmp_dir/post-missing-route.txt" \
    --cache-root "$tmp_dir/cache" \
    --purge-cmd "$tmp_dir/purge-fixture.sh" \
    --fail-on-risk 2>&1
)"
post_missing_exit=$?
set -e

if [[ "$post_missing_exit" != "1" ]]; then
  echo "Expected post-purge missing route to exit 1, got $post_missing_exit" >&2
  echo "$post_missing_output" >&2
  exit 1
fi

grep -q 'live-route-missing-after-purge' <<<"$post_missing_output"

echo "Route report tests passed."
