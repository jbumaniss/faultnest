# FaultNest security policy

FaultNest is under active development and **has no published stable release or guaranteed support window**.

For a potential vulnerability, use GitHub's private vulnerability reporting/security advisory feature for this repository if enabled. If private reporting is unavailable, open a **non-sensitive** issue requesting a private communication method; do not post exploit details, real user data, credentials, access tokens or production logs in a public issue.

Important security boundaries:

- FaultNest does not host a service or expose HTTP routes. Host applications are responsible for authentication, CSRF, rate limits and content-length checks on their browser ingestion routes.
- The host decides whether to enable Mail/custom channels and optional storage. Redaction of free-form messages and arbitrary manually supplied context is best effort.
- Database history must be access-controlled by the host application; FaultNest's PHP reader methods are not an authorization layer.
- Monitoring must never be treated as a secure place to store secrets or full request/authorization data.

No production deployment is recommended before the compatibility and security review checklist has been completed.
