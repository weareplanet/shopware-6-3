<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Api\Transaction\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Exception\OrderNotFoundException,
	Checkout\Order\OrderEntity,
	Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	System\SalesChannel\SalesChannelContext
};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WeArePlanet\Sdk\{
	Model\Transaction,
	Model\TransactionPending
};
use WeArePlanetPayment\Core\{
	Api\OrderDeliveryState\Handler\OrderDeliveryStateHandler,
	Api\Refund\Entity\RefundEntityCollection,
	Api\Refund\Entity\RefundEntityDefinition,
	Api\Transaction\Entity\TransactionEntity,
	Api\Transaction\Entity\TransactionEntityDefinition,
	Settings\Options\Integration,
	Settings\Service\SettingsService,
	Util\LocaleCodeProvider,
	Util\Payload\TransactionPayload
};

/**
 * Class TransactionService
 *
 * @package WeArePlanetPayment\Core\Api\Transaction\Service
 */
class TransactionService {
	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \WeArePlanetPayment\Core\Util\LocaleCodeProvider
	 */
	private $localeCodeProvider;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * @var \WeArePlanetPayment\Core\Settings\Service\SettingsService
	 */
	private $settingsService;

	/**
	 * TransactionService constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                   $container
	 * @param \WeArePlanetPayment\Core\Util\LocaleCodeProvider          $localeCodeProvider
	 * @param \WeArePlanetPayment\Core\Settings\Service\SettingsService $settingsService
	 */
	public function __construct(
		ContainerInterface $container,
		LocaleCodeProvider $localeCodeProvider,
		SettingsService $settingsService
	)
	{
		$this->container          = $container;
		$this->localeCodeProvider = $localeCodeProvider;
		$this->settingsService    = $settingsService;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 *
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * The pay function will be called after the customer completed the order.
	 * Allows to process the order and store additional information.
	 *
	 * A redirect to the url will be performed
	 *
	 * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext             $salesChannelContext
	 *
	 * @return string
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	public function create(
		AsyncPaymentTransactionStruct $transaction,
		SalesChannelContext $salesChannelContext
	): string
	{
		$settings  = $this->settingsService->getSettings($salesChannelContext->getSalesChannel()->getId());
		$apiClient = $settings->getApiClient();

		$transactionPayloadClass = (new TransactionPayload(
			$this->container,
			$this->localeCodeProvider,
			$salesChannelContext,
			$settings,
			$transaction
		));
		$transactionPayloadClass->setLogger($this->logger);
		$transactionPayload = $transactionPayloadClass->get();

		$createdTransaction = $apiClient->getTransactionService()->create($settings->getSpaceId(), $transactionPayload);

		$pendingTransaction = new TransactionPending();
		$pendingTransaction->setId($createdTransaction->getId());
		$pendingTransaction->setVersion($createdTransaction->getVersion());

		$createdTransaction = $apiClient->getTransactionService()
										->confirm($settings->getSpaceId(), $pendingTransaction);

		$this->addWeArePlanetTransactionId(
			$transaction,
			$salesChannelContext->getContext(),
			$createdTransaction->getId(),
			$settings->getSpaceId()
		);

		$redirectUrl = $this->container->get('router')->generate(
			'frontend.weareplanet.checkout.pay',
			['orderId' => $transaction->getOrder()->getId(),],
			UrlGeneratorInterface::ABSOLUTE_URL
		);

		if ($settings->getIntegration() == Integration::PAYMENT_PAGE) {
			$redirectUrl = $apiClient->getTransactionPaymentPageService()
									 ->paymentPageUrl($settings->getSpaceId(), $createdTransaction->getId());
		}

		$this->upsert(
			$createdTransaction,
			$salesChannelContext->getContext(),
			$transaction->getOrderTransaction()->getPaymentMethodId(),
			$transaction->getOrder()->getSalesChannelId()
		);

		$this->holdDelivery($transaction->getOrder()->getId(), $salesChannelContext->getContext());

		return $redirectUrl;
	}

	/**
	 * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
	 * @param \Shopware\Core\Framework\Context                                   $context
	 * @param int                                                                $weareplanetTransactionId
	 * @param int                                                                $spaceId
	 */
	protected function addWeArePlanetTransactionId(
		AsyncPaymentTransactionStruct $transaction,
		Context $context,
		int $weareplanetTransactionId,
		int $spaceId
	): void
	{
		$data = [
			'id'           => $transaction->getOrderTransaction()->getId(),
			'customFields' => [
				TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_WEAREPLANET_TRANSACTION_ID => $weareplanetTransactionId,
				TransactionPayload::ORDER_TRANSACTION_CUSTOM_FIELDS_WEAREPLANET_SPACE_ID       => $spaceId,
			],
		];
		$this->container->get('order_transaction.repository')->update([$data], $context);
	}

	/**
	 * Persist WeArePlanet transaction
	 *
	 * @param \WeArePlanet\Sdk\Model\Transaction $transaction
	 * @param \Shopware\Core\Framework\Context             $context
	 * @param string|null                                  $paymentMethodId
	 * @param string|null                                  $salesChannelId
	 */
	public function upsert(
		Transaction $transaction,
		Context $context,
		string $paymentMethodId = null,
		string $salesChannelId = null
	): void
	{
		try {

			$transactionMetaData = $transaction->getMetaData();
			$orderId             = $transactionMetaData[TransactionPayload::WEAREPLANET_METADATA_ORDER_ID];
			$orderTransactionId  = $transactionMetaData[TransactionPayload::WEAREPLANET_METADATA_ORDER_TRANSACTION_ID];

			$data = [
				'id'                 => $orderId,
				'data'               => json_decode(strval($transaction), true),
				'paymentMethodId'    => $paymentMethodId,
				'orderId'            => $orderId,
				'orderTransactionId' => $orderTransactionId,
				'spaceId'            => $transaction->getLinkedSpaceId(),
				'state'              => $transaction->getState(),
				'salesChannelId'     => $salesChannelId,
				'transactionId'      => $transaction->getId(),
			];

			$data = array_filter($data);
			$this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')->upsert([$data], $context);

		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
		}
	}

	/**
	 * Hold delivery
	 *
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 */
	private function holdDelivery(string $orderId, Context $context)
	{
		try {
			/**
			 * @var OrderDeliveryStateHandler $orderDeliveryStateHandler
			 */
			$orderEntity               = $this->getOrderEntity($orderId, $context);
			$orderDeliveryStateHandler = $this->container->get(OrderDeliveryStateHandler::class);
			$orderDeliveryStateHandler->hold($orderEntity->getDeliveries()->last()->getId(), $context);
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getTraceAsString());
		}
	}

