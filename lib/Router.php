<?php

namespace Lum\Router;

use Lum\Web;

/**
 * Routing Dispatcher.
 * 
 * Matches routes based on rules.
 */
class Router extends Info
{
  use Router\Debugging; /** Will become optional in version 2.0 */

  protected $routes = [];  // A flat list of routes.
  protected $named  = [];  // Any named routes, for reverse generation.
  protected $default;      // The default route, must be explicitly set.

  protected $plugins = []; // Router plugin functions.

  public $route_methods = 
  [
    'GET','POST','PUT','DELETE','HEAD','PATCH','OPTIONS'
  ];

  public $log   = False;   // Basic logging, tracks routing.
  // Advanced debugging log settings.
  public $debug =
  [
    'init'     => 0,
    'matching' => 0,
    'routing'  => 0,
    'building' => 0,
  ];

  public $default_filter = Vars::DEFAULT_FILTER;

  public $default_placeholder = Vars::MODERN_PLACEHOLDER;

  public $current; // The most recently matched route context.

  public function known_routes ($showAll=false)
  {
    if ($showAll)
      return $this->routes;
    else
      return array_keys($this->named);
  }

  public function __construct ($opts=[])
  {
    parent::__construct($opts);

    $this->set_props($opts, true, 
    [
      'default_filter',
      'default_placeholder',
    ]);
  }

  /**
   * Add a route
   */
  public function add ($route, $is_default=False, $add_it=True, $chain=null)
  {
    if ($this->log && $this->debug['init'] > 0)
    {
      $msg = 'Router::add(';
      if ($this->debug['init'] > 1)
        $msg .= json_encode($route);
      elseif (isset($route->name))
        $msg .= json_encode($route->name);
      else
        $msg .= json_encode($route->uri);
      $msg .= ', ' . json_encode($is_default)
        . ', ' . json_encode($add_it);
      if ($this->debug['init'] > 1)
        $msg .= ', ' . json_encode($chain);
      $msg .= ')';
      error_log($msg);
    }

    if ($route instanceof Route)
    { // It's a route object.

      // Ensure proper parentage.
      $route->parent = $this;

      // Add it to our list of routes.
      if ($add_it)
        $this->routes[] = $route;

      /// Handle named routes.
      if (isset($route->name) && !isset($this->named[$route->name]))
        $this->named[$route->name] = $route;

      // Handle the default route.
      if ($is_default)
        $this->default = $route;

      if (isset($chain) && is_array($chain))
      {
        if ($this->log && $this->debug['init'] > 1)
          error_log(" :chaining => ".$route->uri);
        $this->load($chain, $route);
      }

      return $route;
    }
    elseif (is_array($route))
    { // It's options for constructing a route.
      if (isset($route['plugin']))
      {
        $plugin = $route['plugin'];
        if (isset($this->plugins[$plugin]))
        {
          $plugin = $this->plugins[$plugin];
          return $plugin($this, $route, $is_default, $add_it, $chain);
        }
      }
      $route = new Route($route);
      return $this->add($route, $is_default, $add_it, $chain); // magical recursion.
    }
    elseif (is_string($route))
    {
      if (isset($this->plugins[$route]))
      { // A plugin, let's do this!
        $plugin = $this->plugins[$route];
        return $plugin($this, $is_default, $add_it, $chain, null);
      }

      $ropts = ['uri' => $route];      
      if (is_bool($is_default))
      { // Assume the first parameter is the controller, and that the
        // URI is the same as the controller name (but with slashes.)
        $ropts['controller'] = $route;
        $ropts['name']       = $route;
        $ropts['uri']        = "/$route/";
      }
      elseif (is_array($is_default))
      { // Both controller and action specified.
        $ropts['controller'] = $ctrl   = $is_default[0];
        $ropts['action']     = $action = $is_default[1];
        $ropts['name'] = $ctrl.'_'.preg_replace('/^handle_/', '', $action);
      }
      elseif (is_string($is_default))
      { // Just a controller specified.
        $ropts['controller'] = $ropts['name'] = $is_default;
      }
      else
      { // What did you send?
        throw new Exception("Invalid controller specified in Route::add()");
      }

      // If the third parameter is a string or array, it's allowed methods.
      if (!is_bool($add_it))
      {
        if (is_string($add_it) && in_array($add_it, $this->route_methods))
        { // It's an HTTP method.
          $ropts['methods'] = [$add_it];
        }
        elseif (is_array($add_it) && in_array($add_it[0], $this->route_methods))
        { // It's a list of route methods.
          $ropts['methods'] = $add_it;
        }
        $add_it = true;
      }

      // Okay, build the route, and add it.
      $route = new Route($ropts);
      return $this->add($route, false, $add_it, $chain);
    }
    else
    {
      throw new Exception("Unrecognized route sent to Router::add()");
    }
  }

