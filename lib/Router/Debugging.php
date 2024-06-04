<?php

namespace Lum\Router\Router;

/**
 * A meta-trait to add extended debugging support to the Router class.
 * 
 * Includes the following traits:
 * 
 * - `DebugConfig`
 * - `LumDebug`
 * 
 */
trait Debugging
{
  use DebugConfig, LumDebug;
}
