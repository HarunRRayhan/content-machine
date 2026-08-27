# content-machine

content-machine is an open-source content pipeline: Laravel 13 + Inertia 3 + React 19 +
TypeScript + Tailwind 4. `/dashboard/*` is Inertia+React; everything else is plain Blade.
See `docs/` for architecture, `docs/adr/` for decisions already made and why.

## Engineering rules (strict, no exceptions)

These are not style preferences. A PR that violates them gets fixed before merge, not merged
with a "will clean up later" note.

### 1. Action pattern — one class per business operation

Every write operation (create/update/delete/publish/anything that isn't a pure read) lives in an
**Action** class under `app/Actions/{Domain}/{Verb}{Noun}Action.php`, e.g.
`app/Actions/Teams/CreateTeamAction.php`, `app/Actions/Teams/InviteTeamMemberAction.php`.

- One public entry point: `handle(...): mixed`. No package dependency (no
  lorisleiva/laravel-actions) — plain constructor-injected classes.
- Controllers are thin: validate via a Form Request → build a DTO → call one Action →
  return an Inertia response / redirect / API Resource. A controller method with business logic
  in it (a conditional that isn't pure routing, a query that isn't `Model::find`) is a bug.
- Models stay thin: relationships, casts, scopes, accessors. No business logic in an Eloquent
  model beyond that. If you're about to write a method on a model that decides *what should
  happen*, it's an Action.
- Every Action gets its own Pest test in `tests/Unit/Actions/{Domain}/`.

### 2. DTOs — typed data, not arrays, at every boundary

Plain PHP `readonly` classes (PHP 8.3+ syntax), not a package (no spatie/laravel-data unless a
concrete need for its casting/validation machinery shows up later — don't add it speculatively).

- `app/Data/{Domain}/{Name}Data.php`, e.g. `app/Data/Teams/CreateTeamData.php`.
- Named constructors from the boundary they cross: `::fromRequest(FormRequest $request): self`,
  `::fromArray(array $data): self`. Never pass a raw `$request->all()` or an untyped `array` into
  an Action — build the DTO first.
- Constructor-promoted, `public readonly` properties. No setters, no mutation after construction.
- A DTO is data, not behavior — no side effects in its methods beyond pure transformation
  (`toArray()`, a computed accessor). Business logic belongs in the Action that consumes it.

### 3. SOLID, applied concretely here

- **Single responsibility**: enforced by the Action pattern above — one Action, one reason to
  change.
- **Open/closed**: new behavior is a new class implementing an existing interface, not an `if`
  branch in an existing one.
- **Liskov substitution**: any class implementing an interface must be swappable for another
  implementation with no caller-side changes. If a caller needs to check which concrete class it
  has before calling a method, the interface is wrong — fix the interface, don't special-case the
  caller.
- **Interface segregation**: small, focused interfaces over one large one. Don't add a method to
  an interface "just in case" — add it when a second implementation needs it to differ.
- **Dependency inversion**: Actions and controllers depend on interfaces bound in a
  `ServiceProvider`, never on a concrete external-integration class directly. Anything that talks
  to an external system is bound in `app/Providers/` and injected by interface, defined in
  `app/Contracts/`.

### 4. DRY — one source of truth, not one copy per use site

Before writing a new helper, method, or query, grep for an existing one that already does it.
Shared logic goes in a trait (`app/Concerns/`), a small service class, or a scope — not
copy-pasted with minor edits. Config-shaped repetition belongs in a `config/*.php` file, not
scattered `if` statements across the codebase.

### 5. Testing

Pest (`tests/`). Every Action, every DTO's boundary-crossing constructor, every policy/gate, and
every non-trivial trait gets a test. `./vendor/bin/pest --parallel` must pass before any commit,
same for `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G`
(Larastan level 7). CI enforces all three; don't rely on CI to catch what you can catch locally
first.

## Where things live

- `app/Actions/{Domain}/` — business operations (e.g. `Actions/Teams/CreateTeamAction.php`)
- `app/Data/{Domain}/` — DTOs (e.g. `Data/Teams/CreateTeamData.php`)
- `app/Contracts/` — interfaces for external integrations (SOLID's dependency-inversion boundary)
- `app/Models/` — thin Eloquent models (`Team`, `Workspace`, `TeamInvitation`, `User`,
  `StatusTransition`, `ContentVersion`)
- `app/Http/Controllers/` — thin, Inertia/API glue only
- `app/Concerns/` — shared traits (`BelongsToWorkspace`, `RecordsHistory`)
- `app/Support/` — small framework-adjacent singletons (`CurrentWorkspace`)
- `app/Listeners/` — auth-event side effects (team provisioning, invite auto-accept); wired up
  automatically by Laravel's event auto-discovery from each `handle()` method's type-hint, don't
  also register them by hand in a ServiceProvider or they'll fire twice
- `routes/web.php` — public routes only (landing page, invite-accept flow)
- `routes/dashboard.php` — everything behind `auth`+`verified`+`SetCurrentWorkspace`, prefixed
  `/dashboard`
- `routes/settings.php` — workspace Settings (PostSyncer first), first-class at `/settings`
- `docs/adr/` — architecture decisions and why
- `.secrets/<service>.env` — operational/infra credentials (Railway tokens, etc.), gitignored,
  separate from the app's own `.env`. See `.secrets/README.md`. Never put an infra token in the
  app's `.env` — a local test run that overwrites `.env` has taken one down before.

## Shipping

When a change is done and locally green (`pest`, `pint`, `phpstan`, and frontend lint/types
as they apply):

1. Open a PR. Do not ask whether to open one.
2. Wait until CI is green.
3. Self-review the PR diff.
4. Squash-merge without asking. Railway deploys `main`.
