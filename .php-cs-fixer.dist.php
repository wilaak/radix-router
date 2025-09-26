<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'native_function_invocation' => ['include' => ['@all'], 'strict' => true],
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'phpdoc_align' => ['align' => 'left'],
        'declare_strict_types' => true,
        'no_superfluous_phpdoc_tags' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'no_extra_blank_lines' => true,
        'no_whitespace_in_blank_line' => true
    ])
    ->setFinder($finder);
