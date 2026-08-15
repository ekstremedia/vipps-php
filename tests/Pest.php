<?php

declare(strict_types=1);

/*
 * Pest bootstrap. Every test is plain Pest — no base TestCase is needed since
 * the SDK has no framework container; tests construct objects directly and
 * talk to a recording fake PSR-18 client (tests/Support/FakeHttpClient.php).
 */
