# Matriz de autorización — HUMAE API v1

> **Fuente de intención**: [`../../../ARCHITECTURE.md`](../../../ARCHITECTURE.md) §5 (catálogo de endpoints) y §6
> (matriz de roles × permisos). El product owner reafirmó §6 por encima de la implementación cuando ambas
> se contradicen. Este documento transcribe esa intención ruta por ruta y la contrasta contra lo que el
> código hace hoy.
>
> **Sonda ejecutable**: [`tests/Feature/Security/AuthorizationMatrixTest.php`](../../tests/Feature/Security/AuthorizationMatrixTest.php).
> Cada fila de este documento tiene su fila en esa tabla de expectativas. Las divergencias llevan un id
> `F-xx` y la sonda las reporta como *skipped* nombrando el hallazgo, de modo que un rojo en ese archivo
> siempre significa un agujero **nuevo**.
>
> **Alcance de la auditoría**: 145 rutas bajo `/api/v1/` + 9 rutas de infraestructura = 154 rutas totales
> (`php artisan route:list`). Se sondearon las 145 de la API — cobertura verificada por el propio test, que
> falla si alguien añade una ruta sin añadir su fila. Las 9 de infraestructura se documentan pero no se
> sondean (§3, §7).

---

## 1. Cómo leer las tablas

| Símbolo | Significado |
|---|---|
| ✅ | Acceso permitido |
| 🔒 | Acceso permitido pero acotado al recurso propio (empresa propia, vacante propia, expediente propio) |
| ❌ | Acceso denegado. Se espera `401` sin sesión, `403` por rol/política, o `404` cuando el recurso se oculta por pertenencia |
| — | No aplica al rol según §6 (celda "—", no celda "❌") |
| **UNSPECIFIED** | §5/§6 no cubren la ruta. Se documenta la inferencia usada y por qué |

**Columnas de rol**: `anón` (sin autenticar), `cand` (candidate), `recr` (recruiter), `emp`
(company_user), `admin`.

**Columna Estado**: `✔` la implementación coincide con la intención; `F-xx` diverge (ver §5).

---

## 2. Rutas de la API v1

### 2.1 Auth — §5.1

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| POST | `/auth/register` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.1 público | ✔ |
| POST | `/auth/register/recruiter` | ✅ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/auth/register/company` | ❌ | ❌ | ❌ | ❌ | ❌ | §6 «Registrarse — Empresa cliente ❌ (invitación)» | ✔ (ruta retirada) |
| POST | `/auth/login` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.1 público | ✔ |
| POST | `/auth/forgot-password` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.1 público | ✔ |
| POST | `/auth/reset-password` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.1 público | ✔ |
| GET | `/auth/verify-email/{id}/{hash}` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.1 público | ✔ |
| POST | `/auth/verify-email/resend` | ✅ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/auth/resend-verification` | ❌ | ✅ | ✅ | ✅ | ✅ | §5.1 auth | ✔ |
| GET | `/auth/invitation/{token}` | ✅ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/auth/invitation/accept` | ✅ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/auth/logout` | ❌ | ✅ | ✅ | ✅ | ✅ | §5.1 auth | ✔ |
| GET | `/auth/me` | ❌ | ✅ | ✅ | ✅ | ✅ | §5.1 auth | ✔ |

**Inferencias UNSPECIFIED de esta sección**

- `POST /auth/register/recruiter` — §5.1 sólo lista el registro de candidato y §6 marca «Registrarse —
  Reclutador: —». Se infiere **público**: la implementación crea la cuenta en `pending_approval` y el login
  la rechaza hasta que un admin la aprueba, así que el endpoint es una *solicitud*, no un alta. Riesgo
  aceptado: enumeración de correos vía la regla `unique:users,email` y consumo de cuota de correo (mitigado
  con `throttle:5,1`).
- `POST /auth/verify-email/resend` — no aparece en §5.1. Se infiere **público**: el controlador responde
  siempre el mismo mensaje genérico exista o no la cuenta, así que no filtra estado de cuenta. `throttle:3,1`.
- `GET /auth/invitation/{token}` y `POST /auth/invitation/accept` — no aparecen en §5. Se infiere
  **público autorizado por el token** (64 caracteres aleatorios, almacenado como SHA-256, con caducidad).
  `GET` devuelve correo, nombre, rol y razón social de la empresa: es exposición de datos aceptable sólo
  porque el token es la credencial.

### 2.2 Catálogos maestros — **UNSPECIFIED**

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/catalogs/skills` | ❌ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| GET | `/catalogs/languages` | ❌ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| GET | `/catalogs/degree-levels` | ❌ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| GET | `/catalogs/functional-areas` | ❌ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |
| GET | `/catalogs/vacancy-types` | ❌ | ✅ | ✅ | ✅ | ✅ | **UNSPECIFIED** | ✔ |

**Inferencia**: §5 no cataloga estas rutas. Se infiere **cualquier usuario autenticado**: son datos
maestros sin componente personal (nombre y código de habilidades, idiomas, grados académicos) que tanto el
editor de perfil del candidato como los filtros del reclutador necesitan. No contienen PII ni información
comercial.

### 2.3 Perfil del candidato — §5.2 (`role: candidate`)

§5.2 encabeza la sección con «auth, role: candidate». Las 30 rutas de esta superficie y la de §5.4 quedan
tras `RoleMiddleware:candidate` (**F-09 cerrado**).

| Método | Ruta | anón | cand | recr | emp | admin | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|
| GET | `/me/profile` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH | `/me/profile` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| POST | `/me/profile/avatar` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET | `/me/profile/cv.pdf` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/experiences` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH/DELETE | `/me/profile/experiences/{id}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/educations` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH/DELETE | `/me/profile/educations/{id}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/courses` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH/DELETE | `/me/profile/courses/{id}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/certifications` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH/DELETE | `/me/profile/certifications/{id}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/references` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH/DELETE | `/me/profile/references/{id}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/skills` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| DELETE | `/me/profile/skills/{skill}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/languages` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| DELETE | `/me/profile/languages/{language}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET/POST | `/me/profile/documents` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET | `/me/profile/documents/{document}/download` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| DELETE | `/me/profile/documents/{document}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |

