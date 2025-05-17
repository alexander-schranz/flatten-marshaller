<?php

namespace Schranz\FlattenMarshaller;

class FlattenMarshaller
{
    public function __construct(
        private readonly string $metadataKey = '_metadata',
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

            $newKey = \preg_replace('/\.(\d+)\./', '.', $key, -1);
            $newKey = \preg_replace('/\.(\d+)$/', '', $newKey, -1);

            if ($newKey === $key) {
                $newData[$key] = $value;

                continue;
            }

            $newValue = \is_array($value) ? $value : [$value];

            $newData[$newKey] = [
                ...($newData[$newKey] ?? []),
                ...$newValue,
            ];

            if (\str_contains($newKey, '.')) {
                foreach ($newValue as $v) {
                    $metadata[$newKey][] = \preg_replace_callback('/[^.]+/', function ($matches) {
                        return \is_numeric($matches[0]) ? $matches[0] : '*';
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

            $flattened = $this->doFlatten($value, $key . '.');
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
        if (\array_key_exists($this->metadataKey, $data)) {
            \assert(\is_string($data[$this->metadataKey]), 'Expected metadata to be a string.');

            $metadata = \json_decode($data[$this->metadataKey], true, flags: \JSON_THROW_ON_ERROR);
            unset($data[$this->metadataKey]);
        }

        foreach ($data as $key => $value) {
            if (!\array_key_exists($key, $metadata)) {
                $newData[$key] = $value;

                continue;
            }

            $keyParts = \explode('.', $key);
            \assert(\is_array($value) && \array_is_list($value), 'Expected value to be an array.');

            foreach ($value as $subKey => $subValue) {
                \assert(\array_key_exists($subKey, $metadata[$key]), 'Expected key "' . $subKey . '" to exist in "' . $key . '".');

                $keyPartsReplacements = $keyParts;

                $newKeyPath = \preg_replace_callback('/\*/', function () use (&$keyPartsReplacements) {
                    return \array_shift($keyPartsReplacements);
                }, $metadata[$key][$subKey]);

                $newSubData = &$newData;
                foreach (explode('.', $newKeyPath) as $newKeyPart) {
                    $newSubData = &$newSubData[$newKeyPart];
                }

                $newSubData = $subValue;
            }
        }

        return $newData;
    }
}
