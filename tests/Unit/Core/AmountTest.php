<?php

declare(strict_types=1);

use Nesthus\Vipps\Amount;
use Nesthus\Vipps\Exceptions\VippsConfigException;

describe('fromMinor', function () {
    it('keeps the minor units as given', function () {
        $amount = Amount::fromMinor(4950);

        expect($amount->minorUnits)->toBe(4950)
            ->and($amount->currency)->toBe('NOK');
    });

    it('accepts zero', function () {
        expect(Amount::fromMinor(0)->minorUnits)->toBe(0);
    });

    it('accepts another ISO currency', function () {
        expect(Amount::fromMinor(100, 'EUR')->currency)->toBe('EUR');
    });

    it('rejects a negative amount', function () {
        Amount::fromMinor(-1);
    })->throws(VippsConfigException::class, 'negative');
});

describe('fromMajor', function () {
    it('multiplies whole units by 100', function () {
        expect(Amount::fromMajor(49)->minorUnits)->toBe(4900);
    });

    it('adds the minor remainder', function () {
        expect(Amount::fromMajor(49, 50)->minorUnits)->toBe(4950);
    });

    it('accepts the remainder bounds 0 and 99', function () {
        expect(Amount::fromMajor(1, 0)->minorUnits)->toBe(100)
            ->and(Amount::fromMajor(1, 99)->minorUnits)->toBe(199);
    });

    it('rejects a remainder above 99', function () {
        Amount::fromMajor(1, 100);
    })->throws(VippsConfigException::class, 'between 0 and 99');

    it('rejects a negative remainder', function () {
        Amount::fromMajor(1, -1);
    })->throws(VippsConfigException::class, 'between 0 and 99');

    it('rejects a negative major amount', function () {
        Amount::fromMajor(-1);
    })->throws(VippsConfigException::class, 'negative');
});

describe('currency validation', function () {
    it('rejects malformed currency codes', function (string $currency) {
        Amount::fromMinor(100, $currency);
    })->with([
        'lowercase' => 'nok',
        'too short' => 'NO',
        'too long' => 'NOKK',
        'empty' => '',
        'digits' => 'N0K',
    ])->throws(VippsConfigException::class, 'ISO 4217');
});

describe('equals', function () {
    it('is true for the same value and currency', function () {
        expect(Amount::fromMinor(100)->equals(Amount::fromMajor(1)))->toBeTrue();
    });

    it('is false when the value differs', function () {
        expect(Amount::fromMinor(100)->equals(Amount::fromMinor(101)))->toBeFalse();
    });

    it('is false when only the currency differs', function () {
        expect(Amount::fromMinor(100, 'NOK')->equals(Amount::fromMinor(100, 'EUR')))->toBeFalse();
    });
});
