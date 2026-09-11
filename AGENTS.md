# Repository working agreements

## Keep the integration handoff synchronized

Any change that affects installation, supported versions, environment variables, provider behavior, public PHP or TypeScript APIs, routes, authorization, Surfaces, tools, transcripts, usage/cost auditing, or test commands must update the integration handoff in the same change.

The synchronized handoff consists of:

- the `Handoff` section in `README.md`;
- `skills/install-laravel-realtime-agent/SKILL.md` when the agent workflow or its routing changes;
- `skills/install-laravel-realtime-agent/references/integration-manual.md` for operational details and examples;
- `skills/install-laravel-realtime-agent/references/case-studies.md` when a reusable integration pattern changes; case studies must remain anonymous and must not identify private products, repositories, paths, remotes, or proprietary class names;
- `skills/install-laravel-realtime-agent/agents/openai.yaml` when the skill name, purpose, or default invocation changes.

Do not copy speculative APIs into the handoff. Verify examples against the current package and browser exports. Before completing a relevant change, run the package checks, the skill validator, and the README visual validator documented in the repository.
