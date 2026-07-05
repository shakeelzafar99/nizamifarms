# Rider Reports — deploy guide (WEB, real-time)

The manager-facing **⚠ Issues** tab + per-rider **Report Card** on the Riders Map page.

**Architecture: fully real-time.** Every time the report is opened it is computed live from the
source tables (60-second cache) — there is **no scheduled job, no stored snapshot tables, nothing to
refresh, and no staleness/inconsistency**. Only the last **7 days** are offered (config value), which
keeps it fast and stays inside the GPS-trail retention window. Reads only existing tables; adds no
write path to anything live.

---

## 1. SQL to run

**a) Permission grant** — `database/migrations/grant_rider_reports_permission_jul2026.sql`
Grants the web `view_rider_reports` permission to every active **admin-type role** + **supervisor 2**
(Manager, Management, Taimur, Adnan, Shabib, expense-fund, supervisor 2). Idempotent. Run on DEV + PROD.
(Already run on DEV.) To change who sees it later, use **Roles → Permissions**.

**b) Cleanup (only if needed)** — `database/migrations/drop_rider_report_facts_jul2026.sql`
Removes the two fact tables from the earlier draft. Run it **only if** you already created them; if you
never created them, skip. (Already dropped on DEV.)

## 2. Files to upload

| File | New / changed |
|------|---------------|
| `config/rider_reports.php` | new — thresholds (500 m pin, 7-day window, stop/late rules) |
| `app/Services/Riders/RiderDayReportService.php` | new — the real-time compute engine (auto-detects the dev/prod timezone) |
| `app/Http/Controllers/CRM/RiderReportsController.php` | new — the read API (permission-gated, real-time, 7-day cap) |
| `resources/views/pages/riders-map/index.blade.php` | changed — ⚠ Issues tab, Report Card, verified-vs-pressed map, drill-down |
| `app/Http/Controllers/CRM/OrderController.php` | changed — passes the permission flag to the view |
| `routes/web.php` | changed — one new `.../reports` route |
| `routes/console.php` | changed — **no** rider-reports schedule (real-time; nothing added) |
| `app/Http/Controllers/API/RiderController.php` | changed — "at verified" unified to the 500 m config value (2 spots) |

Then:

```
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## 3. Using it

On the **Riders Map** page, users with the permission see a **⚠ Issues** tab:

- **Issues view** (default): only riders with a problem that day — late > 15 min, delivered > 500 m
  from the verified pin, no pin saved, GPS off at delivery, an on-route stop with orders still on
  board, or an odd route. Red riders first; clean riders and office accounts hidden. Customer name
  leads each order. **🗺️ verified vs pressed** opens a two-point map; **🚀 dispatch detail** jumps to
  that rider in the Dispatch Tracker for the same day.
- **"Show all riders"** toggle → the per-rider **Report Card**.
- Date picker + Prev/Next, limited to the last 7 days. Always live.

## Tuning

`config/rider_reports.php` holds every number (500 m pin rule, 7-day window, 10/15-min late, 10-min
stop, GPS quality gates). Change + `config:clear`. No code edit.

## Reversing

Restore the 5 changed files, delete `RiderReportsController.php`, `RiderDayReportService.php`,
`config/rider_reports.php`, and (optionally) `DELETE FROM t_sys_role_permissions WHERE
permission_key='view_rider_reports';`. Nothing else is touched.

---
---

# Rider Reports — MOBILE deploy (⚠ Daily Issues screen)

Same real-time report on the app, in the SideMenu (Store mode), gated by a **mobile** permission.

## 1. SQL (DEV + PROD)

`database/migrations/add_rider_reports_mobile_permission_jul2026.sql` — adds the `view_rider_reports`
**mobile** permission and grants it to admin-type roles + supervisor 2. Idempotent. (Already run on DEV.)
Afterwards you can toggle it per role at **Roles → Mobile Permissions**.

## 2. Web-side files (upload with the web deploy above)

| File | Change |
|------|--------|
| `app/Http/Controllers/CRM/RiderReportsController.php` | `apiIndex()` added — mobile endpoint (gated by `hasMobilePermission`) |
| `routes/api.php` | new `GET /rider/store/rider-reports` route |

Then `php artisan route:clear`.

## 3. Mobile app files (need an APK rebuild to ship)

| File | Change |
|------|--------|
| `src/screens/RiderIssuesScreen.js` | new — the ⚠ Daily Issues screen (real-time, map modal, dispatch-detail) |
| `src/navigation/index.js` | registers the `RiderIssues` screen |
| `src/components/SideMenu.js` | adds the "Daily Issues" menu item, gated by `hasPermission('view_rider_reports')` |
| `src/screens/DispatchTrackerScreen.js` | reads an optional `route.params.date` so "dispatch detail" opens the right day (defaults to today) |

Build + distribute the APK as usual (`build-production-apk-auto.bat`).

## 4. Using it (mobile)

Store mode → side menu → **⚠ Daily Issues** (only visible to permitted roles). Same content as web:
problems-only per rider, date picker (last 7 days), "🗺️ verified vs pressed" map, "🚀 dispatch
detail" opens the Dispatch Tracker for that day.

## Later

- **Report Card on mobile** (the web "Show all riders" view) — easy add to the Issues screen if wanted.
- **Checkout hook**: on checkout, auto-open the day's Issues for roles with access so a manager
  verifies before leaving.
