# GUGU App — System Architecture

**GuraCyangwaGurisha (3G Market)** — Rwanda’s local marketplace for phones, Akarere/Umurenge, and neighborhood meetups.

---

## 1. What GUGU does

| Feature | GUGU |
|---------|------|
| Local trading | Trade by **Akarere** (district) + **Umurenge** (sector) |
| Web app UI | React web UI |
| Navigation | Stack-style page slides |
| Trust | **Trust score** on every user |
| Phone-first | Rwanda phone auth (`078` / `079` / `072` / `073`) |
| Backend | PHP API (live) + optional Node microservices |

---

## 2. Live stack (localhost / XAMPP)

| Layer | Technology |
|-------|------------|
| Frontend | React + TypeScript → `public/app/` |
| Backend | PHP REST (`api/*.php`) |
| Database | MySQL `GUGUapDB` |
| Auth | Phone OTP + password backup, nickname, GPS verify |

**Localhost:** the React app talks to the **PHP API** under `http://localhost/gugu-app/`.

---

## 3. Rwanda product rules

1. **Location = Akarere + Umurenge**
2. **Phone OTP first** — password is backup
3. **Nickname public, legal name private**
4. **GPS re-verify** about every 30 days
5. **Trust score** on profiles
6. **Ubuntu** — free giveaways

---

**GUGU** is Rwanda’s own local marketplace for Abanyarwanda — buy and sell safely by Akarere and Umurenge.
