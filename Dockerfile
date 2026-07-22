FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite
RUN printf '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
        CGIPassAuth On\n\
    </Directory>\n\
    RewriteEngine On\n\
    RewriteCond %%{REQUEST_URI} ^/hanzestatus/(assets|api)/(.*)$\n\
    RewriteRule ^ /%%1/%%2 [L]\n\
    RewriteRule ^hanzestatus/(.*)$ /$1 [L]\n\
</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/
COPY core/ /var/www/core/
