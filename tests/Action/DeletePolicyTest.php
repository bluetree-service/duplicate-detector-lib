<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Action;

use BlueDuplicateDetector\Action\DeletePolicy;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class DeletePolicyTest extends TestCase
{
    private string $dir;
    private array $files;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(Fixture::STANDARD);
        $this->files = ["$this->dir/a/one.txt", "$this->dir/b/two.txt", "$this->dir/b/c/three.txt"];
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    public function testWithoutRulesKeepsFirst(): void
    {
        $this->assertSame(
            ['keep' => [$this->files[0]], 'delete' => [$this->files[1], $this->files[2]]],
            (new DeletePolicy())->decide($this->files)
        );
    }

    public function testKeepRuleWithoutDeleteRulesDeletesRest(): void
    {
        $decision = (new DeletePolicy(['path_is' => '#/b$#']))->decide($this->files);

        $this->assertSame([$this->files[1]], $decision['keep']);
        $this->assertSame([$this->files[0], $this->files[2]], $decision['delete']);
    }

    public function testDeleteRulesLimitDeletedFiles(): void
    {
        $decision = (new DeletePolicy(['path_is' => '#/b$#'], ['filename_is' => '/three/']))->decide($this->files);

        $this->assertSame([$this->files[1]], $decision['keep']);
        $this->assertSame([$this->files[2]], $decision['delete'], 'a/one.txt untouched');
    }

    public function testDeleteRulesIgnoredWhenNoKeepRuleMatches(): void
    {
        $decision = (new DeletePolicy(['path_is' => '#/none$#'], ['filename_is' => '/three/']))->decide($this->files);

        $this->assertSame([$this->files[0]], $decision['keep']);
        $this->assertSame([$this->files[1], $this->files[2]], $decision['delete']);
    }

    public function testNotRules(): void
    {
        $decision = (new DeletePolicy(['filename_not_is' => '/two|three/']))->decide($this->files);

        $this->assertSame([$this->files[0]], $decision['keep']);
    }

    public function testDateRules(): void
    {
        \touch($this->files[2], \strtotime('2000-06-01'));

        $older = (new DeletePolicy(['m_datetime_lt' => '2001-01-01']))->decide($this->files);
        $newer = (new DeletePolicy(['m_datetime_gt' => '2001-01-01']))->decide($this->files);

        $this->assertSame([$this->files[2]], $older['keep']);
        $this->assertSame([$this->files[0], $this->files[1]], $newer['keep']);
    }

    public function testPermissionsRule(): void
    {
        \chmod($this->files[1], 0600);

        $this->assertSame([$this->files[1]], (new DeletePolicy(['permissions' => '0600']))->decide($this->files)['keep']);
    }

    public function testOwnerRule(): void
    {
        $decision = (new DeletePolicy(['owner' => [\fileowner($this->files[0])]]))->decide($this->files);

        $this->assertSame($this->files, $decision['keep']);
        $this->assertSame([], $decision['delete']);
    }

    public static function invalidRules(): array
    {
        return [
            'regex' => [['filename_is' => '/[/']],
            'date' => [['m_datetime_gt' => 'not a date at all']],
            'permissions' => [['permissions' => 'rwx']],
            'owner' => [['owner' => 'root']],
            'unknown' => [['size_gt' => '10']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRules')]
    public function testInvalidRuleThrows(array $rules): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeletePolicy([], $rules);
    }

    public function testFromFileWithInvalidJson(): void
    {
        \file_put_contents("$this->dir/policy.json", '{"keep_rule": ');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid delete policy JSON');

        DeletePolicy::fromFile("$this->dir/policy.json");
    }

    public function testFromMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeletePolicy::fromFile("$this->dir/missing.json");
    }

    public function testExamplePolicyLoads(): void
    {
        $policy = DeletePolicy::fromFile(__DIR__ . '/../../etc/delete_policy.json');

        $this->assertSame([$this->files[0]], $policy->decide($this->files)['keep']);
    }
}
