<?php

namespace Tests\Enums;

enum TestVariableLabels: string {
    case USER_NAME = "user.name";

    case USER_PASSWORD = "user.password";

    case USER_EMAIL = "user.email";

    case ACCOUNT_NAME = "account.name";

    case ACCOUNT_DOCUMENT = "account.document";

    case ACCOUNT_ADDRESS = "account.address";

    case MAIN_USER_ID = "main.user.id";

    case MAIN_ACCOUNT_ID = "main.account.id";

    case MAIN_ACCOUNT_SLUG = "main.account.slug";

    case MAIN_ACCOUNT_TOKEN = "main.account.token";

    case SECONDARY_ACCOUNT_ID = "secondary.account.id";

    case SECONDARY_ACCOUNT_SLUG = "secondary.account.slug";

    case SECONDARY_ACCOUNT_TOKEN = "secondary.account.token";

    case SECONDARY_USER_ID = "secondary.user.id";

    case SECONDARY_USER_PASSWORD = "secondary.user.password";
}
