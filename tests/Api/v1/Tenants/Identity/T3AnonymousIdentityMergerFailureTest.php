<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Identity;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use Illuminate\Support\Facades\DB;
use Tests\Api\v1\Tenants\Identity\Contracts\ApiV1AnonymousIdentityMergerTestContract;
use Tests\Helpers\TenantLabels;

class T3AnonymousIdentityMergerFailureTest extends ApiV1AnonymousIdentityMergerTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    public function testMergeFailsWhenAnonymousUserDoesNotExist(): void
    {
        $target = $this->createCanonicalUser();
        $source = $this->createAnonymousSource();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('One or more anonymous identities could not be found.');

        $this->app[\App\Http\Api\v1\Controllers\PasswordRegistrationController::class]->__invoke(
            new \App\Http\Api\v1\Requests\PasswordRegistrationRequest([
                'name' => $target->name,
                'email' => $target->emails[0],
                'password' => 'password',
                'anonymous_user_ids' => [$source->_id, '60c6e5e5d3f2a3e5c9b7e3a2'],
            ]),
            app(\App\Domain\Identity\PasswordIdentityRegistrar::class),
            app(\App\Domain\Identity\AnonymousIdentityMerger::class)
        );
    }

    public function testMergeFailsWhenNotAnonymousIdentity(): void
    {
        $target = $this->createCanonicalUser();
        $source = $this->createCanonicalUser();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('Only anonymous identities can be merged during registration.');

        $this->app[\App\Http\Api\v1\Controllers\PasswordRegistrationController::class]->__invoke(
            new \App\Http\Api\v1\Requests\PasswordRegistrationRequest([
                'name' => $target->name,
                'email' => $target->emails[0],
                'password' => 'password',
                'anonymous_user_ids' => [$source->_id],
            ]),
            app(\App\Domain\Identity\PasswordIdentityRegistrar::class),
            app(\App\Domain\Identity\AnonymousIdentityMerger::class)
        );
    }

    public function testConcurrencyConflictThrowsException(): void
    {
        $this->expectException(ConcurrencyConflictException::class);

        $target = $this->createCanonicalUser();
        $source = $this->createAnonymousSource();

        // Simulate a concurrent update by incrementing the version
        DB::connection('tenant')->collection('account_users')->where('_id', $target->_id)->increment('version');

        $this->merger()->merge($target, [$source]);
    }
}
