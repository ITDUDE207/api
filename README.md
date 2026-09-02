# Excuse-to-Email API

Turns "overslept, cat spilled coffee on my laptop, missed standup" into an email you can actually send.
Also rewrites angry messages so they land without burning bridges. Plain PHP + MySQL, powered by [Groq](https://groq.com), built to run on free hosts like InfinityFree.

## Endpoints

| Method | Path       | Auth               | What it does                                   |
|--------|------------|--------------------|------------------------------------------------|
| GET    | `/`        | none               | JSON docs                                      |
| GET    | `/health`  | none               | liveness check                                 |
| POST   | `/keys`    | `X-Admin-Secret`   | create an API key `{"label": "..."}`           |
| GET    | `/me`      | `X-Api-Key`        | your usage today                               |
| POST   | `/excuse`  | `X-Api-Key`        | excuse -> `{subject, body, tip}`               |
| POST   | `/tone`    | `X-Api-Key`        | text -> `{rewritten, changes, anger_before, anger_after}` |

Tones: `professional, calm, friendly, firm, apologetic, concise, warm, neutral`.

### Examples

```bash
API=https://yoursite.infinityfreeapp.com

# Create a key (once)
curl -X POST $API/keys -H "X-Admin-Secret: $ADMIN_SECRET" -d '{"label":"cam"}'

# Excuse -> email
curl -X POST $API/excuse -H "X-Api-Key: $KEY" -H "Content-Type: application/json" -d '{
  "excuse": "overslept because I was up til 3am fixing prod, missed the 9am client call",
  "recipient": "Sarah (my team lead)",
  "sender": "Cam",
  "tone": "apologetic"
}'

# Make a message less angry
curl -X POST $API/tone -H "X-Api-Key: $KEY" -H "Content-Type: application/json" -d '{
  "text": "WHY is the deploy broken AGAIN. did anyone even test this??",
  "tone": "firm",
  "audience": "my team channel"
}'
```

Each key gets `daily_limit` requests per UTC day (default 50). Responses include `remaining_today`.

## Run locally

```bash
cp config.example.php config.php
# set groq_api_key, admin_secret, and:
#   'db_dsn' => 'sqlite:' . __DIR__ . '/dev.sqlite', 'db_user' => '', 'db_pass' => '',
php -S localhost:8080
curl localhost:8080/health
```

## Deploy to InfinityFree

1. **Create a MySQL database** in the InfinityFree control panel (MySQL Databases). Note the host (`sqlXXX.infinityfree.com`), db name, user, and password.
2. **Fill in `config.php`** from `config.example.php` with your Groq key, a long random `admin_secret`, and the MySQL details. Tables are created automatically on first request.
3. **Upload** everything (`index.php`, `.htaccess`, `config.php`, `src/`) into `htdocs/` via the File Manager or FTP. Make sure `.htaccess` was uploaded (it's a hidden file).
4. Hit `https://yoursite.infinityfreeapp.com/health`, then create a key with `POST /keys`.

Notes:
- `.htaccess` blocks direct access to `config.php` and `src/`, so your Groq key stays private.
- InfinityFree's free tier has no cron and limited CPU, so there is no background work here: every request is a single fast Groq call.
- Free subdomains serve a JavaScript anti-bot check on first visit, which breaks plain `curl` clients. If you need to call the API from scripts or other servers, point a custom domain at the site (free, in the control panel).
- Groq's free tier is generous but rate limited; if you get a 502 with a Groq error message, you've hit it.

## Files

```
index.php          router, auth, rate limit
src/Handlers.php   /excuse and /tone prompts + validation
src/Groq.php       Groq chat completions (JSON mode) via cURL
src/Db.php         PDO wrapper, auto-creates tables (MySQL or SQLite)
src/Response.php   JSON helpers + CORS
.htaccess          pretty URLs, blocks config/src
```
