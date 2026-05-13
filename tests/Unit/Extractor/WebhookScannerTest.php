<?php

declare(strict_types=1);

use ApexDocs\Extractor\WebhookScanner;

it('discovers a #[Webhook] class via the tokenizer-based scanner', function () {
    $scanner = new WebhookScanner([__DIR__.'/../../Fixtures/Webhooks']);

    $webhooks = $scanner->scan();

    expect($webhooks)->toHaveKey('order.shipped')
        ->and($webhooks['order.shipped']['post']['summary'])->toBe('Order shipped');
});

it('skips ::class const references that previously fooled the regex scanner', function () {
    $tmp = sys_get_temp_dir().'/apexdocs_scan_'.uniqid();
    mkdir($tmp);
    file_put_contents($tmp.'/Trickery.php', <<<'PHP'
<?php
namespace ApexDocs\TestFix\Trickery;

use ApexDocs\Attribute\Webhook;

// This line has the literal word "class " on it but is not a declaration:
$x = \stdClass::class;

#[Webhook(name: 'real.event', summary: 'real one')]
final class RealClass {}
PHP);

    try {
        require_once $tmp.'/Trickery.php';
        $webhooks = (new WebhookScanner([$tmp]))->scan();
        expect($webhooks)->toHaveKey('real.event');
    } finally {
        @unlink($tmp.'/Trickery.php');
        @rmdir($tmp);
    }
});

it('returns an empty map for directories with no webhooks', function () {
    $tmp = sys_get_temp_dir().'/apexdocs_empty_'.uniqid();
    mkdir($tmp);
    file_put_contents($tmp.'/Plain.php', "<?php\nnamespace ApexDocs\\TestEmpty;\nclass Plain {}\n");

    try {
        expect((new WebhookScanner([$tmp]))->scan())->toBe([]);
    } finally {
        @unlink($tmp.'/Plain.php');
        @rmdir($tmp);
    }
});
