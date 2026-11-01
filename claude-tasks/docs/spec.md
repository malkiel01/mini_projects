# Claude Tasks — spec: the live bridge, the command center, and the UI

**By:** the "מטלות לקלוד - אפיון" chat, 2026-11-01. **Implemented by:** "מטלות לקלוד – כותב הקוד".
**Sources:** `creative-suite/docs/requests/claude-tasks-command-center.md` (14 items + the 7-PR plan),
`creative-suite/docs/requests/claude-tasks-live-bridge.md`, and the owner's feedback on the board (#1053, #1069).
**Status:** draft, awaiting the owner's approval. Decisions already made are marked ✅.

> **Language rule (owner, 2026-11-01):** this spec and every skill built from it are written in **English**.
> UI strings stay Hebrew (the board is `lang="he" dir="rtl"`). Questions to the owner are asked in Hebrew.

This spec **replaces the order of the 7 PRs** in the board's answer to the command-center request. The content there still
holds, except where section 12 ("Changes") says otherwise.

---

## 0. Owner decisions (2026-11-01)

| # | Question | Decision |
| --- | --- | --- |
| 1 | Order | ✅ **The live bridge first**, after a short safety stage. The creative suite is paused until it works |
| 2 | How a cloud chat connects | ✅ **A registration token in the Claude environment settings.** The chat registers and reconnects by itself |
| 3 | Platform cost | ✅ **A monthly quota per user**, set by the admin. It covers all of that user's projects, and the user always sees a **graph: usage vs. remaining** |
| 4 | Topics inside a project | ✅ **They become "stages"** (UI: "שלבים") — a filter row in the Tasks tab. The ☰ drawer stays only for general topics |

---

## 1. What the board has today (starting point)

- **Model:** `projects` is a tree (`parent_id`, `path`, `icon`); `topics.project_id` and `tasks.project_id` exist.
  Old tasks have **no project**. A topic without a project is general.
