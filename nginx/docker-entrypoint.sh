#!/bin/sh
# Reverse proxy для панели:
# - Let's Encrypt (certbot) при PANEL_DOMAIN
# - самоподписанный TLS при PANEL_IP (без домена)

set -e

CONF_DIR=/etc/nginx/conf.d
CERTBOT_WEBROOT=/var/www/certbot
SELF_SIGNED_DIR=/etc/nginx/certs/selfsigned
CERT_PATH="/etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem"

mkdir -p "$CERTBOT_WEBROOT" "$SELF_SIGNED_DIR"

write_http_only() {
    cat >"$CONF_DIR/default.conf" <<EOF
server {
    listen 80;
    server_name ${1};

    location /.well-known/acme-challenge/ {
        root ${CERTBOT_WEBROOT};
    }

    location / {
        proxy_pass http://web:80;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF
}

write_https_letsencrypt() {
    cat >"$CONF_DIR/default.conf" <<EOF
server {
    listen 80;
    server_name ${PANEL_DOMAIN};

    location /.well-known/acme-challenge/ {
        root ${CERTBOT_WEBROOT};
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name ${PANEL_DOMAIN};

    ssl_certificate /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${PANEL_DOMAIN}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    location / {
        proxy_pass http://web:80;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
EOF
}

write_https_selfsigned() {
    cat >"$CONF_DIR/default.conf" <<EOF
server {
    listen 80;
    server_name ${PANEL_IP};

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name ${PANEL_IP};

    ssl_certificate ${SELF_SIGNED_DIR}/fullchain.pem;
    ssl_certificate_key ${SELF_SIGNED_DIR}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    location / {
        proxy_pass http://web:80;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
EOF
}

ensure_selfsigned_cert() {
    cert_file="${SELF_SIGNED_DIR}/fullchain.pem"
    key_file="${SELF_SIGNED_DIR}/privkey.pem"

    if [ -f "$cert_file" ] && [ -f "$key_file" ]; then
        if openssl x509 -in "$cert_file" -noout -checkend 86400 >/dev/null 2>&1; then
            echo "[nginx] Самоподписанный сертификат для ${PANEL_IP} найден."
            return 0
        fi
        echo "[nginx] Самоподписанный сертификат истекает — перевыпуск..."
    else
        echo "[nginx] Генерация самоподписанного сертификата для IP ${PANEL_IP}..."
    fi

    openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
        -keyout "$key_file" \
        -out "$cert_file" \
        -subj "/CN=${PANEL_IP}" \
        -addext "subjectAltName=IP:${PANEL_IP}"
}

start_renewal_loop() {
    (
        while true; do
            sleep 12h
            certbot renew --quiet --webroot -w "$CERTBOT_WEBROOT" --deploy-hook "nginx -s reload" || true
        done
    ) &
}

is_valid_ip() {
    case "$1" in
        *[!0-9.]*) return 1 ;;
        ""|*.*.*.*.*|.*..*) return 1 ;;
        *.*.*.*)
            IFS='.'
            set -- $1
            [ "$#" -eq 4 ] || return 1
            for octet in "$@"; do
                case "$octet" in
                    ''|*[!0-9]*) return 1 ;;
                esac
                [ "$octet" -le 255 ] || return 1
            done
            return 0
            ;;
        *) return 1 ;;
    esac
}

if [ -n "$PANEL_DOMAIN" ]; then
    if [ -z "$ACME_EMAIL" ]; then
        echo "[nginx] ОШИБКА: задан PANEL_DOMAIN=${PANEL_DOMAIN}, но не задан ACME_EMAIL."
        echo "[nginx] Добавьте в .env: ACME_EMAIL=you@example.com"
        exit 1
    fi

    if [ -f "$CERT_PATH" ]; then
        echo "[nginx] Сертификат Let's Encrypt найден для ${PANEL_DOMAIN} — HTTPS."
        write_https_letsencrypt
        start_renewal_loop
        exec nginx -g "daemon off;"
    fi

    echo "[nginx] Запрос сертификата Let's Encrypt для ${PANEL_DOMAIN}..."
    write_http_only "$PANEL_DOMAIN"
    nginx

    if ! certbot certonly \
        --webroot -w "$CERTBOT_WEBROOT" \
        -d "$PANEL_DOMAIN" \
        --email "$ACME_EMAIL" \
        --agree-tos \
        --non-interactive \
        --no-eff-email; then
        echo "[nginx] Не удалось получить сертификат. Проверьте DNS (A-запись), порты 80/443 и логи: docker compose logs nginx"
        echo "[nginx] Панель доступна по HTTP: http://${PANEL_DOMAIN}"
        start_renewal_loop
        exec nginx -g "daemon off;"
    fi

    write_https_letsencrypt
    nginx -s reload
    echo "[nginx] HTTPS включён: https://${PANEL_DOMAIN}"
    start_renewal_loop
    exec nginx -g "daemon off;"
fi

if [ -n "$PANEL_IP" ]; then
    if ! is_valid_ip "$PANEL_IP"; then
        echo "[nginx] ОШИБКА: PANEL_IP=${PANEL_IP} — укажите публичный IPv4 (например 203.0.113.5)."
        exit 1
    fi

    ensure_selfsigned_cert
    write_https_selfsigned
    echo "[nginx] HTTPS по IP включён: https://${PANEL_IP}"
    echo "[nginx] Сертификат самоподписанный — браузер покажет предупреждение; это нормально без домена."
    exec nginx -g "daemon off;"
fi

echo "[nginx] PANEL_DOMAIN и PANEL_IP не заданы — HTTP на порту 80."
write_http_only "_"
exec nginx -g "daemon off;"
