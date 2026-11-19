#!/bin/sh
# Run by tests/mocks/install.php (as root) with the install's root as $1: the Microsoft 365 / Graph stand-in is its own server,
# php -S 127.0.0.1:8765, serving /srv/mockms/router.php (copied from here each time - php -S reads the router on every request, so a
# changed router needs no restart). One server for every test install on this machine; started only when nothing answers there yet.
# Then setup.php sets the install's Microsoft 365 app, mailbox and an Outlook link up against it.
here="$(cd "$(dirname "$0")" && pwd)"
dir=/srv/mockms
mkdir -p "$dir"
cp "$here/router.php" "$dir/router.php"
cp "$here/../guard.php" "$dir/guard.php"
echo "Put here by tests/mocks/ms/start.sh - without it the stand-in answers nobody." > "$dir/.mit-test-stand-in"
[ -f "$dir/state.json" ] || echo '{"mail":[],"sent":[]}' > "$dir/state.json"
touch "$dir/state.json.lock"
chown www-data:www-data "$dir" 2>/dev/null
chmod 0777 "$dir"
chmod 0666 "$dir/state.json" "$dir/state.json.lock" 2>/dev/null

if ! curl -s -o /dev/null --max-time 3 --noproxy '*' http://127.0.0.1:8765/_known; then
    mkdir -p /var/tmp/itflow_mocks
    PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:8765 -t "$dir" "$dir/router.php" >> /var/tmp/itflow_mocks/ms-server.log 2>&1 &
    i=0
    while [ $i -lt 50 ] && ! curl -s -o /dev/null --max-time 1 --noproxy '*' http://127.0.0.1:8765/_known; do
        sleep 0.1
        i=$((i + 1))
    done
fi
php "$here/setup.php" "$1"
