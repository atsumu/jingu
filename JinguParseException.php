<?php

class JinguParseException extends RuntimeException {
    protected $t, $e, $message;

    public function __construct($t, $e, $message = '') {
        $this->t = $t;
        $this->e = $e;
        $this->message = $message;
    }

    public function errorMessage() {
        if (is_null($this->e)) {
            return $this->message;
        }
        return $this->dumpError($this->t, $this->e, 0);
    }

    public function dumpError($t, $e, $level) {
        if (is_null($e)) {
            return $this->message;
        }
        $o = $e["offset"];
        $pre = substr($t, 0, $o);
        $start = strrpos($pre, "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($t, "\n", $o);
        if ($end === false) {
            $end = strlen($t);
        }
        $line = count(explode("\n", $pre));
        $column = $o + 1 - $start;
        $pointer  = "";
        for ($i = $start; $i < $o; $i++) {
            $pointer .= str_repeat("-", mb_strwidth($t[$i], "UTF-8"));
        }
        $pointer .= "^";
        $linestring = str_replace("\t", " ", substr($t, $start, $end - $start));
        $linejson = var_export($linestring, true);
        $indent = str_repeat(" ", $level);
        $message = "";
        $message .= sprintf("%sParse error: syntax error on line %s, column %s: expected %s %s, but %s\n", $indent, $line, $column, $e["message"], json_encode($e["args"]), json_encode($t[$o]));
        $message .= sprintf("%s%s\n", $indent, $linestring);
        $message .= sprintf("%s%s\n", $indent, $pointer);
        if (isset($e["children"])) {
            foreach ($e["children"] as $ec) {
                $message .= $this->dumpError($t, $ec, $level + 1);
            }
        }
        return $message;
    }
}

