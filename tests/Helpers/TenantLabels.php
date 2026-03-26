<?php

namespace Tests\Helpers;

use Illuminate\Support\Str;

class TenantLabels extends Labels
{
    protected string $company_name;

    public function __construct(string $base_label, string $company_name)
    {
        parent::__construct($base_label);
        $this->company_name = $company_name;
    }

    public string $id {
        set(string $value) {
            $this->setGlobal($this->base_label.'.id', $value);
            $this->id = $value;
        }
        get {
            return $this->getGlobal($this->base_label.'.id');
        }
    }

    public string $name {
        get {
            return $this->company_name;
        }
    }

    public string $subdomain {
        set(string $value) {
            $this->setGlobal($this->base_label.'.subdomain', $value);
            $this->subdomain = $value;
        }
        get {
            $persistedSubdomain = $this->getGlobal($this->base_label.'.subdomain');

            if ($persistedSubdomain) {
                return $persistedSubdomain;
            }

            return Str::slug($this->name);
        }
    }

    public string $slug {
        set(string $value) {
            $this->setGlobal($this->base_label.'.slug', $value);
            $this->slug = $value;
        }
        get {
            $persistedSlug = $this->getGlobal($this->base_label.'.slug');

            if ($persistedSlug) {
                return $persistedSlug;
            }

            return Str::slug($this->name);
        }
    }

    public string $base_api_url {
        get {
            return "http://$this->subdomain.".env('APP_HOST').'/api/v1/';
        }
    }

    public string $base_url {
        get {
            return "http://$this->subdomain.".env('APP_HOST').'/';
        }
    }

    public string $theme_brightness_default {
        get{
            return $this->getGlobal($this->base_label.'.theme_brightness_default');
        }

        set(string $value) {
            $this->setGlobal($this->base_label.'.theme_brightness_default', $value);
            $this->theme_brightness_default = $value;
        }
    }

    public string $theme_primary_seed_color {
        get{
            return $this->getGlobal($this->base_label.'.theme_primary_seed_color');
        }

        set(string $value) {
            $this->setGlobal($this->base_label.'.theme_primary_seed_color', $value);
            $this->theme_primary_seed_color = $value;
        }
    }

    public string $theme_secondary_seed_color {
        get{
            return $this->getGlobal($this->base_label.'.theme_secondary_seed_color');
        }

        set(string $value) {
            $this->setGlobal($this->base_label.'.theme_secondary_seed_color', $value);
            $this->theme_secondary_seed_color = $value;
        }
    }

    public UserLabels $user_admin {
        get {
            return new UserLabels(
                $this->base_label.'.users.admin'
            );
        }
    }

    public UserLabels $user_roles_manager {
        get {
            return new UserLabels(
                $this->base_label.'.users.roles_manager'
            );
        }
    }

    public UserLabels $user_users_manager {
        get {
            return new UserLabels(
                $this->base_label.'.users.users_manager'
            );
        }
    }

    public UserLabels $user_visitor {
        get {
            return new UserLabels(
                $this->base_label.'.users.visitor'
            );
        }
    }

    public RoleLabels $role_admin {
        get {
            return new RoleLabels(
                $this->base_label.'.roles.admin'
            );
        }
    }

    public RoleLabels $role_roles_manager {
        get {
            return new RoleLabels(
                $this->base_label.'.roles.roles_manager'
            );
        }
    }

    public RoleLabels $role_users_manager {
        get {
            return new RoleLabels(
                $this->base_label.'.roles.users_manager'
            );
        }
    }

    public RoleLabels $role_visitor {
        get {
            return new RoleLabels(
                $this->base_label.'.roles.visitor'
            );
        }
    }

    public RoleLabels $role_disposable {
        get {
            return new RoleLabels(
                $this->base_label.'.role.disposable'
            );
        }
    }

    public AccountLabels $account_primary {
        get {
            return new AccountLabels(
                $this->base_label.'.accounts.primary'
            );
        }
    }

    public AccountLabels $account_secondary {
        get {
            return new AccountLabels(
                $this->base_label.'.accounts.secondary'
            );
        }
    }

    public AccountLabels $account_disposable {
        get {
            return new AccountLabels(
                $this->base_label.'.accounts.disposable'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'slug' => $this->slug,
            'base_api_url' => $this->base_api_url,
            'users' => [
                'admin' => $this->user_admin->toArray(),
                'roles_manager' => $this->user_roles_manager->toArray(),
                'users_manager' => $this->user_users_manager->toArray(),
            ],
        ];
    }
}
