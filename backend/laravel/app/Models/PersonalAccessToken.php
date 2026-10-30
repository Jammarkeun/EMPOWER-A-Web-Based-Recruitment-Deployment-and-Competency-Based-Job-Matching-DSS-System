<?php

namespace App\Models;

use App\Services\SessionLifetime;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, with one change: a background request does not refresh it.
 *
 * Sanctum stamps `last_used_at` on every request the token makes, and the idle
 * limit is measured from that stamp. The unread-count poll runs every minute
 * whether anybody is at the desk or not, so it marks itself as background and
 * this keeps the stamp where the person last left it. Everything else about
 * the request - authentication, permissions, the answer - is unchanged.
 *
 * A client can only use this to be signed out sooner, never later, so there is
 * nothing to gain by sending the header on purpose.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected static function booted(): void
    {
        static::updating(function (self $token) {
            $onlyActivityStamp = array_keys($token->getDirty()) === ['last_used_at'];

            if ($onlyActivityStamp && app(SessionLifetime::class)->isBackground(request())) {
                return false;
            }
        });
    }
}
