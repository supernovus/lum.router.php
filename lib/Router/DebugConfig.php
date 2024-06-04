<?php

namespace Lum\Router\Router;

/**
 * A trait to add debugging via config files to the Router class.
 * 
 * For better integration with other Lum libraries, you may want to use
 * the `LumDebug` trait instead.
 */
trait DebugConfig
{
  /**
   * Set debugging options from a configuration file.
   *
   * Does nothing if the config file doesn't exist.
   *
   * @param string $configfile  The debugging config file.
   */
  public function loadDebugConfig ($configfile)
  {
    if (file_exists($configfile))
    {
      $this->log = true;
      $router_debug = trim(file_get_contents($configfile));
      if (is_numeric($router_debug))
      { // A single numeric value is the old way of debugging.
        // This is very limited, and thus deprecated.
        // The 'routing' will be set to the value, all others will
        // be set to (value-1). Keep that in mind if still using this.
        $router_debug_routing = intval($router_debug);
        $router_debug_other = $router_debug_routing - 1;
        $router_debug =
        [
          'init'     => $router_debug_other,
          'matching' => $router_debug_other,
          'routing'  => $router_debug_routing,
          'building' => $router_debug_other,
        ];
      }
      else
      { // Preferred way to set the desired routing information.
        // Uses the same format as the $core->debug plugin, but only
        // accepts numeric value. Booleans aren't used here.
        $router_debug_def = preg_split("/[\n\,]/", $router_debug);
        $router_debug = 
        [
          'init'     => 0,
          'matching' => 0,
          'routing'  => 0,
          'building' => 0,
        ];
        foreach ($router_debug_def as $router_debug_spec)
        {
          $router_debug_spec = explode('=', trim($router_debug_spec));
          $router_debug_key = trim($router_debug_spec[0]);
          $router_debug_val = intval(trim($router_debug_spec[1]));
          $router_debug[$router_debug_key] = $router_debug_val;
        }
      }
      $this->debug = $router_debug;
    }
  } 
}
