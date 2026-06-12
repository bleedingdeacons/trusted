<?php

declare(strict_types=1);

namespace Trusted\Domain;

/**
 * A single shift definition coming from a weekly template (not yet scheduled
 * against a concrete date). Times are 24-hour "H:i" strings.
 *
 * A shift may optionally name a member (by their Unity anonymous name) who
 * should be pre-assigned when the template is applied. Empty string means the
 * shift carries no member.
 */
final class Shift implements \JsonSerializable
{
    public function __construct(
        private string $startTime,
        private string $endTime,
        private string $label = '',
        private string $member = '',
    ) {
    }

    public function startTime(): string
    {
        return $this->startTime;
    }

    public function endTime(): string
    {
        return $this->endTime;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The anonymous name of the member to pre-assign, or '' for none.
     */
    public function member(): string
    {
        return $this->member;
    }

    /**
     * @return array{start: string, end: string, label: string, member: string}
     */
    public function toArray(): array
    {
        return [
            'start'  => $this->startTime,
            'end'    => $this->endTime,
            'label'  => $this->label,
            'member' => $this->member,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
