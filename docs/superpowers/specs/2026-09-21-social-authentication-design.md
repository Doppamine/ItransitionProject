# Social authentication foundation design

Task 006 adds Google and GitHub sign-in to Symfony 7.4 without passwords or future business features. It preserves the Task 004 Attribute Library and Task 005 Profile model. New social users become Candidates with a Profile containing all current built-in definitions as empty values. Existing users keep their persisted roles when another provider is linked.

## Security and identity

Use one stateful Symfony firewall, Doctrine User provider, one authenticator supporting the two named callback routes, and KnpU OAuth2 clients. The callback route name selects the provider; no request parameter can select it. KnpU manages OAuth exchange and state. Google email must be verified. GitHub email must come from its authenticated email endpoint with both `primary` and `verified` true. If a new identity has no such email, authentication fails without a write. Linked identities resolve by provider identity first.

`User` implements Symfony `UserInterface` and `EquatableInterface`. Its identifier is email. Roles are unique and persisted as JSON; new social registrations get `ROLE_CANDIDATE`, while migration gives pre-existing users `[]`. `blocked` defaults false. A `UserChecker` rejects blocked users at authentication; equality invalidates stateful sessions when identifier, blocked status, or the normalized set of roles changes.

## Persistence and account resolution

`OAuthAccount` has an integer identity, mandatory User, provider (`google` or `github`), provider user ID, and creation timestamp. The database enforces unique `(provider, provider_user_id)` and deletes accounts with their User. No provider tokens or profile blobs are persisted.

The resolver first finds the OAuthAccount. Otherwise it normalizes the verified email with the existing User convention, finds its User or creates a new Candidate, attaches the account, and creates `Profile::createWithBuiltIns()` using a single query for all built-in definitions only for a new Candidate. The write is one Doctrine transaction. Existing User roles and Profile remain untouched. Database unique constraints are the final concurrency guard; a conflict rolls the transaction back rather than creating partial data.

## Routes and UI

Public routes: `/`, `/login`, `/connect/google`, `/connect/google/check`, `/connect/github`, `/connect/github/check`. `/dashboard` is authenticated. `/logout` uses Symfony logout. The login page has two provider buttons. Base navigation shows Login anonymously or email and Logout when authenticated. The dashboard only displays current identity and roles.

## Configuration and deployment

Install Symfony Security, `knpuniversity/oauth2-client-bundle`, and mature League Google/GitHub providers only. OAuth credentials are environment variables, with no real values in tracked files. A single PostgreSQL migration adds roles, blocked, and OAuthAccount. Apply and verify on Neon development and production, then push exactly one atomic commit. Railway's existing pre-deploy migration command remains unchanged. Real OAuth provider app registration may require user interaction; automated tests do not call either provider.

## Verification

Tests cover roles, equality, checker, resolution/linking/idempotence, Candidate Profile built-ins, and transaction rollback. Validate Doctrine schema and database constraints on Neon development and production. Verify Symfony boot, route protection and login rendering locally. After push, inspect Railway and public HTTP if automatic deployment is available. Do not implement Task 007.
