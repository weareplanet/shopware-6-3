<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Api\Configuration\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Framework\Context,
	Framework\Routing\Annotation\RouteScope,};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\{
	HttpFoundation\JsonResponse,
	HttpFoundation\Request,
	HttpFoundation\Response,
	Routing\Annotation\Route};
use WeArePlanetPayment\Core\{
	Api\OrderDeliveryState\Service\OrderDeliveryStateService,
	Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService,
	Api\WebHooks\Service\WebHooksService,
	Util\PaymentMethodUtil};

/**
 * Class ConfigurationController
 *
 * This class handles web calls that are made via the WeArePlanetPayment settings page.
 *
 * @package WeArePlanetPayment\Core\Api\Config\Controller
 * @RouteScope(scopes={"api"})
 */
class ConfigurationController extends AbstractController {

	/**
	 * @var \WeArePlanetPayment\Core\Api\WebHooks\Service\WebHooksService
	 */
	protected $webHooksService;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var \WeArePlanetPayment\Core\Util\PaymentMethodUtil
	 */
	private $paymentMethodUtil;

	/**
	 * @var \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	private $paymentMethodConfigurationService;

	/**
	 * ConfigurationController constructor.
	 *
	 * @param \WeArePlanetPayment\Core\Util\PaymentMethodUtil                                                   $paymentMethodUtil
	 * @param \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService $paymentMethodConfigurationService
	 * @param \WeArePlanetPayment\Core\Api\WebHooks\Service\WebHooksService                                     $webHooksService
	 */
	public function __construct(
		PaymentMethodUtil $paymentMethodUtil,
		PaymentMethodConfigurationService $paymentMethodConfigurationService,
		WebHooksService $webHooksService
	)
	{
		$this->webHooksService   = $webHooksService;
		$this->paymentMethodUtil = $paymentMethodUtil;

		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Set WeArePlanetPayment as the default payment for a give sales channel
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Shopware\Core\Framework\Context          $context
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 *
	 * @Route(
	 *     "/api/v{version}/_action/weareplanet/configuration/set-weareplanet-as-sales-channel-payment-default",
	 *     name="api.action.weareplanet.configuration.set-weareplanet-as-sales-channel-payment-default",
	 *     methods={"POST"}
	 *     )
	 */
	public function setWeArePlanetAsSalesChannelPaymentDefault(Request $request, Context $context): JsonResponse
	{
		$salesChannelId = $request->request->get('salesChannelId');
		$salesChannelId = ($salesChannelId == 'null') ? null : $salesChannelId;

		$this->paymentMethodUtil->setWeArePlanetAsDefaultPaymentMethod($context, $salesChannelId);
		return new JsonResponse([]);
	}

	/**
	 * Register web hooks
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 *
	 * @Route(
	 *     "/api/v{version}/_action/weareplanet/configuration/register-web-hooks",
	 *     name="api.action.weareplanet.configuration.register-web-hooks",
	 *     methods={"POST"}
	 *   )
	 */
	public function registerWebHooks(Request $request): JsonResponse
	{
		$salesChannelId = $request->request->get('salesChannelId');
		$salesChannelId = ($salesChannelId == 'null') ? null : $salesChannelId;

		$result = $this->webHooksService->setSalesChannelId($salesChannelId)->install();

		return new JsonResponse(['result' => $result]);
	}

	/**
	 * Synchronize payment method configurations
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Shopware\Core\Framework\Context          $context
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 *
	 * @Route(
	 *     "/api/v{version}/_action/weareplanet/configuration/synchronize-payment-method-configuration",
	 *     name="api.action.weareplanet.configuration.synchronize-payment-method-configuration",
	 *     methods={"POST"}
	 *   )
	 */
	public function synchronizePaymentMethodConfiguration(Request $request, Context $context): JsonResponse
	{
		$salesChannelId = $request->request->get('salesChannelId');
		$salesChannelId = ($salesChannelId == 'null') ? null : $salesChannelId;
		$result         = [];
		$status         = Response::HTTP_OK;
		try {
			$result = $this->paymentMethodConfigurationService->setSalesChannelId($salesChannelId)->synchronize($context);
		} catch (\Exception $exception) {
			$status = Response::HTTP_NOT_ACCEPTABLE;
			$result = [
				'errorTitle' => $exception->getMessage(),
				'errorMessage' => $exception->getTraceAsString()
			];
			$this->logger->emergency($exception->getTraceAsString());
		}

		return new JsonResponse(['result' => $result], $status);
	}

	/**
	 * Install OrderDeliveryStates
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 *
	 * @Route(
	 *     "/api/v{version}/_action/weareplanet/configuration/install-order-delivery-states",
	 *     name="api.action.weareplanet.configuration.install-order-delivery-states",
	 *     methods={"POST"}
	 *   )
	 */
	public function installOrderDeliveryStates(Context $context): JsonResponse
	{
		/**
		 * @var \WeArePlanetPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService $orderDeliveryStateService
		 */
		$orderDeliveryStateService = $this->container->get(OrderDeliveryStateService::class);
		$orderDeliveryStateService->install($context);

		return new JsonResponse([]);
	}
}