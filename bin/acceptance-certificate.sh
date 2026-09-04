#!/usr/bin/env sh
# bin/acceptance-certificate.sh
# Native/Container 受入 profile で共有できる短期の localhost 用自己署名証明書を作成する。
set -eu

OUTPUT_DIR="${1:?certificate output directory is required}"
CERT_PATH="$OUTPUT_DIR/cert.pem"
KEY_PATH="$OUTPUT_DIR/key.pem"

command -v openssl >/dev/null 2>&1 || {
    echo "openssl is required to create the acceptance certificate" >&2
    exit 1
}

mkdir -p "$OUTPUT_DIR"
if test -e "$CERT_PATH" || test -e "$KEY_PATH"; then
    test -f "$CERT_PATH" && test -f "$KEY_PATH" || {
        echo "certificate and key must be created together" >&2
        exit 1
    }
    echo "受入用証明書は既に存在します: $OUTPUT_DIR"
    exit 0
fi

# Testbed は 127.0.0.1 または localhost で接続するため、両方を SAN に含める。
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 7 \
    -keyout "$KEY_PATH" \
    -out "$CERT_PATH" \
    -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"
chmod 600 "$KEY_PATH"
chmod 644 "$CERT_PATH"
echo "受入用証明書を作成しました: $OUTPUT_DIR"
