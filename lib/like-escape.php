<?php namespace ProcessWire;

if(!function_exists(__NAMESPACE__ . '\\pwna_escape_like_term')) {
    /**
     * Escape a user-provided term for SQL LIKE conditions using backslash as ESCAPE.
     */
    function pwna_escape_like_term($term) {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $term);
    }
}
