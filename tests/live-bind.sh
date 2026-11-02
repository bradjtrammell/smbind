#!/usr/bin/env bash
set -euo pipefail
if [[ "$SMBIND_CI_DATABASE" != "smbind_ci" || "$SMBIND_DSN" != *"dbname=smbind_ci"* ]]; then
  echo "Refusing to run against any non-CI database" >&2
  exit 2
fi
for executable in named named-checkzone named-checkconf rndc dig openssl; do
  command -v "$executable" >/dev/null || { echo "Missing $executable" >&2; exit 2; }
done
root="$(mktemp -d -t smbind-live-XXXXXX)"
named_pid=""
cleanup() {
  if [[ -n "$named_pid" ]]; then
    kill "$named_pid" 2>/dev/null || true
    wait "$named_pid" 2>/dev/null || true
  fi
  rm -rf "$root"
}
trap cleanup EXIT
mkdir -m 0700 "$root/zones"
touch "$root/zones.conf"
secret="$(openssl rand -base64 32)"
cat >"$root/named.conf" <<CONF
options {
  directory "$root";
  listen-on port 19553 { 127.0.0.1; };
  listen-on-v6 { none; };
  recursion no;
  dnssec-validation no;
  pid-file "$root/named.pid";
};
key "smbindci" {
  algorithm hmac-sha256;
  secret "$secret";
};
controls {
  inet 127.0.0.1 port 19554 allow { 127.0.0.1; } keys { "smbindci"; };
};
include "$root/zones.conf";
CONF
cat >"$root/rndc.conf" <<CONF
key "smbindci" {
  algorithm hmac-sha256;
  secret "$secret";
};
options {
  default-server 127.0.0.1;
  default-port 19554;
  default-key "smbindci";
};
CONF
cat >"$root/rndc-run" <<SCRIPT
#!/bin/sh
exec "$(command -v rndc)" -c "$root/rndc.conf" "\$@"
SCRIPT
chmod 0700 "$root/rndc-run"
export SMBIND_ZONE_DIR="$root/zones"
export SMBIND_INCLUDE_FILE="$root/zones.conf"
export SMBIND_NAMED_CONF="$root/named.conf"
export SMBIND_NAMED_CHECKZONE="$(command -v named-checkzone)"
export SMBIND_NAMED_CHECKCONF="$(command -v named-checkconf)"
export SMBIND_RNDC="$root/rndc-run"
named-checkconf "$root/named.conf"
named -g -c "$root/named.conf" -p 19553 >"$root/named.log" 2>&1 &
named_pid="$!"
ready=0
for attempt in $(seq 1 30); do
  if "$root/rndc-run" status >/dev/null 2>&1; then ready=1; break; fi
  if ! kill -0 "$named_pid" 2>/dev/null; then break; fi
  sleep 0.5
done
if [[ "$ready" -ne 1 ]]; then
  tail -n 100 "$root/named.log" >&2 || true
  exit 1
fi
php tests/live-bind-fixture.php create
php bin/publish.php
actual="$(dig @127.0.0.1 -p 19553 live-ci.example.test A +short)"
[[ "$actual" == "192.0.2.81" ]] || { echo "Initial authoritative answer incorrect: '$actual'" >&2; exit 1; }
php tests/live-bind-fixture.php modify
php bin/publish.php
actual="$(dig @127.0.0.1 -p 19553 live-ci.example.test A +short)"
[[ "$actual" == "192.0.2.82" ]] || { echo "Reload did not apply changed record: '$actual'" >&2; exit 1; }
php tests/live-bind-fixture.php delete
php bin/publish.php
actual="$(dig @127.0.0.1 -p 19553 live-ci.example.test A +short)"
[[ -z "$actual" ]] || { echo "Deleted zone still answers: '$actual'" >&2; exit 1; }
[[ ! -e "$root/zones/live-ci.example.test.zone" ]] || { echo "Removed zone file still present" >&2; exit 1; }
echo "PASS: live authoritative BIND published, reloaded, updated, and removed deleted zone."
