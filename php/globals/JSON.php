<?php
$JSON = call_user_func(function() {

  $decode = function($value) use (&$decode) {
    if ($value === null) {
      return Object::$null;
    }
    $type = gettype($value);
    if ($type === 'integer') {
      return (float)$value;
    }
    if ($type === 'string' || $type === 'boolean' || $type === 'double') {
      return $value;
    }
    if ($type === 'array') {
      $result = new Arr();
      foreach ($value as $item) {
        $result->push($decode($item));
      }
    } else {
      $result = new Object();
      foreach ($value as $key => $item) {
        $result->set($key, $decode($item));
      }
    }
    return $result;
  };

  $escape = function($str) {
    return str_replace("\\/", "/", json_encode($str));
  };

  $encode = function($parent, $key, $value, $opts, $encodeNull = false) use (&$escape, &$encode) {
    if ($value instanceof Object) {
      //todo: flatten boxed primitive (use toJSON?)
      //class may specify its own toJSON (date/buffer)
      if (method_exists($value, 'toJSON')) {
        $value = $value->toJSON();
      } else
      if (($toJSON = $value->get('toJSON')) instanceof Func) {
        $value = $toJSON->call($value);
      } else
      //todo: why do we need this?
      if (($valueOf = $value->get('valueOf')) instanceof Func) {
        $value = $valueOf->call($value);
      }
    }
    if ($value === null) {
      return $encodeNull ? 'null' : $value;
    }
    //todo: handle same as above?
    if ($value === Object::$null || $value === INF || $value === -INF) {
      return 'null';
    }
    $type = gettype($value);
    if ($type === 'boolean') {
      return $value ? 'true' : 'false';
    }
    if ($type === 'integer' || $type === 'double') {
      return ($value !== $value) ? 'null' : $value . '';
    }
    if ($type === 'string') {
      return $escape($value);
    }
    $opts->level += 1;
    $prevGap = $opts->gap;
    if ($opts->gap !== null) {
      $opts->gap .= $opts->indent;
    }
    $result = null;
    if ($opts->replacer instanceof Func) {
      $value = $opts->replacer->call($parent, $key, $value, $opts->level);
    }
    if ($value instanceof Arr) {
      $parts = array();
      $len = $value->length;
      for ($i = 0; $i < $len; $i++) {
        $parts[] = $encode($value, $i, $value->get($i), $opts, true);
      }
      if ($opts->gap === null) {
        $result = '[' . join(',', $parts) . ']';
      } else {
        $result = (count($parts) === 0) ? "[]" :
          "[\n" . $opts->gap . join(",\n" . $opts->gap, $parts) . "\n" . $prevGap . "]";
      }
    }
    if ($result === null) {
      $parts = array();
      foreach ($value->getOwnKeys(true) as $key) {
        $item = $value->get($key);
        if ($item !== null) {
          $parts[] = $escape($key) . ':' . $encode($value, $key, $item, $opts);
        }
      }
      if ($opts->gap === null) {
        $result = '{' . join(',', $parts) . '}';
      } else {
        $result = (count($parts) === 0) ? "{}" :
          "{\n" . $opts->gap . join(",\n" . $opts->gap, $parts) . "\n" . $prevGap . "}";
      }
    }
    $opts->level -= 1;
    $opts->gap = $prevGap;
    return $result;
  };

  $methods = array(
    'parse' => function($this_, $arguments, $string) use(&$decode) {
        $value = json_decode($string);
        return $decode($value);
      },
    'stringify' => function($this_, $arguments, $value, $replacer = null, $space = null) use (&$encode) {
        if (is_int_or_float($space)) {
          $space = str_repeat(' ', $space);
        }
        $opts = new stdClass();
        if (is_string($space)) {
          $opts->indent = $space;
          $opts->gap = '';
        } else {
          $opts->indent = null;
          $opts->gap = null;
        }
        $opts->replacer = ($replacer instanceof Func) ? $replacer : null;
        $opts->level = -1.0;
        // dummy object required if we have a replacer function (see json2 implementation)
        $obj = ($opts->replacer !== null) ? new Object('', $value) : null;
        return $encode($obj, '', $value, $opts);
      }
  );

  $JSON = new Object();
  $JSON->setMethods($methods, true, false, true);
  // expose for use elsewhere in php-land
  $JSON->fromNative = $decode;
  return $JSON;
});
