<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Conversation;

/**
 * Unit tests for the Conversation model (getter/setter contracts).
 *
 * These tests are intentionally isolated: no DB, no DI container.
 * AbstractModel stores data in a plain array, so we can use a partial mock
 * that skips _construct() (which would try to load a ResourceModel).
 */
class ConversationTest extends TestCase
{
    private Conversation $model;

    protected function setUp(): void
    {
        // getMockBuilder + disableOriginalConstructor avoids ResourceModel resolution
        $this->model = $this->getMockBuilder(Conversation::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])  // override no methods — test real setters/getters
            ->getMock();
    }

    public function testSessionIdRoundtrip(): void
    {
        $this->model->setSessionId('sess-abc-123');
        $this->assertSame('sess-abc-123', $this->model->getSessionId());
    }

    public function testChannelTypeRoundtrip(): void
    {
        $this->model->setChannelType('email');
        $this->assertSame('email', $this->model->getChannelType());

        $this->model->setChannelType('webchat');
        $this->assertSame('webchat', $this->model->getChannelType());
    }

    public function testCustomerEmailRoundtrip(): void
    {
        $this->model->setCustomerEmail('kunde@example.com');
        $this->assertSame('kunde@example.com', $this->model->getCustomerEmail());
    }

    public function testStatusRoundtrip(): void
    {
        $this->model->setStatus('open');
        $this->assertSame('open', $this->model->getStatus());

        $this->model->setStatus('anonymized');
        $this->assertSame('anonymized', $this->model->getStatus());
    }

    public function testMagentoCustomerIdNullable(): void
    {
        $this->model->setMagentoCustomerId(42);
        $this->assertSame(42, $this->model->getMagentoCustomerId());

        $this->model->setMagentoCustomerId(null);
        $this->assertNull($this->model->getMagentoCustomerId());
    }

    public function testStoreIdDefaultsToZero(): void
    {
        // No data set → getData returns null → cast to int = 0
        $this->assertSame(0, $this->model->getStoreId());
    }

    public function testStoreIdRoundtrip(): void
    {
        $this->model->setStoreId(3);
        $this->assertSame(3, $this->model->getStoreId());
    }

    public function testGetIdReturnsNullWhenNotSet(): void
    {
        $this->assertNull($this->model->getId());
    }

    public function testGetIdCastsToInt(): void
    {
        $this->model->setData('id', '7');
        $this->assertSame(7, $this->model->getId());
    }
}
