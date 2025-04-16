# MikroTik SMS Gateway

A lightweight SMS gateway for MikroTik routers with LTE and RouterOS 7+ support.
It uses a scheduler script on the MikroTik to check a local SMS queue (in case the LTE interface is down) and send SMS messages via `/tool sms send`.
A PHP backend (`sendsms.php`) allows secure, remote SMS requests.

You can host the `sendsms.php` file on a standard PHP server, or run it as a Docker container — even directly on the MikroTik router!

---

## How it works

- Messages are sent using `/tool sms send`.
- In case the messages could not be send (e.g. LTE is down), the MikroTik scheduler script checks `sms_queue.txt` periodically.
- If sending fails, the message is kept in the queue for a future attempt.
- Remote clients use `sendsms.php` to submit SMS messages via HTTP POST.
- IP range restrictions and number validation (e.g. Only allow Dutch phone numbers) are enforced.

--

## Option 1: Use with a regular PHP webhost

Just upload `sendsms.php` to any server with PHP 8.0+.

Send SMS via HTTP POST (JSON or form data):

```json
{
  "phone": "+31612345678",
  "text": "Test message"
}
```

## Option 2: Use Docker on MikroTik (RouterOS v7+)

On the Mikrotik device, do the following (via Windows->Terminal or via SSH/Telnet):

1. Add a RAM disk (recommended)
```/disk/add type=tmpfs tmpfs-max-size=64M slot=ram```

2. Add environment variables
```
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_URL value="http://localhost"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_USER value="sms_user"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_GATEWAY_PASS value="xxxxxxxxx"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_QUEUE_FILE value="sms_queue.txt"
/container/envs/add name=ENV_SMS_GATEWAY key=ALLOWED_IP_RANGES value="192.168.0.0/21,192.168.10.0/24"
/container/envs/add name=ENV_SMS_GATEWAY key=ONLY_DUTCH value="true"
/container/envs/add name=ENV_SMS_GATEWAY key=LOG_TO_FILE value="false"
/container/envs/add name=ENV_SMS_GATEWAY key=SMS_LOG_FILE value="/tmp/sms.log"
```

3. Set registry URL
```/container/config/set registry-url=registry.hub.docker.com```

4. Add the container
```/container/add remote-image=roeller/mikrotik-sms-gateway interface=veth1 root-dir=ram/sms-gateway envlist=ENV_SMS_GATEWAY name=sms-gateway```


## Docker Image
Image available on Docker Hub: https://hub.docker.com/r/roeller/mikrotik-sms-gateway

### Environment Variables
|Variable|Description|
|-----|-------|
|SMS_GATEWAY_URL|Local URL used to call the MikroTik API (typically http://localhost)
|SMS_GATEWAY_USER|Username for basic auth (sent from PHP to MikroTik)
|SMS_GATEWAY_PASS|Password for basic auth
|SMS_QUEUE_FILE|Filename where unsent messages are queued
|ALLOWED_IP_RANGES|Comma-separated list of allowed CIDR ranges (source IP restrictions)
|ONLY_DUTCH|Set to true to only allow Dutch mobile numbers (+316...)
|LOG_TO_FILE|true = write to file, false = echo to stdout (Docker logs)
SMS_LOG_FILE|Path to log file (used if LOG_TO_FILE=true)

## MikroTik Scheduler Script
A scheduler task on the router:
- Checks sms_queue.txt
- Sends messages with /tool sms send
- Adds failed messages back to the queue

The PHP backend adds messages to the queue when needed.

## Requirements
- MikroTik device with RouterOS 7+
- Container package installed
- LTE modem (for sending SMS), like Mikrotik WAP (ac) LTE Kit
- PHP 8.1+ (if running outside Docker)

## License
This project is licensed under the MIT License.

## Maintainer
This project is maintained by Roel Broersma | https://github.com/roelbroersma

## Contribute
Feel free to contribute!
Pull requests welcome!

Feel free to add features like retry counters, rate limits, or a web interface.
If you want to help improve this project, please fork, code, and open a PR.

