# HUMAE — Backend

> Fuente de verdad operativa para cualquier sesión de Claude Code que trabaje en este codebase. Léelo completo antes de escribir código. Si algo cambia (nueva dependencia, nuevo módulo, nueva convención), actualiza este archivo.

---

## 1. Contexto del proyecto

**HUMAE** es una plataforma web **privada** de vinculación laboral que intermedia entre candidatos, empresas contratantes y el equipo interno de reclutamiento. **No es una bolsa de trabajo pública**: el valor está en la combinación de base de talento evaluada + filtros avanzados + trazabilidad + coordinación.

- **Usuarios y roles**: Candidato (membresía de 499 MXN / 6 meses), Reclutador HUMAE, Usuario de Empresa cliente, Administrador.
- **Por qué este rewrite**: reemplaza un backend Laravel 8 legacy con 3 modelos de usuario duplicados, controllers de 1000+ líneas, sin tests, sin Service Layer y con deuda técnica grave (ver `../ARCHITECTURE.md` §1).
- **Codebase hermano**: `../humae_frontend/` — Next.js 15 + TypeScript, consume esta API bajo `/api/v1/*`.
- **Documento maestro**: [`../ARCHITECTURE.md`](../ARCHITECTURE.md) — mapa completo de módulos, ERD, endpoints, estados, ADRs.

## 2. Stack y versiones

| Componente | Versión | Paquete |
|---|---|---|
| PHP | 8.3+ | — |
| Framework | 12.x | `laravel/framework` |
| Auth | 4.x | `laravel/sanctum` |
| Roles/Permisos | 7.x | `spatie/laravel-permission` |
| Auditoría | 5.x | `spatie/laravel-activitylog` |
| Pagos | 20.x | `stripe/stripe-php` |
| Storage | — | Laravel `Storage` nativo (disco local del propio servidor) |
| Imágenes (resize) | 3.x | `intervention/image` |
| PDF | 3.x | `dompdf/dompdf` |
| Mail | — | Driver `smtp` nativo de Laravel → Postfix en `127.0.0.1:25` |
| Redis | 3.x | `predis/predis` |
| Slugs | 4.x | `cocur/slugify` |
| Docs API | 5.x | `knuckleswtf/scribe` |
| Tests | 3.x | `pestphp/pest` + `pestphp/pest-plugin-laravel` |
| Análisis | 3.x | `larastan/larastan` (PHPStan nivel 8) |
| Lint | 1.x | `laravel/pint` |
| IDE | 3.x | `barryvdh/laravel-ide-helper` |

DB: **MySQL 8**. Para tests se usa SQLite in-memory (ver `phpunit.xml`).

## 3. Arquitectura y convenciones

### Estructura de carpetas (`app/`)

```
app/
├── Enums/                       Estados de dominio: CandidateState, JobState, InterviewState, UserRole
├── Helpers/                     Funciones estáticas globales (Functions, LocalFileStorage, StripeClient)
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php       Base. Usa trait ApiResponse + AuthorizesRequests + ValidatesRequests
│   │   ├── Api/V1/
│   │   │   ├── Auth/            Registro, login, logout, me, verify-email, password reset
│   │   │   ├── Candidate/       Endpoints del rol candidato (perfil, psicométricos, membresía)
│   │   │   ├── Recruiter/       Endpoints del rol reclutador (directorio, pipeline, entrevistas)
│   │   │   ├── Company/         Endpoints del rol company_user
│   │   │   ├── Admin/           CRUD de catálogos, reportes, configuración
│   │   │   └── Shared/          Endpoints compartidos (HealthController)
│   │   └── Webhooks/            Stripe (único webhook público; el correo se trackea vía /var/log/mail.log)
│   ├── Middleware/
│   ├── Requests/                {Auth,Candidate,Recruiter,Admin}/<Action><Resource>Request.php
│   └── Resources/V1/            API Resources + Collections
├── Models/
├── Policies/                    Una Policy por modelo (autorización granular sobre Spatie)
├── Providers/
├── Rules/                       Custom validation rules
├── Services/                    Lógica de negocio transaccional (MembershipService, CVGenerationService, ...)
└── Support/
    ├── ApiResponse.php          Trait con success()/error() (envelope)
    └── ApiExceptionHandler.php  Formatea excepciones como envelope JSON
```

### Patrones

- **Controllers** son **delgados**: validan (Form Request), delegan a Service, transforman (API Resource), devuelven `$this->success(...)` / `$this->error(...)`.
- **Services** contienen lógica de negocio pura (sin facades de HTTP). Inyecta dependencias por constructor.
- **Enums PHP 8.3** para estados de dominio (no columnas ENUM de MySQL).
- **Policies** en `app/Policies/` resuelven autorización fina que Spatie solo no cubre (ej. "este reclutador puede ver sólo las vacantes asignadas a él").
- **Feature folders**: cuando un módulo crece, mueve su stack completo (Controller + Requests + Resources + Service + Policy + Tests) a una carpeta del módulo.

