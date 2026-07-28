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

All 32 codes are classified negative / informational / positive, and each catalog entry carries a predicate over signals, because several codes fire unconditionally within a branch.

The full classification is recorded here rather than by reference, deliberately. The technical design that holds it lives in a **private** portfolio repo while this plugin repo is **public**, and the class-asserting drift test does not exist yet — `Reason_Keys_Test.php` currently carries the 32 tokens with no class assertions, and gains them with #320. Until then this table is the only durable record of the classification in the repo whose enum (#317) is being frozen against it.

| Sub-score | Negative | Informational | Positive |
|---|---|---|---|
| discoverability | `disc_llms_txt_empty`, `disc_no_cpt_exposed`, `disc_zero_entries`, `disc_rewrite_conflict` | — | `disc_cpt_exposed`, `disc_entries_listed`, `disc_llms_txt_populated` |
| content_readability | `cr_coverage_low`, `cr_coverage_medium`, `cr_no_exposed_entries` | — | `cr_coverage_good` |
| schema_coverage | `sc_no_structured_data` | — | `sc_native_jsonld`, `sc_seo_plugin_detected` |
| exposure_safety | `es_no_cpt`, `es_risky_statuses` | — | `es_cpt_explicit`, `es_only_published` |
| integration_health | `ih_llm_unconfigured`, `ih_llms_txt_conflict` | — | `ih_llm_configured`, **`ih_llm_disabled`** |
| md_conversion_quality | `mcq_no_cache`, `mcq_empty_bodies`, `mcq_noisy_bodies` *(all advisory in v1)* | `mcq_mean_quality`, `mcq_above_threshold` | — |
| multi_channel_discovery | `mcd_no_channels` | — | `mcd_channels_detected`, `mcd_openapi_bonus`, `mcd_provider_detected`, **`mcd_provider_configurable`** |

Two codes (bolded above) are counter-intuitive enough to need their reasoning recorded — **one was a wrong class, one was a correct class held for the wrong reason** — since the drift test asserts **class** and a wrong class would have been frozen into CI as correct, while a wrong rationale survives in the prose a future reader relies on:

- **`ih_llm_disabled` is positive.** It fires in the `else` awarding **+60** (`Engine.php:454–460`); the docblock states opting out of the LLM stack "is NOT penalised — a valid steady-state configuration." Classifying it negative would generate advisory noise about a deliberate owner choice, on an already-optimised site.
- **`mcd_provider_configurable` is positive** — but the originally-stated reason was wrong, and the reason is what a reader relies on. It does *not* fire "alongside" `mcd_provider_detected`: the two are an if/else (`Engine.php:669–675`) selected by whether `config_url` is a non-empty string. Both branches sit inside the provider-detected path, so either way a provider was detected.

Two sub-scores are **proportional**, so a code-keyed catalog is blind to partial deficits and needs **value-threshold entries**:

- `multi_channel_discovery` awards 25 points per channel (`Engine.php:653–654`), so a site can emit the *positive* `mcd_channels_detected` while carrying a 75-point deficit. The entry must be predicated on **the module actually being disabled** — channel count alone fires when the module is already on and only the llms.txt cache is empty, proposing to enable something already enabled. Note `llms_txt_present` is not served by that module (`Signal_Collector.php:517–522`), so count alone never implies the module is off.
- `discoverability` needs `disc_zero_entries` split in two: zero entries with no CPTs exposed is an exposure problem; zero entries with CPTs already exposed is a stale cache. The correct second predicate is **`total_entries > 0 && llms_txt_entry_count == 0`** — computed live by walking `Entry_Source` sections (`Signal_Collector.php:455–473`) and independent of the cache. The intuitive form (`llms_txt_entry_count == 0 && exposed_cpts_count > 0`) is unreachable, because `cache_populated` is *defined as* `entry_count > 0` (`Signal_Collector.php:146`); worse, a CPT exposed with zero eligible posts matched it, and regenerating there reproduces an identity-header-only document — delta 0, action recurs forever.

### 3. The ability asymmetry — apply is deliberately not a WP Ability

