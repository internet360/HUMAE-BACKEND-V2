<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssignmentStage;
use App\Enums\AttemptStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewMode;
use App\Enums\InterviewState;
use App\Enums\MembershipStatus;
use App\Enums\Priority;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Interview;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricTest;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Models\VacancyAssignmentNote;
use App\Notifications\AssignmentStageChangedNotification;
use App\Notifications\CandidateHiredNotification;
use App\Notifications\InterviewConfirmedNotification;
use App\Notifications\InterviewScheduledNotification;
use App\Notifications\MembershipActivatedNotification;
use App\Services\PsychometricScoringService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Rellena los datos RELACIONALES que PdfDemoSeeder y TestAccountsSeeder no
 * generan: VacancyAssignment (una por cada AssignmentStage), notas internas,
 * entrevistas en distintos estados/modos, un intento psicométrico Big Five
 * completo con resultado calculado, y notificaciones (leídas/no leídas) para
 * un candidato, el reclutador y el usuario de empresa.
 *
 * No crea usuarios ni vacantes nuevas: busca por los emails/códigos conocidos
 * que ya generaron PdfDemoSeeder y TestAccountsSeeder. Sí crea un
 * CompanyMember para vincular a company@test.humae (creado por
 * TestAccountsSeeder como owner de "acme-corp") con "humae-demo-corp" (la
 * empresa dueña de las vacantes demo de PdfDemoSeeder) — sin ese vínculo,
 * VacancyPolicy/InterviewPolicy no le dan acceso a nada de lo que se le
 * notifica, porque PdfDemoSeeder nunca crea miembros para su empresa demo.
 *
 * Nota de arquitectura: ARCHITECTURE.md §4.6 describe columnas
 * `candidate_confirmed_at` / `company_confirmed_at` en `interviews`, pero la
 * tabla real (ver migración create_interviews_tables) y el InterviewService
 * implementado no las tienen — la confirmación es un único `state` compartido
 * (propuesta → confirmada → realizada), sin flag independiente por candidato
 * y por empresa. La aproximación más fiel que el esquema real permite a una
 * "confirmación parcial" es una entrevista en `propuesta` con
 * `alternate_scheduled_at` poblado (ver migración
 * add_alternate_scheduled_at_to_interviews): la empresa ya propuso dos
 * horarios y es el candidato quien está pendiente de elegir uno. Es decir,
 * en este esquema la parte pendiente en una confirmación parcial es siempre
 * el candidato, nunca la empresa.
 *
 * Idempotente: usa firstOrCreate() sobre llaves naturales (o la restricción
 * UNIQUE existente en la tabla). Para notificaciones, que no tienen llave
 * natural, usa un conteo por destinatario como guarda de idempotencia. NO
 * correr en producción.
 */
class DemoRelationalSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('DemoRelationalSeeder: saltado en producción.');

            return;
        }

        $recruiter = User::where('email', 'recruiter@test.humae')->first();
        $companyUser = User::where('email', 'company@test.humae')->first();

        if ($recruiter === null || $companyUser === null) {
            $this->command->error('DemoRelationalSeeder: falta recruiter@test.humae o company@test.humae. Corre TestAccountsSeeder primero.');

            return;
        }

        $vacancies = $this->resolveVacancies();
        if ($vacancies === null) {
            return;
        }

        $demoCompany = Company::where('slug', 'humae-demo-corp')->first();
        if ($demoCompany === null) {
            $this->command->error("DemoRelationalSeeder: falta la empresa demo 'humae-demo-corp'. Corre PdfDemoSeeder primero.");

            return;
        }

        $this->ensureCompanyMembership($demoCompany, $companyUser);

        $candidates = $this->resolveCandidates();
        if ($candidates === null) {
            return;
        }

        $bigFive = PsychometricTest::where('code', 'big-five-25')->first();
        if ($bigFive === null) {
            $this->command->error('DemoRelationalSeeder: falta el test Big Five (código big-five-25). Corre PsychometricBigFiveSeeder primero.');

            return;
        }

        $bigFiveQuestions = $bigFive->questions()->with('options')->orderBy('id')->get();
        if ($bigFiveQuestions->isEmpty()) {
            $this->command->error('DemoRelationalSeeder: el test Big Five no tiene preguntas. Corre PsychometricBigFiveSeeder primero.');

            return;
        }

        $assignments = $this->buildAssignments($vacancies, $candidates, $recruiter);
        $notesCount = $this->buildNotes($assignments, $recruiter);
        $interviews = $this->buildInterviews($assignments, $recruiter);
        $this->syncVacancyStates($vacancies, $recruiter);
        $this->buildPsychometricAttempt($candidates['juan'], $bigFive, $bigFiveQuestions);
        $notificationsCount = $this->buildNotifications($candidates['juan'], $recruiter, $companyUser, $assignments, $interviews);

        $this->command->info('DemoRelationalSeeder: datos relacionales de demo generados.');
        $this->command->info('  - CompanyMember: company@test.humae vinculado como owner de "humae-demo-corp".');
        $this->command->info('  - VacancyAssignment: '.count($assignments).' (cubren las 7 stages de AssignmentStage; "presented" con 3 tarjetas).');
        $this->command->info('  - VacancyAssignmentNote: '.$notesCount.' notas internas.');
        $this->command->info('  - Interview: '.count($interviews).' (propuesta, confirmada y realizada; presencial/online/telefónica).');
        $this->command->info('  - PsychometricAttempt: 1 completado (Big Five) con PsychometricResult calculado para juan.empleado@demo.humae.');
        $this->command->info('  - Notifications: '.$notificationsCount.' (mezcla de leídas/no leídas) para candidato, reclutador y empresa.');
    }

    /**
     * @return array<string, Vacancy>|null
     */
    private function resolveVacancies(): ?array
    {
        $codes = [
            'ingenieria' => 'HUM-DEMO-0001',
            'sistemas' => 'HUM-DEMO-0002',
            'almacen' => 'HUM-DEMO-0003',
            'calidad' => 'HUM-DEMO-0004',
            'rh' => 'HUM-DEMO-0005',
        ];

        $vacancies = [];
        foreach ($codes as $key => $code) {
            $vacancy = Vacancy::where('code', $code)->first();
            if ($vacancy === null) {
                $this->command->error("DemoRelationalSeeder: falta la vacante demo '{$code}'. Corre PdfDemoSeeder primero.");

                return null;
            }
            $vacancies[$key] = $vacancy;
        }

        return $vacancies;
    }

    /**
     * Vincula a company@test.humae con "humae-demo-corp". PdfDemoSeeder crea
     * esa empresa y sus vacantes pero nunca un CompanyMember para ella, así
     * que sin esto VacancyPolicy/InterviewPolicy le niegan acceso a todo lo
     * relacionado con esas vacantes (company@test.humae solo es member de
     * "acme-corp" vía TestAccountsSeeder).
     */
    private function ensureCompanyMembership(Company $company, User $companyUser): void
    {
        CompanyMember::firstOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $companyUser->id,
            ],
            [
                'role' => CompanyMemberRole::Owner,
                'is_primary_contact' => true,
                'accepted_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, CandidateProfile>|null
     */
    private function resolveCandidates(): ?array
    {
        $emails = [
            'pablo' => 'pablo.intern@demo.humae',
            'maria' => 'maria.intern@demo.humae',
            'juan' => 'juan.empleado@demo.humae',
            'sofia' => 'sofia.empleado@demo.humae',
            'lucia' => 'lucia.empleado@demo.humae',
        ];

        $candidates = [];
        foreach ($emails as $key => $email) {
            $user = User::where('email', $email)->first();
            $profile = $user?->candidateProfile;
            if ($profile === null) {
                $this->command->error("DemoRelationalSeeder: falta el candidato demo '{$email}'. Corre PdfDemoSeeder primero.");

                return null;
            }
            $candidates[$key] = $profile;
        }

        return $candidates;
    }

    /**
     * @param  array<string, Vacancy>  $vacancies
     * @param  array<string, CandidateProfile>  $candidates
     * @return array<string, VacancyAssignment>
     */
    private function buildAssignments(array $vacancies, array $candidates, User $recruiter): array
    {
        /**
         * @var list<array{key: string, vacancy: string, candidate: string, stage: AssignmentStage, priority: Priority, score: int, extra: array<string, mixed>}>
         */
        $specs = [
            [
                'key' => 'sourced_pablo_ingenieria',
                'vacancy' => 'ingenieria',
                'candidate' => 'pablo',
                'stage' => AssignmentStage::Sourced,
                'priority' => Priority::Normal,
                'score' => 65,
                'extra' => [],
            ],
            [
                'key' => 'presented_juan_calidad',
                'vacancy' => 'calidad',
                'candidate' => 'juan',
                'stage' => AssignmentStage::Presented,
                'priority' => Priority::Normal,
                'score' => 78,
                'extra' => ['presented_at' => now()->subDays(6)],
            ],
            [
                'key' => 'presented_sofia_calidad',
                'vacancy' => 'calidad',
                'candidate' => 'sofia',
                'stage' => AssignmentStage::Presented,
                'priority' => Priority::Normal,
                'score' => 82,
                'extra' => ['presented_at' => now()->subDays(6)],
            ],
            [
                'key' => 'presented_lucia_calidad',
                'vacancy' => 'calidad',
                'candidate' => 'lucia',
                'stage' => AssignmentStage::Presented,
                'priority' => Priority::Normal,
                'score' => 74,
                'extra' => ['presented_at' => now()->subDays(6)],
            ],
            [
                'key' => 'interviewing_maria_sistemas',
                'vacancy' => 'sistemas',
                'candidate' => 'maria',
                'stage' => AssignmentStage::Interviewing,
                'priority' => Priority::Normal,
                'score' => 88,
                'extra' => ['presented_at' => now()->subDays(9)],
            ],
            [
                'key' => 'finalist_sofia_almacen',
                'vacancy' => 'almacen',
                'candidate' => 'sofia',
                'stage' => AssignmentStage::Finalist,
                'priority' => Priority::High,
                'score' => 91,
                'extra' => [
                    'presented_at' => now()->subDays(15),
                    'shortlisted_at' => now()->subDays(10),
                    'interviewed_at' => now()->subDays(5),
                ],
            ],
            [
                'key' => 'hired_lucia_rh',
                'vacancy' => 'rh',
                'candidate' => 'lucia',
                'stage' => AssignmentStage::Hired,
                'priority' => Priority::High,
                'score' => 95,
                'extra' => [
                    'presented_at' => now()->subDays(20),
                    'shortlisted_at' => now()->subDays(16),
                    'interviewed_at' => now()->subDays(10),
                    'offer_sent_at' => now()->subDays(3),
                    'hired_at' => now()->subDay(),
                ],
            ],
            [
                'key' => 'rejected_juan_almacen',
                'vacancy' => 'almacen',
                'candidate' => 'juan',
                'stage' => AssignmentStage::Rejected,
                'priority' => Priority::Normal,
                'score' => 40,
                'extra' => [
                    'presented_at' => now()->subDays(12),
                    'rejected_at' => now()->subDays(2),
                    'rejection_reason' => 'El perfil no cumple con la experiencia mínima requerida para el área de almacén.',
                ],
            ],
            [
                'key' => 'withdrawn_maria_ingenieria',
                'vacancy' => 'ingenieria',
                'candidate' => 'maria',
                'stage' => AssignmentStage::Withdrawn,
                'priority' => Priority::Normal,
                'score' => 60,
                'extra' => [
                    'presented_at' => now()->subDays(8),
                    'withdrawn_at' => now()->subDay(),
                ],
            ],
        ];

        $assignments = [];
        foreach ($specs as $spec) {
            $vacancy = $vacancies[$spec['vacancy']];
            $candidate = $candidates[$spec['candidate']];

            $assignment = VacancyAssignment::query()
                ->where('vacancy_id', $vacancy->id)
                ->where('candidate_profile_id', $candidate->id)
                ->first();

            if ($assignment === null) {
                $assignment = VacancyAssignment::factory()->create(array_merge(
                    [
                        'vacancy_id' => $vacancy->id,
                        'candidate_profile_id' => $candidate->id,
                        'assigned_by' => $recruiter->id,
                        'stage' => $spec['stage'],
                        'priority' => $spec['priority'],
                        'score' => $spec['score'],
                    ],
                    $spec['extra'],
                ));
            }

            $assignments[$spec['key']] = $assignment;
        }

        return $assignments;
    }

    /**
     * @param  array<string, VacancyAssignment>  $assignments
     */
    private function buildNotes(array $assignments, User $recruiter): int
    {
        $notes = [
            [
                'assignment' => 'presented_juan_calidad',
                'body' => 'Buen fit técnico para Coordinador de Calidad; agendar entrevista con el equipo de planta.',
            ],
            [
                'assignment' => 'finalist_sofia_almacen',
                'body' => 'Finalista con evaluación positiva del equipo de almacén; en espera de la decisión final de la empresa.',
            ],
        ];

        foreach ($notes as $note) {
            $assignment = $assignments[$note['assignment']];

            $exists = VacancyAssignmentNote::query()
                ->where('vacancy_assignment_id', $assignment->id)
                ->where('body', $note['body'])
                ->exists();

            if (! $exists) {
                VacancyAssignmentNote::factory()->create([
                    'vacancy_assignment_id' => $assignment->id,
                    'author_id' => $recruiter->id,
                    'visibility' => 'internal',
                    'body' => $note['body'],
                ]);
            }
        }

        return count($notes);
    }

    /**
     * @param  array<string, VacancyAssignment>  $assignments
     * @return array<string, Interview>
     */
    private function buildInterviews(array $assignments, User $recruiter): array
    {
        /**
         * @var list<array{key: string, assignment: string, round: int, overrides: array<string, mixed>}>
         */
        $specs = [
            [
                'key' => 'propuesta_calidad_juan',
                'assignment' => 'presented_juan_calidad',
                'round' => 1,
                'overrides' => [
                    'scheduled_by' => $recruiter->id,
                    'state' => InterviewState::Propuesta,
                    'mode' => InterviewMode::Presencial,
                    'scheduled_at' => now()->addDays(4),
                    'location' => 'Oficinas HUMAE Demo, Ciudad de México',
                ],
            ],
            [
                // Confirmación parcial: la empresa ya propuso dos horarios
                // (alternate_scheduled_at) y es el candidato quien está
                // pendiente de elegir uno. Ver nota de arquitectura en el
                // docblock de la clase.
                'key' => 'propuesta_calidad_sofia_dual_slot',
                'assignment' => 'presented_sofia_calidad',
                'round' => 1,
                'overrides' => [
                    'scheduled_by' => $recruiter->id,
                    'state' => InterviewState::Propuesta,
                    'mode' => InterviewMode::Telefonica,
                    'scheduled_at' => now()->addDays(6),
                    'alternate_scheduled_at' => now()->addDays(7),
                ],
            ],
            [
                'key' => 'confirmada_sistemas_maria',
                'assignment' => 'interviewing_maria_sistemas',
                'round' => 1,
                'overrides' => [
                    'scheduled_by' => $recruiter->id,
                    'state' => InterviewState::Confirmada,
                    'mode' => InterviewMode::Online,
                    'scheduled_at' => now()->addDays(2),
                    'meeting_url' => 'https://meet.google.com/demo-humae-sistemas',
                    'meeting_provider' => 'google_meet',
                ],
            ],
            [
                'key' => 'realizada_almacen_sofia',
                'assignment' => 'finalist_sofia_almacen',
                'round' => 1,
                'overrides' => [
                    'scheduled_by' => $recruiter->id,
                    'state' => InterviewState::Realizada,
                    'mode' => InterviewMode::Presencial,
                    'scheduled_at' => now()->subDays(5),
                    'location' => 'Bodega Central, Almacén demo',
                    'started_at' => now()->subDays(5),
                    'ended_at' => now()->subDays(5)->addMinutes(50),
                    'rating' => 8,
                    'recruiter_feedback' => 'Buen manejo de procesos logísticos y actitud proactiva durante la entrevista.',
                    'recommendation' => 'advance',
                ],
            ],
            [
                'key' => 'realizada_rh_lucia',
                'assignment' => 'hired_lucia_rh',
                'round' => 1,
                'overrides' => [
                    'scheduled_by' => $recruiter->id,
                    'state' => InterviewState::Realizada,
                    'mode' => InterviewMode::Online,
                    'scheduled_at' => now()->subDays(10),
                    'meeting_url' => 'https://meet.google.com/demo-humae-rh',
                    'meeting_provider' => 'google_meet',
                    'started_at' => now()->subDays(10),
                    'ended_at' => now()->subDays(10)->addMinutes(45),
                    'rating' => 9,
                    'recruiter_feedback' => 'Excelente entrevista; el equipo de RH confirma la contratación.',
                    'recommendation' => 'advance',
                ],
            ],
        ];

        $interviews = [];
        foreach ($specs as $spec) {
            $assignment = $assignments[$spec['assignment']];

            $interview = Interview::query()
                ->where('vacancy_assignment_id', $assignment->id)
                ->where('round', $spec['round'])
                ->first();

            if ($interview === null) {
                $interview = Interview::factory()->create(array_merge(
                    [
                        'vacancy_assignment_id' => $assignment->id,
                        'round' => $spec['round'],
                    ],
                    $spec['overrides'],
                ));
            }

            $interviews[$spec['key']] = $interview;
        }

        return $interviews;
    }

    /**
     * @param  array<string, Vacancy>  $vacancies
     */
    private function syncVacancyStates(array $vacancies, User $recruiter): void
    {
        // Evita el bug que reparó la migración
        // fix_vacancies_stuck_at_activa_with_assignments: una vacante con
        // asignaciones/entrevistas ya no puede quedarse en "activa".
        $targetStates = [
            'ingenieria' => VacancyState::ConCandidatosAsignados,
            'sistemas' => VacancyState::EntrevistasEnCurso,
            'almacen' => VacancyState::FinalistaSeleccionado,
            'calidad' => VacancyState::EntrevistasEnCurso,
            'rh' => VacancyState::Cubierta,
        ];

        foreach ($targetStates as $key => $state) {
            $vacancy = $vacancies[$key];

            $updates = [
                'state' => $state,
                'assigned_recruiter_id' => $recruiter->id,
            ];

            if ($state === VacancyState::Cubierta) {
                $updates['filled_at'] = now()->subDay();
            }

            $vacancy->forceFill($updates)->save();
        }
    }

    /**
     * @param  Collection<int, PsychometricQuestion>  $questions
     */
    private function buildPsychometricAttempt(
        CandidateProfile $candidate,
        PsychometricTest $test,
        Collection $questions,
    ): PsychometricAttempt {
        $attempt = PsychometricAttempt::query()
            ->where('candidate_profile_id', $candidate->id)
            ->where('psychometric_test_id', $test->id)
            ->where('status', AttemptStatus::Completed->value)
            ->first();

        if ($attempt === null) {
            $attempt = PsychometricAttempt::factory()->create([
                'candidate_profile_id' => $candidate->id,
                'psychometric_test_id' => $test->id,
                'status' => AttemptStatus::Completed,
                'started_at' => now()->subDays(3),
                'submitted_at' => now()->subDays(3)->addMinutes(12),
                'duration_seconds' => 720,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'DemoRelationalSeeder/1.0',
            ]);
        }

        // Niveles objetivo (escala 1-5) por dimensión: describen un perfil
        // creíble (extrovertido, responsable, emocionalmente estable) en vez
        // de puntajes planos o en cero. Los ítems invertidos (is_reverse_scored)
        // se resuelven a la respuesta cruda que produce ese nivel tras el
        // ajuste que aplica PsychometricScoringService::reverseScore().
        /** @var array<string, list<int>> $dimensionLevels */
        $dimensionLevels = [
            'extraversion' => [4, 5, 4, 3, 5],
            'amabilidad' => [4, 4, 5, 3, 4],
            'responsabilidad' => [5, 5, 4, 5, 4],
            'neuroticismo' => [2, 3, 2, 2, 1],
            'apertura' => [4, 3, 4, 3, 5],
        ];

        $byDimension = $questions->groupBy(fn (PsychometricQuestion $question): string => $question->dimension ?? 'general');

        foreach ($byDimension as $dimension => $dimensionQuestions) {
            $dimensionKey = (string) $dimension;
            $levels = $dimensionLevels[$dimensionKey] ?? [3, 3, 3, 3, 3];

            foreach ($dimensionQuestions->values() as $index => $question) {
                $target = $levels[$index] ?? 3;
                $raw = $question->is_reverse_scored ? (6 - $target) : $target;
                $raw = max(1, min(5, $raw));

                $option = $question->options->firstWhere('score', $raw) ?? $question->options->first();

                $answerExists = PsychometricAnswer::query()
                    ->where('psychometric_attempt_id', $attempt->id)
                    ->where('psychometric_question_id', $question->id)
                    ->exists();

                if (! $answerExists) {
                    PsychometricAnswer::factory()->create([
                        'psychometric_attempt_id' => $attempt->id,
                        'psychometric_question_id' => $question->id,
                        'psychometric_question_option_id' => $option?->id,
                        'value' => (string) $raw,
                        'score' => null,
                        'time_spent_seconds' => 12,
                    ]);
                }
            }
        }

        // Idempotente por diseño: PsychometricScoringService::score() retorna
        // el PsychometricResult existente sin recalcular si ya hay uno.
        app(PsychometricScoringService::class)->score($attempt->fresh() ?? $attempt);

        return $attempt;
    }

    /**
     * @param  array<string, VacancyAssignment>  $assignments
     * @param  array<string, Interview>  $interviews
     */
    private function buildNotifications(
        CandidateProfile $candidate,
        User $recruiter,
        User $companyUser,
        array $assignments,
        array $interviews,
    ): int {
        $candidateUser = $candidate->user;
        $created = 0;

        if ($candidateUser !== null && $candidateUser->notifications()->count() === 0) {
            $this->storeNotification(
                $candidateUser,
                new AssignmentStageChangedNotification($assignments['presented_juan_calidad'], AssignmentStage::Presented),
                null,
            );
            $created++;

            $membership = $candidateUser->memberships()
                ->where('status', MembershipStatus::Active->value)
                ->first();

            if ($membership !== null) {
                $this->storeNotification(
                    $candidateUser,
                    new MembershipActivatedNotification($membership),
                    now()->subDays(3),
                );
                $created++;
            }
        }

        if ($recruiter->notifications()->count() === 0) {
            $this->storeNotification(
                $recruiter,
                new InterviewConfirmedNotification($interviews['confirmada_sistemas_maria']),
                null,
            );
            $created++;

            $this->storeNotification(
                $recruiter,
                new CandidateHiredNotification($assignments['hired_lucia_rh']),
                now()->subHours(5),
            );
            $created++;
        }

        if ($companyUser->notifications()->count() === 0) {
            $this->storeNotification(
                $companyUser,
                new InterviewScheduledNotification($interviews['propuesta_calidad_juan']),
                null,
            );
            $created++;

            $this->storeNotification(
                $companyUser,
                new CandidateHiredNotification($assignments['hired_lucia_rh']),
                now()->subHour(),
            );
            $created++;
        }

        return $created;
    }

    private function storeNotification(
        User $notifiable,
        AssignmentStageChangedNotification|CandidateHiredNotification|InterviewConfirmedNotification|InterviewScheduledNotification|MembershipActivatedNotification $notification,
        ?Carbon $readAt,
    ): void {
        $notifiable->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'data' => $notification->toArray($notifiable),
            'read_at' => $readAt,
        ]);
    }
}