  public function addPlugin ($name, $function)
  {
    if (is_callable($function))
    {
      $this->plugins[$name] = $function;
    }
    else
    {
      throw new Exception("Invalid plugin '$name' passed to Router.");
    }
  }

  public function initDebugging ()
  { // Compatibility with the old debug values.
    if (is_int($this->debug))
    {
      $debug_r = $this->debug;
      $debug_o = $debug_r - 1;
      $this->debug =
      [
        'init'     => $debug_o,
        'matching' => $debug_o,
        'routing'  => $debug_r,
        'building' => $debug_o,
      ];
    }
  }

  public function load (Array $routes, $parent=null)
  {
    $this->initDebugging();
    if ($this->log && $this->debug['init'] > 1)
    {
      $msg = 'load('.json_encode($routes);
      if (isset($parent))
        $msg .= ', '.$parent->uri;
      $msg .= ')';
      error_log($msg);
    }

    if (!isset($parent))
      $parent = $this;
    foreach ($routes as $route)
    {
      if (is_array($route) && isset($route[0]))
      {
        if (is_array($route[0]) && isset($route[0][0]))
        { // Nested route.
          $topdef = array_shift($route);
          $toproute = call_user_func_array([$parent,'add'], $topdef);
          $this->load($route, $toproute);
        }
        else
        { // Single def.
          $return = call_user_func_array([$parent, 'add'], $route);
          if ($return && $parent instanceof Route && $return !== $parent)
          {
            $parent = $return;
          }
        }
      }
    }
  }

  /**
   * Set a default controller. This will not be checked in the
   * normal route test, and will only be used if no other routes matched.
   */
  public function setDefault ($route)
  {
    if ($route instanceof Route)
    {
      return $this->add($route, True, False);
    }
    elseif (is_array($route))
    { // It's options for constructing a route.
      $route = new Route($route);
      return $this->add($route, True, False); // magical recursion.
    }
    elseif (is_string($route))
    { // Expects a controller with the handle_default() method.
      $route = new Route(
      [
        'controller' => $route,
        'name'       => $route,
      ]);
      return $this->add($route, True, False);
    }
  }

  /**
   * Add a redirect rule.
   */
  public function redirect ($from_uri, $to_uri, $opts=[])
  {
    $short   = isset($opts['short'])   ? $opts['short']   : False;
    $default = isset($opts['default']) ? $opts['default'] : False;
    $isroute = isset($opts['route'])   ? $opts['route']   : False;

    // Determine the appropriate target based on the 'short' option.
    $target = $short ? $to_uri : $this->base_uri . $to_uri;

    $this->add(
    [
      'uri'               => $from_uri,
      'redirect'          => $target,
      'redirect_is_route' => $isroute,
    ], $default);
  }

  /**
   * Display a view without an underlying controller.
   */
  public function display ($path, $view, $is_default=False)
  {
    $def = ['uri'=>$path];
    if (is_array($view))
    {
      $def['view_loader'] = $view[0];
      $def['view']        = $view[1];
    }
    else
    {
      $def['view'] = $view;
    }
    $this->add($def, $is_default);
  }

  /**
   * See if we can match a route against a URI and method.
   *
   * Returns a RouteContext object.
   *
   * If there is no default controller specified, and no route matches,
   * it will return nothing (void).
   */
  public function match ($uri=Null, $method=Null)
  {
    if (is_null($uri))
    {
      $uri = $this->requestUri();
    }

    if (is_null($method))
    { // Use the current request method.
      $method = $_SERVER['REQUEST_METHOD'];
    }
    else
    { // Force uppercase.
      $method = strtoupper($method);
    }

    $path = explode('/', $uri);

    // Common opts we'll include in any RouteContext object.
    $contextOpts =
    [
      'uri'    => $uri,
      'path'   => $path,
      'method' => $method,
    ];

    foreach ($this->routes as $route)
    {
      $routeinfo = $route->match($uri, $method);

      if (isset($routeinfo))
      { // We found a matching route.

        $contextOpts['route']       = $route;
        $contextOpts['path_params'] = $routeinfo; 
        return $this->getContext($contextOpts);

      } // if ($routeinfo)
    } // foreach ($routes)

    // If we reached here, no matching route was found.
    // Let's send the default route.
    if (isset($this->default))
    {
      $contextOpts['route'] = $this->default;
      return $this->getContext($contextOpts);
    }
  } // function match()

