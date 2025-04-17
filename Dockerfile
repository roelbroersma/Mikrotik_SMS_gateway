# Alpine with PHP CLI (built-in server)
# Using Alpine 3.21 (or latest stable)
FROM alpine:3.21
RUN apk add --no-cache php82-cli

# Set up document root and copy PHP script
WORKDIR /var/www
COPY sendsms.php /var/www/

# Optionally, ensure the script is the default index
# (Uncomment the next line to make sendsms.php the index page)
# RUN cp /var/www/sendsms.php /var/www/index.php

# Expose port 80 and start PHP built-in server on 0.0.0.0:80
EXPOSE 80
CMD ["php82", "-S", "0.0.0.0:80", "-t", "/var/www"]
