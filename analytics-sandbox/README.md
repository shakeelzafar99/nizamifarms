# Analytics Sandbox

A walled-off play area inside the Nizami Farms Laravel app where dashboard prototypes can be built against **real data, real auth, and the real layout** without risking any operational code.

This folder is the **only** place a teammate (or their AI agent) is allowed to create or modify files when working on analytics dashboards.

---

## Who is this for?

- **The dashboard developer** (and his AI agent): builds prototypes here. Has full freedom to write Blade views, controllers, raw SQL queries, CSS, JS — as long as everything stays under `analytics-sandbox/`.
- **The repo owner** (Shabib): reviews what was built, fixes business logic / query optimisation, then promotes the prototype into the real app under `app/Services/Analytics/...` and `resources/views/pages/analytics/...`.

---

## Three walls keeping operational code safe

| Wall | What it does |
|------|--------------|
| `.cursor/rules/analytics-sandbox.mdc` | Tells any Cursor agent in this repo: *"only edit files under `analytics-sandbox/`."* Catches mistakes at edit time. |
| `.githooks/pre-commit` | Blocks commits on the `analytics-sandbox*` branch that touch anything outside this folder. Catches mistakes at commit time. |
| Branch policy | The dashboard developer always works on a branch named `analytics-sandbox` (or `analytics-sandbox/<feature>`). `main` is owner-controlled. |

---

## One-time setup (every clone)

After `git clone`, run **once** from the repo root:

```powershell
# 1. Wire the pre-commit hook
.\.githooks\install.ps1

# 2. Pick up the new namespace
composer dump-autoload

# 3. Enable the sandbox in your local .env
#    (paste this line into .env if not present)
ANALYTICS_SANDBOX_ENABLED=true

# 4. Move to the sandbox branch
git checkout analytics-sandbox
```

After that, `php artisan serve` and visit **`/sandbox`** — you'll see the sandbox landing page inside the real Nizami Farms layout (sidebar, header, auth, etc.), and an example **Qurbani** prototype to copy from.

The sidebar will also show an "Analytics Sandbox" entry under the existing menu — gated by `ANALYTICS_SANDBOX_ENABLED=true`, so it never appears in production unless explicitly enabled.

---

## What's in here

```
analytics-sandbox/
├── README.md                ← this file
├── CONTEXT.md               ← schema primer + business glossary (READ FIRST)
├── HANDOFF.template.md      ← copy this per-dashboard, fill it in
├── LEARNINGS.md             ← growing notebook of fixes + business rules
│
├── routes.php               ← all sandbox routes registered here
├── Controllers/
│   ├── SandboxController.php           ← shared base — gives you Auth::user() etc.
│   └── QurbaniSandboxController.php    ← worked example
│
├── views/
│   ├── index.blade.php       ← sandbox landing
│   └── qurbani.blade.php     ← worked example dashboard
│
├── queries/
│   └── qurbani-cohorts.example.sql  ← raw SQL, easy to review
│
└── handoffs/                 ← one HANDOFF-<feature>.md per dashboard you ship
```

---

## Workflow

### For the dashboard developer

1. `git checkout analytics-sandbox`
2. **Read `CONTEXT.md`** — schema, business glossary, things to NOT assume.
3. **Read `LEARNINGS.md`** — past corrections; never repeat those mistakes.
4. Pick a dashboard from the Vercel prototype (`nizamifarms.vercel.app/qurbani`) and replicate it inside `views/<page>.blade.php`, with raw SQL in `queries/<page>-<metric>.sql` and controller logic in `Controllers/<Page>SandboxController.php`.
5. Copy `HANDOFF.template.md` → `handoffs/HANDOFF-<page>.md` and fill it in honestly. Every business assumption you made → checkbox in that file.
6. Commit and push:
   ```bash
   git add analytics-sandbox/
   git commit -m "sandbox: <page> first pass"
   git push origin analytics-sandbox
   ```
7. The pre-commit hook will reject the commit if it includes any file outside `analytics-sandbox/`. That's expected and protective.

### For the repo owner (review + promote)

1. `git checkout analytics-sandbox && git pull`
2. Visit `/sandbox/<page>` locally to confirm it renders against real data.
3. Walk through the new `handoffs/HANDOFF-<page>.md` — answer every checkbox.
4. With the AI assistant, port the prototype into the real app:
   - Queries → `app/Services/Analytics/<Page>AnalyticsService.php` (apply business rules, optimise, cache).
   - View → `resources/views/pages/analytics/<page>.blade.php`.
   - Routes → `routes/web.php` under the existing `auth` middleware group.
5. Commit any **business-rule fixes that the developer should have known** back into the sandbox version (so the developer's AI sees the corrected pattern next time) and add a one-liner to `LEARNINGS.md`.
6. Merge nothing from `analytics-sandbox` into `main` other than:
   - The integrated production code (new files in `app/`, `resources/views/pages/`, `routes/`).
   - The updated sandbox files containing the lessons-learned versions.
   - The updated `LEARNINGS.md`.

---

## Hard rules for anyone working here

1. **Read-only DB access.** The sandbox should run with a MySQL user that has `SELECT` only. Never write `INSERT / UPDATE / DELETE / ALTER / DROP / TRUNCATE / CREATE` against any production table.
2. **Never edit anything outside `analytics-sandbox/`.** If you think you need to (a new permission, a config tweak, a model fix), stop and ask the repo owner — they'll do it.
3. **Always extend `layouts.app`** in your views so the sandbox feels native (sidebar, header, auth context all appear automatically).
4. **All raw SQL goes in `queries/*.sql`** so it's reviewable as plain SQL. Don't bury complex queries inside controller methods.
5. **Update `LEARNINGS.md`** every time the repo owner corrects a business rule. One bullet per correction, max two lines.