The plugin already exposes five WP Abilities (`Abilities/Registrar.php`) — `audit-run`, `profile-read`, `profile-set-exposure`, `llms-txt-regenerate`, `md-view-preview` — and they are **not** uniformly read-only. **All five carry `'mcp' => ['public' => true]`** (`Registrar.php:111`, `:129`, `:166`, `:192`, `:237`) — the public-surface property is universal, not a concession made for the read-only ones. Only two carry `'readonly' => true`: `profile-read` (`:128`) and `md-view-preview` (`:236`). The other **three** mutate: `profile-set-exposure` (`Profile_Ability::set_exposure`, `:61–73`) writes the profile option, `llms-txt-regenerate` rewrites the cache, and `audit-run` writes the Context Score cache (classified as such in AgDR-0044's own table). So the majority of Mokhai's registered abilities already write something.

So the asymmetry is not "Mokhai doesn't do write abilities." It is narrower and needs stating precisely: **the Agent's read surface may be an ability; the Agent's plan-apply step must not be.** Three reasons, in descending order of load-bearing-ness:

1. **Registering apply publishes a bulk, undo-bearing multi-write to a public surface.** All five abilities are `mcp.public`, so an ability is invocable by any REST/MCP client holding `manage_options`, from outside the propose → approve → apply flow. That is exactly right for reading a profile or running an audit. For apply it hands out the write half of the flow without the approval half — and it is the design's own stated ground for this decision (*"a security-relevant control decision on a public API surface"*).
2. **An ability's permission gate cannot carry a reason, and apply's outcome is per-action.** Core's `WP_Ability::execute()` funnels a `WP_Error` returned from a permission callback into `_doing_it_wrong()` and returns a flat `ability_invalid_permissions` — deliberately, so permission internals don't leak. Apply must report per-action outcomes: `Staleness_Check` marks individual targets `skipped` and reports them. A gate structurally forbidden from carrying a reason cannot say *"3 of 7 were stale, and here's which."*
3. **Authorising in the callback splits check from act.** A permission callback that read plan state to authorise would be followed by `execute()` re-reading that state to write it — a check-then-act window across a bulk write carrying undo obligations and a staleness check. The route form does the ownership assertion and the write inside one request.

**What this argument is deliberately *not*.** It is tempting to say "a `permission_callback` is capability-based and cannot express plan ownership." That is false, and the file most likely to be consulted refutes it: `Permissions.php:31` states that *"The Abilities API passes the (validated) input to permission callbacks"* — the helper `require_manage_options()` ignores its input (`:39–43`) and its docblock scopes that choice to itself (*"authorisation **here** is user-capability based"*), but nothing obliges a new ability to reuse the helper. A closure reading `$input['plan_id']` and asserting `user_id === get_current_user_id() && status === 'approved'` would express the ownership condition perfectly well. The asymmetry therefore rests on reasons 1–3 above — surface area, the un-reasoned gate, and check-then-act — **not** on an authorisation limit that does not exist. Anyone re-opening this decision should attack those three, and will not find leverage in the callback signature.

Note also that **`profile-set-exposure` is safe as an ability precisely because it is unlike apply** — a single, idempotent, fully-specified setting write with its own sanitisation. No plan state, no bulk, no undo obligation, no staleness window, and one outcome to report rather than N.

**This supersedes the framing in #315 and the design's summary, and narrows only one of the two claims they bundle.** Both describe the asymmetry as "the model can read everything, write nothing directly." The *second* half — FR-19, propose-only, the model causes no write inside the Agent flow — stands exactly as written and is enforced by six layers in the design. What is superseded is the implied *plugin-wide* reading, that Mokhai exposes no write abilities: it exposes three. FR-19 is a property of the Agent's flow, not of the plugin's ability surface, and conflating the two is what made the original phrasing indefensible.

The forward-looking risk is the reason this is an AgDR rather than a code comment, and it is sharper than a lone counter-example would make it: a later contributor reading `Registrar.php` sees **three of five** abilities already writing, all five publicly exposed, and would reasonably conclude that `agent-apply-plan` was an oversight. Recording it makes "fixing" the inconsistency a decision someone has to argue against, not a tidy-up.

## Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **Rule-derived catalog; model orders + explains (chosen)** | Harm risk moves into a testable, auditable table; drift test catches new codes; reuses the 32-code vocabulary already shipped | Fixable surface bounded by what the table covers; predicate work is real effort (step 6 is the highest-uncertainty item in the plan) |
| LLM proposes actions against a schema, validated on the way out | Broadest coverage; no catalog to maintain; adapts to site shapes nobody enumerated | Validation can only check *shape*, not *appropriateness*; a well-formed wrong action passes; nothing to unit-test and no drift signal; the failure mode is silent and site-specific |
| Fixed hardcoded remediation list, no model at all | Maximally predictable; zero LLM cost | Loses ordering by impact and owner-readable explanation — the two things that make a 32-code diagnosis actionable; reduces the product to the existing advisory UI |
| **Apply as a plan-scoped REST route (chosen)** | Ownership + approved-status asserted in the same request that writes; nonce-gated; per-action outcomes reportable in the response; write path stays inside the approval flow | Inconsistent with three abilities already mutating, so the asymmetry needs this record to survive; not agent-invocable, so an external MCP client cannot apply a plan |
| Apply as a mutating WP Ability | Consistent with AgDR-0044's precedent; agent-invocable end-to-end; one less bespoke route; a bespoke `permission_callback` *could* assert plan ownership from its input, so this is not technically blocked | Publishes a bulk, undo-bearing multi-write to an `mcp.public` surface reachable outside propose → approve → apply; the permission gate cannot report *which* actions were stale (core flattens callback errors to `ability_invalid_permissions`); authorising on plan state read in the callback splits check from act |

## Decision

Chosen: **the rule-derived inversion, the classification table with its one corrected class (`ih_llm_disabled`) plus one corrected rationale (`mcd_provider_configurable`) and two value-threshold entries, and apply as a plan-scoped REST route rather than a WP Ability.**

The derivation contract is fixed as: *reason code selects; signals + assembler supply target/before/after; simulation supplies the estimate.* Action identity is `hash(type, target.kind, target.id)`. Every catalog entry carries a predicate guaranteeing `before != after`. The `ActionType` enum freezes against this contract in #317, which is what lets the review UI (#319) be built against a fixture plan before the catalog (#320) exists.

## Consequences

- **#317 can freeze the enum**, and **#319 can proceed against a fixture plan** — the decoupling in the implementation plan is real only because the contract above is fixed first.
- **The classification drift test becomes a release gate**: a new reason code with no catalog entry fails CI, and the test asserts *class*, so the `ih_llm_disabled` class correction is locked in rather than re-derivable. Note the limit of that protection — `mcd_provider_configurable` was already classified correctly as of rev 3 (rev 2 had it wrong), so from rev 3 onward no test would have caught the wrong *rationale* attached to it; only this record does.
- **v1's fixable surface equals the rule table's coverage.** Combined with the FR-3b page-markup deferral and `md_conversion_quality` being diagnosis-only (both AgDR-0072), v1 diagnoses materially more than it fixes. That is a known, accepted position, not a discovered one.
- **The Agent is not end-to-end agent-invocable in v1.** An external MCP/REST client can read the diagnosis through the existing read abilities; it cannot apply a plan. Whether a *plan* becomes readable over an ability is an open question this record does not settle (#325 defines the REST surface). Any future "make apply an ability" proposal has to defeat reasons 1–3 above — public bulk-write surface, a permission gate that cannot report per-action outcomes, and the check-then-act split. It does **not** need to defeat a claim about the callback signature, and should not be waved through on one.
- **`Signal_Collector` gains a profile-override parameter** (step 7 / #321) as a direct consequence of corpus-derived estimates needing re-collection under a hypothetical profile. Plan generation therefore does real DB work; it is not a pure read.
- Enrichment is refused outright for exposure-class actions, and explanations pass `Narrative_Guard::is_safe()` with an action-scoped allowlist extension — without that extension the guard rejects the simulated estimate and every post title, every explanation silently falls back to template, and the eval harness would measure the fallback while believing it measures the model.

## Artifacts

- Ticket: `Ref34t/mokhai-agent-readiness-kit#315` (epic: #314)
- Technical design: `projects/agentready/designs/mokhai-agent-technical-design.md` (rev 5, Solution Architect APPROVED) — 9h-portfolio
- PRD: `projects/agentready/spec/mokhai-agent.prd.md` — 9h-portfolio
- Prior scope decisions: [AgDR-0072](AgDR-0072-v1-scope-and-timeline.md)
- Abilities precedent: [AgDR-0044](AgDR-0044-abilities-api-integration.md)
- Downstream: #316 (plan persistence, approval state, undo, ID scheme), #317 (value objects + frozen `ActionType` enum), #320 (`Action_Catalog`)
