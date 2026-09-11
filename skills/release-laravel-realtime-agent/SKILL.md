---
name: release-laravel-realtime-agent
description: Review agents-full-duplex/laravel-realtime-agent for release readiness, prepare its SemVer metadata and changelog, run the complete offline-safe release gate, create an atomic local release commit, and add an annotated Git tag. Use for package release audits, release candidates, or local tagged releases; never publish or push unless separately requested.
---

# Release Laravel Realtime Agent

Review first, then create a release only when every required gate passes. A release request authorizes the local version/changelog/build edits, release commit, and tag; it does not authorize a push, GitHub release, registry publication, or paid provider call.

## Establish scope

1. Read the repository `AGENTS.md` and obey its synchronization and Git rules.
2. Confirm that `composer.json` names `agents-full-duplex/laravel-realtime-agent`. If it does not, stop: this skill is repository-specific.
3. Inspect the current branch, worktree, remotes, existing tags, current package versions, `[Unreleased]` changelog, and commits since the latest reachable tag. Never hide, discard, or include unrelated user changes.
4. Resolve the intended SemVer version and canonical tag `v<version>`. Prefer a version explicitly supplied by the user. Otherwise use an already prepared unreleased version when it is greater than the latest tag; if the required major/minor/patch bump is genuinely ambiguous, report the evidence and ask before editing.
5. Treat a request for review only as read-only. Do not prepare a commit or tag unless the user asked for a release or tag.

When no previous tag exists, review the complete reachable history and treat the release as the first public baseline.

## Release review

Read the actual diff and source, not only the changelog. Report findings by severity with file and line references. The following are release blockers:

- failing tests, static analysis, formatting, schema synchronization, build, E2E, skill validation, or repository-required README validation;
- secrets, local credentials, environment-specific files, unreviewed generated output, unresolved merge state, or unexpected worktree changes;
- a public PHP/TypeScript/provider/security/audit change not synchronized with the README Handoff and bundled integration skill as required by `AGENTS.md`;
- identifying private products, repositories, paths, remotes, or proprietary class names in anonymous case studies;
- source and tracked `dist` output disagreeing after a clean TypeScript build;
- incomplete `[Unreleased]` notes, incompatible dependency constraints, or version/tag collisions;
- a high-confidence correctness, authorization, audit, migration, provider-normalization, or backwards-compatibility defect.

Review provider paths without spending money: Fake must remain the standard executable path, OpenAI GPT-Live and ElevenLabs must be covered by contract/faked HTTP tests, credentials must remain server-side, and live smoke tests remain opt-in.

## Prepare the release

Only after the review is clear, make the smallest release-only edits:

1. Update `package.json`, the top-level `package-lock.json` version, and its root `packages[""]` version to the release version. Do not add a `version` field to `composer.json`; Packagist derives it from the Git tag.
2. Keep an empty `## [Unreleased]` section and move the accumulated entries into `## [<version>] - YYYY-MM-DD` using the current repository date. Preserve Keep a Changelog categories and describe shipped behavior, not aspirations.
3. Rebuild tracked TypeScript declarations and JavaScript with `npm run build`. Inspect the resulting `dist` diff and include it only when it is generated from the reviewed source.
4. If release mechanics, supported versions, public APIs, providers, security, audit, or verification changed, update every synchronized handoff file named by `AGENTS.md` before testing.

Do not bundle unrelated feature work into the release commit. If intended release changes are still uncommitted, review them as release content and commit them in coherent functional commits before the final metadata commit.

## Required gate

Run these checks from the repository root with the installed lockfiles:

```bash
composer validate --strict
composer check
npm run readme:check
npm run check
npm run test:e2e
skill_validator="${CODEX_HOME:-$HOME/.codex}/skills/.system/skill-creator/scripts/quick_validate.py"
python3 "$skill_validator" skills/install-laravel-realtime-agent
python3 "$skill_validator" skills/release-laravel-realtime-agent
```

Resolve the validator path from the active `skill-creator` installation if `CODEX_HOME` uses a nonstandard layout. `npm run readme:check` is also included by `npm run check`; execute it explicitly so a visual-documentation failure is easy to identify. If a required validator or its runtime dependency is missing, record that as a release blocker instead of silently skipping it. Never substitute a live provider smoke test for the deterministic suite.

After the build and checks, inspect `git diff --check`, `git status`, the complete staged diff, and the candidate file list. A dirty tree is acceptable only while preparing the intended release commit; it must be clean after the commit.

## Commit and tag

Before mutating Git, verify that neither the local repository nor the configured remote already has the exact tag. A remote check is read-only; if it cannot be performed, state that only the local collision check was proven.

Create one metadata commit named:

```text
chore: release <version>
```

Then create an annotated local tag that points exactly to that commit:

```bash
git tag -a v<version> -m "Laravel Realtime Agent v<version>"
```

Use a signed tag only when the user explicitly requests it and signing is already configured. Do not move, replace, delete, or force an existing tag. Do not amend prior commits.

Verify the outcome with the tag object, its target commit, the release metadata at that commit, and a clean `git status`. Do not run `git push`, publish a GitHub release, or publish Composer/npm artifacts unless the user explicitly requests that separate external action.

## Final report

Lead with either `release ready` or `release blocked`. Include:

- version, tag, branch, and release commit hash;
- review findings, or explicitly state that no release-blocking findings remain;
- exact commands passed and any checks not run;
- provider-live tests intentionally not run;
- confirmation that the tag exists only locally and nothing was pushed or published.
