# GUGU App — System Architecture

**Gura & Gurisha** — Rwanda’s local marketplace for phones, Akarere/Umurenge, and neighborhood meetups.

---

## 1. Source of truth

| Rule | Detail |
|------|--------|
| Code folder | **`C:\xampp\GUGU App` only** |
| Not in htdocs | Do **not** copy the project into `C:\xampp\htdocs\` |
| How it is served | Apache **Alias** `/gugu-app` → `C:/xampp/GUGU App` |
| Alias config | `C:\xampp\apache\conf\extra\httpd-gugu-app.conf` |

Member marketplace, Admin portal, and API all read/write the same MySQL database **`GUGUapDB`**.

---

## 2. What GUGU does

| Feature | GUGU |
|---------|------|
| Local trading | Trade by **Akarere** (district) + **Umurenge** (sector) |
| Buy side | **Gura** — browse items & jobs |
| Sell side | **Gurisha** — post listings (fee + Admin approval) |
| Web app UI | React web UI → `public/app/` |
| Trust | **Trust score** on every user |
| Phone-first | Rwanda phone auth (`078` / `079` / `072` / `073`) |
| Backend | PHP REST (`api/*.php`) |

---

## 3. Live stack (localhost / XAMPP)

| Layer | Technology | Path |
|-------|------------|------|
| Frontend | React + TypeScript | `frontend/` → build `public/app/` |
| Backend | PHP REST | `api/*.php` |
| Admin | PHP portals | `admin/` |
| Database | MySQL `GUGUapDB` | `config/database.php` |
| Auth | Phone OTP + staff password | `sessions` + PHP session for admin |

**Localhost URLs**

- App: http://localhost/gugu-app/app/
- Admin: http://localhost/gugu-app/admin/dashboard.php
- API: http://localhost/gugu-app/api/

---

## 4. Rwanda product rules

1. **Location = Akarere + Umurenge**
2. **Phone OTP first** for members — staff use phone + password
3. **Nickname public, legal name private**
4. **GPS re-verify** about every 30 days
5. **Trust score** on profiles
6. **Ubuntu** — free giveaways
7. **Announce fee** — MoMo then Admin Mark paid → Approve

---

## 5. Roles (same DB)

| role_id | Role | Portal |
|---------|------|--------|
| 1 | System Administrator | System Control Center |
| 2 | District Manager | District Operations Hub |
| 3 | Moderator / Support | Trust & Safety Desk |
| 4 | Member | Marketplace app |

---

**GUGU** is Rwanda’s own local marketplace — Gura & Gurisha — buy and sell safely by Akarere and Umurenge.
