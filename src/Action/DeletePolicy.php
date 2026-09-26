<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

/**
 * Decides which files of a duplicate group are kept and which deleted. Pure logic, no I/O besides stat.
 */
final class DeletePolicy
{
    private const REGEX_RULES = ['filename_is', 'filename_not_is', 'path_is', 'path_not_is'];
    private const DATE_RULES = [
        'a_datetime_gt', 'a_datetime_lt', 'c_datetime_gt', 'c_datetime_lt', 'm_datetime_gt', 'm_datetime_lt',
    ];
    private const LIST_RULES = ['owner', 'group'];

    /**
     * @var array<string, string|array>
     */
    private readonly array $keep;

    /**
     * @var array<string, string|array>
     */
    private readonly array $delete;

    /**
     * @param array<string, string|array> $keepRules
     * @param array<string, string|array> $deleteRules
     * @throws \InvalidArgumentException
     */
    public function __construct(array $keepRules = [], array $deleteRules = [])
    {
        $this->keep = self::validate($keepRules);
        $this->delete = self::validate($deleteRules);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromFile(string $path): self
    {
        $json = @\file_get_contents($path);

        if ($json === false) {
            throw new \InvalidArgumentException("Unable to read delete policy: $path");
        }

        try {
            $data = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                "Invalid delete policy JSON in $path: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        if (!\is_array($data)) {
            throw new \InvalidArgumentException("Invalid delete policy JSON in $path: object expected");
        }

        return new self($data['keep_rule'] ?? [], $data['delete_rule'] ?? []);
    }

    /**
     * @param string[] $files group files, first one is kept when no keep rule matches
     * @return array{keep: string[], delete: string[]} files in neither list stay untouched
     */
    public function decide(array $files): array
    {
        $files = \array_values($files);
        $keep = \array_values(\array_filter($files, fn (string $file): bool => $this->matches($this->keep, $file)));

        if ($keep === []) {
            return ['keep' => [$files[0]], 'delete' => \array_slice($files, 1)];
        }

        $rest = \array_values(\array_diff($files, $keep));

        if ($this->delete !== []) {
            $rest = \array_values(\array_filter($rest, fn (string $file): bool => $this->matches($this->delete, $file)));
        }

        return ['keep' => $keep, 'delete' => $rest];
    }

    /**
     * @param array<string, string|array> $rules
     */
    private function matches(array $rules, string $file): bool
    {
        $info = new \SplFileInfo($file);

        foreach ($rules as $name => $rule) {
            if ($this->ruleMatches($name, $rule, $info)) {
                return true;
            }
        }

        return false;
    }

    private function ruleMatches(string $name, string|array $rule, \SplFileInfo $file): bool
    {
        return match ($name) {
            'filename_is' => \preg_match($rule, $file->getFilename()) === 1,
            'filename_not_is' => \preg_match($rule, $file->getFilename()) === 0,
            'path_is' => \preg_match($rule, $file->getPath()) === 1,
            'path_not_is' => \preg_match($rule, $file->getPath()) === 0,
            'permissions' => \substr(\sprintf('%o', $file->getPerms()), -3) === \substr($rule, -3),
            'owner' => \in_array($file->getOwner(), $rule),
            'group' => \in_array($file->getGroup(), $rule),
            default => $this->dateMatches($name, $rule, $file),
        };
    }

    private function dateMatches(string $name, string $rule, \SplFileInfo $file): bool
    {
        $stamp = match ($name[0]) {
            'a' => $file->getATime(),
            'c' => $file->getCTime(),
            default => $file->getMTime(),
        };

        return \str_ends_with($name, '_gt') ? $stamp > \strtotime($rule) : $stamp < \strtotime($rule);
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, string|array> non-empty rules only
     * @throws \InvalidArgumentException
     */
    private static function validate(array $rules): array
    {
        $rules = \array_filter($rules, static fn ($rule): bool => $rule !== '' && $rule !== [] && $rule !== null);

        foreach ($rules as $name => $rule) {
            $valid = match (true) {
                \in_array($name, self::REGEX_RULES, true) => \is_string($rule) && @\preg_match($rule, '') !== false,
                \in_array($name, self::DATE_RULES, true) => \is_string($rule) && \strtotime($rule) !== false,
                \in_array($name, self::LIST_RULES, true) => \is_array($rule),
                $name === 'permissions' => \is_string($rule) && \preg_match('/^0?[0-7]{3}$/', $rule) === 1,
                default => throw new \InvalidArgumentException("Unknown delete policy rule: $name"),
            };

            if (!$valid) {
                throw new \InvalidArgumentException("Invalid value of delete policy rule $name: " . \json_encode($rule));
            }
        }

        return $rules;
    }
}
