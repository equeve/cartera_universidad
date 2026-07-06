# Usamos la imagen oficial de PHP con Apache incorporado
FROM php:8.2-apache

# Instalar las extensiones necesarias para conectarse a PostgreSQL (PDO)
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Copiar todos los archivos de tu proyecto al directorio web de Apache
COPY . /var/www/html/

# Exponer el puerto 80 para el tráfico web
EXPOSE 80