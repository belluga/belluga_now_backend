<?php

namespace Tests\Enums;

enum TestVariableLabels: string {
    case USER_NAME = "user.name";

    case USER_PASSWORD = "user.password";

    case USER_EMAIL_1 = "user.email.1";

    case USER_EMAIL_2 = "user.email.2";

    case LANDLORD_USER_ID = "main.user.id";

    case SECONDARY_LANDLORD_USER_ID = "secondary.landlord.user.id";

    case SECONDARY_LANDLORD_USER_EMAIL = "secondary.landlord.user.email";

    case SECONDARY_LANDLORD_USER_PASSWORD = "secondary.landlord.user.password";

    case SECONDARY_LANDLORD_TOKEN = "secondary.landlord.token";

    case LANDLORD_TOKEN = "landlord.token";

    case TENANT_1_NAME = "tenant.1.name";
}