### Localización (i18n)

`APP_LOCALE=es` por default. Las traducciones de Laravel viven en **`lang/es/`** (publicadas manualmente — Laravel 12 ya no las publica con `lang:publish` por defecto). Si añades una regla de validación nueva con un mensaje custom, **agrégala también a `lang/es/validation.php`** (sección `attributes` para nombres de campos, raíz para mensajes). Si no, el frontend recibe la llave cruda (`validation.after`) y no se ve nada bonito en los toasts/forms.

### Convenciones de naming

| Artefacto | Patrón |
|---|---|
| Modelo | Singular PascalCase — `User`, `CandidateProfile`, `Job`, `Interview` |
| Controller | `{Resource}Controller` |
| Form Request | `{Action}{Resource}Request` — `CreateJobRequest`, `UpdateCandidateProfileRequest` |
| API Resource | `{Resource}Resource` + `{Resource}Collection` |
| Policy | `{Model}Policy` |
| Service | `{Domain}Service` — `MembershipService`, `PsychometricScoringService` |
| Enum | `{Domain}State` — `CandidateState`, `JobState` |
| Migration | `{timestamp}_create_{tabla}_table` o `_add_{campo}_to_{tabla}` |
| Test Pest | `tests/Feature/Api/V1/{Module}/{Action}Test.php` |

### Convenciones de commits

