<?php

namespace Tests\Enums;

enum TestVariableLabels: string {
    case USER_NAME = "user.name";

    case USER_PASSWORD = "user.password";

    case USER_EMAIL = "user.email";

    case MAIN_USER_ID = "main.user.id";

    case SECONDARY_USER_ID = "secondary.user.id";

    case SECONDARY_USER_PASSWORD = "secondary.user.password";
}
