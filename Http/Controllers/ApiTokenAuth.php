<?php

namespace Modules\Everymarket\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Shared token check for the Everymarket JSON API controllers.
 *
 * Auth: header X-Everymarket-Api-Token, Authorization: Bearer <token>, or ?api_token=
 */
trait ApiTokenAuth
{
    protected function isAuthorized(Request $request): bool
    {
        $token = (string) config('everymarket.stats_api_token', '');
        if ($token === '') {
            return false;
        }

        $provided = $request->header('X-Everymarket-Api-Token')
            ?? $request->query('api_token');

        if (!$provided) {
            $authHeader = (string) $request->header('Authorization', '');
            if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
                $provided = $matches[1];
            }
        }

        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals($token, (string) $provided);
    }

    protected function unauthorizedResponse()
    {
        return response()->json([
            'status' => 'error',
            'msg'    => __('Unauthorized'),
        ], 401);
    }
}
