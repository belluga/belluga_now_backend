<?php

namespace Tests;

enum TestVariableLabels: string {
    case USER_NAME = "user.name";

    case USER_PASSWORD = "user.password";

    case USER_EMAIL = "user.email";

    case ACCOUNT_NAME = "account.name";

    case ACCOUNT_DOCUMENT = "account.document";

    case ACCOUNT_ADDRESS = "account.address";

    case MAIN_USER_ID = "main.user.id";

    case MAIN_ACCOUNT_ID = "main.user.account";

    case MAIN_ACCOUNT_TOKEN = "main.token";
}
