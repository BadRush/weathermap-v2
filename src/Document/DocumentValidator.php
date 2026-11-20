<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final class DocumentValidator
{
    /**
     * Validate a raw map document array and return a list of validation errors.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function validate(array $data): array
    {
        $errors = [];

        if (isset($data['canvas']) && is_array($data['canvas'])) {
            $w = $data['canvas']['width'] ?? 1000;
            $h = $data['canvas']['height'] ?? 640;
            if (! is_numeric($w) || (int) $w < 100 || (int) $w > 8000) {
                $errors[] = 'canvas.width must be between 100 and 8000.';
            }
            if (! is_numeric($h) || (int) $h < 100 || (int) $h > 8000) {
                $errors[] = 'canvas.height must be between 100 and 8000.';
            }
            if (isset($data['canvas']['bgColor']) && ! self::isValidColor($data['canvas']['bgColor'])) {
                $errors[] = 'canvas.bgColor must be a valid hex color (#RGB or #RRGGBB).';
            }
        }

        $nodeIds = [];
        $nodes = $data['nodes'] ?? [];
        if (! is_array($nodes)) {
            $errors[] = 'nodes must be an array.';
        } else {
            foreach ($nodes as $idx => $node) {
                if (! is_array($node)) {
                    $errors[] = "nodes[$idx] must be an object.";

                    continue;
                }
                $id = $node['id'] ?? null;
                if (! is_string($id) || $id === '' || strlen($id) > 128) {
                    $errors[] = "nodes[$idx].id must be a non-empty string up to 128 characters.";
                } elseif (isset($nodeIds[$id])) {
                    $errors[] = "Duplicate node id '$id'.";
                } else {
                    $nodeIds[$id] = true;
                }

                if (isset($node['infoUrl']) && $node['infoUrl'] !== '' && ! self::isSafeUrl($node['infoUrl'])) {
                    $errors[] = "nodes[$idx].infoUrl must be an http(s) URL or relative path.";
                }
            }
        }

        $linkIds = [];
        $links = $data['links'] ?? [];
        if (! is_array($links)) {
            $errors[] = 'links must be an array.';
        } else {
            foreach ($links as $idx => $link) {
                if (! is_array($link)) {
                    $errors[] = "links[$idx] must be an object.";

                    continue;
                }
                $id = $link['id'] ?? null;
                if (! is_string($id) || $id === '' || strlen($id) > 128) {
                    $errors[] = "links[$idx].id must be a non-empty string up to 128 characters.";
                } elseif (isset($linkIds[$id])) {
                    $errors[] = "Duplicate link id '$id'.";
                } else {
                    $linkIds[$id] = true;
                }

                $nodeA = is_array($link['a'] ?? null) ? ($link['a']['nodeId'] ?? null) : null;
                $nodeB = is_array($link['b'] ?? null) ? ($link['b']['nodeId'] ?? null) : null;
                if (! is_string($nodeA) || ! isset($nodeIds[$nodeA])) {
                    $errors[] = "links[$idx].a.nodeId references unknown node.";
                }
                if (! is_string($nodeB) || ! isset($nodeIds[$nodeB])) {
                    $errors[] = "links[$idx].b.nodeId references unknown node.";
                }
                if (is_string($nodeA) && $nodeA === $nodeB) {
                    $errors[] = "links[$idx] cannot connect a node to itself without distinct endpoints.";
                }

                if (isset($link['infoUrl']) && $link['infoUrl'] !== '' && ! self::isSafeUrl($link['infoUrl'])) {
                    $errors[] = "links[$idx].infoUrl must be an http(s) URL or relative path.";
                }
                if (isset($link['staticLabelBgColor']) && ! self::isValidColor($link['staticLabelBgColor'])) {
                    $errors[] = "links[$idx].staticLabelBgColor must be a valid hex color (#RGB or #RRGGBB).";
                }
                if (isset($link['staticLabelTextColor']) && ! self::isValidColor($link['staticLabelTextColor'])) {
                    $errors[] = "links[$idx].staticLabelTextColor must be a valid hex color (#RGB or #RRGGBB).";
                }

                if (isset($link['target']) && is_array($link['target']) && is_array($link['target']['terms'] ?? null)) {
                    foreach ($link['target']['terms'] as $tIdx => $term) {
                        if (! is_array($term)) {
                            $errors[] = "links[$idx].target.terms[$tIdx] must be an object.";

                            continue;
                        }
                        if (($term['source'] ?? null) === TargetTerm::SOURCE_RRD) {
                            $path = $term['rrdPath'] ?? '';
                            if (! is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
                                $errors[] = "links[$idx].target.terms[$tIdx].rrdPath must be a safe relative path.";
                            }
                        }
                    }
                }
            }
        }

        if (array_key_exists('annotations', $data)) {
            $annotations = $data['annotations'];
            if (! is_array($annotations)) {
                $errors[] = 'annotations must be an array.';
            } else {
                $annotationIds = [];
                foreach ($annotations as $idx => $annotation) {
                    if (! is_array($annotation)) {
                        $errors[] = "annotations[$idx] must be an object.";

                        continue;
                    }
                    $id = $annotation['id'] ?? null;
                    if (! is_string($id) || $id === '' || strlen($id) > 128) {
                        $errors[] = "annotations[$idx].id must be a non-empty string up to 128 characters.";
                    } elseif (isset($annotationIds[$id])) {
                        $errors[] = "Duplicate annotation id '$id'.";
                    } else {
                        $annotationIds[$id] = true;
                    }

                    if (isset($annotation['textColor']) && ! self::isValidColor($annotation['textColor'])) {
                        $errors[] = "annotations[$idx].textColor must be a valid hex color (#RGB or #RRGGBB).";
                    }
                    if (isset($annotation['bgColor']) && ! self::isValidColor($annotation['bgColor'])) {
                        $errors[] = "annotations[$idx].bgColor must be a valid hex color (#RGB or #RRGGBB).";
                    }
                    if (isset($annotation['borderColor']) && ! self::isValidColor($annotation['borderColor'])) {
                        $errors[] = "annotations[$idx].borderColor must be a valid hex color (#RGB or #RRGGBB).";
                    }
                    if (isset($annotation['infoUrl']) && $annotation['infoUrl'] !== '' && ! self::isSafeUrl($annotation['infoUrl'])) {
                        $errors[] = "annotations[$idx].infoUrl must be an http(s) URL or relative path.";
                    }
                }
            }
        }

        return $errors;
    }

    public static function isValidColor(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
    }

    public static function isSafeUrl(mixed $url): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $trimmed = trim($url);
        if ($trimmed === '') {
            return true;
        }
        if (str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '//')) {
            return true;
        }
        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
