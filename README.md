# MikroTik SMS Gateway

A lightweight SMS gateway for MikroTik routers with LTE and RouterOS 7+ support.
When an SMS could not be sent, it is queued on the MikroTik router in a text file. This SMS gateway was made because the default MikroTik API is not very user-friendly.

You can run the PHP script on a server but if you want an all-in-one solution, it can run on the MikroTik router!
How does that work? -> The MikroTik router uses the Container package. After a reboot the supplied script creates a RAM disk and starts the Docker container which hosts the PHP file.

The MikroTik router also uses a scheduler script to check if there is anything in the local SMS queue (for example when the LTE interface was down) and sends the messages with `/tool sms send`.
The PHP backend (`sendsms.php`) allows remote SMS requests protected by source-IP restrictions and per-IP rate limiting. `SMS_GATEWAY_USER` and `SMS_GATEWAY_PASS` authenticate the gateway to RouterOS; they do not authenticate incoming HTTP clients.

---


## How it works

- Messages are sent using `/tool sms send`.
- If a message could not be sent (e.g. LTE is down), the MikroTik scheduler script checks `sms_queue.txt` periodically.
- If sending fails, the message is kept in the queue for a future attempt.
- Remote clients use `sendsms.php` to submit SMS messages via HTTP POST.
- IP range restrictions and number validation (e.g. Only allow Dutch phone numbers) are enforced.
- Per-IP rolling-window rate limits are kept only in APCu memory and reset when the container restarts.

The container itself is stored in `ram/sms-gateway`. APCu uses separate process memory. The queue file `sms_queue.txt` stays in the normal RouterOS file storage so queued messages are not lost when only the container restarts.

---


### Option 1: Use with a regular PHP webhost

Upload `sendsms.php` to a server with PHP 8.1+ and APCu enabled. The Docker image already includes APCu.

Send SMS via HTTP POST (JSON or form data):

```json
{
  "phone": "+31612345678",
  "text": "Test message"
}
```

Example:

```bash
curl -X POST http://192.168.0.100/sendsms.php \
  -H "Content-Type: application/json" \
  -d '{"phone":"+31612345678","text":"Test message"}'
```


### Option 2: Use Docker on MikroTik (RouterOS v7+) to host the PHP file!

On the MikroTik device, do the following (via Windows->Terminal or via SSH/Telnet):

1. Add the 'container' package (download it from the mikrotik website and upload and activate it on the router). To activate it you need physical access to the router because after uploading and installing the package, you need to type:
```
/system/device-mode/update container=yes
```
which asks you to press the reset or power button. This is just for security purposes so a hacker cannot easily add the container package and do whatever he wants.

2. Create a veth1 interface and add it to the Bridge
```
/interface/veth/add name=veth1 address=192.168.0.100/24 gateway=192.168.0.254
/interface/bridge/port add bridge=bridge interface=veth1
```

3. Add environment variables
```
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_URL value="http://localhost"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_USER value="sms_user"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_PASS value="xxxxxxxxx"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_QUEUE_FILE value="sms_queue.txt"
/container/envs/add name=ENV_SMS_GATEWAY key=ALLOWED_IP_RANGES value="192.168.0.0/21,192.168.10.0/24"
/container/envs/add name=ENV_SMS_GATEWAY key=RATE_LIMITS value="192.168.0.175:10/600,*:5/600"
/container/envs/add name=ENV_SMS_GATEWAY key=ONLY_DUTCH value="true"
/container/envs/add name=ENV_SMS_GATEWAY key=LOG_TO_FILE value="false"
/container/envs/add name=ENV_SMS_GATEWAY key=LOG_TO_ROUTEROS value="true"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_LOG_FILE value="/tmp/sms.log"
```

4. Set registry URL
```
/container/config/set registry-url=registry.hub.docker.com
```

5. You can now use the automatic script or continue manually with steps 6, 7 and 8.
If you do it manually, you have to repeat those steps after every reboot.

For the automatic setup, download `create_ramdrive_and_container.script` and schedule it to run every 5 minutes. You can then skip steps 6, 7 and 8.


6. Add a RAM disk (only when doing it manually)
```
/disk/add type=tmpfs tmpfs-max-size=50M slot=ram
```


7. Add the container (only when doing it manually)
```
/container/add remote-image=roeller/mikrotik-sms-gateway interface=veth1 root-dir=ram/sms-gateway envlist=ENV_SMS_GATEWAY name=sms-gateway
```

8. Start the container
```
/container/start number=0
```

---

## Docker Image
Image available on Docker Hub: https://hub.docker.com/r/roeller/mikrotik-sms-gateway


### Environment Variables
|Variable|Description|
|-----|-------|
|SMS_GATEWAY_URL|Local URL used to call the MikroTik API (typically http://localhost)
|SMS_GATEWAY_USER|Username used by PHP to authenticate to the MikroTik REST API
|SMS_GATEWAY_PASS|Password used by PHP to authenticate to the MikroTik REST API
|SMS_QUEUE_FILE|Filename where unsent messages are queued
|ALLOWED_IP_RANGES|Comma-separated list of allowed CIDR ranges (source IP restrictions)
|RATE_LIMITS|Comma-separated `IP_OR_CIDR:MAX/SECONDS` rules. Default: `*:10/600`; use `off` to disable
|ONLY_DUTCH|Set to true to only allow Dutch mobile numbers (+316...)
|LOG_TO_FILE|Set to true to append gateway log lines to SMS_LOG_FILE
|LOG_TO_ROUTEROS|Set to true to add successful direct sends to the RouterOS `/log` as `script,info`
|SMS_LOG_FILE|Path to log file (used if LOG_TO_FILE=true)


### Logging

`LOG_TO_FILE` and `LOG_TO_ROUTEROS` are independent and can both be enabled. `LOG_TO_ROUTEROS=true` uses the configured RouterOS REST credentials to call `/rest/execute` after a successful direct send. Container `logging=yes` is not required.

A successfully sent SMS produces a line like:

```text
17:42:10 script,info SMS SENT: +316XXXXXXXX - Test message - Source IP: 192.0.2.10
```


### Rate limiting

`RATE_LIMITS=192.168.0.175:10/600,*:5/600` allows the first IP a maximum of 10 requests in any rolling 600-second window. Every other allowed connecting IP gets a maximum of 5 requests. Exact IP or CIDR rules take precedence over `*`.

The counters and timestamps are stored in APCu shared memory. They do not use a counter file or write to disk, and they are automatically cleared when the container restarts.

The limit uses the connecting IP from `REMOTE_ADDR` and is applied before phone-number and message validation, so invalid requests also count. It limits incoming HTTP requests only; retries from `scheduler.script` are not counted. When the limit is reached, the gateway returns HTTP 429 with a `Retry-After` header.


---

## MikroTik Scheduler Script
A scheduler task on the router:
- Checks sms_queue.txt
- Sends messages with /tool sms send
- Adds failed messages back to the queue

The PHP backend adds messages to the queue when needed.

---

## Requirements
- MikroTik device with RouterOS 7+
- Container package installed
- LTE modem (for sending SMS), like MikroTik WAP (ac) LTE Kit
- PHP 8.1+ (if running outside Docker)
- APCu enabled (if running outside Docker)

---

## License
This project is licensed under the MIT License.


## Maintainer
This project is maintained by Roel Broersma | https://github.com/roelbroersma


## Contribute
Feel free to contribute!
Pull requests welcome!

Feel free to add features like retry counters, incoming authentication, or a web interface.
If you want to help improve this project, please fork, code, and open a PR.
