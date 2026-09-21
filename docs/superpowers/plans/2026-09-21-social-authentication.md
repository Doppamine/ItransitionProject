# Social Authentication Foundation Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking. The user's one-atomic-commit instruction overrides per-task commit examples.

**Goal:** Deliver Google/GitHub sign-in, persisted roles and provider identities, blocked-user enforcement, and Candidate Profile initialization.

**Architecture:** One Symfony stateful firewall and callback authenticator use KnpU clients. A transactional account resolver handles application persistence. User equality invalidates stale sessions.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PostgreSQL 17, PHPUnit 13, KnpU OAuth2 bundle, League Google/GitHub providers.

**Spec:** `docs/superpowers/specs/2026-09-21-social-authentication-design.md`

## Global Constraints

- Preserve Tasks 004–005, no password login or Task 007 features.
- One migration and one final commit: `feat: add social authentication foundation`.
- No secrets, provider tokens, or provider profile blobs in Git/database.
- New social Users are Candidates; pre-existing users retain roles.
- Google email must be verified; GitHub email must be primary and verified.
- Use existing Neon development and production databases; Railway pre-deploy command stays unchanged.

## Review Focus

- A blocked user with an existing session must lose access on the next request.
- Role changes must invalidate session authorization, but role ordering alone must not.
- A provider with no verified email must not write account data.
- Two concurrent logins for the same email/provider identity must not leave partial data.
- GitHub login must use the verified primary email endpoint, not `/user` email alone.

---

### Task 1: User security model and schema

**Files:** Modify `composer.json`, `composer.lock`, `config/bundles.php`, `src/Entity/User.php`; create `src/Entity/OAuthAccount.php`, one migration, and tests in `tests/Entity/UserTest.php` and `tests/Entity/OAuthAccountTest.php`.

**Interfaces:** `User::getUserIdentifier(): string`, `getRoles(): array`, `setRoles(array): void`, `isBlocked(): bool`, `setBlocked(bool): void`, `isEqualTo(UserInterface): bool`; `OAuthAccount::__construct(User, OAuthProvider, string)`.

- [ ] Write tests for new-social Candidate roles, deduplication, identifier, session equality, and OAuthAccount validation. A missing interface/method is the expected RED result.
- [ ] Run focused PHPUnit tests to observe RED.
- [ ] Install `symfony/security-bundle`, `knpuniversity/oauth2-client-bundle`, `league/oauth2-google`, `league/oauth2-github`. Implement entity changes and the PostgreSQL migration with roles JSON default `[]`, blocked false, unique provider identity, and User FK cascade.
- [ ] Run focused tests and `doctrine:schema:validate`; expect GREEN and mapping/schema synchronization after migration.

### Task 2: Transactional account resolution

**Files:** Create `src/Security/SocialAccountResolver.php` and `tests/Security/SocialAccountResolverTest.php`.

**Interfaces:** `resolve(OAuthProvider $provider, string $providerUserId, callable $verifiedEmail): User`. Only unknown identities invoke the verified-email callback. The resolver uses Doctrine repositories, one built-in-definition query, and `EntityManagerInterface::wrapInTransaction()`.

- [ ] Write tests for linked identity, email linking without role changes, fresh Candidate/Profile/four values, repeat login, two-provider linking, missing verified email, and rollback. Expect RED because resolver is absent.
- [ ] Run focused PHPUnit tests to observe RED.
- [ ] Implement minimal resolver and database-backed test fixtures; rely on unique constraints for races and roll back on conflict.
- [ ] Run focused and complete PHPUnit suites; expect GREEN.

### Task 3: Provider boundary and Symfony security

**Files:** Create `config/packages/security.yaml`, `config/packages/knpu_oauth2_client.yaml`, `src/Security/SocialAuthenticator.php`, `src/Security/UserChecker.php`, and provider-email extraction code; modify `.env` with empty placeholders; add focused security tests.

**Interfaces:** Only named callback routes select providers; KnpU `ClientRegistry` supplies clients. Google requires verified userinfo email. GitHub requires primary/verified email from `/user/emails` with `user:email` scope. The authenticator delegates to SocialAccountResolver.

- [ ] Write tests for blocked-user rejection, callback-route mapping, verified-email acceptance/rejection, and login failure without persistence. Expect RED.
- [ ] Run focused tests to observe RED.
- [ ] Implement firewall, user checker, authenticator, provider extraction, environment configuration, and success/failure redirects.
- [ ] Run focused tests and Symfony lint/container checks; expect GREEN.

### Task 4: Routes and minimal UI

**Files:** Create authentication/dashboard controllers and Twig templates; modify `templates/base.html.twig`; add WebTestCase route tests.

**Interfaces:** Public `/login`, `/connect/google`, `/connect/google/check`, `/connect/github`, `/connect/github/check`; protected `/dashboard`; Symfony `/logout`.

- [ ] Write route tests for anonymous login display, protected dashboard redirect, authenticated email/roles, and existing home text. Expect RED.
- [ ] Run focused tests to observe RED.
- [ ] Implement routes, Bootstrap login page/nav, and small dashboard without future features.
- [ ] Run focused and full PHPUnit suites; expect GREEN.

### Task 5: Database, deployment, and delivery verification

**Files:** No additional application files unless verification reveals a defect.

- [ ] Apply one migration to Neon development, inspect columns/defaults/unique/FK/cascade, and validate schema.
- [ ] Verify production migration safety, then apply through Railway's existing pre-deploy command if deployment is available; inspect production schema without printing credentials. Do not copy a masked production connection string into the local clipboard.
- [ ] Confirm exact callback URLs from Symfony's router, inspect diff for secrets, run full tests and prod boot checks.
- [ ] Inspect Railway state, create one atomic commit, push `origin/main`, and verify clean tree/GitHub main.
- [ ] If automatic deployment succeeds, verify pre-deploy migration, public `/` and `/login`; otherwise report deployment pending without waiting through a restricted window.
