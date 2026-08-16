#!/usr/bin/env bash
set -euo pipefail

ROOT=/var/oled/data/AshatHub
DIST="$ROOT/apps/ashat-hub-web/dist"
CONF=/etc/httpd/conf.d/ashathub-rust-vite.conf

if [[ ! -f "$DIST/index.html" ]]; then
  echo "Vite production bundle is missing: $DIST/index.html" >&2
  exit 1
fi

# The backup hostname must be covered by the same certificate.
sudo certbot certonly --webroot -w "$DIST" --cert-name agpstudios.org \
  --non-interactive --agree-tos -m admin@agpstudios.org --expand \
  -d agpstudios.org -d www.agpstudios.org -d ashat.ra3.us

sudo tee "$CONF" >/dev/null <<CONF
# AshatHub Rust + Vite production site.
# This file intentionally contains no PHP handler or PHP front controller.

ProxyPreserveHost On
ProxyRequests Off

<VirtualHost *:80>
    ServerName agpstudios.org
    ServerAlias www.agpstudios.org ashat.ra3.us *.agpstudios.org
    DocumentRoot $DIST

    ProxyPass        /api/ http://127.0.0.1:3100/api/
    ProxyPassReverse /api/ http://127.0.0.1:3100/api/
    ProxyPass        /health http://127.0.0.1:3100/health
    ProxyPassReverse /health http://127.0.0.1:3100/health
    ProxyPass        /ready http://127.0.0.1:3100/ready
    ProxyPassReverse /ready http://127.0.0.1:3100/ready
    ProxyPass        /host/ http://127.0.0.1:3100/host/
    ProxyPassReverse /host/ http://127.0.0.1:3100/host/
    ProxyPass        /x/ http://127.0.0.1:3100/x/
    ProxyPassReverse /x/ http://127.0.0.1:3100/x/

    <Directory "$DIST">
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.html
        FallbackResource /index.html
    </Directory>

    <FilesMatch "^\\.|\\.(env|sql|json|ya?ml|lock|pem|key|bak|backup|dump|gz|tgz|zip|tar)$">
        Require all denied
    </FilesMatch>

    ErrorLog /var/log/httpd/ashathub_rust_error.log
    CustomLog /var/log/httpd/ashathub_rust_access.log combined
</VirtualHost>

<VirtualHost *:443>
    ServerName agpstudios.org
    ServerAlias www.agpstudios.org ashat.ra3.us *.agpstudios.org
    DocumentRoot $DIST

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/agpstudios.org/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/agpstudios.org/privkey.pem

    ProxyPass        /api/ http://127.0.0.1:3100/api/
    ProxyPassReverse /api/ http://127.0.0.1:3100/api/
    ProxyPass        /health http://127.0.0.1:3100/health
    ProxyPassReverse /health http://127.0.0.1:3100/health
    ProxyPass        /ready http://127.0.0.1:3100/ready
    ProxyPassReverse /ready http://127.0.0.1:3100/ready
    ProxyPass        /host/ http://127.0.0.1:3100/host/
    ProxyPassReverse /host/ http://127.0.0.1:3100/host/
    ProxyPass        /x/ http://127.0.0.1:3100/x/
    ProxyPassReverse /x/ http://127.0.0.1:3100/x/

    <Directory "$DIST">
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.html
        FallbackResource /index.html
    </Directory>

    <FilesMatch "^\\.|\\.(env|sql|json|ya?ml|lock|pem|key|bak|backup|dump|gz|tgz|zip|tar)$">
        Require all denied
    </FilesMatch>

    ErrorLog /var/log/httpd/ashathub_rust_ssl_error.log
    CustomLog /var/log/httpd/ashathub_rust_ssl_access.log combined
</VirtualHost>
CONF

# Retire PHP dispatch completely. The application tree is removed only after
# this script's configuration test succeeds and the Rust service is healthy.
sudo mv /etc/httpd/conf.d/ashathub.conf /etc/httpd/conf.d/ashathub.conf.php-retired 2>/dev/null || true
sudo mv /etc/httpd/conf.d/agpstudios-ssl.conf /etc/httpd/conf.d/agpstudios-ssl.conf.php-retired 2>/dev/null || true
sudo mv /etc/httpd/conf.d/pawsandparcels.agpstudios.org.conf /etc/httpd/conf.d/pawsandparcels.agpstudios.org.conf.retired 2>/dev/null || true
sudo mv /etc/httpd/conf.d/php.conf /etc/httpd/conf.d/php.conf.retired 2>/dev/null || true
sudo mv /etc/httpd/conf.d/ashat-rust-api.conf /etc/httpd/conf.d/ashat-rust-api.conf.retired 2>/dev/null || true
sudo apachectl configtest
sudo systemctl reload httpd
sudo systemctl disable --now php-fpm.service 2>/dev/null || true
printf 'Rust + Vite Apache site is active; all PHP vhosts and PHP-FPM are retired.\n'
