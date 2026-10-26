<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Maps public-site paths (as recorded by the tracker) to the content they show:
 *   /                -> Home        /blog    -> Blog        /contact -> Contact
 *   /blog/{slug}     -> that post   /{slug}  -> that page   anything else -> the path
 * Query strings, fragments and trailing slashes are ignored. All slugs are looked
 * up in a single query, however many paths there are.
 */
class UrlToContent
{
    private const FIXED = ['/' => ['Home', 'home'], '/blog' => ['Blog', 'blog'], '/contact' => ['Contact', 'contact']];

    /** "/blog/hello/?utm_source=x#top" -> "/blog/hello"; "" -> "/". Full URLs work too. */
    public static function normalize(?string $url): string
    {
        $path = (string) (parse_url((string) $url, PHP_URL_PATH) ?? '');
        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : $path;
    }

    /**
     * @param  iterable<string>  $urls  raw or normalised paths
     * @return array<string, array{title: string, type: string, id: int|null}> keyed by normalised path;
     *                                                                          type is post, page, home, blog, contact or other
     */
    public function resolve(iterable $urls): array
    {
        $paths = [];
        foreach ($urls as $url) {
            $paths[self::normalize($url)] = true;
        }

        $postSlugs = $pageSlugs = [];
        foreach (array_keys($paths) as $path) {
            if (isset(self::FIXED[$path])) {
                continue;
            }
            if (preg_match('#^/blog/([^/]+)\z#', $path, $m)) {
                $postSlugs[] = rawurldecode($m[1]);
            } elseif (preg_match('#^/([^/]+)\z#', $path, $m)) {
                $pageSlugs[] = rawurldecode($m[1]);
            }
        }

        $found = $this->lookup($postSlugs, $pageSlugs);

        $result = [];
        foreach (array_keys($paths) as $path) {
            if (isset(self::FIXED[$path])) {
                [$title, $type] = self::FIXED[$path];
                $result[$path] = ['title' => $title, 'type' => $type, 'id' => null];

                continue;
            }

            $key = preg_match('#^/blog/([^/]+)\z#', $path, $m) ? 'post:'.rawurldecode($m[1])
                : (preg_match('#^/([^/]+)\z#', $path, $m) ? 'page:'.rawurldecode($m[1]) : null);
            $match = $key ? ($found[$key] ?? null) : null;

            $result[$path] = $match
                ? ['title' => $match['title'], 'type' => $match['type'], 'id' => $match['id']]
                : ['title' => $path, 'type' => 'other', 'id' => null];
        }

        return $result;
    }

    /** One UNION query for all post and page slugs. @return array<string, array{id: int, title: string, type: string}> */
    private function lookup(array $postSlugs, array $pageSlugs): array
    {
        $queries = [];
        if ($postSlugs) {
            $queries[] = DB::table('posts')->select('id', 'title', 'slug', DB::raw("'post' as type"))->whereIn('slug', array_unique($postSlugs));
        }
        if ($pageSlugs) {
            $queries[] = DB::table('pages')->select('id', 'title', 'slug', DB::raw("'page' as type"))->whereIn('slug', array_unique($pageSlugs));
        }
        if (! $queries) {
            return [];
        }

        $query = array_shift($queries);
        foreach ($queries as $other) {
            $query->unionAll($other);
        }

        $found = [];
        foreach ($query->get() as $row) {
            $found["{$row->type}:{$row->slug}"] = ['id' => (int) $row->id, 'title' => $row->title, 'type' => $row->type];
        }

        return $found;
    }
}
