<?php

namespace Ado\Tests;

use Ado\Api\AzureDevOps;
use PHPUnit\Framework\TestCase;

class ParentRelationTest extends TestCase
{
    public function testBuildsHierarchyReverseRelationPatch(): void
    {
        $parentUrl = 'https://dev.azure.com/JMA01/JMA%20samlet/_apis/wit/workitems/44967';

        $this->assertSame(
            [[
                'op' => 'add',
                'path' => '/relations/-',
                'value' => [
                    'rel' => 'System.LinkTypes.Hierarchy-Reverse',
                    'url' => $parentUrl,
                ],
            ]],
            AzureDevOps::parentRelationPatch($parentUrl)
        );
    }
}
