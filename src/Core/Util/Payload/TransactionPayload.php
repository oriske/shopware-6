<?php declare(strict_types=1);

namespace WalleePayment\Core\Util\Payload;


use Psr\Container\ContainerInterface;
use Shopware\Core\{
	Checkout\Cart\Tax\Struct\CalculatedTaxCollection,
	Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity,
	Checkout\Customer\CustomerEntity,
	Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity,
	Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
	Framework\DataAbstractionLayer\Search\Criteria,
	System\SalesChannel\SalesChannelContext};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Wallee\Sdk\{
	Model\AddressCreate,
	Model\LineItemAttributeCreate,
	Model\LineItemCreate,
	Model\LineItemType,
	Model\TaxCreate,
	Model\TransactionCreate};
use WalleePayment\Core\{
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity,
	Settings\Struct\Settings,
	Util\Exception\InvalidPayloadException,
	Util\LocaleCodeProvider};

/**
 * Class TransactionPayload
 *
 * @package WalleePayment\Core\Util\Payload
 */
class TransactionPayload extends AbstractPayload {

	public const ORDER_TRANSACTION_CUSTOM_FIELDS_WALLEE_SPACE_ID       = 'wallee_space_id';
	public const ORDER_TRANSACTION_CUSTOM_FIELDS_WALLEE_TRANSACTION_ID = 'wallee_transaction_id';

	public const WALLEE_METADATA_SALES_CHANNEL_ID     = 'salesChannelId';
	public const WALLEE_METADATA_ORDER_ID             = 'orderId';
	public const WALLEE_METADATA_ORDER_TRANSACTION_ID = 'orderTransactionId';


	/**
	 * @var \Shopware\Core\System\SalesChannel\SalesChannelContext
	 */
	protected $salesChannelContext;

	/**
	 * @var \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct
	 */
	protected $transaction;

	/**
	 * @var \WalleePayment\Core\Settings\Struct\Settings
	 */
	protected $settings;

	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \WalleePayment\Core\Util\LocaleCodeProvider
	 */
	private $localeCodeProvider;

	/**
	 * @var TranslatorInterface
	 */
	protected $translator;

	/**
	 * TransactionPayload constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                  $container
	 * @param \WalleePayment\Core\Util\LocaleCodeProvider         $localeCodeProvider
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext             $salesChannelContext
	 * @param \WalleePayment\Core\Settings\Struct\Settings        $settings
	 * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
	 */
	public function __construct(
		ContainerInterface $container,
		LocaleCodeProvider $localeCodeProvider,
		SalesChannelContext $salesChannelContext,
		Settings $settings,
		AsyncPaymentTransactionStruct $transaction
	)
	{
		$this->localeCodeProvider  = $localeCodeProvider;
		$this->salesChannelContext = $salesChannelContext;
		$this->settings            = $settings;
		$this->transaction         = $transaction;
		$this->container           = $container;
		$this->translator          = $this->container->get('translator');
	}

	/**
	 * Get Transaction Payload
	 *
	 * @return \Wallee\Sdk\Model\TransactionCreate
	 * @throws \Exception
	 */
	public function get(): TransactionCreate
	{
		$customer = $this->salesChannelContext->getCustomer();

		$lineItems       = $this->getLineItems();
		$billingAddress  = $this->getAddressPayload($customer, $customer->getActiveBillingAddress());
		$shippingAddress = $this->getAddressPayload($customer, $customer->getActiveShippingAddress());

		$transactionData = [
			'currency'               => $this->salesChannelContext->getCurrency()->getIsoCode(),
			'customer_email_address' => $billingAddress->getEmailAddress(),
			'customer_id'            => $customer->getCustomerNumber() ?? null,
			'language'               => $this->localeCodeProvider->getLocaleCodeFromContext($this->salesChannelContext->getContext()) ?? null,
			'merchant_reference'     => $this->fixLength($this->transaction->getOrder()->getOrderNumber(), 100),
			'meta_data'              => [
				self::WALLEE_METADATA_ORDER_ID             => $this->transaction->getOrder()->getId(),
				self::WALLEE_METADATA_ORDER_TRANSACTION_ID => $this->transaction->getOrderTransaction()->getId(),
				self::WALLEE_METADATA_SALES_CHANNEL_ID     => $this->salesChannelContext->getSalesChannel()->getId(),
			],
			'shipping_method'        => $this->salesChannelContext->getShippingMethod()->getName() ? $this->fixLength($this->salesChannelContext->getShippingMethod()->getName(), 200) : null,
			'space_view_id'          => $this->settings->getSpaceViewId() ?? null,
		];

		$transactionPayload = (new TransactionCreate())
			->setAutoConfirmationEnabled(false)
			->setBillingAddress($billingAddress)
			->setChargeRetryEnabled(false)
			->setCurrency($transactionData['currency'])
			->setCustomerEmailAddress($transactionData['customer_email_address'])
			->setCustomerId($transactionData['customer_id'])
			->setLanguage($transactionData['language'])
			->setLineItems($lineItems)
			->setMerchantReference($transactionData['merchant_reference'])
			->setMetaData($transactionData['meta_data'])
			->setShippingAddress($shippingAddress)
			->setShippingMethod($transactionData['shipping_method'])
			->setSpaceViewId($transactionData['space_view_id']);

		$paymentConfiguration = $this->getPaymentConfiguration($this->salesChannelContext->getPaymentMethod()->getId());

		$transactionPayload->setAllowedPaymentMethodConfigurations([$paymentConfiguration->getPaymentMethodConfigurationId()]);

		$successUrl = $this->transaction->getReturnUrl() . '&status=paid';
		$failedUrl  = $this->getFailUrl($this->transaction->getOrder()->getId()) . '&status=fail';
		$transactionPayload->setSuccessUrl($successUrl)
						   ->setFailedUrl($failedUrl);

		if (!$transactionPayload->valid()) {
			$this->logger->critical('Transaction payload invalid:', $transactionPayload->listInvalidProperties());
			throw new InvalidPayloadException('Transaction payload invalid:' . json_encode($transactionPayload->listInvalidProperties()));
		}

		return $transactionPayload;
	}

