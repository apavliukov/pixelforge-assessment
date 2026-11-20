#!/bin/sh
# Starts everything the app needs in one container (see docs/DECISIONS.md).
set -e

php artisan migrate --force
# Creates the demo accounts that do not exist yet; safe to repeat on every start.
php artisan db:seed --force
php artisan optimize

# Workaround for Render's free tier, which has no background workers and no cron
# jobs: the queue worker and the scheduler run here, next to the web server.
# On a paid plan each becomes its own Render service and these lines go away.
#
# Two workers, because a worker runs one job at a time: sends to Pixelforge can
# each take up to 10 seconds, and results that have already arrived by webhook
# must not wait behind them. The second worker also runs the pricing refresh.
#
# A worker exits on its own every hour (--max-time, to release memory) and may
# also crash. Nothing else would start it again, so the loop does; "sleep 1"
# keeps a worker that fails immediately from spinning the CPU.
(while true; do php artisan queue:work --queue=submissions --max-time=3600 || sleep 1; done) &
(while true; do php artisan queue:work --queue=webhooks,default --max-time=3600 || sleep 1; done) &
# Laravel's replacement for a system cron entry: runs the schedule every minute.
php artisan schedule:work &

# PORT is set by Render itself (10000 by default): its load balancer accepts
# HTTPS on the public domain and forwards plain HTTP to this port inside the
# container. "${PORT:-10000}" means "use PORT, or 10000 if it is not set", so
# the image also starts outside Render.
export SERVER_NAME=":${PORT:-10000}"
exec frankenphp run --config /etc/frankenphp/Caddyfile
