# MikroTik SMS Gateway

A lightweight SMS gateway for MikroTik routers with LTE and RouterOS 7+ support.
This SMS gateway was made because the default MikroTik API is not very user-friendly.

You can run it on a Linux server, but if you want an all-in-one solution, it can run on the MikroTik router!
How does that work? -> The MikroTik router uses the Container package. After a reboot the supplied script creates a RAM disk and starts the Docker container.

The container has a PHP webserver and a worker. The webserver accepts SMS requests and shows the admin page. The worker is the only part that sends SMS messages, including retries and test messages. So everything uses the same outgoing limits.

`SMS_GATEWAY_USER` and `SMS_GATEWAY_PASS` authenticate the gateway to RouterOS. `ADMIN_USER` and `ADMIN_PASSWORD` protect the admin page. Incoming requests to `sendsms.php` still use source-IP restrictions and per-IP rate limiting.

---

## How it works

- Remote clients use `sendsms.php` to submit SMS messages via HTTP POST.
- Every accepted message goes into the RAM queue, also when LTE is working. HTTP 202 means queued, not sent.
- New messages are added at the end. The worker starts with the oldest message that is ready for another attempt.
- Queue entries have an ID, an ISO 8601 UTC timestamp, the source IP and a retry count.
- The worker sends with `/tool sms send` through the RouterOS REST API.
- By default there is at least 10 seconds between attempts, and at most 20 attempts in any rolling hour. Failed attempts count too.
- A failed message gets at most 3 attempts, with 60 seconds before the second attempt and 300 seconds before the third.
- A message is discarded after 60 minutes in the queue. The queue holds at most 100 messages.
- The last 200 results are kept in RAM and shown on the admin page.

There is no separate RouterOS sending script anymore. Direct requests from other applications to `/tool sms send` or the RouterOS API still bypass this gateway; point those applications at `sendsms.php` too.

### RAM and flash

The container itself is stored in `ram/sms-gateway` when using the supplied MikroTik setup. Queue, counters, recent history and locks live in `SMS_STATE_DIR`, by default `/dev/shm/sms-gateway`, on RAM-backed storage. APCu is no longer needed.

The only persistent gateway data is `/config/settings.json`. Mount `/config` from a separate directory on flash, outside `ram/sms-gateway`. This file is written when you click **Save settings**, not for every SMS or counter update. Gateway and admin passwords stay in Docker ENV settings.

A router reboot clears the RAM queue and history. The MikroTik startup script preserves the state when restarting a container on the existing RAM disk. Recreating only the container also preserves state when its external RAM mount remains; clearing or recreating that mount loses the state. The Linux Docker example uses a container-owned tmpfs, which is cleared when that container stops. A missing or reset state starts a full outgoing-limit cooldown: with `SEND_LIMITS=20/3600`, sending waits 60 minutes. This also applies on first installation. A restart must not give a new allowance of 20 SMS messages. Messages that expire while waiting will not be sent later.

---

## Docker on a Linux server

Build from the checked-out source:

```bash
docker build -t mikrotik-sms-gateway .
mkdir -p ./sms-gateway-config
```

Start the container with a persistent config mount and a RAM-only state directory:

```bash
docker run -d --name sms-gateway \
  -p 8080:80 \
  --tmpfs /dev/shm/sms-gateway:rw,nosuid,nodev,noexec,size=16m \
  --mount type=bind,src="$(pwd)/sms-gateway-config",dst=/config \
  -e SMS_GATEWAY_URL=http://192.168.0.254 \
  -e SMS_GATEWAY_USER=sms_user \
  -e SMS_GATEWAY_PASS=xxxxxxxxx \
  -e ADMIN_USER=admin \
  -e ADMIN_PASSWORD=choose-a-password \
  -e ALLOWED_IP_RANGES=192.168.0.0/24 \
  -e LOG_TO_FILE=false \
  -e LOG_TO_ROUTEROS=true \
  mikrotik-sms-gateway
```

Open `http://SERVER-IP:8080/` for the admin page. Set `SMS_GATEWAY_URL` to the router's address; `localhost` inside the container is the container itself.

The Docker image is available on Docker Hub: https://hub.docker.com/r/roeller/mikrotik-sms-gateway. Building from source uses the version you checked out; an older published image may not yet contain these changes.

