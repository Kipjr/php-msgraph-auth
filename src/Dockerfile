FROM php:8.2-apache

COPY cert.sh /
COPY --chmod=0755 composer.phar /usr/local/bin/composer
ENV DNS=${DNS}
RUN apt-get update; \
	apt-get install -y --no-install-recommends git unzip; \
    apt get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod ssl; \
    /cert.sh "${DNS}"
CMD ["apache2-foreground"]


