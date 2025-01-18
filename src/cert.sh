#!/bin/bash
DNS=${1:-localhost}
DIRECTORY=${2:-/etc/apache2}

mkdir -p "${DIRECTORY}/ssl"
touch "${DIRECTORY}/ssl/server.key"
touch "${DIRECTORY}/ssl/server.crt"

openssl req -x509 -nodes -days 7 \
  -newkey rsa:4096 \
  -keyout ${DIRECTORY}/ssl/server.key \
  -out ${DIRECTORY}/ssl/server.crt \
  -subj "/C=NL/L=City/O=Organization/OU=Department/CN=${DNS}" \
  -extensions v3_ca \
  -addext "basicConstraints=critical,CA:FALSE" \
  -addext "subjectAltName=DNS:${DNS}"