- **Workers:** `X-Worker-Token` (the general one from `config.json`, or `projects.agent_token`) + `X-Worker-Name`.
  A project token is scoped to the **exact** `project_id`, not its subtree — after #1069 that is no longer enough
  (a sub-project is invisible to its parent's token).
- **Notes:** `notes(author TEXT, kind)` — a name only. **Any** signed-in user who comments on a `blocked` task sends it back to the queue.
- **None of:** an `Origin` check, cron, cost tracking, or a conversation channel — only a per-task note thread.
- **UI:** the main screen and the task sheet follow phone-first-design. Settings, projects (`#projects`, `#projectView`),
  admin, topic editing and the login screens still use the old structure (`.sheet__inner`) with only the new tokens.
  Topics live in the ☰ drawer.
- **Network:** the board is reachable from a cloud chat (`api.php` is in `public`; checked from this session — 401 without a token, as expected).
- **"Tasks created: 0"** for `malkiel` (id 1): probably created under another account (the "I don't have an account" screen) — checked in stage 1 (U1).

---

## 2. Data model — what gets unified

### 2.1 Project, stage, topic

| Concept | What it is | Table |
| --- | --- | --- |
| **Project** | A repo (root) or a folder inside it (sub-project), any depth | `projects` |
| **Stage** | A topic that belongs to a project — "Infrastructure", "Design", "Stage 3" | `topics` with `project_id` |
| **General topic** | A topic with no project (the personal board) | `topics` with `project_id IS NULL` |
| **Channel** (new) | The live conversation with one chat, inside a project | `channels` |

In the UI: inside a project it is "שלב" (stage); outside a project, "נושא" (topic). In code and schema it is the same `topics`.

### 2.2 `suite` → hierarchy + `owner_only` (change from the request, item 12)

After #1069 "the creative suite" is a **parent project** with sub-projects — a separate `suite` tag is unnecessary.

- `projects.owner_only INTEGER DEFAULT 0` — **inherited** by the whole subtree (like repo and members). Replaces `suite`.
  - Only `owner` answers (a note that returns the task to the queue) and decides (`decide`).
  - `claim` with the **general token** and no `topic_id` skips it (item 13). From stage 6 on, the route replaces this rule.
- `projects?root_id=<id>` — the whole subtree (instead of `projects?suite=`).
- `tasks?project_id=<id>&subtree=1`, and `counts.by_project` (item 11).
- **A project token covers its whole subtree**, not just the exact `id`.

For the suite's dashboard: use the `root_id` of "חבילת היצירה" instead of matching by name. Also stated in the reply in the request file.

### 2.3 "The owner" = `owner`

`author_role` is set **by the server** only: `owner` (a user with `role = admin`), `member`, `worker` (worker/channel token),
`platform` (the board's own answer), `legacy` (old notes). No client-settable field.

---

## 3. Stages — build order

Each stage = one PR (or two small ones), deployed and verified (`conclusion: success` + a manual check on the phone) before the next.

| # | Stage | What | Why here |
| --- | --- | --- | --- |
| **1** | **Safety & structure** | `author_role`, `owner_only`, subtree tokens, Origin, `root_id`/`subtree`/`by_project`, assigning old tasks | Base for everything; ~60 suite tasks are on the way |
| **2** | **Live bridge — server** | `channels`, `messages`, the channel actions, one unified inbox | ✅ first priority |
| **3** | **Live bridge — chat side** | the `claude-tasks-live` skill, the listener, the hooks, the link file | Without it the server wakes nobody |
| **4** | **Live bridge — UI** | New project screen: tabs "Chats · Tasks · Sub-projects", stages instead of the drawer | The owner sees and replies |
| **5** | **Approvals & policy** | `awaiting_approval`, `ask-approval`, `decide`, `policy` (items 1–2) | Travels through the channel as 🔐 |
| **6** | **One route** | `route: chat \| platform` per project and channel + update `claude-tasks-worker` (items 4, 8) | Merges two switches |
| **7** | **Quota & usage** | Cost logging, per-user quota, the graph (item 5, per decision 3) | **Before** any paid call |
| **8** | **Platform answers** | ☁️ replies in the channel, monitoring and summaries (item 6, bridge §4) | After the quota exists |
| **9** | **UI — remaining screens** | Settings, admin, stage editing, login — rebuilt with the components | "Clunky" — after the bridge works |
| **10** | **Claude Code engine** | `engine: claude-code` in Actions (item 7) | Most expensive, depends on everything |

Stages 1–6 and 9 **need no Platform key**.

---

## 4. Stage 1 — safety & structure

**Server**
- `notes.author_role`, `notes.author_user_id`. Existing notes → `legacy`.
- `add-note` on a `blocked` task becomes an `answer` and returns the task to the queue **only** when the author is `owner`,
  or a `member` with `write` on a project that is not `owner_only`. Otherwise it is a plain `comment`. (Today: anyone signed in.)
- `projects.owner_only` (inherited); `update-project { owner_only }` — `admin` only.
- General `claim` without `topic_id` skips `owner_only` subtrees.
- Project token → whole subtree (`workerTask` and `claimNextTask` by ancestry/`path`, not `=`).
- **Origin:** a cookie-authenticated, non-`GET` request must have `Origin` (or `Referer` when absent) equal to the board's host.
  Requests with `X-Worker-Token` are not checked. No path allow-list (the dashboard on the same domain passes).
- `projects?root_id=`, `tasks?project_id=&subtree=1`, `counts.by_project`.

**Assigning old tasks (U1)**
- Inside a project, Tasks tab → an "Assign tasks" button (shown only when tasks without a project exist): a list grouped by
  general topic, a checkbox per topic and per task, and "Move to this project". A moved topic becomes one of its stages
  (`topics.project_id`), and its tasks move with it.
- Admin → Users: the "Tasks" column also counts tasks from a merged account. `tools/owner-access.php` gets a `tasks` command:
  task count per `created_by`, including ids with no user. That is how "Tasks created: 0" gets checked.

**Done when:** `tests/` cover — a `member` cannot return an `owner_only` task to the queue; a foreign Origin → 403; a parent's
token sees a sub-project; the general token skips `owner_only`. On the phone: old tasks assigned to `mini_projects`.

---

## 5. Stages 2–4 — the live bridge

### 5.1 The principle

> **Git is the record; the board is the conversation; the listener is the bell.**

- **Chat → board:** Claude Code hooks mirror every prompt and every reply. We don't rely on the model "remembering".
- **Board → chat:** a background command in the chat (`curl` in a loop) waits for a message. When one arrives the command
  exits, and that wakes the chat. **No tokens are spent while waiting.**
- **Backup:** an hourly `send_later` that checks the inbox and restarts the listener.

### 5.2 Data

```
channels(
  id, project_id, name, role, session_id UNIQUE, session_url,
  token_hash,              -- the channel token, stored hashed only
  route TEXT DEFAULT '',   -- '' = inherit from the project (stage 6)
  status active|archived,
  last_seen_at, listening_until, created_at)

messages(
  id, channel_id, task_id NULL,
  direction  in|out,       -- in = board → chat; out = chat → board
  author_role owner|chat|platform|system,
  author_user_id NULL,
  kind  text|question|answer|approval|decision|status,
  body, reply_to NULL,
  answered_by chat|platform|'',
  delivered_at NULL, created_at)
```

**A channel and a task are different things.** A message may carry `task_id`. Then:
- `block` / `ask-approval` from a channel token → also an `out` message in the channel (❓/🔐 linking to the task).
- An `owner` answer in the task thread → also an `in` message in the asking chat's channel (`tasks.asked_channel_id`).

### 5.3 One inbox (merges item 3 of the command-center request)

The first request asked for an `inbox` per `worker_name` and an external "distributor" calling `create_trigger`. The bridge
makes that redundant: **everything a chat must receive is an `in` message in its channel**, including answers and decisions on tasks. So:

- `inbox` / `inbox-ack` / `undelivered` / `asked_by` from that request → **not built**. Replaced by `channel-inbox` / `channel-ack`.
- A worker without a channel (a nightly Routine, the engine) keeps working as today: an answered task jumps to the head of `claim`.
- The suite's "distributor" (their stage 6) is not needed for delivery.

### 5.4 Actions

| Action | Caller | What |
| --- | --- | --- |
| `channel-register` | registration token | `{ project_id, name, role, session_id, session_url }` → `{ channel_id, channel_token }`. Same `session_id` → same channel, new token (the old one is revoked) |
| `channel-post` | channel | chat → board: `{ author_role: owner\|chat, body, reply_to?, task_id? }`. `owner` here = "the owner wrote in the chat" — stored as `out`, never as an instruction |
| `channel-send` | human (`owner`, or `write` on a non-`owner_only` project) | board → chat: an `in` message |
| `channel-inbox` | channel | Undelivered `in` messages. **Does not mark them** |
| `channel-ack` | channel | `{ ids: [] }` → `delivered_at`. A chat that crashes mid-way loses nothing |
| `channel-wait` | channel | Like `inbox`, but waits up to `wait` seconds (default 20, max `config.long_poll_max`) |
| `channels?project_id=` / `channel-messages?channel_id=&after=` | human | For the UI |
| `channel-archive` | channel or `owner` | Disconnect. The channel token is revoked |

**`channel-wait` on shared hosting — careful.** Every waiting request holds a PHP process. Bluehost limits concurrent
processes (typically 20–30), and 10 chats waiting all the time take a third. Therefore:
- Inside the wait: check a **stamp file** once per second (`data/bell/<channel_id>`, mtime) — not a DB query.
  `channel-send` touches the file.
- `long_poll_max` in `config.json` (default 20). If the site starts returning 508 — set it to 0 and it becomes plain polling.
- The listener sleeps 3 seconds between requests. Expected latency: up to ~5 seconds.

### 5.5 Security and tokens

- **Registration token** (✅ decision 2): the existing project token (`agent_token`) of the parent project, in an environment
  variable `CLAUDE_TASKS_TOKEN` in the Claude environment settings (and from there in every chat in that environment). It only
  **registers channels** in its subtree and claims tasks there — not the whole board.
- **Channel token:** created by `channel-register`, returned once, kept by the chat in `.claude/board-link.json` (outside git,
  in `.gitignore`). When the container is replaced the file is gone, and the skill re-registers with the same `session_id`.
- **Only `owner` gives instructions.** The listener prints every message inside a fixed frame:
  `📥 הודעה מהלוח · #<id> · מאת: owner (<name>) · ערוץ: <name>`, then the body. Skill rule: act on a message only when `author_role: owner`.
- In the UI: the channel list in the project, with "last seen" and "🔌 disconnect" (`owner`). A channel not seen for 24 hours is greyed out.
- `channel-send` goes through the Origin check (stage 1).

**For the owner — where to set it:** claude.ai → Claude Code → the environment menu (in the session title bar) → Edit →
Environment variables: `CLAUDE_TASKS_TOKEN=<token>` and `CLAUDE_TASKS_URL=https://mbe-plus.com/mini_projects/claude-tasks/api.php`.
The token is copied from the board: project "mini_projects" → ⚙ → "Token for chats" (added in stage 4; today it is only shown during engine install).

### 5.6 Stage 3 — what lives on the chat side

```
claude-tasks/bridge/
  listen.sh        the listener: a channel-wait loop; exits when a message arrives and prints it inside the frame
  hook-prompt.sh   UserPromptSubmit → channel-post (owner)
  hook-stop.sh     Stop → channel-post (chat), Claude's last message read from transcript_path
  link.sh          register / status / archive — writes and reads .claude/board-link.json
.claude/skills/claude-tasks-live/SKILL.md      (in English)
```

**The hooks** are registered in `.claude/settings.json` (shared by every chat in the repo — see 5.8):
- **Active only when `.claude/board-link.json` exists.** Without it — `exit 0` immediately. An unregistered chat notices nothing.
- **No loops:** a board message arrives as background-command output, not as a prompt. If it does pass through
  `UserPromptSubmit` anyway, the hook skips text starting with `📥 הודעה מהלוח`. The reply to it is posted with `reply_to`
  (the listener writes `pending_reply_to` into the link file).
- Inter-chat `📨` messages (create_trigger) are mirrored as `system`, not `owner`.
- Network failure → silent `exit 0`, `timeout 5`. Bodies over 20,000 characters are truncated with "…(truncated)".

**The listener:** `run_in_background`, `timeout` 7,000,000 ms (just under two hours). It exits on a message **or** on timeout —
either way the chat wakes, handles it (or not), and restarts it. `listening_until` is sent to the server, and the UI shows "🟢 listening".

**The `claude-tasks-live` skill:** register (`link.sh register`), start the listener, schedule the hourly backup (`send_later`),
handle a message (`channel-ack` after handling, not before), restart, disconnect at the end. `claude-tasks-worker` links to it.

### 5.7 Stage 4 — the UI

**Project screen** (replaces the old `#projectView`, built with phone-first-design components):
- Header: back arrow (one level up), icon and name, ⚙ (project settings in a sheet).
- **Tabs with counters**, last tab remembered: **💬 Chats · 📋 Tasks · 📁 Sub-projects**. A tab with no content is hidden
  (a project without sub-projects has two tabs).
- **💬 Chats:** one row per channel — role icon in a colored square, bold name, subline (🟢 listening · 3 min ago · ❓ 2), chevron ‹.
  Empty state: "No chats connected. A chat registers itself with the claude-tasks-live skill."
- **Conversation (channel):** chat-like bubbles, `out` on the right and `in` on the left (RTL), a small label for who wrote
  (👤 you in the chat / 👤 you on the board / 💬 chat / ☁️ Platform), and the time. A message with `task_id` shows a tappable task row.
  A fixed composer at the bottom, a "Send" button with a word. Delivery state: ✓ sent · ✓✓ delivered (`delivered_at`).
  Refresh: `channel-messages?after=` every 4 seconds while the screen is visible.
- **📋 Tasks:** the existing list, with a **stages row** on top (horizontally scrolling `.seg`: "All · Infrastructure · Design …",
  ✏️ to edit stages). ✅ decision 4. "+ Task" — as today.
- The ☰ drawer on the main screen — only for general topics, titled "General topics".

**Done when** (from the bridge request, "Done"): a test chat registers; writing on the board → the chat wakes and replies
**in under a minute**, and the reply shows on the board; writing in the chat → the question and the reply show on the board;
a short report on the limits (listener, container replacement). A phone screenshot before "ready".

### 5.8 What must be verified for real (at the start of stage 3, before building everything)

1. A background command that exits **wakes an idle chat** (not only a chat mid-turn). How long after?
2. Does the container stay alive while a background command runs, or is it reclaimed after inactivity anyway? After how long?
3. Does background-command output go through `UserPromptSubmit`?
4. Does the `Stop` hook receive `transcript_path`, and can the last message be extracted from it?

If 1 fails — the bridge falls back to the backup only (up to an hour of latency), and the owner is told before continuing.
**A change to `.claude/settings.json`** affects every chat in the repo — so the hooks must be a no-op without a link file, and are
tested in a clean chat before merging.

---

## 6. Stage 5 — approvals & policy

As "PR 2" in the answer to the command-center request, with two changes:
- `decide` is `owner` only in an `owner_only` subtree (instead of "a project with `suite`").
- `ask-approval` from a channel token → also a 🔐 message in the channel; `decide` → also an `in` message of kind `decision`.

UI: a 🔐 card in the task sheet **and as a bubble in the channel** — summary, detail in `<details>`, and 4 buttons in a 2×2 grid:
"Once" · "Always" · "No" · "Never". "Always" on a `warn` type opens a confirmation that asks to type the type's name.

---

## 7. Stage 6 — one route (merges two switches)

The command-center request: `route: code | platform` — who **executes tasks**. The bridge request: `answer_route: chat | platform` —
who **answers in the channel**. It is the same decision ("on whose account"), so there is **one switch**:

- `projects.route`: `chat | platform | ''` (empty = inherit from parent; at the root, empty = `config.default_route`, default `chat`).
- `channels.route` overrides the project for one channel.
- `X-Worker-Route: chat` (also accepts `code` as the old name). No header = `chat`.
- **A switch applies to new work only:** an `in_progress` task keeps `tasks.route`; a message already being handled finishes on the route it started on.
- The `owner_only` rule in `claim` (stage 1) is replaced here by the route.
- UI: at the top of the project screen, a two-option `.seg`: "💬 הצ'אטים" · "☁️ Platform". Next to ☁️ — how much quota is left.

**`claude-tasks-worker`** (item 8): `X-Worker-Route: chat`; `policy` before `ask-approval`; act on an answer only from `owner`;
and **instead of** a self-check every 15 minutes — the `claude-tasks-live` skill (listener + hourly backup).

---

## 8. Stage 7 — quota & usage (✅ decision 3 — change from the request)

The request asked for two caps **in the server settings only**. The owner decided: **a quota per user, set by the admin in the UI.**

- `usage(at, user_id, project_id, task_id, channel_id, kind develop|answer|monitor, provider, model, tokens_in, tokens_out, cost_usd)`.
  `lib/ai.php` logs every call from the `usage` the provider returns; prices are a table in code, per model.
- **Charged to whom:** the user **whose key paid** (keys are already personal — `user_providers`). In a project, the default is
  the key of the root project's owner. That is how "the quota covers all of that user's projects".
- `users.quota_usd` (monthly). Default `0` = ☁️ off for that user until the admin sets it. Monitoring (`monitor`) — up to 20% of the quota.
- **Admin UI:** Admin → Users → row → "Monthly quota ($)", with this month's usage next to it.
- **The graph — always visible to the user:** Settings → a "Platform usage" card at the top: "$7.40 of $25 · $17.60 left" + a
  progress bar + **daily bars** for the month (stacked by `develop`/`answer`/`monitor`), and a row per project. A compact version
  (bar only) next to the ☁️ switch on the project screen. No library — inline SVG, following the `dataviz` skill.
- **80%:** the bar turns yellow + a warning line on the main screen, and a `system` message in that user's channels.
- **100%:** ☁️ is treated as `chat` in all of the user's projects (computed, not written — resets by itself on the 1st of the month);
  `run-task` → 402 with an explanation; anything already running finishes.
- `usage-summary?user_id=` (the user themselves or `admin`) — for the graph.

---

## 9. Stage 8 — Platform answers

- **In the channel, on the ☁️ route:** `channel-send` stores the message; the UI immediately calls `platform-reply { message_id }`,
  which answers **within that request** (PHP has no background process) and shows "☁️ thinking…". If the screen is closed mid-way,
  the message is marked "waiting for Platform" and the next open completes it. Context: the last 30 messages in the channel, the
  open tasks in the project, and the folder's `README.md` (via GitHub). If code is needed, the reply offers "📋 Turn into a task"
  (tap = `create-task` + `run-task`) and does not write code itself.
- **The chat stays in sync:** messages answered on ☁️ are delivered to it as `kind: status` — in `channel-inbox`, **without waking
  it** (`channel-wait` returns only what needs handling).
- **Monitoring** (item 6 of the request): a 3-line summary per project, ❓/🔐 sorted by urgency, "already asked", type matching.
  **No cron:** runs "lazily" — when the owner opens the board and an hour has passed, and after `block`/`ask-approval` (at most
  once per 10 minutes per project). `monitor-tick` stays available for external callers.
- No key / quota 0 / at 100% — skip silently: `monitor: "off"`.

---

## 10. Stage 9 — the remaining screens (per taste.md)

| Screen | What is clunky today | Fix |
| --- | --- | --- |
| **Settings** | One long scroll of sections | Tabs in a grid: **Usage · Connections · Account** (+ **Users** for admin). "Usage" first — the graph card |
| **Connections** | Forms always shown | One row per connection (GitHub, Anthropic, OpenAI, Gemini): icon, "connected · …4f2a" / "not connected", ‹ → a small sheet |
| **Project ⚙** | One long form | Rows: repo & branch · members · route · engine · skills · token for chats — each opens a sheet |
| **Admin** | `chip`s used as a menu | `.seg-tabs` with counters; log = one row per event (icon, old → new, who, when), day separators, failures in red |
| **Stage editing** | Old `.sheet__inner` | `.sheet-wrap`; keywords as a visible field (not collapsed) |
| **Login** | — | Tokens only; accessibility: a `label` per field, focus after an error |

**Accessibility on every screen:** icon button = `aria-label`; touch targets ≥ 44px; tabs `role="tablist"`; a new channel message in `aria-live="polite"`.

---

## 11. Stage 10 — the Claude Code engine

As "PR 7" in the answer to the command-center request, unchanged: `projects.engine = loop | claude-code`, `run.mjs` stays;
PR only, never deploy; the cost from `total_cost_usd` is logged in `usage` as `develop` **against the quota of whoever started it**.

---

## 12. Changes from the requests — and why

| Request | Change | Why |
| --- | --- | --- |
| Command center 12 `suite` | Inherited `owner_only` + `root_id` | The hierarchy (#1069) already represents a "suite" |
| Command center 3 `inbox`, `undelivered`, distributor | `channel-inbox` / `channel-ack` | One channel for everything addressed to a chat; the listener replaces the distributor |
| Command center 4 `code\|platform` + bridge 4 `chat\|platform` | One `route` switch | Same decision — on whose account |
| Command center 5 server-side caps | Per-user quota + graph | ✅ owner's decision |
| Command center 6 "once an hour" | Lazy monitoring + `monitor-tick` | No cron |
| Command center 8 self-check every 15 min | Listener + hourly backup | Cheaper and faster |
| Bridge 6 "a token per channel in the environment" | Registration token in the environment; channel token minted at registration | The environment is shared by all chats — it cannot hold one token per chat |
| Bridge 1 long-poll ~25 s | Up to 20 s, can be disabled, on a stamp file | Process limit on shared hosting |

## 13. Out of scope

The queue and the automatic catalog, the APK (beyond what reaches it through the PWA anyway), and any creative-suite code.

## 14. Handoff to the code writer

After approval: a `בקשה` message with **stage 1**. Every stage — branch, PR, merge, `conclusion: success`, and a row here:

| Stage | PR | Deployed | Notes |
| --- | --- | --- | --- |
| 1 | #1086 | `conclusion: success` checked after the merge — reported in the `עדכון` message | `subtree` defaults to 1 (the UI already relies on the whole branch); a task with no project — any signed-in user answers, as before; added a project check to `task` / `add-note` (`read`) and `update-task` (`write`); U1 — `owner` only |
