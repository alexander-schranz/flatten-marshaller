# How to flatten and unflatten complex JSON Objects or PHP arrays?

While working on [SEAL the Search Engine Abstraction Layer for PHP](https://github.com/PHP-CMSIG/search)
I have struggled with one issue.

Not all search engines support nested objects in the same way. So for some search engines it is required
to flatten the nested objects into a single level array.

So if we have, for example, something like this:

```php
[
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
]
```

we require it to flatten it to something like that:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
]
```

That way it is possible to query on the nested field `blocks.tags` in search engines
which do not support such nested objects.

## Time to try to flatten the object

The easiest way to flatten the object is to use a recursive function which will iterate over the array 
and flatten the keys:

```php
public function doFlatten(array $data): array
{
    $newData = [];
    foreach ($data as $key => $value) {
        if (!is_array($value)) {
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
```

But this ends in our example in the following output:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.0.title' => 'Title 1',
    'blocks.0.tags.0' => 'UI',
    'blocks.0.tags.1' => 'UX',
    'blocks.1.title' => 'Title 2',
    'blocks.1.tags.0' => 'Tech'
 ]
```

The above is a very common format if you search for "Flatten JSON algorithm" examples.
But which isn't what we want, even we add a check on `array_is_list` and ending up in:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.0.title' => 'Title 1',
    'blocks.0.tags' => [
        'UI',
        'UX',
    ],
    'blocks.1.title' => 'Title 2',
    'blocks.1.tags' => [
        'Tech',
    ],
]
```

It doesn't allow us to query on our required `blocks.tags` field as it is split into multiple fields.
But it's already a good step forward, but I reverted the `array_is_list` to have the common flatten by `blocks.0.tags.0`
as it is easier to move forward from there for very complex objects.

In the next step we will just iterate over the array check if it contains numbers split by using:

```php
$newData = [];
foreach ($flattenData as $key => $value) {
    unset($flattenData[$key]);
    $newKey = preg_replace('/\.(\d+)\./', '.', $key, -1);
    $newKey = preg_replace('/\.(\d+)$/', '', $newKey, -1);
    
    //
```

If the `$newKey` still matches `$key` we can directly go to the next step:

```php
    if ($newKey === $key) {
        $newData[$key] = $value;
    
        continue;
    }
```

If not, we convert the result into an array and eventually merge the values:

```php
    $newData[$newKey] = [
        ...($newData[$newKey] ?? []),
        ...(is_array($value) ? $value : [$value]),
    ];
}
```

So the full example for flatten the data look like this:

```php
class FlattenMarshaller
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function flatten(array $data, string $prefix = ''): array
    {
        $flattenData = $this->doFlatten($data, $prefix);

        $newData = [];
        foreach ($flattenData as $key => $value) {
            unset($flattenData[$key]);

            $newKey = preg_replace('/\.(\d+)\./', '.', $key, -1);
            $newKey = preg_replace('/\.(\d+)$/', '', $newKey, -1);

            if ($newKey === $key) {
                $newData[$key] = $value;

                continue;
            }

            $newValue = is_array($value) ? $value : [$value];

            $newData[$newKey] = [
                ...($newData[$newKey] ?? []),
                ...$newValue,
            ];
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
            if (!is_array($value)) {
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
}
```

and the result is now as expected:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
]
```

All fine tests pass and we are happy or? We just use the reverse logic to unflatten the data?

## Prepare unflatten the object

As we now have flatten the data like this:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
]
```

And we want again this:

```php
[
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
]
```

We will stumble over a small problem.

You already maybe see it. While `blocks.title` may straight forward the `blocks.tags` is not.
We don't know longer that `['UI', 'UX']` was part of `blocks.0` and `['Tech']` was part of `blocks.1`.

My current workaround in SEAL was to have an additional field lets call it `_raw` which includes the whole
original document JSON encoded. So we can use that to unflatten the data back to the original array.:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
    '_raw' => '{"id":1,"title":"Title","blocks":[{"title":"Title 1","tags":["UI","UX"]},{"title":"Title 2","tags":["Tech"]}]}',
]
```

This comes with the big disadvantage that the document is now twice as big as it was before. The second issue is
that when using the highlighting feature, it is applied to the flatten data not the raw data
and so highlighting not works correctly in all cases.

So we need a better solution for this problem.

We need some kind of additional metadata to know how we can map the data back to the original array, 
which I did come up with something like this:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
    '_metadata' => [
        'blocks.title' => ['blocks.0.title', 'blocks.1.title'],
        'blocks.tags' => ['blocks.0.tags.0', 'blocks.0.tags.1', 'blocks.1.tags.0'],
    ],
]
```

So we can use the metadata to unflatten the data back to the original array, but first we need
to create the metadata with a small adoption:

```diff
+      $metadata = [];
       foreach ($flattenData as $key => $value) {
            // ..

            $newData[$newKey] = [
                ...($newData[$newKey] ?? []),
                ...$newValue,
            ];

+           foreach ($newValue as $v) {
+               $metadata[$newKey][] = $key;
+           }
        }

+       $newData['_metadata'] = $metadata;

        return $newData;
```

So yes, it is a basic case, but yes our metadata still looks longer as our actual document. In real use cases the documents
will be a lot bigger with more texts so we could keep it this way. But I would like to compress the metadata by using
placeholders of the data we already know by the key and not require again:

```diff
    '_metadata' => [
-        'blocks.title' => ['blocks.0.title', 'blocks.1.title'],
+        'blocks.title' => ['*.0.*', '*.1.*'],
-        'blocks.tags' => ['*.0.*.0', '*.0.*.1', '*.1.*.0'],
+        'blocks.tags' => ['*.0.*.0', '*.0.*.1', '*.1.*.0'],
    ],
```

With a callback regex replacement we can achieve that:

```diff
-               $metadata[$newKey][] = $key;
+               $metadata[$newKey][] = \preg_replace_callback('/[^.]+/', function ($matches) {
                    return \is_numeric($matches[0]) ? $matches[0] : '*';
                }, $key)
```

As we already split by `.` the `is_numeric` check is enough to know if it is the list index which place is skipped.

So we flatten the object to:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
    '_metadata' => [
        'blocks.title' => ['*.0.*', '*.1.*'],
        'blocks.tags' => ['*.0.*.0', '*.0.*.1', '*.1.*.0'],
    ],
]
```

But the metadata is again some unsupported nested object to fix that we call the `json_encode` method on it:

```php
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
    '_metadata' => '{"blocks.title":["*.0.*","*.1.*"],"blocks.tags":["*.0.*.0","*.0.*.1","*.1.*.0"]}',
]
```

So with a single additional field we have enough metadata to unflatten the data back to the original array.

### Unflatten the object

Unflatten the data is kind of less todo as flatten the data.
First we extract the metadata from the original data
and all fields which do not have metadata can be directly used:

```php
    public function unflatten(array $data): array
    {
        $metadata = json_decode($data['_metadata'], true, flags: JSON_THROW_ON_ERROR);
        unset($data['_metadata']);

        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $metadata)) {
                $newData[$key] = $value;

                continue;
            }
            
            // ...
        }
        
        return $newData;
    }
```

First we need to split the key e.g. `blocks.tags` into `['blocks', 'tags']`.
That value is the array of the data which we loop over.

Via the `preg_replace_callback` we can replace the `*` so `*.0.*` becomes `blocks.0.tags` and so on.
Which the `keyPath` we can use to create the unflatten data structure.

```php
            // ...
            
            $keyParts = explode('.', $key);

            foreach ($value as $subKey => $subValue) {
                $keyPartsReplacements = $keyParts;

                $keyPath = preg_replace_callback('/\*/', function () use (&$keyPartsReplacements) {
                    return array_shift($keyPartsReplacements);
                }, $metadata[$key][$subKey]);
                
                // ...
            }
```

Via a reference we can get into the deep structure we require to set our value we got from the flatten data:

```php
                // ...

                $newSubData = &$newData;
                foreach (explode('.', $keyPath) as $newKeyPart) {
                    $newSubData = &$newSubData[$newKeyPart];
                }

                $newSubData = $subValue;
```

So the whole `unflatten` method look like this:

```php
    public function unflatten(array $data): array
    {
        $newData = [];
        $metadata = $data[$this->metadataKey] ?? [];
        unset($data[$this->metadataKey]);

        foreach ($data as $key => $value) {
            if (!\array_key_exists($key, $metadata)) {
                $newData[$key] = $value;

                continue;
            }

            $keyParts = \explode('.', $key);

            foreach ($value as $subKey => $subValue) {
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
```

With these two methods we are able to flatten and unflatten JSON objects:

```php
// unflatten data:
[
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
];

// flatten data:
[
    'id' => 1,
    'title' => 'Title',
    'blocks.title' => ['Title 1', 'Title 2'],
    'blocks.tags' => ['UI', 'UX', 'Tech'],
    '_metadata' => '{"blocks.title":["*.0.*","*.1.*"],"blocks.tags":["*.0.*.0","*.0.*.1","*.1.*.0"]}',
];
```

## Addition after usage in SEAL

~~For SEAL use case this is enough~~. I was wrong in this case after try to use that I found out
that I used `_` in Loupe as field separator which can conflict with SEAL field name.

Example if we rename our `blocks` field to `content_blocks` and use `_` instead of `.` as a separator:

```php
// unflatten data:
[
    'id' => 1,
    'title' => 'Title',
    'content_blocks' => [
        [
           'title' => 'Title 1',
           'tags' => ['UI', 'UX'],
        ],
        [
           'title' => 'Title 2',
           'tags' => ['Tech'],
        ],
    ],
];

// flatten data:
[
    'id' => 1,
    'title' => 'Title',
    'content_blocks_title' => ['Title 1', 'Title 2'],
    'content_blocks_tags' => ['UI', 'UX', 'Tech'],
    '_metadata' => '{"content_blocks_title":["*_0_*","*_1_*"],"content_blocks_tags":["*_0_*_0","*_0_*_1","*_1_*_0"]}',
];
```

I will not go over the whole refactoring, but I am now using two configurable separators,
one for the field (`_`) and one for the metadata (`/`).

```php
// unflatten data:
[
    'id' => 1,
    'title' => 'Title',
    'content_blocks' => [
        [
           'title' => 'Title 1',
           'tags' => ['UI', 'UX'],
        ],
        [
           'title' => 'Title 2',
           'tags' => ['Tech'],
        ],
    ],
];

// flatten data:
[
    'id' => 1,
    'title' => 'Title',
    'content_blocks_title' => ['Title 1', 'Title 2'],
    'content_blocks_tags' => ['UI', 'UX', 'Tech'],
    '_metadata' => '{"content_blocks/title":["*/0/*","*/1/*"],"content_blocks/tags":["*/0/*/0","*/0/*/1","*/1/*/0"]}',
];
```

This way even if the search engine is limited to specific field names characters,
we can still flatten and unflatten the data.

The whole implementation can be found in [src/FlattenMarshaller.php](src/FlattenMarshaller.php).

## Conclusion

For SEAL use case this is enough; the solution comes with a limitation that field names always need
to start with a letter as numeric fields are reserved for the list indexes. This is not a problem for SEAL
as it already has that strict field name definition.

If you have any other recommendation to tackle such task to flatten and unflatten an object with a better solution,
please let me know.
