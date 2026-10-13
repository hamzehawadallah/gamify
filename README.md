# VCL Gamify: live voting activities for your training sessions

Participants scan a QR code, type their name once, and vote from their phones.
You watch the results update live on the big screen, including inside PowerPoint.

- **Join in seconds.** Each activity has its own QR code and 6-digit code. There's nothing to install.
- **Names are remembered.** Participants type their name once. It's saved on their phone and reused for every future activity on your site.
- **Personas.** Each participant picks a persona emoji from a scrolling row (people and characters only). No two people share one: everyone is offered a different free persona, held for them while they type their name, and personas in use aren't shown. The presenter screen shows them as people vote.
- **Activities with several questions.** One QR code per activity, holding one or more questions, like a toolbox voting wall with three sections. Each question has its own title, description and color.
- **Pictures on options.** Every question can be single or multiple choice (with an optional limit), with a picture on any option.
- **Rankings.** Turn any question into a ranking: a list of items, each with a picture, a color and a title. Participants drag the numbered cards into the order they think fits best, e.g. from *most important* to *least important*, and the presenter shows a live leaderboard.
- **Instant results.** New votes reach the presenter screen in well under a second.
- **Present anywhere.** The presenter view goes straight onto your slides with VCL Gamify's own free PowerPoint add-in. It scales to any size, in light or dark.
- **You stay in control.** Open or close voting, hide results until the reveal, clear votes, see who voted for what, and export to CSV (Excel-friendly, Arabic included).
- **Clean and light.** White surfaces, crisp type and the VCL colors, with an optional dark theme for the presenter screen. Arabic and other right-to-left text works everywhere.

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

> Run the wizard right after deploying. Until setup is finished, whoever opens the site first could complete it. Once `config.php` exists, the wizard is gone for good.

> Prefer to do it by hand? Copy `config.example.php` to `config.php`, fill in the values, and open the site.
> If the server doesn't let the wizard save `config.php`, it shows you the exact text to paste into a new file instead.

### 5. Create your first activity

Go to `yourdomain.com/admin` → **New activity**:

1. Give the activity a title and, if you like, instructions for participants.
2. Fill in the first question: a title, a short description, a color and its options. Tap the square next to an option to add a picture.
3. Click **Add question** for each extra section (e.g. *KYKO*, *Engaging Customer Dialogue*, *Positive Persistence & Learning*).
4. Want people to put things in order instead? Switch the question from **Vote** to **Ranking** (see below).
5. Click **Create activity**.

**Merging questions.** To combine two questions, click the merge button on the one to fold in (next to the delete button) and pick the question it goes into.
Its options move to the end of that question and it disappears. Options with the same name and picture are combined rather than listed twice.
Nothing is saved until you save, and the message that appears has an **Undo** button.
Already have votes? Merging one vote question into another keeps them: each option keeps its votes, combined options add theirs together, and someone who picked the same option in both questions counts once. Ranking places can't be combined, so merging a ranking (or into one) asks first and removes those answers when you save.

The activity page gives you the QR code (download it as PNG or SVG for your slides), the join link and the presenter link.
Participants scan once, answer each question in turn ("1 of 3", "2 of 3" …) and see a summary of their choices at the end.

---

## Show live results in PowerPoint (free add-in)

VCL Gamify comes with its **own PowerPoint add-in**, so you don't need a paid add-in from the store.
The add-in is a small file your site generates. It points PowerPoint at your own VCL Gamify website.

**1. Download it.** Open any activity in admin → **Present** → **Add to PowerPoint** → **Download the PowerPoint add-in**.
The file is also at `https://yourdomain.com/powerpoint/manifest.xml`.

**2. Install it once.** Pick the method that fits:

