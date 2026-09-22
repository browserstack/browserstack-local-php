#!/usr/bin/env bash
#
# LOC-6740 — manual MITM proof for LocalBinary's binary download.
#
# The automated suite (tests/LocalBinaryTest.php, run by .github/workflows/php.yml)
# covers the assertions. This harness is the end-to-end demonstration: it stands up
# a local HTTPS server with a self-signed certificate and runs the real
# LocalBinary class against it, on both the pre-fix and post-fix source.
#
#   ARM 1  pre-fix source (git master), URL pointed at the MITM server
#            -> expected ACCEPTED: the substituted payload is written and chmod 0755'd
#   ARM 2  this working tree, same server
#            -> expected REJECTED with cURL error 60, nothing left on disk
#   ARM 3  this working tree, real platform URL, no override
#            -> expected ACCEPTED: the genuine 36-40 MB binary, mode 755
#
# ARM 3 needs outbound network. ARM 1 needs a local `master` ref.
#
# Requires: php (>= 5.3.19, with the curl and openssl extensions), git, openssl, python3.
#
# Usage:
#   tests/manual/php-poc.sh              # from anywhere inside the checkout
#   REPO=/path/to/checkout tests/manual/php-poc.sh
#
set -u

REPO="${REPO:-$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel 2>/dev/null)}"
[ -n "$REPO" ] && [ -f "$REPO/lib/LocalBinary.php" ] || {
  echo "Could not locate the checkout. Run from inside it, or set REPO=/path/to/browserstack-local-php." >&2
  exit 1
}
PORT="${PORT:-18443}"
WORK="$(mktemp -d)"
SRV_PID=""
cleanup() { [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$WORK"; }
trap cleanup EXIT

for tool in php git openssl python3; do
  command -v "$tool" >/dev/null || { echo "$tool not found" >&2; exit 1; }
done
php -r 'exit(extension_loaded("curl") && extension_loaded("openssl") ? 0 : 1);' || {
  echo "php is missing the curl and/or openssl extension" >&2; exit 1; }
echo "php: $(php -r 'echo PHP_VERSION;')  repo: $REPO"

# A self-signed certificate for localhost. The chain does not validate, which is
# exactly what CURLOPT_SSL_VERIFYPEER controls. Using localhost rather than
# s3.amazonaws.com avoids needing /etc/hosts or curl --resolve, neither of which
# can be injected into the class under test.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
  -keyout "$WORK/key.pem" -out "$WORK/cert.pem" \
  -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost" 2>/dev/null
echo "attacker cert: $(openssl x509 -in "$WORK/cert.pem" -noout -subject -issuer | tr '\n' ' ')"

# The payload is > 1 MiB and carries ELF magic so it also satisfies verify_binary()'s
# format/size gate. That isolates these arms to the TLS control alone.
mkdir -p "$WORK/root"
printf '\x7fELF' > "$WORK/root/BrowserStackLocal-linux-x64"
head -c 2097152 /dev/zero >> "$WORK/root/BrowserStackLocal-linux-x64"
PAYLOAD_URL="https://localhost:$PORT/BrowserStackLocal-linux-x64"

python3 - "$WORK" "$PORT" <<'PY' &
import http.server, os, ssl, sys
work, port = sys.argv[1], int(sys.argv[2])
os.chdir(os.path.join(work, "root"))
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(os.path.join(work, "cert.pem"), os.path.join(work, "key.pem"))
srv = http.server.HTTPServer(("127.0.0.1", port), http.server.SimpleHTTPRequestHandler)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
PY
SRV_PID=$!
for _ in $(seq 1 40); do
  openssl s_client -connect "127.0.0.1:$PORT" </dev/null >/dev/null 2>&1 && break
  sleep 0.25
done

# Runs a given LocalBinary.php with every hardcoded platform URL rewritten to the
# local payload URL. The sed covers both the pre-fix (private) and post-fix
# (protected) shape of platform_url(), so no subclass is needed.
run_arm() {
  local src="$1" out="$2" name="$3"
  mkdir -p "$out/dest"
  sed -E "s#https://s3\.amazonaws\.com/browserStack/browserstack-local/[A-Za-z0-9._-]+#$PAYLOAD_URL#g" \
    "$src" > "$out/LocalBinary.php"
  cp "$REPO/lib/LocalException.php" "$out/LocalException.php"
  cat > "$out/run.php" <<PHP
<?php
require '$out/LocalException.php';
require '$out/LocalBinary.php';
use BrowserStack\\LocalBinary;
use BrowserStack\\LocalException;
try {
  \$b = new LocalBinary();
  \$path = \$b->download_binary('$out/dest');
  if (\$path && file_exists(\$path)) {
    printf("ACCEPTED: %s (%d bytes, mode %o)\n", \$path, filesize(\$path), fileperms(\$path) & 0777);
    echo "VERDICT[$name]: attacker bytes ACCEPTED and made executable\n";
  } else {
    echo "VERDICT[$name]: INCONCLUSIVE — returned \$path with no file\n";
  }
} catch (LocalException \$e) {
  echo "REJECTED: " . \$e->getMessage() . "\n";
  \$left = glob('$out/dest/BrowserStackLocal*');
  echo "leftover files: " . (count(\$left) ? implode(',', \$left) : 'none') . "\n";
  echo "VERDICT[$name]: substitution REFUSED, nothing left on disk\n";
}
PHP
  php "$out/run.php"
}

echo
echo "================ ARM 1 — pre-fix source (git master) ================"
if git -C "$REPO" show master:lib/LocalBinary.php > "$WORK/prefix.php" 2>/dev/null; then
  grep -n 'VERIFYPEER' "$WORK/prefix.php" || echo "(no VERIFYPEER line found)"
  run_arm "$WORK/prefix.php" "$WORK/arm1" "pre-fix"
else
  echo "SKIPPED — no local 'master' ref (git fetch origin master:master to enable)"
fi

echo
echo "================ ARM 2 — this working tree, MITM server ============"
grep -n 'VERIFYPEER\|VERIFYHOST' "$REPO/lib/LocalBinary.php"
run_arm "$REPO/lib/LocalBinary.php" "$WORK/arm2" "post-fix-negative"

echo
echo "================ ARM 3 — this working tree, real download =========="
mkdir -p "$WORK/arm3/dest"
php -r "
require '$REPO/lib/LocalException.php';
require '$REPO/lib/LocalBinary.php';
use BrowserStack\LocalBinary;
use BrowserStack\LocalException;
try {
  \$b = new LocalBinary();
  \$p = \$b->download_binary('$WORK/arm3/dest');
  printf(\"ACCEPTED: %s (%d bytes, mode %o)\n\", \$p, filesize(\$p), fileperms(\$p) & 0777);
  echo \"VERDICT[happy-path]: OK — the real binary still downloads and verifies\n\";
} catch (LocalException \$e) {
  echo 'FAILED: ' . \$e->getMessage() . \"\n\";
  echo \"VERDICT[happy-path]: REGRESSION — the fix broke the legitimate download\n\";
}"

echo
echo "Expected: ARM 1 ACCEPTED (vulnerable), ARM 2 REFUSED (cURL error 60), ARM 3 OK."
