<?php

declare(strict_types=1);

use Pam\Browser\Browser;

require dirname(__DIR__).'/bootstrap.php';

$browser = Browser::launch();
try {
    $version = $browser->version();
    $product = $version['product'] ?? null;
    if (!is_string($product) || !str_contains($product, 'Chrome')) {
        throw new RuntimeException('Browser.getVersion returned an invalid product.');
    }

    $page = $browser->newPage('data:text/html,<title>PAM Browser</title>');
    $title = $page->evaluate('document.title');
    if ($title !== 'PAM Browser') {
        throw new RuntimeException('Runtime.evaluate did not return the page title.');
    }

    $bindingPayload = null;
    $page->exposeBinding('pamBrowserTest', static function (mixed $payload) use (&$bindingPayload): void {
        $bindingPayload = $payload;
    });
    $page->evaluate('globalThis.pamBrowserTest(JSON.stringify({bridge: "ready"}))');
    if ($bindingPayload === null) {
        $page->pump(1.0);
    }
    if (!is_array($bindingPayload) || ($bindingPayload['bridge'] ?? null) !== 'ready') {
        throw new RuntimeException('Runtime binding did not deliver JavaScript data to PHP.');
    }

    fwrite(STDOUT, sprintf(
        "PAM Browser connected to %s, created target %s, evaluated JavaScript, and delivered a PHP binding.\n",
        $product,
        $page->targetId,
    ));
} finally {
    $browser->close();
}
