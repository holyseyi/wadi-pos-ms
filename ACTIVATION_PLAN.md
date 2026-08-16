# Silent-Trial + Permanent Activation System — Implementation Plan

## 1. Executive Summary
DziePOSMS previously used a dual-mode activation system (permanent license code
`Godloveis4all!`, trial-reset code `Ten12TechActivate`, visible 7-day trial).
This build replaces all of that with:

1. **A silent 14-day trial** that starts whenever this build is installed — over
   any previous version (activated or not) or on a fresh install.
2. **A single activation code** that **permanently ends the trial**:

```
LreXO_-S,L#Lp75xK2YF
```

The code does **not** grant a new trial period and never displays a countdown.
The trial itself is completely invisible to the user: the app shows
"Licensed — All Features Unlocked" the whole time. When the 14 days elapse the
app locks; entering the code ends the trial forever and unlocks all features
permanently.

## 2. Requirements

### 2.1 Silent 14-day trial on install
- Every install of this build — fresh, or over any previous version (activated
  or not) — **silently starts a 14-day trial** from the first run.
- The user is never notified; no countdown timer is ever shown; the app appears
  fully licensed during the trial.
- When the 14 days elapse, the app locks and the login page requires the code.
- Reinstalling/updating this same build again does **not** restart the trial
  (guarded by the `activation_scheme = v2` marker).

### 2.2 Single activation code — permanently ends the trial
- **Code**: `LreXO_-S,L#Lp75xK2YF` (defined as `ACTIVATION_CODE`).
- **Behavior**: Entering it ends the running 14-day trial permanently. The app
  becomes truly licensed with **no deadline** and never locks again. It does
  **not** grant another 14 days.
- **Old codes**: `Godloveis4all!` and `Ten12TechActivate` no longer work and are
  logged as invalid attempts.

### 2.3 Override of prior activation state
- The first time this build runs on a database left behind by an older version,
  every previous activation setting (`app_activated`, `license_type`,
  `license_activated_at`, `trial_started_at`, `trial_reset_count`,
  `last_trial_reset_at`) is deleted.
- A fresh silent 14-day trial starts from that moment.
- A database that has already been migrated to this build (scheme v2) keeps its
  state on reinstall: a permanently-activated customer stays activated.

## 3. Architecture

### 3.1 Settings keys (`settings` table)
| Key | Meaning | Default |
|---|---|---|
| `activation_scheme` | Marker that this build has taken over the DB | `v2` (set on first run) |
| `activation_started_at` | Unix timestamp the silent 14-day trial started | now (set on first run) |
| `activation_period_minutes` | Trial length, in minutes | `20160` (= 14 days) |
| `app_activated` | Set to `1` once the code has ended the trial permanently | — |
| `license_type` / `license_activated_at` | Permanent-license metadata (audit) | — |

Legacy `trial_*` / old `license_*` keys are removed on upgrade.

### 3.2 Server-side logic (`inc/functions.php`)
| Function | Change |
|---|---|
| `activate_app(string $code)` | Only accepts `ACTIVATION_CODE`; **ends the trial permanently** (`app_activated = 1`). |
| `is_permanently_licensed()` | True when `app_activated = 1` (trial ended, no deadline). |
| `is_app_activated()` | True while the silent trial runs **or** the trial was ended permanently (keeps the UI on "Licensed" with no countdown). |
| `get_trial_status()` | `activated` when permanently licensed; `trial` / `expired` otherwise. |
| `get_activation_started_at()` / `get_activation_period_seconds()` / `get_activation_deadline()` | Silent-trial clock helpers (default 14 days). |
| `initialize_database()` | On missing/old `activation_scheme`, wipes prior activation state and starts a silent 14-day trial. |
| `check_app_access()` | Unchanged — redirects to login once the trial is expired (and not yet ended by the code). |

### 3.3 API endpoint (`activate.php`)
Accepts JSON `{ "code": "..." }`. Returns `{ success, message, type, trial }`
with `type: "permanent"`.

### 3.4 UI
| Page | Change |
|---|---|
| `login.php` | Normal login with **no countdown** while the trial runs or the app is licensed. When locked: "Activation Required" form for the code. |
| `sales.php` | "Licensed — All Features Unlocked" banner in both states (silent trial / permanent) — no countdown. |

## 4. Security Considerations
1. The code is hardcoded in the PHP source / distributed binary — acceptable for
   a locally deployed POS.
2. Every attempt is logged in `activation_log` (code, action, IP, timestamp).
3. The trial deadline is enforced server-side on every request
   (`check_app_access`), so the client cannot hide the timer or extend it.

## 5. Deployment & Migration
1. `initialize_database()` runs the scheme-v2 upgrade automatically on first
   launch — no manual steps.
2. Business data (products, orders, receipts, users) is untouched by the upgrade.
3. Setting `activation_period_minutes` (e.g. to `1`) allows short test trials.

## 6. Testing Checklist
- [ ] Fresh install → silent 14-day trial starts; app works, "Licensed" banner, no countdown.
- [ ] Fresh install after 14 days (or with `activation_period_minutes=1`) → app locks, login shows "Activation Required".
- [ ] Enter `LreXO_-S,L#Lp75xK2YF` → trial ends permanently; app stays unlocked with no deadline.
- [ ] After the code is entered, waiting past any deadline never locks the app again.
- [ ] Entering the code while the trial is still running → trial ends immediately, no countdown appears.
- [ ] Old codes `Godloveis4all!` and `Ten12TechActivate` → rejected, logged as invalid.
- [ ] Install over an old DB that had `app_activated=1` (permanent license) → old license ignored, silent 14-day trial starts fresh.
- [ ] Install over an old DB on trial → trial state ignored, silent 14-day trial starts fresh.
- [ ] Reinstall this build over itself → trial is NOT restarted, permanent activation survives.
- [ ] Expired install + login attempt → session cleared, redirected to activation screen.
