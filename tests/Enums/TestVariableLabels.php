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

    case TENANT_1_SUBDOMAIN = "tenant.1.subdomain";

    case TENANT_1_MAIN_ACCOUNT_ID = "tenant.1.main.account.id";

    case TENANT_1_MAIN_ACCOUNT_SLUG = "tenant.1.main.account.slug";

    case TENANT_2_SLUG = "tenant.2.slug";

    case TENANT_2_SUBDOMAIN = "tenant.2.subdomain";

    case ROLE_ID = "role.id";

    case MAIN_LANDLORD_ROLE_ID = "main.landlord.role.id";

    case TENANT_2_MAIN_ACCOUNT_SLUG = "tenant.2.main.account.slug";

    case TENANT_1_ACCOUNT_USER_ADMIN_ID = "tenant.1.account.user.admin.id";

    case TENANT_1_ACCOUNT_USER_ADMIN_PASSWORD = "tenant.1.account.user.admin.password";

    case TENANT_1_ACCOUNT_USER_ADMIN_EMAIL = "tenant.1.account.user.admin.email";

    case TENANT_1_ACCOUNT_USER_ADMIN_NAME = "tenant.1.account.user.admin.name";

    case TENANT_1_ACCOUNT_USER_ADMIN_TOKEN = "tenant.1.account.user.admin.token";

    case TENANT_2_MAIN_ACCOUNT_ID = "tenant.2.main.account.id";

    case TENANT_2_MAIN_ACCOUNT_ROLE_ID = "tenant.2.main.account.role.id";

    case TENANT_2_SECONDARY_ACCOUNT_SLUG = "tenant.2.secondary.account.slug";

    case TENANT_2_SECONDARY_ACCOUNT_ID = "tenant.2.secondary.account.id";

    case TENANT_2_SECONDARY_ACCOUNT_ROLE_ID = "tenant.2.secondary.account.role.id";

    case TENANT_2_DELETE_ACCOUNT_SLUG = "tenant.2.delete.account.slug";

    case SECONDARY_ROLE_ID = "tenant.2.main_account.secondary.role.id";

    case SECONDARY_ACCOUNT_USER_ADMIN_ID = "secondary.account.user.admin.id";

    case SECONDARY_ACCOUNT_USER_ADMIN_EMAIL = "secondary.account.user.admin.email";

    case SECONDARY_ACCOUNT_USER_ADMIN_NAME = "secondary.account.user.admin.name";

    case SECONDARY_ACCOUNT_USER_ADMIN_PASSWORD = "secondary.account.user.admin.password";

    case SECONDARY_ACCOUNT_USER_ADMIN_TOKEN = "secondary.account.user.admin.token";

    case TENANT_2_ACCOUNT_USER_USERMANAGE_ID = "tenant.2.account.user.usermanage.id";

    case TENANT_2_ACCOUNT_USER_ROLEMANAGE_ID = "tenant.2.account.user.rolemanage.id";

    case TENANT_2_ACCOUNT_ROLE_USERMANAGE_ID = "tenant.2.account.role.usermanage.id";

    case TENANT_2_ACCOUNT_ROLE_ROLEMANAGE_ID = "tenant.2.account.role.rolemanage.id";

    case TENANT_1_ACCOUNT_ROLE_ADMIN_ID = "tenant.1.account.role.admin.id";

    case TENANT_2_ACCOUNT_ROLE_ADMIN_ID = "tenant.2.account.role.admin.id";

    case SECONDARY_ACCOUNT_ROLE_ADMIN_ID = "secondary.account.role.admin.id";

    case TENANT_2_ACCOUNT_ROLE_VISITOR_ID = "tenant.2.account.role.visitor.id";

    case TENANT_2_ACCOUNT_USER_ADMIN_ID = "tenant.2.account.user.admin.id";

    case TENANT_2_ACCOUNT_USER_ADMIN_PASSWORD = "tenant.2.account.user.admin.password";

    case TENANT_2_ACCOUNT_USER_ADMIN_EMAIL_1 = "tenant.2.account.user.admin.email.1";

    case TENANT_2_ACCOUNT_USER_ADMIN_EMAIL_2 = "tenant.2.account.user.admin.email.2";

    case TENANT_2_ACCOUNT_USER_ADMIN_NAME = "tenant.2.account.user.admin.name";

    case TENANT_2_ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN = "tenant.2.account.user.admin.device_1.token";

    case TENANT_2_ACCOUNT_USER_ADMIN_DEVICE_2_TOKEN = "tenant.2.account.user.admin.device_2.token";

    case TENANT_2_ACCOUNT_USER_USERMANAGE_PASSWORD = "tenant.2.account.user.usermanage.password";

    case TENANT_2_ACCOUNT_USER_USERMANAGE_EMAIL = "tenant.2.account.user.usermanage.email";

    case TENANT_2_ACCOUNT_USER_USERMANAGE_NAME = "tenant.2.account.user.usermanage.name";

    case TENANT_2_ACCOUNT_USER_USERMANAGE_TOKEN = "tenant.2.account.user.usermanage.token";

    case TENANT_2_ACCOUNT_USER_ROLEMANAGE_PASSWORD = "tenant.2.account.user.rolemanage.password";

    case TENANT_2_ACCOUNT_USER_ROLEMANAGE_EMAIL = "tenant.2.account.user.rolemanage.email";

    case TENANT_2_ACCOUNT_USER_ROLEMANAGE_NAME = "tenant.2.account.user.rolemanage.name";

    case TENANT_2_ACCOUNT_USER_ROLEMANAGE_TOKEN = "tenant.2.account.user.rolemanage.token";

    case TENANT_2_ACCOUNT_USER_TODELETE_NAME = "tenant.2.account.user.todelete.name";

    case TENANT_2_ACCOUNT_USER_TODELETE_EMAIL = "tenant.2.account.user.todelete.email";

    case TENANT_2_ACCOUNT_USER_TODELETE_PASSWORD = "tenant.2.account.user.todelete.password";

    case TENANT_2_ACCOUNT_USER_TODELETE_ID = "tenant.2.account.user.todelete.id";

    case TENANT_2_ACCOUNT_USER_TODELETE_EMAIL_2 = "tenant.2.account.user.todelete.email.2";

    case TENANT_2_ACCOUNT_USER_TODELETE_EMAIL_3 = "tenant.2.account.user.todelete.email.3";

    case TENANT_2_ACCOUNT_USER_VISITOR_NAME = "tenant.2.account.user.visitor.name";

    case TENANT_2_ACCOUNT_USER_VISITOR_EMAIL = "tenant.2.account.user.visitor.email";

    case TENANT_2_ACCOUNT_USER_VISITOR_PASSWORD = "tenant.2.account.user.visitor.password";

    case TENANT_2_ACCOUNT_USER_VISITOR_ID = "tenant.2.account.user.visitor.id";

    case TENANT_2_ACCOUNT_USER_VISITOR_TOKEN = "tenant.2.account.user.visitor.token";

    case ROLE_ID_CREATED_BY_ACCOUNT_ADMIN_USER = "role.id.created_by_account_admin_user";

    case ROLE_ID_CREATED_BY_ACCOUNT_ROLEMANAGE_USER = "role.id.created_by_account_rolemanage_user";

    case USER_ID_CREATED_BY_ACCOUNT_ADMIN_USER = "user.id.created_by_account_admin_user";

    case USER_ID_CREATED_BY_ACCOUNT_USERMANAGE_USER = "user.id.created_by_account_rolemanage_user";

}
