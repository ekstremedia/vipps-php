<?php

declare(strict_types=1);

use Nesthus\Vipps\SystemInfo;
use Nesthus\Vipps\Vipps;

it('emits exactly the four Vipps-System headers', function () {
    $headers = (new SystemInfo('My Webshop', '2.1.0'))->headers();

    expect($headers)->toBe([
        'Vipps-System-Name' => 'My Webshop',
        'Vipps-System-Version' => '2.1.0',
        'Vipps-System-Plugin-Name' => 'nesthus/vipps-php',
        'Vipps-System-Plugin-Version' => Vipps::VERSION,
    ]);
});

it('allows overriding the plugin identity', function () {
    $headers = (new SystemInfo('shop', '1.0', 'my-plugin', '9.9.9'))->headers();

    expect($headers['Vipps-System-Plugin-Name'])->toBe('my-plugin')
        ->and($headers['Vipps-System-Plugin-Version'])->toBe('9.9.9');
});

it('clamps every value to 30 characters', function () {
    $headers = (new SystemInfo(str_repeat('a', 45), str_repeat('b', 31)))->headers();

    expect($headers['Vipps-System-Name'])->toBe(str_repeat('a', 30))
        ->and($headers['Vipps-System-Version'])->toBe(str_repeat('b', 30));
});

it('clamps by characters, not bytes, for multibyte names', function () {
    // 'æøå' is 3 characters but 6 bytes; a byte-based clamp would keep only
    // 15 characters — or worse, cut one in half mid-sequence.
    $name = str_repeat('æøå', 12);

    $clamped = (new SystemInfo($name, '1.0'))->headers()['Vipps-System-Name'];

    expect(mb_strlen($clamped))->toBe(30)
        ->and($clamped)->toBe(str_repeat('æøå', 10));
});

it('trims surrounding whitespace before clamping', function () {
    $headers = (new SystemInfo('  My Webshop  ', "\t1.0\n"))->headers();

    expect($headers['Vipps-System-Name'])->toBe('My Webshop')
        ->and($headers['Vipps-System-Version'])->toBe('1.0');
});

it('does not let trimmed padding eat into the 30-character budget', function () {
    $headers = (new SystemInfo('   ' . str_repeat('x', 30), '1.0'))->headers();

    expect($headers['Vipps-System-Name'])->toBe(str_repeat('x', 30));
});
