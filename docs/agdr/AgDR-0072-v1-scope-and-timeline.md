---
id: AgDR-0072
timestamp: 2026-07-27T09:00:00Z
agent: Hisham (Tech Lead)
model: claude-opus-5
session: cd3802fb-ca82-474c-8ecc-cb90b8d9e3e7
trigger: user-prompt
status: executed
category: patterns
projects: [agentready]
---

# v1 Scope and Timeline — Page Markup, Diagnosis-Only Sub-Score, Launch Date

> In the context of the Mokhai Agent v1.0 technical design (rev 5, Solution Architect approved), facing two capability gaps that put the PRD's "+15 median" headline metric at risk on the hardest fixtures, I decided to defer page-markup actions, accept `md_conversion_quality` as diagnosis-only, and move the launch by one week to achieve a v1 that ships what actually works rather than what was originally scoped, accepting that v1 will diagnose materially more than it fixes on page-builder and ACF sites.

## Context

The technical design went through five review rounds with the Solution Architect (Tariq). Two capability gaps survived all five rounds not because they were unexamined, but because every attempt to close them was verified to be broken:

- **Page markup (FR-3).** Milestone 3's CEO-stated success criterion required the Agent to "update page markup from one plan the owner approves." No markup action proposed across any review round survived — each either targeted state the shipped engine doesn't expose, or (once corrected to use real signals) was provably unreachable or unreversible against the actual `Description_Orchestrator` / `Signal_Collector` APIs. Deferring FR-3 removes half of Milestone 3's own stated success criterion, not a minor scope trim.
- **`md_conversion_quality` repair (`rewarm_worst_md_rows`).** This sub-score is 25% of the Context Score and the one most often deficient on page-builder and ACF fixtures — exactly the hard cases. The only fix action designed for it targeted `signals.worst_urls`, an `ORDER BY quality_score ASC LIMIT 5` list. An empty conversion scores 100 (`Walker::empty_result()`), so empty/broken rows sort to the *bottom* of that list and can never appear in it — the fix was structurally incapable of finding what it was meant to fix. The design's own N1 finding recorded this: not diffable, not reversible, no computable delta. A real repair needs a new emptiness-specific `Signal_Collector` query, undo capture across cron ticks, and its own AgDR (recorded as a post-v1 path in this same design).

Together, these two gaps mean v1 diagnoses these two areas without fixing them, directly weakening the PRD's median +15 score-lift target on precisely the fixtures where the target is hardest to hit.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| Re-baseline the +15 target | Keeps the current timeline; the target reflects what v1 can actually do | Undersells the product before it ships; a lowered public target is hard to walk back up later |
| Fund the repaired `rewarm` fix now | Closes the biggest capability gap before launch; hits the original target as scoped | Needs a new query, undo handling, and its own AgDR — real, unbudgeted scope against an already-tight build week; risks the same "wrong mechanism" failure mode the design chain has been catching all along if rushed |
| Move the launch by one week | Keeps the +15 target and full v1 scope intent; buys schedule slack against a build that was already High risk / Low estimate-confidence on a one-week budget | Pushes wp.org publication and any dependent GTM timing by the same week; doesn't itself guarantee the target lands — it's schedule buffer, not a scope fix |

## Decision

Chosen: **defer page markup, accept `md_conversion_quality` as diagnosis-only, and move the launch one week (Aug 10 → Aug 17)** — not re-baseline the target, not fund the `rewarm` repair this cycle.

The CEO weighed the three options directly against the design's own framing and chose schedule slack over either lowering the public target or absorbing new unbudgeted scope into an already-tight, High-risk build week. The +15 target is retained as stated; the extra week is the buffer against the risk the design flagged, not a guarantee the diagnosis-only sub-scores will improve.

## Consequences

- Milestone 3's success criterion no longer includes page-markup writes for v1 — the Agent's write surface for v1 is exposure and description actions only.
- `md_conversion_quality` remains read-only in the Context Score UI through v1; the owner sees the deficit but the Agent proposes no fix for it.
- The repaired `rewarm` design (targeting a real emptiness signal, with proper undo) is deferred to the first post-v1 release, as already noted in the technical design.
- v1's launch date moves from **Aug 10 to Aug 17**. Downstream dates (wp.org submission, any GTM timing tied to launch) move with it.
- The PRD's "+15 median" target is **unchanged** — it is not being re-baselined — so v1's actual lift against that target on page-builder/ACF fixtures should be watched closely post-launch; if the extra week doesn't close the gap, the re-baseline or `rewarm`-funding options remain live for a fast-follow decision.

## Artifacts

- Technical design: `projects/agentready/designs/mokhai-agent-technical-design.md` (9h-portfolio) — § "Decisions needed from the CEO"
- PRD: `projects/agentready/spec/mokhai-agent.prd.md` (9h-portfolio)
- Initiative: `projects/agentready/initiatives/mokhai-road-to-v1.md` (9h-portfolio) — Milestone 3
- Ticket: `Ref34t/mokhai-agent-readiness-kit#312`
