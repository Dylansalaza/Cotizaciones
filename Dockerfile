# Imagen PHP con extensión zip (para exportar el .docx) y cURL (geolocalización).
FROM php:8.2-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN mkdir -p /app/data/uploads && chmod -R 0777 /app/data

# El host inyecta $PORT; el servidor embebido de PHP sirve la app.
EXPOSE 10000
CMD php -S 0.0.0.0:${PORT:-10000} -t /app
