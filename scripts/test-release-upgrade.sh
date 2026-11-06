#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
# Real packaged-app smoke test. Requires PHP 8.3+ with zip/sodium/SQLite/curl and a built release ZIP.
# Usage: sh scripts/test-release-upgrade.sh /absolute/path/to/novfora-release.zip
# All mutable app files, data, signing keys, and HTTP traffic stay under /tmp and loopback.
set -eu

ZIP="${1:?usage: test-release-upgrade.sh <baseline-release.zip>}"
LAYOUT="${SMOKE_LAYOUT:-canonical}"
case "$LAYOUT" in canonical|option_a|option_b|option_c) ;; *) echo "unknown SMOKE_LAYOUT: $LAYOUT" >&2; exit 2 ;; esac
SELF="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d /tmp/novfora-upgrade-smoke.XXXXXX)"
LIVE="$WORK/live"
CANDIDATE="$WORK/candidate"
SERVER_PID=
passed=0
cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; fi
  if [ -f "$WORK/key" ]; then php -r 'unlink($argv[1]);' "$WORK/key"; fi
  if [ "$passed" -eq 1 ] && [ "${KEEP_SMOKE_WORK:-0}" != 1 ]; then
    find "$WORK" -depth -delete
  else
    echo "SMOKE_WORK=$WORK" >&2
  fi
}
trap cleanup EXIT

cat > "$WORK/check-input.php" <<'PHP'
<?php
$zip = new ZipArchive;
if ($zip->open($argv[1], ZipArchive::RDONLY) !== true || $zip->numFiles < 2 || $zip->numFiles > 20000) {
    fwrite(STDERR, "Smoke input is not a bounded release ZIP.\n");
    exit(1);
}
$total = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->statIndex($i);
    $name = $entry['name'] ?? '';
    if (! is_string($name) || $name === '' || strlen($name) > 512 || str_contains($name, "\\")
        || str_starts_with($name, '/') || str_contains($name, '//') || preg_match('/^[A-Za-z]:/', $name)) {
        fwrite(STDERR, "Smoke input has an unsafe path.\n");
        exit(1);
    }
    foreach (explode('/', rtrim($name, '/')) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            fwrite(STDERR, "Smoke input has an unsafe path component.\n");
            exit(1);
        }
    }
    if ($zip->getExternalAttributesIndex($i, $system, $attributes) && $system === ZipArchive::OPSYS_UNIX) {
        $type = ($attributes >> 16) & 0170000;
        if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
            fwrite(STDERR, "Smoke input has a link or special file.\n");
            exit(1);
        }
    }
    $size = (int) ($entry['size'] ?? -1);
    $total += $size;
    if ($size < 0 || $size > 134217728 || $total > 536870912) {
        fwrite(STDERR, "Smoke input exceeds extraction limits.\n");
        exit(1);
    }
}
PHP
php "$WORK/check-input.php" "$ZIP"
mkdir -p "$LIVE" "$CANDIDATE"
unzip -q "$ZIP" -d "$LIVE"
unzip -q "$ZIP" -d "$CANDIDATE"

PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0"); echo (int) substr(strrchr(stream_socket_get_name($s,false),":"),1); fclose($s);')"
if [ "$LAYOUT" = canonical ]; then
  APP_URL="http://127.0.0.1:$PORT"
  WEBROOT="$LIVE/public"
  SERVED="$LIVE/public"
else
  APP_URL="http://127.0.0.1:$PORT/community"
  WEBROOT="$WORK/webroot"
  SERVED="$WEBROOT/community"
  mkdir -p "$WEBROOT"
fi
DB="$LIVE/database/database.sqlite"
touch "$DB"
cp "$LIVE/.env.example" "$LIVE/.env"
APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
cat >> "$LIVE/.env" <<ENV
APP_NAME=NovForaSmoke
APP_ENV=production
APP_DEBUG=false
APP_URL=$APP_URL
APP_KEY=$APP_KEY
DB_CONNECTION=sqlite
DB_DATABASE=$DB
CACHE_STORE=file
QUEUE_CONNECTION=database
NOVFORA_INSTALL_REQUIRE_TOKEN=false
ENV

