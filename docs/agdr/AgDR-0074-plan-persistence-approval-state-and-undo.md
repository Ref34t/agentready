---
id: AgDR-0074
timestamp: 2026-07-28T09:10:00Z
agent: Hisham (Tech Lead)
model: claude-opus-5
session: 72885e90-35c2-4620-954f-2b33ab2c110a
trigger: user-prompt
status: executed
category: patterns
projects: [agentready]
---

# Plan Persistence, Approval State, and Undo

> In the context of the Mokhai Agent v1.0, where a plan spans several requests (generate → review → approve → apply) and each approval is a decision the owner should not have to make twice, I decided to persist the active plan and a 20-deep session history in size-capped options with `autoload: no`, to gate apply on an `approved` status settable only through an ownership-asserting route, and to make undo an explicit captured payload rather than a recomputation, to achieve a review flow whose approvals and reversals survive the gaps between requests without adding a table, accepting that undo has a finite lifetime bounded by ring-buffer eviction and that this must be disclosed to the owner *before* apply rather than discovered after.

## Context

Three storage-and-lifecycle commitments the build needs settled before #317 defines the value objects. All were resolved in the technical design (rev 5, Solution Architect APPROVED); this record fixes them in the public repo and states the consequences the implementation must not quietly drop.

The **action ID scheme** — `hash(type, target.kind, target.id)`, with `origin_reason_codes` as a plural audit-only field — is recorded in [AgDR-0073](AgDR-0073-action-derivation-inversion-and-ability-asymmetry.md) and deliberately **not** restated here. What this record adds is the *persistence* obligation that scheme exists to serve: an approval is stored against an action ID, so the ID must survive a re-generate of the plan. That is exactly why keying on the reason code failed — a coverage-band flip (`cr_coverage_low` → `cr_coverage_medium`) changed the ID and orphaned the stored approval. Persistence is the reason the ID scheme is load-bearing rather than cosmetic.

### 1. Plan persistence — a capped option, not a transient

| Data | Store |
|---|---|
| Active plan | Capped option, `autoload: no` |
| Session history | Same ring buffer, last 20, size-capped |
| Undo payloads | In the session record |
| Content undo | WordPress revisions |

**Why not a transient.** A transient is the reflexive choice for something this short-lived, and it is wrong here: object-cache eviction is **not TTL-bounded**, so a transient can vanish at any moment under an external object cache. Losing a plan mid-review does not merely cost a regeneration — it destroys the owner's approval decisions, which are the one thing in the plan that cannot be recomputed. An option with `autoload: no` is durable and stays off every page load.

**No new tables.** The whole model fits in options plus WordPress's existing revision system, so v1 ships no schema change and needs no migration.

### 2. Approval state and the write gate

An action's status is drawn from the set `proposed | approved | generated | applied | failed | skipped`. The common path is `proposed → approved → applied`; `generated` is traversed **only** by two-phase actions, so a `phase: 'direct'` action never enters it. `failed` and `skipped` are terminals. Two constraints make the flow safe, and both are properties of *state*, not of prose:

- **`Apply_Engine` accepts only `approved`.** No other status can reach a write.
- **`approved` is settable only via a nonce- and capability-gated route asserting `plan.user_id === current_user_id`.** Ownership is checked against the **stored plan**, not inferred from the request.

