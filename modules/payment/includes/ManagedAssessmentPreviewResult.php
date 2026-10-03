<?php
declare(strict_types=1);

/** Stable backend DTO. Deliberately has no persistence behaviour. */
final class ManagedAssessmentPreviewResult implements JsonSerializable
{
    /** @param array<string,mixed> $payload */
    public function __construct(private readonly array $payload) {}
    /** @return array<string,mixed> */ public function toArray(): array { return $this->payload; }
    /** @return array<string,mixed> */ public function jsonSerialize(): array { return $this->payload; }
}
