<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2'                                       => true,
        'array_syntax'                                => [
            'syntax' => 'short',
        ],
        'binary_operator_spaces'                      => [
            'default'            => 'align_single_space',
        ],
        'blank_line_after_opening_tag'                => true,
        'blank_line_before_statement'                 => ['statements' => ['return']],
        'braces_position'                             => [
            'allow_single_line_anonymous_functions' => true,
            'allow_single_line_empty_anonymous_classes' => true,
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'same_line',
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace' => 'same_line',
        ],
        'combine_consecutive_unsets'                  => true,
        'concat_space'                                => [
            'spacing' => 'one',
        ],
        'declare_equal_normalize'                     => true,
        'escape_implicit_backslashes'                 => [
            'single_quoted' => true,
            'double_quoted' => true,
        ],
        'function_typehint_space'                     => true,
        'include'                                     => true,
        'lowercase_cast'                              => true,
    //        'class_attributes_separation'                 => ['elements' => ['method']],
        'native_function_casing'                      => true,
        'no_blank_lines_after_phpdoc'                 => true,
        'no_empty_comment'                            => true,
        'no_empty_statement'                          => true,
        'no_mixed_echo_print'                         => [
            'use' => 'echo',
        ],
        'no_multiline_whitespace_around_double_arrow' => true,
        'multiline_whitespace_before_semicolons'      => false,
        'no_short_bool_cast'                          => true,
        'no_singleline_whitespace_before_semicolons'  => true,
        'no_spaces_around_offset'                     => true,
        'no_unused_imports'                           => true,
        'no_whitespace_before_comma_in_array'         => true,
        'no_whitespace_in_blank_line'                 => true,
        'object_operator_without_whitespace'          => true,
        'ordered_imports'                             => true,
        'short_scalar_cast'                           => true,
        'single_blank_line_before_namespace'          => true,
        'single_quote'                                => true,
        'space_after_semicolon'                       => true,
        'ternary_operator_spaces'                     => true,
        'trailing_comma_in_multiline'                 => ['elements' => ['arrays']],
        'trim_array_spaces'                           => true,
        'unary_operator_spaces'                       => true,
        'whitespace_after_comma_in_array'             => true,
    ])
    ->setFinder($finder)
;
