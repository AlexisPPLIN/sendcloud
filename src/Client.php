<?php

namespace Villermen\SendCloud;

use Villermen\SendCloud\Exception\SendCloudClientException;
use Villermen\SendCloud\Exception\SendCloudRequestException;
use Villermen\SendCloud\Exception\SendCloudStateException;

/**
 * Client to perform calls on the SendCloud API.
 */
class Client
{
    const API_BASE_URL = 'https://panel.sendcloud.sc/api/v2/';

    /** @var \GuzzleHttp\Client */
    protected $guzzleClient;

    /** @var string */
    protected $publicKey;

    /** @var string */
    protected $secretKey;

    /** @var string|null */
    protected $partnerId;

    public function __construct(
        string $publicKey,
        string $secretKey,
        ?string $partnerId = null,
        ?string $apiBaseUrl = null
    ) {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->partnerId = $partnerId;

        $clientConfig = [
            'base_uri' => $apiBaseUrl ?: self::API_BASE_URL,
            'timeout' => 15,
            'auth' => [
                $publicKey,
                $secretKey,
            ],
        ];

        if ($this->partnerId) {
            $clientConfig['headers'] = [
                'Sendcloud-Partner-Id' => $this->partnerId
            ];
        }

        $this->guzzleClient = new \GuzzleHttp\Client($clientConfig);
    }

    /**
     * Fetches basic details about the SendCloud account.
     *
     * @return User
     * @throws SendCloudClientException
     */
    public function getUser(): User
    {
        try {
            return new User(json_decode($this->guzzleClient->get('user')->getBody())->user);
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            throw new SendCloudRequestException(
                'An error occurred while fetching the SendCloud user.',
                0,
                $exception
            );
        }
    }

    /**
     * Fetches available SendCloud shipping methods.
     *
     * @return ShippingMethod[]
     * @throws SendCloudClientException
     */
    public function getShippingMethods(): array
    {
        try {
            $shippingMethodsData = json_decode($this->guzzleClient->get('shipping_methods')->getBody())
                ->shipping_methods;

            $shippingMethods = array_map(function (\stdClass $shippingMethodData) {
                return new ShippingMethod($shippingMethodData);
            }, $shippingMethodsData);

            // Sort by carrier and name
            usort($shippingMethods, function (ShippingMethod $method1, ShippingMethod $method2) {
                if ($method1->getCarrier() !== $method2->getCarrier()) {
                    return strcasecmp($method1->getCarrier(), $method2->getCarrier());
                }

                return strcasecmp($method1->getName(), $method2->getName());
            });

            return $shippingMethods;
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            throw new SendCloudRequestException(
                'An error occurred while fetching shipping methods from the SendCloud API.',
                0,
                $exception
            );
        }
    }

