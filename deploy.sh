#!/bin/bash
# ─────────────────────────────────────────────────────────
# CRM PRINT — Auto-Deploy Script
# Called by: GitHub Webhook → DeployController → nohup bash deploy.sh
#
# The webhook only reaches this script after CI went green: DeployController
# acts on the `workflow_run` event with conclusion=success, not on `push`.
# The GitHub webhook must therefore be subscribed to "Workflow runs".
#
# Pipeline:
# 1. Wait 5s (GitHub race condition)
# 2. git pull --ff-only origin main (3 retries, fetch+reset fallback)
# 3. composer install --no-dev --optimize-autoloader
# 4. php artisan migrate --force
# 5. npm ci + npm run build (frontend assets)
# 6. php artisan optimize:clear + optimize
# 7. sudo supervisorctl restart crm-queue
#
# On failure: auto-rollback to previous commit
# Log: storage/logs/deploy.log
#
# The body is wrapped in { } and ends with an explicit exit, on purpose. Step 2
# pulls, and the pull rewrites this very file while bash is still reading it —
# bash takes a script from disk in chunks, by byte offset, so a file that
# changes length underneath it gets resumed at the wrong place. Reproduced on
# Debian: unwrapped, the run died with "e: command not found" straight after
# the pull — every step from composer onwards silently skipped. A brace group
# has to be parsed to its closing } before anything in it runs, and the exit
# stops bash going back to the file for more once the group is done.
# ─────────────────────────────────────────────────────────
{

set -uo pipefail

# ─── Timestamps ──────────────────────────────────────────
# Everything printed here is read by a person in Kyiv, and the server clock is
# UTC: a deploy at 21:42 was logged as 18:42 — and announced as 18:42 in the
# Telegram message that reaches the phone. Only these strings are converted;
# artisan takes its timezone from config/app.php, never from TZ.
#
# The zone name is checked rather than trusted: `date` answers an unknown TZ
# with UTC and no error, so a missing name would put the timestamps back where
# they started, silently. Europe/Kyiv arrived in tzdata 2022b; Europe/Kiev is
# the same zone under the name Ubuntu 22.04 originally shipped.
KYIV_TZ='Europe/Kyiv'
[ -e "/usr/share/zoneinfo/$KYIV_TZ" ] || KYIV_TZ='Europe/Kiev'
kyiv_time() { TZ="$KYIV_TZ" date "${1:-+%Y-%m-%d %H:%M:%S}"; }

# ─── Single-instance guard ───────────────────────────────
# GitHub can deliver the same push webhook more than once, and on
# 2026-07-27 it did: two deploy.sh processes ran concurrently and
# interleaved their output in deploy.log. Overlapping git pull /
# composer install / npm run build in the same directory can leave
# vendor/ or public/build half-written, so a second run now exits
# instead of racing the first.
exec 200>/tmp/crm-print-deploy.lock
if ! flock -n 200; then
    echo "[$(kyiv_time)] Deploy already in progress — skipping this trigger."
    exit 0
fi

# ─── Environment ─────────────────────────────────────────
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"
export HOME="/home/deploy"

# There were two NVM lines here, sourcing $HOME/.nvm/nvm.sh. They did nothing:
# the log of 2026-07-29 answered it in one line — `node v20.20.2 at
# /usr/bin/node`, the NodeSource package. Had NVM been sourced, node would
# have resolved inside ~/.nvm instead.
#
# They were not harmless. While preparing the Ubuntu 22.04 → 24.04 upgrade they
# produced the conclusion that Node lived in a home directory and the OS
# upgrade would not touch it. The echo below records which node actually
# runs.

APP_DIR="/var/www/crm-print"
LOG_FILE="$APP_DIR/storage/logs/deploy.log"

cd "$APP_DIR"

# ─── Logging ─────────────────────────────────────────────
exec > >(tee -a "$LOG_FILE") 2>&1

# ─── Telegram notifications ─────────────────────────────
# Read credentials from Laravel .env (deploy.sh has no access to artisan config)
TELEGRAM_TOKEN=$(grep -oP '^TELEGRAM_BOT_TOKEN=\K.+' "$APP_DIR/.env" 2>/dev/null || true)
TELEGRAM_CHAT=$(grep -oP '^TELEGRAM_CHAT_ID=\K.+' "$APP_DIR/.env" 2>/dev/null || true)

notify_telegram() {
    local text="$1"
    if [ -n "$TELEGRAM_TOKEN" ] && [ -n "$TELEGRAM_CHAT" ]; then
        curl -s --max-time 10 -X POST \
            "https://api.telegram.org/bot${TELEGRAM_TOKEN}/sendMessage" \
            -d "chat_id=${TELEGRAM_CHAT}" \
            -d "text=${text}" \
            -d "parse_mode=Markdown" \
            > /dev/null 2>&1 || true
    fi
}

echo ""
echo "══════════════════════════════════════════════════"
echo "Deploy started at $(kyiv_time)"
echo "══════════════════════════════════════════════════"

# ─── Step 1: Wait for GitHub to process the push ─────────
# GitHub webhook fires immediately, but the push may not be
# fully available for pull yet. A 5s delay prevents this.
echo "⏳ Waiting 5s for GitHub to process push..."
sleep 5

# ─── Step 2: Git Pull with retry ─────────────────────────
BEFORE_HASH=$(git rev-parse HEAD)

# ─── Rollback function (called on composer/migrate failure) ──
rollback() {
    echo "❌ Deploy FAILED at $(kyiv_time)"
    echo "🔄 Rolling back to $BEFORE_HASH..."
    git reset --hard "$BEFORE_HASH" 2>&1
    composer install --no-dev --optimize-autoloader --no-interaction 2>&1
    # The frontend too: `npm run build` empties public/build before writing,
    # so a build that died mid-deploy left no manifest — code and vendor came
    # back, Telegram said rolled back, and every page answered 500 until
    # someone rebuilt by hand. No `|| rollback` here:
    # recursing into ourselves is the one thing worse than a failed rebuild.
    npm ci --no-audit --no-fund 2>&1
    npm run build 2>&1
    php artisan optimize:clear 2>&1
    php artisan optimize 2>&1
    echo "✅ Rolled back to $BEFORE_HASH"

    # Notify Telegram about failure
    local short_hash="${BEFORE_HASH:0:7}"
    notify_telegram "❌ *Deploy FAILED*
🕐 $(kyiv_time '+%H:%M %d.%m.%Y')
🔄 Rolled back to \`${short_hash}\`
🖥 print.example.com
📋 Check: storage/logs/deploy.log"

    exit 1
}

# Discard any local modifications (prevents "would be overwritten" errors)
git reset --hard HEAD 2>&1

for ATTEMPT in 1 2 3; do
    echo "📥 Git pull attempt $ATTEMPT/3..."
    if git pull --ff-only origin main 2>&1; then
        break
    fi
    if [ "$ATTEMPT" -lt 3 ]; then
        echo "⚠️  Pull failed, retrying in 3s..."
        sleep 3
    else
        # Fallback: force-sync with remote (handles force-push / diverged branches)
        echo "⚠️  Fast-forward failed. Force-syncing with remote..."
        git fetch origin 2>&1
        git reset --hard origin/main 2>&1
        break
    fi
done

AFTER_HASH=$(git rev-parse HEAD)

if [ "$BEFORE_HASH" = "$AFTER_HASH" ]; then
    echo "ℹ️  No new commits. Skipping build."
    echo "Deploy finished at $(kyiv_time) (no changes)"
    exit 0
fi

echo "📝 Updated: $BEFORE_HASH → $AFTER_HASH"
echo "Changed files:"
git diff --stat "$BEFORE_HASH" "$AFTER_HASH"

# ─── Step 3: Composer ────────────────────────────────────
echo "📦 Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || rollback

# ─── Step 4: Migrations ─────────────────────────────────
echo "🗄️  Running migrations..."
php artisan migrate --force 2>&1 || rollback

# ─── Step 5: Build Frontend ─────────────────────────────
echo "🏗️  Building frontend assets..."
# Which Node this actually runs on. Three documents claimed three different
# answers — 20, 22, "via NVM" — and one of them sent the OS upgrade down a
# blind alley. The log settles it once per deploy; keep it, because the next
# person to change Node will need the same line to know whether it took.
echo "   node $(node -v 2>/dev/null || echo '?') at $(command -v node || echo 'not found')"
# `ci`, not `install`: install is free to resolve a newer version than the lock
# file names, so production could be built from dependencies CI never saw. ci
# installs the lock file exactly and fails loudly if it has drifted from
# package.json — which is a deploy that stops here and rolls back, instead of a
# deploy that succeeds with a bundle nobody tested.
npm ci --no-audit --no-fund 2>&1 || rollback
npm run build 2>&1 || rollback

# ─── Step 6: Optimize ───────────────────────────────────
echo "⚡ Optimizing..."
php artisan optimize:clear 2>&1
php artisan optimize 2>&1

# ─── Step 7: Restart Queue ──────────────────────────────
echo "🔄 Restarting queue worker..."
sudo /usr/bin/supervisorctl restart crm-queue 2>&1 || php artisan queue:restart 2>&1

# ─── Done ────────────────────────────────────────────────
echo "✅ Deploy completed at $(kyiv_time)"
echo "══════════════════════════════════════════════════"

# Notify Telegram about success
COMMIT_MSG=$(git log -1 --pretty=format:"%s" 2>/dev/null || echo "unknown")
SHORT_BEFORE="${BEFORE_HASH:0:7}"
SHORT_AFTER="${AFTER_HASH:0:7}"
notify_telegram "✅ *Deploy OK*
🕐 $(kyiv_time '+%H:%M %d.%m.%Y')
📝 \`${SHORT_BEFORE}\` → \`${SHORT_AFTER}\`
💬 ${COMMIT_MSG}
🖥 print.example.com"

# Explicit, and load-bearing: without it bash goes back to the file for more
# input once the group is done, at the byte offset where the old file ended —
# which by now is the middle of the new one. Measured: it executes whatever
# fragment lands there. exit ends the shell before that read happens.
exit 0

}
