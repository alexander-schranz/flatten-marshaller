<?php

namespace Schranz\FlattenMarshaller\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Schranz\FlattenMarshaller\FlattenMarshaller;

#[CoversClass(FlattenMarshaller::class)]
class FlattenMarshallerTest extends TestCase
{
    /**
     * @param array<string, mixed> $unflatten
     * @param array<string, mixed> $flatten
     */
    #[DataProvider('flattenDataProvider')]
    public function testFlatten(array $unflatten, array $flatten): void
    {
        $marshaller = new FlattenMarshaller();

        $this->assertSame($flatten, $marshaller->flatten($unflatten));
    }

    /**
     * @param array<string, mixed> $unflatten
     * @param array<string, mixed> $flatten
     */
    #[DataProvider('flattenDataProvider')]
    public function testUnflatten(array $unflatten, array $flatten): void
    {
        $marshaller = new FlattenMarshaller();

        $this->assertSame($unflatten, $marshaller->unflatten($flatten));
    }

    /**
     * @return \Generator<{
     *     0: array<string, mixed>,
     *     1: array<string, mixed>,
     * }>
     */
    public static function flattenDataProvider(): \Generator
    {
        yield 'nested_one_level' => [
            'unflatten' => [
                'id' => 1,
                'title' => 'Title',
                'blocks' => [
                    [
                        'title' => 'Title 1',
                        'tags' => ['UI', 'UX'],
                    ],
                    [
                        'title' => 'Title 2',
                        'tags' => ['Tech'],
                    ],
                ],
            ],
            'flatten' => [
                'id' => 1,
                'title' => 'Title',
                'blocks.title' => ['Title 1', 'Title 2'],
                'blocks.tags' => ['UI', 'UX', 'Tech'],
                '_metadata' => \json_encode([
                    'blocks.title' => ['*.0.*', '*.1.*'],
                    'blocks.tags' => ['*.0.*.0', '*.0.*.1', '*.1.*.0'],
                ], \JSON_THROW_ON_ERROR),
            ],
        ];

        yield 'simple' => [
            'unflatten' => [
                'id' => 1,
                'title' => 'Title',
            ],
            'flatten' => [
                'id' => 1,
                'title' => 'Title',
            ],
        ];

        yield 'simple_with_multiple' => [
            'unflatten' => [
                'id' => 1,
                'title' => 'Title',
                'tags' => ['UI', 'UX'],
            ],
            'flatten' => [
                'id' => 1,
                'title' => 'Title',
                'tags' => ['UI', 'UX'],
            ],
        ];
    }
}