### Running without Docker

Use PHP 8.1+ with cURL, JSON and session support. Keep the complete project together and run `worker.php` as a separate supervised process. The worker must stay running; uploading only `sendsms.php` to a shared PHP webhost is no longer enough.

Start the built-in server from the project directory with its router:

```bash
php -S 0.0.0.0:8080 -t . router.php
```

`router.php` exposes only the SMS endpoint, admin page and static assets. Use the same route restrictions if you choose another webserver.

Set `SMS_STATE_DIR` to a RAM-backed directory such as `/dev/shm/sms-gateway`, and make the config directory writable by the service account. The worker and webserver need the same environment, user permissions and shared state directory.

---

## Docker on MikroTik (RouterOS v7+)

On the MikroTik device, use the Terminal in WinBox or SSH.

1. Add the `container` package for your RouterOS version. Enable container support:

```routeros
/system/device-mode/update container=yes
```

RouterOS asks you to confirm this with physical access to the router.

2. Create a veth interface and add it to your bridge. Adjust the addresses and bridge name to your network:

```routeros
/interface/veth/add name=veth1 address=192.168.0.100/24 gateway=192.168.0.254
/interface/bridge/port add bridge=bridge interface=veth1
```

3. Add the environment variables. On an existing installation, change the existing entries instead of adding duplicates:

```routeros
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_URL value="http://192.168.0.254"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_USER value="sms_user"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_PASS value="xxxxxxxxx"
/container/envs/add name=ENV_SMS_GATEWAY key=ADMIN_USER value="admin"
/container/envs/add name=ENV_SMS_GATEWAY key=ADMIN_PASSWORD value="choose-a-password"
/container/envs/add name=ENV_SMS_GATEWAY key=ALLOWED_IP_RANGES value="192.168.0.0/21,192.168.10.0/24"
/container/envs/add name=ENV_SMS_GATEWAY key=RATE_LIMITS value="192.168.0.175:10/600,*:5/600"
/container/envs/add name=ENV_SMS_GATEWAY key=SEND_INTERVAL_SECONDS value="10"
/container/envs/add name=ENV_SMS_GATEWAY key=SEND_LIMITS value="20/3600"
/container/envs/add name=ENV_SMS_GATEWAY key=QUEUE_MAX_AGE_SECONDS value="3600"
/container/envs/add name=ENV_SMS_GATEWAY key=QUEUE_MAX_MESSAGES value="100"
/container/envs/add name=ENV_SMS_GATEWAY key=SEND_MAX_ATTEMPTS value="3"
/container/envs/add name=ENV_SMS_GATEWAY key=HISTORY_LIMIT value="200"
/container/envs/add name=ENV_SMS_GATEWAY key=ONLY_DUTCH value="true"
/container/envs/add name=ENV_SMS_GATEWAY key=LOG_TO_FILE value="false"
/container/envs/add name=ENV_SMS_GATEWAY key=LOG_TO_ROUTEROS value="true"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_LOG_FILE value="/dev/shm/sms-gateway/sms.log"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_CONFIG_FILE value="/config/settings.json"
```

4. Create the persistent config directory and mount. This example uses a router with a persistent `flash` directory:

```routeros
/file/add name=flash/sms-gateway-config type=directory
/container/mounts/add name=SMS_CONFIG src=flash/sms-gateway-config dst=/config
```

Use an existing persistent USB/disk path instead if that is where you keep container settings. Check your device: the top-level RouterOS file area is RAM on some models. The source must survive a reboot and must not be inside `ram/sms-gateway`.

The supplied script requires this `SMS_CONFIG` mount to exist. It creates a separate `SMS_STATE` mount from `ram/sms-state` to `/dev/shm/sms-gateway`, so frequent queue and counter updates stay on the RAM disk. The examples and script use the same `name`, `envlist` and `mounts` syntax as the original setup; RouterOS versions with mount lists use their corresponding `list`/`mountlists` syntax.

5. Set the registry URL:

```routeros
/container/config/set registry-url=registry.hub.docker.com
```

6. Use `create_ramdrive_and_container.script` to create the 64 MB RAM disk and container. Schedule it every 5 minutes, as before. It restarts an existing stopped container without deleting its RAM disk, and recreates the container after a router reboot. The persistent config mount must be outside the RAM disk; check the paths near the top of the script before using it.

