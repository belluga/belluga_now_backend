<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Registry;

use Belluga\ContactChannels\ContactChannelValidationException;
use Belluga\ContactChannels\Contracts\ContactChannelDefinitionContract;
use Belluga\ContactChannels\Definitions\EmailContactChannelDefinition;
use Belluga\ContactChannels\Definitions\WhatsAppContactChannelDefinition;

final class ContactChannelDefinitionRegistry
{
    /** @var array<string, ContactChannelDefinitionContract> */
    private array $definitions = [];

    /** @param iterable<ContactChannelDefinitionContract> $definitions */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $type = $definition->type();
            if (isset($this->definitions[$type])) {
                throw new \InvalidArgumentException("Contact channel definition [{$type}] is registered more than once.");
            }

            $this->definitions[$type] = $definition;
        }
    }

    public static function withFirstDeliveryDefinitions(): self
    {
        return new self([
            new EmailContactChannelDefinition,
            new WhatsAppContactChannelDefinition,
        ]);
    }

    public function require(string $type): ContactChannelDefinitionContract
    {
        $normalized = strtolower(trim($type));
        if (! isset($this->definitions[$normalized])) {
            throw new ContactChannelValidationException('type', 'Contact channel type is not supported.');
        }

        return $this->definitions[$normalized];
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return array_keys($this->definitions);
    }
}
