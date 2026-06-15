<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| Search + saved-searches UI strings (Wave 8.1, exercising the framework on the Wave-6.1 surface).
| `:count` / `:term` are Laravel replacement placeholders; |-separated values are pluralisation forms.
*/

return [
    'title' => 'Search',
    'placeholder' => 'Search posts…',
    'submit' => 'Search',
    'filters' => 'Filters',
    'result_word' => 'result|results',
    'results_for' => 'for “:term”',
    'no_match_title' => 'No posts matched your search',
    'no_match_body' => 'Try a different keyword, widen your filters, or check your spelling.',

    // Facets
    'forum' => 'Forum',
    'any_forum' => 'Any forum',
    'author' => 'Author (username)',
    'type' => 'Type',
    'any_post' => 'Any post',
    'opening_only' => 'Opening posts only',
    'from' => 'From',
    'to' => 'To',

    // Saved searches
    'save_this' => 'Save this search',
    'name_this' => 'Name this search',
    'saved' => 'Saved searches',
    'saved_empty' => 'No saved searches yet. Run a search, then use Save this search.',
];
