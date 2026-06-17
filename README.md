# Tabbly Inbound Call API

Developer API for configuring inbound calling on Tabbly voice agents. Published alongside [Tabbly Developer Docs](https://www.tabbly.io/docs/index).

## Base URLs

| Environment | Base URL |
|-------------|----------|
| **Local** | `http://localhost:8080/api/inbound/` |
| **Production** (after deploy) | `https://www.tabbly.io/api/inbound/` |

Use the same paths and request format in both environments. Only the host changes.

> **Note:** The production URL returns Apache `404 Not Found` until this API is deployed and routed on `www.tabbly.io`. Use local testing while developing.

## Authentication

Send your organization `api_key` (from the Tabbly dashboard) with every request:

- **POST endpoints:** JSON body `"api_key": "..."`
- **GET /status:** query `?api_key=...`
- **Optional:** `Authorization: Bearer <api_key>` or header `X-API-Key: <api_key>`

Also required: `"agent_id"` (voice agent ID from the Create Agent API).

Phone number is **not** sent — it is read from `voice_agents.phone_number`.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET/POST | `/status` | Check inbound status |
| POST | `/create` | Enable inbound calling |
| POST | `/refresh-metadata` | Update LiveKit agent config |
| POST | `/disable` | Disable inbound calling |

## Test locally

### 1. Configure environment

```bash
cp .env.example .env
```

Fill in `.env` with your database, LiveKit, and Plivo credentials. The DB user needs `SELECT` on `organizations` and `voice_agents` (and related inbound tables).

### 2. Start the dev server

From the project root:

```bash
php -S localhost:8080 -t public
```

Keep this terminal open while testing.

### 3. Send requests (local)

Always use `curl` (or another HTTP client). **Do not paste the URL alone into the terminal** — zsh will treat `&` and `?` as shell syntax.

Replace `YOUR_KEY` and `AGENT_ID` with real values from your Tabbly dashboard.

```bash
# Status (GET)
curl "http://localhost:8080/api/inbound/status?api_key=YOUR_KEY&agent_id=AGENT_ID"

# Create (POST)
curl -X POST "http://localhost:8080/api/inbound/create" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"YOUR_KEY","agent_id":"AGENT_ID"}'

# Refresh metadata (POST)
curl -X POST "http://localhost:8080/api/inbound/refresh-metadata" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"YOUR_KEY","agent_id":"AGENT_ID"}'

# Disable (POST)
curl -X POST "http://localhost:8080/api/inbound/disable" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"YOUR_KEY","agent_id":"AGENT_ID"}'
```

### 4. Apache / Nginx (optional)

Point the web server document root to `public/`. Ensure all `/api/inbound/*` requests are rewritten to `public/index.php` (see `public/.htaccess` for Apache).

## Send requests when deployed on server

After deployment to `www.tabbly.io`, use the **same curl commands** but swap the host:

```bash
# Status (GET)
curl "https://www.tabbly.io/api/inbound/status?api_key=YOUR_KEY&agent_id=AGENT_ID"

# Create (POST)
curl -X POST "https://www.tabbly.io/api/inbound/create" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"YOUR_KEY","agent_id":"AGENT_ID"}'

# Refresh metadata (POST)
curl -X POST "https://www.tabbly.io/api/inbound/refresh-metadata" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"YOUR_KEY","agent_id":"AGENT_ID"}'

# Disable (POST)
curl -X POST "https://www.tabbly.io/api/inbound/disable" \
  -H "Content-Type: application/json" \
  -d '{"api_key":"YOUR_KEY","agent_id":"AGENT_ID"}'
```

### Deployment checklist

1. Upload project files to the server.
2. Set `public/` as the document root (or equivalent vhost root).
3. Copy `.env.example` → `.env` on the server and fill production credentials.
4. Enable URL rewriting so `/api/inbound/status`, `/api/inbound/create`, etc. route to `index.php`.
5. Confirm PHP 8.1+ with `mysqli` is available.
6. Test with `curl` — a successful response is JSON (`{"status":"success",...}`), not an HTML 404 page.

## Response format

Success:

```json
{"status":"success","data":{...}}
```

Error:

```json
{"status":"error","message":"..."}
```

Common errors:

| Message | Likely cause |
|---------|----------------|
| `Invalid or missing API key` | Wrong or missing `api_key`, or key not in `organizations` table |
| `Subscription inactive` | Organization subscription is not active |
| `Agent not found` | Invalid `agent_id` |
| HTML `404 Not Found` | Endpoint not deployed or rewrite rules missing |

## Notes

- Free-tier number `918035736739` is blocked on all endpoints.
- Logic ported from `inboundCallcode.txt` (LiveKit + Plivo + DB parity).
- Call from server/terminal only — never expose `api_key` in browser JavaScript.
