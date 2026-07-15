<?php

declare(strict_types=1);

namespace Tests\Unit\ContactChannels;

use Belluga\ContactChannels\Registry\ContactChannelDefinitionRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContactChannelDefinitionRegistryTest extends TestCase
{
    #[Test]
    public function it_exposes_the_first_delivery_definition_capabilities_and_canonical_launches(): void
    {
        $registry = ContactChannelDefinitionRegistry::withFirstDeliveryDefinitions();

        $email = $registry->require('email');
        $whatsapp = $registry->require('whatsapp');

        $this->assertTrue($email->capabilities()->publicCard);
        $this->assertTrue($email->capabilities()->directLaunch);
        $this->assertFalse($email->capabilities()->bubble);
        $this->assertFalse($email->capabilities()->messagePresets);
        $this->assertSame('mailto:team%40example.test', $email->resolveLaunch('team@example.test')->uri);

        $this->assertTrue($whatsapp->capabilities()->bubble);
        $this->assertTrue($whatsapp->capabilities()->messagePresets);
        $this->assertSame(
            'https://wa.me/5527999991111?text=Hello%20there',
            $whatsapp->resolveLaunch('+55 (27) 99999-1111', 'Hello there')->uri,
        );
    }

    #[Test]
    public function it_conforms_to_the_package_local_v1_contact_channel_vectors(): void
    {
        /** @var array{version: int, channels: array<string, array<string, mixed>>} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/contact_channels.v1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $fixture['version']);

        $registry = ContactChannelDefinitionRegistry::withFirstDeliveryDefinitions();
        foreach ($fixture['channels'] as $type => $channelFixture) {
            $definition = $registry->require($type);
            $capabilities = $definition->capabilities();
            $this->assertSame($channelFixture['capabilities']['public_card'], $capabilities->publicCard);
            $this->assertSame($channelFixture['capabilities']['direct_launch'], $capabilities->directLaunch);
            $this->assertSame($channelFixture['capabilities']['bubble'], $capabilities->bubble);
            $this->assertSame($channelFixture['capabilities']['message_presets'], $capabilities->messagePresets);
            $this->assertSame($channelFixture['capabilities']['repeatable'], $capabilities->repeatable);
            $this->assertSame($channelFixture['capabilities']['max_initial_messages'], $capabilities->maxInitialMessages);
            $this->assertSame($channelFixture['capabilities']['max_initial_message_cta_length'], $capabilities->maxInitialMessageCtaLength);
            $this->assertSame($channelFixture['capabilities']['max_initial_message_length'], $capabilities->maxInitialMessageLength);

            foreach ($channelFixture['vectors'] as $vector) {
                $this->assertSame(
                    $vector['normalized_value'],
                    $definition->normalizeValue($vector['raw_value']),
                    "{$type} normalization vector failed for {$vector['raw_value']}",
                );
                $this->assertSame(
                    $vector['launch_uri'],
                    $definition->resolveLaunch($vector['raw_value'])->uri,
                    "{$type} launch vector failed for {$vector['raw_value']}",
                );
                if (array_key_exists('launch_uri_with_message', $vector)) {
                    $this->assertSame(
                        $vector['launch_uri_with_message'],
                        $definition->resolveLaunch($vector['raw_value'], 'Hello there')->uri,
                        "{$type} prefixed launch vector failed for {$vector['raw_value']}",
                    );
                }
            }
        }
    }
}
