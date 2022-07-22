<?php

namespace Lum\Router;

use Lum\Web;

/**
 * Routing Dispatcher.
 * 
 * Matches routes based on rules.
 */
class Router
{
  use \Lum\Meta\SetProps;

  const JSON_TYPE = 'application/json';
  const XML_TYPE  = 'application/xml';
  const HTML_TYPE = 'text/html';

  const FORM_URLENC = "application/x-www-form-urlencoded";
  const FORM_DATA   = "multipart/form-data";

  protected $routes = [];  // A flat list of routes.
  protected $named  = [];  // Any named routes, for reverse generation.
  protected $default;      // The default route, must be explicitly set.

  protected $plugins = []; // Router plugin functions.

  public $route_methods = 
  [
    'GET','POST','PUT','DELETE','HEAD','PATCH','OPTIONS'
  ];

  public $base_uri = '';

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

  public $populate_put_files = false; // PUT files added to _FILES global.

  public $populate_put_global = false; // Add _PUT global.

  /**
   * The default `$noHTML` value for the `acceptsXML()` method.
   */
  public $xml_excludes_html = true;

  public function known_routes ($showAll=false)
  {
    if ($showAll)
      return $this->routes;
    else
      return array_keys($this->named);
  }

  public function __construct ($opts=[])
  {
    if (isset($opts['base_uri']))
    {
      $this->base_uri($opts['base_uri']);
    }
    elseif (isset($opts['auto_prefix']) && $opts['auto_prefix'])
    {
      $this->auto_prefix();
    }

    $this->set_props($opts, true, 
    [
      'populate_put_files',
      'populate_put_global',
      'default_filter',
      'default_placeholder',
      'xml_excludes_html',
    ]);
  }

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

  /**
   * Set the base_uri.
   */
  public function base_uri ($newval=Null)
  {
    if (isset($newval))
    {
      $this->base_uri = rtrim($newval, "/");
    }
    return $this->base_uri;
  }

