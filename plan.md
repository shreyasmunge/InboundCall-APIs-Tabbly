# Inbound Call API — Final Build Plan

> **Published at:** [Tabbly Developer Docs](https://www.tabbly.io/docs/index) (new **Inbound** section alongside Campaigns, Agents, Call Logs)
>
> **Source logic:** [inboundCallcode.txt](inboundCallcode.txt) — **all operational features must be implemented through the API in the same way** as the legacy PHP page.

---

## Core rule: full parity with inboundCallcode.txt

**Every feature that the legacy page does for inbound calling must work identically in the API.**

| What stays the same | What changes |
|---------------------|--------------|
| All LiveKit + Plivo integration logic | Auth: `api_key` for developers (not PHP session) |
| Step order for create / refresh / disable | Delivery: REST JSON API (not HTML forms) |
| DB reads/writes (`voice_agents`, `agents_phone_numbers`, `agent_call_logs`) | Audience: external developers (terminal/server) |
| Naming, metadata, conflict retry, best-effort cleanup | Credentials: `.env` (not hardcoded in source) |
| Duplicate phone check, Plivo non-fatal on create | **Added:** free-tier block `918035736739` |
| Subscription must be active | **Added:** agent `organization_id` must match API key org |
| `debugLog` step tracing (server-side) | UI, mobile redirects, HTML — **not ported** |

**Porting strategy:** Extract function blocks from `inboundCallcode.txt` into service classes with **minimal logic changes**. Keep method names and step order identical to reduce regression risk.

---

## Overview

Build a **developer-facing PHP REST API** published on [Tabbly Developer Docs](https://www.tabbly.io/docs/index) so integrators can configure inbound calling programmatically — same as the dashboard “Configure Inbound Calling” page, but via API.

**Prerequisite for developers:** Create a voice agent with a **purchased** phone via existing [Create Agent](https://www.tabbly.io/docs/index) API, then use inbound endpoints.

---

## Plivo and LiveKit

| Service | Role |
|---------|------|
| **Plivo** | Phone network. Receives PSTN calls; Zentrunk forwards SIP to LiveKit. |
| **LiveKit** | Voice/AI. Inbound trunk accepts calls; dispatch rule starts agent `central` in room `call-*` with full agent metadata. |

**Flow:** Caller → Plivo → LiveKit SIP → dispatch rule → AI agent.

---

## Alignment with Tabbly developer APIs

Per [Tabbly Developer Docs](https://www.tabbly.io/docs/index):

| Convention | Existing Tabbly APIs | Inbound API |
|------------|---------------------|-------------|
| Base URL | `https://www.tabbly.io/api/` | `https://www.tabbly.io/api/inbound/` |
| Auth (primary) | `api_key` in **JSON body** | Same |
| Auth (GET) | query param (e.g. Get Voices) | `?api_key=&agent_id=` on `/status` |
| Success | `status: "success"` + `data` | Same |
| Error | `status: "error"` + `message` | Same |
| Key | Dashboard → `organizations.api_key` | Same |

---

## Authentication (only intentional difference from legacy)

Legacy [inboundCallcode.txt](inboundCallcode.txt) uses PHP **session** (`member_id`, `organization_id`). The API uses **organization API key** — same key developers use for other Tabbly APIs.

### What developers send (every endpoint)

| Field | Required? | Notes |
|-------|-----------|-------|
| `api_key` | Yes | JSON body (POST) or query (GET) |
| `agent_id` | Yes | Voice agent ID |
| `phone_number` | **No** | Loaded from `voice_agents.phone_number` (legacy: *"Use agent id and phone from attached number"*) |

### Examples

```bash
# Create
curl -X POST "https://www.tabbly.io/api/inbound/create" \
  -H "Content-Type: application/json" \
  -d '{"api_key": "YOUR_KEY", "agent_id": "AGENT_ID"}'

# Status (GET)
curl "https://www.tabbly.io/api/inbound/status?api_key=YOUR_KEY&agent_id=AGENT_ID"
```

### Middleware chain (replaces session + subscription blocks in legacy lines 45–169)

1. Extract `api_key` from body → query → `Authorization: Bearer`
2. `SELECT organization_id, subscription_status FROM organizations WHERE api_key = ?` → 401
3. `subscription_status = 'yes'` → 403 (same as legacy subscription check)
4. Load `voice_agents` by `agent_id`; verify `organization_id` matches → 403/404
5. Block free-tier `918035736739` (digits-normalized) → 403
6. Run endpoint logic

**Server-to-server only.** Document on Tabbly docs: never expose `api_key` in browser JS.

---

## Free-tier blocklist (API addition)

```php
FREE_TIER_NUMBERS = ['918035736739'];
```

Only **purchased** numbers may use inbound. All endpoints return 403 for this number.

---

## Endpoints (maps 1:1 to legacy page actions)

Base: `https://www.tabbly.io/api/inbound/`

| # | Method | Path | Legacy source |
|---|--------|------|---------------|
| 1 | GET/POST | `/status` | Lines 219–361 (page load + status) |
| 2 | POST | `/create` | `create_inbound` (3609–4463) |
| 3 | POST | `/refresh-metadata` | `refresh_metadata` (2323–3607) |
| 4 | POST | `/disable` | `disable_inbound` (2043–2321) |

### Response shape (Tabbly convention)

```json
{"status": "success", "data": { ... }}
{"status": "error", "message": "..."}
```

---

## Complete feature parity checklist (from inboundCallcode.txt)

### API 1 — Status (`/status`)

| Legacy feature | Implement in API |
|----------------|------------------|
| Load agent by id from `voice_agents` | ✓ |
| Return `agent_name`, `phone_number`, `custom_first_line` | ✓ in `data` |
| Error if agent not found / no phone | ✓ 404 / 400 |
| `inbound_active` when both trunk + rule IDs in `agents_phone_numbers` | ✓ |
| Fallback: `agent_call_logs` count for legacy rows | ✓ |
| No external API calls on load | ✓ |

### API 2 — Create (`/create`)

| Legacy feature | Implement in API |
|----------------|------------------|
| Duplicate check: phone in `agents_phone_numbers` with trunk+rule IDs | ✓ → 409 |
| Duplicate fallback: `agent_call_logs` + org check | ✓ → 409 |
| Load full `voice_agents` row | ✓ |
| Force `call_direction = 'inbound'` in metadata | ✓ |
| Encode full row as JSON metadata | ✓ |
| Extract `api_tools` → `room_config.api_tools` (nested/direct decode) | ✓ |
| `generateLiveKitToken` with `sip.admin: true` | ✓ |
| `deleteInboundTrunksForNumber` before create (best-effort) | ✓ |
| Trunk name `trunk-{phoneDigits}` | ✓ |
| `createInboundTrunk` + defensive ID extraction (`sid`, `sip_trunk_id`, `id`) | ✓ |
| Conflict retry: parse "Conflicting inbound SIP Trunks", `deleteSipTrunkById`, `sleep(1)`, retry | ✓ |
| `getLiveKitSipEndpoint` | ✓ |
| `createPlivoInboundTrunk` via Zentrunk API | ✓ |
| `connectPhoneNumberToTrunk` with Zentrunk `app_id` | ✓ |
| `getPlivoPhoneNumber` verify `app_id` matches | ✓ |
| Plivo failure **non-fatal** — continue with LiveKit; warn in response | ✓ `plivo_trunk_created: false` |
| Dispatch rule name `dispatch-for-trunk-{phoneDigits}` | ✓ |
| Agent name hardcoded `central` | ✓ |
| Room prefix `call-` | ✓ |
| `createSipDispatchRuleIndividual` | ✓ |
| Store `inbound_trunk_id` + `inbound_dispatch_rule_id` in `agents_phone_numbers` | ✓ |
| Insert `agent_call_logs` (legacy) with `custom_first_line` | ✓ |
| Return trunk IDs, rule IDs, `plivo_trunk_id`, `action: created` | ✓ |

### API 3 — Refresh metadata (`/refresh-metadata`)

| Legacy feature | Implement in API |
|----------------|------------------|
| Re-fetch full `voice_agents` row | ✓ |
| Rebuild metadata; `call_direction = inbound` | ✓ |
| `api_tools` handling same as create | ✓ |
| Prefer stored trunk/rule IDs from `agents_phone_numbers` | ✓ |
| Fallback: `listSipDispatchRules` + search by name/trunk/phone | ✓ |
| **Delete + recreate** dispatch rule (not in-place update) | ✓ |
| Update `inbound_dispatch_rule_id` in DB | ✓ |
| Return `action: metadata_refreshed` + new rule ID | ✓ |

### API 4 — Disable (`/disable`)

| Legacy feature | Implement in API |
|----------------|------------------|
| Read stored IDs from `agents_phone_numbers` before clear | ✓ |
| Clear trunk/rule IDs in `agents_phone_numbers` (best-effort) | ✓ |
| Delete `agent_call_logs` row | ✓ |
| `deleteSipDispatchRule` by stored ID | ✓ |
| Fallback: `deleteDispatchRulesForTrunk`, `deleteDispatchRulesForNumber` | ✓ |
| `deleteInboundTrunksForNumber` | ✓ |
| `deleteSipTrunkById` | ✓ |
| Plivo: `getPlivoPhoneNumber`, disconnect, `listPlivoTrunks`, `deletePlivoTrunk` by name match | ✓ best-effort |
| Idempotent — partial cleanup OK; return success | ✓ |
| Return `action: disabled` | ✓ |

### All PHP helper functions — port to clients

| Function | Port | Used in API |
|----------|------|-------------|
| `base64UrlEncode` | ✓ | JWT |
| `generateLiveKitToken` | ✓ | create, refresh, disable |
| `httpPostJson` | ✓ | LiveKit |
| `createInboundTrunk` | ✓ | create |
| `deleteSipTrunkById` | ✓ | create retry, disable |
| `createSipDispatchRuleIndividual` | ✓ | create, refresh |
| `listSipDispatchRules` | ✓ | refresh, delete helpers |
| `deleteSipDispatchRule` | ✓ | refresh, disable |
| `deleteDispatchRulesForTrunk` | ✓ | disable |
| `deleteDispatchRulesForNumber` | ✓ | disable |
| `updateSipDispatchRuleMetadata` | ✓ port | **not called** (legacy refresh uses delete+recreate) |
| `plivoApiRequest` | ✓ | all Plivo ops |
| `getLiveKitSipEndpoint` | ✓ | create |
| `createPlivoInboundTrunk` | ✓ | create |
| `connectPhoneNumberToTrunk` | ✓ | create |
| `updatePhoneNumberWithTrunkId` | ✓ | wrapper — same as `connectPhoneNumberToTrunk` |
| `getPlivoPhoneNumber` | ✓ | create verify, disable |
| `listPlivoTrunks` | ✓ | disable |
| `deletePlivoTrunk` | ✓ | disable |
| `deleteInboundTrunksForNumber` | ✓ | create pre-clean, disable |

### Explicitly NOT ported (UI-only, not inbound logic)

- `isMobileDevice`, org/mobile redirects (lines 85–117)
- PHP session / cookies / `login_tools.php`
- HTML forms, CSS, debug panel UI (lines 4480–4835)
- Hidden form fields (`phone_number` in POST — legacy still uses DB phone)

### Operational constants (must match legacy)

| Constant | Value |
|----------|-------|
| LiveKit agent name | `central` |
| Room prefix | `call-` |
| Trunk name | `trunk-{digits}` |
| Dispatch rule name | `dispatch-for-trunk-{digits}` |
| Plivo Zentrunk `app_id` | from env `PLIVO_ZENTRUNK_APP_ID` (legacy: `12349622293949689`) |
| HTTP timeout | 30s |
| Conflict retry sleep | 1s |

---

## Developer request reference

### API 1 — Status

```bash
curl "https://www.tabbly.io/api/inbound/status?api_key=YOUR_KEY&agent_id=AGENT_ID"
```

**Returns:** `phone_number`, `inbound_active`, `inbound_trunk_id`, `inbound_dispatch_rule_id`, `agent_name`, `custom_first_line`

### API 2 — Create

```bash
curl -X POST "https://www.tabbly.io/api/inbound/create" \
  -H "Content-Type: application/json" \
  -d '{"api_key": "YOUR_KEY", "agent_id": "AGENT_ID"}'
```

**Duplicate phone check:** Rejects if this phone already has inbound on any agent (409).

### API 3 — Refresh metadata

```bash
curl -X POST "https://www.tabbly.io/api/inbound/refresh-metadata" \
  -H "Content-Type: application/json" \
  -d '{"api_key": "YOUR_KEY", "agent_id": "AGENT_ID"}'
```

### API 4 — Disable

```bash
curl -X POST "https://www.tabbly.io/api/inbound/disable" \
  -H "Content-Type: application/json" \
  -d '{"api_key": "YOUR_KEY", "agent_id": "AGENT_ID"}'
```

### Typical workflow (document on Tabbly docs)

1. Get `api_key` from dashboard
2. Create agent with purchased phone → `agent_id`
3. `POST /inbound/create`
4. Edit agent → `POST /inbound/refresh-metadata`
5. `GET /inbound/status` anytime
6. `POST /inbound/disable` when done

---

## Project structure

```
InboundCall API/
├── public/index.php
├── src/
│   ├── Middleware/ApiKeyAuth.php
│   ├── Middleware/AgentOrgCheck.php
│   ├── Middleware/PurchasedNumberCheck.php
│   ├── Controllers/InboundController.php
│   ├── Services/InboundService.php
│   ├── Services/LiveKitSipClient.php
│   ├── Services/PlivoClient.php
│   └── Repositories/AgentRepository.php, InboundRepository.php
├── routes/api.php
├── composer.json
├── .env.example
└── plan.md
```

---

## Environment (.env)

```
DB_HOST= DB_USER= DB_PASS= DB_NAME= DB_PORT=25060
LIVEKIT_SERVER_URL= LIVEKIT_API_KEY= LIVEKIT_API_SECRET= LIVEKIT_SIP_ENDPOINT=
PLIVO_AUTH_ID= PLIVO_AUTH_TOKEN= PLIVO_ZENTRUNK_APP_ID=
APP_DEBUG=false
```

---

## Build sequence

1. **Scaffold** — Composer, router, `.env`, DB, Tabbly response helpers
2. **Auth middleware** — api_key; org; subscription; agent org; free-tier
3. **Port clients** — `LiveKitSipClient`, `PlivoClient` (all helpers, same logic)
4. **API 1** `/status` — full parity lines 219–361
5. **API 4** `/disable` — full parity lines 2043–2321
6. **API 2** `/create` — full parity lines 3609–4463
7. **API 3** `/refresh-metadata` — full parity lines 2323–3607
8. **Publish docs** — add **Inbound** section to [Tabbly Developer Docs](https://www.tabbly.io/docs/index): auth, 4 endpoints, curl examples, errors, free-tier rule, workflow

---

## Test matrix (must pass before publish)

| Test | Expected |
|------|----------|
| Valid api_key + agent | `status: success` |
| Invalid api_key | 401 |
| Inactive subscription | 403 |
| Agent wrong org | 403 |
| Free-tier 918035736739 | 403 |
| Status, no inbound | `inbound_active: false` |
| Create purchased number | trunk + rule + DB IDs |
| Create duplicate phone | 409 |
| Create with Plivo down | success + `plivo_trunk_created: false` |
| LiveKit trunk conflict | auto-delete + retry |
| Refresh after agent edit | new dispatch rule ID |
| Disable | resources cleared; retry OK |
| Legacy `agent_call_logs` only row | status shows active |

---

## Todos

| ID | Task | Status |
|----|------|--------|
| scaffold-php-api | Scaffold PHP API | completed |
| add-api-key-auth | ApiKeyAuth middleware | completed |
| purchased-number-gate | Block 918035736739 | completed |
| port-livekit-plivo | Port all helpers verbatim | completed |
| api-1-get-status | GET/POST /status — parity 219–361 | completed |
| api-4-post-disable | POST /disable — parity 2043–2321 | completed |
| api-2-post-create | POST /create — parity 3609–4463 | completed |
| api-3-post-refresh | POST /refresh-metadata — parity 2323–3607 | completed |
| developer-docs | README + plan (publish to tabbly.io/docs) | completed |

---

## Deliverables

1. Four API endpoints — **full behavioral parity** with [inboundCallcode.txt](inboundCallcode.txt)
2. Auth via `api_key` matching [Tabbly Developer API](https://www.tabbly.io/docs/index)
3. Documentation published on Tabbly developer docs site
4. `.env.example` + free-tier enforcement
5. All LiveKit/Plivo helpers ported; secrets in env only
