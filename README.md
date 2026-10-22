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
Edit `includes/config.php` to change DB settings:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // Set your MySQL password if any
define('DB_NAME', 'ceit_tracker');
define('BASE_URL', 'http://localhost/ceit_tracker');
```

---

## 📝 Notes
- Uploaded files are stored in `/uploads/` folder (max 10MB per file)
- Allowed file types: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, PNG, TXT, ZIP
- The system uses PHP sessions — make sure sessions are enabled in php.ini
- For production: use HTTPS and store passwords with bcrypt hashing
