<?php

namespace Lum\Router;

/**
 * An individual Route.
 */
class Route
{
  use \Lum\Meta\SetProps;

  public $parent;                                    // The Router object.
  public $name;                                      // Optional name.
  public $uri         = '';                          // URI to match.
  public $controller;                                // Target controller.
  public $action      = 'handle_default';            // Target action.
  public $strict      = False;                       // Request data source.
  public $redirect;                                  // If set, we redirect.
  public $view_loader = 'views';                     // Used with 'view'.
  public $view;                                      // A view to load.
  public $view_status;                               // HTTP status override.
  public $content_type;                              // Only certain CT.
  public $is_json     = false;                       // Only JSON content.
  public $is_xml      = false;                       // Only XML content.
  public $accepts;                                   // Only certain Accept.
  public $want_json   = false;                       // Only Accept JSON.
  public $want_xml    = false;                       // Only accept XML.

  public $methods = ['GET','POST'];                  // Supported methods.

  public $redirect_is_route = False;                 // Redirect to a route?

  protected $placeholder_regex;                      // Custom placeholders?
  protected $filters = [];                           // Parameter filters.

  public function __construct ($opts=[])
  {
    $this->set_props($opts);
  }
  
  public function getPlaceholder()
  {
    if (isset($this->placeholder_regex) && is_string($this->placeholder_regex))
    {
      $placeholder = $this->placeholder_regex;
    }
    else
    {
      $placeholder = $this->parent->default_placeholder;
    }

    if (isset(Vars::PLACEHOLDERS[$placeholder]))
    { // It's the name of a pre-defined placeholder class.
      return Vars::PLACEHOLDERS[$placeholder];
    }
    else
    { // Assume it's the placeholder regex itself.
      return $placeholder;
    }
  }

  protected function uri_regex ()
  {
    return preg_replace_callback
    (
      $this->getPlaceholder(), 
      [$this, 'substitute_filter'], 
      $this->uri
    );
  }

  protected function substitute_filter ($matches)
  {
    if (isset($matches, $matches[1]))
    { // There's a variable name.
      if (isset($this->filters[$matches[1]]))
      { // A filter specifically for this variable.
        $filter = $this->filters[$matches[1]];
        if (isset(Vars::FILTERS[$filter]))
        { // It was the name of a pre-defined filter class.
          return Vars::FILTERS[$filter];
        }
        else
        { // Assume it's the filter itself.
          return $filter;
        }
      }
    }
    return $this->parent->default_filter; // The default filter.
  }

  public function match ($uri, $method)
  {
    $debug = $this->parent->log ? $this->parent->debug['matching'] : 0;
    if ($debug > 0)
    {
      error_log("Route[{$this->uri}]::match($uri, $method)");
    }

    if (! in_array($method, $this->methods)) return; // Doesn't match method.

    if ($debug > 1)
      error_log(" -- HTTP method matched.");

    if ($this->is_json && !$this->parent->isJSON()) return; // Not JSON.
    if ($this->is_xml  && !$this->parent->isXML()) return;  // Not XML.
    if ($this->want_json && !$this->parent->acceptsJSON()) return;
    if ($this->want_xml && !$this->parent->acceptsXML()) return;

    if ($debug > 1)
      error_log(" -- is/want tests matched.");

    // If a specific content_type has been specified, make sure it matches.
    if (isset($this->content_type) 
      && !$this->parent->isContentType($this->content_type)) return;

    if ($debug > 1)
      error_log(" -- Content Type matched.");

    // If a specific Accept type has been specified, make sure it matches.
    if (isset($this->accepts)
      && !$this->parent->accepts($this->accepts)) return;

    if ($debug > 1)
      error_log(" -- Accepts matched.");

    $matchUri = $this->uri_regex();
    if ($this->parent->base_uri || $matchUri)
    {
      $match = "@^"
             . $this->parent->base_uri
             . $matchUri
             . "*$@i";

      if ($debug > 2)
        error_log(" :regex => $match");

      if (! preg_match($match, $uri, $matches)) return; // Doesn't match URI.
    }

    if ($debug > 0)
      error_log(" -- Route matched.");

    $params = [];
    $placeholder = $this->getPlaceholder();

    if (preg_match_all($placeholder, $this->uri, $argument_keys))
    {
      $argument_keys = $argument_keys[1];
      foreach ($argument_keys as $key => $name)
      {
        if (isset($matches[$key + 1]))
        {
          $params[$name] = $matches[$key + 1];
        }
      }
    }

    if ($debug > 2)
      error_log(" :params => ".json_encode($params));

    return $params;

  }

  public function build ($params=[], $opts=[])
  {
    $uri = $this->uri;

    $placeholder = $this->getPlaceholder();

    // First, we replace any sent parameters.
    if ($params && preg_match_all($placeholder, $uri, $param_keys))
    {
      $param_keys = $param_keys[1];
      foreach ($param_keys as $key)
      {
        if (isset($params[$key]))
        {
          $uri = preg_replace($placeholder, $params[$key], $uri, 1);
        }
      }
    }

    // Okay, a sanity check. If there are still placeholders, we have
    // a problem, and cannot continue.
    // Pass ['strict'=>False] to make this non-fatal.
    $strict = isset($opts['strict']) ? $opts['strict'] : True;
    if (preg_match_all($placeholder, $uri, $not_found))
    {
      $not_found = $not_found[1];
      $not_found = join(', ', $not_found);
      if ($strict)
      {
        throw new Exception("Route::build() is missing: $not_found");
      }
      else
      {
        return Null;
      }
    }

    if (isset($opts['fulluri']) && $opts['fulluri'])
      $uri = $this->parent->base_uri . $uri;

    return $uri;
  }

  // For chaining Routes.
  public function add ($suburi, $action=Null, $rechain=False, $nestchain=null)
  {
    $ctrl = $this->controller;
    $baseuri = rtrim($this->uri, "/");
    if (is_array($action))
    {
      $ropts = $action;
      $ropts['uri'] = $baseuri . $suburi;
      if (!isset($ropts['controller']))
        $ropts['controller'] = $ctrl;
    }
    elseif (is_string($action))
    { // Specified the action, using our controller and path.
      $ropts =
      [
        'uri'        => $baseuri . $suburi,
        'action'     => $action,
        'name'       => $ctrl . '_' . preg_replace('/^handle_/', '', $action),
        'controller' => $ctrl,
      ];
    }
    else
    { // Action will be 'handle_suburi', don't include the / in the $suburi.
      $ropts =
      [
        'uri'        => "$baseuri/$suburi/",
        'action'     => 'handle_' . $suburi,
        'name'       => $ctrl . '_' . $suburi,
        'controller' => $ctrl,
      ];
    }

    // If the third parameter is a string or array, it's allowed methods.
    if (!is_bool($rechain))
    {
      $meths = $this->parent->route_methods;
      if (is_string($rechain) && in_array($rechain, $meths))
      {
        $ropts['methods'] = [$rechain];
      }
      elseif (is_array($rechain) && in_array($rechain[0], $meths))
      {
        $ropts['methods'] = $rechain;
      }
      // Reset rechain back to a boolean value.
      $rechain = False;
    }

    // Build the sub-route with our compiled options.
    $subroute = new Route($ropts);
    $this->parent->add($subroute, false, true, $nestchain);

    if ($rechain)
      return $subroute;
    else
      return $this;
  }

}