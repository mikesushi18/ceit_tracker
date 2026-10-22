# CEIT CollTrace — File Tracking System
## PHP + XAMPP Setup Guide

---

## 📁 File Structure
```
ceit_tracker/
├── index.php              ← Login page
├── logout.php
├── database.sql           ← Run this first!
├── includes/
│   └── config.php         ← DB connection & session
├── admin/
│   ├── dashboard.php      ← Admin home (5 departments)
│   ├── department.php     ← Department folders + create folder
│   ├── folder_view.php    ← View files in a folder
│   ├── manage_users.php   ← View/deactivate/delete users
│   ├── create_user.php    ← Create chairperson/faculty accounts
│   └── activity_log.php   ← System activity log
├── chairperson/
│   ├── dashboard.php      ← Monitor faculty submissions (view only)
│   └── folder_view.php    ← View files in a folder
├── faculty/
│   ├── dashboard.php      ← See available folders + submission status
│   └── folder_submit.php  ← Upload files to a folder
└── uploads/               ← Uploaded files stored here (auto-created)
```

---

## 🚀 Installation Steps

### 1. Copy project files
Place the `ceit_tracker/` folder inside:
```
C:\xampp\htdocs\ceit_tracker\
```

### 2. Start XAMPP
- Start **Apache** and **MySQL** in XAMPP Control Panel

### 3. Create database
- Open browser → go to `http://localhost/phpmyadmin`
- Click **"New"** → name: `ceit_tracker` → Create
- Click **Import** tab → choose `database.sql` → Go

### 4. Open the system
```
http://localhost/ceit_tracker/
```

---

## 🔑 Default Login Accounts

| Role | Username | Password | Department |
|------|----------|----------|------------|
| Admin | `admin` | `admin123` | — |
| Chairperson | `chair_diet` | `chair123` | DIET |
| Faculty | `faculty1` | `faculty123` | DIET |
| Faculty | `faculty2` | `faculty123` | DIET |

> Create more accounts via Admin → Create Account

---

## 👥 Role Permissions Summary

### ADMIN
- View all 5 departments
- Create folders per department
- Create/deactivate/delete chairperson & faculty accounts
- View all submitted files
- View activity log

### CHAIRPERSON
- View only their department's folders
- Monitor which faculty have submitted files
- Cannot create folders or upload files

### FACULTY
- View folders in their department only
- Upload files to available folders
- Delete their own uploaded files
- Cannot see other departments or other faculty's files

---

## ⚙️ Configuration
Local development defaults are kept in `includes/config.php`. For deployment,
set the environment variables listed in [`.env.example`](./.env.example) in your
hosting provider's project settings. The application does not load `.env` files
itself; configure the variables in the process environment. Never commit real
credentials.

### Deploying to Vercel

The project includes a PHP function entry point and Vercel routes. Configure a
managed MySQL database and an S3-compatible object store (for example, Cloudflare
R2) before deploying. Vercel's local filesystem is temporary, so user-uploaded
files are stored in the private object bucket and PHP sessions are persisted in
the MySQL database.

1. Import the repository into Vercel and set the production `APP_BASE_URL` to
   the site's full HTTPS URL.
2. Add the database variables (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`,
   `DB_NAME`) using a database reachable from Vercel. `DB_HOST=localhost` will
   not work in production.
3. Create a private S3-compatible bucket and set `S3_ENDPOINT`, `S3_BUCKET`,
   `S3_REGION`, `S3_ACCESS_KEY`, and `S3_SECRET_KEY`.
4. Add `GOOGLE_CLIENT_ID`, `SMTP_USER`, and `SMTP_APP_PASSWORD` as Vercel
   environment variables. Set Google OAuth's authorized origin to the
   production site URL.
5. Import `database.sql` and the required migration SQL files into the hosted
   database before first use. The database account must be allowed to create
   the app session table and apply the app's existing schema compatibility
   changes.
6. Deploy. Existing XAMPP files under `uploads/` are not copied into object
   storage automatically; migrate files and their unchanged relative keys to
   the bucket before switching production traffic.

Vercel environment variables are configured in the project settings; `.env`
files are not loaded by the deployed application.

The Admin Google Drive backup workflow still stores OAuth tokens, job state,
and generated archives in local filesystem directories. It is not yet adapted
to Vercel's ephemeral function filesystem and should not be relied on in a
Vercel deployment.

---

## 📝 Notes
- For local XAMPP, uploaded files are stored in `/uploads/`; Vercel deployments
  require the configured S3-compatible object store (max 10MB per file)
- Allowed file types: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, PNG, TXT, ZIP
- The system uses PHP sessions — make sure sessions are enabled in php.ini
- For production: use HTTPS and store passwords with bcrypt hashing
