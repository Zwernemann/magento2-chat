<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Conversation;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Conversation\TopicResolver;

/**
 * The topic-change decision itself is made by the query-builder LLM
 * (ConversationalQueryBuilder::continues_topic); TopicResolver only derives the
 * conversation-grid subject.
 */
class TopicResolverTest extends TestCase
{
    private TopicResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TopicResolver();
    }

    public function testDeriveSubject(): void
    {
        $this->assertSame('Kabeltrommel 50m', $this->resolver->deriveSubject('Kabeltrommel 50m', []));
        $this->assertSame(
            'Jung SCHUKO-Steckdose',
            $this->resolver->deriveSubject('', [['sku' => 'ELK-0425', 'name' => 'Jung SCHUKO-Steckdose']])
        );
        $this->assertSame('', $this->resolver->deriveSubject('', []));
        $this->assertSame('Kabeltrommel 50m', $this->resolver->deriveSubject("  Kabeltrommel\n  50m ", []));
    }
}
