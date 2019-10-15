<?php

namespace Marello\Bundle\OroCommerceBundle\EventListener\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Marello\Bundle\OroCommerceBundle\Entity\OroCommerceSettings;
use Marello\Bundle\OroCommerceBundle\ImportExport\Reader\ProductExportCreateReader;
use Marello\Bundle\OroCommerceBundle\ImportExport\Writer\AbstractExportWriter;
use Marello\Bundle\OroCommerceBundle\ImportExport\Writer\ProductExportBulkDeleteWriter;
use Marello\Bundle\OroCommerceBundle\ImportExport\Writer\ProductExportCreateWriter;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceInventoryLevelConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceProductConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceProductImageConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceProductPriceConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceTaxCodeConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceTaxJurisdictionConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceTaxRateConnector;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceTaxRuleConnector;
use Marello\Bundle\OroCommerceBundle\Integration\OroCommerceChannelType;
use Marello\Bundle\ProductBundle\Entity\Product;
use Marello\Bundle\SalesBundle\Entity\SalesChannel;
use Oro\Bundle\EntityBundle\Event\OroEventManager;
use Oro\Bundle\ImportExportBundle\Context\Context;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\IntegrationBundle\Reader\EntityReaderById;
use Oro\Component\DependencyInjection\ServiceLink;

class ReverseSyncAllProductsListener
{
    /**
     * @var ServiceLink
     */
    protected $syncScheduler;

    /**
     * @var ProductExportBulkDeleteWriter
     */
    protected $productsBulkDeleteWriter;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @param ServiceLink $syncScheduler
     * @param ProductExportBulkDeleteWriter $productsBulkDeleteWriter
     */
    public function __construct(ServiceLink $syncScheduler, ProductExportBulkDeleteWriter $productsBulkDeleteWriter)
    {
        $this->syncScheduler = $syncScheduler;
        $this->productsBulkDeleteWriter = $productsBulkDeleteWriter;
    }

