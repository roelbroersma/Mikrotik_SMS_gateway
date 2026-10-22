#!/bin/sh
set -eu

# Never place the live queue or counters on the config mount. The PHP helper
# verifies that SMS_STATE_DIR is backed by tmpfs before either process starts.
cd /var/www
php85 -d display_errors=0 -r 'require "gateway.php"; gateway_snapshot();'

worker_pid=""
server_pid=""
stop_children() {
    trap - EXIT INT TERM
    [ -z "$worker_pid" ] || kill "$worker_pid" 2>/dev/null || true
    [ -z "$server_pid" ] || kill "$server_pid" 2>/dev/null || true
    wait 2>/dev/null || true
}
trap stop_children EXIT
trap 'exit 0' INT TERM

# Modem calls run here, so a slow modem cannot block the admin page or enqueue.
php85 -d display_errors=0 -d log_errors=1 worker.php &
worker_pid=$!
php85 -d display_errors=0 -d log_errors=1 -d expose_php=0 \
    -d file_uploads=0 -d post_max_size=16K \
    -S 0.0.0.0:80 -t /var/www /var/www/router.php &
server_pid=$!

# If either child exits, stop the other too. The container must not look healthy
# while it accepts messages with no sender running.
while kill -0 "$worker_pid" 2>/dev/null && kill -0 "$server_pid" 2>/dev/null; do
    sleep 1
done
exit 1
