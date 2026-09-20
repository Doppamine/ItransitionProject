# Repository Guidelines

- PHP 8.4 and Symfony 7.4 LTS are fixed.
- PostgreSQL 17 with Doctrine ORM is the persistence stack.
- Use server-rendered Twig, Bootstrap, and AssetMapper as the frontend baseline; do not introduce an SPA unless explicitly requested.
- Avoid duplication and use functionality already provided cleanly by PHP, Symfony, Doctrine, PostgreSQL, or a mature library.
- Prefer simple, framework-conventional solutions over custom abstraction layers.
- Add dependencies only for a concrete current need.
- Prefer small, cohesive methods and clear names; avoid comments where structure and naming explain the code.
- Do not implement speculative future functionality.
- Avoid N+1 queries and queries inside loops.
- Preserve database integrity with proper constraints rather than application-only assumptions.
- Keep the application deployable after every task.
- Make the smallest reasonable diff and one atomic commit per assigned task unless explicitly instructed otherwise.
- Before implementing, inspect existing code and reuse established conventions instead of duplicating mechanisms.
