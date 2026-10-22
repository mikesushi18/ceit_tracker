# SETUP GUIDE — Google Sign-In + OTP Password Change (CollTrace)

Sundin ang mga steps na ito para gumana ang bagong features sa system mo.

## 1. I-run ang database migration

Buksan ang **phpMyAdmin** → piliin ang `ceit_tracker` database → tab na **SQL** →
i-paste at i-run ang laman ng `migration_google_otp.sql` (nasa root ng project).

Ito ang magdadagdag ng:
- `email` at `google_id` column sa `users` table
- bagong `otps` table

## 2. Kumuha ng Google Client ID (para sa "Sign in with Google")

1. Pumunta sa https://console.cloud.google.com/
2. Gumawa ng bagong project (o gamitin ang existing)
3. **APIs & Services → OAuth consent screen** → i-configure (External, ilagay pangalan ng app)
4. **APIs & Services → Credentials → Create Credentials → OAuth client ID**
5. Application type: **Web application**
6. Sa **Authorized JavaScript origins**, idagdag: `http://localhost:8080`
   (o kung anong URL talaga ang gamit mo sa XAMPP/Laragon)
7. Kopyahin ang **Client ID** (may `.apps.googleusercontent.com`)
8. I-paste sa `includes/config.php`:
   ```php
   define('GOOGLE_CLIENT_ID', 'PASTE-MO-DITO.apps.googleusercontent.com');
   ```

## 2.5. (Optional) I-adjust kung anong email domains ang tatanggapin

Sa `includes/config.php`, may nakalagay na:
```php
define('ALLOWED_EMAIL_DOMAINS', ['gmail.com', 'cvsu.edu.ph']);
```
Tinatanggap na ngayon ang parehong personal Gmail (`@gmail.com`) at school email
(`@cvsu.edu.ph`), dahil Google Workspace din pala ang likod ng CvSU email. Pwede kang
magdagdag pa ng ibang domain dito kung kailangan — basta't Google account din ang likod
niya, gagana pa rin ang "Sign in with Google" at OTP.

## 3. Kumuha ng Gmail App Password (para sa OTP emails)

1. I-on muna ang **2-Step Verification** sa Gmail account mo:
   https://myaccount.google.com/security
2. Pagkatapos, pumunta sa https://myaccount.google.com/apppasswords
3. Gumawa ng App Password (piliin "Mail" bilang app)
4. Kopyahin ang 16-character code na lalabas
5. I-paste sa `includes/config.php`:
   ```php
   define('SMTP_USER', 'yourrealaccount@gmail.com');
   define('SMTP_APP_PASSWORD', 'xxxx xxxx xxxx xxxx'); // yung app password, HINDI yung tunay na Gmail password mo
   ```

## 4. I-link ang Gmail ng bawat existing user

Dahil bago lang ang `email` column, wala pa itong laman para sa mga existing accounts.
Pumunta sa **Admin → Manage Users → Edit** ng bawat user at ilagay ang kanilang Gmail address
(kailangang `@gmail.com`), o i-update mismo sa phpMyAdmin:

```sql
UPDATE users SET email = 'jdelacruz@gmail.com' WHERE username = 'jdelacruz';
```

Kapag gumagawa ka na ng **bagong account** (Admin → Create Account), required na ngayon
ang Gmail field — awtomatiko na itong naka-link.

## 5. I-test

- **Local login** (username + password): gumagana pa rin gaya ng dati.
- **"Sign in with Google"**: lalabas ang button sa login page. Kapag pinili ng user ang
  Gmail account nila (dapat tugma sa `email` na naka-link sa account nila sa DB), direkta
  silang ma-lo-login — walang password na hihingin.
- **Nakalimutan ang password?**: click sa link sa ilalim ng login form → maglagay ng
  username → padadalhan ng OTP ang naka-link na Gmail → i-verify → maglagay ng bagong password.
- **Change Password habang naka-login**: makikita sa sidebar ng profile page — pareho ring
  flow (OTP muna bago mapalitan).

## 6. Google Drive Backup Storage

1. Sa Google Cloud Console, siguraduhing enabled ang **Google Drive API** at gumamit ng OAuth
   client na **Web application** type. Gamitin ang kaparehong OAuth Client ID na nakalagay na
   sa `includes/config.php`.
2. Buksan ang admin dashboard → **Backup Storage**. Kopyahin ang ipinakitang URI at idagdag
   ito sa OAuth client bilang **Authorized redirect URI**. Karaniwang local XAMPP URI:
   `http://localhost/ceit_tracker/admin/google_drive_oauth.php` (gamitin ang eksaktong URI
   na ipinapakita ng page kung iba ang host o port).
