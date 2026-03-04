# Notifications Runbook

## Scope

This runbook covers notification delivery health checks and Telegram delivery validation for `staging` and `prod`.

## Quick deploy checklist

1. Update code:
   - `git pull --ff-only origin main`
2. Apply schema changes if any:
   - `php artisan migrate --force`
3. Clear caches:
   - `php artisan optimize:clear`
4. Ensure queue worker is running (Horizon or queue worker).

## Delivery verification

1. Open staff user card and verify `Telegram (chat_id)` is filled.
2. In staff card, use:
   - `Telegram link` to generate one-time `/start <token>`
   - choose delivery channels (`bell` and/or `email`) in the same action
   - `Проверить привязку` to see linked Telegram account metadata
   - `Сбросить Telegram` if wrong account was linked
   - each new token invalidates the previous token for the same user
3. Use `Telegram тест` action in staff edit page.
4. Verify delivery stats:
   - `php artisan notifications:audit --hours=1 --limit=10`
5. Verify health check:
   - `php artisan notifications:health-check --hours=1`

## Expected audit output

- `Total` equals `sent + failed`.
- Telegram rows appear in `By channel/status` when Telegram test is used.
- `Queue failed jobs` is `0` in normal state.

## If Telegram test fails

1. Check user `telegram_chat_id` value.
2. Check bot token config:
   - `TELEGRAM_ENABLED=true`
   - `TELEGRAM_BOT_TOKEN=...`
3. Re-run `Telegram test` from staff page.
4. For connect-link action, verify selected channel delivery in `notifications:audit` (mail/database).
5. Check Laravel logs for `Telegram API error`.

## Self-link flow (user cabinet)

1. Open `Profile -> Notification settings`.
2. Click `Connect Telegram`.
3. Send generated command `/start <token>` to bot.
4. Click `Refresh status` in page and confirm `Telegram connected`.

## Operational hygiene

Keep app working tree clean on servers:

1. Check:
   - `git status --short`
2. Remove temporary backup artifacts if they are no longer needed:
   - `.env.backup_*`
   - `.env.backup_queue_*`
   - `notify`
3. Do not keep ad-hoc files in app root. Place backups in dedicated ops directory outside release path.
