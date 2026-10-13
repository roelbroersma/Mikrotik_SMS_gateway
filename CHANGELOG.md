# Changelog

## v2.1.0 - 2026-10-13

- Grouped and aligned admin settings, with shorter interface text.
- Added a separate startup hold setting (`STARTUP_HOLD_SECONDS`), defaulting to 300 seconds. Saved settings can change an active hold without resetting known counters.
- The startup hold is independent of the counting period. A hold shorter than that period cannot guarantee the rolling limit across a RAM reset; use 3600 seconds for the previous hourly protection.

## v2.0.0 - 2026-10-13

### Added

- One shared outgoing limiter for all SMS attempts, including retries and admin test messages. Defaults: at least 10 seconds between attempts and at most 20 attempts per rolling hour.
- Admin page with `ADMIN_USER` and `ADMIN_PASSWORD` from Docker ENV, a test SMS form, queue, recent results, pause control, settings and the MikroTik inbox.
- ISO timestamps, message IDs, source IPs and attempt counts in the RAM queue. Optional `request_id` for deduplicating client retries.
- Limits on queue age (60 minutes), queue length (100 messages) and attempts (3). Recent results are bounded to 200 entries by default.
- A persistent `/config/settings.json`, written only when settings are saved. Passwords stay in ENV; queue, counters and history stay in RAM.

### Changed

- Newly accepted requests now return HTTP 202 and enter the queue. A separate worker sends them; HTTP success no longer means an SMS was sent immediately.
- Queue and counters use shared RAM-backed storage and locking instead of APCu and the router-side `sms_queue.txt` file.
- A lost state starts a full-window cooldown, including after a reboot or first installation. With the defaults this is 60 minutes; restarting does not give a new outgoing allowance.
- Every outgoing attempt counts before calling RouterOS. Failed attempts use a bounded retry schedule; uncertain results are not automatically retried.
- The optional file log defaults to RAM-backed storage and is rotated.
- Message validation allows only one GSM-7 SMS, with a 160-septet limit and the existing 160-byte cap. Unsupported characters are rejected with HTTP 400.
- A repeated `request_id` returns the retained result: HTTP 202 while queued/in progress, or HTTP 200 for a final result. It does not create a new message.

### Fixed

- Simultaneous requests and queue processing can no longer overwrite each other's queue snapshots.
- A worker interruption no longer causes a whole successful batch to be replayed. An interrupted in-flight message is kept as uncertain.

### Upgrade notes

- Disable the old RouterOS SMS queue scheduler before starting this version. Keep the separate container startup/check scheduler.
- The old `sms_queue.txt` is left untouched and is not automatically imported, to avoid replaying old messages.
- Add the admin credentials and persistent config mount, update the startup script and recreate the container.
- Point all senders at the gateway; direct RouterOS SMS calls bypass its outgoing limits.

## v1.1 - 2026-08-05

### Added

- Added `LOG_TO_ROUTEROS`. When enabled, a successful direct send is added directly to the RouterOS `/log` through `/rest/execute` as `script,info`. Container `logging=yes` is not required. Example: `SMS SENT: +316XXXXXXXX - Test message - Source IP: 192.0.2.10`.