3. Ilagay ang OAuth Client Secret sa file na ipinapakita sa Backup Storage page. Nasa labas
   ito ng `htdocs`; huwag ilagay ang secret sa public project files o ipadala sa chat.
   Huwag gamitin ang Gmail App Password.
4. I-enable ang PHP `curl` at `zip` extensions sa ginagamit na `php.ini`, pagkatapos ay
   i-restart ang Apache.
5. Sa Backup Storage, piliin ang **Sign in with Google** at mag-authorize gamit ang verified
   CvSU account. Naka-save nang lokal ang account at maaaring palitan mula sa page.

Ang manual backup at automatic backup bago maisara ang kasalukuyang school year ay nag-a-upload
ng individual submission files sa `CollTrace Backups` folder sa Google Drive, nakaayos ayon sa
school year, department, faculty/chairperson, at submission folder. Walang ZIP o database export
na ina-upload sa Drive. Sa Manual Backup, hiwalay ang **Back Up to Google Drive** at
**Create Local PDF ZIP**. Ang lokal na ZIP ay naglalaman lang ng faculty/chairperson PDF
submissions at magsisimulang i-download kapag natapos ang local backup. Maaaring i-cancel ang ZIP
transfer mula sa progress banner o sa browser download controls; may link din para muling i-download.
Tumatakbo ang backup sa background; makikita ang progress sa admin pages kahit magpalit ng page.
Para hindi paulit-ulit mag-upload ng parehong files, ginagamit ng Drive backup ang shortcuts para
sa unchanged files. Nakaayos ang ZIP ayon sa school year,
department, role, account, at submission folder; hindi kasama ang database, announcements, o
ibang posted system content. Kung walang admin page na bukas habang nagtatapos ang backup,
makikita pa rin ang ZIP download link sa Recent Backups.
Kung naka-Testing ang external OAuth consent screen, maaaring mag-expire ang Google refresh token
pagkalipas ng ilang araw; kapag nangyari ito, i-connect muli ang account o i-publish/i-configure
ang consent screen ayon sa CvSU Workspace policy.

## Faculty File Comments and Checklist Tasks

Admins and department chairpersons can add a checklist task to a faculty file from its folder's
file list. Faculty can open **Notifications** from the dashboard or a submission page to read tasks
and mark them done; completed task text is struck through. Faculty must enter a reply before using
**Reply & mark done**; the reply and task completion are saved together and the reply appears under
the original remark, notifying its author. The task
creator, all admins, and the chairperson for that department receive a completion notification.
When faculty replaces a file that has a comment, the comment's author receives a notification
linking directly to that file and its remarks. The `file_feedback` and
`file_feedback_notifications` tables are created automatically when the feature is first opened
and are also included in `database.sql`.

## Mga bagay na inayos ko rin habang ginagawa ito (security)

1. **Tinanggal ang plaintext password fallback** sa login — dating tinatanggap din ang
   di-naka-hash na password bilang valid; awtomatiko na ngayong na-a-upgrade sa bcrypt hash
   ang mga lumang plaintext password sa unang successful login.
2. **Na-secure ang `/uploads/` folder** — nagdagdag ako ng `.htaccess` doon na bumabawal
   mag-execute ng `.php` files sa loob ng folder na iyon (may nakita akong test `.php` file
   na naka-upload doon dati — hindi ito mapapatakbo/execute pero pwede mo pa ring i-check
   at tanggalin kung gusto mo). Importante ito dahil kung kahit sino makaka-upload ng
   `.php` file at ma-execute ito, kaya nilang kunin ang buong database mo.

## Mga file na binago/idinagdag

**Bago:**
- `includes/google_drive_backup.php`
- `admin/backup_storage.php`
- `admin/google_drive_oauth.php`
- `admin/backup_download.php`
- `migration_google_otp.sql`
- `google_login.php`
- `forgot_password.php`
- `includes/mailer.php`
- `includes/otp_helper.php`
- `includes/PHPMailer/` (3 files, hindi kailangan ng Composer)
- `uploads/.htaccess`

**Binago:**
- `admin/dashboard.php` (Backup Storage sidebar link)
- `admin/school_year.php` (automatic backup before closing the active school year)
- `SETUP_GUIDE.md` (Google Drive backup setup)
- `includes/config.php` (mga config constants + helper functions)
- `index.php` (Google Sign-In button, fixed password fallback, forgot-password link)
- `admin/create_user.php` (email field)
- `admin/edit_user.php` (email field)
- `profile.php` (Change Password link sa sidebar)
