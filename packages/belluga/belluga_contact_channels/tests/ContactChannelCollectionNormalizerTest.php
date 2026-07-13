<?php

declare(strict_types=1);

namespace Tests\Unit\ContactChannels;

use Belluga\ContactChannels\ContactChannelCollectionNormalizer;
use Belluga\ContactChannels\ContactChannelValidationException;
use Belluga\ContactChannels\Registry\ContactChannelDefinitionRegistry;
use Belluga\ContactChannels\Support\ContactChannelIdentifierGeneratorContract;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContactChannelCollectionNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalizes_repeatable_channels_and_maps_a_new_bubble_draft_key_to_a_server_id(): void
    {
        $normalizer = new ContactChannelCollectionNormalizer(
            ContactChannelDefinitionRegistry::withFirstDeliveryDefinitions(),
            new class implements ContactChannelIdentifierGeneratorContract {
                private int $sequence = 0;

                public function generate(): string
                {
                    $this->sequence++;

                    return 'contact_server_'.$this->sequence;
                }
            },
        );

        $result = $normalizer->normalizeForWrite([
            [
                'draft_key' => 'email-primary',
                'type' => 'email',
                'value' => ' Primary@Example.test ',
                'title' => 'Primary e-mail',
            ],
            [
                'draft_key' => 'email-sales',
                'type' => 'email',
                'value' => 'sales@example.test',
                'title' => 'Sales e-mail',
            ],
            [
                'draft_key' => 'wa-primary',
                'type' => 'whatsapp',
                'value' => '+55 (27) 99999-1111',
                'title' => 'WhatsApp primary',
                'metadata' => [
                    'initial_messages' => [
                        ['id' => 'hello', 'cta' => 'Hello', 'mensagem' => 'Hello there'],
                    ],
                ],
            ],
            [
                'draft_key' => 'wa-sales',
                'type' => 'whatsapp',
                'value' => 'https://wa.me/552799991112',
                'title' => 'WhatsApp sales',
            ],
        ], []);

        $this->assertSame('contact_server_1', $result->channels[0]['id']);
        $this->assertSame('primary@example.test', $result->channels[0]['value']);
        $this->assertSame('contact_server_3', $result->draftKeyToChannelId['wa-primary']);
        $this->assertSame('hello', $result->channels[2]['metadata']['initial_messages'][0]['id']);
    }

    #[Test]
    public function it_rejects_missing_titles_for_a_repeated_channel_type(): void
    {
        $normalizer = $this->normalizer();

        $this->expectException(ContactChannelValidationException::class);
        $this->expectExceptionMessage('title is required');

        $normalizer->normalizeForWrite([
            ['draft_key' => 'one', 'type' => 'email', 'value' => 'one@example.test'],
            ['draft_key' => 'two', 'type' => 'email', 'value' => 'two@example.test', 'title' => 'Second'],
        ], []);
    }

    #[Test]
    public function it_rejects_client_owned_or_unknown_persisted_ids_and_existing_type_changes(): void
    {
        $normalizer = $this->normalizer();

        foreach ([
            [
                [['id' => 'client-owned', 'type' => 'email', 'value' => 'one@example.test']],
                [],
            ],
            [
                [['id' => 'unknown', 'type' => 'email', 'value' => 'one@example.test']],
                [['id' => 'contact_existing', 'type' => 'email', 'value' => 'old@example.test']],
            ],
            [
                [['id' => 'contact_existing', 'type' => 'whatsapp', 'value' => '+55 27 99999-1111']],
                [['id' => 'contact_existing', 'type' => 'email', 'value' => 'old@example.test']],
            ],
        ] as [$incoming, $stored]) {
            try {
                $normalizer->normalizeForWrite($incoming, $stored);
                $this->fail('Expected an immutable identity validation failure.');
            } catch (ContactChannelValidationException $exception) {
                $this->assertNotSame('', $exception->field);
            }
        }
    }

    #[Test]
    public function it_rejects_duplicate_cta_ids_inside_one_whatsapp_channel(): void
    {
        $normalizer = $this->normalizer();

        $this->expectException(ContactChannelValidationException::class);
        $this->expectExceptionMessage('must be unique within its WhatsApp channel');

        $normalizer->normalizeForWrite([
            [
                'draft_key' => 'wa',
                'type' => 'whatsapp',
                'value' => '+55 (27) 99999-1111',
                'metadata' => [
                    'initial_messages' => [
                        ['id' => 'same', 'cta' => 'First', 'mensagem' => 'First message'],
                        ['id' => 'same', 'cta' => 'Second', 'mensagem' => 'Second message'],
                    ],
                ],
            ],
        ], []);
    }

    #[Test]
    public function it_rejects_more_than_twenty_contact_channels(): void
    {
        $channels = array_map(
            static fn (int $index): array => [
                'draft_key' => "email-{$index}",
                'type' => 'email',
                'value' => "contact{$index}@example.test",
                'title' => "Email {$index}",
            ],
            range(1, 21),
        );

        $this->expectException(ContactChannelValidationException::class);
        $this->expectExceptionMessage('maximum of 20');

        $this->normalizer()->normalizeForWrite($channels, []);
    }

    #[Test]
    public function it_rejects_oversized_whatsapp_cta_labels_and_messages(): void
    {
        $normalizer = $this->normalizer();

        foreach ([
            ['cta' => str_repeat('C', 256), 'mensagem' => 'Valid message'],
            ['cta' => 'Valid CTA', 'mensagem' => str_repeat('M', 1001)],
        ] as $message) {
            try {
                $normalizer->normalizeForWrite([
                    [
                        'draft_key' => 'wa',
                        'type' => 'whatsapp',
                        'value' => '+55 (27) 99999-1111',
                        'metadata' => ['initial_messages' => [[
                            'id' => 'message',
                            ...$message,
                        ]]],
                    ],
                ], []);
                $this->fail('Expected an initial message text-limit validation failure.');
            } catch (ContactChannelValidationException $exception) {
                $this->assertStringContainsString('configured limit', $exception->getMessage());
            }
        }
    }

    private function normalizer(): ContactChannelCollectionNormalizer
    {
        return new ContactChannelCollectionNormalizer(
            ContactChannelDefinitionRegistry::withFirstDeliveryDefinitions(),
            new class implements ContactChannelIdentifierGeneratorContract {
                public function generate(): string
                {
                    return 'contact_generated';
                }
            },
        );
    }
}
