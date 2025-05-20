<?php

namespace App\Enums;

enum PermissionsActions: string {
    case VIEW = "view";

    case VIEW_OTHERS = "view.others";

    case CREATE = "create";

    case UPDATE = "update";

    case UPDATE_OTHERS = "update.others";

    case DELETE = "delete";

    case DELETE_OTHERS = "delete.others";

}
