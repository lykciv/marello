<?php

namespace Marello\Bundle\RefundBundle\Workflow\Actions;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Marello\Bundle\RefundBundle\Entity\Refund;
use Marello\Bundle\ReturnBundle\Entity\ReturnEntity;
use Oro\Bundle\WorkflowBundle\Exception\InvalidParameterException;
use Oro\Bundle\WorkflowBundle\Model\Action\AbstractAction;
use Oro\Bundle\WorkflowBundle\Model\Action\ActionInterface;
use Oro\Bundle\WorkflowBundle\Model\ContextAccessor;

class CreateRefundAction extends AbstractAction
{
    /** @var array */
    protected $options;

    /** @var Registry */
    protected $doctrine;

    /**
     * CreateRefundAction constructor.
     *
     * @param ContextAccessor $contextAccessor
     * @param Registry        $doctrine
     */
    public function __construct(ContextAccessor $contextAccessor, Registry $doctrine)
    {
        parent::__construct($contextAccessor);

        $this->doctrine = $doctrine;
    }

    /**
     * @param mixed $context
     */
    protected function executeAction($context)
    {
        /** @var ReturnEntity $return */
        $return = $context->getEntity();

        $refund = Refund::fromReturn($return);

        $this->doctrine->getManager()->persist($refund);
        $this->doctrine->getManager()->flush();
    }

    /**
     * Initialize action based on passed options.
     *
     * @param array $options
     *
     * @return ActionInterface
     * @throws InvalidParameterException
     */
    public function initialize(array $options)
    {
        $this->options = $options;

        return $this;
    }
}