That second point is *not* an argument that a WP Ability could not express the same check — [AgDR-0073](AgDR-0073-action-derivation-inversion-and-ability-asymmetry.md) § 3 explicitly refutes that framing, and this record does not reopen it. The relevant connection is narrower: reading plan state to authorise, and then re-reading it to write, is a check-then-act split (AgDR-0073's reason 3). Asserting ownership inside the same request that performs the write is what closes that window.

**Two-phase actions.** Where the "after" does not exist at plan time, the action carries `phase: 'generate_then_preview'` rather than `'direct'`. For descriptions:

- **Generate** calls the LLM directly and writes the candidate into the action's `after` field **in the plan record**. No post meta is touched, nothing is published.
- **Preview** diffs `before` (the currently-resolved description) against `after` (the candidate) — real text against real text.
- **Apply** captures the prior value into the undo payload *first*, then writes via `Description_Orchestrator::set_manual()`.

Storing the candidate in the plan is what makes the preview honest: the owner approves text that already exists and cannot change between preview and apply.

### 3. Undo — captured, not recomputed, and finite

Undo is an explicit payload captured before each write, held in the session record. For content, WordPress revisions carry the history.

For descriptions the undo is exact, and its exactness comes from writing `_manual` rather than `_auto` (verified against `includes/LlmsTxt/Description_Orchestrator.php`):

- Capture the prior raw `_manual` **before** writing.
- On undo: empty → `clear_manual()`; non-empty → `set_manual( $prior )`.
- That restores the resolved description in all three starting states (no description, `_auto` only, pre-existing `_manual`), because clearing `_manual` falls resolution back to an **untouched** `_auto`.

Four shipped-code properties this depends on, each confirmed in source rather than assumed:

- `should_schedule()` returns `false` outright when `_manual` is non-empty (`:325–328`), so cron cannot overwrite an approved description.
- `get_cached_description()` resolves `_manual` first (`:658–662`), so the approved text is what the site actually serves.
- `regenerate()` clears the `_auto` family and preserves `_manual` — *"admin overrides survive regenerate"* (`:625–631`, the quoted phrase on `:627`).
- `set_manual()` fires `notify_description_changed()` **only when the value actually changed** (`:605–607`), so undo is no-op safe. This one is load-bearing rather than decorative: `/llms.txt` is composed and recomposed off that notification (`:710–715`), so it is the link between "the `_manual` slot was restored" and "the file the site serves was rebuilt." Undo fires it on every path.

**The exactness claim is scoped to the `_manual` slot.** Undo restores the slot it wrote; it does not restore surrounding state that a separate, owner-initiated action has since destroyed. **Two** paths do exactly that, neither carrying a `_manual` guard:

- the per-post REST route — `Descriptions_Rest_Controller::handle_regenerate()` (`includes/LlmsTxt/Descriptions_Rest_Controller.php:321–338`)
- WP-CLI — `Llms_Txt_Descriptions_Command::regen()`, which calls `regenerate()` at `includes/Cli/Llms_Txt_Descriptions_Command.php:249`

The *bulk* path is not a third case: it never calls `regenerate()` at all.

Both reach `Description_Orchestrator::regenerate()`, which deletes the `_auto` family unconditionally (`:639`). From the `_auto`-only starting state: apply sets `_manual` → Regenerate deletes `_auto` and schedules cron → `run()`'s re-check (`:437`) refuses because `_manual` is non-empty → `_auto` never comes back. Undo's `clear_manual()` then resolves past an `_auto` that no longer exists, and `get_cached_description()` returns empty, leaving the caller on its excerpt fallback (`:653–656`) rather than the original generated text.

Two things follow, and they are the reason this is an accepted limit rather than a defect:

- **The loss is already permanent at Regenerate time, before undo runs.** This is not a race undo could win by being faster or better-ordered — the state is degraded the moment Regenerate executes, and undo neither causes the loss nor could have prevented it.
- **It is ordinary undo semantics.** Undo guarantees the slot it wrote; resolution follows current state. This is a *different class* from the hazard rev 5 rejected at `:143` — there, the Agent's **own** action destroyed `_auto` while capturing nothing, so the Agent was responsible for the loss. Here an owner-initiated action outside the plan destroys it. Rev 5's actual FR-21/23 concern — cron overwriting approved text — is closed by `should_schedule()` (`:325–328`) plus `run()`'s re-check (`:437`).

The mitigation worth naming, since this record's whole thesis is *capture, don't recompute*: widen the undo capture to the `_auto` family, not just `_manual`. Deferrable for v1, because the degradation needs a deliberate owner action — but **not** because the floor is soft. `Entry_Source::resolve_description()` reads `post_excerpt` raw and deliberately does **not** auto-generate one, so on a post whose editor never set an excerpt the outcome is nothing: a bare title-and-URL entry. A UI warning cannot substitute for the capture either, since WP-CLI bypasses the UI entirely.

**Undo's authorisation gate.** Undo is a write, so it takes the same gate as apply — nonce- and capability-gated, with an ownership assertion. Rev 5 does not state this and it must not be inferred.

**The object of that assertion is the session record, not the active plan.** This distinction is the whole substance of the requirement, because undo payloads live in the ring-buffer session record (see § 1), which is *not* the active plan and may have a different owner. Asserting against the active plan would therefore authorise the wrong thing: administrator B, holding the current plan, could undo administrator A's archived session. So:

- The **session record carries `user_id`** as a stored field. There is otherwise no substrate to assert against, and an implementer would fall back to the active plan by default.
- Undo asserts `session.user_id === current_user_id` **on the session record holding the payload**, not on whatever plan happens to be active.

An undo endpoint authorising more loosely than the apply it reverses lets one administrator revert another's approved change without ever holding the relevant plan.

**Two finite consequences that must surface in the UI, not in a changelog:**

1. **Undo expires.** It dies when session 21 evicts session 1 from the ring buffer. The design requires this be **disclosed before apply**, not after — an owner deciding whether to apply needs to know how long they can take it back.
2. **An applied description becomes sticky.** Writing `_manual` drops that post out of auto-regeneration; it will not refresh when the content changes. That is correct semantics for a reviewed, owner-approved override — and it is *why* preview and undo can be honest at all — but it is a real behavioural change, fully reversible via `clear_manual()`, and the apply UI must say so in one line.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Capped option, `autoload: no` (chosen)** | Durable — survives object-cache eviction, which is not TTL-bounded; off every page load; no schema change | Options are a blunt store; size discipline is the author's job, hence the cap and the ring buffer |
| Transient | Idiomatic for short-lived data; self-expiring | Eviction is not TTL-bounded under an external object cache, so a plan can vanish mid-review — and it takes the owner's approval decisions with it. The one thing in a plan that cannot be recomputed is the part most at risk |
| Custom table | Room to grow; queryable; no size ceiling | A schema change, a migration, and a migration AgDR for data that lives ~20 sessions. Cost far exceeds the need for v1 |
| **Undo as a captured payload (chosen)** | Exact — restores the literal prior value; works identically across all three starting description states | Costs storage in the session record, and its lifetime is bounded by ring eviction |
| Undo by recomputation | No stored payload; no eviction window | Cannot restore what it cannot recompute — a prior `_manual` written by a human is not derivable from anything, and the `rewarm` action was cut in part for exactly this failure |
| Widen the capture to the `_auto` family too | Would survive an owner-initiated `regenerate()` between apply and undo, closing the one gap in the exactness claim; consistent with "capture, don't recompute" | More payload per action against a ring whose byte cap is itself still open below — so it would fix a cost against an unfixed ceiling, and the thing it shortens is the very undo window the capture exists to protect. Restoring `_auto` alone would also be insufficient: `is_stale()` treats an `_auto` with no `_generated_for_modified` bookmark as an external-write invariant violation and regenerates, so the capture must carry the bookmark keys too. **Deferred, not rejected** — sequence it after the byte cap is fixed |

## Decision

Chosen: **capped options with `autoload: no` for the active plan and a 20-deep size-capped session ring; apply gated on an `approved` status settable only through an ownership-asserting, nonce-gated route; undo as a payload captured before each write, with description undo built on `set_manual()`/`clear_manual()`; no new tables.**

## Consequences

- **#317 can define the value objects and the status enum** against a fixed lifecycle, and the stored-approval requirement pins why the ID scheme from AgDR-0073 must be re-generate-stable.
- **Undo has a finite lifetime**, and the UI carries a pre-apply disclosure obligation. If that line is dropped during implementation the model is no longer honest — this is a UI requirement, not a nicety.
- **An applied description stops auto-refreshing.** The apply UI owes the owner one line about it. Reversible via `clear_manual()`.
- **Undo guarantees the `_manual` slot, not the resolved description**, if a separate admin action has destroyed the `_auto` it would fall back to — via **either** unguarded `regenerate()` caller above (the per-post REST route or WP-CLI). Worth knowing before anyone writes "undo restores the previous description" in owner-facing copy; the honest phrasing is that it removes what the Agent applied. Note this is why a UI-only mitigation cannot be complete: WP-CLI has no UI to warn in.
- **No migration, no migration AgDR, no schema gate** for v1 — a direct consequence of choosing options over a table.
- **Resumable plans (FR-16) sit on the pre-committed cut line**, so a plan interrupted mid-review may not be recoverable in v1 even though the *store* is durable. Durability of the option is not the same property as resumability of the flow; do not read one as delivering the other.
- **Concurrency is not addressed.** See below.

## Not settled here

Recorded so the gap is visible rather than discovered later. Neither was resolved by rev 5, and this record does **not** decide them:

- **Concurrent editing.** Two administrators reviewing plans simultaneously share one active-plan option. The likely outcome is last-writer-wins: one admin's plan overwrites the other's. Note what the ownership assertion already buys — because apply requires `plan.user_id === current_user_id`, the losing admin cannot have *another* admin's approvals applied under their name. So the residual risk is **availability** (a plan is lost and must be regenerated), not **integrity** (approvals crossing between users). That distinction is why this is a flag rather than a blocker — but it holds **only if undo asserts against the session record** as § 3 requires. An undo authorising against the active plan instead would reopen the integrity leg this bullet claims is closed, since the payload being reverted belongs to a different session than the plan being held. Single-admin sites — the overwhelming majority for this plugin — never hit it, which is presumably why it did not surface in review. Worth an explicit call before any multi-admin deployment.
- **Ring-buffer sizing.** "Last 20" and "size-capped" fix the shape, not the byte ceiling. The cap interacts with the undo window: a plan with 20 description candidates is much larger than one with three exposure toggles, so a byte cap could evict sessions faster than the count suggests and shorten the undo lifetime the UI just promised. The concrete limit belongs to #322's implementation.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#316` (epic: #314)
- Technical design: `projects/agentready/designs/mokhai-agent-technical-design.md` (rev 5, APPROVED) — 9h-portfolio, §§ "Data Storage", "Apply-time re-validation and the write seam (N9)", the two-phase description flow
- Companion record: [AgDR-0073](AgDR-0073-action-derivation-inversion-and-ability-asymmetry.md) — the ID scheme, derivation contract, and ability asymmetry
- Prior scope decisions: [AgDR-0072](AgDR-0072-v1-scope-and-timeline.md)
- Downstream: #317 (value objects + status enum), #319 (review UI), #322 (`Apply_Engine`, executors, staleness, undo)
