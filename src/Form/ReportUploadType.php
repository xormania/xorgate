<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ReportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('request_id', HiddenType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Uuid()],
            ])
            ->add('report_role', TextType::class, [
                'label' => 'Report role',
                'help' => 'A stable label for this report within the execution, such as unit-tests or integration-tests.',
                'attr' => ['maxlength' => 80],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 80)],
            ])
            ->add('format', ChoiceType::class, [
                'label' => 'Report format',
                'choices' => ['JUnit XML' => 'junit'],
                'help' => 'The worker will inspect the uploaded bytes. Unsupported report structure is reported explicitly.',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('report', FileType::class, [
                'label' => 'Original report',
                'mapped' => false,
                'help' => 'Upload the original report bytes. A file must be selected again after a rejected submission.',
                'attr' => ['accept' => '.xml,application/xml,text/xml'],
                'constraints' => [new Assert\NotNull(message: 'Select a report file.'), new Assert\File(maxSize: $options['max_bytes'])],
            ])
        ;

        $source = $builder->create('source', FormType::class, [
            'label' => 'Report source context',
            'required' => false,
            'help' => 'Verify the source that produced this report. Known fields must match the stored execution context. Leave unknown values blank.',
        ]);
        foreach ([
            'repository_identity' => ['Repository identity', 160],
            'repository_url' => ['Repository URL', 2048],
            'tested_revision' => ['Actual tested revision', 256],
            'workspace_fingerprint' => ['Workspace fingerprint', 256],
            'pr_head_revision' => ['PR head revision', 256],
            'pr_base_revision' => ['PR base revision', 256],
            'pr_merge_revision' => ['PR merge revision', 256],
        ] as $field => [$label, $max]) {
            $source->add($field, TextType::class, [
                'label' => $label,
                'required' => false,
                'attr' => ['maxlength' => $max],
                'constraints' => [new Assert\Length(max: $max)],
            ]);
        }
        $builder->add($source);
        $builder->add('confirm_context', CheckboxType::class, [
            'label' => 'I confirm that this report was produced from the execution context shown here.',
            'mapped' => false,
            'constraints' => [new Assert\IsTrue(message: 'Confirm the report source context before uploading.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('max_bytes');
        $resolver->setAllowedTypes('max_bytes', 'int');
        $resolver->setAllowedValues('max_bytes', static fn (int $value): bool => $value > 0);
        $resolver->setDefaults(['csrf_protection' => true]);
    }
}
