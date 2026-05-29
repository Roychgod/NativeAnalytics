<?php namespace ProcessWire;

/**
 * Escape MySQL LIKE wildcards (% and _) and the escape char itself so a
 * user-supplied search term matches literally inside a `... LIKE %term%` clause.
 * Backslash must be escaped first so escapes added for % and _ are not doubled.
 */
if(!function_exists('ProcessWire\\pwna_escape_like_term')) {
    function pwna_escape_like_term($term) {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term);
    }
}
