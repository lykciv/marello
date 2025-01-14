<?php

namespace Marello\Bundle\ReturnBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Oro\Bundle\FormBundle\Form\Type\OroDateType;

use Marello\Bundle\ReturnBundle\Entity\ReturnEntity;
use Marello\Bundle\ReturnBundle\Form\EventListener\ReturnTypeSubscriber;
use Marello\Bundle\ReturnBundle\Validator\Constraints\ReturnEntityConstraint;
use Marello\Bundle\SalesBundle\Form\Type\SalesChannelSelectType;

class ReturnType extends AbstractType
{
    const BLOCK_PREFIX = 'marello_return';

    /**
     * @var ReturnTypeSubscriber
     */
    protected $returnTypeSubscriber;

    /**
     * @param ReturnTypeSubscriber $returnTypeSubscriber
     */
    public function __construct(ReturnTypeSubscriber $returnTypeSubscriber)
    {
        $this->returnTypeSubscriber = $returnTypeSubscriber;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('salesChannel', SalesChannelSelectType::class)
            ->add('returnItems', ReturnItemCollectionType::class)
            ->add(
                'trackTraceCode',
                TextType::class,
                [
                    'label' => 'marello.return.returnentity.track_trace_code.label',
                    'required' => false
                ]
            );

        $builder->addEventSubscriber($this->returnTypeSubscriber);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => ReturnEntity::class,
            'constraints' => [
                new ReturnEntityConstraint()
            ]
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return self::BLOCK_PREFIX;
    }
}
