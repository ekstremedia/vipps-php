<?php

declare(strict_types=1);

namespace Nesthus\Vipps;

/**
 * The four Vipps-System-* HTTP headers Vipps asks every integration to send.
 * They identify the merchant's system and the SDK in Vipps' logs — useful to
 * both sides when debugging a support case, and required for certified
 * plugins. Vipps caps each value at 30 characters, so values are truncated
 * rather than rejected: a long system name should degrade, not break a call.
 */
final readonly class SystemInfo
{
    private const MAX_HEADER_LENGTH = 30;

    public function __construct(
        public string $systemName,
        public string $systemVersion,
        public string $pluginName = 'nesthus/vipps-php',
        public string $pluginVersion = Vipps::VERSION,
    ) {}

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Vipps-System-Name' => $this->clamp($this->systemName),
            'Vipps-System-Version' => $this->clamp($this->systemVersion),
            'Vipps-System-Plugin-Name' => $this->clamp($this->pluginName),
            'Vipps-System-Plugin-Version' => $this->clamp($this->pluginVersion),
        ];
    }

    private function clamp(string $value): string
    {
        return mb_substr(trim($value), 0, self::MAX_HEADER_LENGTH);
    }
}
