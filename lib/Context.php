<?php

namespace Lum\Router;

/**
 * A routing context object. Sent to controllers.
 */
class Context implements \ArrayAccess
{
  use \Lum\Meta\SetProps;

  const ROUTER_METHODS =
  [
    'requestUri', 'requestPaths', 'isJSON', 'isXML', 'isContentType',
    'contentType', 'accept', 'accepts', 'acceptsJSON', 'acceptsXML',
    'isHTML', 'acceptsHTML',
  ];

  public $router;              // The router object.
  public $route;               // The route object.
  public $uri            = []; // The URI string (without query string.)
  public $path           = []; // The URI path elements.
  public $request_params = []; // The $_REQUEST, $_GET or $_POST data.
  public $path_params    = []; // Parameters specified in the URI.
  public $body_params    = []; // Params found in a JSON body, if applicable.
  public $body_text;           // Body text (currently XML is supported.)
  public $method;              // The HTTP method used.
  public $files;               // Any files uploaded.
  public $offset_files = true; // Include files in offset* methods.
  public $remote_ip;

  public function __construct ($opts=[])
  {
    $this->set_props($opts);
  }

  // Convert this into a simple array structure.
  public function to_array ($opts=[])
  {
    $array =  $this->path_params + $this->body_params + $this->request_params;
    if (isset($opts['files']) && $opts['files'] && isset($this->files))
      $array = $array + $this->files;
    $array['_context'] = $this;
    return $array;
  }

  public function offsetGet ($offset): mixed
  {
    if (array_key_exists($offset, $this->path_params))
    {
      return $this->path_params[$offset];
    }
    elseif (array_key_exists($offset, $this->body_params))
    {
      return $this->body_params[$offset];
    }
    elseif (array_key_exists($offset, $this->request_params))
    {
      return $this->request_params[$offset];
    }
    elseif ($this->offset_files && isset($this->files, $this->files[$offset]))
    {
      return $this->getFile($offset);
    }
    else
    {
      return Null;
    }
  }

  public function offsetSet ($offset, $value): void
  {
    throw new Exception ("Context parameters are read only.");
  }

  public function offsetExists ($offset): bool
  {
    if (array_key_exists($offset, $this->path_params))
    {
      return True;
    }
    elseif (array_key_exists($offset, $this->body_params))
    {
      return True;
    }
    elseif (array_key_exists($offset, $this->request_params))
    {
      return True;
    }
    elseif ($this->offset_files && \Lum\File::hasUpload($offset, $this))
    {
      return True;
    }
    else
    {
      return False;
    }
  }

  public function offsetUnset ($offset): void
  {
    throw new Exception ("Cannot unset a context parameter.");
  }

  public function getFile ($name)
  {
    return \Lum\File::getUpload($name, $this);
  }

  public function jsonBody ()
  {
    if ($this->router->isJSON())
    {
      return $this->body_params;
    }
  }

  public function xmlBody ()
  {
    if ($this->router->isXML())
    {
      return $this->body_text;
    }
  }

  // Add a handful of Router methods to the RouteContext objects.
  public function __call($name, $args)
  {
    if (isset($this->router) && in_array($name, static::ROUTER_METHODS))
    { // There's a valid Router and callable method in it, go there.
      return $this->router->$name(...$args);
      
      //// return call_user_func_array([$this->router, $name], $args);
    }
    else
    {
      throw new Exception("No such method '$name' in RouteContext");
    }
  }

}
