<?php

namespace Tests\Helpers;

class RoleLabels extends Labels {

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

    public function toArray(): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
        ];
    }

}
