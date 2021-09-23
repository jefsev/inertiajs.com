<?php

namespace App\Jobs;

use App\Events\UserStartedSponsoring;
use App\Events\UserStoppedSponsoring;
use App\Models\Sponsor;
use App\Models\User;
use App\Services\Github\Exceptions\BadCredentialsException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SynchronizeSponsorStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var User
     */
    public $user;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $isGithubSponsor = $this->isGithubSponsor();
        $sponsor = Sponsor::firstOrNew(['github_api_id' => $this->user->github_api_id]);

        if ($isGithubSponsor && (! $sponsor->exists || $sponsor->has_expired)) {
            $sponsor->expires_at = null;
            $sponsor->save();

            $this->user->sponsor_id = $sponsor->id;
            $this->user->save();

            UserStartedSponsoring::dispatch($this->user);
        } elseif ($sponsor->exists && ! $isGithubSponsor) {
            $sponsor->expires_at = now();
            $sponsor->save();

            UserStoppedSponsoring::dispatch($this->user);
        }
    }

    protected function isGithubSponsor(): bool
    {
        try {
            return $this->user->isGithubSponsor();
        } catch (BadCredentialsException $exception) {
            return false;
        }
    }
}