7. Open `http://192.168.0.100/` and sign in with `ADMIN_USER` and `ADMIN_PASSWORD`.

The old scheduler that sends `sms_queue.txt` must be disabled. The replacement `scheduler.script` only logs that it is retired; it never sends. Keep the container startup/check script scheduled; that is a different script.

### Updating an existing installation

1. Disable the old SMS queue scheduler before starting this version. It sends directly and would bypass the new global limits.
2. Keep the old `sms_queue.txt` for review. It is not automatically imported or drained: the old file has no creation times and might contain messages that were already sent.
3. Add the admin ENV settings and the persistent `/config` mount. `SMS_QUEUE_FILE` is no longer used.
4. Update the container startup script and image. Recreate the container so it gets the new environment and mounts.
5. Check the admin page. A fresh state shows a startup cooldown; this is expected, not a stuck queue.
6. Update callers to accept HTTP 202 as success. The API acknowledges queueing; the admin history shows whether RouterOS later accepted the SMS.

Do not leave another copy of the old gateway or sending scheduler running against the same modem if you want a single shared outgoing limit.

---

## Sending an SMS

Send JSON or form data to `sendsms.php`:

```bash
curl -X POST http://192.168.0.100/sendsms.php \
  -H "Content-Type: application/json" \
  -d '{"phone":"+31612345678","text":"Test message","request_id":"monitor-event-123"}'
```

Text must fit one GSM-7 SMS: at most 160 septets and the existing 160-byte UTF-8 limit. GSM extension characters such as `^`, `{` and `€` count as two septets; unsupported characters such as emoji return HTTP 400 instead of being truncated or split.

`request_id` is optional. Reuse the same ID when retrying the same request, so a lost HTTP response does not add the same message twice. The ID is scoped to the connecting IP and retained while its message is in the queue or recent history. Reusing it with different text or a different number returns HTTP 409. Deduplication is kept in RAM; this is not a permanent delivery receipt.

|HTTP response|Meaning|
|---|---|
|200|The same `request_id` already has a final result; inspect `status`. No new send is made|
|202|Queued, waiting for retry or already being sent; check the JSON `status`|
|400|Invalid request, phone number or message|
|403|Connecting IP is not allowed|
|409|The `request_id` was already used for different message contents|
|429|Incoming per-IP limit reached or queue is full; check `Retry-After`|
|503|Configuration or RAM state is unavailable|

A RouterOS success response means that RouterOS accepted the send command. It does not confirm delivery to the recipient's phone. If the connection times out after a command may have reached RouterOS, the result is marked uncertain instead of blindly retrying and possibly sending a duplicate.

## Admin page

Open `/`, `/index.php` or `/admin.php` and use the login form. The admin page uses `ADMIN_USER` and `ADMIN_PASSWORD` from the Docker environment. Without both values, admin access is disabled. Those credentials are separate from the MikroTik API account and are not editable in the web page. Sessions stay in RAM and expire after 30 minutes of inactivity or 12 hours in total. Failed logins are limited per connecting IP.

The page shows:

- Queue entries with timestamps, phone number, text, source IP and attempts.
- Recent sent, failed, expired and uncertain results, kept in RAM.
- Outgoing limits, startup cooldown and pause/resume controls. Individual queued messages can also be removed.
- A test SMS form, using the same queue and outgoing limits.
- The MikroTik SMS inbox. Receiving must be enabled on the router for messages to appear.
- Settings that can be saved to `/config/settings.json`.

The settings form covers the send interval, outgoing count/window, attempts, message age, queue size, history size and default pause state. Values saved in the config file override the matching ENV defaults. Connection details, IP restrictions, logging options and passwords remain in ENV.

The pause/resume buttons only change the RAM state. Save the default pause setting if it should also apply after a router reboot. Changing settings does not clear counters or remove an existing cooldown. Queue and recent history are not written to the config file.

## Environment variables

