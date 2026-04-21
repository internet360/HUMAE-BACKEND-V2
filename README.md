# HUMAE — Backend

API REST para la plataforma HUMAE de vinculación laboral privada. Parte del rewrite definido en [`HUMAE NEW/ARCHITECTURE.md`](../ARCHITECTURE.md).

## Stack

- **Laravel 12** · PHP 8.3+ · MySQL 8
- **Sanctum** (auth SPA + tokens) · **Spatie Laravel Permission** (roles y permisos)
- **Stripe** (pagos) · **Storage local** (disco del servidor) · **Intervention Image** (resize de avatares) · **DomPDF** (generación de CV) · **SMTP local** (Postfix en el mismo host)
- **Pest** (tests) · **Larastan nivel 8** (análisis estático) · **Pint** (formato) · **Scribe** (docs API)

## Requisitos

- PHP 8.3 o superior
- Composer 2.x
- MySQL 8 en local (o Docker)
- Redis (opcional en dev, requerido para queue/cache en producción)
- Credenciales de Stripe (sandbox) para probar flujos de pago
- En dev: MailHog vía `docker-compose` (captura correos). En prod: Postfix instalado en el mismo servidor

## Setup

```bash
# 1. Instalar dependencias
composer install

# 2. Variables de entorno
cp .env.example .env
php artisan key:generate

# 3. Configurar MySQL en .env:
#    DB_CONNECTION=mysql
#    DB_DATABASE=humae
#    DB_USERNAME=...
#    DB_PASSWORD=...
#    (crear la BD vacía previamente)

# 4. Migraciones
php artisan migrate

# 5. Dev server
php artisan serve
# → http://127.0.0.1:8000/api/v1/health
```

## Comandos frecuentes

| Objetivo | Comando |
|---|---|
| Servir en dev | `php artisan serve` |
| Correr tests | `composer test` |
| Tests con cobertura ≥70% | `composer test:coverage` |
| Formatear (Pint) | `composer lint` |
| Verificar formato | `composer lint:check` |
| Análisis estático (Larastan/PHPStan nivel 8) | `composer analyse` |
| Generar docs API (Scribe) | `composer docs` |
| Todos los checks | `composer check` |
| Migraciones frescas + seeders | `php artisan migrate:fresh --seed` |

## Endpoints actuales

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/v1/health` | Health check (app + DB) |
| GET | `/up` | Health interno de Laravel |
| GET | `/docs` | Documentación API (Scribe) |

## Envelope estándar de la API

Éxito:
```json
{
  "success": true,
  "message": "OK",
  "data": { ... },
  "meta": null
}
```

Error:
```json
{
  "success": false,
  "message": "La validación falló.",
  "errors": {
    "field": ["mensaje"]
  }
}
```

## Fases siguientes

Ver [`../ARCHITECTURE.md`](../ARCHITECTURE.md) §14 (roadmap). Esta es la **Fase 1 — Bootstrap**. La Fase 3 (modelo de datos y migraciones) construye las entidades definidas en `ARCHITECTURE.md` §4.

## Para Claude Code

La fuente de verdad operativa vive en [`CLAUDE.md`](./CLAUDE.md). Léelo antes de tocar código.
