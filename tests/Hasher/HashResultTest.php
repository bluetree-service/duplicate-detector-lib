<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\HashResult;
use PHPUnit\Framework\TestCase;

class HashResultTest extends TestCase
{
    public function testMergeJoinsFilesOfSameHashAndErrors(): void
    {
        $first = new HashResult(['aa' => ['/1'], 'bb' => ['/2']], ['e1']);
        $second = new HashResult(['aa' => ['/3'], '123' => ['/4']], ['e2']);

        $merged = $first->merge($second);

        $this->assertSame(['/1', '/3'], $merged->hashes['aa']);
        $this->assertSame(['/2'], $merged->hashes['bb']);
        $this->assertSame(['/4'], $merged->hashes['123']);
        $this->assertSame(['e1', 'e2'], $merged->errors);
    }
}
