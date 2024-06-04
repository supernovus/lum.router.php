<?php

namespace Lum\Router\Router;

/**
 * A trait to add debugging via `Lum\Debug` to the Router class.
 * 
 * There is also the original config file based debugging available
 * in the `DebugConfig` trait as an alternative to this.
 */
trait LumDebug
{
  /**
   * Set the debugging information from the Lum Debug plugin.
   * 
   * @param \Lum\Debug $debug - (Optional) A Debug instance.
   *   If not specified, we will make a new one with defaults.
   */
  public function useLumDebug ($debug=null)
  {
    if (is_null($debug))
    {
      $debug = new \Lum\Debug();
    }
    $router_debug =
    [
      'init'     => $debug->get('router.init',     0),
      'matching' => $debug->get('router.matching', 0),
      'routing'  => $debug->get('router.routing',  0),
      'building' => $debug->get('router.building', 0),
    ];
    $this->debug = $router_debug;
  }  
}
