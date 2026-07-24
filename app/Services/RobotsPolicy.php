<?php

namespace App\Services;

final class RobotsPolicy
{
    /** @var list<string> */
    private array $disallowed = [];

    /** @var list<string> */
    private array $sitemaps = [];

    public static function fromText(string $robots): self
    {
        $policy = new self();
        $applies = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap' && filter_var($value, FILTER_VALIDATE_URL)) {
                $policy->sitemaps[] = $value;
                continue;
            }

            if ($field === 'user-agent') {
                $applies = $value === '*' || str_contains(strtolower($value), 'maxguard');
                continue;
            }

            if ($applies && $field === 'disallow' && $value !== '') {
                $policy->disallowed[] = $value;
            }
        }

        return $policy;
    }

    public function allows(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        foreach ($this->disallowed as $rule) {
            $pattern = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '#')).'#';
            if (preg_match($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function sitemaps(): array
    {
        return array_values(array_unique($this->sitemaps));
    }
}
