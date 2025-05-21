<?php

namespace Schranz\FlattenMarshaller;

class FlattenMarshaller
{
    /**
     * @param non-empty-string $metadataKey
     * @param non-empty-string $fieldSeparator
     * @param non-empty-string $metadataSeparator
     * @param non-empty-string $metadataPlaceholder
     */
    public function __construct(
        private readonly string $metadataKey = '_metadata',
        private readonly string $fieldSeparator = '.',
        private readonly string $metadataSeparator = '/',
        private readonly string $metadataPlaceholder = '*',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function flatten(array $data): array
    {
        $flattenData = $this->doFlatten($data);

        $newData = [];
        $metadata = [];
        foreach ($flattenData as $key => $value) {
            unset($flattenData[$key]);

            /** @var string $metadataKey */
            $metadataKey = \preg_replace('/' . \preg_quote($this->metadataSeparator, '/') . '(\d+)' . \preg_quote($this->metadataSeparator, '/') . '/', $this->metadataSeparator, $key, -1);
            /** @var string $metadataKey */
            $metadataKey = \preg_replace('/' . \preg_quote($this->metadataSeparator, '/') . '(\d+)$/', '', $metadataKey, -1);
            $newKey = \str_replace($this->metadataSeparator, $this->fieldSeparator, $metadataKey);

            if ($newKey === $key) {
                $newData[$newKey] = $value;

                continue;
            }

            if ($metadataKey === $key) {
                $newData[$newKey] = $value;
                $newValue = [$value];
            } else {
                $newValue = \is_array($value) ? $value : [$value];
                $oldValue = ($newData[$newKey] ?? []);

                \assert(\is_array($oldValue), 'Expected old value of key "' . $newKey . '" to be an array got "' . \get_debug_type($oldValue) . '".');

                $newData[$newKey] = [
                    ...$oldValue,
                    ...$newValue,
                ];
            }

            if (\str_contains($metadataKey, $this->metadataSeparator)) {
                foreach ($newValue as $v) {
                    $metadata[$metadataKey][] = \preg_replace_callback('/[^' . \preg_quote($this->metadataSeparator, '/') . ']+/', function ($matches) {
                        return \is_numeric($matches[0]) ? $matches[0] : $this->metadataPlaceholder;
                    }, $key);
                }
            }
        }

        if ($metadata !== []) {
            $newData[$this->metadataKey] = \json_encode($metadata, \JSON_THROW_ON_ERROR);
        }

        return $newData;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function doFlatten(array $data, string $prefix = ''): array
    {
        $newData = [];
        foreach ($data as $key => $value) {
            if (!\is_array($value)) {
                $newData[$prefix . $key] = $value;

                continue;
            }

            $flattened = $this->doFlatten($value, $key . $this->metadataSeparator);
            foreach ($flattened as $subKey => $subValue) {
                $newData[$prefix . $subKey] = $subValue;
            }
        }

        return $newData;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function unflatten(array $data): array
    {
        $newData = [];
        /** @var array<string, array<string>> $metadata */
        $metadata = [];
        $metadataKeyMapping = [];
        if (\array_key_exists($this->metadataKey, $data)) {
            \assert(\is_string($data[$this->metadataKey]), 'Expected metadata to be a string.');

            /** @var array<string, array<string>> $metadata */
            $metadata = \json_decode($data[$this->metadataKey], true, flags: \JSON_THROW_ON_ERROR);

            foreach (\array_keys($metadata) as $subMetadataKey) {
                $metadataKeyMapping[\str_replace($this->metadataSeparator, $this->fieldSeparator, $subMetadataKey)] = $subMetadataKey;
            }

            unset($data[$this->metadataKey]);
        }

        foreach ($data as $key => $value) {
            $metadataKey = $metadataKeyMapping[$key] ?? null;
            if (null === $metadataKey) {
                $newData[$key] = $value;

                continue;
            }

            $keyParts = \explode($this->metadataSeparator, $metadataKey);
            if (!\is_array($value)) {
                $value = [$value];
            }

            foreach ($value as $subKey => $subValue) {
                \assert(\array_key_exists($subKey, $metadata[$metadataKey]), 'Expected key "' . $subKey . '" to exist in "' . $key . '".');

                $keyPartsReplacements = $keyParts;

                /** @var string $newKeyPath */
                $newKeyPath = \preg_replace_callback('/' . \preg_quote($this->metadataPlaceholder, '/') . '/', function () use (&$keyPartsReplacements) {
                    return \array_shift($keyPartsReplacements);
                }, $metadata[$metadataKey][$subKey]);

                $newSubData = &$newData;
                foreach (\explode($this->metadataSeparator, $newKeyPath) as $newKeyPart) {
                    $newSubData = &$newSubData[$newKeyPart]; // @phpstan-ignore-line
                }

                $newSubData = $subValue;
            }
        }

        return $newData;
    }
}
