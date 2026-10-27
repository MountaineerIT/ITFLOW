#!/bin/sh
# Mountaineer IT - make (or refresh) a copy of the TEST install, for checks that run side by side (the hook proof, the functional
# pass): database itflow_tN + files /var/www/itflow_tN, served as itflow-tN.test (vhost zz-itflow-tN.conf, made the first time).
#   sh scripts/mit_test_copy.sh 1 [2 3 4]       (as root, on the test server only - never production)
# The copy gets the test install's database and files as they are now, and its config.php with only $database and
# $config_base_url changed (the stand-in addresses stay). Smoke tests run against it with
#   ITFLOW_URL=http://itflow-tN.test ITFLOW_DB=itflow_tN ITFLOW_ROOT=/var/www/itflow_tN ITFLOW_PHP_LOG=/var/log/apache2/itflow-tN-error.log
set -e
SRC_ROOT="${MIT_COPY_FROM:-/var/www/itflow}"
SRC_DB="$(php -r 'include $argv[1]; echo $database;' "$SRC_ROOT/config.php")"
SRC_URL="$(php -r 'include $argv[1]; echo $config_base_url;' "$SRC_ROOT/config.php")"
case "$SRC_URL" in *.test) ;; *) echo "The source install ($SRC_URL) is not a .test install - refusing."; exit 1 ;; esac
[ $# -gt 0 ] || { echo "Which copies? e.g. sh $0 1 2 3 4"; exit 1; }
for N in "$@"; do
    case "$N" in *[!0-9]*|'') echo "Copy number must be a number: $N"; exit 1 ;; esac
    DB="itflow_t$N"; ROOT="/var/www/itflow_t$N"; HOST="itflow-t$N.test"
    echo "== $HOST"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL ON \`$DB\`.* TO 'itflow'@'localhost'"
    mysqldump --single-transaction --routines --triggers "$SRC_DB" | mysql "$DB"
    mkdir -p "$ROOT"
    rsync -a --delete --exclude config.php "$SRC_ROOT/" "$ROOT/"
    sed -e "s/^\$database = .*/\$database = '$DB';/" -e "s/^\$config_base_url = .*/\$config_base_url = '$HOST';/" "$SRC_ROOT/config.php" > "$ROOT/config.php"
    chown -R www-data:www-data "$ROOT"
    if [ ! -f "/etc/apache2/sites-available/zz-itflow-t$N.conf" ]; then
        cat > "/etc/apache2/sites-available/zz-itflow-t$N.conf" <<EOF
<VirtualHost *:80>
    ServerName $HOST
    DocumentRoot $ROOT
    <Directory $ROOT>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/itflow-t$N-error.log
</VirtualHost>
EOF
        a2ensite "zz-itflow-t$N" >/dev/null && (service apache2 reload >/dev/null 2>&1 || true)
    fi
    grep -q " $HOST\$" /etc/hosts || echo "127.0.0.1 $HOST" >> /etc/hosts
    php -r 'include $argv[1]; echo "   database ", $database, ", ", $config_base_url, "\n";' "$ROOT/config.php"
done
