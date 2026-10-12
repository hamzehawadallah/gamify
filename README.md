# Gamify: live polls for your training sessions

Participants scan a QR code, type their name once, and vote from their phones.
You watch the results update live on the big screen, including inside PowerPoint.

- **Join in seconds.** Each activity has its own QR code and 6-digit code. There's nothing to install.
- **Names are remembered.** Participants type their name once. It's saved on their phone and reused for every future activity on your site.
- **Polls with images.** Single or multiple choice (with an optional limit), and a picture on any option.
- **Instant results.** New votes reach the presenter screen in well under a second.
- **Present anywhere.** The presenter view embeds in PowerPoint (Web Viewer add-in) and scales to any size, in light or dark.
- **You stay in control.** Open or close voting, hide results until the reveal, clear votes, see who voted for what, and export to CSV (Excel-friendly, Arabic included).
- **Designed to feel native.** Calm surfaces, crisp type, smooth motion, and automatic dark mode. Arabic and other right-to-left text works everywhere.

It is plain PHP + MySQL with no build step, so it runs on ordinary shared hosting such as Hostinger.

---

## Requirements

| | |
|---|---|
| Hosting | Any PHP host with MySQL/MariaDB. Hostinger Premium or Business shared hosting is perfect. |
| PHP | 8.0 or newer (8.2+ recommended), with the `pdo_mysql` extension. `gd` is recommended for image resizing. Hostinger has these on by default. |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| HTTPS | Required for PowerPoint embedding. Hostinger includes free SSL. |

---

## Deploy on Hostinger

### 1. Create the database

hPanel → **Databases → MySQL Databases** → create a database.
Write down the **database name**, **username** and **password**. Hostinger prefixes them, e.g. `u123456789_gamify`. The host is `localhost`.

### 2. Upload the code

**Option A: Git (recommended, makes updates one click)**

1. hPanel → **Websites → Dashboard → Advanced → Git**.
2. Repository: `https://github.com/mnabelsi/gamify.git`, Branch: the branch you want to deploy.
   - Private repository? Copy the SSH key Hostinger shows on that page into GitHub → repo **Settings → Deploy keys**, then use the SSH address `git@github.com:mnabelsi/gamify.git`.
3. Directory: leave empty to install at your domain root (`public_html` must be empty), or enter a folder such as `vote` to install at `yourdomain.com/vote`.
4. Click **Create**, then **Deploy**.
5. Optional: turn on **Auto Deployment** and add the webhook URL it gives you in GitHub → **Settings → Webhooks**. Every push then goes live automatically.

**Option B: File Manager.** Download the repository as a ZIP, upload it to `public_html` with hPanel's File Manager and extract it.

### 3. Turn on HTTPS

hPanel → **Security → SSL** → install the free certificate and enable **Force HTTPS**.

### 4. Run the setup wizard

Open your site. The **setup wizard** appears and asks for:

1. The database details from step 1.
2. An admin username and password for you.

It creates the tables, saves `config.php` and signs you in. That's it.

> Prefer to do it by hand? Copy `config.example.php` to `config.php`, fill in the values, and open the site.
> If the server doesn't let the wizard save `config.php`, it shows you the exact text to paste into a new file instead.

### 5. Create your first poll

Go to `yourdomain.com/admin` → **New poll** → add a question and options (tap the square to add a picture) → **Create poll**.
The poll page gives you the QR code (download it as PNG or SVG for your slides), the join link and the presenter link.

---

## Show live results in PowerPoint

1. In PowerPoint, go to **Insert → Get Add-ins** (or **Add-ins**), search for **Web Viewer** and add it.
2. Insert Web Viewer on a slide and paste the poll's **presenter link**. Copy it from the poll page in admin and pick Light or Dark to match your deck.
3. Start the slideshow. Votes appear live with no refresh.

The presenter link contains a private key, so only people you give it to can open the results screen.
The page scales to any slide size and can show the QR code next to the results. You can turn that off per poll.
You can also open the link in a browser window on the projector (press **F** or double-click for full screen), or share that window in Teams or Zoom.

