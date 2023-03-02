<?php

namespace SilverStripe\TxTranslator\Tests;

use SilverStripe\TxTranslator\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use LogicException;

class TranslatorTest extends TestCase
{
    private const INVALID = 'INVALID';

    /**
     * @dataProvider provideGetCleanBranch
     */
    public function testGetCleanBranch(string $branch, string $expected)
    {
        $actual = $this->invokeMethod('getCleanBranch', $branch);
        $this->assertSame($expected, $actual);
    }

    public function provideGetCleanBranch()
    {
        return [
            [
                '5',
                '5'
            ],
            [
                '5.0',
                '5.0'
            ],
            [
                'invalid',
                'invalid'
            ],
            [
                '(HEAD detached at 1.2.3)',
                '1.2'
            ],
            [
                '(HEAD detached at 4.5.6-beta1)',
                '4.5'
            ],
            [
                // This is how it looks when a commit is checked out
                '(HEAD detached at 2238396)',
                '(HEAD detached at 2238396)'
            ]
        ];
    }

    /**
     * @dataProvider provideJsonEncode
     */
    public function testJsonEncode($data, string $expected): void
    {
        if ($expected === self::INVALID) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Malformed UTF-8 characters, possibly incorrectly encoded');
        }
        $actual = $this->invokeMethod('jsonEncode', $data);
        if ($expected !== self::INVALID) {
            $this->assertSame($expected, $actual);
        }
    }

    public function provideJsonEncode(): array
    {
        return [
            [
                [
                    'abc' => 123,
                    'def' => [
                        456,
                        'a / forward slash and some unicode Veröffentlichen'
                    ]
                ],
                trim(<<<EOT
                {
                    "abc": 123,
                    "def": [
                        456,
                        "a / forward slash and some unicode Veröffentlichen"
                    ]
                }
                EOT)
            ],
            [
                [
                    'a' . chr(-1) . 'b'
                ],
                self::INVALID
            ],
        ];
    }

    /**
     * @dataProvider provideJsonDecode
     */
    public function testJsonDecode(string $jsonString, $expected): void
    {
        if ($expected === self::INVALID) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Error json decoding this is not json: Syntax error');
        }
        $actual = $this->invokeMethod('jsonDecode', $jsonString);
        if ($expected !== self::INVALID) {
            $this->assertSame($expected, $actual);
        }
    }

    public function provideJsonDecode(): array
    {
        return [
            [
                trim(<<<EOT
                {
                    "abc": 123,
                    "def": [
                        456,
                        "a / forward slash and some unicode Veröffentlichen"
                    ]
                }
                EOT),
                [
                    'abc' => 123,
                    'def' => [
                        456,
                        'a / forward slash and some unicode Veröffentlichen'
                    ]
                ]
            ],
            [
                'this is not json',
                self::INVALID
            ],
        ];
    }

    /**
     * @dataProvider provideArrayMergeRecursive
     */
    public function testArrayMergeRecursive(array $array1, array $array2, array $expected): void
    {
        $actual = $this->invokeMethod('arrayMergeRecursive', $array1, $array2);
        $this->assertSame($expected, $actual);
    }

    public function provideArrayMergeRecursive(): array
    {
        return [
            [
                [
                    'a' => 1,
                    'b' => 2,
                    'c' => [
                        'ca' => 3,
                        'cb' => 4
                    ]
                ],
                [
                    'b' => 22,
                    'c' => [
                        'cb' => 44
                    ],
                    'd' => 5
                ],
                [
                    'a' => 1,
                    'b' => 22,
                    'c' => [
                        'ca' => 3,
                        'cb' => 44
                    ],
                    'd' => 5
                ],
            ]
        ];
    }

    private function invokeMethod(string $methodName, ...$args)
    {
        $translator = new Translator();
        $method = new ReflectionMethod($translator, $methodName);
        $method->setAccessible(true);
        return $method->invoke($translator, ...$args);
    }
}
