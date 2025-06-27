<?php

namespace Tests\Helpers;

class UserLabels extends Labels {

    public string $token {
        set(string $value) {
            $this->setGlobal($this->base_label . ".token", $value);
            $this->token = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".token");
        }
    }

    public string $user_id {
        set(string $value) {
            $this->setGlobal($this->base_label . ".user_id", $value);
            $this->user_id = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".user_id");
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

    public string $email_1 {
        set(string $value) {
            $this->setGlobal($this->base_label . ".email_1", $value);
            $this->email_1 = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".email_1");
        }
    }

    public string $email_2 {
        set(string $value) {
            $this->setGlobal($this->base_label . ".email_2", $value);
            $this->email_2 = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".email_2");
        }
    }

    public string $password {
        set(string $value) {
            $this->setGlobal($this->base_label . ".password", $value);
            $this->password = $value;
        }
        get {
            return $this->getGlobal($this->base_label . ".password");
        }
    }


}