  /**
   * The primary frontend function for starting the routing.
   */
  public function route ($uri=Null, $method=Null)
  {
    $core = \Lum\Core::getInstance();
    $context = $this->match($uri, $method);
    if (isset($context))
    { // We found a match.
      $this->current = $context;

      $route = $context->route;
      if ($this->log && $route->name)
        error_log("Dispatching to {$route->name}");

      if ($this->log && $this->debug['routing'] > 0)
        error_log(" :ip => ".$context->remote_ip);

      if ($route->redirect)
      { // Whether we redirect to a URL, or go to a known route,
        // depends on the redirect_is_route setting.
        if ($this->log && $this->debug['routing'] > 0)
          error_log(" :redirect => ".$route->redirect);
        if ($route->redirect_is_route)
        {
          $this->go($route->redirect, $context->path_params);
        }
        else
        {
          Web\Url::redirect($route->redirect);
        }
      }
      elseif ($route->view)
      { // We're loading a view.
        if ($this->log && $this->debug['routing'] > 0)
          error_log(" :view => ".$route->view);
        if (isset($route->view_status))
        {
          http_response_code($route->view_status);
        }
        $loader = $route->view_loader;
        return $core->$loader->load($route->view, $context->to_array());
      }
      elseif ($route->controller)
      {
        if ($this->log && $this->debug['routing'] > 0)
          error_log(" :controller => ".$route->controller);
        // We consider it a fatal error if the controller doesn't exist.
        $controller = $core->controllers->load($route->controller);

        if (is_callable([$controller, 'init_route']))
        {
          $controller->init_route($context);
        }

        $action = $route->action;
        if (is_callable([$controller, $action]))
        {
          if ($this->log && $this->debug['routing'] > 0)
            error_log(" :action => $action");
          return $controller->$action($context);
        }
        else
        {
          throw new Exception("Controller action $action not found.");
        }
      }
      else
      {
        throw new Exception("Invalid Route definition.");
      }
    }
    else
    {
      throw new Exception("No route matched, and no default controller set.");
    }
  }

  /**
   * Build a URI for a named route.
   */
  public function build ($routeName, $params=[], $opts=[])
  {
    if ($this->log && $this->debug['building'] > 1)
    {
      $call = "Router::build($routeName";
      if ($this->debug > 2) 
        $call .= ", " .
          json_encode($params) . ", " .
          json_encode($opts);
      $call .= ")";
      error_log($call);
    }

    if (!isset($this->named[$routeName]))
      throw new 
        Exception("No named route '$routeName' in call to Router::build()");

    unset($opts['fulluri']); // Keep our sanity.
    $route_uri = $this->named[$routeName]->build($params, $opts);
    if (isset($opts['short']) && $opts['short'])
      return $route_uri;
    else
      return $this->base_uri . $route_uri;
  }

  /**
   * Redirect the browser to a known route, with the appropriate parameters.
   */
  public function go ($routeName, $params=[], $ropts=[], $bopts=[]): never
  {
    if ($this->log && $this->debug['routing'] > 1)
    {
      $call = "Router::go($routeName";
      if ($this->debug > 2)
      {
        $call .= ', '
        . json_encode($params) . ', '
        . json_encode($ropts)  . ', '
        . json_encode($bopts);
      }
      $call .= ')';
      error_log($call);
    }
    $uri  = $this->build($routeName, $params, $bopts);
    Web\Url::redirect($uri, $ropts);
  }

  /**
   * Check to see if we know about a named route.
   */
  public function has ($routeName)
  {
    return isset($this->named[$routeName]);
  }

  /**
   * Get a named route.
   */
  public function get_route ($routeName)
  {
    if (isset($this->named[$routeName]))
      return $this->named[$routeName];
  }

}
