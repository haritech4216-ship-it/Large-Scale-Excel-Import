#!/usr/bin/env bash
# Optional helper script for local isolated user-space MySQL 8.4 daemon
DAEMON_SCRIPT="/home/harishankar/opt/mysql-8.4/bin/mysql84-stop"

if [ -x "$DAEMON_SCRIPT" ]; then
    exec "$DAEMON_SCRIPT"
else
    echo "Note: This script manages the isolated user-space MySQL 8.4 daemon."
    echo "For standard system MySQL, stop the service using:"
    echo "  sudo systemctl stop mysql    # Linux (systemd)"
    echo "  brew services stop mysql    # macOS"
fi
