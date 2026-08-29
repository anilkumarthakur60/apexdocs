<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony;

use ApexDocs\Bridge\Symfony\DependencyInjection\ApexDocsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle entry point  register in {@see Symfony\Component\HttpKernel\Kernel}
 * or via `bundles.php`:
 *
 *     ApexDocs\Bridge\Symfony\ApexDocsBundle::class => ['all' => true],
 *
 * Then publish configuration under `config/packages/apex_docs.yaml` with the
 * usual `apex_docs:` root (see the bundled config template).
 */
final class ApexDocsBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new ApexDocsExtension();
    }

    public function getPath(): string
    {
        return __DIR__;
    }
}
