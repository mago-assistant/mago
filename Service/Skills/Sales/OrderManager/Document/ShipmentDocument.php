<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as TrackCollectionFactory;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class ShipmentDocument extends AbstractDocument
{
    public const SORTS = [
        'newest' => ['created_at', 'DESC'],
        'oldest' => ['created_at', 'ASC'],
    ];

    protected const FIELD_FILTERS = [
        'document_number' => ['increment_id', 'eq'],
        'from' => ['created_at', 'from'],
        'to' => ['created_at', 'to'],
        'order_ids' => ['order_id', 'in'],
    ];

    public function __construct(
        private readonly ShipmentCollectionFactory $shipmentCollectionFactory,
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly TrackCollectionFactory $trackCollectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getAclResource(): string
    {
        return 'Magento_Sales::shipment';
    }

    protected function collection(array $filters): ShipmentCollection
    {
        $shipments = $this->shipmentCollectionFactory->create();

        $this->filterFields($shipments, $filters);
        $this->filterTracking($shipments, $filters);
        $this->filterProduct($shipments, $filters);

        return $shipments;
    }

    private function filterTracking(ShipmentCollection $shipments, array $filters): void
    {
        $carrier = $filters['carrier'] ?? '';
        $trackingNumber = $filters['tracking_number'] ?? '';
        if ($carrier === '' && $trackingNumber === '') {
            return;
        }

        $tracks = $this->trackCollectionFactory->create();

        if ($carrier !== '') {
            $carrierPattern = '%' . $carrier . '%';
            $tracks->addFieldToFilter(
                ['title', 'carrier_code'],
                [['like' => $carrierPattern], ['like' => $carrierPattern]]
            );
        }

        if ($trackingNumber !== '') {
            $tracks->addFieldToFilter('track_number', $trackingNumber);
        }

        $trackSelect = $tracks->getSelect();
        $trackSelect->reset(Select::COLUMNS);
        $trackSelect->columns('parent_id');

        $shipments->addFieldToFilter('main_table.entity_id', ['in' => $trackSelect]);
    }

    private function filterProduct(ShipmentCollection $shipments, array $filters): void
    {
        $product = $filters['product'] ?? '';
        if ($product === '') {
            return;
        }

        $productPattern = '%' . $product . '%';
        $items = $this->itemCollectionFactory->create();
        $items->addFieldToFilter(
            ['sku', 'name'],
            [['like' => $productPattern], ['like' => $productPattern]]
        );

        $itemSelect = $items->getSelect();
        $itemSelect->reset(Select::COLUMNS);
        $itemSelect->columns('parent_id');

        $shipments->addFieldToFilter('main_table.entity_id', ['in' => $itemSelect]);
    }

    protected function describe(AbstractDb $shipments): array
    {
        $tracksPerShipment = $this->tracks($shipments);

        $documents = [];
        foreach ($shipments as $shipment) {
            $document = $shipment->getData();
            $entityId = (int)($document['entity_id'] ?? 0);
            $tracks = $tracksPerShipment[$entityId] ?? [];
            $trackNumbers = array_column($tracks, 'track_number');
            $carriers = array_unique(array_column($tracks, 'title'));
            $adminUrl = $this->secureAdminUrl->getUrl('sales/shipment/view', ['shipment_id' => $entityId]);

            $documents[] = [
                'number' => $document['increment_id'] ?? '',
                'date' => $document['created_at'] ?? '',
                'order_id' => (int)($document['order_id'] ?? 0),
                'qty' => (float)($document['total_qty'] ?? 0),
                'tracking' => $trackNumbers,
                'carrier' => implode(', ', $carriers),
                'admin_url' => $adminUrl,
            ];
        }

        return $documents;
    }

    private function tracks(AbstractDb $shipments): array
    {
        $shipmentIds = $shipments->getColumnValues('entity_id');
        if ($shipmentIds === []) {
            return [];
        }

        $tracks = $this->trackCollectionFactory->create();
        $tracks->addFieldToFilter('parent_id', ['in' => $shipmentIds]);

        $tracksPerShipment = [];
        foreach ($tracks as $track) {
            $tracksPerShipment[(int)$track->getParentId()][] = $track->getData();
        }

        return $tracksPerShipment;
    }
}