	/**
	 * Get transaction line items
	 *
	 * @return \Wallee\Sdk\Model\LineItemCreate[]
	 * @throws \Exception
	 */
	protected function getLineItems(): array
	{
		/**
		 * @var \Wallee\Sdk\Model\LineItemCreate[] $lineItems
		 */
		$lineItems = [];
		/**
		 * @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
		 */
		foreach ($this->transaction->getOrder()->getLineItems() as $shopLineItem) {

			$taxes = $this->getTaxes(
				$shopLineItem->getPrice()->getCalculatedTaxes(),
				$this->translator->trans('wallee.payload.taxes')
			);

			$uniqueId = $shopLineItem->getId();
			$sku      = $shopLineItem->getProductId() ? $shopLineItem->getProductId() : $uniqueId;
			$payLoad  = $shopLineItem->getPayload();
			if (!empty($payLoad) && !empty($payLoad['productNumber'])) {
				$sku = $payLoad['productNumber'];
			}
			$sku    = $this->fixLength($sku, 200);
			$amount = $shopLineItem->getTotalPrice() ? self::round($shopLineItem->getTotalPrice()) : 0;

			$lineItem = (new LineItemCreate())
				->setName($this->fixLength($shopLineItem->getLabel(), 150))
				->setUniqueId($uniqueId)
				->setSku($sku)
				->setQuantity($shopLineItem->getQuantity() ?? 1)
				->setAmountIncludingTax($amount)
				->setTaxes($taxes);

			$productAttributes = $this->getProductAttributes($shopLineItem);

			if (!empty($productAttributes)) {
				$lineItem->setAttributes($productAttributes);
			}

			if ($shopLineItem->getTotalPrice() >= 0) {
				$lineItem->setType(LineItemType::PRODUCT);
			} else {
				$lineItem->setType(LineItemType::DISCOUNT);
			}

			if (!$lineItem->valid()) {
				$this->logger->critical('LineItem payload invalid:', $lineItem->listInvalidProperties());
				throw new InvalidPayloadException('LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
			}

			$lineItems[] = $lineItem;
		}

		$shippingLineItem = $this->getShippingLineItem();
		if (!is_null($shippingLineItem)) {
			$lineItems[] = $shippingLineItem;
		}

		$adjustmentLineItem = $this->getAdjustmentLineItem($lineItems);
		if (!is_null($adjustmentLineItem)) {
			$lineItems[] = $adjustmentLineItem;
		}

		return $lineItems;

	}

	/**
	 * @param \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection $calculatedTaxes
	 * @param string                                                          $title
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function getTaxes(CalculatedTaxCollection $calculatedTaxes, string $title): array
	{
		$taxes = [];
		foreach ($calculatedTaxes as $calculatedTax) {

			$tax = (new TaxCreate())
				->setRate($calculatedTax->getTaxRate())
				->setTitle($this->fixLength($title . ' : ' . $calculatedTax->getTaxRate(), 40));

			if (!$tax->valid()) {
				$this->logger->critical('Tax payload invalid:', $tax->listInvalidProperties());
				throw new InvalidPayloadException('Tax payload invalid:' . json_encode($tax->listInvalidProperties()));
			}

			$taxes [] = $tax;
		}

		return $taxes;
	}

	/**
	 * @param \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity $shopLineItem
	 *
	 * @return array|null
	 */
	protected function getProductAttributes(OrderLineItemEntity $shopLineItem): ?array
	{
		$productAttributes = [];
		$lineItemPayload   = $shopLineItem->getPayload();

		if (is_array($lineItemPayload) && !empty($lineItemPayload['options'])) {
			foreach ($lineItemPayload['options'] as $option) {

				$label                   = $option['group'];
				$lineItemAttributeCreate = (new LineItemAttributeCreate())
					->setLabel($this->fixLength($label, 512))
					->setValue($this->fixLength($option['option'], 512));

				if ($lineItemAttributeCreate->valid()) {
					$key                     = $this->fixLength('option_' . md5($label), 40);
					$productAttributes[$key] = $lineItemAttributeCreate;
				} else {
					$this->logger->critical('LineItemAttributeCreate payload invalid:', $lineItemAttributeCreate->listInvalidProperties());
					throw new InvalidPayloadException('LineItemAttributeCreate payload invalid:' . json_encode($lineItemAttributeCreate->listInvalidProperties()));
				}
			}
		}

		return empty($productAttributes) ? null : $productAttributes;
	}

	/**
	 * @return \Wallee\Sdk\Model\LineItemCreate|null
	 */
	protected function getShippingLineItem(): ?LineItemCreate
	{
		try {

			$amount = $this->transaction->getOrder()->getShippingTotal();
			$amount = self::round($amount);

			if ($amount > 0) {

				$shippingName = $this->salesChannelContext->getShippingMethod()->getName() ?? $this->translator->trans('wallee.payload.shipping.name');
				$taxes        = $this->getTaxes(
					$this->transaction->getOrder()->getShippingCosts()->getCalculatedTaxes(),
					$shippingName
				);

				$lineItem = (new LineItemCreate())
					->setAmountIncludingTax($amount)
					->setName($this->fixLength($shippingName . ' ' . $this->translator->trans('wallee.payload.shipping.lineItem'), 150))
					->setQuantity($this->transaction->getOrder()->getShippingCosts()->getQuantity() ?? 1)
					->setTaxes($taxes)
					->setSku($this->fixLength($shippingName . '-Shipping-Line-Item', 200))
					/** @noinspection PhpParamsInspection */
					->setType(LineItemType::SHIPPING)
					->setUniqueId($this->fixLength($shippingName . '-Shipping-Line-Item', 200));

				if (!$lineItem->valid()) {
					$this->logger->critical('Shipping LineItem payload invalid:', $lineItem->listInvalidProperties());
					throw new InvalidPayloadException('Shipping LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
				}

				return $lineItem;
			}

		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
		}
		return null;
	}

	/**
	 * Get Adjustment Line Item
	 *
	 * @param \Wallee\Sdk\Model\LineItemCreate[] $lineItems
	 *
	 * @return \Wallee\Sdk\Model\LineItemCreate|null
	 * @throws \Exception
	 */
	protected function getAdjustmentLineItem(array &$lineItems): ?LineItemCreate
	{
		$lineItem = null;

		$lineItemPriceTotal = array_sum(array_map(static function (LineItemCreate $lineItem) {
			return $lineItem->getAmountIncludingTax();
		}, $lineItems));

		$adjustmentPrice = $this->transaction->getOrder()->getAmountTotal() - $lineItemPriceTotal;
		$adjustmentPrice = self::round($adjustmentPrice);

		if (abs($adjustmentPrice) != 0) {
			if ($this->settings->isLineItemConsistencyEnabled()) {
				$error = strtr('LineItems total :lineItemTotal does not add up to order total :orderTotal', [
					':lineItemTotal' => $lineItemPriceTotal,
					':orderTotal'    => $this->transaction->getOrder()->getAmountTotal(),
				]);
				$this->logger->critical($error);
				throw new \Exception($error);

			} else {
				$lineItem = (new LineItemCreate())
					->setName($this->translator->trans('wallee.payload.adjustmentLineItem'))
					->setUniqueId('Adjustment-Line-Item')
					->setSku('Adjustment-Line-Item')
					->setQuantity(1);
				/** @noinspection PhpParamsInspection */
				$lineItem->setAmountIncludingTax($adjustmentPrice)
						 ->setType(($adjustmentPrice > 0) ? LineItemType::FEE : LineItemType::DISCOUNT);

				if (!$lineItem->valid()) {
					$this->logger->critical('Adjustment LineItem payload invalid:', $lineItem->listInvalidProperties());
					throw new InvalidPayloadException('Adjustment LineItem payload invalid:' . json_encode($lineItem->listInvalidProperties()));
				}
			}
		}

		return $lineItem;
	}

	/**
	 * Get address payload
	 *
	 * @param \Shopware\Core\Checkout\Customer\CustomerEntity                                  $customer
	 * @param \Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $customerAddressEntity
	 *
	 * @return \Wallee\Sdk\Model\AddressCreate
	 * @throws \Exception
	 */
	protected function getAddressPayload(CustomerEntity $customer, CustomerAddressEntity $customerAddressEntity): AddressCreate
	{
		// Family name
		$family_name = null;
		if (!empty($customerAddressEntity->getLastName())) {
			$family_name = $customerAddressEntity->getLastName();
		} else {
			if (!empty($customer->getLastName())) {
				$family_name = $customer->getLastName();
			}
		}
		$family_name = !empty($family_name) ? $this->fixLength($family_name, 100) : null;

		// Given name
		$given_name = null;
		if (!empty($customerAddressEntity->getFirstName())) {
			$given_name = $customerAddressEntity->getFirstName();
		} else {
			if (!empty($customer->getFirstName())) {
				$given_name = $customer->getFirstName();
			}
		}
		$given_name = !empty($given_name) ? $this->fixLength($given_name, 100) : null;

		// Organization name
		$organization_name = null;
		if (!empty($customerAddressEntity->getCompany())) {
			$organization_name = $customerAddressEntity->getCompany();
		} else {
			if (!empty($customer->getCompany())) {
				$organization_name = $customer->getCompany();
			}
		}
		$organization_name = !empty($organization_name) ? $this->fixLength($organization_name, 100) : null;

		// Salutation
		$salutation = null;
		if (!(
			empty($customerAddressEntity->getSalutation()) ||
			empty($customerAddressEntity->getSalutation()->getDisplayName())
		)) {
			$salutation = $customerAddressEntity->getSalutation()->getDisplayName();
		} else {
			if (!empty($customer->getSalutation())) {
				$salutation = $customer->getSalutation()->getDisplayName();

			}
		}
		$salutation = !empty($salutation) ? $this->fixLength($salutation, 20) : null;

		$addressData = [
			'city'              => $customerAddressEntity->getCity() ? $this->fixLength($customerAddressEntity->getCity(), 100) : null,
			'country'           => $customerAddressEntity->getCountry() ? $customerAddressEntity->getCountry()->getIso() : null,
			'email_address'     => $customer->getEmail() ? $this->fixLength($customer->getEmail(), 254) : null,
			'family_name'       => $family_name,
			'given_name'        => $given_name,
			'organization_name' => $organization_name,
			'phone_number'      => $customerAddressEntity->getPhoneNumber() ? $this->fixLength($customerAddressEntity->getPhoneNumber(), 100) : null,
			'postcode'          => $customerAddressEntity->getZipcode() ? $this->fixLength($customerAddressEntity->getZipcode(), 40) : null,
			'postal_state'      => $customerAddressEntity->getCountryState() ? $customerAddressEntity->getCountryState()->getShortCode() : null,
			'salutation'        => $salutation,
			'street'            => $customerAddressEntity->getStreet() ? $this->fixLength($customerAddressEntity->getStreet(), 300) : null,
		];

		$addressPayload = (new AddressCreate())
			->setCity($addressData['city'])
			->setCountry($addressData['country'])
			->setEmailAddress($addressData['email_address'])
			->setFamilyName($addressData['family_name'])
			->setGivenName($addressData['given_name'])
			->setOrganizationName($addressData['organization_name'])
			->setPhoneNumber($addressData['phone_number'])
			->setPostCode($addressData['postcode'])
			->setPostalState($addressData['postal_state'])
			->setSalutation($addressData['salutation'])
			->setStreet($addressData['street']);

		if (!$addressPayload->valid()) {
			$this->logger->critical('Address payload invalid:', $addressPayload->listInvalidProperties());
			throw new InvalidPayloadException('Address payload invalid:' . json_encode($addressPayload->listInvalidProperties()));
		}

		return $addressPayload;
	}

	/**
	 * @param string $id
	 *
	 * @return \WalleePayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity
	 */
	protected function getPaymentConfiguration(string $id): PaymentMethodConfigurationEntity
	{
		$criteria = (new Criteria([$id]));

		return $this->container->get('wallee_payment_method_configuration.repository')
							   ->search($criteria, $this->salesChannelContext->getContext())
							   ->getEntities()->first();
	}

	/**
	 * Get failure URL
	 *
	 * @param string $orderId
	 *
	 * @return string
	 */
	protected function getFailUrl(string $orderId): string
	{
		return $this->container->get('router')->generate(
			'frontend.wallee.checkout.recreate-cart',
			['orderId' => $orderId,],
			UrlGeneratorInterface::ABSOLUTE_URL
		);
	}
}