echo '>> installing packaged baseline'
( cd "$LIVE" && php artisan novfora:install --skip-checks --no-interaction \
  --name=NovForaSmoke --url="$APP_URL" --db-driver=sqlite --db-database="$DB" \
  --db-username=smoke --db-password='' --admin-username=smokeowner \
  --admin-email=smoke@example.test --admin-password='SmokePassword123!' ) > "$WORK/install.log" 2>&1 || {
  tail -n 35 "$WORK/install.log" >&2
  exit 1
}

cat > "$WORK/seed.php" <<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$now = now();
\Illuminate\Support\Facades\DB::table('forums')->insert([
    'slug' => 'smoke-forum', 'title' => 'Smoke Forum', 'type' => 'forum', 'path' => '/',
    'created_at' => $now, 'updated_at' => $now,
]);
\Illuminate\Support\Facades\DB::table('settings')->insert([
    'key' => 'smoke.custom_setting', 'value' => 'preserved', 'type' => 'string',
    'created_at' => $now, 'updated_at' => $now,
]);
PHP
php "$WORK/seed.php" "$LIVE"
mkdir -p "$LIVE/storage/app/public/smoke" "$LIVE/modules/custom/smoke"
printf 'upload-survives-release\n' > "$LIVE/storage/app/public/smoke/upload.txt"
printf '{"name":"Smoke Custom Module"}\n' > "$LIVE/modules/custom/smoke/module.json"
if [ "$LAYOUT" = option_a ]; then
  ln -s "$LIVE/public" "$SERVED"
elif [ "$LAYOUT" = option_b ] || [ "$LAYOUT" = option_c ]; then
  ( cd "$LIVE" && php artisan novfora:subdir:scaffold "$SERVED" --base=/community ) > "$WORK/scaffold.log" 2>&1 || {
    tail -n 20 "$WORK/scaffold.log" >&2
    exit 1
  }
  if [ "$LAYOUT" = option_c ]; then
    # Simulate a host without directory symlinks by replacing both generated links with copies.
    php -r 'foreach(array_slice($argv,1) as $p) { if (is_link($p)) unlink($p); }' "$SERVED/build" "$SERVED/storage"
    cp -a "$LIVE/public/build" "$SERVED/build"
    cp -a "$LIVE/storage/app/public" "$SERVED/storage"
  fi
  SERVED_INDEX_HASH="$(sha256sum "$SERVED/index.php" | cut -d ' ' -f 1)"
  SERVED_HTACCESS_HASH="$(sha256sum "$SERVED/.htaccess" | cut -d ' ' -f 1)"
fi
ENV_HASH="$(sha256sum "$LIVE/.env" | cut -d ' ' -f 1)"

echo '>> preparing a signed synthetic 1.4.0 candidate'
php -r '$p=$argv[1]; $s=file_get_contents($p); $n=0; $s=preg_replace("/\x27version\x27\\s*=>\\s*env\\(\\s*\x27APP_VERSION\x27\\s*,\\s*\x271\\.3\\.0\x27\\s*\\)/", "\x27version\x27 => env(\x27APP_VERSION\x27, \x271.4.0\x27)", $s, 1, $n); if($n!==1)exit(1); file_put_contents($p,$s);' "$CANDIDATE/config/app.php"
printf 'new-release-icon' > "$CANDIDATE/public/favicon.ico"
printf '\n' >> "$CANDIDATE/public/build/manifest.json"
ASSET_HASH="$(sha256sum "$CANDIDATE/public/favicon.ico" | cut -d ' ' -f 1)"
BUILD_HASH="$(sha256sum "$CANDIDATE/public/build/manifest.json" | cut -d ' ' -f 1)"
cat > "$CANDIDATE/database/migrations/2026_11_06_235959_create_release_smoke_probes.php" <<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (! Schema::hasTable('release_smoke_probes')) {
            Schema::create('release_smoke_probes', function (Blueprint $table) { $table->id(); });
        }
    }
    public function down(): void { Schema::dropIfExists('release_smoke_probes'); }
};
PHP
php "$SELF/lib/release-manifest.php" "$CANDIDATE"
php "$SELF/lib/zip-dir.php" "$CANDIDATE" "$WORK/novfora-release.zip" > "$WORK/zip.log"
php -r '$p=sodium_crypto_sign_keypair(); file_put_contents($argv[1],sodium_crypto_sign_secretkey($p)); chmod($argv[1],0600); file_put_contents($argv[2],base64_encode(sodium_crypto_sign_publickey($p)));' "$WORK/key" "$WORK/pub"
php "$SELF/sign-release.php" "$WORK/novfora-release.zip" "$WORK/key" smoke-key

