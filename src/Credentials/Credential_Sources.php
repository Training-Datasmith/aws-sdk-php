<?php

declare (strict_types=1);
namespace Aws\Credentials;

/**
 * @internal
 */
final class Credential_Sources
{
    public const STATIC = 'static';
    public const ENVIRONMENT = 'env';
    public const STS_WEB_ID_TOKEN = 'sts_web_id_token';
    public const ENVIRONMENT_STS_WEB_ID_TOKEN = 'env_sts_web_id_token';
    public const PROFILE_STS_WEB_ID_TOKEN = 'profile_sts_web_id_token';
    public const STS_ASSUME_ROLE = 'sts_assume_role';
    public const PROFILE = 'profile';
    public const IMDS = 'instance_profile_provider';
    public const ECS = 'ecs';
    public const PROFILE_SSO = 'profile_sso';
    public const PROFILE_SSO_LEGACY = 'profile_sso_legacy';
    public const PROFILE_PROCESS = 'profile_process';
    public const PROFILE_LOGIN = 'profile_login';
}