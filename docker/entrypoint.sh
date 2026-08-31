#!/bin/sh
set -e

# Laravel's caches are built HERE, at container start, never at image build:
# config:cache freezes the environment it sees, and the environment differs
# per deployment (ADR-0049 §2). bootstrap/cache may be an empty mount — seed
# it with the build-time package manifests first, or artisan cannot boot.
if [ ! -f bootstrap/cache/packages.php ]; then
    cp /opt/bootstrap-cache/*.php bootstrap/cache/
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# The role command becomes PID 1 so SIGTERM reaches Octane/Horizon directly.
exec "$@"