El aislamiento **entre candidatos** ya funcionaba: `ResolvesCandidateProfile::ensureOwned()` responde `404`
a cualquiera que no sea el dueño. Lo que faltaba era el filtro de rol, y el efecto colateral que producía
(ver §5.4).

### 2.4 Membresía y pagos — §5.3

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/me/membership` | ❌ | 🔒 | 🔒 | 🔒 | 🔒 | §5.3 «auth» | ✔ |
| POST | `/me/membership/checkout` | ❌ | ✅ | — | — | — | §6 «Pagar membresía» | ✔ |
| GET | `/me/payments` | ❌ | 🔒 | 🔒 | 🔒 | 🔒 | §5.3 «auth» | ✔ |

§5.3 titula la sección «Membership (auth)» sin acotar rol, y ambos `GET` se autoacotan al usuario
autenticado (devuelven vacío para quien no tiene membresías ni pagos). `POST /checkout` sí está acotado por
§6: sólo el candidato paga membresía.

### 2.5 Psicométricos — §5.4 (`role: candidate`)

| Método | Ruta | anón | cand | recr | emp | admin | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|
| GET | `/me/psychometrics/tests` | ❌ | ✅ | ❌ | ❌ | ❌ | ✔ |
| POST | `/me/psychometrics/attempts` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET | `/me/psychometrics/attempts/{attempt}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| PATCH | `/me/psychometrics/attempts/{attempt}/answers` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| POST | `/me/psychometrics/attempts/{attempt}/submit` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |
| GET | `/me/psychometrics/results/{attempt}` | ❌ | 🔒 | ❌ | ❌ | ❌ | ✔ |

### 2.6 Notificaciones — §5.9

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/me/notifications` | ❌ | 🔒 | 🔒 | 🔒 | 🔒 | §5.9 auth | ✔ |
| POST | `/me/notifications/{id}/read` | ❌ | 🔒 | 🔒 | 🔒 | 🔒 | §5.9 auth | ✔ |
| POST | `/me/notifications/read-all` | ❌ | 🔒 | 🔒 | 🔒 | 🔒 | §5.9 auth | ✔ |

El acotado es correcto: `NotificationController` consulta siempre `$user->notifications()`, así que un id
ajeno responde `404`.

### 2.7 Directorio de candidatos — §5.5 / §6

Sección crítica: es la base de talento evaluada, el activo por el que la empresa cliente paga. §6 la
cierra a la empresa en cuatro filas distintas.

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/directory/candidates` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.5 / §6 «Ver directorio — Empresa ❌» | ✔ |
| GET | `/directory/candidates/{candidate}` | ❌ | ❌ | ✅ | ❌ | ✅ | §6 «Expediente completo — Empresa ❌» | ✔ |
| POST | `/directory/candidates/{candidate}/favorite` | ❌ | ❌ | ✅ | ❌ | ✅ | §6 «Marcar favoritos — Empresa ❌» | ✔ |
| GET | `/directory/candidates/{candidate}/cv.pdf` | ❌ | ❌ | ✅ | ❌ | ✅ | §6 «Descargar CV de cualquier candidato — Empresa ❌» | ✔ |
| GET | `/directory/candidates/{candidate}/documents/{document}/download` | ❌ | ❌ | ✅ | ❌ | ✅ | **UNSPECIFIED** | ✔ |

**Inferencia**: la descarga de documentos no aparece en §5.5. Se infiere **recruiter/admin**, igual que el
resto del expediente: expone archivos que el candidato subió al disco privado. La implementación además
excluye `is_internal = true`.

**Doble candado verificado**: middleware de rol (`RoleMiddleware:recruiter|admin`) + `CandidateProfilePolicy`.
Las cinco rutas responden `403` a `company_user` y a `candidate`.

### 2.8 Empresas — §5.6 (admin / recruiter)

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/companies` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |
| POST | `/companies` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |
| GET | `/companies/{company}` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |
| PATCH | `/companies/{company}` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |
| DELETE | `/companies/{company}` | ❌ | ❌ | ❌ | ❌ | ✅ | **UNSPECIFIED** | ✔ |
| GET | `/companies/{company}/members` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |
| POST | `/companies/{company}/members` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |
| DELETE | `/companies/{company}/members/{userId}` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 admin/recruiter | ✔ |

**Inferencia (`DELETE /companies/{company}`)**: §5.6 lista «GET, POST /companies, /companies/{id}, PATCH,
DELETE — admin / recruiter» en una sola fila. Se infiere **sólo admin** para el `DELETE`: es la única acción
destructiva de la fila y `CompanyPolicy::delete()` devuelve `false` explícitamente, dejando pasar sólo al
admin por `before()`. La implementación coincide con la inferencia; si el product owner quiere que el
reclutador archive empresas, hay que cambiar la Policy, no la ruta.

### 2.9 Vacantes — §5.6

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/vacancies` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.6 recruiter/admin/company (propias) | ✔ |
| POST | `/vacancies` | ❌ | ❌ | ✅ | 🔒 | ✅ | §6 «Crear vacante — Empresa ✅ (propia)» | ✔ |
| GET | `/vacancies/{vacancy}` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.6 | ✔ |
| PATCH | `/vacancies/{vacancy}` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 «PATCH /jobs/{id} — recruiter / admin» | ✔ |
| DELETE | `/vacancies/{vacancy}` | ❌ | ❌ | ❌ | ❌ | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/vacancies/{vacancy}/transition` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.6 «POST /jobs/{id}/transition — recruiter / admin» | ✔ |
| GET | `/vacancies/{vacancy}/suggested-candidates` | ❌ | ❌ | ✅ | ❌ | ✅ | **UNSPECIFIED** | ✔ |

**Inferencias**

- `DELETE /vacancies/{vacancy}` — §5.6 no lista el `DELETE` de vacantes. Se infiere **sólo admin** por
  simetría con empresas y porque es destructivo. Coincide con `VacancyPolicy::delete()`.
- `GET /vacancies/{vacancy}/suggested-candidates` — no aparece en §5. Se infiere **recruiter/admin**: la
  respuesta es un corte rankeado de la base de talento con candidatos que HUMAE **no** presentó, es decir el
  directorio por otra puerta, y §6 cierra el directorio a la empresa. La implementación ya razona así en el
  docblock de `VacancyPolicy::viewSuggestedCandidates()`.

### 2.10 Pipeline — §5.7 (recruiter / admin)

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/vacancies/{vacancy}/assignments` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.7 | ✔ |
| POST | `/vacancies/{vacancy}/assignments` | ❌ | ❌ | ✅ | ❌ | ✅ | §6 «Asignar candidatos — Empresa ❌» | ✔ |
| PATCH | `/assignments/{assignment}` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.7 | ✔ |
| DELETE | `/assignments/{assignment}` | ❌ | ❌ | ✅ | ❌ | ✅ | §5.7 | ✔ |
| PATCH | `/assignments/{assignment}/select-finalist` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.7 + §6 «Seleccionar finalista — Empresa ✅ (decide)» | ✔ |
| GET | `/assignments/{assignment}/notes` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.7 + §6 «Notas internas — Empresa ❌» | ✔ |
| POST | `/assignments/{assignment}/notes` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.7 + §6 «Notas internas — Empresa ❌» | ✔ |

