<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\Catalogs\DegreeLevelController as AdminDegreeLevelController;
use App\Http\Controllers\Api\V1\Admin\Catalogs\FunctionalAreaController as AdminFunctionalAreaController;
use App\Http\Controllers\Api\V1\Admin\Catalogs\LanguageController as AdminLanguageController;
use App\Http\Controllers\Api\V1\Admin\Catalogs\SkillController as AdminSkillController;
use App\Http\Controllers\Api\V1\Admin\ContactSubmissionController as AdminContactSubmissionController;
use App\Http\Controllers\Api\V1\Admin\ReportsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Candidate\AvatarController;
use App\Http\Controllers\Api\V1\Candidate\CandidateProfileController;
use App\Http\Controllers\Api\V1\Candidate\CertificationController;
use App\Http\Controllers\Api\V1\Candidate\CourseController;
use App\Http\Controllers\Api\V1\Candidate\CvController;
use App\Http\Controllers\Api\V1\Candidate\DocumentController;
use App\Http\Controllers\Api\V1\Candidate\EducationController;
use App\Http\Controllers\Api\V1\Candidate\ExperienceController;
use App\Http\Controllers\Api\V1\Candidate\LanguageController;
use App\Http\Controllers\Api\V1\Candidate\MembershipController;
use App\Http\Controllers\Api\V1\Candidate\NotificationController;
use App\Http\Controllers\Api\V1\Candidate\PaymentController;
use App\Http\Controllers\Api\V1\Candidate\PsychometricController;
use App\Http\Controllers\Api\V1\Candidate\ReferenceController;
use App\Http\Controllers\Api\V1\Candidate\SkillController;
use App\Http\Controllers\Api\V1\Company\CompanyVacancyController;
use App\Http\Controllers\Api\V1\Company\MyCompanyController;
use App\Http\Controllers\Api\V1\Company\MyCompanyMemberController;
use App\Http\Controllers\Api\V1\Interviews\InterviewController;
use App\Http\Controllers\Api\V1\Recruiter\AssignmentController;
use App\Http\Controllers\Api\V1\Recruiter\AssignmentNoteController;
use App\Http\Controllers\Api\V1\Recruiter\CompanyController;
use App\Http\Controllers\Api\V1\Recruiter\CompanyMemberController;
use App\Http\Controllers\Api\V1\Recruiter\DirectoryController;
use App\Http\Controllers\Api\V1\Recruiter\VacancyController;
use App\Http\Controllers\Api\V1\Shared\CatalogController;
use App\Http\Controllers\Api\V1\Shared\ContactSubmissionController;
use App\Http\Controllers\Api\V1\Shared\HealthController;
use App\Http\Controllers\Api\V1\Shared\TutorialController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Middleware\EnsureVerifiedEmail;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Todas las rutas se sirven bajo el prefijo /api/v1/ definido en bootstrap/app.php.
|
*/

/**
 * Sesión autenticada y con correo verificado.
 *
 * §8.1 ordena el flujo `register → verify-email → /me/profile → …`, así que
 * verificar precede a todo lo demás. `EnsureVerifiedEmail` acompaña a
 * `auth:sanctum` en cada grupo autenticado como defensa en profundidad: aunque
 * hoy ningún endpoint emita un token sin verificar (F-17 cerró el del alta),
 * cualquier camino futuro que lo haga ya nace tapado.
 *
 * Fuera del candado a propósito: `/auth/logout`, `/auth/me` y
 * `/auth/resend-verification` — son la salida de emergencia de una cuenta sin
 * verificar. Cerrarlas dejaría al usuario encerrado sin forma de verificarse.
 *
 * @var list<string>
 */
$authenticated = ['auth:sanctum', EnsureVerifiedEmail::class];

Route::get('/health', HealthController::class)->name('health');

