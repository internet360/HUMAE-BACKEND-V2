#!/usr/bin/env bash
# Deploy del backend en el servidor cPanel.
# Asume que ya subiste humae-backend.zip vía File Manager a:
#   /home2/humaecom/develop.backend-v1.humae.com.mx/
#
# Uso (en SSH):
#   bash ~/develop.backend-v1.humae.com.mx/deploy.sh
#
# Se preserva el .env y storage/{logs,framework,app/public,app/private}.
# Si composer.lock cambió, corre composer install (--no-dev, optimizado).

set -uo pipefail

APP_DIR="$HOME/develop.backend-v1.humae.com.mx"
ZIP_NAME="humae-backend.zip"
ZIP_PATH="$APP_DIR/$ZIP_NAME"
PHP="/opt/cpanel/ea-php83/root/usr/bin/php"
COMPOSER="/opt/cpanel/composer/composer.phar"

# Fallbacks si las rutas anteriores no existen
[ -x "$PHP" ] || PHP=$(command -v php8.3 || command -v php)

echo "================================================"
echo "  HUMAE — Deploy backend (develop)"
echo "================================================"
echo ""
echo "PHP: $PHP"
echo "App: $APP_DIR"
echo ""

if [ ! -f "$ZIP_PATH" ]; then
  echo "❌ No encuentro el zip en: $ZIP_PATH"
  echo "   Súbelo con cPanel File Manager y vuelve a correr este script."
  exit 1
fi

echo "→ Zip encontrado:"
ls -lh "$ZIP_PATH"

cd "$APP_DIR"

echo ""
echo "→ Hash actual de composer.lock (para detectar si cambió)"
LOCK_BEFORE=""
[ -f composer.lock ] && LOCK_BEFORE=$(md5sum composer.lock 2>/dev/null | awk '{print $1}')
echo "  before: ${LOCK_BEFORE:-<no había composer.lock>}"

echo ""
echo "→ Backup de .env"
if [ -f .env ]; then
  cp .env "$HOME/_humae_backend_env_backup"
  echo "  ✓ guardado en $HOME/_humae_backend_env_backup"
else
  echo "  (no había .env previo)"
fi

echo ""
echo "→ Extrayendo zip (no toca .env, vendor/, ni storage/{logs,framework,app})"
unzip -oq "$ZIP_PATH"

echo ""
echo "→ Restaurando .env"
[ -f "$HOME/_humae_backend_env_backup" ] && cp "$HOME/_humae_backend_env_backup" .env

echo ""
echo "→ Permisos de storage y bootstrap/cache"
chmod -R 775 storage 2>/dev/null || true
chmod -R 775 bootstrap/cache 2>/dev/null || true

echo ""
echo "→ Hash nuevo de composer.lock"
LOCK_AFTER=""
[ -f composer.lock ] && LOCK_AFTER=$(md5sum composer.lock 2>/dev/null | awk '{print $1}')
echo "  after:  ${LOCK_AFTER:-<no hay composer.lock>}"

if [ -n "$LOCK_AFTER" ] && [ "$LOCK_BEFORE" != "$LOCK_AFTER" ]; then
  echo ""
  echo "⚠ composer.lock cambió → reinstalando dependencias (--no-dev)"
  if [ -x "$COMPOSER" ]; then
    "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
  elif command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    echo "❌ No encontré composer. Corre manualmente:"
    echo "   composer install --no-dev --optimize-autoloader"
    exit 1
  fi
else
  echo "  composer.lock sin cambios — se mantiene el vendor/ actual"
fi

echo ""
echo "→ Limpiando y re-cacheando config / rutas / vistas"
"$PHP" artisan view:clear        2>&1 | sed 's/^/  /'
"$PHP" artisan config:clear      2>&1 | sed 's/^/  /'
"$PHP" artisan route:clear       2>&1 | sed 's/^/  /'
"$PHP" artisan event:clear       2>&1 | sed 's/^/  /'
"$PHP" artisan config:cache      2>&1 | sed 's/^/  /'
"$PHP" artisan route:cache       2>&1 | sed 's/^/  /'
"$PHP" artisan view:cache        2>&1 | sed 's/^/  /'
"$PHP" artisan event:cache       2>&1 | sed 's/^/  /'

echo ""
echo "→ Reiniciando queue (si hay workers corriendo)"
"$PHP" artisan queue:restart 2>&1 | sed 's/^/  /'

echo ""
echo "→ Smoke test"
HEALTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://develop.backend-v1.humae.com.mx/up")
LOGIN_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "https://develop.backend-v1.humae.com.mx/api/v1/auth/login" -H "Content-Type: application/json" -d '{"email":"x","password":"x"}')
WEBHOOK_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "https://develop.backend-v1.humae.com.mx/api/v1/webhooks/stripe" -H "Stripe-Signature: invalid")
printf "  %-50s → %s\n" "GET  /up                                       (health)" "$HEALTH_CODE"
printf "  %-50s → %s\n" "POST /api/v1/auth/login                        (422 esperado)" "$LOGIN_CODE"
printf "  %-50s → %s\n" "POST /api/v1/webhooks/stripe (invalid sig)     (400 esperado)" "$WEBHOOK_CODE"

echo ""
echo "✅ Deploy listo."
echo ""
if [ "$HEALTH_CODE" != "200" ]; then
  echo "⚠ /up devolvió $HEALTH_CODE (esperado 200) → revisa logs:"
  echo "   tail -n 100 ~/develop.backend-v1.humae.com.mx/storage/logs/laravel-\$(date +%Y-%m-%d).log"
fi
