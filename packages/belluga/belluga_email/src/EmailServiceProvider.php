<?php

declare(strict_types=1);

namespace Belluga\Email;

use Belluga\Email\Contracts\EmailSettingsSourceContract;
use Belluga\Email\Contracts\EmailTenantContextContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class EmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/belluga_email.php', 'belluga_email');
        $this->ensureHostBinding(EmailSettingsSourceContract::class);
        $this->ensureHostBinding(EmailTenantContextContract::class);
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_email host binding missing for [{$abstract}]");
        });
    }
}