Las tres rutas que la empresa alcanza (`select-finalist`, `notes`) exigen además que la asignación esté en
una etapa `AssignmentStage::visibleToCompany()`. La sonda comprueba las dos mitades: una asignación
`sourced` responde `403` a la empresa, y el payload de `select-finalist` no contiene `recruiter_notes` ni
`rejection_reason`. El hilo de notas se filtra a `visibility = company`.

### 2.11 Entrevistas — §5.8

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/interviews` | ❌ | 🔒 | ✅ | 🔒 | ✅ | §5.8 | ✔ |
| POST | `/interviews` | ❌ | ❌ | ✅ | 🔒 | ✅ | §6 «Programar entrevista — Candidato ❌, Empresa: propuesta» | ✔ |
| GET | `/interviews/{interview}` | ❌ | 🔒 | ✅ | 🔒 | ✅ | §5.8 | ✔ |
| PATCH | `/interviews/{interview}` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.8 «reprograma, cambia estado» | ✔ |
| POST | `/interviews/{interview}/select-slot` | ❌ | 🔒 | ✅ | 🔒 | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/interviews/{interview}/meeting-details` | ❌ | ❌ | ✅ | ❌ | ✅ | **UNSPECIFIED** | ✔ |
| POST | `/interviews/{interview}/confirm` | ❌ | 🔒 | ✅ | 🔒 | ✅ | §6 «Confirmar entrevista» | ✔ |
| POST | `/interviews/{interview}/cancel` | ❌ | 🔒 | ✅ | 🔒 | ✅ | §5.8 (sin rol explícito) | ✔ |
| POST | `/interviews/{interview}/complete` | ❌ | ❌ | ✅ | ❌ | ✅ | **UNSPECIFIED** | ✔ |

**Inferencias**

- `select-slot` — no aparece en §5.8. Se infiere **candidato dueño + HUMAE + decisor (owner/manager) de la
  empresa**: elegir uno de los dos horarios propuestos es una decisión de agenda de las partes, no una
  acción interna.
- `meeting-details` y `complete` — no aparecen en §5.8. Se infieren **recruiter/admin**: el enlace de la
  reunión lo publica HUMAE tras la confirmación (así lo declara `ScheduleInterviewRequest`, que prohíbe
  `meeting_url` a la empresa) y el cierre exige `recruiter_feedback` + `recommendation`, que son evaluación
  interna.
- `cancel` — §5.8 lista la ruta sin anotar rol. Se infiere **partes de la entrevista + HUMAE**.

`InterviewResource` oculta `rating`, `recommendation`, `recruiter_feedback` y `company_feedback` al
candidato. Verificado por sonda con centinela.