/*
|--------------------------------------------------------------------------
| Contacto público (captación de leads)
|--------------------------------------------------------------------------
| Público y sin autenticar: es el formulario de la landing, /contacto y
| /empresas, y también la página "solicitar acceso" para empresas cliente,
| ya que §6 deja las cuentas de empresa como invitación-only — no hay alta
| autoservicio, así que esta es la puerta de entrada. Lleva throttle por la
| misma razón que /auth/register y /auth/verify-email/resend: superficie
| pública sin autenticar, blanco de spam y de amplificación de correo hacia
| la bandeja de soporte.
*/
Route::post('/contact-submissions', [ContactSubmissionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('contact-submissions.store');

/*
|--------------------------------------------------------------------------
| Catálogos maestros (lectura, auth requerida)
|--------------------------------------------------------------------------
| Los candidatos los consumen desde el editor de perfil (skills, languages,
| degree levels); los recruiters los usan para construir filtros de vacantes.
*/
Route::middleware($authenticated)->prefix('catalogs')->name('catalogs.')->group(function (): void {
    Route::get('/skills', [CatalogController::class, 'skills'])->name('skills');
    Route::get('/languages', [CatalogController::class, 'languages'])->name('languages');
    Route::get('/degree-levels', [CatalogController::class, 'degreeLevels'])->name('degree-levels');
    Route::get('/functional-areas', [CatalogController::class, 'functionalAreas'])->name('functional-areas');
    Route::get('/positions', [CatalogController::class, 'positions'])->name('positions');
    Route::get('/vacancy-types', [CatalogController::class, 'vacancyTypes'])->name('vacancy-types');
});

Route::prefix('auth')->name('auth.')->group(function (): void {
    // Público
    Route::post('/register/recruiter', [AuthController::class, 'registerRecruiter'])
        ->middleware('throttle:5,1')
        ->name('register.recruiter');

    // No hay alta autoservicio de empresa cliente: §6 «Registrarse — Empresa
    // cliente ❌ (invitación)». Las cuentas de empresa las crea HUMAE desde
    // POST /admin/users, que emite el token de invitación que consume
    // /auth/invitation/accept.

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1')
        ->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:5,1')
        ->name('password.forgot');

    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.reset');

    // Invitaciones (público)
    Route::get('/invitation/{token}', [InvitationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('invitation.show');
    Route::post('/invitation/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')
        ->name('invitation.accept');

    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('verification.verify');

    // Reenvío público (sin auth) — desde la página /verify-email sin callback.
    // Rate-limit estricto para evitar spam de correos.
    Route::post('/verify-email/resend', [EmailVerificationController::class, 'resendPublic'])
        ->middleware('throttle:3,1')
        ->name('verification.resend-public');

    // Autenticado, pero SIN `EnsureVerifiedEmail`: estas tres son la salida de
    // emergencia de una cuenta sin verificar (cerrar sesión, saber quién eres,
    // pedir otro correo). Engancharles el candado deja al usuario encerrado.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/resend-verification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1')
            ->name('verification.resend');
    });
});

/*
|--------------------------------------------------------------------------
| Candidate (self) endpoints
|--------------------------------------------------------------------------
*/
Route::middleware($authenticated)->prefix('me')->name('me.')->group(function (): void {
    // Membresía y pagos. §5.3 titula la sección «Membership (auth)» sin acotar
    // rol y ambos GET se autoacotan al usuario autenticado, pero el checkout sí
    // tiene fila propia: §6 «Pagar membresía — Candidato ✅», y «—» para todos
    // los demás. La membresía de 499 MXN es del candidato (§1).
    Route::get('/membership', [MembershipController::class, 'show'])->name('membership.show');
    Route::post('/membership/checkout', [MembershipController::class, 'checkout'])
        ->middleware([RoleMiddleware::using([UserRole::Candidate]), 'throttle:10,1'])
        ->name('membership.checkout');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');

    /*
    |----------------------------------------------------------------------
    | Autoservicio del candidato — §5.2 y §5.4
    |----------------------------------------------------------------------
    | Ambas secciones se encabezan «auth, role: candidate» y ninguna de las
    | 30 rutas lo comprobaba (F-09). No era sólo superficie de más: abrir
    | GET /me/profile daba de alta un candidate_profiles para quien llamara,
    | así que un reclutador se inscribía en la base de talento con sólo
    | mirar. El aislamiento entre candidatos ya funcionaba; lo que faltaba
    | era el filtro de rol.
    */
    Route::middleware(RoleMiddleware::using([UserRole::Candidate]))->group(function (): void {
        // Perfil
        Route::get('/profile', [CandidateProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [CandidateProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/avatar', [AvatarController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('profile.avatar');

        // CV PDF
        Route::get('/profile/cv.pdf', [CvController::class, 'download'])
            ->middleware('throttle:30,1')
            ->name('profile.cv');

        // Experiencia laboral
        Route::apiResource('profile/experiences', ExperienceController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('profile.experiences');

        // Educación formal
        Route::apiResource('profile/educations', EducationController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('profile.educations');

        // Cursos
        Route::apiResource('profile/courses', CourseController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('profile.courses');

        // Certificaciones
        Route::apiResource('profile/certifications', CertificationController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('profile.certifications');

        // Referencias
        Route::apiResource('profile/references', ReferenceController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('profile.references');

        // Skills (pivot)
        Route::get('/profile/skills', [SkillController::class, 'index'])->name('profile.skills.index');
        Route::post('/profile/skills', [SkillController::class, 'store'])->name('profile.skills.store');
        Route::delete('/profile/skills/{skill}', [SkillController::class, 'destroy'])->name('profile.skills.destroy');

        // Languages (pivot)
        Route::get('/profile/languages', [LanguageController::class, 'index'])->name('profile.languages.index');
        Route::post('/profile/languages', [LanguageController::class, 'store'])->name('profile.languages.store');
        Route::delete('/profile/languages/{language}', [LanguageController::class, 'destroy'])->name('profile.languages.destroy');

        // Documents
        Route::get('/profile/documents', [DocumentController::class, 'index'])->name('profile.documents.index');
        Route::post('/profile/documents', [DocumentController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('profile.documents.store');
        Route::get('/profile/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware('throttle:60,1')
            ->name('profile.documents.download');
        Route::delete('/profile/documents/{document}', [DocumentController::class, 'destroy'])->name('profile.documents.destroy');

        // Psicométricos
        Route::get('/psychometrics/tests', [PsychometricController::class, 'listTests'])
            ->name('psychometrics.tests');
        Route::post('/psychometrics/attempts', [PsychometricController::class, 'startAttempt'])
            ->middleware('throttle:30,1')
            ->name('psychometrics.attempts.start');
        Route::get('/psychometrics/attempts/{attempt}', [PsychometricController::class, 'showAttempt'])
            ->name('psychometrics.attempts.show');
        Route::patch('/psychometrics/attempts/{attempt}/answers', [PsychometricController::class, 'saveAnswers'])
            ->name('psychometrics.attempts.answers');
        Route::post('/psychometrics/attempts/{attempt}/submit', [PsychometricController::class, 'submitAttempt'])
            ->name('psychometrics.attempts.submit');
        Route::get('/psychometrics/results/{attempt}', [PsychometricController::class, 'showResult'])
            ->name('psychometrics.results.show');
    });

    // Notificaciones (disponibles para cualquier usuario autenticado)
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.mark-read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.mark-all-read');

    // Tutorial de bienvenida por rol (Fase 16 §5.1). Disponible para cualquier
    // usuario autenticado: TutorialService resuelve qué tutorial aplica a su
    // propio rol. Una key que no aplica (de otro rol o inexistente) responde
    // 404, nunca 403 ni 500 — es un dato suministrado por el caller.
    Route::get('/tutorials', [TutorialController::class, 'index'])->name('tutorials.index');
    Route::post('/tutorials/{key}/complete', [TutorialController::class, 'complete'])
        ->name('tutorials.complete');
    Route::post('/tutorials/{key}/skip', [TutorialController::class, 'skip'])
        ->name('tutorials.skip');
});

/*
|--------------------------------------------------------------------------
| Recruiter / Admin: Companies + Vacancies
|--------------------------------------------------------------------------
*/
Route::middleware($authenticated)->group(function (): void {
    // Companies
    Route::apiResource('companies', CompanyController::class)
        ->names('companies');

    // Company members
    Route::get('/companies/{company}/members', [CompanyMemberController::class, 'index'])
        ->name('companies.members.index');
    Route::post('/companies/{company}/members', [CompanyMemberController::class, 'store'])
        ->name('companies.members.store');
    Route::delete('/companies/{company}/members/{userId}', [CompanyMemberController::class, 'destroy'])
        ->name('companies.members.destroy');

    // Vacancies. Lectura y alta las comparte la empresa cliente (§5.6, «GET,
    // POST /jobs — recruiter / admin / company_user (propias)»); la edición
    // administrativa y las transiciones son de HUMAE («PATCH /jobs/{id} —
    // recruiter / admin», «POST /jobs/{id}/transition — recruiter / admin»).
    // La empresa opera las suyas desde /me/company/vacancies/*.
    Route::get('/vacancies', [VacancyController::class, 'index'])->name('vacancies.index');
    Route::post('/vacancies', [VacancyController::class, 'store'])->name('vacancies.store');
    Route::get('/vacancies/{vacancy}', [VacancyController::class, 'show'])->name('vacancies.show');
    Route::middleware(RoleMiddleware::using([UserRole::Recruiter, UserRole::Admin]))->group(function (): void {
        Route::patch('/vacancies/{vacancy}', [VacancyController::class, 'update'])->name('vacancies.update');
        Route::delete('/vacancies/{vacancy}', [VacancyController::class, 'destroy'])->name('vacancies.destroy');
        Route::post('/vacancies/{vacancy}/transition', [VacancyController::class, 'transition'])
            ->name('vacancies.transition');
    });
    // El motor de matching entrega perfiles de candidatos que HUMAE todavía no
    // presentó: es el directorio con pasos extra. Sólo HUMAE
    // (ARCHITECTURE.md §6 «Ver directorio de candidatos — Empresa cliente: ❌»).
    Route::get('/vacancies/{vacancy}/suggested-candidates', [VacancyController::class, 'suggestedCandidates'])
        ->middleware(RoleMiddleware::using([UserRole::Recruiter, UserRole::Admin]))
        ->name('vacancies.suggested-candidates');

    // El directorio de candidatos —listado, expediente y archivos— es sólo de
    // HUMAE (ARCHITECTURE.md §5.5 y §6). La empresa cliente ve únicamente a los
    // candidatos que HUMAE le presentó, vía /me/company/vacancies/{id}/assignments.
    // Doble candado: middleware de rol + Policy.
    Route::middleware(RoleMiddleware::using([UserRole::Recruiter, UserRole::Admin]))->group(function (): void {
        Route::get('/directory/candidates', [DirectoryController::class, 'index'])
            ->name('directory.candidates.index');
        Route::get('/directory/candidates/{candidate}', [DirectoryController::class, 'show'])
            ->name('directory.candidates.show');
        Route::post('/directory/candidates/{candidate}/favorite', [DirectoryController::class, 'toggleFavorite'])
            ->name('directory.candidates.favorite');
        Route::get('/directory/candidates/{candidate}/cv.pdf', [DirectoryController::class, 'downloadCv'])
            ->middleware('throttle:30,1')
            ->name('directory.candidates.cv');
        Route::get('/directory/candidates/{candidate}/documents/{document}/download', [DirectoryController::class, 'downloadDocument'])
            ->middleware('throttle:60,1')
            ->name('directory.candidates.documents.download');
    });

    // Pipeline: assignments (recruiter / admin — ARCHITECTURE.md §5.7).
    // La empresa cliente lee su short list por el endpoint de empresa
    // (/me/company/vacancies/{id}/assignments), que filtra por etapa.
    Route::middleware(RoleMiddleware::using([UserRole::Recruiter, UserRole::Admin]))->group(function (): void {
        Route::get('/vacancies/{vacancy}/assignments', [AssignmentController::class, 'index'])
            ->name('vacancies.assignments.index');
        Route::post('/vacancies/{vacancy}/assignments', [AssignmentController::class, 'store'])
            ->name('vacancies.assignments.store');
        Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update'])
            ->name('assignments.update');
        Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])
            ->name('assignments.destroy');
    });

    // Única acción del pipeline que decide la empresa cliente (§5.7, §6).
    Route::patch('/assignments/{assignment}/select-finalist', [AssignmentController::class, 'selectFinalist'])
        ->name('assignments.select-finalist');

    // Notas de asignación: la empresa sólo alcanza las notas `company` de un
    // candidato ya presentado. Lo resuelve VacancyAssignmentPolicy.
    Route::get('/assignments/{assignment}/notes', [AssignmentNoteController::class, 'index'])
        ->name('assignments.notes.index');
    Route::post('/assignments/{assignment}/notes', [AssignmentNoteController::class, 'store'])
        ->name('assignments.notes.store');

    // Interviews (disponible para recruiter, candidate, company_user con scoping)
    Route::get('/interviews', [InterviewController::class, 'index'])->name('interviews.index');
    Route::post('/interviews', [InterviewController::class, 'store'])->name('interviews.store');
    Route::get('/interviews/{interview}', [InterviewController::class, 'show'])
        ->name('interviews.show');
    Route::patch('/interviews/{interview}', [InterviewController::class, 'update'])
        ->name('interviews.update');
    Route::post('/interviews/{interview}/select-slot', [InterviewController::class, 'selectSlot'])
        ->name('interviews.select-slot');
    Route::post('/interviews/{interview}/meeting-details', [InterviewController::class, 'addMeetingDetails'])
        ->name('interviews.meeting-details');
    Route::post('/interviews/{interview}/confirm', [InterviewController::class, 'confirm'])
        ->name('interviews.confirm');
    Route::post('/interviews/{interview}/cancel', [InterviewController::class, 'cancel'])
        ->name('interviews.cancel');
    Route::post('/interviews/{interview}/complete', [InterviewController::class, 'complete'])
        ->name('interviews.complete');
});

/*
|--------------------------------------------------------------------------
| Company user: vacantes de la empresa del usuario
|--------------------------------------------------------------------------
*/
Route::middleware($authenticated)->prefix('me/company')->name('me.company.')->group(function (): void {
    Route::get('/', [MyCompanyController::class, 'show'])->name('show');
    Route::patch('/', [MyCompanyController::class, 'update'])->name('update');

    Route::get('/members', [MyCompanyMemberController::class, 'index'])
        ->name('members.index');
    Route::post('/members', [MyCompanyMemberController::class, 'store'])
        ->name('members.store');
    Route::patch('/members/{member}', [MyCompanyMemberController::class, 'update'])
        ->name('members.update');
    Route::delete('/members/{member}', [MyCompanyMemberController::class, 'destroy'])
        ->name('members.destroy');

    Route::get('/vacancies', [CompanyVacancyController::class, 'index'])
        ->name('vacancies.index');
    Route::post('/vacancies', [CompanyVacancyController::class, 'store'])
        ->name('vacancies.store');
    Route::get('/vacancies/{vacancy}', [CompanyVacancyController::class, 'show'])
        ->name('vacancies.show');
    Route::patch('/vacancies/{vacancy}', [CompanyVacancyController::class, 'update'])
        ->name('vacancies.update');
    Route::post('/vacancies/{vacancy}/transition', [CompanyVacancyController::class, 'transition'])
        ->name('vacancies.transition');
    Route::get('/vacancies/{vacancy}/assignments', [CompanyVacancyController::class, 'assignments'])
        ->name('vacancies.assignments');
});

/*
|--------------------------------------------------------------------------
| Admin / Recruiter: Reportes
|--------------------------------------------------------------------------
*/
Route::middleware($authenticated)->prefix('admin/reports')->name('admin.reports.')->group(function (): void {
    Route::get('/candidates-registered', [ReportsController::class, 'candidatesRegistered'])
        ->name('candidates-registered');
    Route::get('/active-memberships', [ReportsController::class, 'activeMemberships'])
        ->name('active-memberships');
    Route::get('/payments', [ReportsController::class, 'payments'])->name('payments');
    Route::get('/expiring-memberships', [ReportsController::class, 'expiringMemberships'])
        ->name('expiring-memberships');
    Route::get('/vacancies-by-state', [ReportsController::class, 'vacanciesByState'])
        ->name('vacancies-by-state');
    Route::get('/interviews', [ReportsController::class, 'interviews'])->name('interviews');
    Route::get('/recruiter-effectiveness', [ReportsController::class, 'recruiterEffectiveness'])
        ->name('recruiter-effectiveness');
    Route::get('/time-to-fill', [ReportsController::class, 'timeToFill'])->name('time-to-fill');
    Route::get('/most-searched-profiles', [ReportsController::class, 'mostSearchedProfiles'])
        ->name('most-searched-profiles');
});

/*
|--------------------------------------------------------------------------
| Admin: gestión de usuarios (recruiters, company_users, admins)
|--------------------------------------------------------------------------
*/
Route::middleware($authenticated)->prefix('admin/users')->name('admin.users.')->group(function (): void {
    Route::get('/', [AdminUserController::class, 'index'])->name('index');
    Route::post('/', [AdminUserController::class, 'store'])->name('store');
    Route::post('/{user}/resend-invitation', [AdminUserController::class, 'resendInvitation'])
        ->name('resend-invitation');
    Route::post('/{user}/approve', [AdminUserController::class, 'approve'])
        ->name('approve');
    Route::post('/{user}/reject', [AdminUserController::class, 'reject'])
        ->name('reject');
    Route::delete('/{user}', [AdminUserController::class, 'destroy'])->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Admin: solicitudes de contacto
|--------------------------------------------------------------------------
| Visibilidad mínima de los leads capturados por POST /contact-submissions,
| para que no vivan sólo en la bandeja de correo de soporte.
*/
Route::middleware($authenticated)
    ->prefix('admin/contact-submissions')
    ->name('admin.contact-submissions.')
    ->group(function (): void {
        Route::get('/', [AdminContactSubmissionController::class, 'index'])
            ->middleware(RoleMiddleware::using([UserRole::Admin]))
            ->name('index');
    });

/*
|--------------------------------------------------------------------------
| Admin: CRUD de catálogos (skills, languages, degree_levels)
|--------------------------------------------------------------------------
| Protegido por el permiso Spatie `catalogs.manage` (rol admin). Complementa
| los endpoints públicos de lectura en /api/v1/catalogs/*.
*/
Route::middleware($authenticated)
    ->prefix('admin/catalogs')
    ->name('admin.catalogs.')
    ->group(function (): void {
        Route::apiResource('skills', AdminSkillController::class)
            ->except(['show']);
        Route::apiResource('languages', AdminLanguageController::class)
            ->except(['show']);
        Route::apiResource('degree-levels', AdminDegreeLevelController::class)
            ->except(['show'])
            ->parameters(['degree-levels' => 'degreeLevel']);
        Route::apiResource('functional-areas', AdminFunctionalAreaController::class)
            ->except(['show'])
            ->parameters(['functional-areas' => 'functionalArea']);
    });

/*
|--------------------------------------------------------------------------
| Webhooks (públicos, firmados por el proveedor)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->name('webhooks.stripe');
