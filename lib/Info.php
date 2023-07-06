<?php

namespace Lum\Router;

use Lum\Web;

/**
 * Routing Information and Proccessing
 * 
 * Parses routing information into useful formats,
 * and provides common convenience methods.
 */
class Info
{
  use \Lum\Meta\SetProps;


  const JSON_TYPE = 'application/json';
  const XML_TYPE  = 'application/xml';
  const HTML_TYPE = 'text/html';

  const FORM_URLENC = "application/x-www-form-urlencoded";
  const FORM_DATA   = "multipart/form-data";

  public string $base_uri = '';

  /**
   * PUT files added to _FILES global?
   * @var bool
   */
  public $populate_put_files = false;

  /**
   * Create _PUT global variable?
   * @var bool
   */
  public $populate_put_global = false;

  /**
   * The default `$noHTML` value for the `acceptsXML()` method.
   */
  public $xml_excludes_html = true;

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
      'xml_excludes_html',
    ]);
  }

  /**
   * Get or set the base_uri.
   */
  public function base_uri (?string $newval=null): string
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

  public function requestUri(bool $stripBase=false, bool $trimSlashes=false): string
  {
    $uri = Web\Url::request_uri(false);
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

  public function requestPaths(bool $stripBase=false, bool $trimSlashes=false): array
  {
    return explode('/', $this->requestUri($stripBase, $trimSlashes));
  }

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
   *   The default value is {@see \Lum\Router\Router::$xml_excludes_html}
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

}
