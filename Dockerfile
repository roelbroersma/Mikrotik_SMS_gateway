FROM alpine:3.19

RUN apk add --no-cache php81 php81-cgi lighttpd curl

# Configure lighttpd for PHP
RUN mkdir -p /var/www/localhost/htdocs && \
    echo "cgi.assign = ( \".php\" => \"/usr/bin/php81-cgi\" )" >> /etc/lighttpd/lighttpd.conf && \
    echo "server.document-root = \"/var/www/localhost/htdocs\"" >> /etc/lighttpd/lighttpd.conf

WORKDIR /var/www/localhost/htdocs
COPY sendsms.php .

EXPOSE 80
CMD ["lighttpd", "-D", "-f", "/etc/lighttpd/lighttpd.conf"]