| Where | How |
|---|---|
| **PowerPoint for Windows** | Put the file in a folder and share it (folder → Properties → Sharing). In PowerPoint: **File → Options → Trust Center → Trust Center Settings → Trusted Add-in Catalogs**, add the folder's network path (e.g. `\\YOUR-PC\addins`), tick **Show in Menu** and restart PowerPoint. Then **Home → Add-ins → More Add-ins → Shared Folder** → **VCL Gamify Live Results**. |
| **PowerPoint for Mac** | Copy the file into `~/Library/Containers/com.microsoft.Powerpoint/Data/Documents/wef` (create the `wef` folder if it doesn't exist) and restart PowerPoint. It appears under **Home → Add-ins**. |
| **PowerPoint on the web** | **Home → Add-ins → More Add-ins → My Add-ins → Upload My Add-in** → choose the file. |
| **Your whole company** | A Microsoft 365 admin uploads the file once in the **Microsoft 365 admin center → Settings → Integrated apps → Upload custom apps**. It then shows up for everyone you assign, in PowerPoint for Windows, Mac and the web. It can take a few hours to appear. |

Installed the add-in before the rename (as "Gamify Live Results")? Download the file again and upload it over the old one. In the Microsoft 365 admin center, open the app under **Integrated apps** and choose **Update**. Version 1.1 shows the VCL Gamify name and logo.

**3. Use it on a slide.** **Home → Add-ins → VCL Gamify Live Results**. Paste the activity's **presenter link** (copy it from the activity page, after picking Light or Dark) and click **Show live results**. Resize the box to fill the space you want.
The slide remembers the link, and results update live during the slideshow. To switch activities, hover over the box in edit mode and click **Change link**.

Notes:
- Your site must use **https://**. Turn on the free SSL certificate in hPanel → Security → SSL.
- If your company blocks add-ins altogether, put the presenter link on the slide with **Insert → Link** (for example on a "See results" button) and click it during the slideshow. The live results open in your browser; Alt+Tab takes you back to the slides.
- You can also open the presenter link in a browser window on the projector (press **F** or double-click for full screen), or share that window in Teams or Zoom.

The presenter link contains a private key, so only people you give it to can open the results screen.
The page scales to any size and can show the QR code next to the results. You can turn that off per activity.
With several questions, the presenter shows them **side by side** in their colors, like a voting wall. To give a question its own slide, pick it under **Show** on the activity page; the link then ends in `&q=2`, for example.

During a session you control everything from the activity's admin page, e.g. on your laptop or phone.
**Close voting** or **Hide results** there, and the slide updates instantly.

---

## Rankings

Switch any question to **Ranking** in the editor. Then:

- **Items.** Each item has a title, an optional picture and a color. The number next to each item is its place in your list; tap it to pick another color. Drag the handle to reorder.
- **What the ends mean.** Above and below the items, say what the top and the bottom of the list mean. They start as *Most important* and *Least important*; change them to anything, e.g. *Most interactive* and *Least interactive*, or clear them.
- **Shuffle.** On by default: everyone starts from their own shuffled order, so nobody just keeps the order you typed. Turn it off to start everyone from your order.

On their phones, participants see numbered cards in the item colors with a grip on each one. The first time, a hand shows the gesture by moving a card down and back.
They press and drag a card (or grab its grip) to move it; the numbers update as they go and the page scrolls when they drag to the edge. The arrow keys work too.

The results are a leaderboard, best first, that rearranges itself live as rankings come in:

- **Average place.** Where people put the item on average (1.0 means everyone ranked it first). Items with the same average share a place.
- **The bar** shows the item's score: 100% if everyone put it first, 0% if everyone put it last.
- The CSV export lists each person's order (*1. Safety; 2. Speed; …*) and, per item, the average place, the score (0–100) and how often it was ranked first.

Rankings and votes can share one activity (one QR code). On the presenter wall, a ranking gets its own column next to the votes.

## How personas stay unique

- A persona belongs to one person at a time, across all your activities. It stays theirs while they've used the site in the last 12 hours.
- When someone opens the join page, the next free persona is reserved for them for 15 minutes, so two people scanning at the same moment get different ones.
- After 12 hours away, a persona can go to someone new, but only once all unused personas have been given out (the persona whose owner has been away longest goes first). If that happens, the earlier owner is asked to pick a new one next time.
- If every persona is in use, newcomers can still join; they show with their initials.
- People who joined before personas existed are asked to pick one the next time they open an activity.

The list of personas is in `app/lib/personas.php`.

## How "instant" works on shared hosting

Shared hosting doesn't allow WebSockets, so VCL Gamify uses **long-polling**:

- The presenter screen asks the server "anything new since version 42?". The server holds the request (up to 20 seconds) and checks a single indexed number 4× per second.
- The moment a vote lands, the activity's version goes up and the waiting request answers immediately. The screen animates the new numbers and asks again.
- Phones don't keep connections open. They submit their vote and, if you allow results on phones, check for updates every few seconds with a tiny "nothing new" response.

Measured on a test server: a vote appears on the presenter screen in about 0.3 seconds after the vote request finishes (0.5 seconds from the tap), and 80 simultaneous voters were all counted correctly.
Each open presenter or admin screen holds one connection to the server. That's comfortably within Hostinger's limits for normal use (a handful of screens, hundreds of voters).

---

## Updating

Push changes to the deployed branch and click **Deploy** in hPanel's Git page (or let auto-deploy do it).
Your `config.php`, uploaded images and the database are never touched by a deploy.
Database changes in new versions are applied automatically on the first request, and existing activities and votes are converted. It's still wise to export a backup first (hPanel → Databases → phpMyAdmin → Export).

---

## Configuration (`config.php`)

| Key | What it does |
|---|---|
| `app_name` | Name shown in the browser tab and the PowerPoint add-in (default `VCL Gamify`). |
| `db.host`, `db.port`, `db.name`, `db.user`, `db.pass` | Database connection. |
| `admin.username`, `admin.password` | Your sign-in. The password may be plain text or a `password_hash()` value (the wizard stores a hash). Changing it signs out every session. |
| `secret` | Random string used to sign login cookies. |
| `base_url` | Optional. Force the public URL used in QR codes, e.g. `https://vote.example.com`. Normally detected automatically. |
| `debug` | `true` shows detailed errors. Keep `false` on a live site. Errors are logged to `storage/error.log`. |

---

## Privacy & security

- Participants are identified by a random token stored in a cookie and in the browser. No accounts and no email addresses.
- The presenter screen shows only voters' **personas** (or initials, for anyone without one). Full names appear only on your admin page and in the CSV export.
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
  css/app.css          Design system (tokens, components, optional dark presenter theme)
  js/                  core (helpers, live updates, QR), results, poll, rank (drag to
                       order), participant, present, admin, activity, editor, home
  img/                 VCL Gamify logo and icons
  vendor/qrcode.js     QR generator (MIT, Kazuhiko Arase)
uploads/               Uploaded option images
storage/               Logs and internal markers (not web-accessible)
```

## Roadmap

The data model and UI are built around activity **types**, so new ones slot in next to voting:

- **Open feedback / word cloud.** Short text answers that build a live word cloud.
- **Rating scales.** 1–5 stars or 0–10 sliders, with averages.
- **Quiz.** Correct answers, a countdown and a live leaderboard (this is where the *gamify* part shines).
- **Sessions.** Group activities for a training day so participants can move from one to the next.
