<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Functional;

use Sylius\Bundle\PaymentBundle\Form\Type\GatewayConfigType;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\TwigHooks\Bag\DataBag;
use Sylius\TwigHooks\Bag\ScalarDataBag;
use Sylius\TwigHooks\Hook\Metadata\HookMetadata;
use Sylius\TwigHooks\Hookable\Metadata\HookableMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Twig\Environment;

/**
 * Renders Sylius' own admin "gateway configuration" section of the payment method form (the hookable
 * that dispatches to `...gateway_configuration.<factoryName>`) the same way the admin create/update pages
 * do, and proves the Comgate fields actually end up in the HTML. Without them the admin form can't be
 * submitted: the fields' NotBlank errors would be attached to fields that are never rendered.
 *
 * The root form only carries the `gatewayConfig` child (built by the real GatewayConfigType, which pulls
 * the Comgate configuration type from the gateway configuration type registry) so no database is needed.
 */
final class GatewayConfigurationFormRenderingTest extends KernelTestCase
{
    private const FORM_NAME = 'sylius_admin_payment_method';

    /**
     * @dataProvider operationProvider
     */
    public function testItRendersTheComgateFieldsInTheAdminPaymentMethodForm(string $operation): void
    {
        self::bootKernel();

        $html = $this->renderGatewayConfigurationSection($operation, $this->createForm($this->createComgatePaymentMethod())->createView());

        self::assertStringContainsString('Merchant ID', $html);
        self::assertStringContainsString('Secret', $html);
        self::assertStringContainsString('Test mode', $html);
        self::assertStringContainsString('type="text"', $this->findInput($html, 'merchant'));
        self::assertStringContainsString('autocomplete="off"', $this->findInput($html, 'secret'));
        self::assertStringContainsString('type="checkbox"', $this->findInput($html, 'test'));
    }

    /**
     * @dataProvider operationProvider
     */
    public function testItPrefillsTheStoredGatewayConfiguration(string $operation): void
    {
        self::bootKernel();

        $paymentMethod = $this->createComgatePaymentMethod();
        $paymentMethod->getGatewayConfig()?->setConfig(['merchant' => '123456', 'secret' => 'foobarbaz', 'test' => true]);

        $html = $this->renderGatewayConfigurationSection($operation, $this->createForm($paymentMethod)->createView());

        self::assertStringContainsString('value="123456"', $this->findInput($html, 'merchant'));
        self::assertStringContainsString('value="foobarbaz"', $this->findInput($html, 'secret'));
        self::assertStringContainsString('checked="checked"', $this->findInput($html, 'test'));
    }

    /**
     * @dataProvider operationProvider
     */
    public function testItShowsTheValidationErrorsNextToTheBlankFields(string $operation): void
    {
        self::bootKernel();

        $form = $this->createForm($this->createComgatePaymentMethod());
        $form->submit(['gatewayConfig' => ['config' => ['merchant' => '', 'secret' => '']]]);

        $config = $form->get('gatewayConfig')->get('config');
        self::assertFalse($config->isValid());
        $merchantError = $config->get('merchant')->getErrors()->current();
        self::assertNotFalse($merchantError);

        $html = $this->renderGatewayConfigurationSection($operation, $form->createView());

        self::assertStringContainsString('is-invalid', $this->findInput($html, 'merchant'));
        self::assertStringContainsString('is-invalid', $this->findInput($html, 'secret'));
        self::assertStringContainsString(htmlspecialchars($merchantError->getMessage()), $html);
    }

    public function testItAcceptsAFilledInGatewayConfiguration(): void
    {
        self::bootKernel();

        $form = $this->createForm($this->createComgatePaymentMethod());
        $form->submit(['gatewayConfig' => ['config' => ['merchant' => '123456', 'secret' => 'foobarbaz', 'test' => '1']]]);

        self::assertTrue($form->get('gatewayConfig')->get('config')->isValid());
        self::assertSame(
            ['merchant' => '123456', 'secret' => 'foobarbaz', 'test' => true],
            $form->get('gatewayConfig')->getData()->getConfig(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationProvider(): iterable
    {
        yield 'create page' => ['create'];
        yield 'update page' => ['update'];
    }

    private function createComgatePaymentMethod(): PaymentMethodInterface
    {
        /** @var PaymentMethodFactoryInterface<PaymentMethodInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.payment_method');

        return $factory->createWithGateway('comgate');
    }

    private function createForm(PaymentMethodInterface $paymentMethod): FormInterface
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::getContainer()->get('form.factory');

        return $formFactory
            ->createNamedBuilder(self::FORM_NAME, FormType::class, ['gatewayConfig' => $paymentMethod->getGatewayConfig()], [
                'csrf_protection' => false,
            ])
            ->add('gatewayConfig', GatewayConfigType::class)
            ->getForm()
        ;
    }

    private function renderGatewayConfigurationSection(string $operation, FormView $form): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        // Mirrors what the admin `...content.form.sections` hook passes to its "gateway_configuration" hookable.
        $hookName = sprintf('sylius_admin.payment_method.%s.content.form.sections', $operation);
        $context = new DataBag(['form' => $form]);
        $metadata = new HookableMetadata(new HookMetadata($hookName, $context), $context, new ScalarDataBag([]), [$hookName]);

        return $twig
            ->createTemplate(<<<'TWIG'
                {% form_theme hookable_metadata.context.form '@SyliusAdmin/shared/form_theme.html.twig' %}
                {% include '@SyliusAdmin/payment_method/form/sections/gateway_configuration.html.twig' %}
                TWIG)
            ->render(['hookable_metadata' => $metadata])
        ;
    }

    private function findInput(string $html, string $field): string
    {
        $pattern = sprintf('/<input[^>]*name="%s\[gatewayConfig\]\[config\]\[%s\]"[^>]*>/', self::FORM_NAME, $field);

        self::assertMatchesRegularExpression($pattern, $html, sprintf('The "%s" field is not rendered.', $field));
        preg_match($pattern, $html, $matches);

        return $matches[0];
    }
}
