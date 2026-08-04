<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

/**
 * Compression algorithm applied to a JetStream file-backed stream.
 *
 * - **None**: store messages uncompressed (the JetStream default).
 * - **S2**: compress with S2, trading CPU for disk. Requires NATS 2.10 or newer.
 *
 * The underlying client models this field as a plain string rather than an enum, so this local enum
 * plays the same role {@see RetryHandler} does: it gives the option one authoritative list of allowed
 * values instead of repeating the allowlist at each place that validates or documents it.
 *
 * @see NatsTransportConfiguration::streamCompression() Returns the configured algorithm.
 */
enum StreamCompression: string
{
    case None = 'none';
    case S2 = 's2';
}
