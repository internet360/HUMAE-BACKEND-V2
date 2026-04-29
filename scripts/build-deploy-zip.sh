#!/usr/bin/env bash
# Genera el zip del backend listo para subir a cPanel y extraer en
# ~/develop.backend-v1.humae.com.mx/
#
# El zip excluye:
#   - vendor/ (ya está en el server, ahorra ~50 MB)
#   - .env / .env.* (no pisamos secrets en server)
#   - node_modules/, storage/logs, storage/framework/{cache,sessions,views}/*
#   - .git/, .phpunit.cache/, tests/
#
# Uso:
#   composer deploy:zip
# o
#   bash scripts/build-deploy-zip.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_OUT="${ZIP_OUT:-$HOME/Desktop/humae-backend.zip}"

cd "$ROOT_DIR"

echo "→ Verificando que estamos en humae_backend"
test -f artisan || { echo "ERROR: no existe artisan en $ROOT_DIR"; exit 1; }
test -f composer.json || { echo "ERROR: no existe composer.json en $ROOT_DIR"; exit 1; }

echo "→ Empaquetando zip"
rm -f "$ZIP_OUT"

zip -rq "$ZIP_OUT" \
  app \
  bootstrap \
  config \
  database \
  lang \
  public \
  resources \
  routes \
  storage \
  artisan \
  composer.json \
  composer.lock \
  .htaccess \
  -x \
    ".env" \
    ".env.*" \
    "storage/logs/*" \
    "storage/framework/cache/data/*" \
    "storage/framework/sessions/*" \
    "storage/framework/views/*" \
    "storage/app/livewire-tmp/*" \
    "storage/app/public/*" \
    "storage/app/private/*" \
    "bootstrap/cache/*.php" \
    "node_modules/*" \
    "vendor/*" \
    ".git/*" \
    ".phpunit.cache/*" \
    "tests/*" \
    "*.DS_Store" \
  2>/dev/null || true

# Asegurar que .gitkeep de carpetas de storage queden
echo "→ Inyectando .gitkeep para preservar estructura de storage"
TMP_DIR=$(mktemp -d)
mkdir -p "$TMP_DIR/storage/logs"
mkdir -p "$TMP_DIR/storage/framework/cache/data"
mkdir -p "$TMP_DIR/storage/framework/sessions"
mkdir -p "$TMP_DIR/storage/framework/views"
mkdir -p "$TMP_DIR/storage/app/public"
mkdir -p "$TMP_DIR/storage/app/private"
mkdir -p "$TMP_DIR/bootstrap/cache"
touch "$TMP_DIR/storage/logs/.gitkeep" \
      "$TMP_DIR/storage/framework/cache/data/.gitkeep" \
      "$TMP_DIR/storage/framework/sessions/.gitkeep" \
      "$TMP_DIR/storage/framework/views/.gitkeep" \
      "$TMP_DIR/storage/app/public/.gitkeep" \
      "$TMP_DIR/storage/app/private/.gitkeep" \
      "$TMP_DIR/bootstrap/cache/.gitkeep"
(cd "$TMP_DIR" && zip -rq "$ZIP_OUT" .)
rm -rf "$TMP_DIR"

SIZE=$(ls -lh "$ZIP_OUT" | awk '{print $5}')
echo ""
echo "✅ Zip listo: $ZIP_OUT ($SIZE)"
echo ""
echo "Siguiente paso: subir el zip a cPanel File Manager →"
echo "  /home2/humaecom/develop.backend-v1.humae.com.mx/"
echo ""
echo "Luego corre en SSH:"
echo "  bash ~/develop.backend-v1.humae.com.mx/deploy.sh"