	/**
	 * Get order
	 *
	 * @param String                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \Shopware\Core\Checkout\Order\OrderEntity
	 */
	private function getOrderEntity(string $orderId, Context $context): OrderEntity
	{
		try {
			$criteria = (new Criteria([$orderId]))->addAssociations(['deliveries']);
			$order    = $this->container->get('order.repository')->search(
				$criteria,
				$context
			)->first();
			if (is_null($order)) {
				throw new OrderNotFoundException($orderId);
			}
			return $order;
		} catch (\Exception $e) {
			throw new OrderNotFoundException($orderId);
		}

	}

	/**
	 * Get transaction entity by orderId
	 *
	 * @param string                           $orderId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \WeArePlanetPayment\Core\Api\Transaction\Entity\TransactionEntity
	 */
	public function getByOrderId(string $orderId, Context $context): TransactionEntity
	{
		return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
							   ->search(new Criteria([$orderId]), $context)
							   ->get($orderId);
	}

	/**
	 * Read transaction from WeArePlanet API
	 *
	 * @param int    $transactionId
	 * @param string $salesChannelId
	 *
	 * @return \WeArePlanet\Sdk\Model\Transaction
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	public function read(int $transactionId, string $salesChannelId): Transaction
	{
		$settings = $this->settingsService->getSettings($salesChannelId);
		return $settings->getApiClient()->getTransactionService()->read($settings->getSpaceId(), $transactionId);
	}

	/**
	 * Get transaction entity by WeArePlanet transaction id
	 *
	 * @param int                              $transactionId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \WeArePlanetPayment\Core\Api\Transaction\Entity\TransactionEntity|null
	 */
	public function getByTransactionId(int $transactionId, Context $context): ?TransactionEntity
	{
		return $this->container->get(TransactionEntityDefinition::ENTITY_NAME . '.repository')
							   ->search(
								   (new Criteria())->addFilter(new EqualsFilter('transactionId', $transactionId))
												   ->addAssociations(['refunds']), $context
							   )
							   ->first();
	}

	/**
	 * Get transaction entity by WeArePlanet transaction id
	 *
	 * @param int                              $transactionId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \WeArePlanetPayment\Core\Api\Refund\Entity\RefundEntityCollection
	 */
	public function getRefundEntityCollectionByTransactionId(int $transactionId, Context $context): ?RefundEntityCollection
	{
		return $this->container->get(RefundEntityDefinition::ENTITY_NAME . '.repository')
							   ->search(
								   (new Criteria())->addFilter(new EqualsFilter('transactionId', $transactionId)), $context
							   )
							   ->getEntities();
	}

}