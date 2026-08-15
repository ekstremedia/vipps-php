<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Epayment;

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;

/**
 * Draft for POST /epayment/v1/payments.
 *
 * The reference is validated at construction because it is the one field a
 * merchant must get right BEFORE any request goes out: it becomes the
 * payment's permanent identity in every later call (get, capture, cancel,
 * refund), and Vipps rejects anything outside 8–64 chars of [a-zA-Z0-9-].
 * Better a VippsConfigException in the checkout code path than a 400 in
 * production logs.
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
