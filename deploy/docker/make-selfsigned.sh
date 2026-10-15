#!/bin/sh
# Gera um certificado AUTOASSINADO para TESTAR o compose. O Agent não o aceita (exige CA confiável);
# em produção use o certificado da CA corporativa em ./certs/fullchain.pem e ./certs/privkey.pem.
# Uso: ./make-selfsigned.sh toolit.empresa.local
set -eu

host="${1:?informe o nome DNS, ex.: toolit.empresa.local}"
mkdir -p certs
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 90 \
    -subj "/CN=${host}" -addext "subjectAltName=DNS:${host}" \
    -keyout certs/privkey.pem -out certs/fullchain.pem
# O nginx roda como usuário sem privilégios (uid 101) e precisa ler a chave.
chmod 644 certs/fullchain.pem
chmod 640 certs/privkey.pem
echo "Gerado em ./certs (validade 90 dias). Falta entregar a chave ao nginx:  sudo chown 101 certs/privkey.pem"
