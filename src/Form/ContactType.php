<?php

namespace App\Form;

use App\Model\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('email', EmailType::class)
            ->add('message', TextareaType::class, [
                'attr' => ['rows' => 6],
            ])
            ->add('humanAnswer', IntegerType::class, [
                'mapped' => false,
                'label' => 'Question de sécurité : combien font 3 + 4 ?',
                'constraints' => [
                    new NotBlank(message: 'La réponse est obligatoire.'),
                    new EqualTo(value: 7, message: 'Réponse incorrecte.'),
                ],
            ])
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Site web',
                'constraints' => [
                    new Blank(message: 'Spam détecté.'),
                ],
                'attr' => [
                    'class' => 'honeypot',
                    'autocomplete' => 'off',
                    'tabindex' => '-1',
                ],
                'row_attr' => [
                    'class' => 'honeypot-field',
                    'aria-hidden' => 'true',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactMessage::class,
        ]);
    }
}
