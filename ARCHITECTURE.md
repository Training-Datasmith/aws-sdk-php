# Architecture: aws-sdk-php

## Purpose

The official AWS SDK for PHP, providing client libraries for every AWS service. Each client wraps the AWS REST/query/JSON wire protocol, handles authentication (SigV4, STS, etc.), retries, endpoint resolution, pagination, and waiters — so callers can interact with AWS services using idiomatic PHP objects.

## Directory Structure

```
src/
  Sdk.php                        — Entry point factory: create(service, config) returns a service client
  AwsClient.php                  — Base client: command execution, middleware pipeline, result handling
  AwsClientInterface.php         — Public client contract
  ClientResolver.php             — Resolves credentials, regions, endpoints, and HTTP options
  HandlerList.php                — Middleware stack for request/response pipeline
  Command.php                    — Value object: a named operation with input parameters
  Result.php                     — Value object: service response data with ArrayAccess
  ResultPaginator.php            — Lazy iterator over paginated responses
  Waiter.php                     — Polls a resource until a desired state is reached

  data/                          — JSON service model files per service + API version
    {service}/{version}/
      api-2.json                 — Operations, shapes, serialisation format
      docs-2.json                — Per-operation documentation strings
      paginators-1.json          — Pagination token definitions
      waiters-2.json             — Waiter state machine definitions
      endpoint-rule-set-1.json   — Endpoint resolution rules (SmithyEndpointRuleSet)

  Credentials/                   — Credentials resolution: static, env, instance profile, SSO, assume-role
  Endpoint/                      — Endpoint rule evaluation (Smithy endpoint rules)
  Middleware/                    — Middleware: signing, retry, presigning, user-agent, etc.
  Retry/                         — Configurable retry strategies (legacy, standard, adaptive)
  Signature/                     — SigV4, SigV4A, SigV2 signing implementations
  Token/                         — AWS Bearer Token auth (for SSO-based services)
  S3/                            — S3-specific client (multipart upload, presigned URLs, stream wrappers)
  DynamoDB/                      — DynamoDB-specific marshalling helpers
  ...                            — One directory per service with service-specific customisations

tests/
  features/                      — Behat integration tests
  Unit/                          — PHPUnit unit tests mirroring src/ structure
```

## Key Design Decisions

- **Code generation from JSON models** — service clients are not hand-written; they are generated at build time from `src/data/*/api-2.json` model files that describe every operation, shape, and error.
- **Middleware pipeline** — `HandlerList` composes request/response handlers into a pipeline (signing → retry → error parsing → result parsing), keeping cross-cutting concerns decoupled from operation logic.
- **Smithy endpoint rules** — endpoint URL resolution uses the Smithy EndpointRuleSet engine (`endpoint-rule-set-1.json`), enabling service-specific and region-specific endpoint logic without hard-coding.
- **Credentials chain** — `CredentialProvider` tries multiple sources in order (env vars, ini file, container metadata, instance metadata, SSO, assume-role) and caches the resolved credential for the TTL.
- **Lazy pagination** — `ResultPaginator` returns a Generator that only makes the next HTTP call when iterated, keeping memory usage constant for large result sets.

## Extension Points

- Inject custom middleware into `HandlerList` to log, trace, or modify every request.
- Implement `CredentialsInterface` for a custom credential source.
- Implement `EndpointProviderV2` for custom endpoint resolution.
- Use `MockHandler` in tests to inject fixture responses without network calls.

## Dependency Flow

```
Sdk::createS3(config)
  └── ClientResolver (credentials + endpoint)
        └── S3Client extends AwsClient
              └── HandlerList (middleware pipeline)
                    ├── Signature\SignatureV4 (auth)
                    ├── Retry\RetryMiddleware (exponential backoff)
                    └── GuzzleHttp\Handler (HTTP transport)
                          → AWS API → Result
```
