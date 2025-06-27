<?php

namespace Tests\Helpers;

class AccountLabels extends Labels {

    public string $id {
        set(string $value) {
            $this->setGlobal($this->base_label . ".id", $value);
            $this->id = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".id");
        }
    }

    public string $name {
        set(string $value) {
            $this->setGlobal($this->base_label . ".name", $value);
            $this->name = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".name");
        }
    }

    public string $document {
        set(string $value) {
            $this->setGlobal($this->base_label . ".document", $value);
            $this->document = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".document");
        }
    }

    public string $slug {
        set(string $value) {
            $this->setGlobal($this->base_label . ".slug", $value);
            $this->slug = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".slug");
        }
    }

    public UserLabels $user_admin {
        get {
            return new UserLabels(
                $this->base_label.".users.admin"
            );
        }
    }

    public UserLabels $user_users_manager {
        get {
            return new UserLabels(
                $this->base_label.".users.users_manager"
            );
        }
    }

    public UserLabels $user_visitor {
        get {
            return new UserLabels(
                $this->base_label.".users.visitor"
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

    public RoleLabels $role_admin {
        get {
            return new RoleLabels(
                $this->base_label.".roles.admin"
            );
        }
    }

    public RoleLabels $role_manager {
        get {
            return new RoleLabels(
                $this->base_label.".roles.role_manager"
            );
        }
    }

    public RoleLabels $role_user_manager {
        get {
            return new RoleLabels(
                $this->base_label.".roles.user_manager"
            );
        }
    }

    public RoleLabels $role_visitor {
        get {
            return new RoleLabels(
                $this->base_label.".roles.visitor"
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

}
