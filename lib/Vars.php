<?php

namespace Lum\Router;

class Vars
{
  const CLASSIC_PLACEHOLDER = "/:([\w-]+)/";     // -> /some/:var/:name
  const MODERN_PLACEHOLDER  = "/\{([\w-]+)\}/";  // -> /some/{var}/{name}

  const DEFAULT_FILTER = "([\w\-\~\.]+)";   // Match common characters.
  const UNSIGNED_INT   = "(\d+)";           // Positive integers.
  const SIGNED_INT     = "([+-]?\d+)";      // Positive and negative integers.

  // TODO: more filter types.

  const PLACEHOLDERS =
  [
    'classic' => self::CLASSIC_PLACEHOLDER,
    'modern'  => self::MODERN_PLACEHOLDER,
  ];

  const FILTERS = 
  [
    'default'  => self::DEFAULT_FILTER,
    'uint'     => self::UNSIGNED_INT,
    'int'      => self::SIGNED_INT,
  ];
}
