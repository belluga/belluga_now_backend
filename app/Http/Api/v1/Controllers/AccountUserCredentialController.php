<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\CredentialLinkRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

class AccountUserCredentialController extends Controller
{
    public function store(CredentialLinkRequest , string ): JsonResponse
    {
         = AccountUser::where('_id', new ObjectId())->firstOrFail();

         = ->validated();
         = ['provider'];
         = ['subject'];
         = ['metadata'] ?? [];

         = AccountUser::where('_id', '!=', new ObjectId())
            ->where('credentials', 'elemMatch', [
                'provider' => ,
                'subject' => ,
            ])->exists();

        if () {
            throw ValidationException::withMessages([
                'subject' => ['This credential is already linked to another identity.'],
            ]);
        }

         = null;
        if ( === 'password') {
            if (['secret'] ?? null) {
                 = Hash::make(['secret']);
                ->password = ;
            } elseif (->password) {
                 = ->password;
            } else {
                throw ValidationException::withMessages([
                    'secret' => ['A password must be provided when linking the first password credential.'],
                ]);
            }

            ->ensureEmail();
        }

         = ->syncCredential(, , , );

        return response()->json([
            'data' => [
                'credentials' => ->credentials,
                'credential' => ,
            ],
        ], 201);
    }

    public function destroy(Request , string , string ): JsonResponse
    {
         = AccountUser::where('_id', new ObjectId())->firstOrFail();

         = collect(->credentials)->firstWhere(function (array ) use (): bool {
             = ['_id'] ?? ['id'] ?? null;
            return  === ;
        });

        if (! ) {
            abort(404, 'Credential not found.');
        }

        if ((['provider'] ?? null) === 'password' && ->identity_state === 'verified') {
             = collect(->credentials)->filter(static function (array ) use (): bool {
                 = ['_id'] ?? ['id'] ?? null;
                return (['provider'] ?? null) === 'password' &&  !== ;
            });

            if (->isEmpty()) {
                throw ValidationException::withMessages([
                    'credential_id' => ['Verified identities must keep at least one password credential linked.'],
                ]);
            }
        }

         = ->removeCredentialById();

        if (! ) {
            abort(404, 'Credential not found.');
        }

        return response()->json([
            'data' => [
                'credentials' => ->credentials,
            ],
        ]);
    }
}
