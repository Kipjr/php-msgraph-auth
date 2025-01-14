FROM php:8.2-apache

COPY cert.sh /

ENV DNS=${DNS}
RUN /cert.sh "${DNS}"


