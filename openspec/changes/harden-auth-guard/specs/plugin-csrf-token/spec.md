# Delta for plugin-csrf-token

## Provenance

- **Source**: `proposal.md` → VULN-0004, "Relationship to this plugin's canonical specs",
  Capabilities → Modified `plugin-csrf-token`.
- **Change class**: additive. Generation, storage, TTL, validation, view helpers and SSE
  guard integration are untouched. This delta adds one requirement stating what a token is
  **not**.
- **Identifier note**: the canonical capability names its requirements without stable IDs.
  IDs are introduced here for the new requirements only (`CSRF-NN`); earlier requirements
  keep their names and are not renumbered by this delta.

## ADDED Requirements

### Requirement: CSRF-01 — A CSRF token is never an authentication credential

No value validated by this capability MUST confer, extend or restore an authenticated
identity. A valid `su_csrf_token` proves only *"this request reached a page that rendered the
token, and that page was reached through one of the two authorized gates"*. It MUST NOT be
accepted as evidence that a user is logged in, and it MUST NOT substitute for a session
written by a real login.

The session guard of the standalone process scripts MUST NOT consult framework CSRF state
for any purpose: not `CsrfManager`, not `_csrf`, not `_token`, not `X-CSRF-Token`. This
also removes the last authorization decision in this plugin that depended on the core's
session store, which the capability's Token Storage requirement already keeps the plugin
out of.

This requirement does not weaken `su_csrf_token`. Its security property is **provenance**,
and provenance is **two authorized minting points, each behind its own honest gate**: the
`#[AdminOnly]` `admin_updater` page, gated by the attribute plus an authenticated
administrator session, and `recovery.php`, gated by the A2 per-installation secret compared
constant-time plus an administrator session in the console (`RACC-07`). Storage in
`SU_SESS`, format, 4 h TTL and `hash_equals()` comparison are unchanged, and the token
remains the only authenticator for `start`/`begin`/`chunk`. Minting is authorized from
either gate and from nowhere else.

*Recorded reversal.* An earlier revision of this delta said "only a rendered `#[AdminOnly]`
page mints it" and "single minting point". The product owner reversed that framing; a
second gated point trades one strong provenance for another equally strong one. Any contract
test asserting that no second point exists MUST be rewritten to assert that only these two
points exist, each behind its own gate.

#### Scenario: A token does not open a session-guarded endpoint

- GIVEN a request carrying a valid `su_csrf_token` and no login-written session
- WHEN a session-guarded action is requested (`download_backup.php`, or any `process_*`
  action other than `start`/`begin`/`chunk`)
- THEN the request is refused
- AND the refusal comes from the session guard, not from the token check

#### Scenario: A framework token is not a session either

- GIVEN a request carrying `_csrf_token`, `_token` or `X-CSRF-Token` obtained from a public
  page
- WHEN any guarded action is requested
- THEN the request is refused
- AND no core CSRF manager was consulted

#### Scenario: The plugin token keeps its own contract

- GIVEN a valid `su_csrf_token` for the current `SU_SESS` session
- WHEN `action=start` is requested
- THEN it is accepted exactly as before this change
- AND its storage, TTL and comparison semantics are unchanged

#### Scenario: Only the two authorized minting points exist

- GIVEN the production call graph after this change
- WHEN every HTTP entry point able to reach the minting helpers is enumerated
- THEN the set is exactly two: a render of the `#[AdminOnly]` `admin_updater` page, and
  `recovery.php`'s render after the secret gate and after administrator authentication
- AND no `process_*` script mints a token
- AND no third entry point mints one

#### Scenario: A recovery-minted token is not an identity either

- GIVEN a token minted by `recovery.php` after both of its gates
- WHEN it is presented with no login-written session to a session-guarded action
- THEN the request is refused with the guard's uniform 401 (`AUTH-04`)
- AND the token conferred no identity, exactly as an admin-minted token would not

## Traceability

| Requirement | Capability | Finding / source | Proposal success criterion |
|---|---|---|---|
| CSRF-01 | plugin-csrf-token | VULN-0004 ("silent on identity, and that silence was exploited") | a session with only `_csrf` is not authenticated; token mechanism unchanged; two authorized minting points |

## Testing notes — this suite has no database

- `CSRF-01` is asserted with the existing `CsrfTokenTest` lifecycle helpers plus a
  guard-level test that presents a valid token with an empty identity and asserts denial.
- The two-authorized-minting-points scenario is a source-tree enumeration, not a runtime
  assertion: it asserts that the set of entry points equals the two gated ones, never that
  the set has a single element.
