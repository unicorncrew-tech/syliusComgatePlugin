<p align="center">
    <a href="https://sylius.com" target="_blank">
        <img src="https://media.sylius.com/sylius-logo-800.png" alt="Sylius Logo" width="200">
    </a>
</p>

<h1 align="center">Sylius Comgate Plugin</h1>

<p align="center">
    <a href="https://github.com/unicorncrew-tech/syliusComgatePlugin/actions"><img src="https://github.com/unicorncrew-tech/syliusComgatePlugin/workflows/Build/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/unicorncrew-tech/sylius-comgate-plugin"><img src="https://img.shields.io/packagist/v/unicorncrew-tech/sylius-comgate-plugin.svg?style=flat-square" alt="Latest Version on Packagist"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square" alt="Software License"></a>
</p>

<p align="center">
    Integration of the Czech payment gateway <a href="https://www.comgate.cz/" target="_blank">Comgate</a>
    with <a href="https://sylius.com" target="_blank">Sylius</a> as a
    <a href="https://github.com/Payum/Payum" target="_blank">Payum</a> gateway.
</p>

---

## Requirements

- Sylius `^2.0`
- PHP `^8.2`
- A Comgate merchant account ([comgate.cz](https://www.comgate.cz/)), with the **merchant ID** and
  **secret** from [portal.comgate.cz](https://portal.comgate.cz/).

## How it works

Comgate is a redirect + server-to-server-webhook gateway: the shopper is redirected to a Comgate-hosted
payment page, Comgate then confirms the outcome by calling back a *STATUS URL* on your shop and by
redirecting the shopper back to a *PAID/CANCELLED/PENDING URL*.

This plugin implements that flow as a Payum gateway (factory name `comgate`), the same architecture used
by the official `sylius/paypal-plugin` and `sylius/mollie-plugin`:

- `CaptureAction` creates the payment at Comgate and throws a redirect to the hosted payment page. When
  the shopper is redirected back, it re-fetches the authoritative status straight from Comgate rather than
  trusting query parameters set by the browser.
- `NotifyAction` handles the asynchronous webhook Comgate calls on `payum_notify_do_unsafe/{gateway}`. It
  only uses the webhook payload to look the payment up (by `refId`/`transId`), the payment status itself is
  always re-fetched through `Client::getStatus()`.
- `StatusAction` maps the Comgate payment status onto Sylius' payment state machine. Sylius' own
  `UpdatePaymentStateExtension` (registered core-wide) takes care of applying the corresponding transition
  after every `Capture`/`Notify` call, so no extra listener is required.
- `RefundAction` returns money of a paid payment through Comgate's refund API (full or partial). It is
  triggered from the admin, see [Refunds](#refunds).

## Installation

1. Require the plugin:

   ```shell
   composer require unicorncrew-tech/sylius-comgate-plugin
   ```

2. Enable it in `config/bundles.php`:

   ```php
   return [
       // ...
       Unicorncrew\SyliusComgatePlugin\UnicorncrewSyliusComgatePlugin::class => ['all' => true],
   ];
   ```

3. Import its configuration in `config/packages/unicorncrew_sylius_comgate.yaml`:

   ```yaml
   imports:
       - { resource: "@UnicorncrewSyliusComgatePlugin/config/config.yaml" }
   ```

4. Run migrations if needed (this plugin adds no new entities/tables, so this is normally a no-op) and
   clear the cache.

## Configuration

1. Go to the admin panel, **Configuration > Payment methods**, and create a new payment method.
2. Pick **Comgate** as the gateway.
3. Fill in:
   - **Code**: e.g. `comgate` — this becomes the gateway name used in the webhook URL below.
   - **Channels**: the channels this payment method should be available on.
   - **Merchant ID** / **Secret**: from [portal.comgate.cz](https://portal.comgate.cz/).
   - **Test mode**: check this while testing; Comgate flags the payment as a test transaction rather than
     using a different endpoint.
4. Give it a display name for each locale, and save.

### Webhook (STATUS URL)

In [portal.comgate.cz](https://portal.comgate.cz/), set the **STATUS URL** (and, if you want Comgate itself
to redirect back with a `PAID`/`CANCELLED`/`PENDING` query string, the corresponding redirect URLs — this
plugin already sets its own dynamic return URL per payment, so those portal-level URLs are only a fallback)
to:

```
https://your-shop.example/payment/notify/unsafe/<payment method code>
```

Use `bin/console debug:router payum_notify_do_unsafe` to double check the exact path prefix used by your
shop, and the payment method's `code` (as configured in step 3 above) as the `<payment method code>`
segment.

## Refunds

Refunds are sent to Comgate's refund API from the Sylius admin. There are two ways to do it, depending on
whether [`sylius/refund-plugin`](https://github.com/Sylius/RefundPlugin) is installed.

### Full refund (Sylius core)

On the order page (**Sales > Orders > order > Payments**), the **Refund** button of a completed Comgate
payment refunds the whole payment amount at Comgate, then marks the payment as refunded.

- If Comgate refuses the refund (e.g. the amount was already refunded, or the merchant balance is too
  low), the payment stays **completed** and the admin sees an error flash with Comgate's reason. Sylius'
  refund route always uses its "Payment has been successfully refunded." flash text, so that message also
  shows up, in red.
- A Comgate payment with no Comgate transaction (e.g. an admin completed it by hand) is only marked as
  refunded. No refund is sent to Comgate, because there is no Comgate transaction to take the money from.

This works with the Symfony Workflow state machine (the Sylius 2 default) and with
`winzou_state_machine` if your app maps the `sylius_payment` graph to it.

### Partial refunds (`sylius/refund-plugin`)

With [`sylius/refund-plugin`](https://github.com/Sylius/RefundPlugin) installed and enabled, Sylius' core
payment **Refund** button is hidden, and you refund from the order's **Refunds** page instead. There you pick
any items and shipping costs to refund, including partial amounts. This plugin adds `comgate` to
`sylius_refund.supported_gateways` for you, so Comgate payment methods show up as refund methods.

When the refund method you pick is a Comgate one, the refunded amount goes to Comgate through the order's
Comgate payment, and the refund payment is marked **completed** straight away. If Comgate refuses the
refund, or the order was not paid through Comgate, the refund plugin rolls the whole refund back (no
credit memo, no refund payment) and shows its generic error flash. The reason is in the application log.
To refund an order without moving money through Comgate (e.g. by bank transfer), pick an `offline`
refund method.

Comgate always refunds in the currency of the original transaction. Test-mode gateways send test-mode
refunds.

## Testing

This plugin uses [`sylius/test-application`](https://github.com/Sylius/TestApplication) as a dev
dependency instead of bundling its own throwaway Sylius app. `tests/Unit` runs in isolation (no
framework needed); `tests/Functional` boots the real Sylius kernel — with this plugin enabled via
`tests/TestApplication/bundles.php` and `tests/TestApplication/.env` — and asserts the container actually
compiles: the `comgate` Payum gateway factory is registered, its actions resolve, and the admin gateway
configuration form type is wired, i.e. everything `bin/console debug:container` would otherwise be used
for. It also renders the admin payment method form's gateway configuration section (create and update)
through Sylius' Twig hooks and asserts the Merchant ID / Secret / Test mode fields and their validation
errors show up, and applies Sylius' payment `refund` transition to check that it refunds at Comgate, or
is aborted when Comgate refuses the refund. A second kernel with `sylius/refund-plugin` enabled checks
that Comgate is offered as a refund method and that the plugin's refund payments are refunded at Comgate.

```shell
composer install

# Full test suite (unit + functional). Compiling the real Sylius container needs more than the
# default CLI 128M memory_limit.
php -d memory_limit=-1 vendor/bin/phpunit

vendor/bin/phpstan analyse -c phpstan.neon.dist
vendor/bin/ecs check src/ tests/
```

No database is required to run the suite (`tests/TestApplication/.env` points `DATABASE_URL` at a local
SQLite file); it's only needed for Behat/browser-level testing, which this plugin does not ship.

## Releasing

Commit messages MUST follow [Conventional Commits](https://www.conventionalcommits.org/) - this is what
drives versioning and the changelog:

- `feat: …` - new functionality, bumps the minor version (`1.2.0` -> `1.3.0`).
- `fix: …` - bug fixes, bumps the patch version (`1.2.0` -> `1.2.1`).
- `feat!: …` / a `BREAKING CHANGE:` footer - bumps the major version (`1.2.0` -> `2.0.0`).
- `chore:`, `docs:`, `test:`, `ci:`, `refactor:`, `style:` - no release on their own; grouped in the
  changelog instead of triggering a version bump.

Releases are automated with [release-please](https://github.com/googleapis/release-please): every push to
`master` runs `.github/workflows/release-please.yaml`, which keeps a "Release PR" up to date with the next
version (computed from the commits above) and the generated `CHANGELOG.md`. Merging that PR tags the
release and publishes a GitHub Release; Packagist picks up new tags automatically once the repository is
registered there.

There is no `version` field to bump in `composer.json` - Packagist and Composer resolve versions from git
tags, so nothing else needs to change by hand.

## Security

If you think you have found a security issue, please do not use the public issue tracker; contact the
maintainers directly instead.

## License

This plugin is released under the [MIT License](LICENSE).
