<?php

namespace Marello\Bundle\OrderBundle\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Marello\Bundle\CustomerBundle\Entity\Company;
use Marello\Bundle\LayoutBundle\Context\FormChangeContextInterface;
use Marello\Bundle\LayoutBundle\Provider\FormChangesProviderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Templating\EngineInterface;

class OrderCompanyCustomerFormChangesProvider implements FormChangesProviderInterface
{
    /**
     * @var EngineInterface
     */
    protected $templatingEngine;

    /**
     * @var FormFactoryInterface
     */
    protected $formFactory;

    /**
     * @var ManagerRegistry
     */
    protected $registry;

    public function __construct(
        EngineInterface $templatingEngine,
        FormFactoryInterface $formFactory,
        ManagerRegistry $registry
    ) {
        $this->templatingEngine = $templatingEngine;
        $this->formFactory = $formFactory;
        $this->registry = $registry;
    }
    
    /**
     * {@inheritdoc}
     */
    public function processFormChanges(FormChangeContextInterface $context)
    {
        $form = $context->getForm();
        $data = $context->getSubmittedData();
        if (!$form->has('company') || empty($data['customer'])) {
            return;
        }

        $companyId = $this->registry
            ->getRepository(Company::class)
            ->getCompanyIdByCustomerId((int) $data['customer']);
        if (!$companyId) {
            return;
        }

        $data['company'] = (string) $companyId;
        $orderFormName = $form->getName();
        $field = $form->get('company');

        $form = $this->formFactory
            ->createNamedBuilder($orderFormName)
            ->add(
                'company',
                get_class($field->getConfig()->getType()->getInnerType()),
                $field->getConfig()->getOptions()
            )
            ->getForm();

        $form->submit($data);

        $result = $context->getResult();
        $result['company'] = $this->renderForm($form->get('company')->createView());
        $context->setResult($result);
    }

    /**
     * @param FormView $formView
     * @return string
     */
    protected function renderForm(FormView $formView)
    {
        return $this
            ->templatingEngine
            ->render('MarelloOrderBundle:Form:companySelector.html.twig', ['form' => $formView]);
    }
}
