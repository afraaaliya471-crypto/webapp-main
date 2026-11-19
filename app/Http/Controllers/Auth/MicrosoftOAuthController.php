<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Handles the Microsoft Entra (OAuth 2.0) sign-in flow.
 *
 * Security notes:
 *   - Auth routes are rate-limited (15 req/min/IP) at the route level.
 *   - Email domain is restricted via MICROSOFT_ALLOWED_DOMAIN.
 *   - Session ID is regenerated after login to prevent session fixation.
 *   - Error messages never leak exception details to the user.
 */
class MicrosoftOAuthController extends Controller
{
    /** Generic message shown when the OAuth flow cannot start (config issue, network, etc.). */
    private const ERROR_OAUTH_UNAVAILABLE = 'Authentication is not available right now. Please contact the administrator.';

    /** Generic message shown when the OAuth callback fails for any reason. */
    private const ERROR_OAUTH_FAILED = 'Authentication failed. Please try again or contact support.';

    /** Shown when the user's email is outside the allowed domain. */
    private const ERROR_DOMAIN_RESTRICTED = 'Access is restricted to company accounts only.';

    /** Begin the OAuth dance — redirect the user to Microsoft. */
    public function redirectToMicrosoft(): SymfonyRedirectResponse|RedirectResponse
    {
        try {
            if (! $this->oauthConfigured()) {
                $this->logMissingConfig();

                return redirect('/')->with('error', self::ERROR_OAUTH_UNAVAILABLE);
            }

            return Socialite::driver('microsoft')
                ->redirectUrl($this->redirectUri())
                ->redirect();
        } catch (\Throwable $e) {
            Log::error('Microsoft OAuth redirect failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return redirect('/')->with('error', self::ERROR_OAUTH_UNAVAILABLE);
        }
    }

    /** Handle the redirect back from Microsoft, log the user in, redirect to the dashboard. */
    public function handleMicrosoftCallback(Request $request): RedirectResponse
    {
        try {
            $microsoftUser = Socialite::driver('microsoft')
                ->redirectUrl($this->redirectUri())
                ->user();

            $email = strtolower(trim((string) ($microsoftUser->getEmail() ?? '')));

            if ($email === '') {
                Log::warning('Microsoft OAuth: no email returned from provider');

                return redirect('/')->with('error', self::ERROR_OAUTH_FAILED);
            }

            if (! $this->emailDomainAllowed($email)) {
                Log::warning('Microsoft OAuth: unauthorised domain login attempt', [
                    'email_domain' => $this->extractDomain($email),
                ]);

                return redirect('/')->with('error', self::ERROR_DOMAIN_RESTRICTED);
            }

            $user = $this->findOrCreateUser($microsoftUser, $email);

            // Regenerate the session ID after successful login to prevent session fixation.
            // This is critical: without it, a malicious link could pre-set a session ID
            // and hijack the authenticated session after the victim signs in.
            $request->session()->regenerate();

            Auth::login($user, remember: false);

            return redirect()->intended(route('dashboard'));
        } catch (\Throwable $e) {
            Log::error('Microsoft OAuth callback failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'has_code' => $request->has('code'),
                'has_state' => $request->has('state'),
                'oauth_error' => $request->get('error'),
                'error_desc' => $request->get('error_description'),
            ]);

            return redirect('/')->with('error', self::ERROR_OAUTH_FAILED);
        }
    }

    /** Sign the user out and invalidate their session. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /** Single source of truth for the configured OAuth redirect URI. */
    private function redirectUri(): string
    {
        return (string) config('services.microsoft.redirect');
    }

    /** Whether the Azure credentials are present in config. */
    private function oauthConfigured(): bool
    {
        return ! empty(config('services.microsoft.client_id'))
            && ! empty(config('services.microsoft.client_secret'));
    }

    /** Whether the email passes the optional domain allow-list. */
    private function emailDomainAllowed(string $email): bool
    {
        $allowedDomain = config('services.microsoft.allowed_domain');

        if (empty($allowedDomain)) {
            return true;
        }

        return str_ends_with($email, '@'.strtolower((string) $allowedDomain));
    }

    /** Extract the domain part of an email address for safe logging. */
    private function extractDomain(string $email): string
    {
        $atPos = strrpos($email, '@');

        return $atPos !== false ? substr($email, $atPos + 1) : '';
    }

    /** Find an existing user by email or create one from the OAuth profile. */
    private function findOrCreateUser(SocialiteUser $microsoftUser, string $email): User
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return User::create([
                'name' => $microsoftUser->getName() ?? $microsoftUser->getNickname() ?? $email,
                'email' => $email,
                'microsoft_id' => $microsoftUser->getId(),
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        // Backfill the Microsoft ID on existing users from the legacy password flow.
        if (empty($user->microsoft_id)) {
            $user->microsoft_id = $microsoftUser->getId();
            $user->save();
        }

        return $user;
    }

    /** Diagnostic log when OAuth credentials are missing. Never logs the values themselves. */
    private function logMissingConfig(): void
    {
        Log::error('Microsoft OAuth configuration missing', [
            'client_id_set' => ! empty(config('services.microsoft.client_id')),
            'client_secret_set' => ! empty(config('services.microsoft.client_secret')),
            'redirect_uri_set' => ! empty(config('services.microsoft.redirect')),
        ]);
    }
}
