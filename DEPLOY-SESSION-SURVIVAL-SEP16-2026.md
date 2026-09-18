# DEPLOY — 🔐 A session ends only when the SERVER says so (16-Sep-2026)

**NO SQL. WEB UNCHANGED — this is an APK-only round.** No new permission, no route change, no
`/xclean` needed for this fix on its own.

## Files (mobile only)
```
src/services/api.js                     changed   isAuthRejection + refresh outcomes + interceptor
src/services/authService.js             changed   login() message/retry rule + autoLogin() cleanup
__tests__/authSessionSurvival.test.js   NEW       14 tests, 3 of which fail against the old code
```

## The incident it fixes
Farooq was signed out mid-shift at **01:20 PKT on 16-Sep** and then could not log back in.
The production access log settled it:

- his phone made **1,328 requests that evening, every one HTTP 200** — not a single rejection;
- at **01:20:23** it did a fresh login (200), worked 36 seconds, and at **01:20:59 stopped
  reaching the server entirely** — no request from it for the remaining ~9 hours of the log;
- that login is the **only** `/api/auth/authenticate` POST in the whole 251,926-line, 4-day file,
  so **none of his retries ever arrived**;
- 17 other phones worked normally throughout, including several that took the new APK;
- the served APK has the correct production URL compiled in (verified inside the JS bundle);
- and there is **no bot-filter signature anywhere**: all 6,727 401s are exactly 30 bytes
  (Laravel's own JSON), the 403s are 43–74 bytes, the 419s are Laravel's page. ⚠ An earlier
  reading of this as StackProtect was WRONG and was corrected before any code was written.

So the server never refused him. **The app refused him**, because every kind of failure looked
identical to it.

## The rule now
⚠⚠ **A user is signed out ONLY when the server explicitly refused his identity — a 401/403
carrying our API's own JSON.** Everything else (no response at all, a 5xx, an HTML page from
anything sitting in front of Laravel, a captive portal) means WE could not ask, and must cost him
nothing. One predicate, `isAuthRejection()`, exported from `api.js` so the interceptor and
`authService` cannot drift apart.

| What happened | Before | Now |
|---|---|---|
| server refuses the refresh (401 JSON) | sign out | sign out (unchanged) |
| server unreachable | **sign out** | session kept, request fails as transient |
| refresh hits a 5xx | **sign out** | session kept |
| 401 whose body is an HTML page | **sign out** | session kept |
| auto-login fails, server unreachable/5xx/HTML | **saved password DELETED** | password AND token kept |
| login screen message for the above | "Login Failed" (reads as wrong password) | "Could not reach the server…" and it retries |

⭐ The token is now kept on a transient auto-login failure too — it may still be perfectly valid,
and the next request will say so. Throwing it away guaranteed a login screen.

⚠ `refreshToken()` no longer returns a bare boolean; it returns `REFRESH_OK` /
`REFRESH_AUTH_FAILED` / `REFRESH_UNAVAILABLE`. It has exactly one caller (the interceptor) —
keep it that way, and never reduce it back to a boolean: the boolean IS the bug.

## Proof
`__tests__/authSessionSurvival.test.js` **14/14**. Verified honestly by reverting both halves of
the fix: **3 of the 14 fail against the old code**, then pass again. Full mobile suite
**464/464** (`--runInBand`), eslint 0 errors on the changed files.
⚠ `__tests__/App.test.tsx` still fails to load at baseline (a react-native-gesture-handler native
import); it contributes 0 tests and is untouched by this round.

## Still open, separately
- **Farooq's handset** — the log proves nothing of his reached the server for ~9 hours. Check the
  site in Chrome ON his phone, his installed version, and where his APK came from. This fix stops
  the app compounding such a problem; it cannot fix the connectivity itself.
- **The server does not log failed logins**, which is why this had to be diagnosed by inference.
  Worth adding; not in this round.
