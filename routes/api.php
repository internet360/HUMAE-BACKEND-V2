<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Catalogs\DegreeLevelController as AdminDegreeLevelController;
use App\Http\Controllers\Api\V1\Admin\Catalogs\LanguageController as AdminLanguageController;
use App\Http\Controllers\Api\V1\Admin\Catalogs\SkillController as AdminSkillController;
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
use App\Http\Controllers\Api\V1\Shared\HealthController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Todas las rutas se sirven bajo el prefijo /api/v1/ definido en bootstrap/app.php.
|
*/

Route::get('/health', HealthController::class)->name('health');

/*
|--------------------------------------------------------------------------
| Catálogos maestros (lectura, auth requerida)
|--------------------------------------------------------------------------
| Los candidatos los consumen desde el editor de perfil (skills, languages,
| degree levels); los recruiters los usan para construir filtros de vacantes.
*/
Route::middleware('auth:sanctum')->prefix('catalogs')->name('catalogs.')->group(function (): void {
    Route::get('/skills', [CatalogController::class, 'skills'])->name('skills');
    Route::get('/languages', [CatalogController::class, 'languages'])->name('languages');
    Route::get('/degree-levels', [CatalogController::class, 'degreeLevels'])->name('degree-levels');
});

Route::prefix('auth')->name('auth.')->group(function (): void {
    // Público
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

    // Autenticado
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
Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function (): void {
    // Membresía
    Route::get('/membership', [MembershipController::class, 'show'])->name('membership.show');
    Route::post('/membership/checkout', [MembershipController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('membership.checkout');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');

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

    // Notificaciones (disponibles para cualquier usuario autenticado)
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.mark-read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.mark-all-read');
});

/*
|--------------------------------------------------------------------------
| Recruiter / Admin: Companies + Vacancies
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
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

    // Vacancies
    Route::get('/vacancies', [VacancyController::class, 'index'])->name('vacancies.index');
    Route::post('/vacancies', [VacancyController::class, 'store'])->name('vacancies.store');
    Route::get('/vacancies/{vacancy}', [VacancyController::class, 'show'])->name('vacancies.show');
    Route::patch('/vacancies/{vacancy}', [VacancyController::class, 'update'])->name('vacancies.update');
    Route::delete('/vacancies/{vacancy}', [VacancyController::class, 'destroy'])->name('vacancies.destroy');
    Route::post('/vacancies/{vacancy}/transition', [VacancyController::class, 'transition'])
        ->name('vacancies.transition');

    // Directorio de candidatos (recruiter/admin)
    Route::get('/directory/candidates', [DirectoryController::class, 'index'])
        ->name('directory.candidates.index');
    Route::get('/directory/candidates/{candidate}', [DirectoryController::class, 'show'])
        ->name('directory.candidates.show');
    Route::post('/directory/candidates/{candidate}/favorite', [DirectoryController::class, 'toggleFavorite'])
        ->name('directory.candidates.favorite');
    Route::get('/directory/candidates/{candidate}/cv.pdf', [DirectoryController::class, 'downloadCv'])
        ->middleware('throttle:30,1')
        ->name('directory.candidates.cv');

    // Pipeline: assignments
    Route::get('/vacancies/{vacancy}/assignments', [AssignmentController::class, 'index'])
        ->name('vacancies.assignments.index');
    Route::post('/vacancies/{vacancy}/assignments', [AssignmentController::class, 'store'])
        ->name('vacancies.assignments.store');
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update'])
        ->name('assignments.update');
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])
        ->name('assignments.destroy');
    Route::patch('/assignments/{assignment}/select-finalist', [AssignmentController::class, 'selectFinalist'])
        ->name('assignments.select-finalist');

    // Notas de asignación
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
Route::middleware('auth:sanctum')->prefix('me/company')->name('me.company.')->group(function (): void {
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
Route::middleware('auth:sanctum')->prefix('admin/reports')->name('admin.reports.')->group(function (): void {
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
Route::middleware('auth:sanctum')->prefix('admin/users')->name('admin.users.')->group(function (): void {
    Route::get('/', [AdminUserController::class, 'index'])->name('index');
    Route::post('/', [AdminUserController::class, 'store'])->name('store');
    Route::post('/{user}/resend-invitation', [AdminUserController::class, 'resendInvitation'])
        ->name('resend-invitation');
    Route::delete('/{user}', [AdminUserController::class, 'destroy'])->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Admin: CRUD de catálogos (skills, languages, degree_levels)
|--------------------------------------------------------------------------
| Protegido por el permiso Spatie `catalogs.manage` (rol admin). Complementa
| los endpoints públicos de lectura en /api/v1/catalogs/*.
*/
Route::middleware('auth:sanctum')
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
    });

/*
|--------------------------------------------------------------------------
| Webhooks (públicos, firmados por el proveedor)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->name('webhooks.stripe');
