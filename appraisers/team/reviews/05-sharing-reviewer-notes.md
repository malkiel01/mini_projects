# Review findings — appraisers slice 05 (worktree /tmp/rv @93ffca4)
(incremental notes)

## Holds (VERIFIED-BY-RUN, scratch s1/s2)
- Enumerated every non-public/non-dev action in APR_ACCESS as partner (office B) with real office-A pids (case, file, fact, comp, visit, photo, check, requirement, task, party, contact, identifier, profile, user) vs bogus pids:
  byte-identical responses in states: no share / no office / pending / declined / expired (clock only, no tick) / revoked. No write to office A tables.
- re-accept of revoked share → 404; another user accepting someone's invite → 404; partner cannot revoke → 404.

## F1 🟡/🔴? internal file fields leak without see_internal (VERIFIED-BY-RUN s3.php view)
- case-docs as partner (scope all, perms view only): requirements[].files[].file contains "source_note":"הערת-מקור-סודית" and "uploader":"אבי".
  Author nulls these only in the `unassigned` branch (documents.php:573) but not for files linked to a requirement (documents.php:~564 apr_file_out($files[...])).

## F2 🟡 facts leak through comps to items-scope partner (VERIFIED-BY-RUN s4.php)
- share scope=items [comps] + see_internal: `fact`/`case-facts` → 404 (as declared), but `comp-set` returns `subject.fact` = full fact record (value 85.5, source note, entered_by) (comps.php:917 'subject' => apr_comp_set_subject ungated, only subject_areas gated), and `comp` returns similar.rows[].subject (subject fact values) (comps.php ~327-348/895).
## F3 🟡 support search across cases logs only first case (VERIFIED-BY-RUN s6.php)
- sysadmin docs-search?shared=1 returned hits from 2 support-shared cases; only ONE support.view row (case 2). shares.php:120 `$GLOBALS['apr_support_case'] ??= $c`.
## Holds (s5/s6): preview enforced server side (hash, user, case, single use); perms mass-assignment rejected; tal/dana w/o members → 403; partner re-share → 404; grant-more-than-own → share_perm_exceeds; support: no expiry/items/reason/edit rejected, confirm required; sysadmin cannot create support (403 share_sysadmin / no_office); sysadmin without share 404; expiry w/o tick 404.
## F4 🟡 search starvation / weak oracle (VERIFIED s7.php): items share on F1; after inserting 304 matching file_texts pages on hidden newer file F2, partner's docs-search shared=1 returns 0 (expected 1 F1 hit). extract.php:839 LIMIT 300 before visibility filter (same pattern documents.php LIMIT 200 for names/requirements).
## F5 🟡 download perm is cosmetic for PDF/images (VERIFIED s3): without download, `file?inline=1`, `file-thumb`, `visit-photo` return full original bytes (APR_FILE_PREVIEW = pdf/jpeg/png/webp). Only docx/xlsx/zip blocked. Spec letter (§6) allows preview; reviewer's requirement "no byte path" fails.
## ⚪ whole+see_internal partner: case-facts exposes conflicts [{pid,status,kind}] + conflicts_open while conflict/case-conflicts are 404 (author claims discrepancies fully closed); fact.history (audit rows with names/meta) visible.
## ⚪ share-holder/sysadmin: `office` param no longer validated on 'case' actions: cases?office=<foreign office pid> → 200 (own data) where plain member gets 404/office_changed (access.php:233 returns before apr_office()). Stale-tab guard lost.
## ⚪ apr_share_for per-request lookup = SCAN case_shares (EXPLAIN): no plain index on case_id (only partial unique indexes, unusable with the OR).
## Holds: migration 015 only file changed under migrations; applies on v14 DB with data (internal members avi/dana/tal/visitor/boaz identical responses on 22 read points before/after; sysadmin 403 no_office → 404 on case reads); re-exec ok; triggers reject edit/any non-listed key incl. nested/invalid JSON; support see_internal rejected.
## Holds: office A suspended → partner 404 & lists empty; partner suspended → 403 account_suspended; suspended member who is sysadmin → 403 on own office; tick warn once, expire → revoked, re-accept 404, re-share → pending; deleted case → 404.
## F6 🔴 party phone/name + access note via visit (VERIFIED s8.php): items share [visit V2] w/o see_internal → case-visits/visit return access_contact {pid,name:"לקוח-סודי",phone:"050-SECRET"}, access_note, attendees. visits.php:194 (apr_visit_out) used unchanged in ext branch visits.php:226-236. README claims "never: mail/phone of a party"; preview lists `contacts` as hidden.
## Mutations
- Author's mutations-05.php: run on a copy (/tmp/rv-scratch/mut): 60/60 killed, 0 NOT-APPLIED.
- Mine (61, /tmp/rv-scratch/mymut.php, on copy mut2): 42 killed by s-shares, 1 crash (tab=shared), 1 killed only by full suite (case_get office match), 17 SURVIVED full suite (baseline of copy = 3 ✗ unrelated git checks):
  file_visible pid-less guard; unassigned uploader/source_note hide; review_note/reviewer hide; party_pid/split_from hide; links filtered by visible files; respond on expired invite; sys-support kind filter; visit `others` filter; source-check facts/items/tasks gating (3); comp-set subject_areas gating; case view explain_internal for internal w/o see_internal; apr_case_perms_full defaults for pre-05 rows; support forced items scope; perms.view=false guard; support_case ??=.
## Not checked: browser UI behaviour, real HTTP layer (api.php headers), concurrency races, performance, Graphify n/a.
