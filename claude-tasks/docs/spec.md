# Claude Tasks — spec: the live bridge, the command center, and the UI

**By:** the "מטלות לקלוד - אפיון" chat, 2026-11-01. **Implemented by:** "מטלות לקלוד – כותב הקוד".
**Sources:** `creative-suite/docs/requests/claude-tasks-command-center.md` (14 items + the 7-PR plan),
`creative-suite/docs/requests/claude-tasks-live-bridge.md`, and the owner's feedback on the board (#1053, #1069).
**Status (2026-11-02):** approved 2026-11-01; **stages 1–10 built and deployed** (see §14). Still open — not code:
(1) the live check of the bridge on the real board waits for `CLAUDE_TASKS_TOKEN` in the environment settings;
(2) Platform answers and the Claude Code engine were tested only against fake providers; (3) every repo with the engine
installed needs "התקנה מחדש" (cookie fix + usage reporting) — the owner postponed it. Decisions are marked ✅.

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

**Decided during the build (2026-11-01, questions from the code writer):**
- **A task with no project** (all old tasks): any signed-in user may answer, as today — there is no `owner_only` to protect.
  A `worker` **never** answers: a worker-sent `kind=answer` is stored as `comment` and does not return the task to the queue.
- **`tasks?project_id=`** already returns the whole subtree, and the UI relies on it — so `subtree=1` is the **default**;
  `subtree=0` = the exact project only.
- **Per-task permissions** (found in the code: today any signed-in user reads and edits any task): `task` and `add-note`
  require `read` on the task's project, `update-task` requires `write`. A task with no project — as today. Done now, not in stage 9.
- **"Assign tasks" (U1)** — `owner` (admin) only: it cleans up the personal board, not a project member's action.

**Done when:** `tests/` cover — a `member` cannot return an `owner_only` task to the queue; a foreign Origin → 403; a parent's
token sees a sub-project; the general token skips `owner_only`. On the phone: old tasks assigned to `mini_projects`.

