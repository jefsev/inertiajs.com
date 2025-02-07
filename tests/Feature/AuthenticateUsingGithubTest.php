<?php

namespace Tests\Feature;

use App\Events\GithubCredentialsUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redirect;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Manager\OAuth2\User as OAuthUser;
use Tests\TestCase;

class AuthenticateUsingGithubTest extends TestCase
{
    use RefreshDatabase;

    protected function mockSocialiteResponse(): void
    {
        Socialite::shouldReceive('driver->setScopes->redirect')->andReturn(Redirect::to('/auth/github/callback?code=123&state=456'));
        Socialite::shouldReceive('driver->user')->andReturn((new OAuthUser())
            ->setToken('gho_INVALIDxq3Ly5ca88vy9aUKjLIXdqr')
            ->setRaw([
                'login' => 'claudiodekker',
                'id' => 1752195,
                'node_id' => '5ca88vy9aUKjLIIXdqr=',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/1752195?v=4',
                'gravatar_id' => '',
                'url' => 'https://api.github.com/users/claudiodekker',
                'html_url' => 'https://github.com/claudiodekker',
                'followers_url' => 'https://api.github.com/users/claudiodekker/followers',
                'following_url' => 'https://api.github.com/users/claudiodekker/following{/other_user}',
                'gists_url' => 'https://api.github.com/users/claudiodekker/gists{/gist_id}',
                'starred_url' => 'https://api.github.com/users/claudiodekker/starred{/owner}{/repo}',
                'subscriptions_url' => 'https://api.github.com/users/claudiodekker/subscriptions',
                'organizations_url' => 'https://api.github.com/users/claudiodekker/orgs',
                'repos_url' => 'https://api.github.com/users/claudiodekker/repos',
                'events_url' => 'https://api.github.com/users/claudiodekker/events{/privacy}',
                'received_events_url' => 'https://api.github.com/users/claudiodekker/received_events',
                'type' => 'User',
                'site_admin' => false,
                'name' => 'Claudio Dekker',
                'company' => null,
                'blog' => 'https://dekker.io',
                'location' => 'Amsterdam, The Netherlands',
                'email' => null,
                'hireable' => null,
                'bio' => 'Artisan @laravel ❯ Maintainer @inertiajs  ❯ Open-source enthusiast ❯ VILT is my stack.',
                'twitter_username' => 'claudiodekker',
                'public_repos' => 27,
                'public_gists' => 5,
                'followers' => 144,
                'following' => 12,
                'created_at' => '2012-05-18T12:06:13Z',
                'updated_at' => '2021-06-05T18:34:12Z',
            ])
            ->map([
                'id' => 1752195,
                'nickname' => 'claudiodekker',
                'name' => 'Claudio Dekker',
                'avatar' => 'https://avatars.githubusercontent.com/u/1752195?v=4',
            ]));
    }

    /** @test */
    public function guests_are_redirected_to_github_in_order_to_authorize(): void
    {
        $this->mockSocialiteResponse();

        $this->get('/auth/github')->assertRedirect('/auth/github/callback?code=123&state=456');
    }

    /** @test */
    public function guests_can_create_an_account_by_authenticating_through_github(): void
    {
        Event::fake(GithubCredentialsUpdated::class);
        $this->mockSocialiteResponse();
        $this->assertEquals(0, User::count());

        $this->get('/auth/github/callback?code=123&state=456')->assertRedirect('/');

        $user = User::where('github_api_id', 1752195)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertEquals('claudiodekker', $user->github_api_login);
        $this->assertEquals('gho_INVALIDxq3Ly5ca88vy9aUKjLIXdqr', $user->github_api_access_token);
        Event::assertDispatched(GithubCredentialsUpdated::class, fn ($event) => $event->user->is($user));
    }

    /** @test */
    public function guests_can_sign_in_to_their_account_by_authenticating_through_github(): void
    {
        Event::fake(GithubCredentialsUpdated::class);
        $this->mockSocialiteResponse();
        $user = User::factory()->withGithub()->create();

        $this->get('/auth/github/callback?code=123&state=456')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        Event::assertNotDispatched(GithubCredentialsUpdated::class);
    }

    /** @test */
    public function outdated_github_credentials_are_automatically_updated_during_sign_in(): void
    {
        Event::fake(GithubCredentialsUpdated::class);
        $this->mockSocialiteResponse();
        $user = User::factory()->withGithub()->create([
            'github_api_login' => 'old-login',
            'github_api_access_token' => 'old-token',
        ]);

        $this->get('/auth/github/callback?code=123&state=456')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        tap($user->fresh(), function (User $user) {
            $this->assertSame('claudiodekker', $user->github_api_login);
            $this->assertSame('gho_INVALIDxq3Ly5ca88vy9aUKjLIXdqr', $user->github_api_access_token);
        });
        Event::assertDispatched(GithubCredentialsUpdated::class, fn ($event) => $event->user->is($user));
    }

    /** @test */
    public function guests_fail_to_sign_in_when_github_authorization_was_rejected(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(InvalidStateException::class);

        $this->get('/auth/github/callback?code=123&state=456')->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /** @test */
    public function it_redirects_the_user_back_to_the_original_page_when_they_were_redirected_for_authentication(): void
    {
        Event::fake();
        $this->mockSocialiteResponse();
        $user = User::factory()->withGithub()->create();

        $this->get('/connections/discord/authorize')->assertRedirect('/auth/github');
        $this->get('/auth/github')->assertRedirect('/auth/github/callback?code=123&state=456');
        $this->assertGuest();
        $this->get('/auth/github/callback?code=123&state=456')->assertRedirect('/connections/discord/authorize');
        $this->assertAuthenticatedAs($user);
    }
}
