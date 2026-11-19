<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    Config::set('services.microsoft.client_id', 'fake-client-id');
    Config::set('services.microsoft.client_secret', 'fake-client-secret');
    Config::set('services.microsoft.redirect', 'http://localhost/auth/microsoft/callback');
});

/** Build a fake SocialiteUser that satisfies the contract. */
function fakeSocialiteUser(string $email, string $name = 'Test User', string $id = 'ms-user-1'): SocialiteUser
{
    return new class($email, $name, $id) implements SocialiteUser
    {
        public function __construct(
            private string $email,
            private string $name,
            private string $id,
        ) {}

        public function getId()
        {
            return $this->id;
        }

        public function getNickname()
        {
            return null;
        }

        public function getName()
        {
            return $this->name;
        }

        public function getEmail()
        {
            return $this->email;
        }

        public function getAvatar()
        {
            return null;
        }
    };
}

it('rejects login from an email outside the allowed domain', function () {
    Config::set('services.microsoft.allowed_domain', 'tanseeqinvestment.com');

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser('attacker@evil.com'));

    $response = $this->get('/auth/microsoft/callback');

    $response->assertRedirect('/');
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('restricted');

    // Critically: no user was created.
    expect(User::where('email', 'attacker@evil.com')->exists())->toBeFalse();
    expect(auth()->check())->toBeFalse();
});

it('allows login from the allowed domain and creates the user', function () {
    Config::set('services.microsoft.allowed_domain', 'tanseeqinvestment.com');

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser('newhire@tanseeqinvestment.com', 'New Hire'));

    $response = $this->get('/auth/microsoft/callback');

    $response->assertRedirect(route('dashboard'));
    expect(User::where('email', 'newhire@tanseeqinvestment.com')->exists())->toBeTrue();
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->name)->toBe('New Hire');
});

it('signs in an existing user without creating a duplicate', function () {
    Config::set('services.microsoft.allowed_domain', 'tanseeqinvestment.com');

    $existing = User::create([
        'name'     => 'Existing User',
        'email'    => 'existing@tanseeqinvestment.com',
        'password' => bcrypt('irrelevant'),
    ]);

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser('existing@tanseeqinvestment.com', 'Existing User', 'ms-id-99'));

    $this->get('/auth/microsoft/callback');

    expect(User::where('email', 'existing@tanseeqinvestment.com')->count())->toBe(1);
    expect(auth()->id())->toBe($existing->id);
    expect($existing->fresh()->microsoft_id)->toBe('ms-id-99', 'should backfill microsoft_id');
});

it('regenerates the session ID after successful login (prevents session fixation)', function () {
    Config::set('services.microsoft.allowed_domain', 'tanseeqinvestment.com');

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser('user@tanseeqinvestment.com'));

    $this->startSession();
    $oldSessionId = session()->getId();

    $this->get('/auth/microsoft/callback');

    expect(session()->getId())->not->toBe($oldSessionId, 'session ID must be regenerated on login');
});

it('handles emails matching domain case-insensitively', function () {
    Config::set('services.microsoft.allowed_domain', 'tanseeqinvestment.com');

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser('User@Tanseeqinvestment.COM'));

    $this->get('/auth/microsoft/callback');

    expect(User::where('email', 'user@tanseeqinvestment.com')->exists())->toBeTrue();
    expect(auth()->check())->toBeTrue();
});

it('allows any domain when MICROSOFT_ALLOWED_DOMAIN is empty', function () {
    Config::set('services.microsoft.allowed_domain', null);

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser('anyone@anywhere.com'));

    $this->get('/auth/microsoft/callback');

    expect(auth()->check())->toBeTrue();
});

it('rejects login when Socialite returns no email', function () {
    Config::set('services.microsoft.allowed_domain', 'tanseeqinvestment.com');

    Socialite::shouldReceive('driver->redirectUrl->user')
        ->andReturn(fakeSocialiteUser(''));

    $response = $this->get('/auth/microsoft/callback');

    $response->assertRedirect('/');
    expect(auth()->check())->toBeFalse();
});

it('attaches throttle middleware to the OAuth redirect and callback routes', function () {
    $redirect = Route::getRoutes()->getByName('auth.microsoft');
    $callback = Route::getRoutes()->getByName('auth.microsoft.callback');

    expect($redirect)->not->toBeNull('auth.microsoft route must exist');
    expect($callback)->not->toBeNull('auth.microsoft.callback route must exist');

    expect(collect($redirect->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle:')))
        ->toBeTrue('auth.microsoft must be throttled');

    expect(collect($callback->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle:')))
        ->toBeTrue('auth.microsoft.callback must be throttled');
});
