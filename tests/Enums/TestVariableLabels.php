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

    case TENANT_1_SLUG = "tenant.1.slug";

    case TENANT_2_SLUG = "tenant.2.slug";

    case TENANT_2_SUBDOMAIN = "tenant.2.subdomain";

    case ROLE_ID = "role.id";

    case MAIN_LANDLORD_ROLE_ID = "main.landlord.role.id";

    case TENANT_2_MAIN_ACCOUNT_SLUG = "tenant.2.main.account.slug";

    case TENANT_2_MAIN_ACCOUNT_ID = "tenant.2.main.account.id";

    case TENANT_2_MAIN_ACCOUNT_ROLE_ID = "tenant.2.main.account.role.id";

    case TENANT_2_DELETE_ACCOUNT_SLUG = "tenant.2.delete.account.slug";

    case SECONDARY_ROLE_ID = "tenant.2.main_account.secondary.role.id";
}