  /**
   * Automatically set the URL prefix based on our SCRIPT_NAME.
   */
  public function auto_prefix ()
  {
    $dir = dirname($_SERVER['SCRIPT_NAME']);
    $this->base_uri($dir);
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

  public function requestUri($stripBase=false, $trimSlashes=false)
  {
    $uri = $_SERVER['REQUEST_URI'];
    if (($pos = strpos($uri, '?')) !== False)
    {
      $uri = substr($uri, 0, $pos);
    }
    if ($stripBase && trim($this->base_uri) != '')
    {
      $uri = str_replace($this->base_uri, '', $uri);
    }
    if ($trimSlashes)
    {
      $uri = trim($uri, '/');
    }
    return $uri;
  }

  public function requestPaths($stripBase=false, $trimSlashes=false)
  {
    return explode('/', $this->requestUri($stripBase, $trimSlashes));
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

  public function getContext($opts=[])
  {
    $files = $request = null;
    $ct = $this->contentType();

    if (isset($opts['path']))
    { 
      if (is_string($opts['path']))
      { // The URI was set in the place of the path.
        if (!isset($opts['path']))
          $opts['uri'] = $opts['path'];
        $opts['path'] = explode('/', $opts['uri']);
      }
      elseif (!is_array($opts['path']))
      { // It was boolean or something else, use requestPaths().
        $opts['path'] = $this->requestPaths();
      }
    }

    if (!isset($opts['method']))
    { // Wasn't set, use the current request method.
      $opts['method'] = $_SERVER['REQUEST_METHOD'];
    }

    $method = $opts['method'];

    if ($method == 'PUT' && 
      ($ct == static::FORM_URLENC || $ct == static::FORM_DATA))
    { // PUT is handled different by PHP, thanks guys.
      $body = file_get_contents("php://input");

      if ($ct == static::FORM_URLENC)
      { // At least they made this somewhat easy.
        parse_str($body, $request);
      }
      else
      { // This on the other hand...
        list($request, $files) = $this->parse_multipart($body);
        if ($this->populate_put_global)
        {
          $GLOBALS['_PUT'] = $request;
        }
        if ($this->populate_put_files)
        {
          foreach ($files as $name => $spec)
          {
            if (!isset($_FILES[$name]))
              $_FILES[$name] = $spec;
          }
        }
      }
    }
    elseif (isset($opts['route']) && $opts['route']->strict)
    { // Strict-mode.
      if ($method == 'GET' && isset($_GET))
      {
        $request = $_GET;
      }
      elseif ($method == 'POST' && isset($_POST))
      {
        $request = $_POST;
        $files   = $_FILES;
      }
      else
      {
        $request = $_REQUEST;
        $files   = $_FILES;
      }
    }
    else
    {
      $request = $_REQUEST;
      $files   = $_FILES;
    }

    if ($this->isJSON())
    { // Add the JSON body params.
      $opts['body_params'] = 
        json_decode(file_get_contents("php://input"), true);
    }

    if ($this->isXML())
    {
      $opts['body_text'] = file_get_contents("php://input");
    }

    $opts = array_merge($opts,
    [
      'router'         => $this,
      'request_params' => $request,
      'files'          => $files,
      'remote_ip'      => $_SERVER['REMOTE_ADDR'],
    ]);

    $context = new Context($opts);

    return $context;
  }

  public function parse_multipart ($raw_body)
  {
    $data = $files = [];
    $boundary = substr($raw_body, 0, strpos($raw_body, "\r\n"));
    if (empty($boundary))
    { // No boundary, parse as x-www-form-urlencoded instead.
      parse_str($raw_body, $data);
      return [$data, $files];
    }

    // There was a boundary, let's get the parts.
    $parts = array_splice(explode($boundary, $raw_body), 1);

    foreach($parts as $part)
    {
      if ($part == "--\r\n") break; // last part.
      $part = ltrim($part, "\r\n");
      list($raw_headers, $body) = explode("\r\n\r\n", $part, 2);

      // Parse the headers.
      $raw_headers = explode("\r\n", $raw_headers);
      $headers = [];
      foreach ($raw_headers as $header)
      {
        list($name, $value) = explode(':', $header);
        $headers[strtolower($name)] = ltrim($value, ' ');
      }
      if (isset($headers['content-disposition']))
      { // Let's parse this as either a file or a form value.
        $filename = $tmp_name = null;
        preg_match(
          '/^(.+); *name="([^"]+)"(; *filename="([^"]+")?/',
          $headers['content-disposition'],
          $matches
        );
        list(,$ftype, $name) = $matches;
        if (isset($matches[4]))
        { // It's a file.
          if (isset($files[$name])) continue; // skip duplicates.
          $filename = $matches[4];
          $filename_parts = pathinfo($filename);
          $output = $filename_parts['filename'];
          $tmp_name = tempnam(ini_get('upload_tmp_dir'), $output);

          if (isset($headers['content-type']))
            $type = strtolower($headers['content-type']);
          else
            $type = $ftype;

          $files[$name] =
          [
            'error'    => 0,
            'name'     => $filename,
            'tmp_name' => $tmp_name,
            'size'     => strlen($body),
            'type'     => $type,
          ];

          file_put_contents($tmp_name, $body);
        }
        else
        { // It's not a file, add it to the data.
          $data[$name] = substr($body, 0, strlen($body) - 2);
        }
      }
    }
    return [$data, $files];
  }

  public function isJSON ()
  {
    return $this->isContentType(static::JSON_TYPE, false);
  }

  public function isXML ()
  {
    return $this->isContentType(static::XML_TYPE, false);
  }

  public function isHTML ()
  {
    return $this->isContentType(static::HTML_TYPE, false);
  }

  public function isContentType ($wanttype, $forcelc=true)
  {
    if ($forcelc)
      $wanttype = strtolower($wanttype);
    $havetype = $this->contentType(false);
    return ($wanttype == $havetype);
  }

  public function contentType ($withOpts=false)
  {
    $ctypedef = explode(';', $_SERVER['CONTENT_TYPE']);
    $ctype = strtolower(array_shift($ctypedef));
    if ($withOpts)
    {
      $opts = [];
      foreach ($ctypedef as $optstr)
      {
        $optdef  = explode('=', $optstr, 2);
        // the option name should be in lowercase.
        $optname = strtolower($optdef[0]);
        // strip whitespace and " characters from the values.
        $optval  = trim($optdef[1], " \t\n\r\0\x0B\"");
        $opts[$optname] = $optval;
      }
      return [$ctype, $opts];
    }
    return $ctype;
  }

  /**
   * Return the accept header itself.
   */
  public function accept ()
  {
    return strtolower($_SERVER['HTTP_ACCEPT']);
  }

  /**
   * Get a list of `Accept` headers, or test if we accept a type.
   *
   * @param string|array|null $mimeTypes  Test for the acceptance of this.
   *
   *   If `null` we aren't testing for any types.
   *   If a `string` we're testing for a single mime type.
   *   If an `array` we are testing a bunch of mime types.
   *
   * @return array|bool|string|null  The output depends on `$mimeTypes`.
   *
   *   If `$mimeTypes` was `null` this will return an `array` where the
   *   key is the mime type, and the value is the weight (defaults to 1
   *   if there was no ;q={weight} portion in the header.)
   *
   *   If `$mimeTypes` was a `string` this will return a `bool` indicating
   *   if that single mime type was in the `Accept` header.
   *
   *   If `$mimeTypes` was an `array` this will either return a string
   *   representing the first matching mime type found, or `null` indicating
   *   no mime type matched.
   *
   */
  public function accepts ($mimeTypes=null)
  {
    // No header, return null.
    if (!isset($_SERVER['HTTP_ACCEPT'])) return null;

    $acceptTypes = [];
    $acceptRaw = strtolower(str_replace(' ', '', $_SERVER['HTTP_ACCEPT']));
    $acceptRaw = explode(',', $acceptRaw);

    foreach ($acceptRaw as $a)
    {
      $q = 1;
      if (strpos($a, ';q='))
      {
        list($a, $q) = explode(';q=', $a);
      }
      $acceptTypes[$a] = $q;
    }
    arsort($acceptTypes);
    
    // No desired mime type(s), return the full list.
    if (!$mimeTypes) return $acceptTypes;

    if (is_string($mimeTypes))
    { // Search for a single mime type.
      $mimeTypes = strtolower($mimeTypes);
      foreach ($acceptTypes as $mime => $q)
      {
        if ($q && $mimeTypes == $mime) return true; // was found, return true.
      }
      // String wasn't found, return false.
      return false;
    }

    // Search for one of several mime types.    
    $mimeTypes = array_map('strtolower', (array)$mimeTypes);
    
    foreach  ($acceptTypes as $mime => $q)
    {
      if ($q && in_array($mime, $mimeTypes)) return $mime;
    }

    // Nothing matched.
    return null;
  }
  
  /**
   * We accept JSON
   */
  public function acceptsJSON ()
  {
    return $this->accepts(static::JSON_TYPE);
  }

  /**
   * Check if we accept XML.
   *
   * @param bool $noHTML  (Optional) How to handle HTML.
   *
   *   If this is `true` then if the `acceptsHTML()` method returns
   *   true, this will return false.
   *
   *   If this is `false` we don't care whether or not HTML is accepted,
   *   and will simply check for the application/xml in the Accepts header.
   *
   *   The default value is {@see \Lum\Plugins\Router::$xml_excludes_html}
   *
   * @return bool  If we accept XML.
   */
  public function acceptsXML ($noHTML=null)
  {
    if (is_null($noHTML)) $noHTML = $this->xml_excludes_html;
    if ($noHTML && $this->acceptsHTML())
    { // HTML was not allowed, but was found. Bye bye.
      return false;
    } 
    return $this->accepts(static::XML_TYPE);
  }

  /**
   * Check if we accept HTML.
   *
   * This is using the standard `text/html` that every modern browser includes
   * in their default `Accept` header when requesting a page.
   *
   * @return bool  If we accept HTML.
   */
  public function acceptsHTML()
  {
    return $this->accepts(static::HTML_TYPE);
  }

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
