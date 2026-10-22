# Alpine with PHP CLI (built-in server)
# Pinned to Alpine 3.24 (Fixed PHP2Shell vulnerability)
FROM alpine:3.24
# The HTTP server and sender share a small, locked state file on tmpfs.
RUN apk add --no-cache php85-cli php85-curl php85-session

# Set up document root and copy PHP script
WORKDIR /var/www
COPY sendsms.php gateway.php worker.php admin.php index.php router.php admin.css admin.js /var/www/
COPY entrypoint.sh /usr/local/bin/sms-gateway
RUN chmod +x /usr/local/bin/sms-gateway && mkdir -p /config

ENV SMS_STATE_DIR=/dev/shm/sms-gateway \
    SMS_CONFIG_FILE=/config/settings.json \
    LOG_TO_FILE=false

# Expose the API and admin page on the same small PHP server.
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/sms-gateway"]