### 2.12 Mi empresa (`company_user`) — §5.6

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/me/company` | ❌ | ❌ | ❌ | 🔒 | ✅ | §6 «Ver/editar su propio perfil — Empresa ✅ (propia)» | ✔ |
| PATCH | `/me/company` | ❌ | ❌ | ❌ | 🔒 | ✅ | §6 ídem (owner/manager) | ✔ |
| GET | `/me/company/members` | ❌ | ❌ | ❌ | 🔒 | ❌ | **UNSPECIFIED** | ✔ |
| POST | `/me/company/members` | ❌ | ❌ | ❌ | 🔒 | ❌ | **UNSPECIFIED** | ✔ |
| PATCH | `/me/company/members/{member}` | ❌ | ❌ | ❌ | 🔒 | ❌ | **UNSPECIFIED** | ✔ |
| DELETE | `/me/company/members/{member}` | ❌ | ❌ | ❌ | 🔒 | ❌ | **UNSPECIFIED** | ✔ |
| GET | `/me/company/vacancies` | ❌ | ❌ | ❌ | 🔒 | ✅ | §5.6 «GET /me/company/jobs — company_user» | ✔ |
| POST | `/me/company/vacancies` | ❌ | ❌ | ❌ | 🔒 | ✅ | §5.6 «POST /me/company/jobs — queda borrador» | ✔ |
| GET | `/me/company/vacancies/{vacancy}` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.6 | ✔ |
| PATCH | `/me/company/vacancies/{vacancy}` | ❌ | ❌ | ✅ | 🔒 | ✅ | §5.6 | ✔ |
| POST | `/me/company/vacancies/{vacancy}/transition` | ❌ | ❌ | ✅ | 🔒 | ✅ | §6 «Aprobar/activar ❌», «Marcar cubierta ✅ (propone)» | ✔ |
| GET | `/me/company/vacancies/{vacancy}/assignments` | ❌ | ❌ | ✅ | 🔒 | ✅ | §6 «Ver candidatos asignados — Empresa ✅ (propia vacante)» | ✔ |

**Inferencia (`/me/company/members/*`)**: §5.6 lista `/companies/{id}/members` como admin/recruiter pero no
prevé una gestión de equipo autoservicio. Se infiere **miembros de la propia empresa leen, el `owner`
escribe**, que es lo que implementa `MyCompanyMemberController`. La restricción de un único `owner` por
empresa está resguardada por el controlador.

`GET /me/company/vacancies/{vacancy}/assignments` es la única puerta legítima de la empresa al pipeline:
filtra por `AssignmentStage::visibleToCompanyValues()` y usa `CompanyAssignmentResource`, que omite
`recruiter_notes`, `rejection_reason` y datos de contacto. La sonda confirma que ni el apellido del
candidato `sourced` ni ninguna clave de PII aparecen en el payload.

### 2.13 Reportes — §5.10 / §6

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/admin/reports/candidates-registered` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 «Ver reportes» | **F-11** |
| GET | `/admin/reports/active-memberships` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/payments` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/expiring-memberships` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/vacancies-by-state` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/interviews` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/recruiter-effectiveness` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/time-to-fill` | ❌ | ❌ | 🔒 | 🔒 | ✅ | §6 ídem | **F-11** |
| GET | `/admin/reports/most-searched-profiles` | ❌ | ❌ | 🔒 | ❌ | ✅ | §6 + §5.5 | **F-11** |

**Conflicto interno del documento maestro**: §5.10 titula la sección «Admin (admin only)», mientras §6
concede «Ver reportes — Reclutador ✅ (sus procesos), Empresa cliente ✅ (sus vacantes)». Se resuelve a favor
de §6 por indicación del product owner. Excepción documentada: `most-searched-profiles` devuelve nombres de
candidatos, y §6 cierra el directorio a la empresa (❌), así que ese informe concreto se mantiene en
recruiter/admin.

### 2.14 Admin — usuarios y catálogos — §5.10 (admin only)

| Método | Ruta | anón | cand | recr | emp | admin | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|
| GET | `/admin/users` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| POST | `/admin/users` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| POST | `/admin/users/{user}/resend-invitation` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| POST | `/admin/users/{user}/approve` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| POST | `/admin/users/{user}/reject` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| DELETE | `/admin/users/{user}` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| GET/POST | `/admin/catalogs/skills` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| PATCH/DELETE | `/admin/catalogs/skills/{skill}` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| GET/POST | `/admin/catalogs/languages` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| PATCH/DELETE | `/admin/catalogs/languages/{language}` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| GET/POST | `/admin/catalogs/degree-levels` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| PATCH/DELETE | `/admin/catalogs/degree-levels/{degreeLevel}` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| GET/POST | `/admin/catalogs/functional-areas` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |
| PATCH/DELETE | `/admin/catalogs/functional-areas/{functionalArea}` | ❌ | ❌ | ❌ | ❌ | ✅ | ✔ |

Los 16 endpoints de catálogos se cierran con el permiso Spatie `catalogs.manage`, que sólo el rol `admin`
posee — coherente con §6 «CRUD catálogos: Reclutador ❌». Los 6 de usuarios usan una comprobación de rol
directa (`UserController::ensureAdmin()`).

### 2.15 Salud y webhooks

| Método | Ruta | anón | cand | recr | emp | admin | Fuente | Estado |
|---|---|:-:|:-:|:-:|:-:|:-:|---|---|
| GET | `/health` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.11 público | ✔ |
| POST | `/webhooks/stripe` | ✅ | ✅ | ✅ | ✅ | ✅ | §5.3 público, firmado | ✔ |

El webhook autoriza por firma HMAC (`Stripe-Signature`); una petición sin firma válida recibe `400` antes
de tocar el dominio.

---

## 3. Rutas de infraestructura (fuera de `/api/v1`)

Nueve rutas que no están en §5 y no se sondean. Se documentan porque forman parte de la superficie expuesta.

| Método | Ruta | Acceso | Nota |
|---|---|---|---|
| GET | `/` | Público | Vista `welcome` de Laravel. Retirar antes de producción |
| GET | `/login` | Público | Stub que devuelve `401` en JSON; existe sólo para que `Authenticate` tenga a dónde redirigir |
| GET | `/up` | Público | Health check del framework |
| GET | `/sanctum/csrf-cookie` | Público | Requerido por el modo SPA de Sanctum |
| GET | `/docs` | **Público** | Documentación Scribe: catálogo completo de endpoints y payloads de ejemplo |
| GET | `/docs.openapi` | **Público** | Spec OpenAPI |
| GET | `/docs.postman` | **Público** | Colección Postman |
| GET | `/storage/{path}` | Firma requerida | `ServeFile` exige `hasValidRelativeSignature()` porque el disco `local` es privado |
| PUT | `/storage/{path}` | Firma requerida | `ReceiveFile` exige `?upload=1` + firma válida |

**Verificado**: `config/filesystems.php` declara `local.serve = true` con raíz `storage/app/private`, que es
donde viven los documentos del candidato. Se revisó `Illuminate\Filesystem\ServeFile` y
`Illuminate\Filesystem\ReceiveFile`: ambos abortan salvo firma relativa válida, porque la visibilidad del
disco no es `public`. **No hay descarga ni escritura anónima de documentos privados.**

**Recomendación (no es un hallazgo de autorización)**: las tres rutas `/docs*` son públicas. Publican el
inventario completo de endpoints de una plataforma privada. Conviene servirlas tras autenticación o
limitarlas a entornos no productivos (`config/scribe.php` → `laravel.middleware`).

---

## 4. Rutas UNSPECIFIED — resumen

§5/§6 no cubren 20 rutas. Ninguna se dejó sin criterio: cada una lleva la inferencia aplicada y su motivo
en la sección correspondiente.

| # | Ruta | Inferencia | Motivo |
|---|---|---|---|
| 1 | `POST /auth/register/recruiter` | Público (solicitud + aprobación admin) | §6 marca «—» para el reclutador; la cuenta nace en `pending_approval` |
| 2 | `POST /auth/verify-email/resend` | Público con rate limit | Respuesta genérica, no filtra existencia de cuenta |
| 3 | `GET /auth/invitation/{token}` | Público, autorizado por el token | El token es la credencial |
| 4 | `POST /auth/invitation/accept` | Público, autorizado por el token | Ídem |
| 5–9 | `GET /catalogs/{skills,languages,degree-levels,functional-areas,vacancy-types}` | Cualquier autenticado | Datos maestros sin PII |
| 10 | `GET /directory/candidates/{c}/documents/{d}/download` | recruiter / admin | Es parte del expediente completo (§5.5) |
| 11 | `DELETE /companies/{company}` | admin | Única acción destructiva de la fila §5.6 |
| 12 | `DELETE /vacancies/{vacancy}` | admin | Simetría con empresas; destructivo |
| 13 | `GET /vacancies/{v}/suggested-candidates` | recruiter / admin | Es el directorio por otra puerta; §6 lo cierra a la empresa |
| 14 | `POST /interviews/{i}/select-slot` | Candidato dueño + HUMAE + decisor de la empresa | Decisión de agenda de las partes |
| 15 | `POST /interviews/{i}/meeting-details` | recruiter / admin | El enlace lo publica HUMAE (coherente con `ScheduleInterviewRequest`) |
| 16 | `POST /interviews/{i}/complete` | recruiter / admin | Exige evaluación interna |
| 17 | `POST /interviews/{i}/cancel` | Partes + HUMAE | §5.8 lista la ruta sin anotar rol |
| 18–21 | `GET/POST/PATCH/DELETE /me/company/members[/{member}]` | Miembros leen, `owner` escribe | §5.6 no prevé gestión de equipo autoservicio |

Nueve de estas rutas (5–9, 18–21) son de bajo riesgo. Las restantes tocan PII o el pipeline y conviene que
el product owner las ratifique en §5/§6 antes del siguiente ciclo.

---

## 5. Hallazgos

Severidad: **Crítica** = fuga de PII o escritura entre inquilinos; **Alta** = escritura o lectura que §6
niega explícitamente; **Media** = superficie de más sin fuga inmediata; **Baja** = divergencia documental o
funcionalidad ausente.

| Id | Sev. | Ruta | Rol | Qué ocurre | Fila §5/§6 violada |
|---|---|---|---|---|---|
| ~~**F-01**~~ | Crítica | `GET /companies` | `company_user` (cualquiera) | **Cerrado.** `CompanyPolicy::viewAny()` es sólo recruiter (admin vía `before`). Segundo candado: el scope de inquilino deja el padrón en la propia empresa aunque alguien reabra la Policy | §5.6 «GET /companies — admin / recruiter» |
| ~~**F-02**~~ | Crítica | `POST /vacancies` | `company_user` ajeno | **Cerrado.** `VacancyRequest` declara `company_id` como campo acotado por inquilino y `CompanyTenancy::assertBelongsTo()` responde `403` si el llamante no pertenece a esa empresa | §6 «Crear vacante — Empresa cliente ✅ (**propia**)» |
| ~~**F-03**~~ | Alta | `POST /vacancies/{id}/transition` | `company_user` owner/manager | **Cerrado.** El endpoint de staff queda tras `RoleMiddleware:recruiter|admin` (§5.6) y autoriza con la habilidad que nombra el estado destino (`VacancyStateMachine::abilityFor()`), no con `update` | §5.6 «POST /jobs/{id}/transition — recruiter / admin»; §6 «Marcar vacante como cubierta — Reclutador ✅ (confirma)» |
| ~~**F-04**~~ | Alta | `PATCH /vacancies/{id}` | `company_user` owner/manager | **Cerrado.** `PATCH`, `DELETE` y `/transition` de `/vacancies/{id}` quedan tras `RoleMiddleware:recruiter|admin`: son la superficie de staff que §5.6 describe. La empresa opera las suyas en `/me/company/vacancies/*` | §5.6 «PATCH /jobs/{id} — recruiter / admin»; §6 «Agregar notas internas — Empresa ❌» |
| ~~**F-05**~~ | Alta | `POST` y `PATCH /me/company/vacancies[/{id}]` | `company_user` owner/manager | **Cerrado.** `VacancyRequest::staffOnlyFields()` rechaza con `403` `internal_notes`, `fee_amount`, `fee_percentage`, `sla_days` y `assigned_recruiter_id`; `company_id` pasa por el candado de inquilino. El `unset()` silencioso del controlador se borró | §6 «Agregar notas internas — Empresa cliente ❌» |
| ~~**F-06**~~ | Alta | `PATCH /companies/{id}` | `company_user` owner/manager | **Cerrado.** `CompanyPolicy::update()` es sólo recruiter. Además `MyCompanyController` dejó de llevar su propia lista de campos: ambos endpoints comparten `CompanyRequest`, que declara esos cinco como `staffOnlyFields()` | §5.6 «PATCH /companies/{id} — admin / recruiter» |
| ~~**F-07**~~ | Alta | `GET`, `POST`, `DELETE /companies/{id}/members` | `company_user` owner/manager | **Cerrado** para el cliente: el controlador sigue autorizando con `CompanyPolicy::view/update`, que ahora son de staff. Queda abierto que `AttachMemberRequest` acepte cualquier `user_id` — para HUMAE es el alta de equipo que §5.6 le concede; ver F-13 para la variante de autoservicio | §5.6 «GET/POST/DELETE /companies/{id}/members — admin / recruiter» |
| ~~**F-08**~~ | Alta | `PATCH /interviews/{id}` | `company_user` owner/manager | **Cerrado.** Ambos Requests declaran la misma regla por el mismo mecanismo (`RestrictsFieldsByRole`). `company_feedback` sigue abierto a la empresa: es su propia opinión | §6 «Agregar notas internas — Empresa cliente ❌» |
| ~~**F-09**~~ | Media | 30 rutas `/me/profile/*` y `/me/psychometrics/*` | `recruiter`, `company_user`, `admin` | **Cerrado.** Las 30 rutas quedan tras `RoleMiddleware:candidate`. Además `ProfileService` se partió en `find()` (no crea) y `findOrCreate()` (crea, y **rechaza** si la cuenta no es de candidato); `ensureOwned()` usa `find()`, así que una lectura denegada ya no escribe. El rastro de datos preexistente queda pendiente de verificar — ver §5.4 | §5.2 y §5.4 «auth, role: candidate» |
| ~~**F-10**~~ | Media | `POST /me/company/vacancies/{id}/transition` | `company_user` | **Cerrado.** Se borró la lista blanca del controlador. Ambos endpoints derivan la habilidad del estado destino: `publish` (staff), `close` (staff + owner/manager), `cancel` (ídem), `advance` (staff) | §6 «Aprobar / activar vacante — Empresa ❌» y «Marcar vacante como cubierta — Empresa ✅ (propone)» |
| **F-11** | Media | 9 rutas `/admin/reports/*` | `recruiter`, `company_user` | El reclutador recibe agregados **globales** (pagos, membresías, efectividad de todos los reclutadores) donde §6 dice «sus procesos». La empresa recibe `403` donde §6 dice «✅ sus vacantes» | §6 «Ver reportes» |
| ~~**F-12**~~ | Baja | `POST /auth/register/company` | Todos | **Cerrado retirando la ruta**, junto con `AuthController::registerCompany`, `RegisterCompanyRequest` y `AuthService::registerCompanyUser`. `pending_approval` la hacía segura, no correcta: cualquiera creaba una fila en `companies` y una cuenta `company_user`, y §5 no lista la ruta. El alta soportada es `POST /admin/users`, que emite el token que consume `/auth/invitation/accept`. **Cambio incompatible para el frontend** | §6 «Registrarse — Empresa cliente ❌ (invitación)» |
| ~~**F-13**~~ | Media | `POST /me/company/members` | `company_user` owner | **Cerrado.** El endpoint ya no asigna roles y sólo enlaza cuentas que **ya** son `company_user` y no pertenecen a otra empresa; cualquier otra responde `403` remitiendo a HUMAE. Un flujo de invitación con aceptación explícita sería mejor y no se construyó aquí: sería una funcionalidad nueva, no un cierre de hallazgo | **UNSPECIFIED** — inferencia: no se puede enrolar una cuenta ajena sin su consentimiento |
| **F-14** | Media | Toda la API | Todos | `EnsureVerifiedEmail` y `EnsureActiveMembership` están registrados como alias en `bootstrap/app.php` y **no se aplican a ninguna ruta**. Mismo tipo de defecto que la `InterviewPolicy` muerta: parecen protección en revisión y no ejecutan nada. Consecuencia de negocio: un candidato sin membresía activa (499 MXN / 6 meses) usa el perfil y los psicométricos completos | §1 (premisa de negocio); §5 no lo especifica |
| ~~**F-15**~~ | Baja | `GET /companies/{id}` | `company_user` miembro | **Cerrado.** `CompanyPolicy::view()` es sólo recruiter. El cliente se lee a sí mismo en `/me/company` | §5.6 «GET /companies/{id} — admin / recruiter» |
| ~~**F-16**~~ | Baja | `POST /me/membership/checkout` | `recruiter`, `company_user`, `admin` | **Cerrado.** `RoleMiddleware:candidate` sobre la ruta. `GET /me/membership` y `GET /me/payments` siguen abiertos a cualquier autenticado, como dice §5.3 | §6 «Pagar membresía — Reclutador —, Empresa —, Admin —» |

### 5.1 Causa raíz de F-03: dos habilidades escritas y nunca conectadas — resuelta

`VacancyPolicy` definía `publish()` y `close()` con reglas propias y **ningún controlador las invocaba**. Los
dos endpoints de transición autorizaban con `update`, que la Policy concede al `owner`/`manager` de la
empresa dueña: «puedo editar mi vacante» y «puedo cerrar mi vacante» colapsadas en la misma habilidad.

Ahora la habilidad la nombra el estado destino, en un solo sitio
(`VacancyStateMachine::abilityFor()`), y ambos endpoints la derivan de ahí:

| Estado destino | Habilidad | Quién, según §6 |
|---|---|---|
| `activa` | `publish` | Reclutador / admin — «Aprobar / activar vacante: Empresa ❌» |
| `cubierta` | `close` | Reclutador (confirma) + owner/manager de la empresa (propone) |
| `cancelada` | `cancel` | Ídem `close` — §6 no tiene fila; se conserva el comportamiento previo |
| resto (`en_busqueda`, `con_candidatos_asignados`, `entrevistas_en_curso`, `finalista_seleccionado`) | `advance` | Reclutador / admin — es el avance interno de HUMAE (§5.7) |

`publish` dejó de exigir que el reclutador sea el `assigned_recruiter_id`: §6 no pone esa condición y el
mismo reclutador podía editar la fila de todos modos. Se documenta como relajación deliberada.

### 5.2 Inventario de habilidades de Policy

`tests/Feature/Security/AuthorizationMatrixTest.php` fija este inventario y falla si alguien añade una
habilidad sin clasificarla.

| Policy | Invocadas | Huérfanas |
|---|---|---|
| `CandidateProfilePolicy` | `viewAny`, `view`, `downloadCv`, `downloadDocument`, `favorite` | — |
| `CompanyPolicy` | `viewAny`, `view`, `create`, `update`, `delete` | — |
| `InterviewPolicy` | `view`, `selectSlot`, `reschedule`, `confirm`, `cancel` | — |
| `VacancyAssignmentPolicy` | `viewAny`, `create`, `update`, `delete`, `selectFinalist`, `scheduleInterview`, `viewNotes`, `createNote`, `viewInternalNotes` | — |
| `VacancyPolicy` | `viewAny`, `view`, `viewSuggestedCandidates`, `create`, `update`, `publish`, `close`, `cancel`, `advance`, `delete` | — |

**Cero habilidades huérfanas.** Las 7 que había se resolvieron una por una:

| Habilidad | Resolución |
|---|---|
| `VacancyPolicy::publish` | **Conectada** — transición a `activa` en ambos endpoints |
| `VacancyPolicy::close` | **Conectada** — transición a `cubierta` en ambos endpoints |
| `InterviewPolicy::confirm` | **Conectada** — `POST /interviews/{id}/confirm` |
| `InterviewPolicy::cancel` | **Conectada** — `POST /interviews/{id}/cancel`. Al conectarla salió a la luz que le faltaba la rama del candidato: el controlador autorizaba con `view`, que sí la tiene, así que la habilidad escrita era más estricta que el comportamiento real. Se añadió la rama |
| `CandidateProfilePolicy::update` | **Borrada** — el controlador resuelve el perfil desde el usuario autenticado; la pertenencia es estructural, no una decisión de política |
| `CandidateProfilePolicy::delete` | **Borrada** — no existe endpoint que borre un expediente |
| `VacancyAssignmentPolicy::view` | **Borrada** — no existe endpoint que lea una asignación suelta |

Se añadieron dos habilidades nuevas para completar el vocabulario de transiciones: `cancel` y `advance`.

Las Policies se descubren por convención de Laravel 12 (`App\Models\X` → `App\Policies\XPolicy`);
`AppServiceProvider` no registra ninguna explícitamente. El descubrimiento funciona, pero cualquier Policy
que no siga el naming quedaría silenciosamente desconectada.

### 5.4 Rastro de datos de F-09 — pendiente de verificar en producción

El agujero de acceso venía con uno de integridad: cada llamada de un no-candidato a la superficie
`/me/profile/*` **creaba** su fila en `candidate_profiles`, es decir lo inscribía en la base de talento.
El código ya no lo hace; las filas creadas antes siguen ahí.

**No se pudo comprobar**: la base MySQL no es alcanzable desde el entorno de trabajo (`Connection
refused`) y la suite corre sobre SQLite en memoria. La verificación queda pendiente contra la base real.

Consulta de detección (sólo lectura):

```sql
SELECT cp.id, cp.user_id, u.email, cp.state, cp.created_at,
       GROUP_CONCAT(r.name) AS roles
FROM candidate_profiles cp
JOIN users u ON u.id = cp.user_id
LEFT JOIN model_has_roles mhr
       ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
LEFT JOIN roles r ON r.id = mhr.role_id
WHERE cp.deleted_at IS NULL
GROUP BY cp.id, cp.user_id, u.email, cp.state, cp.created_at
HAVING COALESCE(SUM(r.name = 'candidate'), 0) = 0;
```

**Limpieza propuesta — no ejecutada.** Un perfil huérfano sólo es seguro de retirar si nunca se usó. Antes
de tocar nada hay que confirmar, fila por fila, que no tiene dependientes en `vacancy_assignments`,
`psychometric_attempts`, `candidate_documents`, `directory_favorites`, `candidate_skills`,
`candidate_languages`, `candidate_functional_areas`, `candidate_work_schedules` ni en las tablas de
historial (`candidate_experiences`, `candidate_educations`, `candidate_courses`,
`candidate_certifications`, `candidate_references`).

- Perfil **sin dependientes y en estado `registro_incompleto`**: es el efecto colateral puro. Se propone
  soft delete — `candidate_profiles` ya usa `SoftDeletes` —, que lo saca del directorio y conserva la
  evidencia.
- Perfil **con dependientes**: no se toca. Significa que alguien lo usó de verdad; revisar caso por caso
  con el product owner.

Recomendación operativa: correr la detección, adjuntar el resultado al ticket y decidir con el product
owner antes de escribir nada.


### 5.3 Permiso a nivel de campo: un solo mecanismo

`App\Http\Requests\Concerns\RestrictsFieldsByRole` es el único sitio donde se declara qué campos puede
enviar cada rol. Un Request que lo usa contesta dos preguntas:

| Declaración | Significado | Respuesta si se incumple |
|---|---|---|
| `staffOnlyFields()` | Campos que sólo escribe HUMAE (recruiter/admin) | `403` nombrando los campos |
| `companyScopedFields()` | Campos con un `company_id` que debe ser del llamante | `403` |

**Por qué `403` y no `422`**: «este campo no es tuyo» es una decisión de autorización. Contestar `422`
invita al llamante a corregir el payload cuando lo que está mal es su rol. Además el chequeo corre en
`authorize()`, antes de las reglas, así que un campo prohibido se rechaza aunque el resto del payload sea
inválido.

Declaraciones actuales:

| Request | `staffOnlyFields()` | `companyScopedFields()` |
|---|---|---|
| `VacancyRequest` | `internal_notes`, `fee_amount`, `fee_percentage`, `sla_days`, `assigned_recruiter_id` | `company_id` |
| `UpdateInterviewRequest` | `rating`, `recommendation`, `recruiter_feedback`, `meeting_url`, `meeting_provider`, `meeting_id`, `location` | — |
| `ScheduleInterviewRequest` | `meeting_url`, `meeting_provider`, `meeting_id` | — |
| `CompanyRequest` | `status`, `internal_notes`, `account_manager_id`, `rfc`, `slug` | — |


---

## 6. Auditoría del seeder de permisos

`database/seeders/RolesAndPermissionsSeeder.php`. La autorización se resuelve hoy por nombre de método de
Policy, así que la tabla de permisos parece inerte. No lo es: `spatie/laravel-permission` registra un
`Gate::before` que devuelve `true` en cuanto el nombre de la habilidad coincide con un permiso del usuario,
**antes de que la Policy se ejecute**. Un permiso de más es una Policy que se apagará sola el día que
alguien escriba `$user->can('directory.view-full')`.

### 6.1 Concesiones que contradecían §6

Todas en el rol `company_user`, bajo el comentario «Directorio de candidatos (acceso al pool global evaluado
por HUMAE)».

| Permiso retirado | Fila §6 |
|---|---|
| `directory.view` | «Ver directorio de candidatos — Empresa cliente: ❌» |
| `directory.view-full` | «Ver expediente completo de candidato — Empresa cliente: ❌» |
| `directory.favorite` | «Marcar favoritos — Empresa cliente: ❌» |
| `cv.download-any` | «Descargar CV de cualquier candidato — Empresa cliente: ❌» |
| `assignments.create` | «Asignar candidatos a vacante — Empresa cliente: ❌» |
| `assignments.update` | §5.7 deja el pipeline en recruiter/admin |
| `vacancies.publish` | «Aprobar / activar vacante — Empresa cliente: ❌» |

`vacancies.publish` merece una nota: §6 lo niega y la implementación lo permite (F-10). Se retira el permiso
para alinear el seeder con §6, tal como pidió el product owner; **la divergencia de comportamiento sigue
abierta como F-10** y no se tocó código para cerrarla.

### 6.2 Concesiones conservadas y por qué

- `assignments.notes.create` (company_user) — §6 le cierra a la empresa las notas **internas**, y el hilo
  `visibility = company` sí es suyo. El nombre del permiso no distingue las dos cosas. Se conserva y se
  recomienda partirlo en `assignments.notes.create-company` / `-internal` cuando alguien lo consulte.
- `interviews.schedule`, `interviews.reschedule`, `interviews.cancel` (company_user) — §6 concede
  «Programar entrevista — Empresa cliente: propuesta desde panel, requiere HUMAE». Reprogramar y cancelar no
  tienen fila propia; se conservan por coherencia con `InterviewPolicy`.
- `vacancies.close` (company_user) — §6 «Marcar vacante como cubierta — Empresa ✅ (propone)».
- `reports.view-own` (company_user y recruiter) — §6 «Ver reportes» con alcance acotado para ambos.

### 6.3 Deriva de vocabulario (no se corrigió)

El vocabulario de permisos y el de Policies llevan vidas separadas. No es explotable hoy, pero garantiza que
cualquier migración futura a `can()` rompa el modelo:

- El reclutador **no** tiene `companies.create` ni `companies.update-any`, pero `CompanyPolicy` le permite
  crear y editar cualquier empresa.
- El reclutador **no** tiene `vacancies.update-any`, pero `VacancyPolicy::update()` le permite editar
  cualquier vacante.
- El reclutador **no** tiene `interviews.confirm`, pero `InterviewPolicy` le permite confirmar.
- El candidato **no** tiene `interviews.cancel`, pero el controlador le permite cancelar la propia.
- **No existe** ningún permiso para «Seleccionar candidato finalista», la única acción del pipeline que §6
  concede a la empresa.

### 6.4 Guardia permanente

`tests/Feature/Security/RolePermissionMatrixTest.php` fija las celdas ❌ de §6 como permisos que el rol no
puede tener, y comprueba de punta a punta que `$user->can(...)` devuelve `false` para las siete habilidades
retiradas. Las celdas «—» quedan deliberadamente fuera: §6 las marca como no aplicables, no como denegadas.

---

## 7. Cobertura de la sonda

| Concepto | Cantidad |
|---|---|
| Rutas totales (`route:list`) | 154 |
| Rutas `/api/v1/*` | 145 |
| Rutas `/api/v1/*` sondeadas | 144 (100 %, verificado por test) |
| Rutas de infraestructura documentadas, no sondeadas | 9 |
| Filas de la tabla de expectativas | 154 (nueve rutas se sondean dos o tres veces con distinto payload: inquilino ajeno, escritura de campos internos, etapa `sourced`) |
| Peticiones HTTP por corrida | 1 078 (154 filas × 7 actores) |
| Actores | 7 — anónimo, candidato dueño, candidato ajeno, reclutador, `company_user` dueño, `company_user` ajeno, admin |
| Tests de apoyo en el mismo archivo | 5 — cobertura de rutas, inventario de Policies, habilidades invocadas, habilidades huérfanas, middlewares aplicados |

Cada petición denegada se contrasta contra una huella de contenido de 24 tablas de dominio, así que un `403`
que aun así escribe se reporta como fallo. La huella se toma también en los `GET` denegados: fue así como se
detectó el efecto colateral de F-09.

---

## 8. Recomendaciones, por orden

1. **Centralizar el aislamiento entre inquilinos.** F-01, F-02, F-04, F-05, F-06 y F-07 son la misma
   omisión repetida: «pertenece a mi empresa» se comprueba en unos sitios sí y en otros no. Un
   `scope`/trait único (`BelongsToCallerCompany`) aplicado en el query builder y en la validación de
   `company_id` cierra los seis de una vez.
2. **Separar «editar» de «transicionar».** Conectar `VacancyPolicy::publish/close` en ambos endpoints de
   transición y mover la lista blanca de estados por rol a `VacancyStateMachine`, para que la restricción
   viva en un solo sitio y no en cada controlador. Cierra F-03 y F-10.
3. **Cerrar los Form Requests por rol.** `VacancyRequest` y `UpdateInterviewRequest` aceptan campos
   internos de cualquier llamante. `ScheduleInterviewRequest` ya demuestra el patrón correcto
   (`prohibited` condicionado al rol); replicarlo. Cierra F-04, F-05 y F-08.
4. **Poner un `role:candidate` sobre `/me/profile/*` y `/me/psychometrics/*`**, y sacar la creación implícita
   de `CandidateProfile` de la ruta de lectura. Cierra F-09.
5. **Aplicar o retirar los middlewares muertos.** F-14: o se enganchan a las rutas que corresponde, o se
   borran. Un middleware registrado que no protege nada es peor que ninguno.
6. **Ratificar en §5/§6 las 20 rutas UNSPECIFIED**, empezando por las que tocan PII o pipeline.