**Added after the spec, at the owner's request (#1088):** `move-project` (`owner` only, blocks cycles, tasks move with the
project) and a "Structure" section in project settings: an "owner only" checkbox (`owner_only` had no UI) and a "move into
project (temporary)" picker — so the 8 creative-suite projects created before the tree can go under
`mini_projects › חבילת היצירה`. **Recommendation:** keep `move-project` permanently — reorganizing the tree is a normal need
("add more projects easily", #1069); only the "(temporary)" label goes. ✅ Kept permanently.

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

*Revised 2026-11-01 after the 5.8 checks (code writer, against the live board).*

```
claude-tasks/bridge/
  lib.sh           shared: URL, token, curl with the hosting cookie (below), timeouts
  listen.sh        the listener: a channel-wait loop; on a message it writes .claude/board-pending.json and exits
  hook-prompt.sh   UserPromptSubmit → channel-post (owner)
  hook-stop.sh     Stop → channel-post (chat), from the hook input's last_assistant_message
  link.sh          register / status / pending / archive — reads and writes .claude/board-link.json
.claude/skills/claude-tasks-live/SKILL.md      (in English)
```

**The hosting's bot protection.** A request without a browser gets `409` + JavaScript that sets the cookie `humans_21909=1`;
with `-b humans_21909=1` it passes. So **every** chat-side request goes through `lib.sh`, which sends the cookie. The name can
change: `lib.sh` reads it from `CLAUDE_TASKS_COOKIE` (default `humans_21909=1`), and on a `409` whose body sets a cookie it
extracts the new name/value, retries once, and stores it in the link file. The engine (`agent/run.mjs`, running in GitHub
Actions) is probably blocked the same way — **check it in stage 2 and fix it there** (one header), since a silently blocked
engine leaves tasks stuck.

**A woken chat does not see the listener's output.** The background command's exit arrives as a `<task-notification>` with the
command and a summary only — the output sits in an output file. Therefore:
- The listener **writes the message(s) to `.claude/board-pending.json`** (outside git) and prints one short line.
- The skill: on any wake from the listener, run `link.sh pending` — it prints each pending message inside the fixed frame
  (`📥 הודעה מהלוח · #<id> · מאת: … · ערוץ: …`, then the body). Handle, `channel-ack`, then restart the listener.

**The hooks** are registered in `.claude/settings.json` (shared by every chat in the repo):
- **Active only when `.claude/board-link.json` exists.** Without it — `exit 0` immediately. An unregistered chat notices nothing.
- **No loops:** `hook-prompt.sh` skips a prompt that starts with `<task-notification>` (listener wakes, `send_later`
  backups and every other background event) and one that starts with `📥 הודעה מהלוח`. The reply to a board message is
  posted with `reply_to` (`link.sh pending` writes `pending_reply_to` into the link file; `hook-stop.sh` uses and clears it).
- Inter-chat `📨` messages (create_trigger) are mirrored as `system`, not `owner`.
- `hook-stop.sh` takes `last_assistant_message` from the hook input — no transcript parsing.
- Network failure → silent `exit 0`, `timeout 5`. Bodies over 20,000 characters are truncated with "…(truncated)".
- For testing, hooks in `.claude/settings.local.json` load immediately without restarting the session — use that before
  touching the shared `settings.json`.

**The listener:** `run_in_background`, `timeout` 7,000,000 ms (just under two hours). Measured: a background command exiting
wakes an idle chat in **under 2 seconds**. It exits on a message **or** on timeout — either way the chat wakes, handles it
(or not), and restarts it. `listening_until` is sent to the server, and the UI shows "🟢 listening". 5 s between requests
held 34/34 without a block; keep ≥ 3 s.

**The `claude-tasks-live` skill:** register (`link.sh register`), start the listener, schedule the hourly backup (`send_later`),
handle a message (`link.sh pending` → act → `channel-ack` after handling, not before), restart, disconnect at the end.
`claude-tasks-worker` links to it.

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

### 5.8 What must be verified for real (first thing in stage 2, before building the server side)

Moved forward from stage 3 (2026-11-01): it costs one small script and decides whether the server side is worth building as
specified. Use a throwaway listener against an existing endpoint (e.g. `health.php` in a `curl` loop that exits when a test
file appears) — no new server code needed. Write the results into this section before starting the channel tables.

1. A background command that exits **wakes an idle chat** (not only a chat mid-turn). How long after?
2. Does the container stay alive while a background command runs, or is it reclaimed after inactivity anyway? After how long?
3. Does background-command output go through `UserPromptSubmit`?
4. Does the `Stop` hook receive `transcript_path`, and can the last message be extracted from it?

**Results (2026-11-01, code writer, against the live board):**

| # | Result |
| --- | --- |
| 1 | ✅ The chat's turn had ended; a 3-minute `curl` loop on `health.php` exited at 15:39:30, and `UserPromptSubmit` fired at 15:39:30 — **under 2 seconds** |
| 2 | ✅ A 55-minute loop (one request every 20 s): 161/161 answered `200`, no gap, and its exit woke the chat. ⚠ The owner was talking to the chat during part of that window, so "a completely quiet chat for an hour+" is still unproven — the hourly `send_later` backup stays mandatory |
| 3 | ✅ Yes, but as a `<task-notification>` with the command and a summary only — **not the output** (→ 5.6: the listener writes `.claude/board-pending.json`) |
| 4 | ✅ Yes — and also `last_assistant_message`, the full text of the last reply (→ 5.6: no transcript parsing) |

Also found: the hosting's bot protection (`409` + `humans_21909=1`, → 5.6); 34/34 requests at 5 s and 161/161 at 20 s with the
cookie, no blocking; hooks in `.claude/settings.local.json` load immediately without restarting the session.
**The engine was blocked:** `agent/run.mjs` got `409` on every board call, including its failure report — fixed in stage 2
(it sends the cookie, and on a `409` that sets a different one it retries once). Repos with the engine already installed need
"Install engine" again to get the new file.

If 1 fails — the bridge falls back to the backup only (up to an hour of latency), and the owner is told before continuing.
**A change to `.claude/settings.json`** affects every chat in the repo — so the hooks must be a no-op without a link file, and are
tested in a clean chat before merging.

---

### 5.9 Opening chats — a ready prompt per project, a "long chat" alert, a follow-up chat in one click (owner request 2026-11-02; **stage 11**)

**Why** (the first v2 chat, "היצירה v2 - סטודיו העיצוב", project 6, channel #1): it worked, but the owner hit four traps —
copied a **sub-project's** token instead of the root's; opened the chat in a **different environment** than the one holding
the token; the prompt still had `[ ]` and an empty `___` project number, and the board shows the project id nowhere except
"token for chats"; and a leftover `CLAUDE_TASKS_PROJECT=10` in the environment pointed the chat at the wrong project.
Rule from now on: **the owner never fills anything in by hand.**

#### 5.9.1 Decisions (mine, per the owner's delegation)

| Question | Decision | Why |
| --- | --- | --- |
| One generic skill or a file per project? | **One generic flow in `claude-tasks-live`** + a prompt the board **generates** per project and role. No per-project files in git | Project data lives in the board; a file per project goes stale and needs a PR for every new chat |
| Where the template lives | **In the board**, per project, **inherited** field by field from ancestors (set it once on "חבילת היצירה", every sub-project gets it) | Same inheritance as route/policy |
| Where the project id comes from | **Only from the prompt** (`--project N`). `CLAUDE_TASKS_PROJECT` is **ignored** (removed from the skill and `link.sh`) | An environment is shared by many chats; a project id there is wrong for all but one |
| Which token | **One token per environment = the root's.** Sub-projects no longer show a token: their "token for chats" row says "uses the token of <root> — open" | The root token already covers the whole tree (stage 1) |
| Environment confusion | The board stores the **environment name** (text, set once by the owner) and prints it in the "open a chat" sheet: "open the chat in environment **Default Cloud Environment**". The prompt starts with a preflight that stops with a clear Hebrew message if the token is missing | The board cannot see environments; it can only say which one, and fail loudly |
| Project id visible | `#6` next to the name in the ⚙ header card and in the breadcrumbs | Asked for directly |

#### 5.9.2 The template — data and UI

```
chat_templates(project_id PK,           -- NULL fields inherit from the nearest ancestor that sets them
  name_pattern TEXT,    -- "היצירה v2 - {role}"
  roles TEXT,           -- JSON [{key, label, icon, reports_to, brief}]  e.g. המתכלל / האפיון / רסטר …
  context_files TEXT,   -- paths to read first, one per line (CLAUDE.md is always implied)
  hierarchy TEXT,       -- a few lines: who is above whom
  notify_session TEXT,  -- session to notify on join (default: board-wide config, = the "מטלות לקלוד - אפיון" chat)
  extra TEXT,           -- free instructions appended to every prompt
  updated_at)
config: chat_environment_name (text), chat_notify_session_default
```

- **Project ⚙ → new row "🆕 צ'אט חדש"** (subline: "4 תפקידים · סביבה: Default Cloud Environment").
  The sheet: **role tiles** (2×2 grid, icon + label); tapping a role shows the **finished prompt** (read-only, monospace),
  a big **"📋 העתקה"** and **"פתיחת Claude Code"** (opens `https://claude.ai/code` in a new tab). Above it, one line:
  "בחרו בסביבה: **<name>**". A ✏️ opens the template editor (labelled fields; each inherited field shows
  "יורש מ: <project>" and a "שינוי כאן" link).
- **Pre-filled prompt via URL:** test whether claude.ai/code accepts a prompt in the URL (e.g. `?q=`). If it does, the button
  opens with the prompt filled; if not, copy + open, and the toast says "הפרומפט הועתק — הדבקה בצ'אט החדש". Record the result here.
- Template edit = `owner` (it shapes every chat in the subtree).

**The generated prompt** (Hebrew to the chat, fixed skeleton; every value filled by the board, never a placeholder):

```
אתה "<name from pattern>" — צ'אט <role label> בפרויקט "<project path>" (#<id>) בלוח "מטלות לקלוד".
1. הפעל את הסקיל claude-tasks-live והירשם:
   claude-tasks/bridge/link.sh register --project <id> --name "<name>" --role "<key>" [--replaces <channel_id>]
   אם הרישום נכשל — עצור ודווח לבעל המערכת את ההודעה כפי שהיא (היא אומרת מה לתקן).
2. קרא: CLAUDE.md, <context files…>
3. ההיררכיה: <hierarchy>. אתה מדווח ל: <reports_to>.
4. בסיום הרישום — שלח הודעת הצטרפות (create_trigger) ל-<notify_session>.
<brief of the role>
<extra>
[<handoff block — only for a follow-up chat, 5.9.4>]
```

**Preflight in `link.sh register`** — each failure prints one Hebrew line that says exactly what to do:
`CLAUDE_TASKS_TOKEN` missing → "הצ'אט נפתח בסביבה בלי האסימון. פתחו צ'אט חדש בסביבה '<name from board, if reachable>'";
token does not cover the project → (server, new error code) "האסימון בסביבה הזו שייך ל-<project>, ולא מכסה את #<id>.
שימו בסביבה את האסימון של <root>"; `CLAUDE_TASKS_PROJECT` set → a warning that it is ignored.

#### 5.9.3 "Long chat" alert

- `hook-stop.sh` measures, at most once per 5 minutes: `transcript_bytes` (size of `transcript_path`), `turns`, and
  `compactions` (count of compaction-boundary entries in the transcript — the strongest sign that context ran out once).
  It sends them with a new action **`channel-health { bytes, turns, compactions }`** — stored on `channels`, **not** a message
  (no noise in the conversation).
- Levels (in `config.json`, these defaults): **🟡 "הצ'אט מתארך"** at ≥ 1.5 MB or ≥ 1 compaction;
  **🔴 "הצ'אט ארוך — מומלץ צ'אט המשך"** at ≥ 3 MB or ≥ 2 compactions. Shown as a chip on the channel row and as a
  bar at the top of the conversation screen, with the "🔁 צ'אט המשך" button inside it.
- A channel that never reported (old skill) shows nothing — no guessing.

#### 5.9.4 Follow-up chat in one click

1. **"🔁 צ'אט המשך"** (owner) on the channel screen → the board sends an `in` message, `kind: handoff_request`.
2. The skill (`claude-tasks-live`, English): on `handoff_request`, write a handoff in a fixed structure — **state & key values ·
   decisions (with why) · open items · files touched · sessions & branch · what the next chat must do first** — and post it
   with `link.sh handoff <file>` (`channel-post kind: handoff`). It then keeps working only until told it was replaced.
3. The board builds the follow-up prompt = the template prompt for **the same role** + `--replaces <old channel id>` + the
   handoff block. The sheet shows it ready to copy/open (as in 5.9.2). If no handoff arrives within 2 minutes (dead
   container), "בלי תקציר" builds the block from the last 30 channel messages instead.
4. `channel-register` with `replaces` (same project subtree; owner-issued prompt) → the old channel gets
   `status: replaced`, `replaced_by`; the old chat receives a `system` message "הוחלפת על ידי <name> — סיים ואל תמשיך";
   the board shows "הוחלף ← <new>" on the old row (greyed, still readable) and the new chat inherits the open
   `asked_channel_id` tasks of the old one.

#### 5.9.5 Join notice

The join notice goes to the template's `notify_session` — by default the board-wide one, set to **this chat
("מטלות לקלוד - אפיון")**, not to the creative suite's coordinator. The coordinator gets it only if its template says so.
The board also posts a `system` message "<name> הצטרף" in the channel, so the notice is never the only record.

**Done when:** a new chat opened from the sheet, with nothing typed by hand, registers on the right project; a wrong token
and a missing token each stop with the Hebrew line above; `CLAUDE_TASKS_PROJECT` is ignored; the 🟡/🔴 levels show from
`channel-health`; a follow-up chat replaces the old one with a handoff, tasks move over, and the old row shows "הוחלף";
the join notice reaches `notify_session`. Tests for the template inheritance, the register errors, `replaces`, and the health
levels; screenshots 390/320 light/dark.

### 5.10 Multiple-choice questions (AskUserQuestion) on the board (owner request 2026-11-02; **stage 11b**, with 11)

**Today:** the bridge does not see them. The hooks are only `UserPromptSubmit` and `Stop`; while the question dialog is
open the turn is blocked, so `Stop` never runs — nothing reaches the board, and an answer written on the board waits in the
queue until someone answers in the app. (Example: the design-studio chat asked "which project to register in", 2 options.)

**Decision — option (a), plus printing the question in the chat:**
- A `PreToolUse` hook with matcher `AskUserQuestion` (`hook-choice.sh`), **active only in a registered chat** (link file),
  posts the questions to the board and **denies** the tool, with this reason to the model: *"The question was sent to the
  board (message #<id>). End your turn now, after printing the same questions in the chat as numbered options, so the owner
  can also answer here by typing the number(s) or free text. Do not ask again with the tool. The answer will arrive as a
  board message or as the owner's next prompt."*
- **Why not (b):** with the dialog open, a board answer cannot reach the chat until the dialog is closed in the app, so the
  board would be decorative. With (a) the owner answers **from either place** — tap on the board, or type in the chat — and
  the chat is never stuck behind a dialog nobody sees.
- An unregistered chat is untouched (the hook exits 0 and the dialog works as usual).
- **Per-channel override:** `channels.questions = board | app` (default `board`). `app` = the tool runs normally; the hook only
  mirrors the question (no deny), and a `PostToolUse` hook mirrors the chosen answer and marks it answered. Set from the channel
  screen (⋯ → "שאלות בחירה: בלוח / באפליקציה"). For when the owner works at the computer and wants the dialog.

**Data / API:**
- `channel-post kind: choice` with `meta.questions` = the tool input as is (`question`, `header`, `multiSelect`,
  `options[{label, description}]`, up to 4 questions). Stored `out`, `author_role: chat`, status `open`.
- Answer from the board: `choice-answer { message_id, answers: { "<question>": "<label>" | ["<label>",…] | {other: "<text>"} } }`
  (`owner`, or whoever may answer on the project) → an `in` message `kind: choice_answer`, `reply_to` the choice, body formatted
  for the chat: `תשובה לשאלה #<id>:\n• <header>: <label(s) or free text>`; the choice is marked `answered` with the answers.
- Answer typed in the chat: `link.sh` keeps `pending_choice` in the link file; `hook-prompt.sh` posts the owner's prompt with
  `reply_to` = that choice, and the server marks the choice `answered` (`answered_via: chat`, body as the answer). The pending
  choice is cleared. A board answer after that → 409 "כבר נענתה בצ'אט".
- Double answer race: the first one wins (atomic update on `status = 'open'`); the second gets 409 and the UI refreshes.

**UI (conversation screen):** a choice bubble — per question: the header as a small chip, the question bold, then **one full-width
button per option** (label bold, description grey under it); `multiSelect` → toggle buttons with ✓; an **"אחר…"** button that
opens a text field. With one single-select question, a tap sends at once; otherwise a "שליחה" button enabled when every
question has an answer. After answering: the bubble collapses to "✓ נענתה (בלוח/בצ'אט): <answers>". The channel row shows
❓ for an open choice, and it counts in "ממתין לך".

**Skill (`claude-tasks-live`, English):** explain the deny ("the question went to the board — print it as numbered options
and end the turn"), and that a `choice_answer` message or a prompt replying to the choice **is** the answer — act on it.

**Done when:** in a registered chat, an `AskUserQuestion` appears on the board within seconds, the chat prints it and ends
the turn; a tap on the board wakes the chat with the answer; a number typed in the chat marks the board bubble answered;
multi-question and multiSelect work; `questions: app` keeps the dialog and mirrors the answer; an unregistered chat is unaffected.
Tests for `choice-answer`, the race, and the typed-answer path.

### 5.11 The conversation screen — translate, pin, label, and ⚙ chat settings (owner request 2026-11-02; **stage 12a–e**)

**The owner asked for:** (1) a translate button on every message, into a preferred language set in the app's settings —
so a chat that answered in English reads in Hebrew with one tap, **without asking the chat to switch language and spending its
tokens**; (2) pin messages and tag them with labels, then search by label; (3) the 🔌 button at the top of the conversation
becomes **⚙ chat settings** — a menu with everything: labels, search, filter by labels.

The code writer's expanded proposal (message of 2026-11-02 08:18) is **accepted as the design**, with the decisions below.
Where this section is silent, that proposal holds.

#### 5.11.1 Decisions — the owner's (2026-11-02), replacing my first draft

| Question | Decision |
| --- | --- |
| Shared or personal? | **The label decides.** Each label is created with a **visibility**: `shared` (everyone who sees the channel sees the mark) or `private` (only the user who marked it), and a **scope**: the whole board (`project_id NULL`) or this project (and its subtree). A mark with a private label is stored with `user_id` and returned only to that user |
| Pins | **The same two kinds:** the message menu has "📌 הצמד לכולם" and "📌 הצמד לי" (`message_pins.visibility`, `user_id` for private). The pinned strip shows both, the private ones with a small 🔒 |
| Translation engine | **Free only — no AI, no cost, no server.** On the device: (1) Chrome's built-in **Translator API** when available (desktop Chrome 138+; it is **not** available on Android); (2) otherwise an **on-device model**: Helsinki-NLP **Opus-MT** (`en→he`, `he→en`, quantized ONNX) run with **transformers.js** in a Web Worker. No engine available → the 🌐 button is disabled with the reason |
| Model download | Opt-in, once per device: Settings → Account → "🌐 תרגום במכשיר" → "הורדה (≈80–100 MB לכל כיוון)", with a Wi-Fi hint and real progress (taste.md: "X of Y MB · ~N left"). Cached by the browser (Cache Storage); "הסרה" frees it. Model files come from the Hugging Face CDN at a **pinned revision**; the transformers.js library itself is served locally from `assets/vendor/`. Not in the repo, not on our server (tens of MB per pair through `rsync` on shared hosting is the wrong place) |
| Other languages | The preferred-language list offers only languages with an available pair; others pivot through English (two models). Phase 1: Hebrew ↔ English |
| Where translations are kept | **On the device only** (IndexedDB, key = message id + language). It is free, so a server cache buys nothing; no `message_translations` table, no `translate` action, no `usage` row |
| Auto-translate | **None** in this stage — a button only |
| Stage order | **12a** ⚙ chat settings (info, name and color, mute, disconnect and archive in the red zone — replaces 🔌) → **12b** message actions (menu on long press / ⋯: copy, reply, make a task, link) → **12c** pins and labels with their kinds → **12d** search, filter, export → **12e** on-device translation. 12e may move earlier if the check below passes quickly |

**Before building 12e — measure on the owner's Android phone** (record the numbers here): download size and time for `en→he`;
time to load the model from cache; time to translate a typical chat reply (≈ 150 words, with a code block left untouched);
memory. **Acceptable:** a cached reply translates in ≤ 5 s on the phone. If not — stop and report; do not ship a button that
freezes the screen. Check also that the exact Hugging Face repo for `opus-mt-en-he` / `opus-mt-he-en` in ONNX exists (convert
once with Optimum and host it ourselves only if it does not).

#### 5.11.2 Rules that must hold

- **Translation is display only and stays on the device.** The message text is never sent anywhere to translate it, never
  sent to the chat, and the stored message never changes. What the owner writes is never translated on its way to the chat.
- **Markdown in, Markdown out:** translate text segments only — code blocks, inline code, URLs, paths, file names, ids
  (`#1234`, `session_…`) and numbers are cut out before translation and put back after.
- **The 🌐 button shows only when the message is probably not in the preferred language** (a cheap script-ratio check).
  The bubble shows "🌐 תורגם מאנגלית · הצג מקור"; the choice per message is remembered on the device.
- **Bubble stays clean:** in the meta line only 🌐 (when relevant), 📌 (when pinned) and small colored label chips. Everything
  else is in the message menu (long press on a phone, "⋯" on hover/focus on desktop): 📌 for everyone / for me · 🏷 · 🌐 ·
  📋 copy (original Markdown) · ↩ reply · ➕ make a task · 🔗 copy link (`#m<id>`).
- **Labels:** 8 fixed colors, accessible in light and dark — no free color. Creating one asks for name, color, **visibility**
  (shared / private) and **scope** (board / this project). Quick "+ תווית חדשה" from the message menu; full management in ⚙.
  Deleting asks for confirmation and says how many messages carry it. None are created automatically.
- **Permissions:** shared pin / shared label mark = whoever may `channel-send` on the project; private ones = any viewer, for
  themselves only; creating a shared label or a board-wide one, and disconnect / archive = `owner`.
- **⚙ chat settings** = a `.sheet-wrap` with rows that open sheets, exactly like project ⚙ (§10.2): 🔎 search · 🏷 filter ·
  📌 pinned · 🏷 manage labels · 🌐 translation (language for this chat) · ℹ️ about (name, role, project + #id, channel #,
  session link, registered, last seen, listener, route 💬/☁️) · ✏️ name, role, square color · 🔔 mute · 📏 length and 🔁
  follow-up chat (§5.9) · ⬇️ export Markdown (all or filtered; private marks only for the exporting user). **Danger zone at
  the bottom:** 🔌 disconnect and 🗄 archive, each with a confirmation, owner only.
- **Active filter** shows as removable chips above the conversation; search highlights matches and steps ▲▼ through them
  (translations on the device are searched too, when present).

**API** — as proposed, minus `translate` (on the device now): `message-pin` (`visibility`), `labels`, `label-save`, `label-delete`, `message-label`, `channel-search`,
`channel-update`, `channel-export`; `labels` carry `visibility`; `channel-messages` adds `pinned` and `labels[]` (private ones only
for the viewer). Users: `users.translate_lang` (default `he`), set in Settings → Account → "שפה לתרגום" (short list + "other" as BCP-47).

**Done when** (per sub-stage): the proposal's test list (translation: code untouched, nothing leaves the device, disabled with no
engine; pin/label permissions, deleting a label clears it; label + text + kind filters combined), screenshots 320/390
light/dark, keyboard access to the message menu, `aria-pressed` on pin. A row in §14 for each of 12a–12e, and the phone measurements for 12e above.

## 6. Stage 5 — approvals & policy

As "PR 2" in the answer to the command-center request, with two changes:
- `decide` is `owner` only in an `owner_only` subtree (instead of "a project with `suite`").
- `ask-approval` from a channel token → also a 🔐 message in the channel; `decide` → also an `in` message of kind `decision`.

UI: a 🔐 card in the task sheet **and as a bubble in the channel** — summary, detail in `<details>`, and 4 buttons in a 2×2 grid:
"Once" · "Always" · "No" · "Never". "Always" on a `warn` type opens a confirmation that asks to type the type's name.

### 6.1 Approvals per project — managed and editable (owner request 2026-11-01; **stage 7b**, right after stage 7)

Stage 5 built one **global** policy table (`policy(type PK)`). The owner: *"for each project, manage the approvals that
were granted and denied, so they can be changed."*

**Data:**
- `policy` gets `project_id` (NULL = board-wide default). Key: `(project_id, type)`. `policy_log` gets `project_id` too.
- **Resolution** for a task: its project → each ancestor up to the root → the board-wide row → `ask`. The nearest rule wins,
  so a sub-project can override its parent ("allowed in the suite, but never in raster").
- **Migration:** existing rows stay as board-wide (`project_id NULL`) — nothing changes in behaviour on deploy.
- `decide always|never` writes the rule **on the task's project** (not board-wide). A task with no project → board-wide.

**API** (the decider rule from stage 5 — `canAnswer` on that project — for every write):
- `policy?type=&project_id=` → `{ verdict, source: { project_id, name } | "board" | null }` (a worker passes nothing —
  the project comes from its token/channel scope or from `task_id`).
- `policy-list?project_id=` → for **every type in the catalog**: the effective verdict, where it comes from (this project /
  inherited from X / board / none), and this project's own rule if any; plus this project's log (last 100).
- `policy-set { project_id|null, type, verdict: ask|allow|deny, confirm_type? }` — `ask` deletes this project's rule (so the
  inherited one applies again). Same catalog limits as `decide`: `no` types cannot be `allow`; `warn` + `allow` needs
  `confirm_type`. Every change → `policy_log` with `prev` and the user. Replaces `policy-revoke` (kept as an alias for `ask`).

**UI — project ⚙ → new row "🔐 אישורים"** (subline: "3 מותרים · 1 חסום" / "אין כללים"):
- `.hint`: "מה קלוד רשאי לעשות בפרויקט הזה בלי לשאול. כלל כאן גובר על מה שבפרויקט האב."
- **One row per catalog type**: the type's Hebrew label bold, a grey subline with where it comes from ("כאן" / "יורש מ: חבילת
  היצירה" / "כללי לכל הלוח" / "לא נקבע"), and a three-option `.seg`: **שואל · תמיד מותר · אף פעם**. Inherited values show
  the segment of the inherited verdict outlined (not filled), so it is clear nothing is set here. "תמיד מותר" is disabled for
  `no` types (with the reason under the row); for `warn` types it asks to type the type's name.
- Rows with a rule set **here** come first, then the rest.
- **History** below — "מה הוחלט" — one row per event, newest first: ✅/⛔/↩︎ icon, the type, old → new, who, when, and the
  task it came from (tappable) when it came from a `decide`. Day separators. Also the one-off decisions (`once` / `deny`)
  from this project's tasks, marked "פעם אחת", so the owner sees everything that was approved or refused — not only rules.
- The board-wide defaults: Settings → "מערכת" → "🔐 אישורים כלליים" — the same sheet with `project_id = null` (owner only).

**Skills** (`claude-tasks-worker`, `claude-tasks-live` — English): `policy` is per project now; nothing else changes for them.

**Done when:** tests — resolution order (child overrides parent, parent overrides board), migration keeps old rows board-wide,
`decide always` lands on the task's project, `ask` clears only this project's rule, catalog limits on `policy-set`, permissions;
screenshots of the sheet 390/320 light/dark.

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

### 10.1 Connections — rebuilt (owner request 2026-11-01, moved forward: **stage 3a**, right after stage 3)

The owner, after #1095: "who is connected and who isn't" is not visible, disconnecting is hidden, adding is a fixed list, and
there is only one ★ default. ✅ Decisions (2026-11-01): **two groups**; **automatic fallback** by priority; **several GitHub
accounts** and **several keys per provider**.

**Data — one table instead of `users.github_*` + `user_providers`:**

```
connections(
  id, user_id, kind github|anthropic|openai|google,
  label TEXT,              -- "personal", "work" — shown next to the kind; default = login or "…4f2a"
  secret TEXT,             -- encrypted with lib/crypto.php, never returned by the API (last 4 only)
  login TEXT, scopes TEXT, -- github
  model TEXT,              -- ai
  priority INTEGER,        -- ai only; 1 = first. Unique per user among ai rows
  status ok|failing, last_error TEXT, failing_until INTEGER, last_ok_at INTEGER,
  created_at)

project_connections(project_id, user_id, connection_id, PRIMARY KEY(project_id, user_id))  -- github choice, per user
tasks.connection_id NULL   -- a specific ai connection; NULL = auto (priority order)
```

**Migration (careful — secrets):** copy the existing ciphertexts **as-is** (same key, same `encryptSecret` format — no
decrypt/re-encrypt round trip) into `connections`; `priority` from today's ★ first, then provider order. Keep the old columns
**read-only** for one release, then drop them in a later PR. A test decrypts every migrated row and compares it to the old
column. Back up `data/tasks.sqlite` before the migration runs (the same `.bak-<date>` as `owner-access.php`).

**GitHub — which connection a project uses:** the rule "an action goes out with the token of whoever performs it" stays.
For the acting user: their row in `project_connections` (inherited up the tree) → their connection whose `login` = `repo_owner`
→ their first connection that has access to the repo (probed once, cached) → error "no connection of yours reaches this repo".
Project ⚙ gets a row "my GitHub connection" (a picker over **my** GitHub connections).

**AI — priority and fallback:**
- Order = `priority`. A task with `connection_id` uses only that one (no fallback — the user chose it).
- **Falls over to the next** on: 401/403 (bad/expired key), 429 / "insufficient credit" / quota, 5xx or timeout after one retry.
  **Not** on a 400 (the prompt itself is wrong — another provider will fail the same way).
- A failing connection is marked `failing` with `last_error`, skipped for 10 minutes (`failing_until`), then tried again.
- Every fallback writes a note on the task ("עבר ל-OpenAI · עבודה: מפתח Anthropic נדחה (401)") and an `events` row.
- **Quota (stage 7):** all connections of one user count against that user's single monthly quota, in USD — so fallback
  never bypasses it. At 100% the chain is not tried at all.
- The catalog and every other `lib/ai.php` caller go through the same chain. The engine (`run.mjs`) keeps one key
  (`AGENT_AI_KEY` = the first connection at install time); fallback inside the engine — later, not now.

**UI (Settings → Connections tab):**
- **"מחוברים (N)"** first. Two sub-groups with small headers: **קוד** (GitHub rows) and **בינה מלאכותית** (AI rows, in priority order).
  Row: icon in a colored square, **kind + label** bold, subline "🟢 מחובר · …4f2a · claude-sonnet" or **"🔴 נכשל · מפתח נדחה · לפני 5 דק'"**,
  and at the end a visible **🔌** icon button (`aria-label="ניתוק <name>"`). AI rows also show the number (1, 2, 3) and **↑ ↓**
  icon buttons to reorder (no drag — reliable on a phone).
- Tap a row → a sheet: label, model picker (#1095's `provider-models`), "בדיקה" (test now — clears `failing` on success),
  and "ניתוק" as a red button with a confirmation. Disconnecting a GitHub connection that projects use → the confirmation
  lists them and says they fall back to the rule above.
- **"+ חיבור"** (word, primary) → a sheet with **tiles 2×2**: GitHub · Anthropic · OpenAI · Gemini → a short form. A new AI
  connection goes **last** in the order.
- Empty state: "אין חיבורים עדיין. ‎+ חיבור כדי להתחבר לגיטהאב או לספק בינה."
- The task sheet's provider picker becomes "אוטומטי (לפי הסדר)" + one option per AI connection.

**Done when:** tests cover migration (every secret decrypts identically), fallback (401 → next, 400 → no fallback, failing
skipped then retried), per-user GitHub resolution; screenshots 390/320 light/dark; the owner sees connected vs not at a glance.

### 10.2 Project settings (⚙) — rebuilt (owner request 2026-11-01: "more readable, more organized"; **stage 3b**, with 3a)

**What is wrong today** (owner's screenshot, project "Mini projects"): one long scroll of forms — details, structure, members,
skills, engine, files — each with paragraphs of explanation; **"שמירה" and "🗑️ מחיקה" side by side with equal weight**; the
members block shows a member row and then two unlabeled dropdowns + "הוספה"; the engine block is three paragraphs and two buttons.

**The new screen — a summary, then rows (taste.md: "records → rows", "long screen → summary first"):**

1. **Header card** (not chips in a row): the project icon large, the name bold, a subline `malkiel01/mini_projects · main`,
   and one line of state chips: "הרשאתך: ניהול" · "🔒 רק בעל הלוח" (if set) · "⚙️ מנוע מותקן" / "מנוע לא מותקן".
   ✏️ next to the name → the "Details" sheet.
2. **Rows** — icon in a colored square, bold title, grey subline with the **current value**, chevron ‹; the whole row taps:

| Row | Subline (example) | Opens a sheet with |
| --- | --- | --- |
| ✏️ פרטים | "Mini projects · 📁" | name + icon **side by side**, folder path; "שמירה" |
| 👥 חברים | "3 · מלכיאל (ניהול), …" | member rows; "+ חבר" (see below) |
| 🔗 חיבור הגיטהאב שלי | "personal · malkiel01" | picker over my GitHub connections (§10.1) |
| 💬/☁️ מי עונה | "💬 הצ'אטים" | the route `.seg` (stage 6; hidden until then) |
| 🧩 סקילים | "אין עדיין" / "4" | skill rows + "+ סקיל" |
| ⚙️ מנוע ההרצה | "לא מותקן" / "מותקן · npm test" | check command, "התקנה" / "התקנה מחדש" |
| 🔑 אסימון לצ'אטים | "הצגה" | the registration token (§5.5), copy button, regenerate |
| 📂 קבצים | "עיון בריפו" | the existing file browser |
| 🏗️ מבנה | "רמה עליונה" / "בתוך: חבילת היצירה" | "רק בעל הלוח" switch + "העברה לתוך פרויקט" picker |

   Rows the viewer cannot use are hidden, not disabled (a `read` member sees Details read-only, Members, Files).
3. **Danger zone, last and separate:** a thin red-bordered card "מחיקת פרויקט" with one line — "רק פרויקט ריק, בלי מטלות ובלי
   תתי-פרויקטים" — and a red text button. Disabled with the reason when not empty. Confirmation types the project name.
   **Never next to "שמירה".**

**Inside the sheets:**
- **One `.hint` line** at the top of each sheet says what it is. The long explanations (skills, engine) move into a
  `<details>` "מה זה?" at the **bottom** — taste.md allows `<details>` only for genuinely secondary, long text, and this is that.
- **Members:** one row per member — initial in a circle, name bold, subline "גיטהאב: login" or "בלי גיטהאב" (yellowish when
  missing), the level as a small `.seg` of three: **צפייה · כתיבה · ניהול** (changes save immediately, with a toast), and a 🗑️
  icon button (`aria-label="הסרת <name>"`). "+ חבר" (word, primary) → a small sheet: a user picker **with a label**, the same
  three-option `.seg`, "הוספה". No unlabeled dropdowns anywhere.
- **Engine:** the state as a summary line first ("לא מותקן — מטלות פיתוח לא ירוצו לבד"), then the check-command field
  **with a label and an example**, then one primary button ("התקנה" or "שמירה" — not both side by side).
- Save buttons say what they save; a sheet with a single field saves on blur with a toast.

**Done when:** screenshots 390/320 light/dark of the main settings screen and each sheet; no horizontal scroll; no paragraph
longer than two lines outside a `<details>`; every input has a visible label; delete is not adjacent to any save.

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
| 2 | #1096 | checked after the merge — reported in the `עדכון` message | `author_role` on `in` messages is `authorRole()` (`owner` or `member`) — a `member` with `write` may send on a non-`owner_only` project, so `member` was added to the roles; the skill still acts only on `owner`. An answer by anyone allowed to answer (not only `owner`) is delivered to the asking channel, with its role. A channel token also works as a worker token for its subtree (`claim`, `block` …). The general token may register channels too. `channel-wait` records `listening_until = now + wait + 30`. The engine cookie fix (5.8) |
| 3a | #1101 | `conclusion: success` (run 1191) | The secrets are copied as ciphertext, never decrypted and re-encrypted; a `VACUUM INTO` backup comes first. The old columns stay read-only. GitHub chosen per project (inherited by sub-projects) → by repo owner → the first one. Each fallback writes a note on the task and an event. The test of a thinking model needs `MIN_OUTPUT_TOKENS` 2048 + `effort: low` (#1097) |
| 3 | #1100 | `conclusion: success` | The bridge scripts (`link.sh`, `listen.sh`) and the hooks in `.claude/settings.json` — no-ops without `.claude/board-link.json`. Verified locally end to end (register, the listener wakes the chat in ~2 s, the reply is recorded with `reply_to`, `<task-notification>` is not mirrored). **The real-board test is still open** — it waits for the owner to set `CLAUDE_TASKS_TOKEN` |
| 3b | #1102 | `conclusion: success` (run 1192) | One sheet per row; rows hidden by the viewer's level, not disabled. Member level is a three-way switch that saves at once. Delete needs the project's name typed and is disabled with the reason while the project has tasks or sub-projects. The engine sheet has one primary button (install, or save the check command when installed) |
| 4 | #1104 | `conclusion: success` (run 1195) | Inside a project the 💬 tab is always shown (its empty state is how a new chat gets connected); 📁 only when there are sub-projects. The default tab is 💬 when the project has chats, else 📋; the last one is remembered per device. `out` is the chat side (start/right), `in` the board side (end/left), system centered. The poll asks `after` the earliest undelivered `in`, so ✓ turns into ✓✓ without a second endpoint. Enter is a new line; Ctrl/⌘+Enter sends. Stages = topics with a `project_id`; the ✏️ sheet lists them and adds new ones. No server change |
| 5 | #1105 | `conclusion: success` (run 1196) | `lib/approvals.php`: the catalog from command-center §1.3, plus `other` (no "always", no "never" — it has no policy). Who decides = `canAnswer` (owner always; only owner in `owner_only`; else `write`). `decide` on a task that is not `awaiting_approval` → 409. `policy` is open to workers and people; `policy-list` to people; `policy-revoke` owner only, logged with `prev`. `channel-messages` attaches the request to each 🔐 bubble with `pending` and `can_decide`. UI: a 6th status chip "🔐 אישור" appears only when there are requests. "Always" is hidden for `no`/`other`, "Never" for `other`; `warn` asks to type the type name (`prompt`), and the server checks it too. `n_blocked` on a project counts both kinds of waiting. Worker skill: section 3a (English) |
| 6 | #1108 | `conclusion: success` (run 1199) | `lib/routes.php`: one pass over the tree resolves every project's route (inherit → parent → `config.default_route`, default `chat`). `claim` filters by `X-Worker-Route` (`code` → `chat`, none → `chat`, unknown → 400) and stores `tasks.route`; the `owner_only` skip in `claim` is gone (the lib parameter stays for direct callers). Changing a project's route is **owner only** (it decides on whose account), like `set-default-route`. `projects` returns `route` + `route_effective` and `default_route`; `channels` returns `route_effective` (a channel's own `route` wins; no UI to set it yet). UI: a two-option `.seg` under the breadcrumbs (disabled for non-owners, a hint when inherited or ☁️), and a "מי עונה" row in ⚙ with inherit / 💬 / ☁️ plus the board default. The bridge (`lib.sh`) sends `X-Worker-Route: chat`; both skills say so |
| 7 | #1111 | `conclusion: success` (run 1202) | `lib/usage.php`. Prices by longest model prefix (dated ids match); unknown model → the provider's highest price + `usage.unknown_model` event; an unknown provider → $10/$50. Tokens: Anthropic input includes cache read/write, Google output includes thinking. The payer is `connections.user_id` (each chain link carries it); the global system key has no owner and is not recorded. `userConn` defaults to `{kind: monitor, enforce: true}`; connection tests record but are never blocked. The quota is checked **once, before the first link** of a chain. The engine reports each provider call via `report-usage` (worker), charged to `tasks.run_user_id` set by `run-task` — repos need "reinstall engine". `projectRoutes()` downgrades ☁️ to chat when the root owner has no quota or is over it; `route_wanted` keeps the choice for the UI. 80% and 100% each send one `system` message per month (`usage_alerts`). UI: Settings → 📊 usage is the first tab (bar, stacked daily SVG with legend + table view — palette slots 1–3 validated with the dataviz script both modes), a mini bar under the ☁️ switch for the payer or admin, a warning line on the main screen, and a quota field per user in Admin → Users. `answer` is recorded once stage 8 answers |
| 7b | #1112 | `conclusion: success` (run 1203) | `policy` is rebuilt (SQLite can't change a PK): rename → new table with `id`, `project_id`, unique index on `(IFNULL(project_id,0), type)` → copy as board-wide → drop. Writes are delete + insert (no upsert on an expression index — safe on older SQLite at the host). `policy` takes `project_id`, or `task_id`, or the worker token's project. Board-wide rules: owner only; a project: `canAnswer` on it. `other` has no rules at all. `policy-list` returns `can_edit`, `counts` (local allow / deny for the ⚙ subline), and a merged history: rule changes (with `prev`) + `once` / `deny` decisions from that project's own tasks, newest first, 100. UI: filled = set here, outlined = what applies without a rule here (inherited, board, or the default "שואל") |
| 8 | #1113 | checked after the merge (owner asked directly: "תמשיך ל8") | `lib/answers.php`, `lib/monitor.php`. An `in` message on ☁️ is stored with `answered_by='platform'` and no bell; `platform-reply` claims it atomically (`platform-busy:<ts>`, reclaimable after 180 s), answers with the payer's chain (`kind: answer`, `enforce` checked once before), stores the reply as `out` / `author_role: platform` / `kind: answer` / `reply_to`, with `meta.task` when code is needed, marks the original delivered, and adds a `status` message for the chat with no bell — the inbox returns it, `channel-wait` never does. No quota / no key / the route went back to chat → the message is handed to the chat (answered_by cleared, bell rung). A model failure leaves it waiting for the next open. Context: last 30 messages, open tasks in the subtree, the folder's README via the payer's GitHub (skipped on error). Monitoring: the 3-line summary is the only model call; waiting items (oldest first), "already asked" (word Jaccard ≥ 0.55 against answered questions in the subtree) and the type suggestion (keyword map) need no model and show even when monitoring is off. `monitor-lazy` (owner, on board open) runs at most 2 stale (> 1 h) projects, those with waiting items first; after `block` / `ask-approval` — at most once per 10 minutes. Test temp-dir prefixes made unique (`platform.php` and `projects.php` shared `ctp-`) |
| 9 | #1115 | owner asked directly ("תמשיך ל9") | Already done in earlier stages: Settings tabs + Usage first (7), Connections rows (3a), Project ⚙ rows (3b). Here: Settings tabs as a grid (`auto-fit`, wraps to two rows on a phone) with a "👥 משתמשים" tab for the admin (the same list as Admin → Users, quota field included); Admin is a `.sheet-wrap` with `.seg-tabs` + counters (users; failures in the last 24 h, red); the log is one row per event — icon and Hebrew label by action, detail as "old → new" when the event carries it, who · target · time — with day separators, failures on a red background, and an "all / failures only" switch. Stage editing is a `.sheet-wrap`, title "עריכת שלב" / "עריכת נושא" by `project_id`, keywords a visible textarea. Login: error `role=alert`, fields `aria-invalid` + `aria-describedby`, focus returns to the field to fix (password, selected). No server change |
| 10 | #1118 | owner asked directly ("תמשיך ל10") | `projects.engine`: `loop` / `claude-code` / '' (inherit; root default `loop`); changing it resets `engine_at` (the repo needs the other files). `claude-code` installs `.github/workflows/claude-code-agent.yml` + `.github/claude-agent/finish.mjs` and the secrets `AGENT_BOARD_TOKEN` + `ANTHROPIC_API_KEY`; install and dispatch refuse a non-Anthropic connection. The workflow runs the Claude Code CLI (`npm i -g @anthropic-ai/claude-code`, `claude -p … --output-format json`) rather than `claude-code-action`, so the JSON report (`total_cost_usd`) is in hand; permissions `contents` + `pull-requests` only; `--disallowedTools` for `gh pr merge`, `gh workflow`, `gh run`, `gh api`, force / no-verify push; a `pre-push` hook rejects the base branch. `finish.mjs` runs `if: always()`: `report-usage` with `cost_usd` (recorded as given, `develop`, against `tasks.run_user_id`), then blocks the task with the reason and the run link if it is still `in_progress`. Verified end to end against the local board over HTTP. UI: a `.seg` "🔁 לולאה · 🤖 Claude Code" in ⚙ → engine, with what each does; the check-command field hides for Claude Code (the repo's CLAUDE.md drives checks) |
| 10.1 | #1122 | owner asked directly (the chat's formatting looked like raw `#` and `**` on the board) | Chat bubbles render Markdown like the chat does: headings, bold / italic / strike, inline and fenced code, quotes (the `> **סיכום:**` line), bulleted / numbered / task lists with nesting, tables (alignment; a wide table scrolls inside the bubble), links and bare URLs. `assets/js/markdown.js`: `parseMarkdown` returns a plain tree and `renderMarkdown` builds nodes with `createElement` / `textContent` — no `innerHTML` anywhere, so chat text is never parsed as HTML; links only `http(s)` (`javascript:` / `data:` stay text). A single Enter stays a line break (chat style). `_` inside words (`CLAUDE_TASKS_TOKEN`) is not italic. Each block gets `dir="auto"`; inline code stays whole (an RTL line used to split `greeting-cards/` at the hyphen and swap the halves). Bubbles only — task notes are unchanged. `tests/markdown.mjs` (node): 36 |
| 11b | #1133 | §5.10 as written | `lib/choices.php`: `messages.status` (open / answered) and `channels.questions` (board / app, default board). `channel-post` with `kind: choice` + `questions` → `choicePost` (normalized: ≤4 questions, ≤8 options, lengths capped; body = numbered text for search/export) and returns `{id, mode}`, so the hook knows whether to deny. `choice-answer` validates every question (label, list for multiSelect, or `{other}`), marks answered **atomically** (`UPDATE … WHERE status = 'open'`; the loser gets 409), and inserts an `in` `choice_answer` with `reply_to` that rings the bell. A typed answer: `hook-prompt.sh` sends the owner's prompt with `reply_to = pending_choice`; `channelPost` marks the open choice `answered_via: chat` (the chat's own replies never close it). `app` mode: the hook only mirrors; board answering is refused (409, answer in the dialog); `hook-choice-done.sh` (PostToolUse) posts `choice_answer` with the tool's `answers` → `answered_via: app`. `link.sh pending` clears `pending_choice` when a board answer arrives. Hooks fail open: if the board does not answer, the dialog runs as usual. UI: `assets/js/choices.js` — header chip, bold question, full-width option buttons with descriptions, ✓ toggles for multiSelect, "אחר…" field; one single-select question sends on tap, otherwise "שליחה" when complete; answered → collapsed "✓ נענתה (בלוח/בצ'אט/באפליקציה)" with the answers in a `<details>`. ⋯ in the conversation header opens "אפשרויות הצ'אט" with the board/app switch (the stage-11 ⚙ sheet will absorb it). Channel row: "🔘 N ממתינה לך"; `project-monitor` returns `choices` and "ממתין לך" lists them first, opening the conversation. Also fixed: the monitor card showed "nullnull" (`replaceChildren` turns `null` into text). `tests/choices.php`: 39; the hooks verified end to end over HTTP against a local board (deny, typed answer, board answer + `pending`, app mode, unregistered chat) |
| 11 | #1137 | §5.9 as written | `lib/templates.php`: `chat_templates` with field-by-field inheritance through `projectChain` (each field reports its source; empty = inherit); defaults: pattern `{project} - {role}`, one role, `notify_session` = config `chat_notify_session_default` or this spec chat. `chatPrompt` fills the §5.9.2 skeleton — no placeholders (tested: no `[ ]`, `___`, `< >`); it also tells the chat to set its session title. Names and patterns lose `" ` $ \\` so `--name "…"` stays one argument. Template edit and the environment name (`config.chat_environment_name`) are owner-only. **Register:** `AppError` now carries a machine code (returned as `code`); `token_scope` says whose token it is and whose is needed; `no_project` for an unknown id. `link.sh` reads `--project` only, warns on `CLAUDE_TASKS_PROJECT`, and maps a missing token / unknown token (401) to Hebrew lines that name the environment (from a new **public** `chat-env` action — a name, not a secret). Also fixed: `link.sh` read `BOARD_HTTP` from inside `$( )`, so the status code was always lost. **Health:** `channel-health` (channel token) stores bytes/turns/compactions/at on `channels`; `hook-stop.sh` measures at most every 5 minutes (`compact_boundary` entries, falling back to `isCompactSummary`); levels from `chat_warn_*` / `chat_alert_*`. A channel that never reported shows nothing. **Follow-up:** `handoff-request` (owner) → `in` `handoff_request`; `link.sh handoff <file>` → `out` `handoff`; `handoff-prompt` returns `{requested, ready, waited, can_fallback}` or the prompt; `fallback` only after 120 s, from the last 30 messages. `replaces` is validated **before** anything is written (a refused registration leaves no channel); then `status: replaced`, `replaced_by`, tasks' `asked_channel_id` move, and the old chat gets "הוחלפת על ידי…". A replaced channel's token still reads (so the old chat receives that message) but the board cannot send to it. Join: a `system` message in the new channel; `register` returns `notify_session` and `project_path`. **UI:** ⚙ → "🆕 צ'אט חדש" (role tiles, the prompt with the command on its own LTR line, 📋 copy, "פתיחת Claude Code" = copy + open), ✏️ template editor ("יורש מ:" / "↺ חזרה לירושה", role rows), sub-projects' token row opens the root's; the token sheet now prints `CLAUDE_TASKS_TOKEN` only — it used to print `CLAUDE_TASKS_PROJECT`, the source of the stage-11 trap. `#id` in the ⚙ card and breadcrumbs. Channel row: 🟡/🔴 chip, "הוחלף ← …" greyed; conversation: health bar with "🔁 צ'אט המשך", also in ⋯. **§5.9.2 URL test:** claude.ai/code has no documented prompt-in-URL parameter, and it cannot be checked from the build environment (the page needs a signed-in browser) — so the button always copies, then opens, and the toast says so. `tests/templates.php`: 52; `link.sh` register/handoff/replaces and the health hook verified end to end over HTTP against a local board |
| 12a | (this PR) | §5.11 as written (the 08:27 message predated §5.11.1; I followed §5.11.1 on main) | ⚙ replaces 🔌 and the 11b ⋯ menu in the conversation header. `lib/chatsettings.php`: `channels.color` (one of 8 fixed hues: blue/teal/green/olive/amber/orange/red/violet; '' = by role), `channels.hidden`, `channel_mutes(user_id, channel_id)`. Actions: `channel-info` (everything for the sheet, any viewer), `channel-update` (name/role/color = whoever may `channel-send`; `route` = owner; '' = like the project), `channel-mute` (per user, any viewer, for themselves), `channel-hide` (owner; an active chat is disconnected first; `false` restores), `channels` gains `include_hidden` + `hidden_count`. Mute = the chat is left out of "ממתין לך" for that user and the row shows 🔕 (the board has no push notifications to silence). Disconnect = the existing `channel-archive` (token revoked, row stays); archive = off the list into "🗄 N בארכיון". UI: summary card (name, ערוץ #, path · #project, status, 🔕), rows ℹ️ / ✏️ / 🔔 (toggles in place, `aria-pressed`) / 🧭 / 🔘 / 📏 + 🔁, red zone 🔌 / 🗄; sub-sheets about (a `<dl>`), edit (name, role, 8 swatches + auto), route seg, questions seg. Search, filter, pins, labels, translation and export rows arrive with 12b–e. `tests/chatsettings.php`: 26 |
