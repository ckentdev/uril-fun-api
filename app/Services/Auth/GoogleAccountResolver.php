<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Account-linking policy for Google sign-in:
 *
 *  1. A user already linked to this Google ID signs in (profile image refreshed).
 *  2. A user with the same email but no Google link is linked ONLY IF Google
 *     reports the email as verified AND the local account's email is verified.
 *     Otherwise sign-in is refused (prevents pre-registration account takeover).
 *  3. No user at all: a new account is created, email marked verified
 *     (Google vouches for it), with no local password.
 */
class GoogleAccountResolver
{
    public const REASON_UNVERIFIED_GOOGLE_EMAIL = 'google_email_unverified';

    public const REASON_ACCOUNT_EXISTS = 'account_exists';

    public function resolve(SocialiteUser $google): User
    {
        $googleId = (string) $google->getId();
        $email = strtolower((string) $google->getEmail());
        $emailVerified = (bool) ($google->user['email_verified'] ?? $google->user['verified_email'] ?? false);

        if ($googleId === '' || $email === '') {
            throw new GoogleAuthException('missing_profile');
        }

        $linked = User::where('google_id', $googleId)->first();
        if ($linked) {
            $this->refreshAvatar($linked, $google);

            return $linked;
        }

        if (! $emailVerified) {
            throw new GoogleAuthException(self::REASON_UNVERIFIED_GOOGLE_EMAIL);
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            if (! $existing->hasVerifiedEmail()) {
                throw new GoogleAuthException(self::REASON_ACCOUNT_EXISTS);
            }

            $existing->forceFill(['google_id' => $googleId])->save();
            $this->refreshAvatar($existing, $google);

            return $existing;
        }

        $user = new User(['name' => $google->getName() ?: $email, 'email' => $email]);
        $user->forceFill([
            'google_id' => $googleId,
            'avatar_url' => $google->getAvatar(),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function refreshAvatar(User $user, SocialiteUser $google): void
    {
        if ($google->getAvatar() && $user->avatar_url !== $google->getAvatar()) {
            $user->forceFill(['avatar_url' => $google->getAvatar()])->save();
        }
    }
}