cat > "$WORK/queue.php" <<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['releases.trusted_keys' => ['smoke-key' => trim(file_get_contents($argv[2]))]]);
$base = 'https://github.com/getnovfora/novfora/releases/download/v1.4.0/';
\Illuminate\Support\Facades\Http::fake([
    'api.github.com/repos/getnovfora/novfora/releases/latest' => \Illuminate\Support\Facades\Http::response([
        'draft' => false, 'prerelease' => false, 'tag_name' => 'v1.4.0',
        'published_at' => '2026-11-06T00:00:00Z',
        'assets' => [
            ['name' => 'novfora-release.zip', 'browser_download_url' => $base.'novfora-release.zip'],
            ['name' => 'novfora-release.zip.sig', 'browser_download_url' => $base.'novfora-release.zip.sig'],
        ],
    ]),
    $base.'novfora-release.zip' => \Illuminate\Support\Facades\Http::response(file_get_contents($argv[3])),
    $base.'novfora-release.zip.sig' => \Illuminate\Support\Facades\Http::response(file_get_contents($argv[3].'.sig')),
]);
\Illuminate\Support\Facades\Http::preventStrayRequests();
$owner = \App\Models\User::where('email', 'smoke@example.test')->firstOrFail();
$owner->forceFill(['two_factor_confirmed_at' => now()])->save();
$coordinator = app(\App\Upgrade\ReleaseCoordinator::class);
try {
$coordinator->request($owner, $argv[4]);
$run = $coordinator->advance();
if (($run['phase'] ?? '') === 'snapshotting') {
    // Test clock only: bypass the real one-minute in-flight request drain in this private sandbox.
    // The first advance must still persist the gated snapshotting phase before any backup or swap.
    $state = app(\App\Upgrade\ReleaseRunState::class);
    $state->locked(function () use ($state): void {
        $waiting = $state->read();
        $waiting['snapshot_after'] = time() - 1;
        $state->write($waiting);
    });
    $run = $coordinator->advance();
}
} catch (\Throwable $error) {
    fwrite(STDERR, 'PREPARE_ERROR='.$error->getMessage()."\n");
    exit(1);
}
echo 'PREPARED_PHASE='.($run['phase'] ?? 'missing')."\n";
if (($run['phase'] ?? '') !== 'applying') {
    fwrite(STDERR, 'PREPARE_ERROR='.($run['error'] ?? 'none')."\n");
    exit(1);
}
PHP

echo '>> preparing real coordinator with faked signed GitHub assets'
php "$WORK/queue.php" "$LIVE" "$WORK/pub" "$WORK/novfora-release.zip" "$SERVED" > "$WORK/queue.log" 2>&1 || {
  tail -n 35 "$WORK/queue.log" >&2
  exit 1
}
tail -n 2 "$WORK/queue.log"

