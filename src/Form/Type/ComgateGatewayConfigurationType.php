<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ComgateGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('merchant', TextType::class, [
                'label' => 'unicorncrew_sylius_comgate.form.merchant',
                'constraints' => [
                    new NotBlank(['groups' => ['sylius']]),
                ],
            ])
            ->add('secret', TextType::class, [
                'label' => 'unicorncrew_sylius_comgate.form.secret',
                'constraints' => [
                    new NotBlank(['groups' => ['sylius']]),
                ],
            ])
            ->add('test', CheckboxType::class, [
                'label' => 'unicorncrew_sylius_comgate.form.test_mode',
                'required' => false,
            ])
        ;
    }
}