|Variable|Default / description|
|---|---|
|`SMS_GATEWAY_URL`|RouterOS URL reachable from the container, for example `http://192.168.0.254`|
|`SMS_GATEWAY_USER`|RouterOS REST API username|
|`SMS_GATEWAY_PASS`|RouterOS REST API password|
|`SMS_GATEWAY_VERIFY_TLS`|`false`: set `true` to verify the RouterOS HTTPS certificate|
|`SMS_PORT`|`lte1`: RouterOS modem port used to send SMS|
|`ADMIN_USER`|Admin page username; no default login|
|`ADMIN_PASSWORD`|Admin page password; no default login|
|`ALLOWED_IP_RANGES`|Allowed IPv4 addresses/CIDR ranges, separated by commas|
|`RATE_LIMITS`|Incoming `IP_OR_CIDR:MAX/SECONDS` rules. Default `*:10/600`; `off` disables only the incoming limit|
|`SEND_INTERVAL_SECONDS`|`10`: minimum seconds between outgoing attempts|
|`SEND_LIMITS`|`20/3600`: maximum outgoing attempts per rolling window|
|`SEND_MAX_ATTEMPTS`|`3`: total attempts per message, including the first|
|`QUEUE_MAX_AGE_SECONDS`|`3600`: maximum message age in seconds|
|`QUEUE_MAX_MESSAGES`|`100`: maximum queue size|
|`HISTORY_LIMIT`|`200`: maximum recent results kept in RAM|
|`ONLY_DUTCH`|`true`: only allow Dutch `+316...` mobile numbers|
|`LOG_TO_FILE`|`false` in the Docker image: write gateway log lines to `SMS_LOG_FILE` when enabled|
|`LOG_TO_ROUTEROS`|Write successful sends directly to RouterOS `/log` as `script,info`|
|`SMS_LOG_FILE`|Defaults to `sms.log` inside `SMS_STATE_DIR`; the path must stay directly inside that RAM directory|
|`SMS_STATE_DIR`|`/dev/shm/sms-gateway`: shared queue, counters and history; use RAM-backed storage|
|`SMS_CONFIG_FILE`|`/config/settings.json`: persistent settings, written when saving settings|

### Rate limiting

`RATE_LIMITS=192.168.0.175:10/600,*:5/600` allows the first IP a maximum of 10 requests in any rolling 600-second window. Every other allowed connecting IP gets a maximum of 5 requests. The first matching IP or CIDR rule takes precedence over `*`.

The source IP is the connecting `REMOTE_ADDR`. Forwarded headers are not trusted; when a proxy connects to the gateway, it is the proxy's address that counts.

The outgoing limit is separate. `SEND_LIMITS=20/3600` means at most 20 attempts in any rolling hour, shared by all clients, test messages and retries. It accepts one count/window pair; for example `50/86400` is 50 attempts per rolling day. The worker reserves an attempt before contacting RouterOS. File locking keeps simultaneous requests and worker processes from overwriting each other's queue or spending the same allowance twice.

Counters and recent history use RAM-backed files, not router flash. Do not point `SMS_STATE_DIR` at the persistent `/config` directory. Pausing or saving settings does not give a new allowance.

### Logging

`LOG_TO_FILE` and `LOG_TO_ROUTEROS` are independent and can both be enabled. `LOG_TO_ROUTEROS=true` uses the RouterOS REST credentials to call `/rest/execute` after a successful send. Container `logging=yes` is not required.

A successfully sent SMS produces a line like:

```text
17:42:10 script,info SMS SENT: +316XXXXXXXX - Test message - Source IP: 192.0.2.10
```

The optional file log rotates at 256 KiB with one backup. Its location must be directly inside `SMS_STATE_DIR`, so it stays in RAM. To read it from the MikroTik terminal:

```routeros
/container/shell [find name="sms-gateway"]
```

Then inside the container:

```sh
tail -n 50 /dev/shm/sms-gateway/sms.log
```

RouterOS `/log` normally uses a memory action. Your RouterOS logging rules determine whether that output is also written elsewhere.

---

## Requirements

- MikroTik device with RouterOS 7+ and REST API enabled.
- LTE modem for sending SMS, such as a MikroTik wAP LTE kit.
- Container package installed when running on the router.
- RAM-backed storage for the queue, counters and recent history.
- A persistent config directory for saved admin settings.
- Linux with PHP 8.1+, cURL and session support, and a supervised worker when running outside Docker.

## License

This project is licensed under the MIT License.

## Maintainer

This project is maintained by Roel Broersma | https://github.com/roelbroersma

## Contribute

Feel free to contribute!
Pull requests welcome!

If you want to help improve this project, please fork, code, and open a PR.