echo '>> running real preboot worker against loopback HTTP'
php -r '$served=$argv[1]; $layout=$argv[2]; $source="<?php\n\$path=parse_url(\$_SERVER[\x27REQUEST_URI\x27] ?? \x27\x27, PHP_URL_PATH);\nif (is_string(\$path) && is_file(\$_SERVER[\x27DOCUMENT_ROOT\x27].\$path) && basename(\$path)!==\x27index.php\x27) return false;\n\$_SERVER[\x27SCRIPT_NAME\x27]=\x27".($layout==="canonical"?"":"/community")."/index.php\x27;\n\$_SERVER[\x27SCRIPT_FILENAME\x27]=\x27".addslashes($served)."/index.php\x27;\n\$_SERVER[\x27PHP_SELF\x27]=\$_SERVER[\x27SCRIPT_NAME\x27];\nrequire \x27".addslashes($served)."/index.php\x27;\n"; file_put_contents($argv[3],$source);' "$SERVED" "$LAYOUT" "$WORK/router.php"
( cd "$LIVE" && php -S "127.0.0.1:$PORT" -t "$WEBROOT" "$WORK/router.php" ) > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
php -r '$port=(int)$argv[1]; for($i=0;$i<50;$i++){ $s=@stream_socket_client("tcp://127.0.0.1:$port",$code,$error,.1); if($s){fclose($s);exit(0);} usleep(100000);} exit(1);' "$PORT"
( cd "$LIVE" && php artisan schedule:run ) > "$WORK/worker.log" 2>&1 || {
  tail -n 40 "$WORK/worker.log" >&2
  tail -n 20 "$WORK/server.log" >&2
  exit 1
}

cat > "$WORK/check.php" <<'PHP'
<?php
$root = $argv[1];
$db = new PDO('sqlite:'.$root.'/database/database.sqlite');
$checks = [
    'migration' => (bool) $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='release_smoke_probes'")->fetchColumn(),
    'forum' => $db->query("SELECT title FROM forums WHERE slug='smoke-forum'")->fetchColumn() === 'Smoke Forum',
    'setting' => $db->query("SELECT value FROM settings WHERE key='smoke.custom_setting'")->fetchColumn() === 'preserved',
    'upload' => file_get_contents($root.'/storage/app/public/smoke/upload.txt') === "upload-survives-release\n",
    'module' => file_get_contents($root.'/modules/custom/smoke/module.json') === "{\"name\":\"Smoke Custom Module\"}\n",
    'env' => hash_file('sha256', $root.'/.env') === $argv[2],
    'app_asset' => hash_file('sha256', $root.'/public/favicon.ico') === $argv[3],
    'served_asset' => is_file($argv[5].'/favicon.ico') && hash_file('sha256', $argv[5].'/favicon.ico') === $argv[3],
    'served_build' => hash_file('sha256', $argv[5].'/build/manifest.json') === $argv[6],
];
if ($argv[7] === 'option_b' || $argv[7] === 'option_c') {
    $checks['scaffold_index'] = hash_file('sha256', $argv[5].'/index.php') === $argv[8];
    $checks['scaffold_htaccess'] = hash_file('sha256', $argv[5].'/.htaccess') === $argv[9];
}
$state = json_decode(file_get_contents($root.'/storage/framework/release-upgrade/state.json'), true);
$checks['phase'] = ($state['phase'] ?? null) === 'complete';
$context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$health = json_decode((string) @file_get_contents($argv[4].'/health', false, $context), true);
$checks['health'] = ($health['version'] ?? null) === '1.4.0' && ($health['installed'] ?? null) === true;
$servedIcon = @file_get_contents($argv[4].'/favicon.ico', false, $context);
$checks['http_asset'] = is_string($servedIcon) && hash('sha256', $servedIcon) === $argv[3];
foreach ($checks as $name => $ok) echo strtoupper($name).'='.($ok ? 'PASS' : 'FAIL')."\n";
if (in_array(false, $checks, true)) exit(1);
PHP
php "$WORK/check.php" "$LIVE" "$ENV_HASH" "$ASSET_HASH" "$APP_URL" "$SERVED" "$BUILD_HASH" "$LAYOUT" "${SERVED_INDEX_HASH:-}" "${SERVED_HTACCESS_HASH:-}"
echo "RELEASE_SMOKE_${LAYOUT}=PASS"
passed=1
