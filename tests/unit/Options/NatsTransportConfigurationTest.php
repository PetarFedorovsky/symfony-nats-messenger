<?php

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\Enum\DiscardPolicy;
use IDCT\NATS\JetStream\Enum\ReplayPolicy;
use IDCT\NATS\JetStream\Enum\RetentionPolicy;
use IDCT\NATS\JetStream\Enum\StorageBackend;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\StreamCompression;
use PHPUnit\Framework\TestCase;

final class NatsTransportConfigurationTest extends TestCase
{
    public function testTypedAccessorsNormalizeScalarValues(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [
                'consumer' => 123,
                'batching' => '0',
                'max_batch_timeout' => '0.001',
                'stream_max_age' => '-5',
                'stream_max_bytes' => '1024',
                'stream_max_messages' => '500',
                'stream_max_messages_per_subject' => '25',
                'stream_storage' => 'memory',
                'stream_replicas' => '-1',
            ],
            natsRetryHandlerEnabled: true,
        );

        self::assertSame('123', $configuration->consumer());
        self::assertSame(1, $configuration->batching());
        self::assertSame(1, $configuration->maxBatchTimeoutMs());
        self::assertSame(0, $configuration->streamMaxAgeSeconds());
        self::assertSame(1024, $configuration->streamMaxBytes());
        self::assertSame(500, $configuration->streamMaxMessages());
        self::assertSame(25, $configuration->streamMaxMessagesPerSubject());
        self::assertSame(StorageBackend::Memory, $configuration->streamStorage());
        self::assertSame(1, $configuration->streamReplicas());
        self::assertTrue($configuration->isNatsRetryHandlerEnabled());
    }

    public function testTypedAccessorsProvideDefaults(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
        );

        self::assertSame('client', $configuration->consumer());
        self::assertSame(1, $configuration->batching());
        self::assertSame(1000, $configuration->maxBatchTimeoutMs());
        self::assertSame(0, $configuration->streamMaxAgeSeconds());
        self::assertNull($configuration->streamMaxBytes());
        self::assertNull($configuration->streamMaxMessages());
        self::assertNull($configuration->streamMaxMessagesPerSubject());
        self::assertSame(StorageBackend::File, $configuration->streamStorage());
        self::assertSame(1, $configuration->streamReplicas());
        self::assertFalse($configuration->isNatsRetryHandlerEnabled());
    }

    public function testTypedAccessorsTruncateFloatValues(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [
                'batching' => 3.7,
                'stream_max_bytes' => 2048.9,
                'stream_max_messages' => 100.1,
                'stream_max_messages_per_subject' => 7.9,
                'stream_replicas' => 2.5,
            ],
            natsRetryHandlerEnabled: false,
        );

        self::assertSame(3, $configuration->batching());
        self::assertSame(2048, $configuration->streamMaxBytes());
        self::assertSame(100, $configuration->streamMaxMessages());
        self::assertSame(7, $configuration->streamMaxMessagesPerSubject());
        self::assertSame(2, $configuration->streamReplicas());
    }

    public function testScheduledMessagesAccessorReturnsConstructorValue(): void
    {
        $enabled = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
            scheduledMessagesEnabled: true,
        );

        $disabled = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
            scheduledMessagesEnabled: false,
        );

        self::assertTrue($enabled->isScheduledMessagesEnabled());
        self::assertFalse($disabled->isScheduledMessagesEnabled());
    }

    public function testScheduledMessagesDefaultsToFalse(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
        );

        self::assertFalse($configuration->isScheduledMessagesEnabled());
    }

    public function testNewStreamAndConsumerAccessorsReturnConfiguredValues(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [
                'stream_retention' => 'workqueue',
                'stream_discard' => 'new',
                'stream_duplicate_window' => '30',
                'stream_max_message_size' => '1048576',
                'stream_max_consumers' => '4',
                'stream_compression' => 's2',
                'stream_description' => 'demo stream',
                'stream_deny_delete' => true,
                'stream_deny_purge' => '1',
                'stream_allow_direct' => 'true',
                'stream_allow_rollup_headers' => false,
                'max_ack_pending' => '256',
                'inactive_threshold' => '2',
                'replay_policy' => 'original',
            ],
            natsRetryHandlerEnabled: false,
        );

        self::assertSame(RetentionPolicy::WorkQueue, $configuration->retention());
        self::assertSame(DiscardPolicy::New, $configuration->discard());
        self::assertSame(30, $configuration->duplicateWindowSeconds());
        self::assertSame(1048576, $configuration->streamMaxMessageSize());
        self::assertSame(4, $configuration->streamMaxConsumers());
        self::assertSame(StreamCompression::S2, $configuration->compression());
        self::assertSame('demo stream', $configuration->streamDescription());
        self::assertTrue($configuration->denyDelete());
        self::assertTrue($configuration->denyPurge());
        self::assertTrue($configuration->allowDirect());
        self::assertFalse($configuration->allowRollupHeaders());
        self::assertSame(256, $configuration->maxAckPending());
        self::assertSame(2000, $configuration->inactiveThresholdMs());
        self::assertSame(ReplayPolicy::Original, $configuration->replayPolicy());
    }

    public function testNewStreamAndConsumerAccessorsDefaultToNull(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
        );

        self::assertNull($configuration->retention());
        self::assertNull($configuration->discard());
        self::assertNull($configuration->duplicateWindowSeconds());
        self::assertNull($configuration->streamMaxMessageSize());
        self::assertNull($configuration->streamMaxConsumers());
        self::assertNull($configuration->compression());
        self::assertNull($configuration->streamDescription());
        self::assertNull($configuration->denyDelete());
        self::assertNull($configuration->denyPurge());
        self::assertNull($configuration->allowDirect());
        self::assertNull($configuration->allowRollupHeaders());
        self::assertNull($configuration->maxAckPending());
        self::assertNull($configuration->inactiveThresholdMs());
        self::assertNull($configuration->replayPolicy());
    }

    public function testInactiveThresholdIsClampedToAtLeastOneMillisecond(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: ['inactive_threshold' => '0.0001'],
            natsRetryHandlerEnabled: false,
        );

        self::assertSame(1, $configuration->inactiveThresholdMs());
    }

    public function testAutoSetupAccessorReturnsConstructorValue(): void
    {
        $enabled = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
            autoSetupEnabled: true,
        );

        $disabled = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
        );

        self::assertTrue($enabled->isAutoSetupEnabled());
        self::assertFalse($disabled->isAutoSetupEnabled());
    }
}