**Conventional Commits** (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`). Cada sub-tarea completada = un commit atómico. PRs con changelog.

### Envelope estándar de API

Definido en `app/Support/ApiResponse.php` (trait). Éxito:
```json
{"success": true, "message": "OK", "data": {...}, "meta": null}
```
Error:
```json
{"success": false, "message": "...", "errors": {...}}
```

Status HTTP semánticos (200, 201, 204, 400, 401, 403, 404, 422, 429, 500). `app/Support/ApiExceptionHandler.php` los mapea desde excepciones Laravel estándar.

## 4. Comandos frecuentes

| Objetivo | Comando |
|---|---|
| Setup desde cero | `cp .env.example .env && composer install && php artisan key:generate && php artisan migrate` |
| Dev server | `php artisan serve` (puerto 8000) |
| Dev full (serve + queue + logs + vite) | `composer dev` |
| Tests | `composer test` |
| Tests cobertura ≥70% | `composer test:coverage` |
| Lint (fix) | `composer lint` |
| Lint (check only) | `composer lint:check` |
| PHPStan nivel 8 | `composer analyse` |
| Docs API (Scribe) | `composer docs` → output en `/docs` |
| Check completo (pre-commit) | `composer check` |
| Migrar desde cero con seeders | `php artisan migrate:fresh --seed` |
| Listar rutas | `php artisan route:list` |

## 5. Skills del proyecto

Skills globales en `~/.claude/skills/` relevantes para este codebase:

| Skill | Cuándo usar |
|---|---|
| `feature` | Implementar un módulo nuevo end-to-end (migraciones + modelos + requests + controllers + services + tests) |
| `test` | Generar suite de tests ROI-priorizada para un módulo |
| `review` | Code review multi-agente antes de cerrar una fase |
| `security-review` | Al cerrar módulos con datos sensibles (auth, pagos, documentos) |
| `audit` | Hardening final |
| `init` | Si hay que regenerar este CLAUDE.md desde cero |

## 6. Reglas inquebrantables

### Nunca

- ❌ Tocar `HUMAE LEGACY/` ni `FIELOO_BACKEND/` — son solo lectura.
- ❌ Hardcodear secretos. Todo va en `.env` y `.env.example` con valor vacío.
- ❌ Poner lógica de negocio en Controllers. Delega a Services.
- ❌ Guardar archivos en `public_path()`. Usa `LocalFileStorage` helper (disco `public` para avatares, `local` privado para documentos). Nunca escribas directo con `Storage::disk()` desde un controller — pasa por el helper.
- ❌ Usar `auth:sanctum` sin Policy/Permission cuando el recurso no pertenece al usuario autenticado.
- ❌ Crear endpoints sin API Resource (no devuelvas modelos Eloquent directo).
- ❌ Exponer columnas de DB con `toArray()` directo (usa Resources).
- ❌ `dd()`, `dump()`, `var_dump()` olvidados en commits.
- ❌ Migrations editadas retroactivamente. Cada cambio es una migration nueva.
- ❌ Usar rutas sin versionar. Todo bajo `/api/v1/`.

### Siempre

- ✅ Form Request para toda validación (ni un `$request->validate()` inline).
- ✅ Policies para autorización de recursos que pertenecen a un dueño.
- ✅ Tests de feature para cada endpoint (happy path + errores + autorización).
- ✅ `composer check` verde antes de cerrar una fase (lint + phpstan + tests).
- ✅ ADR en `docs/adr/` para decisiones arquitectónicas no triviales.
- ✅ Actualizar este `CLAUDE.md` si agregas un módulo, dependencia, convención o comando.
- ✅ Envelope consistente (`ApiResponse` trait) en TODAS las respuestas JSON.

## 7. Puntos de integración

### Con el frontend (`../humae_frontend/`)

- **Base URL**: `http://localhost:8000/api/v1/` en dev, `https://api.humae.com.mx/v1/` en prod.
- **Auth**: Sanctum SPA (cookie-based cross-origin) para el frontend; tokens para mobile futuro.
- **CORS**: `config/cors.php` apunta a `FRONTEND_URL` del `.env`.
- **Contrato**: cada cambio de shape va con un bump semántico en Scribe + nota en ADR.

### Servicios externos

| Servicio | Uso | Env vars |
|---|---|---|
| Stripe | Checkout Session + webhooks de membresía | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CURRENCY`, `STRIPE_PRICE_CANDIDATE_6M` |
| Storage local | Avatars (disco `public`), documentos (disco `local`), logos | `FILESYSTEM_DISK` |
| SMTP local (Postfix) | Emails transaccionales (driver `smtp` a `127.0.0.1:25`) | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO` |
| Sentry (futuro) | Error tracking | `SENTRY_LARAVEL_DSN` |

Todos los env vars están documentados en `.env.example` con valor vacío. Nunca commitees `.env`.

## 8. Roadmap y estado actual

- **Fase 0** ✅ — Descubrimiento y Arquitectura (`../ARCHITECTURE.md`)
- **Fase 1** ✅ — Bootstrap Backend
- **Fase 2** ✅ — Bootstrap Frontend
- **Fase 3** ✅ — Modelo de datos y migraciones (27 migraciones, 54 tablas de dominio, 9 seeders, factories, `composer check` verde)
- **Fase 4** ✅ — Auth + Authorization backend (8 endpoints `/api/v1/auth/*`, 45 permisos, 4 Policies, 2 middlewares, 18 tests)
- **Fase 5** ✅ — Auth Frontend (guards, store Zustand)
- **Fase 6** ✅ — Membership + Stripe Checkout (endpoints `/me/membership/*` + webhook, ExpireMembershipsJob diario)
- **Fase 7** ✅ — Profile (candidate) — 22 endpoints, ProfileService + LocalFileStorage (Laravel Storage + Intervention Image)
- **Fase 8** ✅ — Psychometrics — 6 endpoints, scoring con reverse-scored, Big Five seeder (25 ítems ES), 47 tests
- **Fase 9** ✅ — **CV PDF Generator** — endpoint `GET /me/profile/cv.pdf`, `CvGenerationService` + Blade template con tokens HUMAE (logo, paleta brand), **49 tests feature** verdes
- **Fase 10** ⏳ — Companies + Vacancies
- **Fase 11–15** — Directory/Pipeline/Interviews/Notifications/Reports
- **Fase Final** — Hardening (cobertura, E2E, Docker, CI, security audit)

Ver roadmap completo en `../ARCHITECTURE.md` §14.

## 9. Troubleshooting

### `composer require` falla con conflicto de `illuminate/support`

PHP 8.5 está muy nuevo y algunos paquetes no lo validan aún. El proyecto está pineado a Laravel 12 por compatibilidad con el ecosistema de Intervention Image v3 y las versiones pineadas de `spatie/*`. No intentes actualizar a Laravel 13 hasta que el ecosistema migre.

### `php artisan migrate` falla con `access denied`

Verifica que `DB_USERNAME` y `DB_PASSWORD` en `.env` coincidan con tu MySQL local. En tests se usa SQLite (ver `phpunit.xml`), no MySQL.

### PHPStan reporta errores en `tests/`

Los tests están **excluidos** de PHPStan en `phpstan.neon` (paths apunta solo a `app`, `routes`, `database/factories`, `database/seeders`) porque los test helpers de Pest requieren plugin de PHPStan específico. Se puede reactivar más adelante con `pestphp/pest-plugin-type-coverage`.

### Pint modifica archivos de vendor-published configs

Por diseño: Pint aplica `declare_strict_types` y otras reglas a todo `app/`, `config/`, `database/`, `routes/`. Si un upgrade de vendor trae cambios en un config publicado, resolver el conflicto normalmente (los cambios de Pint son cosméticos, no semánticos).

### Health endpoint devuelve 503

Significa que la conexión a MySQL falló. Revisa `.env` y que el servicio MySQL esté arriba. En CI/tests usa SQLite automáticamente.
