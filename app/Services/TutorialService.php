<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TutorialChannel;
use App\Enums\TutorialStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserTutorialState;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Resolves the one-time welcome tutorial each role sees on its own home
 * route. `should_show` is computed here and only here: the frontend never
 * learns the versioning rule, so a future policy (e.g. "re-show after 90
 * days") changes this class and nothing else.
 */
class TutorialService
{
    /** Maps the Spatie role that owns each home tutorial. */
    private const array ROLE_KEYS = [
        UserRole::Candidate->value => 'candidate_home',
        UserRole::Recruiter->value => 'recruiter_home',
        UserRole::CompanyUser->value => 'company_home',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function statusForUser(User $user): array
    {
        $keys = $this->applicableKeysForUser($user);

        $states = UserTutorialState::query()
            ->where('user_id', $user->id)
            ->whereIn('tutorial_key', $keys)
            ->get()
            ->keyBy('tutorial_key');

        return array_map(
            fn (string $key): array => $this->present($key, $states->get($key)),
            $keys,
        );
    }

    /**
     * Marks a tutorial completed. Idempotent: calling it again (even with a
     * different channel) just overwrites the row with the latest choice.
     */
    public function complete(User $user, string $key, TutorialChannel $channel): UserTutorialState
    {
        $config = $this->assertApplicable($user, $key);

        return UserTutorialState::query()->updateOrCreate(
            ['user_id' => $user->id, 'tutorial_key' => $key],
            [
                'status' => TutorialStatus::Completed,
                'version' => $config['version'],
                'channel' => $channel,
                'completed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Marks a tutorial skipped. Idempotent, same as {@see self::complete()}.
     */
    public function skip(User $user, string $key): UserTutorialState
    {
        $config = $this->assertApplicable($user, $key);

        return UserTutorialState::query()->updateOrCreate(
            ['user_id' => $user->id, 'tutorial_key' => $key],
            [
                'status' => TutorialStatus::Skipped,
                'version' => $config['version'],
                'channel' => null,
                'completed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Shapes one row for the API response, whether or not the user has ever
     * interacted with this tutorial.
     *
     * @return array<string, mixed>
     */
    public function present(string $key, ?UserTutorialState $state): array
    {
        /** @var array{version: int, video: string|null} $config */
        $config = config("tutorials.{$key}");

        return [
            'tutorial_key' => $key,
            'version' => $config['version'],
            'should_show' => $state === null || $state->version < $config['version'],
            'status' => $state?->status?->value,
            'channel' => $state?->channel?->value,
            'completed_at' => $state?->completed_at?->toIso8601String(),
            'video_url' => $config['video'],
        ];
    }

    /**
     * @return list<string>
     */
    private function applicableKeysForUser(User $user): array
    {
        $config = config('tutorials', []);
        $keys = [];

        foreach (self::ROLE_KEYS as $role => $key) {
            if ($user->hasRole($role) && array_key_exists($key, $config)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Guards against a caller-supplied key that does not exist or does not
     * belong to the caller's own role: both are a 404, never a 500.
     *
     * @return array{version: int, video: string|null}
     */
    private function assertApplicable(User $user, string $key): array
    {
        $config = config('tutorials', []);

        if (! in_array($key, $this->applicableKeysForUser($user), true) || ! isset($config[$key])) {
            throw (new ModelNotFoundException)->setModel(UserTutorialState::class, [$key]);
        }

        /** @var array{version: int, video: string|null} */
        return $config[$key];
    }
}
