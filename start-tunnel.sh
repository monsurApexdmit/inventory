#!/bin/bash
# Start localtunnel for bKash/Nagad sandbox callbacks
# Usage: ./start-tunnel.sh [subdomain]
# Example: ./start-tunnel.sh myshop-bkash

PORT=8005
SUBDOMAIN=${1:-""}
ENV_FILE="$(dirname "$0")/.env"

if [ -n "$SUBDOMAIN" ]; then
  URL=$(lt --port $PORT --subdomain "$SUBDOMAIN" 2>/dev/null &)
  sleep 2
  TUNNEL_URL="https://${SUBDOMAIN}.loca.lt"
else
  # Start lt in background and capture URL
  lt --port $PORT > /tmp/lt_output.txt 2>&1 &
  LT_PID=$!
  sleep 3
  TUNNEL_URL=$(grep -o 'https://[a-z0-9-]*\.loca\.lt' /tmp/lt_output.txt | head -1)
fi

if [ -z "$TUNNEL_URL" ]; then
  echo "ERROR: Could not get tunnel URL. Run: lt --port $PORT"
  exit 1
fi

echo ""
echo "====================================="
echo "  Tunnel URL: $TUNNEL_URL"
echo "====================================="
echo ""
echo "Updating APP_URL in .env to: $TUNNEL_URL"

# Update APP_URL in .env
sed -i "s|^APP_URL=.*|APP_URL=$TUNNEL_URL|" "$ENV_FILE"

echo "Done. Now run inside Docker:"
echo "  docker exec laravel-app php artisan config:clear"
echo ""
echo "bKash callback will be:"
echo "  $TUNNEL_URL/api/gateway/bkash/callback"
echo ""
echo "aura-shop FRONTEND_URL is still: http://localhost:8081"
echo "Make sure FRONTEND_URL in .env stays as localhost:8081 (browser redirect, not server-to-server)"
echo ""

# Keep tunnel alive if started in foreground
if [ -n "$SUBDOMAIN" ]; then
  lt --port $PORT --subdomain "$SUBDOMAIN"
else
  wait $LT_PID
fi