    /**
     * Creates a parcel with SendCloud and registers it to the order.
     *
     * @param Address $shippingAddress Address to be shipped to.
     * @param null|string $orderNumber
     * @param int|ShippingMethod|null $shippingMethod
     *     Shipping method or shipping method id.
     *     The default set in SendCloud will be used if null.
     * @param int|null $weight Weight of the parcel in grams. The default set in SendCloud will be used if null or zero.
     * @param bool $requestLabel Whether to create a label with the parcel or just add it in SendCloud.
     * @param int|SenderAddress|Address|null $senderAddress
     *     Address or address id of the sender.
     *     The default set in SendCloud will be used if null.
     *     If `$requestLabel` is false, this will be discarded.
     * @return Parcel
     * @throws SendCloudRequestException
     */
    public function createParcel(
        Address $shippingAddress,
        ?string $orderNumber = null,
        $shippingMethod = null,
        ?int $weight = null,
        bool $requestLabel = true,
        $senderAddress = null
    ): Parcel {
        $parcelData = [
            'name' => $shippingAddress->getName() ?? '',
            'company_name' => $shippingAddress->getCompanyName() ?? '',
            'address' => $shippingAddress->getStreet() ?? '',
            'house_number' => $shippingAddress->getHouseNumber() ?? '',
            'city' => $shippingAddress->getCity() ?? '',
            'postal_code' => $shippingAddress->getPostalCode() ?? '',
            'country' => $shippingAddress->getCountryCode() ?? '',
            'email' => $shippingAddress->getEmailAddress() ?? '',
            'telephone' => $shippingAddress->getPhoneNumber() ?? '',

            'order_number' => $orderNumber ?? '',

            'request_label' => $requestLabel,
        ];

        if ($weight) {
            $parcelData['weight'] = ceil($weight / 10) / 100;
        }

        // Shipping method
        if ($shippingMethod instanceof ShippingMethod) {
            /** @var ShippingMethod $shippingMethod */
            $shippingMethod = $shippingMethod->getId();
        }
        if (is_int($shippingMethod)) {
            $parcelData['shipment'] = [
                'id' => $shippingMethod
            ];
        } elseif ($shippingMethod !== null) {
            throw new \InvalidArgumentException('shippingMethod must be an integer, ShippingMethod or null.');
        }

        // Sender address
        if ($senderAddress instanceof SenderAddress) {
            /** @var SenderAddress $senderAddress */
            $senderAddress = $senderAddress->getId();
        }
        if (is_int($senderAddress)) {
            /** @var int $senderAddress */
            $parcelData['sender_address'] = $senderAddress;
        } elseif ($senderAddress instanceof Address) {
            /** @var Address $senderAddress */
            $parcelData = array_merge($parcelData, [
                'from_name' => $senderAddress->getName() ?? '',
                'from_company_name' => $senderAddress->getCompanyName() ?? '',
                'from_address_1' => $senderAddress->getStreet() ?? '',
                'from_address_2' => '',
                'from_house_number' => $senderAddress->getHouseNumber() ?? '',
                'from_city' => $senderAddress->getCity() ?? '',
                'from_postal_code' => $senderAddress->getPostalCode() ?? '',
                'from_country' => $senderAddress->getCountryCode() ?? '',
                'from_telephone' => $senderAddress->getPhoneNumber() ?? '',
                'from_email' => $senderAddress->getEmailAddress() ?? '',
            ]);
        } elseif ($senderAddress !== null) {
            throw new \InvalidArgumentException('senderAddress must be an integer, SenderAddress, Address or null.');
        }

        // Perform the creation
        try {
            $response = $this->guzzleClient->post('parcels', [
                'json' => [
                    'parcel' => $parcelData
                ]
            ]);

            return new Parcel(json_decode($response->getBody())->parcel);
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            // Precondition failed
            if ($exception->getCode() === 412) {
                throw new SendCloudRequestException(
                    sprintf(
                        'SendCloud account is not fully configured yet. (%s).',
                        json_decode($exception->getResponse()->getBody())->error->message
                    ),
                    0,
                    $exception
                );
            }

            throw new SendCloudRequestException('Could not create parcel with SendCloud.', 0, $exception);
        }
    }

    /**
     * Fetches the PDF label for the given parcel;
     * The parcel must already have a label created.
     *
     * @param Parcel|int $parcel
     * @param int $format `Parcel::LABEL_FORMATS`
     * @return string PDF data.
     * @throws SendCloudClientException
     */
    public function getLabel($parcel, int $format): string
    {
        if (!in_array($format, Parcel::LABEL_FORMATS)) {
            throw new \InvalidArgumentException('Invalid label format given.');
        }

        if (is_int($parcel)) {
            /** @var int $parcel */
            $parcel = $this->getParcel($parcel);
        } elseif (!($parcel instanceof Parcel)) {
            throw new \InvalidArgumentException('parcel must be an integer or Parcel.');
        }
        /** @var Parcel $parcel */

        $labelUrl = $parcel->getLabelUrl($format);

        if (!$labelUrl) {
            throw new SendCloudStateException('SendCloud parcel does not have any labels.');
        }

        try {
            return (string)($this->guzzleClient->get($labelUrl)->getBody());
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            throw new SendCloudRequestException('Could not retrieve label.', 0, $exception);
        }
    }

    /**
     * Fetches the sender addresses configured in SendCloud.
     *
     * @return SenderAddress[]
     * @throws SendCloudClientException
     * @deprecated Endpoint does not seem to work (404).
     */
    public function getSenderAddresses(): array
    {
        try {
            $senderAddressesData = json_decode($this->guzzleClient->get('addresses/sender')->getBody())
                ->sender_addresses;

            return array_map(function (\stdClass $senderAddressData) {
                return new SenderAddress($senderAddressData);
            }, $senderAddressesData);
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            throw new SendCloudRequestException('Could not retrieve sender addresses.', 0, $exception);
        }
    }

    /**
     * @param int $parcelId
     * @return Parcel
     * @throws SendCloudClientException
     */
    public function getParcel(int $parcelId): Parcel
    {
        try {
            $response = $this->guzzleClient->get('parcels/' . $parcelId);
            return new Parcel(json_decode($response->getBody())->parcel);
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            throw new SendCloudRequestException('Could not retrieve parcel.', 0, $exception);
        }
    }
}
