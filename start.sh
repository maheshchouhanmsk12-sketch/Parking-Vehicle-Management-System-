#!/bin/bash
set -e

# Render assigns a dynamic port via $PORT (defaults to 10000)
PORT="${PORT:-10000}"

echo "========================================="
echo " Starting Apache for Render Environment"
echo " Listening on PORT: ${PORT}"
echo "========================================="

# Update Apache ports configuration
sed -i "s/Listen [0-9]*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Render Reverse Proxy & Port 10000 Trailing Slash Fix Configuration
cat << 'EOF' > /etc/apache2/conf-available/render-reverse-proxy.conf
# Do not leak internal port in Apache-generated redirects
UseCanonicalName Off
UseCanonicalPhysicalPort Off
ServerName localhost

# Support Render SSL termination headers
RemoteIPHeader X-Forwarded-For
RemoteIPInternalProxy 10.0.0.0/8
RemoteIPInternalProxy 172.16.0.0/12
RemoteIPInternalProxy 192.168.0.0/16

# Map HTTPS from Render's X-Forwarded-Proto header
SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
EOF

a2enconf render-reverse-proxy

# Launch Apache in foreground
exec apache2-foreground
