---
id: AgDR-0073
timestamp: 2026-07-28T08:00:00Z
agent: Hisham (Tech Lead)
model: claude-opus-5
session: 72885e90-35c2-4620-954f-2b33ab2c110a
trigger: user-prompt
status: executed
category: patterns
projects: [agentready]
---

# Core Inversion — Rule-Derived Actions, Reason-Code Classification, and the Apply/Ability Asymmetry

> In the context of building the Mokhai Agent v1.0 on top of a shipped Context Score engine, facing the risk that an LLM given a repair goal would invent plausible-but-wrong actions against a WordPress site it can write to, I decided to invert the control flow — a rule layer derives every candidate action from reason codes plus signals, the model only orders and explains a catalog it cannot extend — and to deliberately keep the Agent's apply step **off** the WP Abilities API, to achieve a write surface whose safety is testable and auditable rather than model-dependent, accepting that v1's fixable surface is bounded by what the rule table covers and that the ability asymmetry is an inconsistency a later contributor may try to "fix".

## Context

Three architectural commitments underpin every subsequent build step in the v1.0 Agent. They were settled across five design-review rounds with the Solution Architect (rev 5 APPROVED) and are recorded here because #317 freezes the `ActionType` enum against them, and because each is the kind of decision a future contributor would plausibly reverse without knowing what it was protecting.

### 1. The inversion — the model cannot introduce an action

`Engine.php` emits **32 stable reason codes**. That vocabulary is already the site's machine-readable defect list, which makes an LLM-authored action list redundant as well as unsafe:

> A rule layer maps reason codes and signals to candidate actions. The LLM orders them, explains them, and authors content. It cannot introduce an action no rule produced.

**Derivation contract**: the reason code *selects*; `sub_scores[x].signals` and the `Context_Assembler` *supply* target, `before`, and `after`; the estimate comes from simulation, never from the model.

The claim being made here is deliberately narrow. Rule-derivation prevents *invented* actions. It does **not** prevent a correctly-derived action from being harmful on a particular site shape. What it does is move harm risk out of the model and into a table that can be unit-tested, fixture-tested, and audited — which is the actual reason to do it. Overstating this as "the Agent cannot cause harm" would be false.

Two structural consequences follow, both discovered as review findings rather than designed up front:

- **Action identity is keyed `hash(type, target.kind, target.id)`, not on the reason code** (N2). Keyed on the code, the scheme broke both things it existed to do: `disc_no_cpt_exposed` and `es_no_cpt` fire on the *identical* condition (`count(profile.exposed_cpts) === 0`, same source array) and write the same setting, so one change produced two actions whose second `before` was stale by apply time; and `cr_coverage_low` / `cr_coverage_medium` are threshold bands on one continuum, so partially fixing coverage flipped the code, changed the ID, and lost the very approval the scheme existed to re-associate. Keying on `(type, target)` dedupes the first and survives the second — hence `origin_reason_codes` is plural and audit-only.
- **Every catalog entry's predicate must guarantee `before != after`.** A proposal that changes nothing is a pure trust cost. This is why the predicate work is non-optional rather than defensive polish.

### 2. Classification — presence of a code is not evidence of a defect

All 32 codes are classified negative / informational / positive, and each catalog entry carries a predicate over signals, because several codes fire unconditionally within a branch. Two classifications are counter-intuitive enough to be worth recording, since the drift test asserts **class** and a wrong class would have been frozen into CI as correct:

- **`ih_llm_disabled` is positive.** It fires in the `else` awarding **+60** (`Engine.php:454–460`); the docblock states opting out of the LLM stack "is NOT penalised — a valid steady-state configuration." Classifying it negative would generate advisory noise about a deliberate owner choice, on an already-optimised site.
- **`mcd_provider_configurable` is positive** — but the originally-stated reason was wrong, and the reason is what a reader relies on. It does *not* fire "alongside" `mcd_provider_detected`: the two are an if/else (`Engine.php:669–675`) selected by whether `config_url` is a non-empty string. Both branches sit inside the provider-detected path, so either way a provider was detected.

Two sub-scores are **proportional**, so a code-keyed catalog is blind to partial deficits and needs **value-threshold entries**:

- `multi_channel_discovery` awards 25 points per channel (`Engine.php:653–654`), so a site can emit the *positive* `mcd_channels_detected` while carrying a 75-point deficit. The entry must be predicated on **the module actually being disabled** — channel count alone fires when the module is already on and only the llms.txt cache is empty, proposing to enable something already enabled. Note `llms_txt_present` is not served by that module (`Signal_Collector.php:517–522`), so count alone never implies the module is off.
- `discoverability` needs `disc_zero_entries` split in two: zero entries with no CPTs exposed is an exposure problem; zero entries with CPTs already exposed is a stale cache. The correct second predicate is **`total_entries > 0 && llms_txt_entry_count == 0`** — computed live by walking `Entry_Source` sections (`Signal_Collector.php:455–473`) and independent of the cache. The intuitive form (`llms_txt_entry_count == 0 && exposed_cpts_count > 0`) is unreachable, because `cache_populated` is *defined as* `entry_count > 0` (`Signal_Collector.php:146`); worse, a CPT exposed with zero eligible posts matched it, and regenerating there reproduces an identity-header-only document — delta 0, action recurs forever.

### 3. The ability asymmetry — apply is deliberately not a WP Ability

The plugin already exposes five WP Abilities (`Abilities/Registrar.php`) — audit-run, profile-read, profile-set-exposure, llms-txt-regenerate, md-view-preview — and they are **not** uniformly read-only. Only `profile-read` and `md-view-preview` carry `'readonly' => true` plus `'mcp' => ['public' => true]` (`Registrar.php:128`, `:236`); `mokhai/profile-set-exposure` (`Profile_Ability::set_exposure`, `:61–73`) and `llms-txt-regenerate` are genuine *mutating* abilities established by AgDR-0044.

