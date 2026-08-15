<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * Draft for POST /epayment/v1/payments.
 *
 * The reference is validated at construction because a merchant must get it
 * right BEFORE any request goes out: it becomes the payment's permanent
 * identity in every later call (get, capture, cancel, refund), and Vipps
 * rejects anything outside 8–64 chars of [a-zA-Z0-9-]. Better a
 * VippsConfigException in the checkout code path than a 400 in production
 * logs.
 */
final readonly class CreatePayment
{
    public function __construct(
        public Amount $amount,
        public string $reference,
        public string $returnUrl,
        public UserFlow $userFlow = UserFlow::WebRedirect,
        public ?string $customerPhoneNumber = null,
        public ?string $paymentDescription = null,
    ) {
        if (preg_match('/^[a-zA-Z0-9-]{8,64}$/', $reference) !== 1) {
            throw new VippsConfigException(
                "Payment reference must be 8-64 characters of [a-zA-Z0-9-], got \"{$reference}\".",
            );
        }

        // Same wire concept as Recurring's NewAgreement.phoneNumber, so the
        // same rule: an MSISDN with country code and no plus sign. It only
        // targets/prefills the Vipps app, so a wrong number is confusing
        // rather than fatal — reject the classic +47… mistake before it ships.
        if ($customerPhoneNumber !== null && preg_match('/^\d{8,15}$/', $customerPhoneNumber) !== 1) {
            throw new VippsConfigException('customerPhoneNumber must be an MSISDN without the plus sign, e.g. "4791234567".');
        }

        // PUSH_MESSAGE has no browser hop: the phone number is the only way
        // Vipps can reach the customer, and the API requires `customer` for
        // this flow. Rejecting the combination here beats a 400 in production.
        if ($userFlow === UserFlow::PushMessage && $customerPhoneNumber === null) {
            throw new VippsConfigException('customerPhoneNumber is required for UserFlow::PushMessage — the push has nowhere to go without it.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'amount' => AmountShape::toArray($this->amount),
            'paymentMethod' => ['type' => 'WALLET'],
            'reference' => $this->reference,
            'returnUrl' => $this->returnUrl,
            'userFlow' => $this->userFlow->value,
        ];

        if ($this->customerPhoneNumber !== null) {
            $payload['customer'] = ['phoneNumber' => $this->customerPhoneNumber];
        }

        if ($this->paymentDescription !== null) {
            $payload['paymentDescription'] = $this->paymentDescription;
        }

        return $payload;
    }
}
