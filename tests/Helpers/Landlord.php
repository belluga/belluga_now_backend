<?php

namespace Tests\Helpers;

class Landlord extends Labels {

    public UserLabels $user_superadmin {
        get {
            return new UserLabels(
                $this->base_label.".users.superadmin"
            );
        }
    }

    public UserLabels $user_cross_tenant_admin {
        get {
            return new UserLabels(
                $this->base_label.".users.cross_tenant_admin"
            );
        }
    }

    public UserLabels $user_cross_tenant_visitor {
        get {
            return new UserLabels(
                $this->base_label.".users.cross_tenant_visitor"
            );
        }
    }

    public UserLabels $user_disposable {
        get {
            return new UserLabels(
                $this->base_label.".users.disposable"
            );
        }
    }

    public RoleLabels $role_superadmin {
        get {
            return new RoleLabels(
                $this->base_label.".role.superadmin"
            );
        }
    }

    public RoleLabels $role_tenants_manager {
        get {
            return new RoleLabels(
                $this->base_label.".role.tenants_manager"
            );
        }
    }

    public RoleLabels $role_users_manager {
        get {
            return new RoleLabels(
                $this->base_label.".role.users_manager"
            );
        }
    }

    public RoleLabels $role_visitor {
        get {
            return new RoleLabels(
                $this->base_label.".role.visitor"
            );
        }
    }

    public RoleLabels $role_disposable {
        get {
            return new RoleLabels(
                $this->base_label.".role.disposable"
            );
        }
    }

    public TenantLabels $tenant_primary {
        get {
            return new TenantLabels(
                $this->base_label.".tenant.primary",
                "Belluga Solutions Test"
            );
        }
    }

    public TenantLabels $tenant_secondary {
        get {
            return new TenantLabels(
                $this->base_label.".tenant.secondary",
                "Tenant Secondary"
            );
        }
    }

    public TenantLabels $tenant_disposable {
        get {
            return new TenantLabels(
                $this->base_label.".tenant.disposable",
                "Tenant Disposable"
            );
        }
    }
}
