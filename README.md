# GUGU App — Gura & Gurisha

Local marketplace for **Rwanda**: buy (**Gura**) and sell (**Gurisha**) with people in your **Akarere** and **Umurenge**.

## Single source of truth

**All Gura & Gurisha code lives only here:**

`C:\xampp\GUGU App`

It is **not** stored under `htdocs`. XAMPP Apache serves this folder via Alias:

| URL | Points to |
|-----|-----------|
| http://localhost/gugu-app/ | `C:\xampp\GUGU App` |
| http://localhost/gugu-app/app/ | Member marketplace (React) |
| http://localhost/gugu-app/admin/ | System Administrator / staff portal |
| http://localhost/gugu-app/api/ | PHP API |

Apache config: `C:\xampp\apache\conf\extra\httpd-gugu-app.conf`

## Features

- **Local marketplace** — Browse and sell across all 30 districts of Rwanda
- **Quick listing** — Photos and details in under a minute
- **In-app chat** — Negotiate and arrange meet-ups
- **Trust score** — Community reputation on every profile
- **Favorites** — Save items you like
- **Categories** — Electronics, Furniture, Fashion, Vehicles, Real Estate, Jobs, and more
- **Ubuntu** — Give items away for free
- **RW / EN / FR** — Kinyarwanda, English, and French
- **Location filter** — Province, district (Akarere), and sector (Umurenge)

## Requirements

- XAMPP (Apache + MySQL + PHP 8.0+)
- Modern web browser

## Installation

1. Start **Apache** and **MySQL** in XAMPP Control Panel.
2. Confirm Alias file exists: `apache\conf\extra\httpd-gugu-app.conf` (included from `httpd.conf`).
3. Open setup if needed: http://localhost/gugu-app/setup.php
4. Open the app: http://localhost/gugu-app/app/

### Demo login (staff)

| Role | Phone | Password |
|------|-------|----------|
| System Administrator | `0781111111` | `Admin@gugu.rw` |
| District Manager | `0782222222` | `Manager@gugu.rw` |
| Moderator / Support | `0783333333` | `Support@gugu.rw` |
| Member | `0784444444` | OTP / member flow |

## Database

- MySQL database: **`GUGUapDB`**
- Config: `config/database.php`
- Shared by Member app, Admin portal, and PHP API

## Project layout

```
GUGU App/
  admin/          Staff portals (System Admin, District, Support)
  api/            PHP REST API
  config/         DB + app settings
  database/       Schema / SQL
  frontend/       React source (build → public/app/)
  includes/       Shared PHP helpers
  public/         Built web assets + uploads
```

## Phone numbers

Rwanda mobile: `+2507XXXXXXXX`

- MTN: `078`, `079`
- Airtel: `072`, `073`

---

**GUGU App** — Gura no kugurisha mu Rwanda.
