FROM php:8.2-apache

COPY cert.sh /

ENV DNS=${DNS}
RUN a2enmod ssl \
    && /cert.sh "${DNS}"


