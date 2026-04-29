# AGENTS.md — Nizami Farms

Instructions for any AI coding assistant (Cursor, Claude Code, Codex, Aider, Continue, etc.) operating in this repository.

This file is the cross-tool source of truth. The Cursor-specific copy at `.cursor/rules/analytics-sandbox.mdc` mirrors the same rules.

---

## Project context (read once, every session)

This is the live Nizami Farms operations app — Laravel 11, hosted at `app.nizamifarms.com`. Production is deployed manually by uploading files to stackcp; the git repository is the development source-of-truth only.

---

## Edit-boundary rule (the most important rule in this repo)

If your current task is anything related to **analytics dashboards, prototypes, reporting, or the sandbox folder**, you may **only** create, modify, or delete files inside `analytics-sandbox/`.

You may **read** any file in the repository for reference. You may not **write** to anything outside `analytics-sandbox/`.

### Forbidden write paths during analytics work

- `app/**`
- `routes/**`
- `resources/**` *(except `analytics-sandbox/views/`)*
- `database/**`
- `config/**`
- `bootstrap/**`
- `public/**`
- Repo root files: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `artisan`, `.env*`, `phpunit.xml`, this file, etc.

If the user asks you to modify any forbidden path, **refuse and reply**:

> "That file is outside the analytics sandbox. Ask the repo owner (Shabib) to make this change — I can prepare the diff for him to apply."

A `pre-commit` git hook will also reject any commit on the `analytics-sandbox*` branch that touches non-sandbox files. Don't try to bypass it.

---

## Required reading before writing any analytics query

1. `analytics-sandbox/README.md` — folder layout + workflow.
2. `analytics-sandbox/CONTEXT.md` — schema, table names, business glossary.
3. `analytics-sandbox/LEARNINGS.md` — past corrections; never reproduce these mistakes.

---

## Database safety

- The sandbox runs against the **production database** (read-only DB user expected, but assume nothing).
- **Never** write `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`, `TRUNCATE`, `CREATE`, `REPLACE`, `GRANT`, or `REVOKE` in any SQL file under `analytics-sandbox/queries/`. SELECT only.
- The base controller `AnalyticsSandbox\Controllers\SandboxController::runQueryFile()` will reject query files that contain destructive keywords. Do not depend on this guard — write read-only SQL by default.

---

## Coding conventions inside `analytics-sandbox/`

- Every Blade view in `analytics-sandbox/views/` must `@extends('layouts.app')` so the sandbox uses the real app sidebar, header, and auth context.
- Raw SQL goes in `analytics-sandbox/queries/<page>-<metric>.sql`, **not** inline in controller methods. Controllers call `$this->runQueryFile('...')`.
- Every new dashboard ships with a corresponding `analytics-sandbox/handoffs/HANDOFF-<page>.md` (copy `analytics-sandbox/HANDOFF.template.md`) listing every business-rule assumption you made.
- When the repo owner corrects a business rule, add a one-line entry to the top of `analytics-sandbox/LEARNINGS.md`.

---

## Branch + commit hygiene

- Analytics dashboard work goes on a branch named `analytics-sandbox` or `analytics-sandbox/<feature>`.
- The pre-commit hook on those branches refuses any commit that touches files outside `analytics-sandbox/`. Resolve by unstaging the offending file (`git restore --staged <file>`) and asking the repo owner to make the change on `main` separately.
- Do not run `git commit --no-verify` to bypass the hook. If you genuinely need a non-sandbox change, ask the repo owner.

---

## When in doubt

Ask the repo owner. Don't guess business rules — every guess becomes a checkbox in `HANDOFF-<page>.md` for him to answer.
