#!/usr/bin/env bash
# Optional helper script for local isolated user-space MySQL 8.4 daemon
DAEMON_SCRIPT="/home/harishankar/opt/mysql-8.4/bin/mysql84-status"

if [ -x "$DAEMON_SCRIPT" ]; then
    exec "$DAEMON_SCRIPT"
else
    echo "Note: This script manages the isolated user-space MySQL 8.4 daemon."
    echo "For standard system MySQL status, run:"
    echo "  sudo systemctl status mysql    # Linux (systemd)"
    echo "  mysqladmin -u root -p ping      # Command line ping"
fi
