<?php
/**
 * Author: SevaCode
 * CreatedAt: 01.10.2018
 */

declare(strict_types=1);

namespace SevaCode\ClickHouseClient;

class ChcRequest
{
    private ?array $settings = null;
    private ?string $query = null;
    private ?string $returnFormat = null;

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function getReturnFormat(): ?string
    {
        return $this->returnFormat;
    }

    public function setReturnFormat(?string $returnFormat): self
    {
        $this->returnFormat = $returnFormat;
        return $this;
    }
}