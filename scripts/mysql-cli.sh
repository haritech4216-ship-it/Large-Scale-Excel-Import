#!/usr/bin/env bash
# Optional helper script for connecting to MySQL CLI
DAEMON_SCRIPT="/home/harishankar/opt/mysql-8.4/bin/mysql84-cli"

if [ -x "$DAEMON_SCRIPT" ]; then
    exec "$DAEMON_SCRIPT" "$@"
else
    # Fallback to standard mysql client
    exec mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" "$@"
fi
