#!/usr/bin/env bash
# Deploy del backend en el servidor cPanel.
# Asume que ya subiste humae-backend.zip vía File Manager a la carpeta de la app.
#
# Uso (en SSH):
#   bash ~/deploy-backend.sh production
#   bash ~/deploy-backend.sh develop         # default
#
# Overrides:
#   APP_DIR=~/otra-carpeta bash ~/deploy-backend.sh production
#   RUN_MIGRATIONS=0       bash ~/deploy-backend.sh production   # sólo reporta pendientes
#
# Se preserva el .env y storage/{logs,framework,app/public,app/private}.
# Si composer.lock cambió, corre composer install (--no-dev, optimizado).

set -uo pipefail

DEPLOY_ENV="${DEPLOY_ENV:-${1:-develop}}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"

case "$DEPLOY_ENV" in
  production)
    API_URL="https://backend-v1.humae.com.mx"
    DEFAULT_APP_DIR="$HOME/backend-v1.humae.com.mx"
    ;;
  develop)
    API_URL="https://develop.backend-v1.humae.com.mx"
    DEFAULT_APP_DIR="$HOME/develop.backend-v1.humae.com.mx"
    ;;
  *)
    echo "❌ DEPLOY_ENV inválido: '$DEPLOY_ENV' (esperado: develop | production)"
    exit 1
    ;;
esac

APP_DIR="${APP_DIR:-$DEFAULT_APP_DIR}"
ZIP_NAME="humae-backend.zip"
ZIP_PATH="$APP_DIR/$ZIP_NAME"
PHP="/opt/cpanel/ea-php83/root/usr/bin/php"
COMPOSER="/opt/cpanel/composer/composer.phar"

# Fallbacks si las rutas anteriores no existen
[ -x "$PHP" ] || PHP=$(command -v php8.3 || command -v php)

echo "================================================"
echo "  HUMAE — Deploy backend ($DEPLOY_ENV)"
echo "================================================"
echo ""
echo "PHP: $PHP"
echo "App: $APP_DIR"
echo "API: $API_URL"
echo ""

if [ ! -d "$APP_DIR" ]; then
  echo "❌ No existe la carpeta de la app: $APP_DIR"
  echo "   Verificá el document root en cPanel → Domains, o pasá APP_DIR=..."
  exit 1
fi

# El piso real del proyecto es PHP 8.2 (composer.json: ^8.2). Ya hubo un 500 en
# develop por sintaxis 8.3 que en local pasaba: el PHP de desarrollo es más nuevo
# que el del servidor. Dejar la versión a la vista evita repetir ese diagnóstico.
PHP_VERSION=$("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null)
echo "→ Versión de PHP en uso: $PHP_VERSION"
if ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
  echo "❌ PHP $PHP_VERSION es menor a 8.2, el piso declarado en composer.json."
  echo "   Ajustá la versión en cPanel → MultiPHP Manager para este dominio."
  exit 1
fi

if [ ! -f "$ZIP_PATH" ]; then
  echo "❌ No encuentro el zip en: $ZIP_PATH"
  echo "   Súbelo con cPanel File Manager y vuelve a correr este script."
  exit 1
fi

echo ""
echo "→ Zip encontrado:"
ls -lh "$ZIP_PATH"

cd "$APP_DIR" || exit 1

if [ ! -f .env ]; then
  echo ""
  echo "❌ No hay .env en $APP_DIR."
  echo "   Subilo antes de desplegar: sin él, artisan corre contra la config por defecto."
  exit 1
fi

echo ""
echo "→ Hash actual de composer.lock (para detectar si cambió)"
LOCK_BEFORE=""
[ -f composer.lock ] && LOCK_BEFORE=$(md5sum composer.lock 2>/dev/null | awk '{print $1}')
echo "  before: ${LOCK_BEFORE:-<no había composer.lock>}"

echo ""
echo "→ Backup de .env"
cp .env "$HOME/_humae_backend_env_backup_$DEPLOY_ENV"
echo "  ✓ guardado en $HOME/_humae_backend_env_backup_$DEPLOY_ENV"

# En producción el sitio queda sirviendo 503 mientras corre el deploy, en vez de
# atender requests contra un código a medio extraer o un esquema a medio migrar.
if [ "$DEPLOY_ENV" = "production" ]; then
  echo ""
  echo "→ Modo mantenimiento ON"
  "$PHP" artisan down --render="errors::503" --retry=60 2>&1 | sed 's/^/  /'
fi

echo ""
echo "→ Extrayendo zip (no toca .env, vendor/, ni storage/{logs,framework,app})"
unzip -oq "$ZIP_PATH"

echo ""
echo "→ Restaurando .env"
cp "$HOME/_humae_backend_env_backup_$DEPLOY_ENV" .env

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

# Un deploy que sube código nuevo sin su esquema no falla al desplegar: falla
# después, con 500 en las rutas que tocan las columnas que no existen. Se listan
# primero para que quede registro de qué se corrió.
echo ""
echo "→ Migraciones pendientes"
"$PHP" artisan migrate:status 2>&1 | grep -i pending | sed 's/^/  /' || echo "  (ninguna)"

if [ "$RUN_MIGRATIONS" = "1" ]; then
  echo ""
  echo "→ Corriendo migraciones"
  "$PHP" artisan migrate --force 2>&1 | sed 's/^/  /'
  MIGRATE_STATUS=${PIPESTATUS[0]}
  if [ "$MIGRATE_STATUS" != "0" ]; then
    echo ""
    echo "❌ Las migraciones fallaron. El sitio sigue en mantenimiento a propósito:"
    echo "   revisá el error, arreglá, y volvé a correr este script."
    echo "   Para levantarlo sin migrar: $PHP artisan up"
    exit 1
  fi
else
  echo "  (RUN_MIGRATIONS=0 — saltadas a pedido)"
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

if [ "$DEPLOY_ENV" = "production" ]; then
  echo ""
  echo "→ Modo mantenimiento OFF"
  "$PHP" artisan up 2>&1 | sed 's/^/  /'
fi

echo ""
echo "→ Smoke test"
HEALTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$API_URL/up")
LOGIN_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_URL/api/v1/auth/login" -H "Content-Type: application/json" -d '{"email":"x","password":"x"}')
WEBHOOK_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_URL/api/v1/webhooks/stripe" -H "Stripe-Signature: invalid")
printf "  %-50s → %s\n" "GET  /up                                       (200 esperado)" "$HEALTH_CODE"
printf "  %-50s → %s\n" "POST /api/v1/auth/login                        (422 esperado)" "$LOGIN_CODE"
printf "  %-50s → %s\n" "POST /api/v1/webhooks/stripe (invalid sig)     (400 esperado)" "$WEBHOOK_CODE"

echo ""
echo "✅ Deploy listo ($DEPLOY_ENV)."
echo ""
if [ "$HEALTH_CODE" != "200" ]; then
  echo "⚠ /up devolvió $HEALTH_CODE (esperado 200) → revisá logs:"
  echo "   tail -n 100 $APP_DIR/storage/logs/laravel-\$(date +%Y-%m-%d).log"
fi