So the asymmetry is not "Mokhai doesn't do write abilities." It is narrower and needs stating precisely: **the Agent's read surface may be an ability; the Agent's plan-apply step must not be.** Three reasons, in descending order of load-bearing-ness:

1. **An ability's authorisation cannot express what apply requires.** `Permissions::require_manage_options()` ignores its input by design — the docblock is explicit that "authorisation here is user-capability based, not input-dependent" (`Permissions.php:31–33`). Apply's safety condition is entirely input-dependent: `Apply_Engine` accepts only `approved` status, settable only via a route asserting `plan.user_id === current_user_id`. A capability check cannot say *"this specific user approved this specific plan."*
2. **Abilities are a public, discoverable surface.** Registered abilities are invocable by any REST/MCP client holding the capability, from outside the propose → approve → apply flow. That is exactly right for reading a profile or running an audit; for a bulk multi-write carrying undo obligations and staleness re-validation, it hands out the write half of the flow without the approval half.
3. **`profile-set-exposure` is safe as an ability precisely because it is unlike apply** — a single, idempotent, fully-specified setting write with its own sanitisation. No plan state, no bulk, no undo obligation, no staleness window.

The forward-looking risk is the reason this is an AgDR rather than a code comment: a later contributor reading `Registrar.php` will see a mutating ability already registered and reasonably conclude that `agent-apply-plan` was an oversight. Recording it makes "fixing" the inconsistency a decision someone has to argue against, not a tidy-up.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Rule-derived catalog; model orders + explains (chosen)** | Harm risk moves into a testable, auditable table; drift test catches new codes; reuses the 32-code vocabulary already shipped | Fixable surface bounded by what the table covers; predicate work is real effort (step 6 is the highest-uncertainty item in the plan) |
| LLM proposes actions against a schema, validated on the way out | Broadest coverage; no catalog to maintain; adapts to site shapes nobody enumerated | Validation can only check *shape*, not *appropriateness*; a well-formed wrong action passes; nothing to unit-test and no drift signal; the failure mode is silent and site-specific |
| Fixed hardcoded remediation list, no model at all | Maximally predictable; zero LLM cost | Loses ordering by impact and owner-readable explanation — the two things that make a 32-code diagnosis actionable; reduces the product to the existing advisory UI |
| **Apply as a plan-scoped REST route (chosen)** | Ownership + approved-status assertable per request; nonce-gated; write path stays inside the approval flow | Inconsistent with `profile-set-exposure` being an ability; not agent-invocable, so external MCP clients can read a plan but not apply one |
| Apply as a mutating WP Ability | Consistent with AgDR-0044's precedent; agent-invocable end-to-end; one less bespoke route | `permission_callback` is input-independent by construction, so plan-ownership cannot be enforced where it matters; exposes bulk writes on a public surface outside the approval flow |

## Decision

Chosen: **the rule-derived inversion, the classification table with its two corrected classes and two value-threshold entries, and apply as a plan-scoped REST route rather than a WP Ability.**

The derivation contract is fixed as: *reason code selects; signals + assembler supply target/before/after; simulation supplies the estimate.* Action identity is `hash(type, target.kind, target.id)`. Every catalog entry carries a predicate guaranteeing `before != after`. The `ActionType` enum freezes against this contract in #317, which is what lets the review UI (#319) be built against a fixture plan before the catalog (#320) exists.

## Consequences

- **#317 can freeze the enum**, and **#319 can proceed against a fixture plan** — the decoupling in the implementation plan is real only because the contract above is fixed first.
- **The classification drift test becomes a release gate**: a new reason code with no catalog entry fails CI, and the test asserts *class*, so the `ih_llm_disabled` / `mcd_provider_configurable` corrections are locked in rather than re-derivable.
- **v1's fixable surface equals the rule table's coverage.** Combined with the FR-3b page-markup deferral and `md_conversion_quality` being diagnosis-only (both AgDR-0072), v1 diagnoses materially more than it fixes. That is a known, accepted position, not a discovered one.
- **The Agent is not end-to-end agent-invocable in v1.** An external MCP/REST client can read the diagnosis and, in due course, a plan; it cannot apply one. Any future "make apply an ability" proposal must first solve per-plan ownership inside an input-independent `permission_callback` — otherwise it is a regression wearing a consistency argument.
- **`Signal_Collector` gains a profile-override parameter** (step 7 / #321) as a direct consequence of corpus-derived estimates needing re-collection under a hypothetical profile. Plan generation therefore does real DB work; it is not a pure read.
- Enrichment is refused outright for exposure-class actions, and explanations pass `Narrative_Guard::is_safe()` with an action-scoped allowlist extension — without that extension the guard rejects the simulated estimate and every post title, every explanation silently falls back to template, and the eval harness would measure the fallback while believing it measures the model.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#315` (epic: #314)
- Technical design: `projects/agentready/designs/mokhai-agent-technical-design.md` (rev 5, Solution Architect APPROVED) — 9h-portfolio
- PRD: `projects/agentready/spec/mokhai-agent.prd.md` — 9h-portfolio
- Prior scope decisions: [AgDR-0072](AgDR-0072-v1-scope-and-timeline.md)
- Abilities precedent: [AgDR-0044](AgDR-0044-abilities-api-integration.md)
- Downstream: #316 (plan persistence, approval state, undo, ID scheme), #317 (value objects + frozen `ActionType` enum), #320 (`Action_Catalog`)
