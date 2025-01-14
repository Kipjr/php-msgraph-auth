#!/bin/bash
DNS=${1:-192.168.2.6}

mkdir -p /etc/apache/ssl

openssl req -x509 -nodes -days 365 \
  -newkey rsa:2048 \
  -keyout /etc/apache2/ssl/server.key \
  -out /etc/apache2/ssl/server.crt \
  -subj "/C=NL/L=City/O=Organization/OU=Department/CN=${DNS}"
  -extensions v3_ca \
  -addext "subjectAltName=DNS:${DNS}"


