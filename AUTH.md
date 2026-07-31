# GUGU Auth — Identity guide

## What you get

| Feature | How it works in GUGU |
|---------|----------------------|
| **Phone OTP** | Primary login: phone → SMS OTP → session |
| **Password backup** | Still available (“Use password”) + Quick Gabby login |
| **Public nickname** | Shown as `Nickname • District` (e.g. `Gabby • Gasabo`) |
| **Private real name** | Stored as `full_name` — not shown on listings/public profile |
| **Email backup** | Optional recovery email on profile setup |
| **GPS verify** | Browser GPS saved; re-verify every **30 days** |
| **Trust score** | Community trust shown on profile |

## Localhost setup

1. Open **http://localhost/gugu-app/setup.php** (adds OTP table + identity columns).
2. Open **http://localhost/gugu-app/** → **Injira / Log in**.
3. Enter phone → **Send OTP**.
4. In **DEV mode**, the 6-digit code is shown on screen (and in the API as `dev_otp`).
5. First-time users set **nickname + district** (+ optional legal name & email).
6. Allow **GPS** (or skip for later).

## API endpoints

| Action | Method | Body |
|--------|--------|------|
| `/api/auth.php?action=send-otp` | POST | `{ "phone": "078..." }` |
| `/api/auth.php?action=verify-otp` | POST | `{ "phone", "code" }` |
| `/api/auth.php?action=complete-profile` | POST | `{ "nickname", "real_name?", "email?", "province", "district" }` + Bearer |
| `/api/auth.php?action=verify-location` | POST | `{ "lat", "lng" }` + Bearer |
| `/api/auth.php?action=login` | POST | `{ "phone", "password" }` (backup) |

## Production SMS

In `config/database.php`:

```php
define('OTP_DEV_MODE', false);
define('SMS_API_KEY', 'your-key'); // Africa's Talking / Twilio / SMS.rw
```

Then implement the send call inside `sendSmsOtp()` in `includes/helpers.php`.

## Display rule

**Public:** `display_name` = nickname • sector/district + trust score  
**Private (self only):** full_name, email, phone, GPS coordinates
