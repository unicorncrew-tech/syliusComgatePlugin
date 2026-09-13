# Repository Guidelines

## Project Overview

`unicorncrew-tech/sylius-comgate-plugin` integrates the Czech payment gateway
[Comgate](https://www.comgate.cz/) into Sylius 2.x as a **Payum gateway** named `comgate`. It implements
the standard redirect + server-to-server-webhook flow: the shopper is sent to a Comgate-hosted payment
page, then Comgate confirms the outcome both by redirecting the shopper back and by calling a webhook
("STATUS URL"). The plugin never trusts either callback's payload directly — it always re-verifies the
authoritative status via `Client::getStatus()`.

Architecture deliberately mirrors `sylius/paypal-plugin` and `sylius/mollie-plugin` (Payum gateway
factory + tagged actions), **not** the newer command-bus style used by `sylius/stripe-plugin`.

## Architecture & Data Flow

```
Sylius Capture/Notify controllers (Payum bundle, vendor)
        │
        ▼
ComgateGatewayFactory (src/Payum/ComgateGatewayFactory.php)
  registers: payum.api closure (→ ComgateApi) + 4 tagged actions
        │
        ├─ ConvertPaymentAction   Payment → array details, seeds status=NEW (once, before first Capture)
        ├─ CaptureAction          creates payment at Comgate, throws HttpRedirect to send shopper offsite;
        │                         on return, re-polls status instead of trusting the browser
        ├─ StatusAction           maps details['status'] (ComgateStatus::*) → Payum's canonical
        │                         GetStatusInterface mark*() calls → drives Sylius' payment state machine
        └─ NotifyAction           webhook handler (payum_notify_do_unsafe/{gateway}); looks up Payment by
                                  refId, re-fetches status from Comgate (never trusts the webhook body)
        │
        ▼
Api\ComgateApi (thin wrapper around vendor comgate/sdk Client)
```

The shared state store across every action is `Payment::$details` (an array persisted by Payum): keys
`status` (one of `ComgateStatus::{NEW,PENDING,AUTHORIZED,PAID,CANCELLED}`) and `trans_id` (Comgate's
transaction id — also the refId lookup key on the webhook side).

No new Doctrine entities/migrations exist; the plugin only adds services, actions, and a form type.

## Key Directories

- `src/Api/` — `ComgateApiInterface` (the `payum.api` contract) + `ComgateApi` (wraps `comgate/sdk`'s
  `Client`). This is the only place that talks to Comgate over HTTP.
- `src/Payum/` — `ComgateGatewayFactory` (gateway bootstrap/config validation) and `ComgateStatus`
  (string-constant bag re-exporting `Comgate\SDK\Entity\Codes\PaymentStatusCode`, plus a synthetic `NEW`).
- `src/Payum/Action/` — the four `ActionInterface` implementations described above. Each has a matching
  `supports()` guard and starts `execute()` with `RequestNotSupportedException::assertSupports($this, $request)`.
- `src/Form/Type/` — `ComgateGatewayConfigurationType`, the admin "gateway configuration" form
  (merchant/secret/test) shown when a payment method's gateway is set to `comgate`.
- `src/DependencyInjection/` — `UnicorncrewSyliusComgateExtension` (alias `unicorncrew_sylius_comgate`),
  loads `config/services.yaml`. No `Configuration` class — there are no bundle-level config keys.
- `config/services/` — `payum.yaml` (gateway factory builder + 4 tagged actions, manual wiring,
  `autowire: false` except `NotifyAction`) and `form.yaml` (form type, standard `autowire: true`).
- `config/config.yaml` — **consumer-app-facing** config (imported by host apps, not just this plugin):
  whitelists `processing` as an allowed checkout payment state and adds an isolated `comgate` Monolog
  channel/handler.
- `translations/` — `messages.{en,cs}.yaml` for the gateway label + form field labels.
- `tests/Unit/` — framework-free PHPUnit tests, one per class in `src/`.
- `tests/Functional/` — a single container-compilation smoke test (see Testing & QA).
- `tests/TestApplication/` — overlay for the shared `sylius/test-application` dev-dependency kernel
  (`bundles.php`, `.env`, `.env.test`). Not a bespoke app.
- No `scripts/` directory exists — every command is a raw `composer`/`vendor/bin/*` invocation.

## Development Commands

```shell
composer install                                    # install deps (composer.lock is gitignored: a library, not an app)

php -d memory_limit=-1 vendor/bin/phpunit            # full suite (unit + functional); see Testing & QA for why -d memory_limit=-1
vendor/bin/phpunit --testsuite "Unicorncrew Sylius Comgate Plugin - Unit"        # unit only, no memory flag needed

vendor/bin/phpstan analyse -c phpstan.neon.dist      # static analysis, level 8, src/ only
vendor/bin/ecs check src/ tests/                     # coding standard (sylius-labs/coding-standard via ECS)
vendor/bin/ecs check src/ tests/ --fix               # auto-fix

composer validate --strict                           # composer.json sanity
composer run analyse                                 # validate + phpstan + ecs in one go
```

CI (`.github/workflows/build.yaml`) runs this exact sequence on PHP 8.2 and 8.3, with
`memory_limit=-1` baked into the PHP setup step (`shivammathur/setup-php`'s `ini-values`) rather than a
per-command flag.

## Code Conventions & Common Patterns

- **Every plugin-authored class is `final`.** No inheritance hierarchies within `src/`.
- **Naming suffixes**: `*Action` (Payum `ActionInterface`), `*Factory` (`GatewayFactory`), `*Status`
  (constant bags), `*Interface` (contracts), `*Api` (external-service adapters), `*Extension`/`*Plugin`
  (Symfony bundle plumbing), `*Type` (Symfony form types).
- **Error handling is deliberate and minimal, no custom exception classes**:
  - Every action's `execute()` opens with `RequestNotSupportedException::assertSupports($this, $request)`
    (fail-fast on gateway misconfiguration).
  - `CaptureAction` throws Payum's `LogicException` for a missing payment/order (programmer error).
  - `ComgateApi`'s constructor uses `Webmozart\Assert\Assert` for option validation (throws
    `Webmozart\Assert\InvalidArgumentException`).
  - `NotifyAction` uses **silent early-return** (no exception, no logging) for malformed/unrecognized
    webhook payloads — webhook callers can't be trusted and there's no way to surface an error back to
    Comgate anyway.
- **Never trust callback payloads.** Both `CaptureAction` (on return) and `NotifyAction` (webhook)
  re-fetch status via `ComgateApiInterface::getStatus()` instead of reading the browser query string or
  webhook body's own status field.
- **Manual DI wiring is the default for Payum services** (`config/services/payum.yaml` sets
  `autowire: false` project-wide for that file); the one exception is `NotifyAction`, which sets
  `autowire: true` specifically so its `Sylius\Component\Core\Repository\PaymentRepositoryInterface`
  constructor argument resolves via autowiring — do not wire that dependency by explicit service id
  string, autowiring is the only way it resolves in Sylius' compiled container.
- **Form services use standard Symfony defaults** (`config/services/form.yaml`:
  `autowire: true, autoconfigure: true, public: false`), the opposite of `payum.yaml`.
- **PHPStan**: `@param <ConcreteRequest> $request` docblocks narrow Payum's untyped `execute($request)`
  contract — this is intentional (matches paypal-plugin/mollie-plugin) and the resulting contravariance
  error is explicitly ignored per-path in `phpstan.neon.dist`. Don't "fix" that pattern by widening the
  docblock.

## Important Files

- `src/UnicorncrewSyliusComgatePlugin.php` — bundle entry point (`SyliusPluginTrait`, explicit
  `getContainerExtension()` override to pin the DI alias).
- `src/Payum/ComgateGatewayFactory.php` — where `merchant`/`secret`/`test` options are validated and the
  lazy `payum.api` closure builds `ComgateApi`.
- `src/Payum/Action/CaptureAction.php` — the `HttpRedirect` throw site (offsite redirect) and the
  re-poll-on-return branch.
- `src/Payum/Action/NotifyAction.php` — webhook entry point, `PaymentRepositoryInterface` lookup by
  `refId`.
- `config/services/payum.yaml` / `config/services/form.yaml` — all service wiring; there is no
  attribute-based (`#[AsTaggedItem]` etc.) service registration anywhere in this plugin.
- `config/config.yaml` — what a consuming app imports (see README "Installation" step 3); edit this, not
  `config/services.yaml`, when adding host-app-facing config.
- `phpstan.neon.dist`, `ecs.php`, `phpunit.xml.dist` — excluded from the distributed package via
  `.gitattributes` `export-ignore` (dev-only tooling, never shipped to Packagist consumers).
- `release-please-config.json` / `.release-please-manifest.json` — see Releasing below.

## Runtime/Tooling Preferences

- **PHP 8.2+** (CI matrix: 8.2, 8.3). Composer package, no Node/JS toolchain, no `scripts/` wrappers.
- **Composer** is the only package manager; `composer.lock` is intentionally gitignored (library
  convention — Packagist/Composer resolve versions from git tags, not a lockfile or a `version` field).
- Dependency versions are pinned narrower than usual in a few spots to avoid known breakage: e.g.
  `symfony/var-exporter: ^7.4` (newer 8.x renamed a method Doctrine ORM's proxy factory still expects) and
  `extra.symfony.require: ^7.4`. Don't casually bump these without checking `sylius/test-application`
  compatibility.
- Default branch is **`master`** (not `main`) — both CI workflows trigger on pushes to `master`.
- Commit messages MUST follow **Conventional Commits** (`feat:`, `fix:`, `chore:`, `docs:`, `test:`,
  `ci:`, `refactor:`, `style:`, `feat!:`/`BREAKING CHANGE:` footer for majors) — this is what drives
  `release-please`'s version bump and changelog, documented in README "Releasing".

## Testing & QA

- **PHPUnit 10.5**, two independent testsuites in `phpunit.xml.dist`: `Unit` (`tests/Unit`, no framework
  bootstrap) and `Functional` (`tests/Functional`, boots a real Sylius kernel).
- **Unit tests** construct Payum request objects directly (`Payum\Core\Request\{Capture,Notify,Convert}`,
  `Sylius\Bundle\PayumBundle\Request\GetStatus`) and mock `ComgateApiInterface` with PHPUnit's
  `createMock()`. To simulate real Comgate SDK responses without network calls, tests build the response
  wrapper chain bottom-up: `new Nyholm\Psr7\Response(200, [], json_encode([...]))` → `new
  Comgate\SDK\Http\Response(...)` → the specific SDK entity (`new PaymentCreateResponse(...)` /
  `PaymentStatusResponse(...)`). Follow this exact pattern for any new Comgate-response fixture (see
  `tests/Unit/Payum/Action/CaptureActionTest.php`).
- Sylius entities (`Payment`/`Order`/`Customer`) have no public `id` setter — tests use
  `\ReflectionProperty` to force it (see `createPayment()` helper in `CaptureActionTest`).
- Webhook simulation (`NotifyActionTest`) builds a real `Payum\Core\Gateway` with an inline anonymous
  `ActionInterface` that intercepts `GetHttpRequest` and injects fixture query/request data — no HTTP
  layer involved.
- **Functional test** (`tests/Functional/ContainerCompilationTest.php`, extends `KernelTestCase`) is a
  container-compilation smoke test standing in for `bin/console debug:container`: it boots the kernel
  provided by `sylius/test-application` (extended via `tests/TestApplication/bundles.php` +
  `.env`/`.env.test`) and asserts the bundle is registered, the `comgate` Payum gateway factory is
  registered *and actually buildable*, all four action services resolve, the form type is registered, and
  `comgate` appears in the `sylius.gateway_factories` parameter. No database is queried (SQLite path
  configured but unused).
- **Compiling the real Sylius container needs more than PHP's default 128M CLI `memory_limit`** — always
  run the full suite as `php -d memory_limit=-1 vendor/bin/phpunit`, or scope to
  `--testsuite "Unicorncrew Sylius Comgate Plugin - Unit"` if you don't need the container boot.
  A new PR or test that mysteriously "hangs"/OOMs is almost certainly this.
- Test naming: `final class <Subject>Test extends TestCase`, methods `testIt<BehaviorDescription>(): void`
  (behavior-first, never bare `testExecute`), private `createXxx()` helper factories for fixtures,
  `@dataProvider` static methods yielding `'description' => [...]` for table-driven cases (see
  `StatusActionTest::statusMappingProvider`).
- No Behat/browser-level tests ship with this plugin (mentioned explicitly in README) — coverage stops at
  the container/unit level.
