<?php

namespace App\Services;

class LogQlBuilder
{
    /**
     * Build a LogQL query from validated dashboard filters. Users never supply
     * raw LogQL; every value is escaped into quoted matchers.
     *
     * @param  array<int, int>  $serverIds
     * @param  array<int, string>  $sources
     */
    public function build(array $serverIds, array $sources, ?string $search): string
    {
        $matchers = ['server_id=~"'.implode('|', $serverIds).'"'];

        if ($sources !== []) {
            $matchers[] = 'source=~"'.$this->escape(implode('|', array_map($this->escapeRegexAlternative(...), $sources))).'"';
        }

        $query = '{'.implode(', ', $matchers).'}';

        if ($search !== null && $search !== '') {
            $query .= ' |= "'.$this->escape($search).'"';
        }

        return $query;
    }

    /**
     * Escape for a double-quoted LogQL string.
     */
    protected function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Keep regex alternation safe for literal source names.
     */
    protected function escapeRegexAlternative(string $value): string
    {
        return preg_quote($value);
    }
}
