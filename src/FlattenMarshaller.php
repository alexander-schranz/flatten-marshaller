<?php

namespace Schranz\FlattenMarshaller;

class FlattenMarshaller
{
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
    public function flatten(array $data, string $prefix = ''): array
    {
        $flattenData = $this->doFlatten($data, $prefix);

        $newData = [];
        $metadata = [];
        foreach ($flattenData as $key => $value) {
            unset($flattenData[$key]);

            $metadataKey = \preg_replace('/' . \preg_quote($this->metadataSeparator, '/') . '(\d+)' . \preg_quote($this->metadataSeparator, '/') . '/', $this->metadataSeparator, $key, -1);
            $metadataKey = \preg_replace('/' . \preg_quote($this->metadataSeparator, '/') . '(\d+)$/', '', $metadataKey, -1);
            $newKey = \str_replace($this->metadataSeparator, $this->fieldSeparator, $metadataKey);

            if ($metadataKey === $key) {
                $newData[$newKey] = $value;

                continue;
            }

            $newValue = \is_array($value) ? $value : [$value];

            $newData[$newKey] = [
                ...($newData[$newKey] ?? []),
                ...$newValue,
            ];

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
        $metadata = [];
        $metadataKeyMapping = [];
        if (\array_key_exists($this->metadataKey, $data)) {
            \assert(\is_string($data[$this->metadataKey]), 'Expected metadata to be a string.');

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
            \assert(\is_array($value) && \array_is_list($value), 'Expected value to be an array.');

            foreach ($value as $subKey => $subValue) {
                \assert(\array_key_exists($subKey, $metadata[$metadataKey]), 'Expected key "' . $subKey . '" to exist in "' . $key . '".');

                $keyPartsReplacements = $keyParts;

                $newKeyPath = \preg_replace_callback('/' . \preg_quote($this->metadataPlaceholder, '/') . '/', function () use (&$keyPartsReplacements) {
                     return \array_shift($keyPartsReplacements);
                }, $metadata[$metadataKey][$subKey]);

                $newSubData = &$newData;
                foreach (\explode($this->metadataSeparator, $newKeyPath) as $newKeyPart) {
                    $newSubData = &$newSubData[$newKeyPart];
                }

                $newSubData = $subValue;
            }
        }

        return $newData;
    }
}
