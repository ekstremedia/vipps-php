<?php

declare(strict_types=1);

namespace Nesthus\Vipps\Exceptions;

use Throwable;

/**
 * Marker interface for every exception this SDK throws, so integrators can
 * `catch (VippsException $e)` at a boundary without enumerating the concrete
 * types.
 */
interface VippsException extends Throwable {}