    /**
     * @param PreUpdateEventArgs $args
     */
    public function preUpdate(PreUpdateEventArgs $args)
    {

        $channel = $args->getEntity();
        if ($channel instanceof Channel && $channel->getType() === OroCommerceChannelType::TYPE) {
            $this->entityManager = $args->getEntityManager();
            /** @var OroCommerceSettings $transport */
            $transport = $this->entityManager
                ->getRepository(OroCommerceSettings::class)
                ->find($channel->getTransport()->getId());
            $settingsBag = $transport->getSettingsBag();
            $this->entityManager = $args->getEntityManager();
            $changeSet = $args->getEntityChangeSet();
            $channelId = $channel->getId();
            if (count($changeSet) > 0 && isset($changeSet['enabled'])) {
                if ($changeSet['enabled'][1] === true) {
                    if ($settingsBag->get(OroCommerceSettings::DELETE_REMOTE_DATA_ON_DEACTIVATION) === true) {
                        $products = $this->getAllProducts();
                        foreach ($products as $product) {
                            $salesChannel = $this->getSalesChannelFromIntegrationChannel($product, $channel);
                            if ($salesChannel !== null) {
                                $this->syncScheduler->getService()->schedule(
                                    $channel->getId(),
                                    OroCommerceProductConnector::TYPE,
                                    [
                                        AbstractExportWriter::ACTION_FIELD => AbstractExportWriter::CREATE_ACTION,
                                        ProductExportCreateReader::SKU_FILTER => $product->getSku(),
                                    ]
                                );
                                $finalPrice = ReverseSyncProductPriceListener::getFinalPrice($product, $salesChannel);
                                $this->syncScheduler->getService()->schedule(
                                    $channel->getId(),
                                    OroCommerceProductPriceConnector::TYPE,
                                    [
                                        'processorAlias' => 'marello_orocommerce_product_price.export',
                                        AbstractExportWriter::ACTION_FIELD => AbstractExportWriter::CREATE_ACTION,
                                        ProductExportCreateReader::SKU_FILTER => $product->getSku(),
                                        'value' => $finalPrice->getValue(),
                                        'currency' => $finalPrice->getCurrency(),
                                    ]
                                );
                                $this->syncScheduler->getService()->schedule(
                                    $channel->getId(),
                                    OroCommerceInventoryLevelConnector::TYPE,
                                    [
                                        AbstractExportWriter::ACTION_FIELD => AbstractExportWriter::UPDATE_ACTION,
                                        'product' => $product->getId(),
                                        'group' => $salesChannel->getGroup()->getId(),
                                    ]
                                );
                                if ($image = $product->getImage()) {
                                    $this->syncScheduler->getService()->schedule(
                                        $channel->getId(),
                                        OroCommerceProductImageConnector::TYPE,
                                        [
                                            AbstractExportWriter::ACTION_FIELD => AbstractExportWriter::CREATE_ACTION,
                                            EntityReaderById::ID_FILTER => $image->getId(),
                                        ]
                                    );
                                }
                            }
                        }
                    } elseif ($settingsBag->get(OroCommerceSettings::DELETE_REMOTE_DATA_ON_DEACTIVATION) === false) {
                        $data = $settingsBag->get(OroCommerceSettings::DATA);
                        if (isset($data[AbstractExportWriter::NOT_SYNCHRONIZED])) {
                            $notSynchronizedData = $data[AbstractExportWriter::NOT_SYNCHRONIZED];
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceProductConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceProductImageConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceProductPriceConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceInventoryLevelConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceTaxCodeConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceTaxRateConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceTaxJurisdictionConnector::TYPE
                            );
                            $notSynchronizedData = $this->synchronizeNotSynchronizedData(
                                $channel,
                                $notSynchronizedData,
                                OroCommerceTaxRuleConnector::TYPE
                            );
                            if ($data[AbstractExportWriter::NOT_SYNCHRONIZED] !== $notSynchronizedData) {
                                $data[AbstractExportWriter::NOT_SYNCHRONIZED] = $notSynchronizedData;
                                if (empty($data[AbstractExportWriter::NOT_SYNCHRONIZED])) {
                                    unset($data[AbstractExportWriter::NOT_SYNCHRONIZED]);
                                }
                                $transport->setData($data);
                                $this->entityManager->persist($transport);
                                /** @var OroEventManager $eventManager */
                                $eventManager = $this->entityManager->getEventManager();
                                $eventManager->removeEventListener(
                                    'preUpdate',
                                    'marello_orocommerce.event_listener.doctrine.reverse_sync_product.all'
                                );
                                $this->entityManager->flush($transport);
                            }
                        }
                    }
                } elseif ($changeSet['enabled'][1] === false &&
                    $settingsBag->get(OroCommerceSettings::DELETE_REMOTE_DATA_ON_DEACTIVATION) === true)
                {
                    $products = $this->getSynchronizedProducts();
                    $context = new Context(['channel' => $channelId]);
                    $this->productsBulkDeleteWriter->setImportExportContext($context);
                    $this->productsBulkDeleteWriter->write($products);
                }
            }
        }
    }
    
    /**
     * @param LifecycleEventArgs $args
     */
    public function preRemove(LifecycleEventArgs $args)
    {
        $channel = $args->getEntity();
        if ($channel instanceof Channel && $channel->getType() === OroCommerceChannelType::TYPE) {
            $settingsBag = $channel->getTransport()->getSettingsBag();
            if ($settingsBag->get(OroCommerceSettings::DELETE_REMOTE_DATA_ON_DELETION) === true) {
                $this->entityManager = $args->getEntityManager();
                $products = $this->getSynchronizedProducts();
                $context = new Context(['channel' => $channel->getId()]);
                $this->productsBulkDeleteWriter->setImportExportContext($context);
                $this->productsBulkDeleteWriter->write($products);
            }
        }
    }
    
    /**
     * @return Product[]
     */
    private function getAllProducts()
    {
        return $this->entityManager->getRepository(Product::class)->findAll();
    }

    /**
     * @return Product[]
     */
    private function getSynchronizedProducts()
    {
        return $this->entityManager
            ->getRepository(Product::class)
            ->findByDataKey(ProductExportCreateWriter::PRODUCT_ID_FIELD);
    }

    /**
     * @param Product $product
     * @param Channel $integrationChannel
     * @return SalesChannel|null
     */
    private function getSalesChannelFromIntegrationChannel(Product $product, Channel $integrationChannel)
    {
        foreach ($product->getChannels() as $salesChannel) {
            if ($salesChannel->getIntegrationChannel() === $integrationChannel) {
                return $salesChannel;
            }
        }
        
        return null;
    }

    /**
     * @param Channel $channel
     * @param array $data
     * @param string $connectorType
     * @return array
     */
    private function synchronizeNotSynchronizedData(Channel $channel, array $data, $connectorType)
    {
        if (isset($data[$connectorType])) {
            foreach ($data[$connectorType] as $key => $connector_params) {
                $this->syncScheduler->getService()->schedule(
                    $channel->getId(),
                    $connectorType,
                    $connector_params
                );
                unset($data[$connectorType][$key]);
            }
            if (empty($data[$connectorType])) {
                unset($data[$connectorType]);
            }
        }

        return $data;
    }
}