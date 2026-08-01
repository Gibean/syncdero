FROM php:8.3-cli-alpine

# Instalar librerías de clientes y extensiones para PostgreSQL, MySQL/MariaDB y SQLite
RUN apk add --no-cache \
    postgresql-dev \
    postgresql-client \
    mariadb-client \
    sqlite-dev \
    sqlite \
    git \
    bash \
    && docker-php-ext-install \
        pdo_pgsql \
        pdo_mysql \
        pdo_sqlite

WORKDIR /app

# Copiar el código fuente de SYNCDERO
COPY . /app

# Permisos para scripts ejecutables
RUN chmod +x /app/bin/syncdero 2>/dev/null || true

EXPOSE 8088

# Por defecto arranca el servidor Web Dashboard integrado
CMD ["php", "-S", "0.0.0.0:8088", "-t", "/app/public"]
