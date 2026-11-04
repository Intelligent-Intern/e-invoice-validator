# syntax=docker/dockerfile:1.7
ARG PHP_IMAGE=php:8.4.27-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76
FROM composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS composer_binary

FROM ${PHP_IMAGE} AS native_build
RUN apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS libcurl4-openssl-dev libssl-dev libxml2-dev zlib1g-dev curl unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=king_source /extension /build/king/extension
COPY --from=king_source /infra/scripts/bootstrap-saxonc.sh /build/king/infra/scripts/bootstrap-saxonc.sh
WORKDIR /build/king/extension
RUN phpize && ./configure --enable-king && make -j4 \
    && install -D modules/king.so /native/king.so
RUN KING_SAXONC_PREFIX=/native/saxonc bash /build/king/infra/scripts/bootstrap-saxonc.sh
RUN KING_SAXONC_LIBRARY=/native/saxonc/libsaxonc-core-he.so \
    php -n -d extension=/native/king.so -r \
    '$s=king_xslt_engine_status(); if(!($s["available"]??false)) {fwrite(STDERR,json_encode($s));exit(1);} echo $s["product"],PHP_EOL;'

FROM ${PHP_IMAGE} AS application_build
COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/validator
COPY composer.json ./
COPY src/ ./src/
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts --no-interaction

FROM ${PHP_IMAGE} AS runtime
ARG KING_COMMIT=unknown
LABEL org.opencontainers.image.title="King E-Invoice Validator" \
      org.opencontainers.image.description="Native PHP/King/SaxonC invoice validator, without a Java process" \
      io.intelligent-intern.king.revision="${KING_COMMIT}"
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils libxml2 libcurl4 libssl3 zlib1g \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 validator \
    && useradd --uid 10001 --gid 10001 --no-create-home --home-dir /nonexistent validator
COPY --from=native_build /native/king.so /usr/local/lib/php/extensions/king.so
COPY --from=native_build /native/saxonc /opt/saxonc
COPY --from=king_source /LICENSE /opt/licenses/king/LICENSE
RUN printf '%s\n' 'extension=/usr/local/lib/php/extensions/king.so' \
        'memory_limit=256M' 'max_execution_time=60' \
        'display_errors=stderr' 'log_errors=On' \
        > /usr/local/etc/php/conf.d/e-invoice-validator.ini \
    && php -r 'foreach(["dom","libxml","json","iconv","king"] as $e) {if(!extension_loaded($e)) {fwrite(STDERR,"Missing PHP extension: $e\n");exit(1);}}'
ENV KING_SAXONC_LIBRARY=/opt/saxonc/libsaxonc-core-he.so \
    HOME=/tmp TMPDIR=/tmp
WORKDIR /opt/validator
COPY --from=application_build /opt/validator/vendor ./vendor/
COPY composer.json SECURITY.md LICENSE THIRD_PARTY_NOTICES.md ./
COPY docker/php-security.ini /usr/local/etc/php/conf.d/zz-security.ini
COPY tools/check-runtime.php ./tools/check-runtime.php
COPY src/ ./src/
COPY bin/ ./bin/
COPY rules/ ./rules/
COPY tests/ ./tests/
COPY examples/ ./examples/
RUN php tools/check-runtime.php
USER 10001:10001
ENTRYPOINT ["php", "/opt/validator/bin/e-invoice-validator"]
CMD ["--help"]
