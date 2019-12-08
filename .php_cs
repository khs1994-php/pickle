<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('/build')
    ->exclude('cache')
    ->exclude('vendor')
    ->exclude('node_modules')
    // ->notPath('src/Symfony/Component/Translation/Tests/fixtures/resources.php')
    ->in(__DIR__)
    ->ignoreVCS(true);
return PhpCsFixer\Config::create()
    ->setRules([
      '@Symfony' => true,
      '@Symfony:risky'=>true,
      'array_syntax' => array('syntax' => 'short'),
      'ordered_imports' => true,
      // 'declare_strict_types' => true, // @PHP70Migration:risky, @PHP71Migration:risky
      // 'ternary_to_null_coalescing' => true, // @PHP70Migration, @PHP71Migration
      // 'void_return' => true, // @PHP71Migration:risky
      'visibility_required'=> true,
    ])
    ->setCacheFile(__DIR__.'/.php_cs.cache')
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