During a session you control everything from the poll's admin page, e.g. on your laptop or phone.
**Close voting** or **Hide results** there, and the slide updates instantly.

---

## How "instant" works on shared hosting

Shared hosting doesn't allow WebSockets, so Gamify uses **long-polling**:

- The presenter screen asks the server "anything new since version 42?". The server holds the request (up to 20 seconds) and checks a single indexed number 4× per second.
- The moment a vote lands, the activity's version goes up and the waiting request answers immediately. The screen animates the new numbers and asks again.
- Phones don't keep connections open. They submit their vote and, if you allow results on phones, check for updates every few seconds with a tiny "nothing new" response.

Measured on a test server: a vote appears on the presenter screen in about 0.3 seconds after the vote request finishes (0.5 seconds from the tap), and 80 simultaneous voters were all counted correctly.
Each open presenter or admin screen holds one connection to the server. That's comfortably within Hostinger's limits for normal use (a handful of screens, hundreds of voters).

---

## Updating

Push changes to the deployed branch and click **Deploy** in hPanel's Git page (or let auto-deploy do it).
Your `config.php`, uploaded images and the database are never touched by a deploy.
Database changes in new versions are applied automatically on the first request.

---

## Configuration (`config.php`)

| Key | What it does |
|---|---|
| `app_name` | Name shown in the header and browser tab. |
| `db.host`, `db.port`, `db.name`, `db.user`, `db.pass` | Database connection. |
| `admin.username`, `admin.password` | Your sign-in. The password may be plain text or a `password_hash()` value (the wizard stores a hash). Changing it signs out every session. |
| `secret` | Random string used to sign login cookies. |
| `base_url` | Optional. Force the public URL used in QR codes, e.g. `https://vote.example.com`. Normally detected automatically. |
| `debug` | `true` shows detailed errors. Keep `false` on a live site. Errors are logged to `storage/error.log`. |

---

## Privacy & security

- Participants are identified by a random token stored in a cookie and in the browser. No accounts and no email addresses.
- The presenter sees only voters' **initials** on screen. Full names appear only on your admin page and in the CSV export.
- Admin pages use a signed, HTTP-only cookie, CSRF protection and sign-in rate limiting.
- Uploaded images are checked and re-encoded, and nothing in `uploads/` can execute.
- `.htaccess` blocks `app/`, `storage/`, `config.php` and hidden files such as `.git`.
- After setup, the wizard is disabled for good.

---

## Run it locally

```bash
# MySQL/MariaDB running locally, with an empty database
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 index.php
# open http://127.0.0.1:8080 and follow the setup wizard
```

`PHP_CLI_SERVER_WORKERS` lets the built-in server handle the live connection and normal requests at the same time.

---

## Project layout

```
index.php              Front controller (all requests come here via .htaccess)
config.example.php     Configuration template (config.php is created by the wizard)
app/
  bootstrap.php        Loads config + libraries
  routes.php           URL map
  lib/                 Database, auth, participants, activities, uploads, helpers
  handlers/            Page and API handlers (public, presenter, admin, setup)
  views/               HTML templates
assets/
  css/app.css          Design system (tokens, light/dark, components)
  js/                  core (helpers, live updates, QR), results, poll, participant,
                       present, admin, activity, editor, home
  vendor/qrcode.js     QR generator (MIT, Kazuhiko Arase)
uploads/               Uploaded option images
storage/               Logs and internal markers (not web-accessible)
```

## Roadmap

The data model and UI are built around activity **types**, so new ones slot in next to polls:

- **Open feedback / word cloud.** Short text answers that build a live word cloud.
- **Rating scales.** 1–5 stars or 0–10 sliders, with averages.
- **Quiz.** Correct answers, a countdown and a live leaderboard (this is where the *gamify* part shines).
- **Sessions.** Group activities for a training day so participants can move from one to the next.
