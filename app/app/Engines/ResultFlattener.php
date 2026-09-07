<?php

namespace App\Engines;

use App\Engines\Data\OutputRef;

final class ResultFlattener
{
    /**
     * @return list<OutputRef>
     */
    public static function flatten(array $result): array
    {
        $urls = [];
        $walk = function (mixed $node) use (&$walk, &$urls): void {
            if (is_string($node)) {
                if (preg_match('#^https://#i', $node) && ! in_array($node, $urls, true)) {
                    $urls[] = $node;
                }

                return;
            }

            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };

        $walk($result['urls'] ?? []);

        return array_values(array_map(
            static fn (int $index, string $url): OutputRef => new OutputRef($index, $url),
            array_keys($urls),
            $urls,
        ));
    }
}
