FROM alpine:3.19

RUN apk add --no-cache php81 curl

WORKDIR /app
COPY sendsms.php .

EXPOSE 80
CMD ["php81", "-S", "0.0.0.0:80", "sendsms.php"]
