<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Recurring;

use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * The draft sent to POST /agreements. Two URLs are required on purpose and
 * are not interchangeable: merchantAgreementUrl is the page where the user
 * can manage or cancel the agreement (the Vipps terms require it to exist),
 * merchantRedirectUrl is merely where the user lands after the confirmation
 * page — landing there proves nothing about approval (see RecurringApi).
 *
 * skipLandingPage requires a special permission from Vipps and is only sent
 * when true. scope (space-separated userinfo scopes, e.g. "name email")
 * makes the approval double as a profile-sharing consent.
 */
final readonly class NewAgreement
{
    public function __construct(
        public Pricing $pricing,
        public Interval $interval,
        public string $productName,
        public string $merchantRedirectUrl,
        public string $merchantAgreementUrl,
        public ?string $productDescription = null,
        public ?string $phoneNumber = null,
        public ?InitialCharge $initialCharge = null,
        public ?string $scope = null,
        public bool $skipLandingPage = false,
        public ?string $externalId = null,
    ) {
        // Vipps wants an MSISDN: country code included, no plus sign. It only
        // prefills the landing page, so a wrong number is confusing rather
        // than fatal — reject the classic +47… mistake before it ships.
        if ($phoneNumber !== null && preg_match('/^\d{8,15}$/', $phoneNumber) !== 1) {
            throw new VippsConfigException('phoneNumber must be an MSISDN without the plus sign, e.g. "4791234567".');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'pricing' => $this->pricing->toPayload(),
            'interval' => $this->interval->toPayload(),
            'merchantRedirectUrl' => $this->merchantRedirectUrl,
            'merchantAgreementUrl' => $this->merchantAgreementUrl,
            'productName' => $this->productName,
        ];

        if ($this->productDescription !== null) {
            $payload['productDescription'] = $this->productDescription;
        }
        if ($this->phoneNumber !== null) {
            $payload['phoneNumber'] = $this->phoneNumber;
        }
        if ($this->initialCharge !== null) {
            $payload['initialCharge'] = $this->initialCharge->toPayload();
        }
        if ($this->scope !== null) {
            $payload['scope'] = $this->scope;
        }
        if ($this->skipLandingPage) {
            $payload['skipLandingPage'] = true;
        }
        if ($this->externalId !== null) {
            $payload['externalId'] = $this->externalId;
        }

        return $payload;
    }
}
