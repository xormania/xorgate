#!/bin/sh
set -eu

mkdir -p /app/var/cache /app/var/log "${XORGATE_ARTIFACT_DIR:-/var/lib/xorgate/artifacts}"

if [ "$1" = 'frankenphp' ]; then
    test -f /app/vendor/autoload.php || {
        echo 'Run ./bin/setup before starting the application.' >&2
        exit 1
    }
fi

exec docker-php-entrypoint "$@"
