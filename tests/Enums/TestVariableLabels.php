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

    case ACCOUNT_USER_ADMIN_ID = "account.user.admin.id";

    case ACCOUNT_USER_USERMANAGE_ID = "account.user.usermanage.id";

    case ACCOUNT_USER_ROLEMANAGE_ID = "account.user.rolemanage.id";

    case ACCOUNT_ROLE_USERMANAGE_ID = "account.role.usermanage.id";

    case ACCOUNT_ROLE_ROLEMANAGE_ID = "account.role.rolemanage.id";

    case ACCOUNT_ROLE_ADMIN_ID = "account.role.admin.id";

    case ACCOUNT_USER_ADMIN_PASSWORD = "account.user.admin.password";

    case ACCOUNT_USER_ADMIN_EMAIL_1 = "account.user.admin.email.1";

    case ACCOUNT_USER_ADMIN_EMAIL_2 = "account.user.admin.email.2";

    case ACCOUNT_USER_ADMIN_NAME = "account.user.admin.name";

    case ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN = "account.user.admin.device_1.token";

    case ACCOUNT_USER_ADMIN_DEVICE_2_TOKEN = "account.user.admin.device_2.token";

    case ACCOUNT_USER_USERMANAGE_PASSWORD = "account.user.usermanage.password";

    case ACCOUNT_USER_USERMANAGE_EMAIL = "account.user.usermanage.email";

    case ACCOUNT_USER_USERMANAGE_NAME = "account.user.usermanage.name";

    case ACCOUNT_USER_USERMANAGE_TOKEN = "account.user.usermanage.token";

    case ACCOUNT_USER_ROLEMANAGE_PASSWORD = "account.user.rolemanage.password";

    case ACCOUNT_USER_ROLEMANAGE_EMAIL = "account.user.rolemanage.email";

    case ACCOUNT_USER_ROLEMANAGE_NAME = "account.user.rolemanage.name";

    case ACCOUNT_USER_ROLEMANAGE_TOKEN = "account.user.rolemanage.token";

    case ACCOUNT_USER_TODELETE_NAME = "account.user.todelete.name";

    case ACCOUNT_USER_TODELETE_EMAIL = "account.user.todelete.email";

    case ACCOUNT_USER_TODELETE_PASSWORD = "account.user.todelete.password";

    case ACCOUNT_USER_TODELETE_ID = "account.user.todelete.id";

    case ACCOUNT_USER_TODELETE_EMAIL_2 = "account.user.todelete.email.2";

    case ACCOUNT_USER_TODELETE_EMAIL_3 = "account.user.todelete.email.3";

    case ACCOUNT_USER_VISITOR_NAME = "account.user.visitor.name";

    case ACCOUNT_USER_VISITOR_EMAIL = "account.user.visitor.email";

    case ACCOUNT_USER_VISITOR_PASSWORD = "account.user.visitor.password";

    case ACCOUNT_USER_VISITOR_ID = "account.user.visitor.id";

}
